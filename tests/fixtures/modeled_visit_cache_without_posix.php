<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');$ok=false;
try{
    if(count($argv)!==3||function_exists('posix_geteuid')||!ctype_digit($argv[2]))throw new RuntimeException();
    require_once dirname(__DIR__,2).'/services/ModeledConversionStatusBudget.php';
    require_once dirname(__DIR__,2).'/services/ModeledConversionVisitProof.php';
    $method=new ReflectionMethod(ModeledConversionVisitProof::class,'readCacheAt');
    $result=$method->invoke(null,$argv[1],(int)$argv[2],new ModeledConversionStatusBudget());
    $ok=($result['available']??false)===true&&($result['fresh']??false)===true;
}catch(Throwable $ignored){}
echo json_encode(['ok'=>$ok],JSON_THROW_ON_ERROR)."\n";exit($ok?0:1);
