<?php
declare(strict_types=1);

/** Observe two completed future worker ticks. Never invokes the worker itself. */
final class HotelWorkCronVerifier
{
    private ?array $first=null;
    public function __construct(private int $after) {}
    public function observe(array $health,int $now): ?array
    {
        if (($health['available']??false)!==true||($health['stale']??true)!==false||($health['trigger']??null)!=='cron') return null;
        $started=$health['last_started_at']??null;$finished=$health['last_finished_at']??null;
        if (!is_string($started)||!is_string($finished)) return null;
        $at=strtotime($started);$end=strtotime($finished);
        if ($at===false||$end===false||$at<=$this->after||$at>$now||$end<$at||$end>$now) return null;
        if (($health['state']??null)==='failed') throw new RuntimeException('hotel_cron_worker_failed');
        if (!in_array($health['state']??null,['ok','awaiting_connection'],true)) return null;
        if ($this->first===null) {$this->first=['at'=>$at,'started_at'=>$started,'state'=>$health['state'],'stage'=>$this->stage($health)];return null;}
        if ($at<=$this->first['at']) return null;
        $interval=$at-$this->first['at'];
        if ($interval<45||$interval>90) throw new RuntimeException('hotel_cron_interval_out_of_bounds');
        return ['trigger'=>'cron','first_started_at'=>$this->first['started_at'],'second_started_at'=>$started,
            'observed_interval_seconds'=>$interval,'first_state'=>$this->first['state'],'second_state'=>$health['state'],'first_stage'=>$this->first['stage'],'second_stage'=>$this->stage($health)];
    }
    private function stage(array $health):?string
    {return in_array($health['stage']??null,['bootstrap','subscription','delivery','server_cycle','server_cycle_hold','complete'],true)?$health['stage']:null;}
}
