<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
ini_set('display_errors','0');ini_set('log_errors','0');error_reporting(0);
try{
    $root=(string)($argv[1]??'');$nonce=(string)($argv[2]??'');$kind=(string)($argv[3]??'');
    if(!is_dir($root)||!preg_match('/\A[a-f0-9]{32}\z/D',$nonce)||!in_array($kind,['legacy','canonical'],true))throw new RuntimeException();
    $level=ob_get_level();ob_start();
    try{require $root.'/config.php';require_once $root.'/services/LeadBridgeConfig.php';}finally{while(ob_get_level()>$level)ob_end_clean();}
    $secret=LeadBridgeConfig::secret();if($secret==='')throw new RuntimeException();
    $proof=hash_hmac('sha256',$nonce,hash_hmac('sha256','anytour-max-tracker-lookup-v1',$secret,true));
    $result=['ok'=>true,'kind'=>$kind,'proof'=>$proof,'tracker_ready'=>null];
    if($kind==='legacy'){
        $level=ob_get_level();ob_start();try{$s=require dirname($root).'/bitrix/.settings.php';}finally{while(ob_get_level()>$level)ob_end_clean();}
        $d=$s['connections']['value']['default']??null;if(!is_array($d))throw new RuntimeException();
        $pdo=new PDO('mysql:host='.$d['host'].';dbname='.$d['database'].';charset=utf8mb4',$d['login'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
        try{
            $pdo->query('SELECT ID,UF_CHAT_ID,UF_CLIENT_ID,UF_METRIC_SOURCE,UF_SOURCE,UF_DATE FROM max_sub_links LIMIT 0');
            $count=(int)$pdo->query("SELECT COUNT(*) FROM b_iblock_property p JOIN b_iblock_element_property ep ON ep.IBLOCK_PROPERTY_ID=p.ID WHERE p.IBLOCK_ID=8 AND p.CODE='YA_METRIK_ID' AND ep.VALUE='98615635'")->fetchColumn();
            $result['tracker_ready']=$count>0;$result['counter_configurations']=$count;
        }finally{$pdo->rollBack();}
    }
    echo json_encode($result,JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $ignored){echo '{"ok":false,"reason":"preflight_unavailable"}'."\n";exit(1);}
