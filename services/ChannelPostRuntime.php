<?php
declare(strict_types=1);

require_once __DIR__.'/ProjectConfig.php';
require_once __DIR__.'/ConversationDb.php';
require_once __DIR__.'/ChannelPostAutomationService.php';

/** Request boundary for the opted-in publication feature; no customer dispatch. */
final class ChannelPostRuntime
{
    private static array $observed=[];
    private static array $results=[];
    public static function identity(string $platform): ?array
    {
        if (!in_array($platform,['max','telegram'],true)) return null;
        $config=ProjectConfig::get('channel_posts.'.$platform);
        if (!is_array($config)) return null;
        $bot=trim((string)($config['bot_key']??''));
        $url=trim((string)($config['bot_url']??''));
        $base=$platform==='max'?'https://max.ru/':'https://t.me/';
        if (!preg_match('/\A[A-Za-z0-9_]{3,96}\z/',$bot)||$url!==$base.$bot) return null;
        return ['project_key'=>ProjectConfig::projectId(),'platform'=>$platform,'bot_key'=>$bot,'bot_url'=>$url];
    }

    public static function botIdentities(): array
    {
        $out=[];
        foreach (['max','telegram'] as $platform) {
            $identity=self::identity($platform);
            if ($identity) $out[$platform]=$identity;
        }
        return $out;
    }

    public static function receive(string $platform,array $update): array
    {
        $identity=self::identity($platform);
        if (!$identity) return ['recognized'=>ChannelPostIncomingAdapter::isChannelUpdate($platform,$update),'queued'=>false,'state'=>'not_configured'];
        $result=(new ChannelPostAutomationService())->receive($platform,$update,$identity['project_key'],$identity['bot_key']);
        self::$results[$platform]=$result;
        if ((int)($result['publication_id']??0)>0&&(int)($result['version_id']??0)>0) self::$observed[$platform]=$result;
        return $result;
    }

    public static function storageFailed(string $platform): bool
    {
        return (self::$results[$platform]['reason']??'')==='channel_storage_unavailable';
    }

    /** Called after flushing the channel webhook acknowledgement, at most one job. */
    public static function finish(string $platform): array
    {
        $identity=self::identity($platform);
        if (!$identity||!ConversationDb::isConfigured()) return [];
        try {
            $registry=new ChannelPostRegistryService();
            // Capture the exact observed image before provider button calls. A
            // later SMM edit may already have created a new current version.
            try {if (is_file(__DIR__.'/ChannelPostMediaService.php')) {
                require_once __DIR__.'/ChannelPostMediaService.php';
                $media=new ChannelPostMediaService($registry);
                $observed=self::$observed[$platform]??null;
                unset(self::$observed[$platform]);
                $versions=$observed?[$observed]:$registry->pendingPublications($identity['project_key'],$platform,$identity['bot_key'],1);
                foreach ($versions as $version) {
                    $publication=$registry->versionForPublication((int)$version['publication_id'],(int)$version['version_id']);
                    if ($publication&&$publication['project_key']===$identity['project_key']&&$publication['platform']===$platform&&$publication['bot_key']===$identity['bot_key']) {
                        try {$media->archiveVersion($publication);} catch (Throwable $ignored) {}
                    }
                }
            }} catch (Throwable $ignored) {}
            return (new ChannelPostAutomationService($registry))->processPending($identity['project_key'],$platform,$identity['bot_key'],1);
        } catch (Throwable $ignored) {
            return [['state'=>'requires_attention','reason'=>'storage_unavailable']];
        }
    }
}
