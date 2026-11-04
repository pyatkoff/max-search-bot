<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,3).'/services/ModeledConversionAcquisitionStatsView.php';

/** Synthetic aggregates only; uses the real renderer and never opens a database. */
function modeledAcquisitionViewFixture(string $scenario='ready'):array
{
    $bins=['under_1h'=>1,'from_1h_to_1d'=>1,'from_1d_to_7d'=>1,'from_7d_to_30d'=>1,'over_30d'=>1];
    $excluded=['join_missing'=>3,'join_after_inquiry'=>1,'join_ambiguous'=>2,'identity_ambiguous'=>1,'membership_unavailable'=>0];
    $max=['inquiries'=>12,'valid_pairs'=>5,'coverage_pct'=>41.6667,'median_seconds'=>90000,'p90_seconds'=>3000000,
        'bins'=>$bins,'excluded'=>$excluded,'membership_status'=>'saved_max_events_only'];
    $telegram=['inquiries'=>9,'valid_pairs'=>0,'coverage_pct'=>null,'median_seconds'=>null,'p90_seconds'=>null,
        'bins'=>array_fill_keys(array_keys($bins),0),'excluded'=>array_replace(array_fill_keys(array_keys($excluded),0),['membership_unavailable'=>9]),
        'membership_status'=>'unavailable_pending_bitrix'];
    $input=['date'=>'2026-11-04','period'=>30,'platform'=>'all'];
    $page=['schema_version'=>1,'available'=>true,'reason'=>null,'as_of'=>'2026-11-04T10:20:30Z',
        'meaning'=>'first_observed_substantive_inquiry_not_first_ever','rule_version'=>'saved_substantive_interest_v1',
        'filters'=>$input,'window'=>['from'=>'2026-10-05T22:00:00Z','until'=>'2026-11-04T22:00:00Z','timezone'=>'Europe/Kaliningrad'],
        'platforms'=>['max'=>$max,'telegram'=>$telegram],
        'sources'=>['profile_fence'=>'99','profiles_examined'=>43,'projected_records'=>32,
            'first_profile_at'=>'2026-10-10T08:00:00Z','last_profile_at'=>'2026-11-04T10:20:00Z',
            'missing_clock'=>0,'coverage'=>'prospective_saved_profiles_not_full_archive'],
        'limits'=>['max_records'=>50000,'max_bytes'=>8388608,'max_seconds'=>5],'query_count'=>3];
    if($scenario==='error'){$page['available']=false;$page['reason']='time_limit';$page['platforms']=null;}
    if($scenario==='empty')foreach(['max','telegram']as$key){
        $page['platforms'][$key]['inquiries']=0;$page['platforms'][$key]['valid_pairs']=0;
        $page['platforms'][$key]['coverage_pct']=null;$page['platforms'][$key]['median_seconds']=null;
        $page['platforms'][$key]['p90_seconds']=null;$page['platforms'][$key]['bins']=array_fill_keys(array_keys($bins),0);
        $page['platforms'][$key]['excluded']=array_fill_keys(array_keys($excluded),0);
    }
    if($scenario==='unknown'){
        $page['platforms']['max']['valid_pairs']=0;$page['platforms']['max']['coverage_pct']=0.0;
        $page['platforms']['max']['median_seconds']=null;$page['platforms']['max']['p90_seconds']=null;
        $page['platforms']['max']['bins']=array_fill_keys(array_keys($bins),0);
        $page['platforms']['max']['excluded']=array_replace(array_fill_keys(array_keys($excluded),0),['join_missing'=>12]);
    }
    return [$page,$input];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    [$page,$input]=modeledAcquisitionViewFixture($argv[1]??'ready');
    echo ModeledConversionAcquisitionStatsView::render($page,$input);
}
