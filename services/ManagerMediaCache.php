<?php

class ManagerMediaCache
{
    private const TTL_SECONDS = 604800;

    public static function store(int $conversationId,int $managerId,string $source,string $name,string $mime): ?array
    {
        if($conversationId<=0||$managerId<=0||!is_file($source))return null;
        self::prune();
        $dir=self::dir();
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))return null;
        $id=bin2hex(random_bytes(16));
        $file=$dir.'/'.$id.'.bin';
        $meta=$dir.'/'.$id.'.json';
        if(!@copy($source,$file))return null;
        @chmod($file,0600);
        $payload=[
            'id'=>$id,
            'conversation_id'=>$conversationId,
            'manager_id'=>$managerId,
            'name'=>self::safeName($name),
            'mime'=>self::safeMime($mime),
            'created_at'=>time(),
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($json===false||@file_put_contents($meta,$json,LOCK_EX)===false){@unlink($file);@unlink($meta);return null;}
        @chmod($meta,0600);
        return $payload+['url'=>'media-file.php?id='.rawurlencode($id)];
    }

    public static function get(string $id): ?array
    {
        if(!preg_match('/^[a-f0-9]{32}$/',$id))return null;
        $dir=self::dir();$meta=$dir.'/'.$id.'.json';$file=$dir.'/'.$id.'.bin';
        if(!is_file($meta)||!is_file($file))return null;
        $data=json_decode((string)@file_get_contents($meta),true);
        if(!is_array($data)||empty($data['created_at'])||(int)$data['created_at']<time()-self::TTL_SECONDS){self::remove($id);return null;}
        return $data+['path'=>$file];
    }

    public static function remove(string $id): void
    {
        if(!preg_match('/^[a-f0-9]{32}$/',$id))return;
        $dir=self::dir();@unlink($dir.'/'.$id.'.bin');@unlink($dir.'/'.$id.'.json');
    }

    public static function prune(): void
    {
        $dir=self::dir();if(!is_dir($dir))return;$cutoff=time()-self::TTL_SECONDS;
        foreach((array)glob($dir.'/*.json') as $meta){if((int)@filemtime($meta)>=$cutoff)continue;$id=basename($meta,'.json');self::remove($id);}
    }

    private static function dir(): string { return dirname(__DIR__).'/runtime/manager-media'; }
    private static function safeName(string $name): string { $name=trim(basename($name));$name=preg_replace('/[\x00-\x1F\x7F]+/u','',$name)?:'attachment';return mb_substr($name,0,180); }
    private static function safeMime(string $mime): string { $mime=strtolower(trim($mime));return preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#',$mime)?$mime:'application/octet-stream'; }
}
