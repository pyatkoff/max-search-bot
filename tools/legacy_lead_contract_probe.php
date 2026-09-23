<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){exit(2);}
$docRoot=rtrim((string)($argv[1]??''),'/');
if($docRoot===''||!is_file($docRoot.'/bitrix/modules/main/include/prolog_before.php')){fwrite(STDERR,"missing_bitrix_prolog\n");exit(2);}
$_SERVER['DOCUMENT_ROOT']=$docRoot;
require_once $docRoot.'/bitrix/modules/main/include/prolog_before.php';
$module=class_exists('Bitrix\\Main\\Loader')&&\Bitrix\Main\Loader::includeModule('iblock');
$iblock=$module?\CIBlock::GetByID(4)->Fetch():false;
$section=$module?\CIBlockSection::GetByID(26)->Fetch():false;
$codes=['NAME','DATE','PHONE','DEPARTURE','COUNTRY','PEOPLE','MEAL','NIGHTS','COMMENTS','STATUS','SOURCE','IS_ANYTOUR_ONLINE'];
$props=[];
if($module){
  $rs=\CIBlockProperty::GetList(['SORT'=>'ASC'],['IBLOCK_ID'=>4]);
  while($p=$rs->Fetch()){
    $code=(string)($p['CODE']??'');
    if(in_array($code,$codes,true))$props[$code]=[
      'active'=>(string)($p['ACTIVE']??''),
      'required'=>(string)($p['IS_REQUIRED']??''),
      'type'=>(string)($p['PROPERTY_TYPE']??''),
      'multiple'=>(string)($p['MULTIPLE']??''),
    ];
  }
}
$fields=[
 'IBLOCK_ID'=>4,'IBLOCK_SECTION_ID'=>26,
 'PROPERTY_VALUES'=>[
   'NAME'=>'Synthetic','DATE'=>date('d.m.Y H:i:s'),'PHONE'=>'79990000000',
   'DEPARTURE'=>'Москва','COUNTRY'=>'Турция','PEOPLE'=>'Взрослых: 2',
   'MEAL'=>'','NIGHTS'=>'','COMMENTS'=>'synthetic contract probe','STATUS'=>9,'SOURCE'=>36,
 ],
 'NAME'=>'Synthetic lead contract probe','ACTIVE'=>'Y',
];
$checkSupported=false;$checkOk=null;$errorClass='none';
if($module&&class_exists('CIBlockElement')){
  $el=new \CIBlockElement();
  $checkSupported=method_exists($el,'CheckFields');
  if($checkSupported){
    try{$checkOk=(bool)$el->CheckFields($fields,false);}
    catch(Throwable $e){$checkOk=false;$errorClass='exception_'.get_class($e);}
    if($checkOk===false&&$errorClass==='none'){
      $err=mb_strtolower(trim((string)($el->LAST_ERROR??'')),'UTF-8');
      if($err==='')$errorClass='empty_error';
      elseif(str_contains($err,'раздел')||str_contains($err,'section'))$errorClass='section';
      elseif(str_contains($err,'обяз')||str_contains($err,'required'))$errorClass='required';
      elseif(str_contains($err,'свойств')||str_contains($err,'property'))$errorClass='property';
      elseif(str_contains($err,'инфоблок')||str_contains($err,'iblock'))$errorClass='iblock';
      elseif(str_contains($err,'дата')||str_contains($err,'date'))$errorClass='date';
      else $errorClass='other';
    }
  }
}
$missing=array_values(array_diff($codes,array_keys($props)));
$out=[
 'ok'=>$module&&is_array($iblock)&&is_array($section)&&((int)($section['IBLOCK_ID']??0)===4),
 'module'=>$module,
 'iblock_exists'=>is_array($iblock),
 'section_exists'=>is_array($section),
 'section_iblock_id'=>is_array($section)?(int)($section['IBLOCK_ID']??0):0,
 'properties'=>$props,
 'missing_property_codes'=>$missing,
 'check_fields_supported'=>$checkSupported,
 'check_fields_ok'=>$checkOk,
 'check_fields_error_class'=>$errorClass,
];
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";
