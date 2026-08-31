<?php
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/services/RuntimeBootstrap.php');
RuntimeBootstrap::boot((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
require_once(__DIR__ . '/maxsearchclass.php');
require_once(__DIR__ . '/handlers/MaxUpdateHandler.php');

$is_log = true;

// Temporary legacy helper used by CallbackHandler while callback presentation is
// being moved into the shared dialogue layer.
function maxQueryUserName($query)
{
    $name = '';
    if (!empty($query['from']['first_name'])) {
        $name = $query['from']['first_name'];
        if (!empty($query['from']['last_name'])) $name .= ' ' . $query['from']['last_name'];
    } elseif (!empty($query['from']['username'])) {
        $name = $query['from']['username'];
    }
    return trim($name);
}

function put_log_in($data)
{
    global $is_log;
    if ($is_log) file_put_contents('tmp_in.txt', date('d.m.Y H:i:s') . '--- ' . $data . "\r\n", FILE_APPEND);
}

function put_log_out($data)
{
    global $is_log;
    if ($is_log) file_put_contents('tmp_out.txt', date('d.m.Y H:i:s') . '--- ' . $data . "\r\n", FILE_APPEND);
}

MaxUpdateHandler::handle();
