<?php
declare(strict_types=1);
require_once __DIR__.'/ManagerRequestProfile.php';

/** Public differences of immutable manager-curated versions, never inferred agreement. */
final class ManagerRequestHistory
{
    public static function decode(array $row): array
    {
        $raw=$row['profile_json']??null;
        if(!is_string($raw)||strlen($raw)>ManagerRequestProfile::MAX_BYTES
            ||!is_string($row['profile_sha256']??null)||!hash_equals(hash('sha256',$raw),$row['profile_sha256'])
            ||!preg_match('/\A[a-f0-9]{32}\z/D',(string)($row['trip_id']??''))
            ||(int)($row['version']??0)<=0||(int)($row['boundary_message_id']??0)<=0||(int)($row['manager_id']??0)<=0)
            throw new RuntimeException('invalid_profile_history');
        $profile=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($profile)||!ManagerRequestProfile::validateStored($profile))throw new RuntimeException('invalid_profile_history');
        foreach($profile['fields'] as $field)if($field['citation']!==null&&$field['citation']['message_id']<(int)$row['boundary_message_id'])throw new RuntimeException('invalid_profile_history');
        return $profile;
    }

    /** This table's sole writer explicitly stores gmdate(), unlike mixed-origin message times. */
    public static function recordedAt($value): ?string
    {
        if(!is_string($value)||preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D',$value)!==1)return null;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('UTC'));
        return $date&&$date->format('Y-m-d H:i:s')===$value?$date->format('Y-m-d\TH:i:s\Z'):null;
    }

    public static function projectField(?array $field): ?array
    {
        if($field===null)return null;
        $citation=$field['citation'];
        return ['value'=>$field['value'],'kind'=>$field['kind'],'importance'=>$field['importance'],'note'=>$field['note'],
            'citation'=>$citation===null?null:array_intersect_key($citation,array_flip(['message_id','sender_type','quote']))];
    }

    public static function event(array $row,?array $previous): array
    {
        $profile=self::decode($row);$version=(int)$row['version'];$before=null;
        $baseline=$version===1?'initial':'previous_unavailable';
        if($previous&&(int)$previous['version']===$version-1){
            if($previous['trip_id']!==$row['trip_id'])$baseline='new_trip';
            elseif((int)$previous['boundary_message_id']===(int)$row['boundary_message_id']){$before=self::decode($previous);$baseline='same_trip';}
        }
        $changes=[];
        foreach(ManagerRequestProfile::FIELDS as $key=>$label){
            $old=$before['fields'][$key]??null;$new=$profile['fields'][$key]??null;
            if($old===$new)continue;
            $dimensions=[];foreach(['value','kind','importance','note','citation'] as $dimension)if(($old[$dimension]??null)!==($new[$dimension]??null))$dimensions[]=$dimension;
            $changes[]=['field'=>$key,'label'=>$label,'before'=>self::projectField($old),'after'=>self::projectField($new),'dimensions'=>$dimensions];
        }
        $name=$row['author_name']??null;
        if(!ManagerRequestProfile::text($name,160)||trim($name)==='')$name=null;
        return ['version'=>$version,'trip_id'=>$row['trip_id'],'label'=>$profile['label'],'baseline'=>$baseline,
            'previous_version'=>$baseline==='same_trip'?$version-1:null,
            'author'=>['id'=>(int)$row['manager_id'],'name'=>$name], 'recorded_at'=>self::recordedAt($row['created_at']??null),
            'label_before'=>$before['label']??null,'label_changed'=>$before===null||$before['label']!==$profile['label'],
            'offer_changed'=>($before['hotel_focus']??null)!==($profile['hotel_focus']??null),'changes'=>$changes];
    }
}
