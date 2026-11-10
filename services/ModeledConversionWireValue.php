<?php
declare(strict_types=1);
require_once __DIR__.'/MetrikaOfflineClient.php';
require_once __DIR__.'/ModeledConversionValueContract.php';

/** Fixed wire correction evidence. No worker, timer, MCP action or automatic POST. */
final class ModeledConversionWireValue
{
    public static function key(array $r):string
    {
        if(($r['project_key']??null)!=='anytour'||(string)($r['counter_id']??'')!=='98615635'
            ||($r['transport_target']??null)!==ModeledConversionValueContract::TARGET
            ||!is_string($r['client_id']??null)||preg_match('/\A[1-9][0-9]{0,39}\z/D',$r['client_id'])!==1
            ||!is_numeric($r['event_unix']??null)||(int)$r['event_unix']<946684800)throw new InvalidArgumentException('wire_scope');
        return hash('sha256',implode('|',['anytour','98615635',$r['client_id'],ModeledConversionValueContract::TARGET,(string)(int)$r['event_unix']]));
    }
    /** A stable total replaces the same provider key; individual monetary rows remain unchanged. */
    public static function plan(array $rows,int $now):array
    {
        require_once __DIR__.'/ModeledConversionVisitProof.php';
        if(count($rows)<2||count($rows)>16)throw new InvalidArgumentException('wire_members');
        usort($rows,static fn(array $a,array $b):int=>strlen((string)$a['id'])<=>strlen((string)$b['id'])?:strcmp((string)$a['id'],(string)$b['id']));
        $first=$rows[0];$key=self::key($first);$total=0;$members=[];$failed=false;
        foreach($rows as$r){
            $id=(string)($r['id']??'');
            if(preg_match('/\A[1-9][0-9]{0,19}\z/D',$id)!==1||isset($members[$id])||self::key($r)!==$key
                ||!in_array($r['state']??null,['processed','unmatched'],true)
                ||!in_array($r['upload_status']??null,['PROCESSED','LINKAGE_FAILURE'],true)
                ||!is_string($r['upload_id']??null)||preg_match('/\A[1-9][0-9]{0,19}\z/D',$r['upload_id'])!==1
                ||(int)$r['event_unix']>$now)throw new InvalidArgumentException('wire_binding');
            MetrikaOfflineClient::modeledValueCsv($r,(int)$r['event_unix']); // Pure historical wire validation, not upload permission.
            $members[$id]=ModeledConversionVisitProof::fingerprint($r);$total+=(int)$r['value_rub'];
            $failed=$failed||$r['upload_status']==='LINKAGE_FAILURE';
        }
        if(!$failed||$total>ModeledConversionValueContract::MAX_VALUE_RUB)throw new InvalidArgumentException('wire_total');
        $event=$first;$event['value_rub']=$total;
        $comment='AnyTourWire'.hash('sha256',json_encode([$key,$total,$members],JSON_THROW_ON_ERROR));
        return ['wire_key'=>$key,'members'=>$members,'total_rub'=>$total,'comment'=>$comment,
            'event'=>$event,'csv'=>MetrikaOfflineClient::modeledValueCsv($event,(int)$event['event_unix'])];
    }
    private static function path(array $row):string
    {
        return dirname(__DIR__,2).'/max-search-bot-modeled-visit-proof/wire-corrections/'.self::key($row).'.json';
    }
    public static function read(array $row):?array
    {
        $path=self::path($row);$dir=dirname($path);
        if(!is_file($path))return null;
        $owner=fileowner(__FILE__);
        if(is_link($dir)||is_link($path)||(fileperms($dir)&0777)!==0700||(fileperms($path)&0777)!==0600
            ||fileowner($dir)!==$owner||fileowner($path)!==$owner||filesize($path)>16384)throw new RuntimeException('wire_journal_boundary');
        $v=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($v)||!is_array($v['receipt']??null)||!is_string($v['sha256']??null)
            ||!hash_equals($v['sha256'],hash('sha256',json_encode($v['receipt'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR))))
            throw new RuntimeException('wire_journal_hash');
        $r=$v['receipt'];
        if(($r['version']??null)!=='modeled_wire_correction_v1'||($r['wire_key']??null)!==self::key($row)
            ||!in_array($r['state']??null,['sending','accepted','unknown','processed','failed'],true)
            ||!is_int($r['total_rub']??null)||$r['total_rub']<=0||$r['total_rub']>ModeledConversionValueContract::MAX_VALUE_RUB
            ||!is_array($r['members']??null)||count($r['members'])<2||count($r['members'])>16
            ||!is_string($r['comment']??null)||preg_match('/\AAnyTourWire[a-f0-9]{64}\z/D',$r['comment'])!==1
            ||!is_int($r['sent_at_unix']??null)||!is_int($r['checked_at_unix']??null)
            ||($r['upload_id']!==null&&(!is_string($r['upload_id'])||preg_match('/\A[1-9][0-9]{0,19}\z/D',$r['upload_id'])!==1)))
            throw new RuntimeException('wire_journal_contract');
        return $r;
    }
    /** Durable sending intent must exist before the one explicitly authorized repair POST. */
    public static function save(array $row,array $receipt):void
    {
        if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==fileowner(__FILE__))
            throw new RuntimeException('wire_journal_writer');
        $path=self::path($row);$dir=dirname($path);$parent=dirname($dir);
        if(is_link($parent)||!is_dir($parent)||(fileperms($parent)&0777)!==0700||fileowner($parent)!==posix_geteuid()
            ||is_link($dir)||is_link($path))throw new RuntimeException('wire_journal_boundary');
        if(!is_dir($dir)&&!mkdir($dir,0700))throw new RuntimeException('wire_journal_directory');
        if((fileperms($dir)&0777)!==0700||fileowner($dir)!==posix_geteuid())throw new RuntimeException('wire_journal_boundary');
        if(file_exists($path)&&((fileperms($path)&0777)!==0600||fileowner($path)!==posix_geteuid()))
            throw new RuntimeException('wire_journal_boundary');
        $json=json_encode($receipt,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $data=json_encode(['sha256'=>hash('sha256',$json),'receipt'=>$receipt],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(strlen($data)>16384)throw new RuntimeException('wire_journal_size');
        $tmp=tempnam($dir,'.wire-');if($tmp===false)throw new RuntimeException('wire_journal_temporary');
        try{
            if(!chmod($tmp,0600))throw new RuntimeException('wire_journal_permissions');
            $h=fopen($tmp,'wb');if($h===false)throw new RuntimeException('wire_journal_open');
            try{if(fwrite($h,$data)!==strlen($data)||!fflush($h)||!fsync($h))throw new RuntimeException('wire_journal_write');}
            finally{fclose($h);}
            if(!rename($tmp,$path))throw new RuntimeException('wire_journal_rename');
            $d=fopen($dir,'r');if($d===false)throw new RuntimeException('wire_journal_directory_sync');
            try{if(!fsync($d))throw new RuntimeException('wire_journal_directory_sync');}finally{fclose($d);}
        }finally{if(is_file($tmp))unlink($tmp);}
    }
    /** Existing proof reader only observes the private correction; it can never replay it. */
    public static function observe(array $row,array $ledger,object $api,int $now):string
    {
        static $seen=[];$key=self::key($row).'|'.$now;if(isset($seen[$key]))return $seen[$key];
        $r=self::read($row);if($r===null)return $seen[$key]='not_recorded';
        $members=[];foreach($ledger as$m)if(isset($r['members'][(string)$m['id']]))$members[]=$m;
        try{$plan=self::plan($members,$now);}catch(Throwable $e){return $seen[$key]='invalid';}
        if($plan['wire_key']!==$r['wire_key']||$plan['members']!==$r['members']||$plan['total_rub']!==$r['total_rub']
            ||$plan['comment']!==$r['comment'])return $seen[$key]='invalid';
        if(in_array($r['state'],['processed','failed'],true)||$r['checked_at_unix']>$now-300)return $seen[$key]=$r['state'];
        $poll=$r['upload_id']===null?$api->findModeledWireCorrection($members,$now):$api->poll($r['upload_id']);
        if(is_array($poll)&&($poll['comment']??null)===$plan['comment']
            &&(int)($poll['source_quantity']??0)===1&&(int)($poll['line_quantity']??0)===1){
            $r['upload_id']=(string)$poll['id'];$r['upload_status']=$poll['status'];
            $r['state']=$poll['status']==='PROCESSED'?'processed':($poll['status']==='LINKAGE_FAILURE'?'failed':'accepted');
        }elseif($r['upload_id']===null)$r['state']='unknown';
        $r['checked_at_unix']=$now;self::save($row,$r);return $seen[$key]=$r['state'];
    }
}
