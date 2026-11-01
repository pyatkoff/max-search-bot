<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerPreparedInputEvidence.php';

/** Private immutable receipts. Canonical job owner supplies authorization and transaction. */
final class ManagerPreparedInputService
{
    public const MAX_PER_JOB=16;
    public static function matches(ManagerPreparedInputEvidence $e,array $job): bool
    {
        $p=$e->packet();$scope=($job['purpose']??'background')==='open_chat'?'opened_chat'
            :((int)$job['manager_id']===0?'unassigned_waiting':'assigned_manager');
        return $job['project_key']===ProjectConfig::projectId()&&$p['job_id']===$job['job_id']
            &&$p['revision']===$job['context_revision']&&$p['instruction_revision']===$job['instruction_revision']
            &&$p['latest_message_id']===(int)$job['latest_message_id']&&$p['preparation_scope']===$scope
            &&$p['context']['conversation_id']===(int)$job['conversation_id'];
    }
    public static function identity(int $admin,?string $connection): bool
    {return $admin>0&&is_string($connection)&&preg_match('/\A[a-f0-9]{32}\z/D',$connection)===1;}

    /** Caller holds the job lock, including the bounded issuance count. */
    public static function record(PDO $pdo,array $job,ManagerPreparedInputEvidence $e,int $admin,string $connection): array
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM manager_prepared_reply_inputs WHERE job_id=?');$q->execute([$job['job_id']]);$count=(int)$q->fetchColumn();$q->closeCursor();
        if($count>=self::MAX_PER_JOB)return ['captured'=>false,'reason'=>'receipt_limit'];
        $id=bin2hex(random_bytes(32));$at=gmdate('Y-m-d H:i:s');
        $q=$pdo->prepare('INSERT INTO manager_prepared_reply_inputs(receipt_id,job_id,admin_id,connection_id,packet_json,packet_sha256,issued_at) VALUES(?,?,?,?,?,?,?)');
        $q->execute([$id,$job['job_id'],$admin,$connection,$e->json(),$e->hash(),$at]);
        return ['captured'=>true,'receipt_id'=>$id,'packet_sha256'=>$e->hash(),'issued_at'=>$at,
            'provenance'=>'server_issued_context','external_model_input'=>'unknown'];
    }

    public static function receipt(PDO $pdo,string $id,array $job,int $admin,?string $connection): ?ManagerPreparedInputEvidence
    {
        if(!self::identity($admin,$connection)||preg_match('/\A[a-f0-9]{64}\z/D',$id)!==1)return null;
        $r=self::load($pdo,'i.receipt_id=? AND i.job_id=? AND i.admin_id=? AND i.connection_id=?',[$id,$job['job_id'],$admin,$connection]);
        $e=$r?self::restore($r):null;
        return $e&&self::matches($e,$job)?$e:null;
    }
    public static function linked(PDO $pdo,string $job): ?string
    {
        $q=$pdo->prepare('SELECT receipt_id FROM manager_prepared_reply_input_links WHERE job_id=?');$q->execute([$job]);$id=$q->fetchColumn();$q->closeCursor();return $id===false?null:$id;
    }
    public static function link(PDO $pdo,string $job,string $receipt): void
    {$pdo->prepare('INSERT INTO manager_prepared_reply_input_links(job_id,receipt_id) VALUES(?,?)')->execute([$job,$receipt]);}

    /** Historical packet only; current context/selection/provider is never read here. */
    public static function read(PDO $pdo,array $draft): array
    {
        try{
            $id=self::linked($pdo,$draft['preparation_job_id']);
            if($id===null)return ['available'=>false,'reason'=>'prepared_input_not_captured'];
            $r=self::load($pdo,'i.receipt_id=? AND i.job_id=?',[$id,$draft['preparation_job_id']]);
            $e=$r?self::restore($r):null;
            if(!$e)return ['available'=>false,'reason'=>'invalid_evidence'];
            $q=$pdo->prepare('SELECT job_id,conversation_id,manager_id,project_key,context_revision,instruction_revision,latest_message_id,purpose,status,SUBSTR(proposal_json,1,32769) AS proposal_json,proposal_sha256 FROM manager_prepared_replies WHERE job_id=?');
            $q->execute([$draft['preparation_job_id']]);$job=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();
            if(!$job||$job['status']!=='ready'||!self::matches($e,$job)||$job['project_key']!==$draft['project_key']
                ||(int)$job['conversation_id']!==(int)$draft['conversation_id']||(int)$job['latest_message_id']!==(int)$draft['latest_message_id']
                ||$job['instruction_revision']!==($draft['instruction_revision']??null)||$job['proposal_json']!==$draft['proposal_json']||!is_string($job['proposal_sha256'])
                ||!hash_equals($job['proposal_sha256'],hash('sha256',$job['proposal_json'])))return ['available'=>false,'reason'=>'invalid_evidence'];
            $proposal=json_decode($draft['proposal_json'],true,16,JSON_THROW_ON_ERROR);
            if(!is_array($proposal['evidence_message_ids']??null)||array_diff($proposal['evidence_message_ids'],array_column($e->packet()['context']['messages'],'id')))return ['available'=>false,'reason'=>'invalid_evidence'];
            return ['available'=>true,'input_sha256'=>$e->hash(),'input_evidence'=>json_decode($e->input()->json(),true,64,JSON_THROW_ON_ERROR),
                'provenance'=>'server_issued_context','external_model_input'=>'unknown','prepared_packet'=>$e->packet(),
                'input_receipt'=>$id,'issued_at'=>$r['issued_at']];
        }catch(Throwable $ignored){return ['available'=>false,'reason'=>'storage_unavailable'];}
    }
    private static function load(PDO $pdo,string $where,array $args): array|false
    {
        $binary=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'BINARY':'BLOB';
        $q=$pdo->prepare('SELECT SUBSTR(CAST(i.packet_json AS '.$binary.'),1,'.(ManagerPreparedInputEvidence::MAX_BYTES+1).') AS packet_json,i.packet_sha256,i.issued_at FROM manager_prepared_reply_inputs i WHERE '.$where);
        $q->execute($args);$r=$q->fetch(PDO::FETCH_ASSOC);$q->closeCursor();return $r;
    }
    private static function restore(array $r): ?ManagerPreparedInputEvidence
    {
        $j=$r['packet_json']??null;$h=$r['packet_sha256']??null;
        return is_string($j)&&is_string($h)&&preg_match('/\A[a-f0-9]{64}\z/D',$h)===1&&hash_equals($h,hash('sha256',$j))
            ?ManagerPreparedInputEvidence::fromJson($j):null;
    }
}
