<?php
declare(strict_types=1);
require_once __DIR__.'/ConversationRecordClock.php';

/** Public, display-only projection of one already-authorized saved message. */
final class ManagerMessageMaterials
{
    public const KINDS=['all','files','links','offers'];

    public static function project(array $row,string $kind='all'): ?array
    {
        $meta=json_decode((string)($row['metadata_json']??''),true,32);
        if(!is_array($meta))$meta=[];
        if(in_array($meta['type']??null,['callback','start','bot_started'],true))return null;
        $files=[];$fileCount=0;
        foreach(is_array($meta['attachments']??null)?$meta['attachments']:[] as $attachment){
            if(!is_array($attachment)||!in_array($attachment['type']??null,['image','video','audio','file'],true))continue;
            $fileCount++;
            if(count($files)<8){
                $name=is_string($attachment['name']??null)?$attachment['name']:'';
                $name=trim(preg_replace('/[\x00-\x1f\x7f]/u','',$name)??'');
                $files[]=['type'=>$attachment['type'],'name'=>mb_substr($name,0,160,'UTF-8')];
            }
        }
        $text=(string)($row['text']??'');
        if(($row['sender_type']??'')==='manager')$text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8');
        preg_match_all('~https?://[^\s<>"\x00-\x20]+~iu',$text,$found);
        $links=[];
        foreach($found[0]??[] as $candidate){
            $url=rtrim($candidate,".,;!?'");
            foreach(['('=>')','['=>']','{'=>'}'] as $open=>$close){
                while(str_ends_with($url,$close)&&substr_count($url,$close)>substr_count($url,$open))$url=substr($url,0,-1);
            }
            $parts=parse_url($url);
            if(strlen($url)>2048||!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['http','https'],true)
                ||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))continue;
            $links[$url]=true;
        }
        // Only a recorded manager delivery identifies an offer. Text alone
        // never invents a sent offer, delivery result or a current publication.
        $offer=($row['sender_type']??'')==='manager'&&($row['direction']??'')==='outbound'
            &&is_string($meta['tour_selection']??null)&&preg_match('/^[a-f0-9]{32}$/D',$meta['tour_selection'])
            &&is_int($meta['tour_offer_id']??null)&&$meta['tour_offer_id']>0;
        $kinds=[];if($fileCount)$kinds[]='files';if($links)$kinds[]='links';if($offer)$kinds[]='offers';
        if(!$kinds||($kind!=='all'&&!in_array($kind,$kinds,true)))return null;
        $recorded=ConversationRecordClock::read($row['metadata_json']??null);
        return ['message_id'=>(int)$row['id'],'sender_type'=>(string)($row['sender_type']??''),
            'created_at'=>$recorded!==null?gmdate('Y-m-d\TH:i:s\Z',$recorded):(string)($row['created_at']??''),
            'snippet'=>mb_substr(trim($text),0,240,'UTF-8'),'kinds'=>$kinds,
            'files'=>$files,'file_count'=>$fileCount,'links'=>array_slice(array_keys($links),0,8),'link_count'=>count($links)];
    }
}
