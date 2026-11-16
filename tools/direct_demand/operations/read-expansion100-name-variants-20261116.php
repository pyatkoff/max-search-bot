<?php
declare(strict_types=1);
// One bounded read of the existing stored catalogue. No publisher, supplier or DB writes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
function insist(bool $ok, string $code): void { if (!$ok) throw new RuntimeException($code); }
$out=['ok'=>false,'mode'=>'existing_catalogue_country_scoped_name_variants','at'=>gmdate('c'),'supplier_calls'=>0,'db_writes'=>0,'post_writes'=>0,'feed_writes'=>0,'site_writes'=>0,'native_bindings_accepted'=>0,'queries'=>[]];
try {
    $root='/var/www/anytoour/data/www/anytoour.ru';
    $endpoint=$root.'/data/hotel-search-v1.php';
    foreach ([$endpoint=>'e7c7cf9656feea4913a2d0d064462856deeec3c4',$root.'/data/db-v1.php'=>'c7e13f41be4a317087fc063da0db6f5dcf769e29'] as $path=>$blob) {
        insist(!is_link($path)&&is_file($path)&&realpath($path)===$path&&filesize($path)<=100000,'catalogue_source_unavailable');
        $bytes=file_get_contents($path);
        insist(is_string($bytes)&&hash('sha1','blob '.strlen($bytes)."\0".$bytes)===$blob,'catalogue_source_changed');
    }
    $groups=[
        ['AN-AE','Anantara',9,['EXP019','EXP021','EXP022','EXP024']],
        ['AN-TH','Anantara',2,['EXP006','EXP007','EXP009','EXP010','EXP011','EXP012','EXP013','EXP015']],
        ['AN-VN','Anantara',16,['EXP026','EXP027']],
        ['FS-MV','Four Seasons',8,['EXP074','EXP075','EXP076']],
        ['FS-AE','Four Seasons',9,['EXP082','EXP083','EXP084']],
        ['FS-TH','Four Seasons',2,['EXP077','EXP078','EXP079','EXP080']],
        ['FS-EG','Four Seasons',1,['EXP085','EXP086','EXP087','EXP088']],
        ['FS-TR','Four Seasons',4,['EXP089','EXP090']],
        ['MAR-MV','JW Marriott',8,['EXP056','EXP066']],
        ['SUN-MV','Siyam',8,['EXP054']],
        ['HIL-MV','SAii',8,['EXP067']],
    ];
    $started=microtime(true);
    foreach($groups as [$group,$query,$country,$workIds]) {
        insist(microtime(true)-$started<60,'catalogue_read_budget_reached');
        $args=['q'=>$query,'countryId'=>$country,'limit'=>20];
        $code='$_SERVER["DOCUMENT_ROOT"]='.var_export($root,true).';$_GET='.var_export($args,true).';require '.var_export($endpoint,true).';';
        $p=proc_open([PHP_BINARY,'-d','max_execution_time=10','-r',$code],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        insist(is_resource($p),'catalogue_process_failed');fclose($pipes[0]);
        $raw=stream_get_contents($pipes[1],262145);fclose($pipes[1]);
        stream_get_contents($pipes[2],4096);fclose($pipes[2]);$exit=proc_close($p);
        insist($exit===0&&is_string($raw)&&strlen($raw)<262145,'catalogue_response_invalid');
        $payload=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        insist(is_array($payload)&&($payload['ok']??false)===true&&($payload['source']??null)==='anytour-catalog'&&is_array($payload['items']??null),'catalogue_read_failed');
        foreach($payload['items'] as $item)insist(($item['country']['id']??null)===$country,'catalogue_country_mismatch');
        $out['queries'][]=['group'=>$group,'work_ids'=>$workIds,'request'=>$args,'response_sha256'=>hash('sha256',$raw),'possibly_truncated'=>count($payload['items'])===20,'response'=>$payload];
    }
    $out['ok']=count($out['queries'])===11;
    $out['duration_seconds']=round(microtime(true)-$started,3);
} catch(Throwable $e) {
    $out['error']=preg_match('/\A[a-z_]+\z/D',$e->getMessage())===1?$e->getMessage():'catalogue_read_failed';
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
exit($out['ok']?0:2);
