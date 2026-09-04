<?php

require_once __DIR__ . '/DialogueStateMachine.php';
require_once __DIR__ . '/DiagnosticLogger.php';

/**
 * Observe a proposed canonical transition without changing or blocking it.
 *
 * Callers intentionally receive no decision value. Blocking may be introduced
 * only after production evidence proves the observed transition contract.
 */
final class DialogueTransitionObserver
{
    public static function observe(
        $chatId,
        int $fromStatus,
        int $toStatus,
        string $mode,
        string $scope
    ): void {
        $fromState = DialogueStateMachine::stateForStatus($fromStatus);
        $toState = DialogueStateMachine::stateForStatus($toStatus);
        $allowed = $fromState !== null
            && $toState !== null
            && DialogueStateMachine::canTransition($fromState, $toState, $mode);

        $data = [
            'scope' => $scope,
            'mode' => $mode,
            'from_status' => $fromStatus,
            'from_state' => $fromState,
            'to_status' => $toStatus,
            'to_state' => $toState,
            'allowed' => $allowed,
        ];

        if ($allowed) {
            DiagnosticLogger::log('dialogue_transition', 'transition_observed', $data, $chatId);
            return;
        }

        DiagnosticLogger::warning('dialogue_transition', 'transition_violation_observed', $data, $chatId);
    }
}
