<?php
require_once __DIR__ . '/../services/CallbackController.php';

/**
 * Backward-compatible wrapper. New code should use CallbackController directly.
 */
class CallbackHandler
{
    public static function handle($query)
    {
        $controller = new CallbackController();
        return $controller->handle((array)$query);
    }
}
