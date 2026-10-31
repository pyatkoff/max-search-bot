<?php
declare(strict_types=1);

/** Private case judgements only. Caller owns current owner authorization and transaction. */
final class ManagerAiUsageAssessmentService
{
    private const FLAGS=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR;
    public const KINDS=['unchanged','style','meaning','unassessable'];
    public const DIMENSIONS=['none','conditions','last_question','selection','unnecessary_question','unsupported_claim','other'];
    public const EFFECTS=['unknown','better','worse','mixed'];

    public static function snapshot(array $message,array $draft,array $case,array $sent,array $input): array
    {
        $initial=!empty($sent['available'])?$sent['text']:($case['initial_text_available']?trim(htmlspecialchars_decode($message['text'],ENT_QUOTES)):null);
        $context=!empty($input['available'])?$input['input_evidence']['context']:null;
        $canJudge=$initial!==null&&$context!==null&&!$context['history_incomplete']&&!$context['media_unread']
            &&!$context['selections_link_unread']&&$input['input_evidence']['coverage']==='text_only'
            &&!array_filter($context['messages'],static fn($m)=>!empty($m['text_truncated'])||!empty($m['media_unread'])||!empty($m['selections_link_unread']));
        $basis=[$message['id'],$draft['draft_id'],$draft['conversation_id'],$draft['manager_id'],$draft['project_key'],
            $draft['context_revision'],$draft['instruction_revision'],$draft['latest_message_id'],$draft['preparation_job_id']??null,hash('sha256',$draft['proposal_json']),
            $initial===null?null:hash('sha256',$initial),$input['input_sha256']??null,$input['available']??false,$input['reason']??null];
        return ['comparison_sha256'=>hash('sha256',json_encode($basis,self::FLAGS)),
            'proposed_text'=>$case['proposed_text'],'initial_sent_text'=>$initial,'pair_available'=>$initial!==null,
            'pair_changed'=>$initial!==null&&$initial!==$case['proposed_text'],'can_judge_effect'=>$canJudge,
            'input_available'=>$context!==null,'input_reason'=>$context===null?($input['reason']??'not_recorded'):null,
            'input_sha256'=>$input['input_sha256']??null,'request_messages'=>$context['messages']??[],
            'input_partial'=>$context!==null&&!$canJudge,'sender_id'=>(int)$draft['manager_id'],'draft_id'=>$draft['draft_id']];
    }

    public static function read(PDO $pdo,int $actor,int $messageId,array $snapshot,?string $operation=null): array
    {
        $latest=self::latest($pdo,$messageId);$candidate=self::latest($pdo,$messageId,'proposal');$decision=null;
        if($candidate&&hash_equals($candidate['comparison_sha256'],$snapshot['comparison_sha256'])){
            $decision=self::latest($pdo,$messageId,'adjudication',$candidate['version']);
            if($decision&&!hash_equals($decision['comparison_sha256'],$snapshot['comparison_sha256']))throw new RuntimeException('invalid_assessment');
        }else $candidate=null;
        $q=$pdo->prepare('SELECT version,manager_id,phase,target_version,comparison_sha256,created_at FROM manager_ai_usage_assessments WHERE message_id=? ORDER BY version DESC LIMIT 5');$q->execute([$messageId]);$history=$q->fetchAll(PDO::FETCH_ASSOC);$q->closeCursor();
        foreach($history as &$h){$h['version']=(int)$h['version'];$h['manager_id']=(int)$h['manager_id'];$h['target_version']=(int)$h['target_version'];$h['stale']=!hash_equals($h['comparison_sha256'],$snapshot['comparison_sha256']);unset($h['comparison_sha256']);}unset($h);
        $receipt=null;if($operation!==null){if(!self::hex($operation,32))return self::error('invalid_request');
            $q=$pdo->prepare('SELECT message_id,version FROM manager_ai_usage_assessments WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$operation]);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            $receipt=['found'=>$r!==false&&(int)$r['message_id']===$messageId,'saved_version'=>$r&&(int)$r['message_id']===$messageId?(int)$r['version']:null];}
        $independent=$candidate&&$candidate['manager_id']!==$actor&&$snapshot['sender_id']!==$actor;
        // This means two distinct authorized accounts judged this one frozen case.
        // It is neither an overall quality gain nor a verified supplier/company fact.
        $confirmed=$candidate&&$decision&&$candidate['data']['kind']==='meaning'&&$candidate['data']['effect']==='better'
            &&$snapshot['can_judge_effect']&&$decision['data']['verdict']==='confirm'
            &&$decision['manager_id']!==$candidate['manager_id']&&$decision['manager_id']!==$snapshot['sender_id'];
        return ['ok'=>true,'version'=>$latest['version']??0,'snapshot'=>$snapshot,'candidate'=>$candidate,'decision'=>$decision,
            'can_adjudicate'=>(bool)$independent,'case_improvement_confirmed'=>(bool)$confirmed,'history'=>$history,'operation'=>$receipt];
    }

    /** Message lock held by caller serializes sequence/CAS across all owners. */
    public static function save(PDO $pdo,int $actor,int $messageId,array $snapshot,array $input,string $now): array
    {
        if(!is_int($input['version']??null)||$input['version']<0||!self::hex($input['operation_id']??null,32)
            ||!self::hex($input['comparison_sha256']??null,64)||!in_array($input['phase']??null,['proposal','adjudication'],true)
            ||!is_array($input['data']??null)||!is_int($input['target_version']??null))return self::error('invalid_request');
        $command=[];foreach(['message_id','case_revision','version','operation_id','comparison_sha256','phase','target_version','data'] as $key)$command[$key]=$input[$key]??null;
        $request=hash('sha256',json_encode($command,self::FLAGS));
        $q=$pdo->prepare('SELECT message_id,version,request_sha256 FROM manager_ai_usage_assessments WHERE manager_id=? AND operation_id=?');$q->execute([$actor,$input['operation_id']]);$receipt=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
        if($receipt)return (int)$receipt['message_id']===$messageId&&hash_equals($receipt['request_sha256'],$request)
            ?['ok'=>true,'saved_version'=>(int)$receipt['version'],'replayed'=>true]:self::error('operation_mismatch');
        if(!hash_equals($snapshot['comparison_sha256'],$input['comparison_sha256']))return self::error('comparison_changed');
        $latest=self::latest($pdo,$messageId);if(($latest['version']??0)!==$input['version'])return self::error('conflict');
        $data=$input['data'];$phase=$input['phase'];
        if(!self::validData($phase,$data))return self::error('invalid_assessment');
        if($phase==='proposal'){
            if($input['target_version']!==0||array_diff(array_keys($data),['kind','dimension','effect','reason','citation'])
                ||!in_array($data['kind']??null,self::KINDS,true)||!in_array($data['dimension']??null,self::DIMENSIONS,true)
                ||!in_array($data['effect']??null,self::EFFECTS,true))return self::error('invalid_assessment');
            if($data['kind']!=='unassessable'&&(!$snapshot['pair_available']||($data['kind']==='unchanged')===$snapshot['pair_changed']))return self::error('pair_unavailable');
            if(($data['kind']==='meaning')!==($data['dimension']!=='none')||($data['kind']!=='meaning'&&$data['effect']!=='unknown'))return self::error('invalid_assessment');
            if($data['effect']!=='unknown'&&!$snapshot['can_judge_effect'])return self::error('effect_unassessable');
            $required=$data['effect']!=='unknown';
        }else{
            if(array_diff(array_keys($data),['verdict','reason','citation'])||!in_array($data['verdict']??null,['confirm','reject','insufficient_context'],true))return self::error('invalid_assessment');
            $candidate=self::latest($pdo,$messageId,'proposal');
            if(!$candidate||$candidate['version']!==$input['target_version']||!hash_equals($candidate['comparison_sha256'],$snapshot['comparison_sha256']))return self::error('candidate_changed');
            if($candidate['manager_id']===$actor||$snapshot['sender_id']===$actor)return self::error('independent_owner_required');
            if($data['verdict']==='confirm'&&(!$snapshot['can_judge_effect']||$candidate['data']['kind']!=='meaning'||$candidate['data']['effect']==='unknown'))return self::error('effect_unassessable');
            $required=$data['verdict']==='confirm';
        }
        if(!self::citation($data['citation']??null,$snapshot,$required))return self::error('invalid_citation');
        $json=json_encode($data,self::FLAGS);if(strlen($json)>16000)return self::error('invalid_assessment');
        $version=$input['version']+1;$q=$pdo->prepare('INSERT INTO manager_ai_usage_assessments (message_id,version,draft_id,manager_id,operation_id,request_sha256,comparison_sha256,phase,target_version,assessment_json,assessment_sha256,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $q->execute([$messageId,$version,$snapshot['draft_id'],$actor,$input['operation_id'],$request,$snapshot['comparison_sha256'],$phase,$input['target_version'],$json,hash('sha256',$json),$now]);
        return ['ok'=>true,'saved_version'=>$version,'replayed'=>false];
    }
    private static function latest(PDO $pdo,int $messageId,?string $phase=null,?int $target=null): ?array
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $sql='SELECT version,manager_id,operation_id,comparison_sha256,phase,target_version,SUBSTR(CAST(assessment_json AS '.$binary.'),1,16001) AS assessment_json,assessment_sha256,created_at FROM manager_ai_usage_assessments WHERE message_id=?';$args=[$messageId];
        if($phase!==null){$sql.=' AND phase=?';$args[]=$phase;}if($target!==null){$sql.=' AND target_version=?';$args[]=$target;}
        $q=$pdo->prepare($sql.' ORDER BY version DESC LIMIT 1');$q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();if(!$r)return null;
        if((int)$r['version']<=0||(int)$r['manager_id']<=0||!self::hex($r['operation_id'],32)||!self::hex($r['comparison_sha256'],64)
            ||strlen($r['assessment_json'])>16000||!self::hex($r['assessment_sha256'],64)||!hash_equals($r['assessment_sha256'],hash('sha256',$r['assessment_json'])))throw new RuntimeException('invalid_assessment');
        $r['data']=json_decode($r['assessment_json'],true,16,JSON_THROW_ON_ERROR);unset($r['assessment_json'],$r['assessment_sha256']);
        if(!is_array($r['data'])||!self::validData($r['phase'],$r['data'])
            ||($r['phase']==='proposal'?(int)$r['target_version']!==0:((int)$r['target_version']<=0||(int)$r['target_version']>=(int)$r['version'])))throw new RuntimeException('invalid_assessment');
        $r['version']=(int)$r['version'];$r['manager_id']=(int)$r['manager_id'];$r['target_version']=(int)$r['target_version'];return $r;
    }
    private static function citation($c,array $snapshot,bool $required): bool
    {
        if($c===null)return !$required;
        if(!is_array($c)||count($c)!==2||!is_int($c['message_id']??null)||!self::text($c['quote']??null,1000))return false;
        foreach($snapshot['request_messages'] as $m)if($m['id']===$c['message_id']&&$m['sender_type']==='customer'&&empty($m['text_truncated'])
            &&empty($m['media_unread'])&&empty($m['selections_link_unread'])&&str_contains($m['text'],$c['quote']))return true;
        return false;
    }
    private static function validData(string $phase,array $d): bool
    {
        if(!self::text($d['reason']??null,2000)||!array_key_exists('citation',$d))return false;
        $c=$d['citation'];if($c!==null&&(!is_array($c)||count($c)!==2||!is_int($c['message_id']??null)||$c['message_id']<=0||!self::text($c['quote']??null,1000)))return false;
        if($phase==='proposal')return count($d)===5&&in_array($d['kind']??null,self::KINDS,true)&&in_array($d['dimension']??null,self::DIMENSIONS,true)
            &&in_array($d['effect']??null,self::EFFECTS,true)&&($d['kind']==='meaning')===($d['dimension']!=='none')
            &&($d['kind']==='meaning'||$d['effect']==='unknown')&&($d['effect']==='unknown'||$c!==null);
        return $phase==='adjudication'&&count($d)===3&&in_array($d['verdict']??null,['confirm','reject','insufficient_context'],true)&&($d['verdict']!=='confirm'||$c!==null);
    }
    private static function text($v,int $max): bool{return is_string($v)&&trim($v)!==''&&strlen($v)<=$max*4&&preg_match('//u',$v)===1&&preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$v)!==1&&preg_match_all('/./us',$v)<=$max;}
    private static function hex($v,int $n): bool{return is_string($v)&&preg_match('/\A[a-f0-9]{'.$n.'}\z/D',$v)===1;}
    private static function error(string $e): array{return ['ok'=>false,'error'=>$e];}
}
