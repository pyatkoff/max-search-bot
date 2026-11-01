<?php
declare(strict_types=1);
// Read-only commands only. No hidden apply, network probe, worker or model call.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==3||!in_array($argv[1],['inventory','verify-bindings','quarantine-preflight'],true)){
    fwrite(STDERR,"Usage: php tools/hotel_native_recovery.php inventory|verify-bindings|quarantine-preflight PRIVATE_REQUEST.json\n");exit(2);
}
try{
    require_once dirname(__DIR__).'/config.php';
    require_once dirname(__DIR__).'/services/ProjectAccessService.php';
    require_once dirname(__DIR__).'/services/HotelNativeBindingInventory.php';
    require_once dirname(__DIR__).'/services/HotelLegacyEffectQuarantine.php';
    ProjectAccessService::initializeReadOnly();
    if(!is_file($argv[2])||filesize($argv[2])>65536)throw new InvalidArgumentException();
    $a=json_decode((string)file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($a)||!is_int($a['admin_id']??null)||!is_string($a['connection_id']??null)||!is_string($a['queue_id']??null))throw new InvalidArgumentException();
    $c=new HotelNativeRecoveryContext(ConversationDb::connection(),$a['admin_id'],$a['connection_id']);
    $inventory=new HotelNativeBindingInventory($c);
    $result=match($argv[1]){
        'inventory'=>$inventory->read($a['queue_id']),
        'verify-bindings'=>$inventory->verifyHostExport($a['queue_id'],$a['expected_tasks'],$a['connector_id'],$a['host_export']),
        'quarantine-preflight'=>(new HotelLegacyEffectQuarantine($c))->preflight($a['queue_id']),
    };
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable$e){fwrite(STDERR,"{\"state\":\"HOLD\",\"reason\":\"native_recovery_unavailable\",\"dispatch_authority\":false}\n");exit(1);}
