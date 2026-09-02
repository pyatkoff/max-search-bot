<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ManagerRequestContext.php';

final class ManagerHttp
{
    public static function start(): void
    {
        self::redirectLegacyPath();
        ManagerRequestContext::startSession();
    }

    private static function redirectLegacyPath(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $uri=(string)($_SERVER['REQUEST_URI']??'');
        $path=(string)(parse_url($uri,PHP_URL_PATH)??'');
        $legacyPrefix='/max-search/manager';
        if ($path!==$legacyPrefix && !str_starts_with($path,$legacyPrefix.'/')) {
            return;
        }

        $targetPath=substr($path,strlen('/max-search'));
        if ($targetPath==='') {
            $targetPath='/manager/';
        }
        $query=(string)(parse_url($uri,PHP_URL_QUERY)??'');
        $target='https://app.anytoour.ru'.$targetPath.($query!==''?'?'.$query:'');
        header('Location: '.$target,true,308);
        header('Cache-Control: no-store');
        exit;
    }

    public static function startJson(): void
    {
        self::start();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }

    public static function body(): array
    {
        return ManagerRequestContext::jsonBody();
    }

    public static function manager(): ?array
    {
        return ManagerRequestContext::manager();
    }

    public static function managerId(): int
    {
        return ManagerRequestContext::managerId();
    }

    public static function csrf(bool $rotate=false): string
    {
        return ManagerRequestContext::csrf($rotate);
    }

    public static function isAdmin(array $manager): bool
    {
        return ManagerRequestContext::isAdmin($manager);
    }

    public static function requireManager(): array
    {
        $manager=self::manager();
        if(!$manager||self::managerId()<=0){
            self::respond(['ok'=>false,'error'=>'unauthorized'],401);
        }
        return $manager;
    }

    public static function requireCsrf(array $data): void
    {
        $token=isset($data['csrf'])?(string)$data['csrf']:null;
        if(!ManagerRequestContext::validCsrf($token)){
            self::respond(['ok'=>false,'error'=>'csrf'],403);
        }
    }

    public static function requireAdmin(array $manager): void
    {
        if(!self::isAdmin($manager)){
            self::respond(['ok'=>false,'error'=>'forbidden'],403);
        }
    }

    public static function canEditConversation(array $conversation,array $manager): bool
    {
        return ManagerRequestContext::canEditAssignedConversation($conversation,$manager);
    }

    public static function requireConversationEdit(array $conversation,array $manager): void
    {
        if(!self::canEditConversation($conversation,$manager)){
            self::respond(['ok'=>false,'error'=>'forbidden'],403);
        }
    }

    public static function respond(array $data,int $status=200): void
    {
        http_response_code($status);
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        exit;
    }
}
