<?php
declare(strict_types=1);
// Fixed CLI bootstrap only; no CLI arguments or caller-supplied SQL/targets.
ini_set('display_errors','0');ini_set('log_errors','0');
$level=ob_get_level();ob_start(static fn(string $discard):string=>'');
$result=['operation'=>'hotel083-effective-privileges-readonly-v1','read_only'=>true,
    'provided_pdo_only'=>true,'role_activation_attempts'=>0,'migration_apply_attempts'=>0,
    'retirement_attempts'=>0,'new_start_allowed'=>false,'ddl_admission'=>false,
    'state'=>'HOLD','reason'=>'fixed_application_bootstrap_unavailable'];
try{
    $root='/var/www/anytoour/data/www/app.anytoour.ru';
    if(PHP_SAPI!=='cli'||count($argv)!==1||realpath(getcwd())!==$root
        ||!function_exists('posix_geteuid')||posix_geteuid()!==1012)
        throw new RuntimeException('fixed_scope_unconfirmed');
    require $root.'/config.php';
    require_once $root.'/services/ConversationDb.php';
    require_once $root.'/services/ProjectConfig.php';
    if(ProjectConfig::projectId()!=='anytour'||!ConversationDb::isConfigured())
        throw new RuntimeException('fixed_scope_unconfirmed');
    // One ordinary application SQL session in this standalone CLI invocation.
    // No alternate accounts, profiles, socket auth, sudo or role activation.
    $applicationPdo=ConversationDb::connection();
    $result=HotelWorkPrivilegeInventory::read($applicationPdo,(string)CONVERSATION_DB_NAME);
    unset($applicationPdo);
}catch(Throwable $ignored){
    // Fixed HOLD only. Raw bootstrap/config/PDO text never leaves this buffer.
}finally{
    while(ob_get_level()>$level)ob_end_clean();
}
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
