<?php
declare(strict_types=1);
require_once __DIR__.'/ModeledConversionAssignedValue.php';
require_once __DIR__.'/InternalOwnerDialogueScope.php';

/** Private read-time current registry over existing membership and profile stores. No writes. */
final class ModeledConversionValueRegistry
{
    public function __construct(private PDO $pdo){}

    public function members(array $page):array
    {
        $rows=[];$omitted=0;
        foreach($page['members']as$member){
            // Use exact bound MAX identity; no global linked-person or source guess.
            $q=$this->pdo->prepare("SELECT c.id,c.is_test,".InternalOwnerDialogueScope::conversationSql('c')." AS internal_owner FROM conversations c JOIN customer_channels cc ON cc.id=c.customer_channel_id AND cc.customer_id=c.customer_id AND cc.project_key=c.project_key AND cc.channel=c.channel WHERE c.project_key='anytour' AND c.channel='max' AND cc.external_user_id=? AND c.external_chat_id=? ORDER BY c.id DESC LIMIT 2");
            $q->execute([$member['external_user_id'],'-'.$member['external_user_id']]);$bindings=$q->fetchAll(PDO::FETCH_ASSOC);
            if(array_filter($bindings,static fn(array $r):bool=>(bool)$r['is_test']||(bool)$r['internal_owner'])){$omitted++;continue;}
            $value=ModeledConversionAssignedValue::member($member,$page['observed_at_unix']);
            $value['attribution']=$member['attribution'];$value['profile_binding']=count($bindings)>1?'ambiguous':'no_dialogue';
            if(count($bindings)===1){
                $p=$this->latestProfile((string)$bindings[0]['id']);
                if($p!==null&&($p['access_status']??null)==='allowed'){
                    $value=ModeledConversionAssignedValue::merge($value,ModeledConversionAssignedValue::profile($p));
                    $value['profile_binding']='exact_private_chat_current_profile';
                    $value['coverage']['dialogue_scope']='conversation_prefix_not_verified_current_trip';
                }
            }
            $rows[]=$value;
        }
        return ['rules_version'=>ModeledConversionAssignedValue::VERSION,'rows'=>$rows,'next_after'=>$page['next_after'],
            'has_more'=>$page['has_more'],'omitted_owner_or_test'=>$omitted,'coverage'=>$page['coverage'],
            'telegram_membership'=>'not_available_from_max_bridge','export_allowed'=>false,'conversion_uploads'=>0];
    }

    /** Existing admitted current dialogue snapshots; member identity is not guessed. */
    public function profiles(string $after,int $now):array
    {
        if(preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/D',$after)!==1)throw new InvalidArgumentException('value_registry_cursor');
        $length=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(p.profile_json)':'LENGTH(CAST(p.profile_json AS BLOB))';
        $q=$this->pdo->prepare('SELECT p.id,CASE WHEN '.$length.'<=131072 THEN p.profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions p'
            .' WHERE p.project_key=? AND p.id>? AND p.observed_at_unix<=?'
            .' AND NOT EXISTS (SELECT 1 FROM modeled_conversion_profile_versions n WHERE n.project_key=p.project_key AND n.platform=p.platform AND n.customer_key=p.customer_key AND n.conversation_id=p.conversation_id AND n.id>p.id AND n.observed_at_unix<=?) ORDER BY p.id LIMIT 6');
        $q->execute(['anytour',$after,$now,$now]);$page=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($page)>5;$rows=[];$omitted=0;
        foreach(array_slice($page,0,5)as$r){
            $after=(string)$r['id'];if(!is_string($r['profile_json'])){$omitted++;continue;}
            $p=json_decode($r['profile_json'],true,64,JSON_THROW_ON_ERROR);
            if(($p['access_status']??null)!=='allowed'){$omitted++;continue;}
            $v=ModeledConversionAssignedValue::profile($p);$v['attribution']=['status'=>'unknown','reason'=>'profile_does_not_prove_advertising_visit'];
            $v['coverage']['unit']='unresolved_conversation_prefix_not_unique_person';$rows[]=$v;
        }
        return ['rows'=>$rows,'next_after'=>$after,'has_more'=>$more,'omitted'=>$omitted,
            'rules_version'=>ModeledConversionAssignedValue::VERSION,'coverage'=>'bounded_current_admitted_profiles_not_complete_cohort',
            'do_not_sum_with_member_rows'=>'unresolved_identity_or_same_stage_may_overlap',
            'export_allowed'=>false,'conversion_uploads'=>0];
    }

    private function latestProfile(string $conversation):?array
    {
        // SQL bounds private source bytes before copying; no transcript or native message table read.
        $length=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'OCTET_LENGTH(profile_json)':'LENGTH(CAST(profile_json AS BLOB))';
        $q=$this->pdo->prepare('SELECT CASE WHEN '.$length.'<=131072 THEN profile_json ELSE NULL END AS profile_json FROM modeled_conversion_profile_versions WHERE project_key=? AND platform=? AND conversation_id=? ORDER BY id DESC LIMIT 1');
        $q->execute(['anytour','max',$conversation]);$json=$q->fetchColumn();
        if(!is_string($json))return null;
        $p=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        return ($p['subject']['project_key']??null)==='anytour'&&($p['subject']['platform']??null)==='max'
            &&(string)($p['subject']['conversation_id']??'')===$conversation?$p:null;
    }
}
