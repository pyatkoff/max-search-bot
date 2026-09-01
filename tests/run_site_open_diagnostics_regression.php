<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/services/LiveSessionAnalyzer.php';

$failed=0;
function soCheck(string $name,bool $ok):void{global $failed;echo ($ok?'PASS  ':'FAIL  ').$name."\n";if(!$ok)$failed++;}

$conversation=['id'=>901,'project_key'=>'anytour','source_id'=>1,'channel'=>'max','status'=>'ai'];
$messages=[
    ['direction'=>'inbound','sender_type'=>'customer','text'=>'show_tours','created_at'=>'2026-09-01 20:00:00'],
];
$without=LiveSessionAnalyzer::analyze($conversation,$messages,[]);
soCheck('show_tours alone is not a real site open',empty($without['site_opened'])&&($without['site_opened_at']??null)===null);
soCheck('show_tours alone keeps tours_opened drop point',($without['drop_point']??'')==='tours_opened');

$events=[['event_type'=>'site_open','actor_type'=>'system','created_at'=>'2026-09-01 20:00:12']];
$with=LiveSessionAnalyzer::analyze($conversation,$messages,$events);
soCheck('site_open event marks real site entry',!empty($with['site_opened']));
soCheck('site_open timestamp is exposed',($with['site_opened_at']??null)==='2026-09-01 20:00:12');
soCheck('site_open becomes funnel drop point',($with['drop_point']??'')==='site_open');

$openTours=(string)file_get_contents(dirname(__DIR__).'/open_tours.php');
$snapshot=(string)file_get_contents(dirname(__DIR__).'/tools/live_session_snapshot.php');
soCheck('open_tours mirrors site_open into conversation DB',strpos($openTours,"ConversationRecorder::eventByChat('max', \$chatID, 'site_open'")!==false);
soCheck('snapshot exposes site_open summary',strpos($snapshot,"'site_opened'=>0")!==false&&strpos($snapshot,"'site_opened_at'")!==false&&strpos($snapshot,"\$summary['calendar_day']['site_opened']++")!==false);

echo "TOTAL ".(7-$failed)." PASS / {$failed} FAIL\n";
exit($failed?1:0);
