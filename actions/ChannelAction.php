<?php
require_once __DIR__ . '/../services/ProjectConfig.php';

class ChannelAction
{
    public static function plan($chatId): array
    {
        $url = '';
        if (class_exists('MaxSearchApi')) {
            try { $url = (string)MaxSearchApi::buildChannelMiniappUrl($chatId); } catch (Throwable $e) {}
        }
        if ($url === '') $url = (string)ProjectConfig::get('messenger.channel_url', '');
        return [
            'action'=>'CHANNEL',
            'url'=>$url,
            'ready'=>$url !== '',
        ];
    }
}
