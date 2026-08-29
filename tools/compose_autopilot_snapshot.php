<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

function readAutopilotJson(string $path,string $label):array
{
    if(!is_file($path)||!is_readable($path))throw new RuntimeException($label.'_missing');
    $raw=(string)file_get_contents($path);
    $decoded=json_decode($raw,true);
    if(!is_array($decoded))throw new RuntimeException($label.'_invalid_json');
    if(array_key_exists('ok',$decoded)&&empty($decoded['ok']))throw new RuntimeException($label.'_not_ok');
    return $decoded;
}

function migrationSummary(array $migrations):array
{
    $total=count($migrations);$pending=0;$checksumFailures=0;$latest=null;
    foreach($migrations as $migration){
        if(empty($migration['applied']))$pending++;
        if(array_key_exists('checksum_ok',$migration)&&empty($migration['checksum_ok']))$checksumFailures++;
        $version=(string)($migration['version']??'');
        if($version!==''&&($latest===null||version_compare($version,(string)$latest,'>')))$latest=$version;
    }
    return ['total'=>$total,'pending'=>$pending,'checksum_failures'=>$checksumFailures,'latest_version'=>$latest];
}

function flaggedLiveSessions(array $sessions,int $limit=30):array
{
    $out=[];
    foreach($sessions as $session){
        $flags=array_values(array_filter(array_map('strval',(array)($session['flags']??[]))));
        if(!$flags)continue;
        $out[]=[
            'conversation_id'=>(int)($session['conversation_id']??$session['id']??0),
            'flags'=>$flags,
            'drop_point'=>$session['drop_point']??null,
            'manager_response_bucket'=>$session['manager_response_bucket']??null,
            'manager_response_seconds'=>$session['manager_response_seconds']??null,
        ];
        if(count($out)>=$limit)break;
    }
    return $out;
}

try{
    if($argc<6)throw new RuntimeException('usage: compose_autopilot_snapshot.php production.json live.json handoff.json website.json ops.json [daily.json]');
    $production=readAutopilotJson($argv[1],'production');
    $live=readAutopilotJson($argv[2],'live');
    $handoff=readAutopilotJson($argv[3],'handoff');
    $website=readAutopilotJson($argv[4],'website');
    $ops=readAutopilotJson($argv[5],'ops');
    $daily=$argc>=7?readAutopilotJson($argv[6],'daily'):null;

    $snapshot=[
        'ok'=>true,
        'schema_version'=>1,
        'generated_at'=>gmdate('c'),
        'production'=>$production['production']??[],
        'migrations'=>migrationSummary((array)($production['migrations']??[])),
        'health'=>$production['health']??[],
        'manager'=>[
            'response'=>$production['manager_response_health']??[],
            'push'=>$production['manager_push_health']??[],
            'visibility'=>$production['manager_visibility']??[],
        ],
        'handoff'=>[
            'health'=>$production['handoff_integrity_health']??[],
            'snapshot_summary'=>$handoff['summary']??[],
            'ok'=>(bool)($handoff['ok']??false),
        ],
        'live'=>[
            'window_hours'=>$live['window_hours']??null,
            'since_utc'=>$live['since_utc']??null,
            'summary'=>$live['summary']??[],
            'flagged_sessions'=>flaggedLiveSessions((array)($live['sessions']??[])),
        ],
        'website'=>[
            'ok'=>(bool)($website['ok']??false),
            'summary'=>$website['summary']??[],
        ],
        'ops'=>$ops,
        'artifacts'=>[
            'production'=>'production_snapshot.json',
            'live'=>'live_session_report.json',
            'handoff'=>'handoff.json',
            'website'=>'website_smoke.json',
            'ops'=>'ops_status.json',
        ],
    ];
    if(is_array($daily)){
        $snapshot['daily']=[
            'window_hours'=>$daily['window_hours']??null,
            'since_utc'=>$daily['since_utc']??null,
            'summary'=>$daily['summary']??[],
        ];
        $snapshot['artifacts']['daily']='daily_session_report.json';
    }

    $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE);
    if($json===false)throw new RuntimeException('autopilot_snapshot_json_encode_failed');
    echo $json."\n";
}catch(Throwable $e){
    fwrite(STDERR,json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
    exit(1);
}
