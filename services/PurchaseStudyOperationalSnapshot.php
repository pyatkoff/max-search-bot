<?php
declare(strict_types=1);

require_once __DIR__.'/ModeledConversionPurchaseStudy.php';

/** Private technical projection only; no source queries, registration, or model work. */
final class PurchaseStudyOperationalSnapshot
{
    private const FILE_BYTES=2097152;
    private const STATE_BYTES=1048576;

    private static function dto(string $status):array
    {
        return [
            'status'=>$status,
            'supplier_version'=>ModeledConversionPurchaseStudy::VERSION,
            'protocol_registered_at_utc'=>null,
            'receipt_observed_at_utc'=>null,
            'protocol_sha256'=>null,
            'runtime_compatibility'=>'unknown',
            'ordinary_cron_provenance'=>'unknown',
        ];
    }

    public static function read():array
    {
        $lock=$file=null;
        try{
            $log=DiagnosticLogger::filePath();
            if($log==='')self::fail();
            $dir=dirname($log).'/purchase-study';
            if(!self::directory($dir))return self::dto('unknown');

            // Open the existing lock read-only: no creation, chmod, or repair.
            $lock=self::open($dir.'/lock',null);
            if(!flock($lock,LOCK_SH|LOCK_NB))return self::dto('busy');
            self::sameFile($dir.'/lock',$lock,null);

            $file=self::open($dir.'/state.json',self::FILE_BYTES);
            $raw=stream_get_contents($file,self::FILE_BYTES+1);
            if(!is_string($raw)||strlen($raw)>self::FILE_BYTES)self::fail();
            self::sameFile($dir.'/state.json',$file,self::FILE_BYTES);

            $wrapper=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
            $keys=['schema_version','state_json','state_sha256','receipt_json','receipt_sha256'];
            if(!is_array($wrapper)||count($wrapper)!==5
                ||array_diff($keys,array_keys($wrapper))
                ||$wrapper['schema_version']!==1)self::fail();
            foreach(['state','receipt']as$part){
                $json=$wrapper[$part.'_json'];$hash=$wrapper[$part.'_sha256'];
                if(!is_string($json)||!self::sha($hash)
                    ||!hash_equals($hash,hash('sha256',$json)))self::fail();
            }
            if(strlen($wrapper['state_json'])>self::STATE_BYTES)self::fail();

            $state=json_decode($wrapper['state_json'],true,256,JSON_THROW_ON_ERROR);
            $receipt=json_decode($wrapper['receipt_json'],true,8,JSON_THROW_ON_ERROR);
            if(!is_array($state)||!is_array($receipt)
                ||($state['kind']??null)!=='purchase_study_state'
                ||($state['version']??null)!=='purchase_study_runtime_v1'
                ||!is_array($state['manifest']??null)
                ||($state['manifest']['reader_kind']??null)!=='reviewed_runtime_reader'
                ||!self::sha($state['manifest']['reader_sha256']??null)
                ||!hash_equals(self::readerSha(),$state['manifest']['reader_sha256'])
                ||!is_array($state['cases']??null)||count($state['cases'])>256
                ||!is_int($state['monitor_cursor']??null)||$state['monitor_cursor']<0
                ||!self::id($state['profile_cursor']??null)
                ||!self::id($state['profile_fence']??null))self::fail();

            // No case iteration, outcome processing, or derived report.
            $registered=$state['manifest']['registered_at']??null;
            $observed=$receipt['observed_at_utc']??null;
            $registeredAt=self::instant($registered);$observedAt=self::instant($observed);
            $protocol=$state['protocol_sha256']??null;
            if($registeredAt>$observedAt||$observedAt>time()
                ||!self::sha($protocol)||($receipt['protocol_sha256']??null)!==$protocol)self::fail();
            self::sameFile($dir.'/lock',$lock,null);
            self::sameFile($dir.'/state.json',$file,self::FILE_BYTES);

            return array_replace(self::dto('saved_metadata_valid'),[
                'protocol_registered_at_utc'=>$registered,
                'receipt_observed_at_utc'=>$observed,
                'protocol_sha256'=>$protocol,
            ]);
        }catch(Throwable $ignored){
            return self::dto('failed');
        }finally{
            if(is_resource($file))fclose($file);
            if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}
        }
    }

    private static function directory(string $path):bool
    {
        $root=realpath(dirname(__DIR__));
        if($root===false||$path===''||$path[0]!=='/'||str_contains($path,"\0")
            ||$path===$root||str_starts_with($path,$root.'/'))self::fail();
        $walk='';
        foreach(explode('/',$path)as$part){
            if($part==='')continue;
            if(in_array($part,['.','..'],true))self::fail();
            $walk.='/'.$part;if(is_link($walk))self::fail();
        }
        clearstatcache(true,$path);$stat=@lstat($path);
        if($stat===false)return false;
        if(($stat['mode']&0170000)!==0040000||($stat['mode']&0777)!==0700)self::fail();
        return true;
    }

    private static function stat(string $path,?int $cap):array
    {
        clearstatcache(true,$path);$stat=@lstat($path);
        if(!$stat||($stat['mode']&0170000)!==0100000
            ||($stat['mode']&0777)!==0600||$stat['nlink']!==1
            ||($cap!==null&&$stat['size']>$cap))self::fail();
        return $stat;
    }

    private static function open(string $path,?int $cap)
    {
        $before=self::stat($path,$cap);$handle=@fopen($path,'rb');
        if(!is_resource($handle))self::fail();
        try{
            $open=fstat($handle);
            if(!$open||$open['ino']!==$before['ino']||$open['dev']!==$before['dev'])self::fail();
            self::sameFile($path,$handle,$cap);return $handle;
        }catch(Throwable $error){fclose($handle);throw $error;}
    }

    private static function sameFile(string $path,$handle,?int $cap):void
    {
        $stat=self::stat($path,$cap);$open=fstat($handle);
        if(!$open||$stat['ino']!==$open['ino']||$stat['dev']!==$open['dev']
            ||$stat['size']!==$open['size']||($open['mode']&0170000)!==0100000
            ||($open['mode']&0777)!==0600||$open['nlink']!==1)self::fail();
    }

    private static function instant($value):int
    {
        if(!is_string($value))self::fail();
        $time=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new DateTimeZone('UTC'));
        if(!$time||$time->format('Y-m-d\TH:i:s\Z')!==$value)self::fail();
        return $time->getTimestamp();
    }

    private static function id($value):bool
    {
        return (is_string($value)||is_int($value))
            &&preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',(string)$value)===1;
    }

    private static function sha($value):bool
    {
        return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;
    }

    private static function readerSha():string
    {
        // Exact existing recipe. This companion must remain outside its inputs.
        $hashes=[];
        foreach(['services/ModeledConversionPurchaseStudy.php','services/ModeledConversionOutcomeReceipt.php',
            'tools/purchase_conversion_study.py','tools/purchase_study_runtime.py',
            'tools/profile_conversion_journal.py','tools/extract_conversion_profiles.py']as$path){
            $hash=hash_file('sha256',dirname(__DIR__).'/'.$path);
            if(!is_string($hash))self::fail();$hashes[$path]=$hash;
        }
        ksort($hashes,SORT_STRING);
        return hash('sha256',json_encode($hashes,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    private static function fail():void
    {
        throw new RuntimeException('study_metadata_unavailable');
    }
}
