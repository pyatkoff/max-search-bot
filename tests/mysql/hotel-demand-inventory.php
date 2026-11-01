<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/hotel_demand_inventory_checks.php';

// Separate test connection with temporary tables only. Existing native fixture
// tables and the canonical selection-delivery scenario are left untouched.
$demandDsn=(string)getenv('PREPARED_REPLY_MYSQL_DSN');
if(!preg_match('/\Amysql:.*(?:\Amysql:|;)dbname=(maxbot_prepared_test(?:_[a-zA-Z0-9_]+)?)(?:;|\z)/D',$demandDsn,$demandMatch))throw new RuntimeException('unsafe_fixture_database');
$demandNative=new PDO($demandDsn,getenv('PREPARED_REPLY_MYSQL_USER')?:null,getenv('PREPARED_REPLY_MYSQL_PASS')?:null,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if($demandNative->query('SELECT DATABASE()')->fetchColumn()!==$demandMatch[1])throw new RuntimeException('unsafe_fixture_database');
[$demandSeed]=hotelDemandFixture();
foreach($demandSeed->query("SELECT name,sql FROM sqlite_master WHERE type='table'")->fetchAll() as $demandTable){
    $demandSql=preg_replace('/\bINTEGER\b/','BIGINT',$demandTable['sql']);
    $demandSql=str_replace('selection_json TEXT','selection_json MEDIUMTEXT',$demandSql);
    $demandNative->exec(preg_replace('/\ACREATE TABLE /','CREATE TEMPORARY TABLE ',$demandSql));
    foreach($demandSeed->query('SELECT * FROM '.$demandTable['name'])->fetchAll() as $demandRow){
        $demandInsert=$demandNative->prepare('INSERT INTO '.$demandTable['name'].' VALUES('.implode(',',array_fill(0,count($demandRow),'?')).')');
        $demandInsert->execute(array_values($demandRow));
    }
}
$demandBuffered=$demandNative->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
$demandResult=hotelDemandRead($demandNative);
deliveryCheck('native hotel demand typed source delivery inquiry and approved bundle read',
    $demandResult['status']==='available'&&$demandResult['counts']['delivered_card_exposures']===1&&$demandResult['counts']['native_inquiries']===1
    &&$demandResult['hotels'][0]['hotel_ref']===['catalog'=>'anytour','id'=>1554]);
deliveryCheck('native hotel demand read rolls back and restores caller buffering',
    !$demandNative->inTransaction()&&$demandNative->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)===$demandBuffered);
$demandNative->exec('DELETE FROM manager_tour_selection_publications');
$demandInsert=$demandNative->prepare('INSERT INTO manager_tour_selection_publications VALUES(?,?,?,?,?,?)');
for($demandIndex=1;$demandIndex<=6;++$demandIndex)$demandInsert->execute([str_pad(dechex($demandIndex),32,'0',STR_PAD_LEFT),10,str_repeat('a',64),'{}',str_repeat('x',900000),'2026-11-01 10:00:00']);
$demandResult=hotelDemandRead($demandNative);
deliveryCheck('native hotel demand cumulative byte refusal closes unbuffered cursor and transaction',
    ($demandResult['reason']??null)==='byte_limit'&&!$demandNative->inTransaction()
    &&$demandNative->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)===$demandBuffered
    &&(int)$demandNative->query('SELECT COUNT(*) FROM manager_tour_selection_publications')->fetchColumn()===6);
$demandNative=null;
