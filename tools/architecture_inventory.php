<?php

declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}

$root=dirname(__DIR__);
$areas=['handlers','actions','services','integrations','manager','website','cron','migrations','tests','tools'];
$runtimeAreas=['handlers','actions','services','integrations','manager','website','cron'];
$schemaInfrastructurePaths=['services/MigrationRunner.php'];
$result=[
    'ok'=>true,
    'schema_version'=>1,
    'generated_at'=>gmdate('c'),
    'areas'=>[],
    'hotspots'=>[],
    'signals'=>[
        'runtime_ddl'=>[],
        'schema_infrastructure_ddl'=>[],
        'direct_sql_writes'=>[],
        'authorization_mentions'=>[],
        'validation_mentions'=>[],
    ],
];

$phpJs=[];
foreach($areas as $area){
    $dir=$root.'/'.$area;
    $files=0;$bytes=0;$lines=0;
    if(is_dir($dir)){
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){
            if(!$file->isFile())continue;
            $files++;$bytes+=$file->getSize();
            $path=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
            $ext=strtolower($file->getExtension());
            if(in_array($ext,['php','js','css'],true)){
                $content=(string)file_get_contents($file->getPathname());
                $fileLines=substr_count($content,"\n")+1;$lines+=$fileLines;
                if(in_array($ext,['php','js'],true))$phpJs[]=['path'=>$path,'lines'=>$fileLines,'bytes'=>$file->getSize()];
                if(in_array($area,$runtimeAreas,true)&&$ext==='php'){
                    if(preg_match('/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i',$content)){
                        $signal=in_array($path,$schemaInfrastructurePaths,true)?'schema_infrastructure_ddl':'runtime_ddl';
                        $result['signals'][$signal][]=$path;
                    }
                    if(preg_match('/\b(?:INSERT\s+INTO|UPDATE\s+[A-Za-z_`]|DELETE\s+FROM)\b/i',$content))$result['signals']['direct_sql_writes'][]=$path;
                    if(preg_match('/\b(?:canEdit|canView|authorize|authorization|permission|role)\b/i',$content))$result['signals']['authorization_mentions'][]=$path;
                    if(preg_match('/\b(?:validate|validation|csrf|invalid_)\b/i',$content))$result['signals']['validation_mentions'][]=$path;
                }
            }
        }
    }
    $result['areas'][$area]=['files'=>$files,'bytes'=>$bytes,'code_lines'=>$lines];
}

usort($phpJs,static fn(array $a,array $b):int=>($b['lines']<=>$a['lines'])?:strcmp($a['path'],$b['path']));
foreach(array_slice($phpJs,0,20) as $entry){
    $severity=$entry['lines']>=800?'high':($entry['lines']>=400?'medium':'observe');
    $result['hotspots'][]=$entry+['severity'=>$severity];
}
foreach($result['signals'] as &$paths){$paths=array_values(array_unique($paths));sort($paths);}unset($paths);

if(in_array('--compact',$argv,true)){
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}else{
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
}
