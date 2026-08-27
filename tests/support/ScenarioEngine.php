<?php

declare(strict_types=1);

final class ScenarioEngine
{
    /** @var array<string,callable> */
    private array $handlers;
    private int $passed=0;
    private int $failed=0;

    /** @param array<string,callable> $handlers */
    public function __construct(array $handlers)
    {
        $this->handlers=$handlers;
    }

    public function runDirectory(string $directory):int
    {
        $files=glob(rtrim($directory,'/').'/*.json')?:[];
        sort($files);
        $this->check('scenario corpus is non-empty',count($files)>0,true);
        foreach($files as $file)$this->runFile($file);
        return $this->failed;
    }

    public function report():void
    {
        $total=$this->passed+$this->failed;
        echo "\n--------------------------\n";
        echo "TOTAL {$total} | PASS {$this->passed} | FAIL {$this->failed}\n";
    }

    private function runFile(string $file):void
    {
        $raw=file_get_contents($file);
        $scenario=is_string($raw)?json_decode($raw,true):null;
        $name=basename($file);
        $this->check("{$name} parses",is_array($scenario),true);
        if(!is_array($scenario))return;

        $this->check("{$name} has id",trim((string)($scenario['id']??''))!=='',true);
        $this->check("{$name} has source",trim((string)($scenario['source']??''))!=='',true);
        $this->check("{$name} has suite",trim((string)($scenario['suite']??''))!=='',true);
        $this->check("{$name} has steps",!empty($scenario['steps'])&&is_array($scenario['steps']),true);
        if(empty($scenario['steps'])||!is_array($scenario['steps']))return;

        $context=['scenario'=>$scenario,'state'=>[]];
        foreach($scenario['steps'] as $index=>$step){
            $label=(string)($scenario['id']??$name).' step '.($index+1);
            if(!is_array($step)){
                $this->check("{$label} is object",false,true);
                continue;
            }
            $type=(string)($step['type']??'');
            $handler=$this->handlers[$type]??null;
            if(!is_callable($handler)){
                $this->check("{$label} known type",$type,implode('|',array_keys($this->handlers)));
                continue;
            }
            $result=$handler($step,$context);
            if(!is_array($result)||!array_key_exists('actual',$result)||!array_key_exists('expected',$result)){
                $this->check("{$label} handler contract",false,true);
                continue;
            }
            $suffix=trim((string)($result['label']??$type));
            $this->check("{$label} {$suffix}",$result['actual'],$result['expected']);
        }
    }

    private function check(string $name,$actual,$expected):void
    {
        if($actual===$expected){
            echo "PASS  {$name}\n";
            $this->passed++;
            return;
        }
        echo "FAIL  {$name}\n";
        echo '      expected: '.json_encode($expected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        echo '      actual:   '.json_encode($actual,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        $this->failed++;
    }
}
