<?php
declare(strict_types=1);
function needMeta18(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
needMeta18(PHP_SAPI==='cli'&&$argc===3,'exact_private_input_and_marker_paths_required');
$raw=file_get_contents($argv[1]);needMeta18(is_string($raw)&&strlen($raw)>0,'actual_private_input_required');
$input=json_decode($raw,true,256,JSON_THROW_ON_ERROR);needMeta18(($input['operation']??'')==='commercial-hotel-search-links-20261105-context18-ten2'&&($input['quote_state']??'')==='NOQUOTE','actual_new_context_input_required');
define('APPROVED_CONTEXT18_INPUT_SHA256',hash('sha256',$raw));needMeta18(!defined('APPROVED_CONTEXT17_INPUT_SHA256'),'old_context_constant_must_be_absent');
$originalError=false;$out=['installed_sha'=>'1fec9202b45a8e1a6343750a8896a6e5072e1880'];
try{$out['input_sha256']=APPROVED_CONTEXT17_INPUT_SHA256;}catch(Error $e){$originalError=$e->getMessage()==='Undefined constant "APPROVED_CONTEXT17_INPUT_SHA256"';}
needMeta18($originalError&&!array_key_exists('input_sha256',$out),'exact_original_final_metadata_undefined_constant_not_reproduced');
$out['input_sha256']=APPROVED_CONTEXT18_INPUT_SHA256;needMeta18($out['input_sha256']===hash('sha256',$raw),'corrected_exact_actual_input_metadata_failed');
file_put_contents($argv[2],json_encode(['original_error_reproduced'=>true,'corrected_input_sha256'=>$out['input_sha256']],JSON_THROW_ON_ERROR)."\n");
echo "CONTEXT18_ORIGINAL_FINAL_METADATA_ERROR_AND_CORRECTED_ACTUAL_INPUT_CASES_OK new_cases=2 old_fixtures0 provider0 db0\n";
