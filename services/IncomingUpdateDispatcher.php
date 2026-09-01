<?php
require_once __DIR__ . '/DialogueApplication.php';
require_once __DIR__ . '/DiagnosticLogger.php';
require_once __DIR__ . '/ConversationRecorder.php';
require_once __DIR__ . '/ConversationAttributionService.php';
require_once __DIR__ . '/ConversationControlService.php';
require_once __DIR__ . '/ManagerPushService.php';
require_once __DIR__ . '/MetrikaConversionGoalService.php';
require_once __DIR__ . '/SourceHandlingService.php';

class IncomingUpdateDispatcher
{
    private $application;

    public function __construct(?DialogueApplication $application = null)
    {
        $this->application = $application ?: new DialogueApplication();
    }

    public function dispatch(?array $incoming): bool
    {
        if (!$incoming) return false;
        $platform = strtolower(trim((string)($incoming['platform'] ?? '')));
        $type = (string)($incoming['type'] ?? '');
        $chatId = $incoming['user']['chat_id'] ?? 0;
        if ($platform === '' || $type === '' || !$chatId) {
            DiagnosticLogger::log('incoming_dispatch','invalid_incoming',['platform'=>$platform,'type'=>$type,'has_chat_id'=>(bool)$chatId],$chatId ?: null,'warning');
            return false;
        }

        ConversationRecorder::inbound($incoming);
        ConversationAttributionService::syncByChat($platform,$chatId);
        if (SourceHandlingService::handle($incoming)) {
            DiagnosticLogger::log('incoming_dispatch','source_handling',['platform'=>$platform,'type'=>$type],$chatId);
            return true;
        }
        $ownership = ConversationControlService::statusByChat($platform, $chatId);
        if ($ownership && in_array((string)$ownership['status'], ['waiting_manager','manager'], true)) {
            $status = (string)$ownership['status'];
            $allow = false;

            if ($status === 'manager' && $type !== 'callback') {
                MetrikaConversionGoalService::customerReplyAfterManager((int)$ownership['id']);
            }

            if ($status === 'waiting_manager') {
                if ($type === 'contact') {
                    $allow = true;
                } elseif ($type === 'callback') {
                    $payload = (string)($incoming['callback_data'] ?? '');
                    if ($payload === 'phone_manual') $allow = true;
                    if (in_array($payload, ['back_check','tours_checked'], true)) {
                        ConversationControlService::resumeAiByChat($platform, $chatId, 'handoff_cancelled');
                        $allow = true;
                    }
                } elseif ($type === 'message' && class_exists('MaxSearchApi')) {
                    try { $allow = MaxSearchApi::getCurentStatus($chatId) == MaxSearchApi::$statusPhone; } catch (Throwable $ignored) {}
                }
            }

            if (!$allow) {
                ManagerPushService::notifyConversation((int)$ownership['id'], $status === 'manager' ? 'Клиент ответил в вашем диалоге' : 'Новое сообщение в заявке');
                DiagnosticLogger::log('incoming_dispatch','manager_owned',['platform'=>$platform,'type'=>$type,'status'=>$status],$chatId);
                return true;
            }
        }

        $handled = $this->application->dispatch($incoming);
        DiagnosticLogger::log('incoming_dispatch',$handled?'handled':'ignored',['platform'=>$platform,'type'=>$type],$chatId);
        return $handled;
    }
}
