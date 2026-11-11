<?php
declare(strict_types=1);

/** Immutable private bytes owned by the canonical quick-reply manifest.
 * There is no public file URL, provider upload, sender or runtime schema writer.
 */
final class ManagerQuickReplyMaterials
{
    public const MAX_FILES = 10;
    public const MAX_BYTES = 50000000;
    private static ?string $testRoot = null;

    public static function uploadLimits(): array
    {
        $parse = static function(string $value): int {
            if (!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/iD', $value, $m)) return 0;
            return min(PHP_INT_MAX, (int)$m[1] * (1024 ** array_search(strtoupper($m[2] ?? ''), ['', 'K', 'M', 'G'], true)));
        };
        $upload=$parse((string)ini_get('upload_max_filesize'));
        $post=$parse((string)ini_get('post_max_size'));
        // Leave room for the bounded command and multipart headers.
        $total=$post>0?max(0,$post-65536):self::MAX_BYTES;
        return ['max_files'=>self::MAX_FILES,'max_file_bytes'=>min(self::MAX_BYTES,$upload>0?$upload:self::MAX_BYTES,$total),
            'max_total_bytes'=>min(self::MAX_BYTES,$total)];
    }

    /** Paths come only from PHP's validated upload vector, never JSON input. */
    public static function inspectUploads(array $uploads): array
    {
        if (!array_is_list($uploads) || count($uploads)>self::MAX_FILES) throw new InvalidArgumentException('invalid_materials');
        $out=[];$total=0;$limits=self::uploadLimits();
        foreach ($uploads as $file) {
            $path=is_array($file)?(string)($file['tmp_name']??''):'';
            $stat=$path!==''?@lstat($path):false;
            if (!$stat || ($stat['mode']&0170000)!==0100000 || $stat['size']<=0) throw new InvalidArgumentException('invalid_material_file');
            $total+=(int)$stat['size'];
            if ($stat['size']>$limits['max_file_bytes'] || $total>$limits['max_total_bytes']) throw new InvalidArgumentException('material_too_large');
            $name=preg_replace('/[\x00-\x1f\x7f"\\\\]+/u','_',basename(str_replace('\\','/',(string)($file['name']??'Вложение'))));
            $name=mb_substr(trim($name?:'Вложение'),0,180);
            if ($name==='') $name='Вложение';
            $mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D',$mime)) $mime='application/octet-stream';
            $hash=hash_file('sha256',$path);
            if (!is_string($hash)) throw new RuntimeException('material_storage_unavailable');
            $item=['name'=>$name,'size'=>(int)$stat['size'],'mime'=>$mime,'sha256'=>$hash,'path'=>$path];
            if (str_starts_with($mime,'image/')) {
                $image=@getimagesize($path);
                if (is_array($image)) {$item['width']=(int)$image[0];$item['height']=(int)$image[1];}
            }
            $out[]=$item;
        }
        return $out;
    }

    public static function wireUploads(array $uploads): array
    {
        return array_map(static function(array $item):array {unset($item['path']);return $item;},$uploads);
    }

    public static function manifest($value): array
    {
        if ($value===null || $value==='') return [];
        $items=is_string($value)?json_decode($value,true,32,JSON_THROW_ON_ERROR):$value;
        if (!is_array($items)||!array_is_list($items)||count($items)>self::MAX_FILES) throw new RuntimeException('invalid_saved_materials');
        $ids=[];
        foreach ($items as $item) {
            if (!is_array($item)||!preg_match('/^[a-f0-9]{32}$/D',(string)($item['id']??''))
                ||!is_string($item['name']??null)||$item['name']===''||mb_strlen($item['name'])>180
                ||!is_int($item['size']??null)||$item['size']<=0||$item['size']>self::MAX_BYTES
                ||!preg_match('/^[a-f0-9]{64}$/D',(string)($item['sha256']??''))
                ||!preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D',(string)($item['mime']??''))
                ||isset($ids[$item['id']])) throw new RuntimeException('invalid_saved_materials');
            $ids[$item['id']]=true;
        }
        return $items;
    }

    /** Resolve the entire order before writing any private bytes. */
    public static function plan($requested, array $existing, array $uploads): array
    {
        if ($requested===null) {
            if ($uploads) throw new InvalidArgumentException('invalid_materials');
            return $existing;
        }
        if (!is_array($requested)||!array_is_list($requested)||count($requested)>self::MAX_FILES) throw new InvalidArgumentException('invalid_materials');
        $known=array_column($existing,null,'id');$used=[];$seen=[];$result=[];
        foreach ($requested as $entry) {
            if (!is_array($entry)) throw new InvalidArgumentException('invalid_materials');
            if (array_keys($entry)===['id'] && is_string($entry['id']) && isset($known[$entry['id']]) && !isset($seen[$entry['id']])) {
                $seen[$entry['id']]=true;$result[]=$known[$entry['id']];
            } elseif (array_keys($entry)===['upload_index'] && is_int($entry['upload_index']) && isset($uploads[$entry['upload_index']]) && !isset($used[$entry['upload_index']])) {
                $used[$entry['upload_index']]=true;$result[]=['upload_index'=>$entry['upload_index']];
            } else throw new InvalidArgumentException('invalid_materials');
        }
        if (count($used)!==count($uploads)) throw new InvalidArgumentException('invalid_materials');
        return $result;
    }

    public static function persist(int $projectId,array $plan,array $uploads,array &$created): array
    {
        if (!$uploads) return self::manifest($plan);
        $dir=self::directory($projectId);$result=[];
        foreach ($plan as $entry) {
            if (!isset($entry['upload_index'])) {$result[]=$entry;continue;}
            $item=$uploads[$entry['upload_index']];$id=bin2hex(random_bytes(16));$path=$dir.'/'.$id.'.bin';
            $handle=@fopen($path,'xb');
            if (!$handle) throw new RuntimeException('material_storage_unavailable');
            $created[]=['project_id'=>$projectId,'id'=>$id];
            try {
                if (!@chmod($path,0600)) throw new RuntimeException('material_storage_unavailable');
                $input=@fopen($item['path'],'rb');
                if (!$input) throw new RuntimeException('material_storage_unavailable');
                try {$count=stream_copy_to_stream($input,$handle,$item['size']+1);} finally {fclose($input);}
                if ($count!==$item['size']||!fflush($handle)) throw new RuntimeException('material_storage_unavailable');
                if (function_exists('fsync')&&!fsync($handle)) throw new RuntimeException('material_storage_unavailable');
            } finally {fclose($handle);}
            if (hash_file('sha256',$path)!==$item['sha256']) throw new RuntimeException('material_storage_unavailable');
            unset($item['path']);$result[]=['id'=>$id]+$item;
        }
        return self::manifest($result);
    }

    /** Only called for files created by a transaction with acknowledged rollback. */
    public static function discard(array $created): void
    {
        foreach ($created as $entry) {
            if ((int)($entry['project_id']??0)<=0||!preg_match('/^[a-f0-9]{32}$/D',(string)($entry['id']??''))) continue;
            $path=self::directory((int)$entry['project_id']).'/'.$entry['id'].'.bin';
            $stat=@lstat($path);if ($stat&&($stat['mode']&0170000)===0100000) @unlink($path);
        }
    }

    public static function open(int $projectId,array $item): array
    {
        self::manifest([$item]);$path=self::directory($projectId).'/'.$item['id'].'.bin';
        $stat=@lstat($path);
        if (!$stat||($stat['mode']&0170000)!==0100000||$stat['size']!==$item['size']) throw new RuntimeException('material_storage_unavailable');
        $stream=@fopen($path,'rb');
        if (!$stream) throw new RuntimeException('material_storage_unavailable');
        try {
            $opened=fstat($stream);
            if (!$opened||$opened['ino']!==$stat['ino']||$opened['dev']!==$stat['dev']||$opened['size']!==$item['size']) throw new RuntimeException('material_storage_unavailable');
            $hash=hash_init('sha256');hash_update_stream($hash,$stream);
            if (hash_final($hash)!==$item['sha256']||!rewind($stream)) throw new RuntimeException('material_storage_unavailable');
            return ['stream'=>$stream]+$item;
        } catch (Throwable $e) {fclose($stream);throw $e;}
    }

    private static function directory(int $projectId): string
    {
        if ($projectId<=0) throw new RuntimeException('material_storage_unavailable');
        $root=realpath(dirname(__DIR__));
        if ($root===false) throw new RuntimeException('material_storage_unavailable');
        $base=self::$testRoot??dirname($root).'/max-search-bot-private-materials';
        if ($base===''||$base[0]!=='/'||str_contains($base,"\0")||preg_match('~/(?:\.|\.\.)(?:/|$)~',$base)
            ||$base===$root||str_starts_with($base,$root.'/')) throw new RuntimeException('material_storage_unavailable');
        foreach ([$base,$base.'/'.$projectId] as $dir) {
            $stat=@lstat($dir);
            if (!$stat) {
                if (!@mkdir($dir,0700)&&!is_dir($dir)) throw new RuntimeException('material_storage_unavailable');
                $stat=@lstat($dir);
            }
            if (!$stat||($stat['mode']&0170000)!==0040000||!@chmod($dir,0700)||realpath($dir)!==$dir) throw new RuntimeException('material_storage_unavailable');
        }
        return $base.'/'.$projectId;
    }
}
