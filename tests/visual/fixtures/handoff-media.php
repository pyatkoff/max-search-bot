<?php
/** Synthetic CLI-only fixture; no config, database connection or customer I/O. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../../services/ManagerHandoffContextService.php';
require_once __DIR__ . '/../../../services/ManagerMessageMediaService.php';

$rows = [
    ['id'=>41,'direction'=>'inbound','sender_type'=>'customer','text'=>'Посмотрите это фото, пожалуйста.','created_at'=>'2026-09-21 10:00:00',
        'attachments'=>ManagerMessageMediaService::publicAttachments(41, [
            ['type'=>'image','provider'=>'telegram','name'=>'Фото туриста','file_id'=>'SYNTHETIC_SOURCE_REFERENCE'],
        ])],
    ['id'=>42,'direction'=>'inbound','sender_type'=>'customer','text'=>'Второй вариант.','created_at'=>'2026-09-21 10:00:10',
        'attachments'=>ManagerMessageMediaService::publicAttachments(42, [
            ['type'=>'image','url'=>'https://media.example.test/handoff-fixture.png','name'=>'Второй вариант'],
        ])],
];
$context = ['city'=>'Москва','country'=>'Египет','adults'=>2,'children'=>0,'nights'=>'7','date'=>'10.10.2026'];

function fixtureDetail(array $context, array $rows): array
{
    $original = $rows;
    // Production API integration is guarded by run_manager_request_regression.php.
    // Here the real summary builder and attachment projection feed the real browser renderer.
    if (!ManagerHandoffContextService::hasManagerReply($rows)) {
        $summary = ManagerHandoffContextService::build($context, $rows);
        if ($summary !== '') {
            $rows[] = ['id'=>0,'direction'=>'outbound','sender_type'=>'ai',
                'text'=>"📋 Запрос туриста для менеджера\n".$summary."\n\n".ManagerHandoffContextService::firstReplyGuidance(),
                'created_at'=>'2026-09-21 10:00:10',
                'attachments'=>ManagerHandoffContextService::customerAttachments($rows)];
        }
    }
    return ['messages'=>$rows,'original'=>$original];
}
$afterReply = $rows;
$afterReply[] = ['id'=>43,'direction'=>'outbound','sender_type'=>'manager','text'=>'Вижу фотографии, спасибо.','attachments'=>[]];
$textOnly = [['id'=>44,'direction'=>'inbound','sender_type'=>'customer','text'=>'Нужен спокойный отдых.','attachments'=>[]]];
$missing = [['id'=>45,'direction'=>'inbound','sender_type'=>'customer','text'=>'📎 Фото','attachments'=>[['type'=>'image','token'=>'SYNTHETIC_MISSING_URL']]]];
$result = [
    'photos'=>fixtureDetail($context,$rows),
    'after_reply'=>fixtureDetail($context,$afterReply),
    'text_only'=>fixtureDetail($context,$textOnly),
    'missing_url'=>fixtureDetail($context,$missing),
];
echo json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
