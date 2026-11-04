<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','0');
const OPERATION='commercial-miniapp-current-source-contract-20261104-read3';
$targets=[
 ['label'=>'max2_script3','root'=>'/var/www/www-root/data/www/tour-max.ru/new/max2','name'=>'script3.php'],
 ['label'=>'tg_tour_max_index','root'=>'/var/www/www-root/data/www/tour-max.ru/tgmini','name'=>'index.php'],
 ['label'=>'tg_rhat_index','root'=>'/var/www/www-root/data/www/tg.rhat.ru/tgmini','name'=>'index.php'],
 ['label'=>'tg_tgz_index','root'=>'/var/www/www-root/data/www/tgz.ru/tgmini','name'=>'index.php']
];
function safe_literal(string $raw):string {
 $value=substr($raw,1,-1);
 $safe=['ok','saved','publicationOnly','publicationId','offerId','link','mode','production','post_id','offer_id','post_url','platform','bot_id','channel_id','project_id','context','ctx1','start_param','startapp','startParam','initData','clientId','yclid','region','campaign','telegram','max','post1_','post1','Any_tour_bot','anyru_bot','id9704048781_2_bot','id9704048781_1_bot','sha256','sha1','HMAC','auth_date','hash','signature','user','id','checkLink','getLink','getLinkNew','getLinkBySource','saveLink','saveQuery','post-routes.json','routes','posts','funnel','production.php','lib.php'];
 return in_array($value,$safe,true)?$raw:"'[REDACTED_LITERAL]'";
}
function contract_projection(string $source):string {
 $out='';
 foreach(token_get_all($source) as $token){
  if(!is_array($token)){$out.=$token;continue;}
  [$type,$s]=$token;
  if(in_array($type,[T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML],true))continue;
  if($type===T_CONSTANT_ENCAPSED_STRING){$out.=safe_literal($s);continue;}
  if($type===T_ENCAPSED_AND_WHITESPACE){$out.='[REDACTED_LITERAL]';continue;}
  if(in_array($type,[T_LNUMBER,T_DNUMBER],true)){$out.='0';continue;}
  $out.=$s;
 }
 return trim($out);
}
if(getenv('MINIAPP_CONTRACT_FIXTURE')==='1'){
 $a=contract_projection('<?php $x="123456:abcdefghijklmnopqrstuvwxyz123456"; $id=9704048781; $r=["post_id"=>$p,"saved"=>false];');
 assert(!str_contains($a,'abcdefghijklmnopqrstuvwxyz')&&!str_contains($a,'9704048781'));
 if(str_contains($a,'abcdefghijklmnopqrstuvwxyz')||str_contains($a,'9704048781')||!str_contains($a,'"post_id"')||!str_contains($a,'"saved"'))exit(2);
 $a=contract_projection('<?php /* fake 123456 */ echo "raw-private"; $z="prefix $uid tail";');
 if(str_contains($a,'fake')||str_contains($a,'raw-private')||str_contains($a,'prefix')||str_contains($a,'tail'))exit(3);
 $a=contract_projection('<?php require __DIR__."/lib.php"; $r=["platform"=>"max","context"=>$ctx];');
 if(!str_contains($a,'"platform"')||!str_contains($a,'"context"')||!str_contains($a,'"max"'))exit(4);
 echo "MINIAPP_STATIC_REDACTION_OK new_cases=3 server_calls=0 provider_calls=0\n";exit;
}
$out=['operation'=>OPERATION,'ok'=>true,'mode'=>'static_source_projection_only','source_files_executed'=>0,'db_reads'=>0,'db_writes'=>0,'provider_calls'=>0,'message_sends'=>0,'runtime_writes'=>0,'files'=>[]];
foreach($targets as $target){
 $path=$target['root'].'/'.$target['name'];$r=['label'=>$target['label'],'exists'=>is_file($path),'readable'=>is_readable($path)];
 if($r['exists']&&$r['readable']){
  $size=filesize($path);if($size===false||$size>400000){$r['state']='SIZE_BOUND_STOP';$out['files'][]=$r;continue;}
  $s=file_get_contents($path);if(!is_string($s))throw new RuntimeException('STATIC_READ_FAILED');
  $r['bytes']=strlen($s);$r['sha256']=hash('sha256',$s);
  $r['indicators']=[];foreach(['ctx1','post1_','post_id','offer_id','publicationOnly','post-routes.json','Any_tour_bot','anyru_bot','initData','start_param','saveLink','getLink','YCLID','yclid','project_id','bot_id','channel_id'] as $k)$r['indicators'][$k]=str_contains($s,$k);
  $r['declared_local_contract_files']=[];
  if(preg_match_all('~[\'"](?:\./)?([A-Za-z0-9_./-]+\.(?:php|json|js))[\'"]~',$s,$m)){
   foreach(array_unique($m[1]) as $name){
    if(str_contains($name,'..')||str_starts_with($name,'/')||preg_match('~config|secret|token|credential|password|settings|archive|receipt|log~i',$name))continue;
    $r['declared_local_contract_files'][]=$name;
   }
  }
  $r['php_contract_projection']=str_ends_with($target['name'],'.php')?contract_projection($s):'';
  if(strlen($r['php_contract_projection'])>140000){unset($r['php_contract_projection']);$r['state']='PROJECTION_SIZE_BOUND_STOP';}
  $out['files'][]=$r;
 }else $out['files'][]=$r;
}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
