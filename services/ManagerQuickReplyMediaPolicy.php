<?php
declare(strict_types=1);

require_once __DIR__.'/ManagerQuickReplyMaterials.php';

/** Read-only presentation of the current sender's transport and PHP limits.
 * No provider request and no alternate send path. Sources checked 2026-11-11:
 * https://dev.max.ru/docs-api/use-cases/sending-messages/attachment-types
 * https://core.telegram.org/bots/api#sending-files
 * https://core.telegram.org/bots/api#sendphoto
 */
final class ManagerQuickReplyMediaPolicy
{
    public static function forChannel(string $channel): array
    {
        $upload=ManagerQuickReplyMaterials::uploadLimits();
        $supported=in_array($channel,['max','telegram'],true);
        $limits=$channel==='telegram'
            ?['image'=>10000000,'video'=>50000000,'audio'=>50000000,'file'=>50000000]
            :['image'=>50000000,'video'=>250000000,'audio'=>256000000,'file'=>4000000000];
        foreach ($limits as &$limit) $limit=min($limit,$upload['max_file_bytes']);
        unset($limit);
        return ['channel'=>$channel,'supported'=>$supported,'max_files'=>ManagerQuickReplyMaterials::MAX_FILES,
            'max_file_bytes_by_type'=>$limits,'max_total_bytes'=>$upload['max_total_bytes'],
            'max_image_edge'=>$channel==='max'?7680:null,
            'max_photo_dimension_sum'=>$channel==='telegram'?10000:null,
            'max_photo_ratio'=>$channel==='telegram'?20:null,
            'caption_warning_limit'=>$channel==='telegram'?1024:null,
            'image_formats'=>$channel==='max'?['image/jpeg','image/png','image/gif','image/tiff','image/bmp','image/heic','image/heif']:['image/jpeg','image/png','image/webp','image/gif'],
            'video_formats'=>['video/mp4','video/quicktime','video/x-matroska','video/webm'],
            'separate_messages'=>true,'caption_first_only'=>true,'checked_on'=>'2026-11-11'];
    }
}
