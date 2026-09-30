"""Extend only the ephemeral read-only report, never production source."""
from pathlib import Path
p = Path('audit/bot-db-funnel-readonly.php')
s = p.read_text()
needle = "    $paired=0;$delays=[];$activeBeforeIntro=0;"
assert s.count(needle) == 1
addition = r'''
    $cohortGroups=[];$cohortKeys=[];
    foreach($offers as $chat=>$entries){
        usort($entries,static fn($a,$b)=>$a['ts']<=>$b['ts']);
        $first=$entries[0];$cid=$first['group'];
        if(!in_array($cid,['714260445','714320748'],true))continue;
        $day=(new DateTimeImmutable('@'.$first['ts']))->setTimezone($tz)->format('Y-m-d');
        $flags=array_fill_keys($events,false);$flags60=$flags;
        foreach($history[$chat]??[] as $ev){
            if($ev['ts']<$first['ts'])continue;
            $flags[$ev['event']]=true;
            if($ev['ts']<$first['ts']+3600)$flags60[$ev['event']]=true;
        }
        foreach([$cid,'all'] as $g){
            foreach(['all',$day] as $d){
                $key=$g.'|'.$d;
                if(!isset($cohortGroups[$key]))$cohortGroups[$key]=['offered_users'=>0,'mature_60m'=>0,'started_search'=>0,'search_ready'=>0,'show_tours'=>0,'manager_request'=>0,'started_search_60m'=>0,'search_ready_60m'=>0,'show_tours_60m'=>0];
                $cohortGroups[$key]['offered_users']++;
                $started=$flags['ai_text']||$flags['ai_start']||$flags['start_search']||$flags['search_ready']||$flags['show_tours'];
                if($started)$cohortGroups[$key]['started_search']++;
                foreach(['search_ready','show_tours','manager_request'] as $ev)if($flags[$ev])$cohortGroups[$key][$ev]++;
                if($first['ts']+3600<=$now){
                    $cohortGroups[$key]['mature_60m']++;
                    if($flags60['ai_text']||$flags60['ai_start']||$flags60['start_search']||$flags60['search_ready']||$flags60['show_tours'])$cohortGroups[$key]['started_search_60m']++;
                    foreach(['search_ready','show_tours'] as $ev)if($flags60[$ev])$cohortGroups[$key][$ev.'_60m']++;
                }
            }
        }
        $cohortKeys[hash('sha256','max-funnel-user-v1|'.$chat)]=['campaign'=>$cid,'first_offer_utc'=>gmdate('c',$first['ts']),'search_ready'=>$flags['search_ready'],'show_tours'=>$flags['show_tours']];
    }
    ksort($cohortGroups);$out['pilot_cohort_outcomes']=$cohortGroups;
    $out['pilot_cohort_method']='First post-deploy offer per chat, first campaign wins; outcomes from the same chat after that offer. Search engagement includes AI text and wizard events; no claim that a text is a lead. 60m columns exclude immature exposures. Followup timestamp pairing is not used for conversion counts.';
'''
s = s.replace(needle, addition + '\n' + needle)
p.write_text(s)
