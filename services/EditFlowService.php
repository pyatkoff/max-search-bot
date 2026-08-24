<?php
require_once __DIR__ . '/DialogueView.php';

class EditFlowService
{
    public static function finishIfNeeded($chatId, string $field): bool
    {
        if ((string)MaxSearchApi::getEditMode($chatId) !== $field) return false;
        MaxSearchApi::setEditMode($chatId, '');
        DialogueView::check($chatId);
        return true;
    }
}
