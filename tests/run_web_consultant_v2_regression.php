<?php
$widget = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget.js');
$preview = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/index.php');

$failures = [];
$check = function (bool $ok, string $message) use (&$failures): void {
    if ($ok) {
        echo "PASS {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL {$message}\n";
};

$check($widget !== '', 'widget source exists');
$check(strpos($widget, 'Подобрать тур с AI') !== false, 'launcher has product-oriented CTA');
$check(strpos($widget, 'quick-actions') !== false, 'welcome screen exposes quick actions');
$check(substr_count($widget, 'data-prompt=') >= 4, 'welcome screen keeps four quick prompts');
$check(strpos($widget, '100dvh') !== false, 'mobile consultant uses dynamic viewport height');
$check(strpos($widget, 'env(safe-area-inset-top)') !== false, 'mobile header respects top safe area');
$check(strpos($widget, 'env(safe-area-inset-bottom)') !== false, 'mobile composer respects bottom safe area');
$check(strpos($widget, "new URL('api.php'") !== false, 'widget still uses local canonical API endpoint');
$check(strpos($widget, "action:'send'") !== false, 'existing send transport is preserved');
$check(strpos($widget, "action:'poll'") !== false, 'existing polling transport is preserved');
$check(strpos($widget, "action:'profile'") !== false, 'existing contact handoff transport is preserved');
$check(strpos($preview, 'widget.js?v=2') !== false, 'preview loads V2 widget cache key');

if ($failures) {
    fwrite(STDERR, "Web consultant V2 regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEB CONSULTANT V2 REGRESSION PASSED\n";
