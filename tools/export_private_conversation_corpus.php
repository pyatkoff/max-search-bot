<?php
declare(strict_types=1);

/**
 * Private, one-shot full conversation-store export. This file is self-contained:
 * from a protected runner pipe it to `cd PRODUCTION_ROOT && php /dev/stdin
 * --production-sha FULL_SHA`. No collector file is installed on production.
 * STDOUT is private JSONL, never an Actions log or a public artifact.
 */
final class PrivateConversationCorpusFailure extends RuntimeException {}

final class PrivateConversationCorpus
{
    public const SCHEMA = 'private_conversation_corpus_v1';
    public const REPOSITORY = 'pyatkoff/max-search-bot';
    private const PAGE_SIZE = 200;
    private const MAX_RECORDS = 2000000;
    private const MAX_OUTPUT_BYTES = 268435456;
    private const MAX_RECORD_BYTES = 67108864;
    private const MAX_TEXT_BYTES = 8388608;
    private const MAX_METADATA_BYTES = 1048576;
    private const MAX_SECONDS = 600;
    private PDO $pdo;
    private string $scope;
    private string $salt;
    private $write;
    private int $bytes = 0;
    private int $records = 0;
    private float $started;
    private array $expected = [];
    private array $exported = ['conversations'=>0,'messages'=>0,'events'=>0,'assignments'=>0,'managers'=>0];
    private array $upper = [];
    private array $statistics = [];
    private bool $transactionStarted = false;
    private bool $headerWritten = false;

    public function __construct(PDO $pdo, string $scope, callable $write)
    {
        if ($scope === '' || strlen($scope)>64 || !preg_match('/^[A-Za-z0-9_.:-]+$/D',$scope)) {
            throw new PrivateConversationCorpusFailure('invalid_project_scope');
        }
        $this->pdo=$pdo; $this->scope=$scope; $this->write=$write;
        $this->salt=random_bytes(32); $this->started=microtime(true);
    }

    /** No source truncation. Only recognizable contact/credential patterns are masked. */
    public static function privateText(?string $text): ?string
    {
        if ($text===null) return null;
        $patterns=[
            '~\b[0-9]{5,14}:[A-Za-z0-9_-]{20,}\b~u'=>'[credential-redacted]',
            '~\bBearer[ \t]+[A-Za-z0-9._\~+/-]+=*~iu'=>'Bearer [credential-redacted]',
            '~\b(?:sk|ghp|github_pat|xoxb|xoxp)[_-][A-Za-z0-9_-]{16,}\b~u'=>'[credential-redacted]',
            '~((?:[?&]|\b)(?:access_token|token|api_key|apikey|password|secret|authorization|yclid|email|phone)[ \t]*[=:][ \t]*)[^\s&#]+~iu'=>'$1[private-redacted]',
            '~\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b~iu'=>'[email-redacted]',
            '~(?<![0-9])(?:\+7|8)[ \t()\-]*(?:[0-9][ \t()\-]*){9}[0-9](?![0-9])~u'=>'[phone-redacted]',
            '~(?<![0-9])[78][0-9]{10}(?![0-9])~u'=>'[phone-redacted]',
        ];
        foreach ($patterns as $pattern=>$replacement) {
            $result=preg_replace($pattern,$replacement,$text);
            if ($result===null) throw new PrivateConversationCorpusFailure('invalid_utf8');
            $text=$result;
        }
        return $text;
    }

    private function ref(string $kind, $id): ?string
    {
        if ($id===null || (string)$id==='' || (string)$id==='0') return null;
        return $kind.'_'.substr(hash_hmac('sha256',$kind.':'.(string)$id,$this->salt),0,24);
    }

    private static function code($value, int $max=96): ?string
    {
        if ($value===null || $value==='') return null;
        if (!is_scalar($value)) return '[nonstandard]';
        $value=(string)$value;
        return strlen($value)<=$max && preg_match('/^[A-Za-z0-9_.:\/-]+$/D',$value) ? $value : '[nonstandard]';
    }

    private function checkBudget(): void
    {
        if (microtime(true)-$this->started>self::MAX_SECONDS) throw new PrivateConversationCorpusFailure('time_limit');
    }

    private function emit(array $record): void
    {
        $this->checkBudget();
        $line=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
        $length=strlen($line);
        if ($length>self::MAX_RECORD_BYTES) throw new PrivateConversationCorpusFailure('record_byte_limit');
        if ($this->bytes+$length>self::MAX_OUTPUT_BYTES) throw new PrivateConversationCorpusFailure('output_byte_limit');
        if (++$this->records>self::MAX_RECORDS) throw new PrivateConversationCorpusFailure('record_limit');
        ($this->write)($line); $this->bytes+=$length;
    }

    private function query(string $sql,array $args=[]): PDOStatement
    {
        $this->checkBudget();
        $q=$this->pdo->prepare($sql); $q->execute($args); return $q;
    }

    private function one(string $sql,array $args=[]): array
    {
        $q=$this->query($sql,$args); $row=$q->fetch(PDO::FETCH_ASSOC); $q->closeCursor();
        if (!is_array($row)) throw new PrivateConversationCorpusFailure('missing_manifest_row');
        return $row;
    }

    private function begin(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql') throw new PrivateConversationCorpusFailure('mysql_required');
        if ($this->pdo->inTransaction()) throw new PrivateConversationCorpusFailure('transaction_already_active');
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $attribute=constant('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY');
            // This one-shot collector owns a fresh connection, so no previous
            // buffered-query state needs to be read or restored.
            $this->pdo->setAttribute($attribute,false);
        }
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $this->transactionStarted=true;
        if (!$this->pdo->inTransaction()) throw new PrivateConversationCorpusFailure('snapshot_not_active');
        $tables=['conversations','messages','conversation_events','manager_assignments','managers','conversation_sources','projects'];
        $q=$this->query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')',$tables);
        $found=[];
        while ($row=$q->fetch(PDO::FETCH_ASSOC)) $found[(string)$row['TABLE_NAME']]=(string)$row['ENGINE'];
        $q->closeCursor();
        foreach ($tables as $table) if (($found[$table]??'')!=='InnoDB') throw new PrivateConversationCorpusFailure('snapshot_engine_unavailable');
    }

    private function managerPredicate(): string
    {
        return "(EXISTS(SELECT 1 FROM conversations c WHERE c.project_key=? AND (c.manager_id=g.id OR c.lead_outcome_manager_id=g.id))"
            ." OR EXISTS(SELECT 1 FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=? AND m.sender_type='manager' AND BINARY m.sender_id=BINARY CAST(g.id AS CHAR))"
            ." OR EXISTS(SELECT 1 FROM manager_assignments a JOIN conversations c ON c.id=a.conversation_id WHERE c.project_key=? AND a.manager_id=g.id)"
            ." OR EXISTS(SELECT 1 FROM conversation_events e JOIN conversations c ON c.id=e.conversation_id WHERE c.project_key=? AND e.actor_type='manager' AND BINARY e.actor_id=BINARY CAST(g.id AS CHAR)))";
    }

    private function definitions(): array
    {
        return [
            'conversations'=>['table'=>'conversations c','id'=>'c.id','where'=>'c.project_key=?','args'=>[$this->scope],
                'select'=>'c.id,c.customer_id,c.channel,c.entry_channel,c.attribution_region,c.attribution_campaign,c.status,c.is_test,c.test_source,c.manager_id,c.started_at,c.last_message_at,c.closed_at,c.source_id,c.lead_outcome,c.lead_close_reason,c.lead_sale_amount,c.lead_sale_date,c.lead_outcome_updated_at,c.lead_outcome_manager_id,CASE WHEN sp.project_key=c.project_key THEN s.source_key ELSE NULL END AS source_key',
                'joins'=>' LEFT JOIN conversation_sources s ON s.id=c.source_id LEFT JOIN projects sp ON sp.id=s.project_id'],
            'messages'=>['table'=>'messages m JOIN conversations c ON c.id=m.conversation_id','id'=>'m.id','where'=>'c.project_key=?','args'=>[$this->scope],
                'select'=>'m.id,m.conversation_id,m.direction,m.sender_type,m.sender_id,m.channel,'
                    .'CASE WHEN OCTET_LENGTH(m.text)<='.self::MAX_TEXT_BYTES.' THEN m.text ELSE NULL END AS text,'
                    .'CASE WHEN OCTET_LENGTH(m.metadata_json)<='.self::MAX_METADATA_BYTES.' THEN m.metadata_json ELSE NULL END AS metadata_json,'
                    .'(OCTET_LENGTH(m.text)>'.self::MAX_TEXT_BYTES.' OR OCTET_LENGTH(m.metadata_json)>'.self::MAX_METADATA_BYTES.') AS oversized_record,m.created_at','joins'=>''],
            'events'=>['table'=>'conversation_events e JOIN conversations c ON c.id=e.conversation_id','id'=>'e.id','where'=>'c.project_key=?','args'=>[$this->scope],
                'select'=>'e.id,e.conversation_id,e.event_type,e.actor_type,e.actor_id,'
                    .'CASE WHEN OCTET_LENGTH(e.payload_json)<='.self::MAX_METADATA_BYTES.' THEN e.payload_json ELSE NULL END AS payload_json,'
                    .'(OCTET_LENGTH(e.payload_json)>'.self::MAX_METADATA_BYTES.') AS oversized_record,e.created_at','joins'=>''],
            'assignments'=>['table'=>'manager_assignments a JOIN conversations c ON c.id=a.conversation_id','id'=>'a.id','where'=>'c.project_key=?','args'=>[$this->scope],
                'select'=>'a.id,a.conversation_id,a.manager_id,a.assignment_type,a.assigned_at,a.released_at','joins'=>''],
            'managers'=>['table'=>'managers g','id'=>'g.id','where'=>$this->managerPredicate(),'args'=>array_fill(0,4,$this->scope),
                'select'=>'g.id,g.role,g.is_active,g.display_name','joins'=>''],
        ];
    }

    private function manifest(array $definitions): void
    {
        foreach ($definitions as $kind=>$d) {
            $row=$this->one('SELECT COUNT(*) AS n,COALESCE(MAX('.$d['id'].'),0) AS upper_id FROM '.$d['table'].' WHERE '.$d['where'],$d['args']);
            $this->expected[$kind]=(int)$row['n']; $this->upper[$kind]=(int)$row['upper_id'];
            if ($this->expected[$kind]<0 || $this->upper[$kind]<0) throw new PrivateConversationCorpusFailure('invalid_manifest_count');
        }
        if (array_sum($this->expected)+2>self::MAX_RECORDS) throw new PrivateConversationCorpusFailure('record_limit');
    }

    private function increment(string $section,string $key): void
    {
        $key=self::code($key,191)??'unknown';
        if (!isset($this->statistics[$section][$key]) && count($this->statistics[$section]??[])>=64) $key='other';
        $this->statistics[$section][$key]=($this->statistics[$section][$key]??0)+1;
    }

    /** Only listed metadata is projected; arbitrary URLs/payloads/profile data never leave. */
    private function messageMetadata(?string $json): array
    {
        $metadata=json_decode($json??'',true);
        if (!is_array($metadata)) {
            if ($json!==null && $json!=='') $this->increment('data_quality','invalid_message_metadata');
            return ['type'=>'message','attachments'=>[]];
        }
        $out=['type'=>self::code($metadata['type']??'message',32),'attachments'=>[]];
        if (isset($metadata['source_key'])) $out['source_key']=self::code($metadata['source_key']);
        foreach ((array)($metadata['attachments']??[]) as $attachment) {
            if (!is_array($attachment)) continue;
            $kind=(string)($attachment['type']??'unknown');
            $kind=in_array($kind,['image','video','audio','file'],true)?$kind:'other';
            $out['attachments'][]=['type'=>$kind]; $this->increment('attachments_by_type',$kind);
        }
        if ($out['attachments']) $this->increment('data_quality','messages_with_attachments');
        $linked=$metadata['linked_message']??null;
        if (is_array($linked) && in_array($linked['type']??null,['reply','forward'],true)) {
            $out['linked_message']=['type'=>$linked['type'],'text'=>self::privateText(isset($linked['text'])?(string)$linked['text']:null)];
        }
        return $out;
    }

    private function eventMetadata(?string $json): array
    {
        $payload=json_decode($json??'',true); if (!is_array($payload)) return [];
        $out=[];
        foreach (['source_key','entry_channel','region_id','campaign_id','from_stage_key','to_stage_key','lead_outcome','reason_key','handling_mode','channel','provider','status','action'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) $out[$key]=self::code($payload[$key],191);
        }
        foreach (['from_manager_id','to_manager_id','manager_id'] as $key) {
            if (isset($payload[$key]) && (is_int($payload[$key]) || (is_string($payload[$key]) && ctype_digit($payload[$key])))) {
                $out[str_replace('_id','_ref',$key)]=$this->ref('g',$payload[$key]);
            }
        }
        foreach (['source_id','post_id','publication_id'] as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) $out[str_replace('_id','_ref',$key)]=$this->ref('s',$payload[$key]);
        }
        foreach (['outside_hours','success','delivered','first_reply','reopened'] as $key) if (isset($payload[$key]) && is_bool($payload[$key])) $out[$key]=$payload[$key];
        return $out;
    }

    private function record(string $kind,array $row): array
    {
        if (!empty($row['oversized_record'])) throw new PrivateConversationCorpusFailure('source_record_byte_limit');
        if ($kind==='conversations') {
            $this->increment('conversations_by_channel',(string)$row['channel']);
            $this->increment('conversations_by_status',(string)$row['status']);
            $this->increment('conversations_by_test',!empty($row['is_test'])?'test':'business');
            return ['type'=>'conversation','id'=>$this->ref('c',$row['id']),'source_locator'=>['conversation_id'=>(int)$row['id']],
                'customer_ref'=>$this->ref('u',$row['customer_id']),
                'channel'=>self::code($row['channel'],32),'status'=>self::code($row['status'],32),'is_test'=>(bool)$row['is_test'],
                'test_source'=>self::code($row['test_source'],64),'manager_ref'=>$this->ref('g',$row['manager_id']),
                'started_at'=>$row['started_at'],'last_message_at'=>$row['last_message_at'],'closed_at'=>$row['closed_at'],
                'attribution'=>['source_ref'=>$this->ref('s',$row['source_id']),'source_key'=>self::code($row['source_key']),
                    'entry_channel'=>self::code($row['entry_channel'],64),'region'=>self::code($row['attribution_region'],64),'campaign'=>self::code($row['attribution_campaign'],64)],
                'recorded_outcome'=>['outcome'=>self::code($row['lead_outcome'],32),'close_reason'=>self::code($row['lead_close_reason'],64),
                    'sale_amount'=>$row['lead_sale_amount'],'sale_date'=>$row['lead_sale_date'],'updated_at'=>$row['lead_outcome_updated_at'],
                    'manager_ref'=>$this->ref('g',$row['lead_outcome_manager_id'])]];
        }
        if ($kind==='messages') {
            $meta=$this->messageMetadata($row['metadata_json']);
            $manager=($row['sender_type']==='manager');
            $managerRef=$manager?$this->ref('g',$row['sender_id']):null;
            if ($manager && $managerRef===null) $this->increment('data_quality','manager_messages_without_sender');
            $this->increment('messages_by_channel',(string)$row['channel']);
            $this->increment('messages_by_sender',(string)$row['sender_type']);
            $this->increment('messages_by_direction',(string)$row['direction']);
            $this->increment('message_types',(string)$meta['type']);
            return ['type'=>'message','id'=>$this->ref('m',$row['id']),
                'source_locator'=>['message_id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],
                    'manager_id'=>$manager && $row['sender_id']!==null && ctype_digit((string)$row['sender_id'])?(int)$row['sender_id']:null],
                'conversation_ref'=>$this->ref('c',$row['conversation_id']),
                'direction'=>self::code($row['direction'],16),'sender_type'=>self::code($row['sender_type'],16),
                'manager_ref'=>$managerRef,'sender_attribution'=>$manager?($managerRef===null?'missing':'recorded_sender'):'not_manager',
                'channel'=>self::code($row['channel'],32),'created_at'=>$row['created_at'],
                'text'=>$meta['type']==='contact'?'[contact-redacted]':self::privateText($row['text']), 'metadata'=>$meta];
        }
        if ($kind==='events') {
            $this->increment('events_by_type',(string)$row['event_type']);
            return ['type'=>'event','id'=>$this->ref('e',$row['id']),
                'source_locator'=>['event_id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id']],
                'conversation_ref'=>$this->ref('c',$row['conversation_id']),
                'event_type'=>self::code($row['event_type']),'actor_type'=>self::code($row['actor_type'],32),
                'manager_ref'=>$row['actor_type']==='manager'?$this->ref('g',$row['actor_id']):null,
                'created_at'=>$row['created_at'],'metadata'=>$this->eventMetadata($row['payload_json'])];
        }
        if ($kind==='assignments') return ['type'=>'assignment','id'=>$this->ref('a',$row['id']),
            'source_locator'=>['assignment_id'=>(int)$row['id'],'conversation_id'=>(int)$row['conversation_id'],'manager_id'=>(int)$row['manager_id']],
            'conversation_ref'=>$this->ref('c',$row['conversation_id']),'manager_ref'=>$this->ref('g',$row['manager_id']),
            'assignment_type'=>self::code($row['assignment_type'],32),'assigned_at'=>$row['assigned_at'],'released_at'=>$row['released_at']];
        return ['type'=>'manager','id'=>$this->ref('g',$row['id']),'source_locator'=>['manager_id'=>(int)$row['id']],
            'display_name'=>self::privateText($row['display_name']??null),'role'=>self::code($row['role'],32),'is_active'=>(bool)$row['is_active']];
    }

    private function stream(string $kind,array $definition): void
    {
        $last=0; $upper=$this->upper[$kind];
        do {
            $count=0;
            $sql='SELECT '.$definition['select'].' FROM '.$definition['table'].$definition['joins'].' WHERE '.$definition['where']
                .' AND '.$definition['id'].'>? AND '.$definition['id'].'<=? ORDER BY '.$definition['id'].' ASC LIMIT '.self::PAGE_SIZE;
            $q=$this->query($sql,array_merge($definition['args'],[$last,$upper]));
            try {
                while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
                    $id=(int)$row['id'];
                    if ($id<=$last || $id>$upper) throw new PrivateConversationCorpusFailure('invalid_page_order');
                    $last=$id; $count++;
                    if ($this->exported[$kind]>=$this->expected[$kind]) throw new PrivateConversationCorpusFailure('count_mismatch');
                    $this->emit($this->record($kind,$row));
                    $this->exported[$kind]++;
                }
            } finally { $q->closeCursor(); }
        } while ($count===self::PAGE_SIZE);
        if ($this->exported[$kind]!==$this->expected[$kind]) throw new PrivateConversationCorpusFailure('count_mismatch');
    }

    private function restore(): void
    {
        if ($this->transactionStarted && $this->pdo->inTransaction()) $this->pdo->rollBack();
        $this->transactionStarted=false;
    }

    public function run(string $sha): bool
    {
        try {
            if (!preg_match('/^[a-f0-9]{40}$/D',$sha)) throw new PrivateConversationCorpusFailure('invalid_production_sha');
            $this->begin(); $definitions=$this->definitions(); $this->manifest($definitions);
            $this->emit(['type'=>'header','schema'=>self::SCHEMA,'repository'=>self::REPOSITORY,'production_sha'=>$sha,
                'generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),'project_scope'=>[$this->scope],
                'snapshot'=>['isolation'=>'REPEATABLE READ','read_only'=>true],'upper_ids'=>$this->upper,'expected_counts'=>$this->expected,
                'privacy'=>['pseudonyms'=>'capture_local_hmac','text'=>'complete_with_best_effort_contact_and_credential_masks',
                    'profiles_exported'=>false,'attachments'=>'metadata_type_only'],
                'safety_limits'=>['max_records'=>self::MAX_RECORDS,'max_output_bytes'=>self::MAX_OUTPUT_BYTES,
                    'max_text_bytes'=>self::MAX_TEXT_BYTES,'max_metadata_bytes'=>self::MAX_METADATA_BYTES,'max_seconds'=>self::MAX_SECONDS,
                    'exceeded'=>'fail_closed_without_complete_footer'],
                'coverage'=>['open_and_closed'=>true,'all_recorded_channels'=>true,'tests'=>'included_and_marked',
                    'source'=>'conversation_database_best_effort_mirror','external_messenger_completeness'=>'not_verified']]);
            $this->headerWritten=true;
            foreach ($definitions as $kind=>$definition) $this->stream($kind,$definition);
            if ($this->expected!==$this->exported) throw new PrivateConversationCorpusFailure('count_mismatch');
            $this->restore();
            $this->emit(['type'=>'footer','schema'=>self::SCHEMA,'complete'=>true,'expected_counts'=>$this->expected,
                'exported_counts'=>$this->exported,'statistics'=>$this->statistics,'finished_at'=>gmdate('Y-m-d\TH:i:s\Z')]);
            return true;
        } catch (Throwable $e) {
            try { $this->restore(); } catch (Throwable $ignored) {}
            $error=$e instanceof PrivateConversationCorpusFailure ? $e->getMessage() : 'database_or_output_failed';
            // Never expose an exception message, SQL, DSN, text or partial-success footer.
            if ($this->headerWritten) {
                try { ($this->write)(json_encode(['type'=>'footer','schema'=>self::SCHEMA,'complete'=>false,'error'=>$error,
                    'expected_counts'=>$this->expected,'exported_counts'=>$this->exported],JSON_THROW_ON_ERROR)."\n"); } catch (Throwable $ignored) {}
            }
            fwrite(STDERR,'private_corpus_export_failed:'.$error."\n");
            return false;
        }
    }
}

if (!defined('PRIVATE_CONVERSATION_CORPUS_TESTING')) {
    if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
    ini_set('display_errors','0'); ini_set('log_errors','0');
    set_error_handler(static function(): bool { throw new PrivateConversationCorpusFailure('php_runtime_warning'); });
    try {
        $args=$argv??[];
        if (count($args)!==3 || $args[1]!=='--production-sha' || !preg_match('/^[a-f0-9]{40}$/D',$args[2])) throw new PrivateConversationCorpusFailure('invalid_arguments');
        $root=getcwd();
        if ($root===false || !is_file($root.'/config.php') || !is_file($root.'/services/ConversationDb.php') || !is_file($root.'/project.php')) throw new PrivateConversationCorpusFailure('production_bootstrap_unavailable');
        ob_start();
        try {
            require_once $root.'/config.php';
            require_once $root.'/services/ConversationDb.php';
            $project=require $root.'/project.php';
            $bootstrap=(string)ob_get_contents();
        } finally { ob_end_clean(); }
        if ($bootstrap!=='') throw new PrivateConversationCorpusFailure('unexpected_bootstrap_output');
        $scope=is_array($project)?($project['id']??'default'):'default';
        if (!is_string($scope)) throw new PrivateConversationCorpusFailure('invalid_project_scope');
        $collector=new PrivateConversationCorpus(ConversationDb::connection(),$scope,static function(string $line): void {
            $offset=0; $length=strlen($line);
            while ($offset<$length) {
                $written=fwrite(STDOUT,substr($line,$offset));
                if ($written===false || $written===0) throw new PrivateConversationCorpusFailure('private_output_failed');
                $offset+=$written;
            }
        });
        exit($collector->run($args[2])?0:1);
    } catch (Throwable $e) {
        fwrite(STDERR,'private_corpus_export_failed:'.($e instanceof PrivateConversationCorpusFailure?$e->getMessage():'bootstrap_failed')."\n");
        exit(1);
    }
}
