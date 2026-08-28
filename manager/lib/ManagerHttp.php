<?php

declare(strict_types=1);

require_once dirname(__DIR__,2).'/services/ManagerRequestContext.php';

final class ManagerHttp
{
    public static function startJson(): void
    {
        ManagerRequestContext::startSession();
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
        if(!ManagerRequestContext::isAdmin($manager)){
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
