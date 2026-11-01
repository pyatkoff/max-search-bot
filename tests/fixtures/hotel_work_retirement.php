<?php
declare(strict_types=1);
function hrtSchema(PDO $p):void
{
    $mysql=$p->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    if($mysql&&preg_match('/\Amaxbot_prepared_test(?:_[a-zA-Z0-9_]+)?\z/D',(string)$p->query('SELECT DATABASE()')->fetchColumn())!==1)throw new RuntimeException('unsafe_retirement_fixture');
    hrtCleanup($p);$p->exec('DROP TABLE IF EXISTS hotel_work_events');
    $event=(string)file_get_contents(dirname(__DIR__,2).'/migrations/071_hotel_work_events.sql');
    $sql=(string)file_get_contents(dirname(__DIR__,2).'/migrations/083_hotel_work_retirement.sql');
    if($mysql){foreach(explode(';',$event."\n".$sql)as$s)if(trim($s)!=='')$p->exec($s);return;}
    $convert=static function(string$s):string{
        $s=preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin/','',$s);$s=str_replace([' ENGINE=InnoDB',' DEFAULT CHARSET=utf8mb4','INSERT IGNORE','LONGBLOB'],['','','INSERT OR IGNORE','BLOB'],$s);
        $s=preg_replace('/UNIQUE KEY [a-zA-Z0-9_]+\s*\(/','UNIQUE(',$s);return preg_replace('/,\s*KEY [a-zA-Z0-9_]+\s*\([^)]*\)/','',$s);
    };
    $p->exec($convert($event));$p->exec($convert(substr($sql,0,strpos($sql,'CREATE TRIGGER'))));
    foreach(HotelWorkRetirement::TABLES as$i=>$t)foreach(['insert','update','delete']as$op){
        $keys=$op==='insert'?'NEW.queue_id':($op==='delete'?'OLD.queue_id':'OLD.queue_id,NEW.queue_id');
        $p->exec('CREATE TRIGGER hotel_retired_'.$i.'_'.$op.' BEFORE '.strtoupper($op).' ON '.$t.' WHEN EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id IN('.$keys.")) BEGIN SELECT RAISE(ABORT,'retired_queue'); END");
    }
    foreach(['insert','update']as$op)$p->exec('CREATE TRIGGER hotel_retired_lane_'.$op.' BEFORE '.strtoupper($op)." ON hotel_work_lane WHEN EXISTS(SELECT 1 FROM hotel_work_retirements WHERE queue_id=NEW.queue_id) BEGIN SELECT RAISE(ABORT,'retired_queue'); END");
    foreach(['update','delete']as$op)$p->exec('CREATE TRIGGER hotel_retirement_immutable_'.$op.' BEFORE '.strtoupper($op)." ON hotel_work_retirements BEGIN SELECT CASE WHEN (SELECT COUNT(*) FROM hotel_retirement_gate)>0 THEN RAISE(ABORT,'immutable_retirement') END; END");
}
function hrtCleanup(PDO$p):void
{
    foreach(HotelWorkRetirement::TABLES as$i=>$t)foreach(['insert','update','delete']as$op)$p->exec('DROP TRIGGER IF EXISTS hotel_retired_'.$i.'_'.$op);
    foreach(['hotel_retired_lane_insert','hotel_retired_lane_update','hotel_retirement_immutable_update','hotel_retirement_immutable_delete']as$n)$p->exec('DROP TRIGGER IF EXISTS '.$n);
    $p->exec('DROP TABLE IF EXISTS hotel_work_retirements');$p->exec('DROP TABLE IF EXISTS hotel_retirement_gate');
}

