<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ProjectConfig.php';

final class ManagerConversationProductionHealth
{
    public static function collect(int $hours=168): array
    {
        $hours=max(1,min(720,$hours)); $pdo=ConversationDb::connection(); $project=ProjectConfig::projectId();
        $q=$pdo->prepare("SELECT id,channel,direction,sender_type,external_message_id,created_at,metadata_json FROM messages WHERE created_at>=UTC_TIMESTAMP()-INTERVAL {$hours} HOUR AND channel IN ('max','telegram','website') ORDER BY id DESC LIMIT 5000");
        $q->execute(); $counts=['max_media'=>0,'max_media_local'=>0,'linked'=>0,'linked_invalid'=>0,'edited'=>0,'edited_invalid'=>0];
        foreach($q->fetchAll() as $row){
            $meta=json_decode((string)($row['metadata_json']??''),true); if(!is_array($meta))$meta=[];
            if((string)$row['channel']==='max'&&(string)$row['direction']==='inbound'){
                foreach((array)($meta['attachments']??[]) as $a){
                    if(!is_array($a)||!in_array((string)($a['type']??''),['image','video','audio','file'],true))continue;
                    $counts['max_media']++;
                    $local=(array)($a['local_media']??[]);
                    if(preg_match('/^[a-f0-9]{32}$/D',(string)($local['id']??''))&&(int)($local['size']??0)>0)$counts['max_media_local']++;
                }
            }
            if(isset($meta['linked_message'])){
                $counts['linked']++; $l=(array)$meta['linked_message'];
                if(!in_array((string)($l['type']??''),['reply','forward'],true)||trim((string)($l['mid']??''))==='')$counts['linked_invalid']++;
            }
            if(isset($meta['edited_at'])){
                $counts['edited']++;
                if((string)$row['direction']!=='outbound'||(string)$row['sender_type']!=='manager'||(int)($meta['edited_by_manager_id']??0)<=0||strtotime((string)$meta['edited_at'])===false)$counts['edited_invalid']++;
            }
        }
        $checks=[
            'max_media_descriptors_ok'=>$counts['max_media']===0||$counts['max_media_local']===$counts['max_media'],
            'reply_metadata_ok'=>$counts['linked_invalid']===0,
            'edit_metadata_ok'=>$counts['edited_invalid']===0,
            'moscow_projection_contract'=>self::moscowProjectionContract(),
        ];
        return ['ok'=>!in_array(false,$checks,true),'window_hours'=>$hours,'counts'=>$counts,'checks'=>$checks];
    }

    private static function moscowProjectionContract(): bool
    {
        $path=dirname(__DIR__).'/manager/assets/workspace-v2-conversation.js';
        $src=is_file($path)?(string)file_get_contents($path):'';
        return str_contains($src,"timeZone:'Europe/Moscow'")&&str_contains($src,'Date.UTC(');
    }
}
