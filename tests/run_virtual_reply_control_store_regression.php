<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/virtual_reply_controls.php';
$passed=0;$failed=0;
$check=static function(string $name,bool $ok) use (&$passed,&$failed):void {
    echo ($ok?'PASS ':'FAIL ').$name."\n";$ok?$passed++:$failed++;
};
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec('PRAGMA foreign_keys=ON');
samplingSchema($pdo);controlStoreSchema($pdo);controlStoreAssertions($pdo,$check);

$absent=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$optional=new VirtualReplyControlStore($absent);
$check('fully absent controls require no source or sampling schema reads',$optional->status()['status']==='migration_unavailable'&&!$optional->owns(str_repeat('a',64)));
VirtualReplyControlStore::invalidateSource($absent,str_repeat('a',64));VirtualReplyControlStore::invalidateGroup($absent,str_repeat('a',64));
$check('legacy ordinary purge hooks remain inert before migration052',!$optional->available());
$absent->exec('CREATE TABLE virtual_reply_control_runs (run_id TEXT)');
$check('partial control schema fails technically instead of falling back',controlStoreReject(fn()=>new VirtualReplyControlStore($absent),'virtual_reply_control_storage_unavailable'));
echo "Virtual reply control store regression: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
