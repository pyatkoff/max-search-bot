<?php

declare(strict_types=1);

require_once __DIR__.'/fixtures/virtual_reply_sampling.php';
$passed=0;$failed=0;
$check=static function(string $name,bool $ok)use(&$passed,&$failed):void{
    echo ($ok?'PASS ':'FAIL ').$name."\n";$ok?$passed++:$failed++;
};
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('PRAGMA foreign_keys=ON');samplingSchema($db);
samplingAssertions($db,$check);
samplingSqliteCompatibilityAssertions($check);
echo "Virtual reply sampling regression: {$passed} passed, {$failed} failed\n";
exit($failed?1:0);
