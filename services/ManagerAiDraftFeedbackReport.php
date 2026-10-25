<?php

declare(strict_types=1);

/** Private observation only: persisted provenance is neither acceptance nor quality. */
final class ManagerAiDraftFeedbackReport
{
    public const DRAFT_LIMIT = 1000;
    public const MESSAGE_LIMIT = 5000;
    public const SAMPLE_LIMIT = 10;
    public const SAMPLE_CHARACTERS = 2000;
    private const COUNTS = ['persisted_proposals','reply_proposals','no_reply_proposals','invalid_proposals',
        'insert_requested_drafts','invalid_insert_request_drafts','linked_drafts','linked_messages','recorded_same_messages','recorded_changed_messages',
        'prepared_proposals','prepared_linked_messages','prepared_same_messages','prepared_changed_messages',
        'edited_messages','manager_message_rows','manager_text_messages','unlinked_manager_text_messages',
        'unresolved_reference_messages','invalid_reference_messages','unclassifiable_message_rows','media_messages_excluded'];

    /** Pure historical proof for the protected usage view; never a send permission. */
    public static function inspectUsage(array $message,array $draft,string $projectKey,DateTimeImmutable $now): ?array
    {
        $metadata=self::metadata($message['metadata_json']??null);$proposal=self::proposal($draft['proposal_json']??null);
        $ref=$metadata['ai_draft']??null;$created=self::timestamp($draft['created_at']??null);
        $requested=self::timestamp($draft['insert_requested_at']??null);$sent=self::timestamp($message['created_at']??null);
        if(!$metadata||!$proposal||!$proposal['should_draft']||!is_array($ref)||array_is_list($ref)||count($ref)!==3
            ||!self::hex($ref['draft_id']??null,32)||!is_bool($ref['text_matches_proposal']??null)||!self::hex($ref['sent_text_sha256']??null,64)
            ||!self::positive($message['id']??null)||!self::positive($message['conversation_id']??null)||!self::positive($message['sender_id']??null)
            ||!self::positive($draft['manager_id']??null)||!self::positive($draft['latest_message_id']??null)
            ||($draft['draft_id']??null)!==$ref['draft_id']||($draft['project_key']??null)!==$projectKey||($message['project_key']??null)!==$projectKey
            ||($metadata['project_key']??null)!==$projectKey||(int)($draft['conversation_id']??0)!==(int)$message['conversation_id']
            ||(int)$draft['manager_id']!==(int)$message['sender_id']||!self::hex($draft['context_revision']??null,64)||!self::hex($draft['instruction_revision']??null,64)
            ||(($draft['preparation_job_id']??null)!==null&&!self::hex($draft['preparation_job_id'],64))
            ||($message['direction']??null)!=='outbound'||($message['sender_type']??null)!=='manager'
            ||!in_array($message['channel']??null,['max','telegram','website'],true)||$message['channel']!==($message['conversation_channel']??null)
            ||(array_key_exists('attachments',$metadata)&&(!is_array($metadata['attachments'])||$metadata['attachments']))
            ||$created===null||$requested===null||$sent===null||$requested<$created||$sent<$requested||$sent-$created>86400||$sent>=$now->getTimestamp()
            ||$ref['text_matches_proposal']!==hash_equals(hash('sha256',trim($proposal['draft'])),$ref['sent_text_sha256']))return null;
        $stored=$message['text']??null;if(!is_string($stored)||preg_match('//u',$stored)!==1)return null;
        $raw=htmlspecialchars_decode($stored,ENT_QUOTES);$initial=self::text($raw,1,20000)&&strlen($stored)<=120000&&hash_equals($ref['sent_text_sha256'],hash('sha256',trim($raw)));
        preg_match_all('/./us',$raw,$characters);$truncated=count($characters[0])>6000||strlen($stored)>120000;
        $edited=self::timestamp($metadata['edited_at']??null);$edited=$edited!==null&&$edited>=$sent&&$edited<=$now->getTimestamp();
        return ['message_id'=>(int)$message['id'],'conversation_id'=>(int)$message['conversation_id'],
            'latest_message_id'=>(int)$draft['latest_message_id'],'manager_id'=>(int)$draft['manager_id'],
            'source'=>($draft['preparation_job_id']??null)!==null?'prepared':'generated','proposed_text'=>trim($proposal['draft']),
            'current_sent_text'=>implode('',array_slice($characters[0],0,6000)),'current_sent_text_truncated'=>$truncated,
            'initial_text_available'=>$initial,'recorded_text_matches_proposal'=>$ref['text_matches_proposal'],'edited'=>$edited,
            'reason'=>$proposal['reason'],'generated_at'=>$draft['created_at'],'sent_at'=>$message['created_at'],
            'case_revision'=>hash('sha256',json_encode([$message['id'],$ref['draft_id'],$message['created_at'],$ref['sent_text_sha256'],hash('sha256',$stored),hash('sha256',$draft['proposal_json']),$metadata['edited_at']??null],JSON_THROW_ON_ERROR))];
    }

    /**
     * Trusted private CLI boundary; no auth/config bootstrap and no business writes.
     * Own transaction uses driver isolation. Time bounds are immutable, while current
     * text means the value observed in that read transaction, not historical text.
     */
    public static function collect(PDO $pdo, string $projectKey, DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $until = $now->format('Y-m-d H:i:s');
        $since = $now->modify('-7 days')->format('Y-m-d H:i:s');
        $summary = ['ok'=>false,'error'=>null,'generated_at'=>$now->format('Y-m-d\TH:i:s\Z'),
            'window'=>['since'=>$since,'until'=>$until,'timezone'=>'UTC','days'=>7,'basis'=>'persisted_generation_cohort'],
            'limits'=>['draft_rows'=>self::DRAFT_LIMIT,'message_rows'=>self::MESSAGE_LIMIT,
                'samples'=>self::SAMPLE_LIMIT,'sample_characters'=>self::SAMPLE_CHARACTERS],
            'complete'=>false,'draft_limit_reached'=>false,'message_limit_reached'=>false,
            'counts'=>array_fill_keys(self::COUNTS,null)];
        if (!self::text($projectKey,1,64)) return self::failure($summary,'invalid_project');
        if ($pdo->inTransaction()) return self::failure($summary,'borrowed_transaction');
        $owned = false;
        try {
            if (!$pdo->beginTransaction()) return self::failure($summary,'storage_unavailable');
            $owned = true;
            $counts = array_fill_keys(self::COUNTS,0);
            $q=$pdo->prepare('SELECT d.draft_id,d.conversation_id,d.manager_id,d.project_key,d.context_revision,d.instruction_revision,d.latest_message_id,d.preparation_job_id,SUBSTR(d.proposal_json,1,32769) AS proposal_json,d.created_at,d.insert_requested_at,c.project_key AS conversation_project FROM manager_ai_drafts d JOIN conversations c ON c.id=d.conversation_id WHERE d.project_key=? AND c.project_key=? AND c.is_test=0 AND d.created_at>=? AND d.created_at<? ORDER BY d.created_at DESC,d.draft_id DESC LIMIT '.(self::DRAFT_LIMIT+1));
            $q->execute([$projectKey,$projectKey,$since,$until]);
            $drafts=[];$scanned=0;
            while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
                if (++$scanned>self::DRAFT_LIMIT) {$summary['draft_limit_reached']=true;break;}
                // The older conversation project column can have a folding collation.
                if ($row['project_key']!==$projectKey || $row['conversation_project']!==$projectKey) continue;
                ++$counts['persisted_proposals'];
                $proposal=self::proposal($row['proposal_json']);
                $created=self::timestamp($row['created_at']);
                $valid=self::hex($row['draft_id'],32) && self::positive($row['conversation_id'])
                    && self::positive($row['manager_id']) && self::positive($row['latest_message_id'])
                    && self::hex($row['context_revision'],64) && self::hex($row['instruction_revision'],64)
                    && $created!==null && $created>=self::timestamp($since) && $created<self::timestamp($until)
                    && ($row['preparation_job_id']===null || self::hex($row['preparation_job_id'],64))
                    && $proposal!==null;
                if (!$valid) {++$counts['invalid_proposals'];$drafts[(string)$row['draft_id']]=null;continue;}
                ++$counts[$proposal['should_draft']?'reply_proposals':'no_reply_proposals'];
                $prepared=$row['preparation_job_id']!==null;
                if ($prepared) ++$counts['prepared_proposals'];
                $requested=self::timestamp($row['insert_requested_at']);
                $requestedValid=$proposal['should_draft'] && $requested!==null && $requested>=$created
                    && $requested<self::timestamp($until) && $requested-$created<=86400;
                if ($requestedValid) ++$counts['insert_requested_drafts'];
                elseif ($row['insert_requested_at']!==null) ++$counts['invalid_insert_request_drafts'];
                $drafts[$row['draft_id']]=['conversation_id'=>(int)$row['conversation_id'],'manager_id'=>(int)$row['manager_id'],
                    'created'=>$created,'requested'=>$requestedValid?$requested:null,'created_at'=>$row['created_at'],
                    'proposal'=>$proposal,'prepared'=>$prepared];
            }
            $q->closeCursor();
            // Metadata is bounded before transfer. Message text is hydrated only for ten samples.
            $q=$pdo->prepare("SELECT m.id,m.conversation_id,m.sender_id,m.channel,m.created_at,SUBSTR(m.metadata_json,1,8193) AS metadata_json,c.project_key,c.channel AS conversation_channel FROM messages m JOIN conversations c ON c.id=m.conversation_id WHERE c.project_key=? AND c.is_test=0 AND m.direction='outbound' AND m.sender_type='manager' AND m.created_at>=? AND m.created_at<? ORDER BY m.created_at DESC,m.id DESC LIMIT ".(self::MESSAGE_LIMIT+1));
            $q->execute([$projectKey,$since,$until]);
            $linked=[];$candidates=[];$scanned=0;
            while ($row=$q->fetch(PDO::FETCH_ASSOC)) {
                if (++$scanned>self::MESSAGE_LIMIT) {$summary['message_limit_reached']=true;break;}
                if ($row['project_key']!==$projectKey) continue;
                ++$counts['manager_message_rows'];
                $metadata=self::metadata($row['metadata_json']);
                if ($metadata===null) {++$counts['unclassifiable_message_rows'];continue;}
                if (array_key_exists('attachments',$metadata) && (!is_array($metadata['attachments']) || $metadata['attachments'])) {
                    ++$counts[is_array($metadata['attachments'])?'media_messages_excluded':'unclassifiable_message_rows'];continue;
                }
                ++$counts['manager_text_messages'];
                if (!array_key_exists('ai_draft',$metadata)) {++$counts['unlinked_manager_text_messages'];continue;}
                $ref=$metadata['ai_draft'];
                if (!is_array($ref) || array_is_list($ref) || count($ref)!==3
                    || !self::hex($ref['draft_id']??null,32) || !is_bool($ref['text_matches_proposal']??null)
                    || !self::hex($ref['sent_text_sha256']??null,64)) {
                    ++$counts['invalid_reference_messages'];++$counts['unlinked_manager_text_messages'];continue;
                }
                if (!array_key_exists($ref['draft_id'],$drafts)) {
                    ++$counts['unresolved_reference_messages'];++$counts['unlinked_manager_text_messages'];continue;
                }
                $draft=$drafts[$ref['draft_id']];$sent=self::timestamp($row['created_at']);
                if ($draft===null || !$draft['proposal']['should_draft'] || $draft['requested']===null
                    || $draft['conversation_id']!==(int)$row['conversation_id'] || !self::positive($row['sender_id'])
                    || $draft['manager_id']!==(int)$row['sender_id'] || ($metadata['project_key']??null)!==$projectKey
                    || $ref['text_matches_proposal']!==hash_equals(hash('sha256',trim($draft['proposal']['draft'])),$ref['sent_text_sha256'])
                    || $row['channel']!==$row['conversation_channel'] || !in_array($row['channel'],['max','telegram','website'],true)
                    || $sent===null || $sent<$draft['requested'] || $sent-$draft['created']>86400) {
                    ++$counts['invalid_reference_messages'];++$counts['unlinked_manager_text_messages'];continue;
                }
                ++$counts['linked_messages'];$linked[$ref['draft_id']]=true;
                ++$counts[$ref['text_matches_proposal']?'recorded_same_messages':'recorded_changed_messages'];
                if ($draft['prepared']) {
                    ++$counts['prepared_linked_messages'];
                    ++$counts[$ref['text_matches_proposal']?'prepared_same_messages':'prepared_changed_messages'];
                }
                $edited=self::timestamp($metadata['edited_at']??null);
                $edited=$edited!==null && $edited>=$sent && $edited<=self::timestamp($until);
                if ($edited) ++$counts['edited_messages'];
                if (count($candidates)<self::SAMPLE_LIMIT) $candidates[]=['row'=>$row,'draft'=>$draft,'ref'=>$ref,'edited'=>$edited];
            }
            $q->closeCursor();
            $counts['linked_drafts']=count($linked);
            $samples=[];
            $q=$pdo->prepare('SELECT SUBSTR(text,1,120001) AS text FROM messages WHERE id=? AND conversation_id=?');
            foreach ($candidates as $candidate) {
                ['row'=>$row,'draft'=>$draft,'ref'=>$ref,'edited'=>$edited]=$candidate;
                $q->execute([$row['id'],$row['conversation_id']]);$stored=$q->fetchColumn();$q->closeCursor();
                if (!is_string($stored) || preg_match('//u',$stored)!==1) continue;
                $raw=htmlspecialchars_decode($stored,ENT_QUOTES);
                $bounded=self::text($raw,1,20000) && strlen($stored)<=120000;
                $initial=$bounded && hash_equals($ref['sent_text_sha256'],hash('sha256',trim($raw)));
                [$proposalText,$proposalTruncated]=self::sampleText(trim($draft['proposal']['draft']));
                [$sentText,$sentTruncated]=self::sampleText($raw);
                $samples[]=['draft_id'=>$ref['draft_id'],'conversation_id'=>(int)$row['conversation_id'],'message_id'=>(int)$row['id'],
                    'source'=>$draft['prepared']?'prepared':'generated',
                    'proposed_text'=>$proposalText,'current_sent_text'=>$sentText,
                    'proposed_text_truncated'=>$proposalTruncated,'current_sent_text_truncated'=>$sentTruncated||strlen($stored)>120000,
                    'initial_text_available'=>$initial,'recorded_text_matches_proposal'=>$ref['text_matches_proposal'],
                    'edited'=>$edited,'created_at'=>$draft['created_at'],'sent_at'=>$row['created_at']];
            }
            $summary['ok']=true;$summary['counts']=$counts;
            $summary['complete']=!$summary['draft_limit_reached']&&!$summary['message_limit_reached'];
            return ['summary'=>self::publicSummary($summary),'samples'=>$samples];
        } catch (Throwable $ignored) {
            return self::failure($summary,'storage_unavailable');
        } finally {
            if ($owned && $pdo->inTransaction()) {try {$pdo->rollBack();} catch(Throwable $ignored) {}}
        }
    }

    /** Defense in depth for public compose: no extra nested/private fields escape. */
    public static function publicSummary(array $value): array
    {
        $out=[];
        foreach (['ok','complete','draft_limit_reached','message_limit_reached'] as $key) $out[$key]=($value[$key]??null)===true;
        $out['error']=in_array($value['error']??null,['invalid_project','borrowed_transaction','storage_unavailable'],true)?$value['error']:null;
        $out['generated_at']=is_string($value['generated_at']??null)&&preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D',$value['generated_at'])===1?$value['generated_at']:null;
        $window=is_array($value['window']??null)?$value['window']:[];
        $out['window']=['since'=>self::timestamp($window['since']??null)!==null?$window['since']:null,
            'until'=>self::timestamp($window['until']??null)!==null?$window['until']:null,
            'timezone'=>'UTC','days'=>7,'basis'=>'persisted_generation_cohort'];
        $out['limits']=['draft_rows'=>self::DRAFT_LIMIT,'message_rows'=>self::MESSAGE_LIMIT,'samples'=>self::SAMPLE_LIMIT,'sample_characters'=>self::SAMPLE_CHARACTERS];
        $counts=is_array($value['counts']??null)?$value['counts']:[];$out['counts']=[];
        foreach (self::COUNTS as $key) $out['counts'][$key]=$out['ok']&&is_int($counts[$key]??null)&&$counts[$key]>=0?$counts[$key]:null;
        return $out;
    }

    private static function failure(array $summary,string $error): array
    {
        $summary['ok']=false;$summary['error']=$error;$summary['complete']=false;
        $summary['counts']=array_fill_keys(self::COUNTS,null);
        return ['summary'=>self::publicSummary($summary),'samples'=>[]];
    }
    private static function metadata($json): ?array
    {
        if ($json===null || $json==='') return [];
        if (!is_string($json)||strlen($json)>8192||!str_starts_with(ltrim($json),'{')) return null;
        try {$v=json_decode($json,true,16,JSON_THROW_ON_ERROR);} catch(Throwable $ignored) {return null;}
        return is_array($v)?$v:null;
    }
    private static function proposal($json): ?array
    {
        if (!is_string($json)||strlen($json)>32768||!str_starts_with(ltrim($json),'{')) return null;
        try {$v=json_decode($json,true,16,JSON_THROW_ON_ERROR);} catch(Throwable $ignored) {return null;}
        $fields=['ok','should_draft','draft','next_action','reason','checks','evidence_message_ids','history_incomplete'];
        if (!is_array($v)||count($v)!==8||array_diff($fields,array_keys($v))||$v['ok']!==true
            ||!is_bool($v['should_draft'])||!is_bool($v['history_incomplete'])
            ||!in_array($v['next_action'],['clarify','answer_question','check_offer','prepare_selection','no_contact'],true)
            ||!self::text($v['reason'],1,1000)||!is_array($v['checks'])||!array_is_list($v['checks'])||count($v['checks'])>10
            ||!is_array($v['evidence_message_ids'])||!array_is_list($v['evidence_message_ids'])
            ||!$v['evidence_message_ids']||count($v['evidence_message_ids'])>20) return null;
        if ($v['should_draft']?($v['next_action']==='no_contact'||!self::text($v['draft'],1,3000)):$v['draft']!==null) return null;
        foreach ($v['checks'] as $check) if (!self::text($check,1,400)) return null;
        $seen=[];foreach($v['evidence_message_ids'] as $id) {if(!is_int($id)||$id<=0||isset($seen[$id]))return null;$seen[$id]=true;}
        return $v;
    }
    private static function text($value,int $min,int $max): bool
    {
        return is_string($value)&&strlen($value)<=$max*4&&preg_match('//u',$value)===1
            &&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===0
            &&preg_match_all('/./us',$value)<=$max&&preg_match_all('/./us',trim($value))>=$min;
    }
    private static function sampleText(string $value): array
    {
        preg_match_all('/./us',$value,$characters);
        return [implode('',array_slice($characters[0],0,self::SAMPLE_CHARACTERS)),count($characters[0])>self::SAMPLE_CHARACTERS];
    }
    private static function positive($value): bool {return (is_int($value)&&$value>0)||(is_string($value)&&preg_match('/\A[1-9][0-9]*\z/D',$value)===1&&filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])!==false);}
    private static function hex($value,int $length): bool {return is_string($value)&&preg_match('/\A[a-f0-9]{'.$length.'}\z/D',$value)===1;}
    private static function timestamp($value): ?int
    {
        if(!is_string($value))return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d H:i:s')===$value?$date->getTimestamp():null;
    }
}
