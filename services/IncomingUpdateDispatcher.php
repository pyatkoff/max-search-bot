<?php
require_once __DIR__ . '/DialogueApplication.php';
require_once __DIR__ . '/DiagnosticLogger.php';
require_once __DIR__ . '/ConversationRecorder.php';
require_once __DIR__ . '/ConversationControlService.php';

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
        if (!ConversationControlService::shouldRouteToAi($platform, $chatId)) {
            DiagnosticLogger::log('incoming_dispatch','manager_owned',['platform'=>$platform,'type'=>$type],$chatId);
            return true;
        }

        $handled = $this->application->dispatch($incoming);
        DiagnosticLogger::log('incoming_dispatch',$handled?'handled':'ignored',['platform'=>$platform,'type'=>$type],$chatId);
        return $handled;
    }
}
