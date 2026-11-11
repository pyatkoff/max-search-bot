<?php
declare(strict_types=1);
require_once __DIR__.'/ProjectMaxConnection.php';
require_once __DIR__.'/ProjectFunnelLaunch.php';

/** Public, versioned company data. Secrets remain in the existing server connection. */
final class ProjectFunnelSettings
{
    public static function load(ProjectMaxConnection $connection): ?array
    {
        $data=$connection->settings()->get('sales_funnel');
        if($data===null){
            $path=__DIR__.'/../resources/project-funnel/'.$connection->projectKey().'.json';
            if(is_link($path)||!is_file($path)||filesize($path)>262144)return null;
            try{$data=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
        }
        try{return self::validate($data,$connection->projectKey(),$connection->botKey());}catch(InvalidArgumentException $e){return null;}
    }
    public static function validate($data,string $project,string $bot): array
    {
        $fail=static function(): never {throw new InvalidArgumentException('invalid_funnel_settings');};
        if(!is_array($data)||($data['schema_version']??null)!==1||($data['project_key']??null)!==$project||($data['bot_key']??null)!==$bot||($data['enabled']??null)!==true)$fail();
        if(($data['handoff_mode']??'manager_first')!=='manager_first')$fail();
        if(!preg_match('/\A[a-z][a-z0-9_]{0,39}\z/D',$project)||!preg_match('/\A[A-Za-z0-9_]{3,100}_bot\z/D',$bot))$fail();
        foreach(['brand','source_key','advertising_source_key','channel_id','channel_url','default_post']as$key)if(!is_string($data[$key]??null))$fail();
        if($data['brand']===''||strlen($data['brand'])>191||preg_match('/[\x00-\x1f<>]/',$data['brand'])||!preg_match('/\Amax:[A-Za-z0-9_-]{1,150}\z/D',$data['source_key'])
            ||!preg_match('/\A[a-z][a-z0-9_.-]{0,79}\z/D',$data['advertising_source_key'])||!preg_match('/\A-[1-9][0-9]{0,19}\z/D',$data['channel_id'])
            ||!preg_match('~\Ahttps://max\.ru/[A-Za-z0-9_]{3,100}\z~D',$data['channel_url']))$fail();
        if(!is_array($data['posts']??null)||!$data['posts']||count($data['posts'])>1000||!isset($data['posts'][$data['default_post']]))$fail();
        $offers=[];
        foreach($data['posts']as$key=>$post){
            ProjectFunnelLaunch::ref((string)$key);
            if(!is_array($post)||!in_array($post['owner']??null,['channel_post_registry','appleservice39_pilot'],true))$fail();
            if($post['owner']==='channel_post_registry'&&(!is_int($post['publication_id']??null)||$post['publication_id']<=0))$fail();
            if($post['owner']==='appleservice39_pilot'&&($project!=='appleservice39'||$bot!=='id390521129062_bot'||!preg_match('/\A[a-z][a-z0-9_-]{0,55}\z/D',(string)$key)))$fail();
            $offer=ProjectFunnelLaunch::ref($post['offer_id']??null);if(isset($offers[$offer]))$fail();$offers[$offer]=true;
            foreach(['title','description','image_url']as$field)if(!is_string($post[$field]??null)||$post[$field]===''||strlen($post[$field])>4000||preg_match('/[\x00-\x1f<>]/',$post[$field]))$fail();
            if(strlen($post['title'])>1000)$fail();
            if(isset($post['labels'])){if(!is_array($post['labels'])||!array_is_list($post['labels'])||count($post['labels'])!==5)$fail();foreach($post['labels']as$label)if(!is_string($label)||strlen($label)>320||preg_match('/[\x00-\x1f<>]/',$label))$fail();}
            $url=parse_url($post['image_url']);if(!$url||($url['scheme']??'')!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['fragment'])||isset($url['query']))$fail();
        }
        $metric=$data['metrika']??null;
        if(!is_array($metric)||!is_string($metric['counter_id']??null)||($metric['counter_id']!==''&&!preg_match('/\A[1-9][0-9]{0,19}\z/D',$metric['counter_id']))||!in_array($metric['mode']??null,['off','collect','send'],true))$fail();
        if($metric['mode']!=='off'&&$metric['counter_id']==='')$fail();
        if(!is_array($metric['goals']??null))$fail();
        foreach($metric['goals']as$event=>$goal)if(!in_array($event,['customer_activity','manager_request','customer_reply','working','offer_sent','booking','won','predictive_value'],true)||!is_string($goal)||!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D',$goal))$fail();
        if(count(array_unique($metric['goals']))!==count($metric['goals']))$fail();
        if(!in_array($metric['credential_source']??'project',['project','platform'],true))$fail();
        foreach(['goal_ids','goal_match']as$field)if(isset($metric[$field])&&(!is_array($metric[$field])||array_diff(array_keys($metric[$field]),array_keys($metric['goals']))))$fail();
        foreach(($metric['goal_ids']??[])as$id)if(!is_string($id)||!preg_match('/\A[1-9][0-9]{0,19}\z/D',$id))$fail();
        foreach(($metric['goal_match']??[])as$event=>$match)if(!in_array($match,['exact','contain'],true)||($match==='contain'&&!isset($metric['goal_ids'][$event])))$fail();
        if(isset($data['prediction'])){
            $model=$data['prediction'];
            if(!is_array($model)||!is_int($model['version']??null)||$model['version']<1||$model['version']>1000000
                ||!is_string($model['model_id']??null)||!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D',$model['model_id'])
                ||!is_string($model['evidence_sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/D',$model['evidence_sha256'])
                ||!is_int($model['validated_at_unix']??null)||$model['validated_at_unix']<946684800||!is_int($model['valid_until_unix']??null)
                ||$model['valid_until_unix']<=$model['validated_at_unix']||$model['valid_until_unix']-$model['validated_at_unix']>90*86400
                ||!is_string($model['base_value_rub']??null)||!preg_match('/\A[1-9][0-9]{0,7}\.[0-9]{2}\z/D',$model['base_value_rub'])
                ||!is_array($model['stage_probability']??null)||!$model['stage_probability'])$fail();
            foreach($model['stage_probability']as$stage=>$probability)if(!in_array($stage,['customer_activity','manager_request','customer_reply','working','offer_sent','booking'],true)
                ||(!is_int($probability)&&!is_float($probability))||!is_finite((float)$probability)||$probability<=0||$probability>=1)$fail();
        }
        return $data;
    }
    public static function publicClient(array $settings): array
    {
        return ['brand'=>$settings['brand'],'project'=>$settings['project_key'],'channel_url'=>$settings['channel_url'],'counter_id'=>$settings['metrika']['mode']==='off'?'':$settings['metrika']['counter_id']];
    }
}
