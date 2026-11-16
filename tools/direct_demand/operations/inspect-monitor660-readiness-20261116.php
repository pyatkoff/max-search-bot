<?php
declare(strict_types=1);
// Bounded read-only observation: no application bootstrap, provider, database or publisher calls.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
function check(bool $ok,string $code):void{if(!$ok)throw new RuntimeException($code);}
function bytes(string $path,int $limit):string {
    check(!is_link($path)&&is_file($path)&&realpath($path)===$path,'read_path_invalid');
    $f=fopen($path,'rb');check(is_resource($f),'read_open_failed');
    try{
        $before=fstat($f);check(is_array($before)&&$before['size']>0&&$before['size']<=$limit,'read_size_invalid');
        $raw=stream_get_contents($f,$limit+1);check(is_string($raw)&&strlen($raw)===$before['size'],'read_size_changed');
        clearstatcache(true,$path);$after=lstat($path);
        foreach(['dev','ino','size','mtime','ctime'] as $k)check(($after[$k]??null)===$before[$k],'read_changed');
        return $raw;
    }finally{fclose($f);}
}
function git_read(string $root,array $args):string {
    $p=proc_open(array_merge(['git','-C',$root],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    check(is_resource($p),'git_start_failed');fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1],1048577);fclose($pipes[1]);stream_get_contents($pipes[2],4096);fclose($pipes[2]);
    check(proc_close($p)===0&&is_string($stdout)&&strlen($stdout)<=1048576,'git_read_failed');return trim($stdout);
}
$out=['ok'=>false,'checked_at'=>gmdate('c'),'mode'=>'read_only_monitor660_readiness','server_writes'=>0,'database_calls'=>0,'supplier_calls'=>0,'post_writes'=>0,'feed_writes'=>0];
try{
    $app='/var/www/anytoour/data/www/app.anytoour.ru';check(!is_link($app)&&realpath($app)===$app,'app_path_changed');
    $head=git_read($app,['rev-parse','HEAD']);check(preg_match('/\A[a-f0-9]{40}\z/D',$head)===1,'git_head_invalid');
    $out['app']=['head'=>$head,'tracked_clean'=>git_read($app,['status','--porcelain','--untracked-files=no'])===''];
    $path='/var/www/anytoour/data/www/anytoour.ru/data/monitor-price-hook.php';$raw=bytes($path,2000000);
    check(preg_match('/const HOOK_ROSTER_COUNT\s*=\s*([0-9]+);/',$raw,$m)===1,'hook_count_missing');
    $out['installed_hook']=['sha256'=>hash('sha256',$raw),'bytes'=>strlen($raw),'roster_count'=>(int)$m[1],'matches_accepted660'=>hash('sha256',$raw)==='dd85c064745f77f2b72b637fa4b572562ffcc2a318c2f35578c016e6f5e88e72'];
    check(preg_match('/const HOOK_RUNTIME_PINS_B64="([A-Za-z0-9+\/=]+)";/',$raw,$m)===1&&strlen($m[1])<=16384,'hook_pins_invalid');
    $pins=json_decode(base64_decode($m[1],true),true,16,JSON_THROW_ON_ERROR);check(is_array($pins)&&count($pins)<=40,'hook_pins_scope_invalid');
    $mismatches=[];
    foreach($pins as $relative=>$expected){
        check(is_string($relative)&&preg_match('~\A(?:services|integrations|migrations)/[A-Za-z0-9_-]+\.(?:php|sql)\z~D',$relative)===1&&is_string($expected)&&preg_match('/\A[a-f0-9]{40}\z/D',$expected)===1,'hook_pin_scope_invalid');
        $source=bytes($app.'/'.$relative,2000000);$actual=hash('sha1','blob '.strlen($source)."\0".$source);
        if($actual!==$expected)$mismatches[]=['path'=>$relative,'expected'=>$expected,'actual'=>$actual];
    }
    $out['installed_hook']['runtime_pin_count']=count($pins);$out['installed_hook']['runtime_pin_mismatches']=$mismatches;
    $cohort=bytes('/var/www/anytoour/data/www/anytoour.ru/data/published-hotels-monitor-v1.php',1000000);
    $out['data_cohort']=['sha256'=>hash('sha256',$cohort),'expansion20_present'=>str_contains($cohort,'function v2_published_expansion20_monitor_ids_20261116()'),'expansion32_present'=>str_contains($cohort,'function v2_published_expansion32_monitor_ids_20261116()')];
    $j='/var/www/anytoour/data/channel-post-operations/monitor-price-after-collection-v1.jsonl';check(!is_link($j)&&realpath($j)===$j,'journal_path_invalid');
    $f=fopen($j,'rb');check(is_resource($f),'journal_open_failed');
    try{
        check(flock($f,LOCK_SH|LOCK_NB),'journal_busy_no_retry');$before=fstat($f);check(is_array($before)&&$before['size']>0,'journal_empty');
        $offset=max(0,$before['size']-4194304);check(fseek($f,$offset)===0,'journal_seek_failed');$tail=stream_get_contents($f,4194305);
        check(is_string($tail)&&strlen($tail)===$before['size']-$offset&&str_ends_with($tail,"\n"),'journal_tail_invalid');
        $body=substr($tail,0,-1);$separator=strrpos($body,"\n");check($offset===0||$separator!==false,'journal_last_line_exceeds_bound');
        $line=$separator===false?$body:substr($body,$separator+1);$state=json_decode($line,true,64,JSON_THROW_ON_ERROR);check(is_array($state)&&array_key_exists('pending',$state),'journal_state_invalid');
        clearstatcache(true,$j);$after=lstat($j);foreach(['dev','ino','size','mtime','ctime'] as $k)check(($after[$k]??null)===$before[$k],'journal_changed');
        $cycle=$state['nightly_cycle']??null;
        $out['journal']=['bytes'=>$before['size'],'last_line_sha256'=>hash('sha256',$line."\n"),'pending_is_null'=>$state['pending']===null,'pending_phase'=>is_array($state['pending'])?($state['pending']['phase']??null):null,'historical_completed_count'=>is_array($state['completed']??null)?count($state['completed']):null,'nightly_cycle'=>is_array($cycle)?array_intersect_key($cycle,array_flip(['collector_run_id','state','night_date','started_at','finished_at','updated_pairs','unchanged_pairs','held_pairs','scanned_pairs','remaining_unscanned_pairs','roster_count'])):null];
    }finally{flock($f,LOCK_UN);fclose($f);}
    check(git_read($app,['rev-parse','HEAD'])===$head,'app_head_changed_during_read');$out['ok']=true;
}catch(Throwable $e){$out['error']=preg_match('/\A[a-z0-9_]+\z/D',$e->getMessage())===1?$e->getMessage():'read_failed';}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";exit($out['ok']?0:2);
