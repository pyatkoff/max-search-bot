<?php
declare(strict_types=1);
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Prospective source journal only. No trip inference, model, provider or goal caller. */
final class ModeledConversionStream
{
    public const VERSION='prospective_source_stream_v1';
    public const ROW_LIMIT=500;
    public const BYTE_LIMIT=2097152;
    public const DAILY_LIMIT=10000;
    private const PAGE=30;
    private const RECONCILE=50;
    private const KINDS=['message','event','visit'];
    private PDO $pdo;
    private bool $mysql;
    private float $deadline=PHP_FLOAT_MAX;

    public function __construct(PDO $pdo)
    {
        $this->pdo=$pdo;
        $this->mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        if (!$this->mysql && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite') self::fail('driver');
        if (!$this->mysql) $pdo->sqliteCreateFunction('modeled_hash',static fn($v)=>hash('sha256',(string)$v),1);
    }

    /** Explicit start creates future ID floors once; resume never resets them. */
    public function start(int $now): array
    {
        self::time($now);
        return $this->transaction(function()use($now){
            $old=$this->state(true);
            if (!$old) {
                $state=['schema_version'=>1,'reconcile'=>['message'=>'0','event'=>'0','visit'=>'0']];
                foreach(self::KINDS as $kind){
                    $floor=$this->maximum($kind);
                    $state[$kind]=['floor'=>$floor,'forward'=>$floor,'sweep'=>$floor,'ceiling'=>$floor];
                }
                $this->query('INSERT INTO modeled_conversion_stream_state (project_key,enabled,started_at_unix,state_json,quota_day,quota_used,ticks) VALUES (?,1,?,?,?,0,0)',
                    ['anytour',$now,self::json($state),gmdate('Y-m-d',$now)]);
            } else $this->query('UPDATE modeled_conversion_stream_state SET enabled=1 WHERE project_key=?',['anytour']);
            return $this->status();
        });
    }

    public function stop(): array
    {
        return $this->transaction(function(){
            $this->state(true);
            $this->query('UPDATE modeled_conversion_stream_state SET enabled=0 WHERE project_key=?',['anytour']);
            return $this->status();
        });
    }

    public function status(): array
    {
        $row=$this->state();
        return ['version'=>self::VERSION,'enabled'=>$row && (bool)$row['enabled'],
            'started_at_unix'=>$row?(int)$row['started_at_unix']:null,'ticks'=>$row?(int)$row['ticks']:0,
            'quota_day'=>$row?$row['quota_day']:null,'quota_used'=>$row?(int)$row['quota_used']:0,
            'limits'=>['native_rows'=>self::ROW_LIMIT,'payload_bytes'=>self::BYTE_LIMIT,'new_revisions_per_day'=>self::DAILY_LIMIT],
            'last_receipt'=>$row && $row['last_receipt_json']!==null?json_decode($row['last_receipt_json'],true,16,JSON_THROW_ON_ERROR):null,
            'model_calls'=>0,'conversion_uploads'=>0];
    }

    /** Source pages, all revisions, reconciliation and cursor/quota commit atomically. */
    public function tick(int $now,?callable $beforeCommit=null): array
    {
        self::time($now);
        $started=microtime(true);$this->deadline=$started+5.0;
        return $this->transaction(function()use($now,$beforeCommit,$started){
            $row=$this->state(true);
            if (!$row || !(bool)$row['enabled']) return ['status'=>'off','model_calls'=>0,'conversion_uploads'=>0];
            if ($now<(int)$row['started_at_unix']) self::fail('clock');
            $last=$row['last_receipt_json']!==null?json_decode($row['last_receipt_json'],true,16,JSON_THROW_ON_ERROR):null;
            if($last && $now<(int)$last['observed_at_unix'])self::fail('clock_regressed');
            $state=json_decode($row['state_json'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($state)||($state['schema_version']??null)!==1)self::fail('checkpoint');
            foreach(self::KINDS as$kind){
                foreach(['floor','forward','sweep','ceiling']as$lane)self::id($state[$kind][$lane]??'invalid');
                self::id($state['reconcile'][$kind]??'invalid');
                if(self::compare($state[$kind]['floor'],$state[$kind]['forward'])>0
                    ||self::compare($state[$kind]['floor'],$state[$kind]['sweep'])>0
                    ||self::compare($state[$kind]['sweep'],$state[$kind]['ceiling'])>0)self::fail('checkpoint');
            }
            $used=$row['quota_day']===gmdate('Y-m-d',$now)?(int)$row['quota_used']:0;
            $receipt=['status'=>'observed','version'=>self::VERSION,'observed_at_unix'=>$now,
                'native_rows_read'=>0,'payload_bytes'=>0,'new_revisions'=>0,'unchanged'=>0,
                'omitted_payloads'=>0,'deleted'=>0,'scope_excluded'=>0,'limit_reached'=>false,
                'source_history_complete'=>false,'trip_status'=>'unknown','exposure'=>'unknown',
                'model_calls'=>0,'conversion_uploads'=>0];
            if ($used>=self::DAILY_LIMIT) {$receipt['status']='daily_limit';$receipt['limit_reached']=true;}
            else {
                foreach(self::KINDS as $kind){
                    // Forward pages are fast; bounded sweeps recover commits arriving behind them.
                    foreach(['forward','sweep'] as $lane){
                        if (!$this->room($receipt,$used)) break 2;
                        if ($lane==='sweep' && self::compare($state[$kind]['sweep'],$state[$kind]['ceiling'])>=0) {
                            $state[$kind]['sweep']=$state[$kind]['floor'];
                            $maximum=$this->maximum($kind);
                            $state[$kind]['ceiling']=self::compare($maximum,$state[$kind]['floor'])<0?$state[$kind]['floor']:$maximum;
                        }
                        $ceiling=$lane==='sweep'?$state[$kind]['ceiling']:null;
                        $pageLimit=min(self::PAGE,intdiv(self::ROW_LIMIT-$receipt['native_rows_read']-4,2));
                        if($pageLimit<1)break 2;
                        $rows=$this->metadataPage($kind,$state[$kind][$lane],$ceiling,$pageLimit);
                        $receipt['native_rows_read']+=count($rows);
                        foreach($rows as $meta){
                            if (!$this->observe($kind,$meta,$now,$used,$receipt)) break 3;
                            $state[$kind][$lane]=self::id($meta['id']);
                        }
                        if($lane==='sweep' && count($rows)<$pageLimit) $state[$kind]['sweep']=$state[$kind]['ceiling'];
                    }
                }
                foreach(self::KINDS as $kind){
                    if (!$this->room($receipt,$used)) break;
                    $q=$this->query('SELECT source_id FROM modeled_conversion_stream_sources WHERE project_key=? AND source_kind=? AND source_id>? ORDER BY source_id LIMIT '.self::RECONCILE,
                        ['anytour',$kind,$state['reconcile'][$kind]]);
                    $ids=$q->fetchAll(PDO::FETCH_COLUMN);
                    foreach($ids as $id){
                        if (!$this->room($receipt,$used)) break 2;
                        $meta=$this->metadataPage($kind,'0',null,1,self::id($id));
                        $receipt['native_rows_read']+=count($meta);
                        if (!$meta) {
                            $present=$this->query('SELECT id FROM '.$this->table($kind).' WHERE id=?',[self::id($id)])->fetchColumn()!==false;
                            $receipt['native_rows_read']+=(int)$present;
                            $snapshot=['source_status'=>$present?'scope_excluded':'deleted','source_id'=>self::id($id),'source_kind'=>$kind];
                            $this->append($kind,self::id($id),$snapshot,$now,$used,$receipt);
                        } elseif (!$this->observe($kind,$meta[0],$now,$used,$receipt)) break 2;
                        $state['reconcile'][$kind]=self::id($id);
                    }
                    if(count($ids)<self::RECONCILE)$state['reconcile'][$kind]='0';
                }
            }
            $receipt['limit_reached']=$receipt['limit_reached']||!$this->room($receipt,$used);
            if($beforeCommit)$beforeCommit($receipt);
            $receipt['duration_ms']=(int)round((microtime(true)-$started)*1000);
            $this->query('UPDATE modeled_conversion_stream_state SET state_json=?,quota_day=?,quota_used=?,ticks=ticks+1,last_receipt_json=? WHERE project_key=?',
                [self::json($state),gmdate('Y-m-d',$now),$used,self::json($receipt),'anytour']);
            return $receipt;
        });
    }

    /** Cheap OFF path; scheduler uses the canonical configured DB only. */
    public static function runtimeTick(): array
    {
        require_once __DIR__.'/ProjectConfig.php';
        require_once __DIR__.'/ConversationDb.php';
        if(ProjectConfig::projectId()!=='anytour'||!ConversationDb::isConfigured())return ['status'=>'off','model_calls'=>0,'conversion_uploads'=>0];
        require_once __DIR__.'/ProjectAccessService.php';
        ProjectAccessService::initializeReadOnly();
        return (new self(ConversationDb::connection()))->tick(time());
    }

    private function observe(string $kind,array $meta,int $now,int &$used,array &$receipt): bool
    {
        if (!$this->room($receipt,$used)) return false;
        $id=self::id($meta['id']);
        $sourceHash=$meta['payload_hash'];
        $base=['source_kind'=>$kind,'source_id'=>$id,'source_status'=>'present',
            'project_key'=>'anytour','platform'=>$meta['platform'],'conversation_id'=>$meta['conversation_id'],
            'customer_key'=>$meta['customer_key'],'source_created_at'=>$meta['created_at'],
            'source_clock'=>'database_persistence_not_provider_occurrence','payload_sha256'=>$sourceHash,
            'trip_status'=>'unknown','history_complete'=>false];
        $estimate=(int)$meta['payload_bytes']*6+4096;
        if ($estimate>65536) $snapshot=$base+['payload_status'=>'oversize_not_copied','payload'=>null];
        else {
            if ($receipt['payload_bytes']+$estimate>self::BYTE_LIMIT) {$receipt['limit_reached']=true;return false;}
            $old=$this->query('SELECT latest_hash FROM modeled_conversion_stream_sources WHERE project_key=? AND source_kind=? AND source_id=?',['anytour',$kind,$id])->fetchColumn();
            // Hash includes scope/role/content status, not receipt time; unchanged observations are free.
            $snapshot=$base+['payload_status'=>'copied','payload'=>null];
            $fingerprint=hash('sha256',self::json($snapshot));
            if($old===$fingerprint){$receipt['unchanged']++;return true;}
            $payload=$this->payload($kind,$id);
            $receipt['native_rows_read']++;
            if($payload===null)self::fail('source_changed_inside_snapshot');
            $snapshot['payload']=$payload;
            $bytes=strlen(self::json($snapshot));
            if($bytes>$estimate||$receipt['payload_bytes']+$bytes>self::BYTE_LIMIT)self::fail('payload_budget');
        }
        // Fingerprint excludes copied payload bytes because their exact source digest is already bound.
        $fingerprint=hash('sha256',self::json(array_replace($snapshot,['payload'=>null])));
        $this->append($kind,$id,$snapshot,$now,$used,$receipt,$fingerprint);
        return true;
    }

    private function append(string $kind,string $id,array $snapshot,int $now,int &$used,array &$receipt,?string $hash=null): void
    {
        $hash=$hash??hash('sha256',self::json($snapshot));
        $old=$this->query('SELECT latest_hash,version_no FROM modeled_conversion_stream_sources WHERE project_key=? AND source_kind=? AND source_id=?',['anytour',$kind,$id])->fetch(PDO::FETCH_ASSOC);
        if($old && $old['latest_hash']===$hash){$receipt['unchanged']++;return;}
        if($used>=self::DAILY_LIMIT)self::fail('quota_overrun');
        $snapshotJson=self::json($snapshot);
        if($receipt['payload_bytes']+strlen($snapshotJson)>self::BYTE_LIMIT)self::fail('payload_budget');
        $receipt['payload_bytes']+=strlen($snapshotJson);
        $version=$old?(int)$old['version_no']+1:1;
        $this->query('INSERT INTO modeled_conversion_stream_versions (project_key,source_kind,source_id,version_no,revision_hash,observed_at_unix,snapshot_json) VALUES (?,?,?,?,?,?,?)',
            ['anytour',$kind,$id,$version,$hash,$now,$snapshotJson]);
        if($old)$this->query('UPDATE modeled_conversion_stream_sources SET latest_hash=?,version_no=?,observed_at_unix=? WHERE project_key=? AND source_kind=? AND source_id=?',[$hash,$version,$now,'anytour',$kind,$id]);
        else $this->query('INSERT INTO modeled_conversion_stream_sources (project_key,source_kind,source_id,latest_hash,version_no,observed_at_unix) VALUES (?,?,?,?,?,?)',['anytour',$kind,$id,$hash,$version,$now]);
        $used++;$receipt['new_revisions']++;
        if(($snapshot['payload_status']??'')==='oversize_not_copied')$receipt['omitted_payloads']++;
        foreach(['deleted','scope_excluded']as$status)if(($snapshot['source_status']??'')===$status)$receipt[$status]++;
    }

    private function metadataPage(string $kind,string $after,?string $ceiling,int $limit,?string $exact=null): array
    {
        [$from,$where,$bind]=$this->scopeSql($kind);
        $clauses=$exact!==null?'s.id=?':'s.id>?';$bind[]=$exact??$after;
        if($ceiling!==null){$clauses.=' AND s.id<=?';$bind[]=$ceiling;}
        if($kind==='visit')$select="s.id,s.platform,'' AS conversation_id,'' AS customer_key,s.received_at_unix AS created_at,2048 AS payload_bytes,s.payload_sha256 AS payload_hash";
        else {
            $fields=$kind==='message'?'s.direction,s.sender_type,s.text,s.metadata_json':'s.event_type,s.actor_type,s.payload_json';
            $raw='JSON_ARRAY('.$fields.')';
            $hash=$this->mysql?'SHA2('.$raw.',256)':'modeled_hash(json_array('.$fields.'))';
            $length=$this->mysql?'OCTET_LENGTH':'LENGTH';
            $bytes=$kind==='message'?"COALESCE($length(s.text),0)+COALESCE($length(s.metadata_json),0)":"COALESCE($length(s.payload_json),0)";
            $select="s.id,c.channel AS platform,c.id AS conversation_id,c.customer_id AS customer_key,s.created_at,($bytes) AS payload_bytes,$hash AS payload_hash";
        }
        return $this->query('SELECT '.$select.' FROM '.$from.' WHERE '.$where.' AND '.$clauses.' ORDER BY s.id LIMIT '.$limit,$bind)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function scopeSql(string $kind): array
    {
        $table=$this->table($kind);
        if($kind==='visit')return [$table.' s',"s.project_key=? AND s.source_key='tgmini' AND s.platform='telegram' AND s.counter_id='98615635' AND NOT EXISTS (SELECT 1 FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.project_key=s.project_key AND c.channel=s.platform AND HEX(cc.external_user_id)=HEX(s.external_user_id) AND (c.is_test=1 OR ".InternalOwnerDialogueScope::conversationSql('c')."))",['anytour']];
        return [$table.' s JOIN conversations c ON c.id=s.conversation_id JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel',
            "c.project_key=? AND c.channel IN ('max','telegram') AND c.is_test=0 AND NOT ".InternalOwnerDialogueScope::conversationSql('c').($kind==='message'?' AND s.channel=c.channel':''),['anytour']];
    }
    private function payload(string $kind,string $id): ?array
    {
        $columns=match($kind){'message'=>'direction,sender_type,text,metadata_json','event'=>'event_type,actor_type,payload_json','visit'=>'source_key,platform,counter_id,source_cursor,visit_id,visit_at_unix,observed_at_unix,external_user_id,identity_status,client_id,yclid,campaign_id,region_id,payload_sha256,received_at_unix'};
        return $this->query('SELECT '.$columns.' FROM '.$this->table($kind).' WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC)?:null;
    }
    private function maximum(string $kind): string
    {
        [$from,$where,$bind]=$this->scopeSql($kind);
        return self::id($this->query('SELECT COALESCE(MAX(s.id),0) FROM '.$from.' WHERE '.$where,$bind)->fetchColumn());
    }
    private function table(string $kind): string {return match($kind){'message'=>'messages','event'=>'conversation_events','visit'=>'advertising_visit_observations',default=>throw new RuntimeException('modeled_stream_kind')};}
    private function state(bool $lock=false): ?array {return $this->query('SELECT * FROM modeled_conversion_stream_state WHERE project_key=?'.($lock&&$this->mysql?' FOR UPDATE':''),['anytour'])->fetch(PDO::FETCH_ASSOC)?:null;}
    private function query(string $sql,array $values): PDOStatement {$q=$this->pdo->prepare($sql);$q->execute($values);return $q;}
    private function transaction(callable $fn)
    {
        if($this->pdo->inTransaction())self::fail('outer_transaction');
        $lock=null;
        if($this->mysql){
            $database=(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $lock='modeled_stream:'.substr(hash('sha256',$database),0,32).':anytour';
            $acquired=$this->query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn();
            if((string)$acquired==='0')return ['status'=>'busy','model_calls'=>0,'conversion_uploads'=>0];
            if((string)$acquired!=='1')self::fail('lock_unavailable');
        }
        try{
            if($this->mysql)$this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->beginTransaction();
            try{$value=$fn();$this->pdo->commit();return $value;}
            catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }finally{if($lock!==null){try{$this->query('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable $ignored){}}}
    }
    private function room(array $r,int $used): bool {return $r['native_rows_read']<=self::ROW_LIMIT-4&&$r['payload_bytes']<self::BYTE_LIMIT&&$used<self::DAILY_LIMIT&&microtime(true)<$this->deadline;}
    private static function id($v): string {$s=(string)$v;if(!preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$s))self::fail('source_id');return $s;}
    private static function compare(string $a,string $b): int{return strlen($a)<=>strlen($b)?:strcmp($a,$b);}
    private static function time(int $v): void{if($v<946684800)self::fail('clock');}
    private static function json(array $v): string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private static function fail(string $reason): void {throw new RuntimeException('modeled_stream_'.$reason);}
}
