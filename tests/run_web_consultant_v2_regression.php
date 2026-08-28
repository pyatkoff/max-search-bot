<?php
$widget = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget.js');
$preview = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/index.php');
$ux = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/ux.js');
$rollout = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/rollout.php');

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
$check(strpos($widget, "await submitText(prompt)") !== false, 'quick prompts send immediately without a second tap');
$check(strpos($widget, "e.key==='Escape'") !== false, 'desktop Escape closes the consultant');
$check(strpos($widget, 'resetComposerHeight') !== false, 'composer height resets after send or close');
$check(strpos($widget, 'lastFocus') !== false, 'closing consultant restores prior focus');
$check(strpos($widget, '100dvh') !== false, 'mobile consultant uses dynamic viewport height');
$check(strpos($widget, 'env(safe-area-inset-top)') !== false, 'mobile header respects top safe area');
$check(strpos($widget, 'env(safe-area-inset-bottom)') !== false, 'mobile composer respects bottom safe area');
$check(strpos($widget, "new URL('api.php'") !== false, 'widget still uses local canonical API endpoint');
$check(strpos($widget, "action:'send'") !== false, 'existing send transport is preserved');
$check(strpos($widget, "action:'poll'") !== false, 'existing polling transport is preserved');
$check(strpos($widget, "action:'profile'") !== false, 'existing contact handoff transport is preserved');
$check(strpos($preview, 'widget.js?v=2') !== false, 'preview loads V2 widget cache key');
$check(strpos($preview, 'ux.js?v=1') !== false, 'preview loads conversation focus UX companion');
$check(strpos($ux, "messages.querySelector('.msg')") !== false, 'conversation UX detects real chat messages');
$check(strpos($ux, 'welcome.remove()') !== false, 'welcome content is removed after conversation starts');
$check(strpos($ux, 'MutationObserver') !== false, 'conversation focus follows asynchronously loaded messages');
$check(strpos($rollout, '/max-search/web-consultant/ux.js') !== false, 'rollout includes conversation focus UX companion');
$check(strpos($rollout, 'data-anytour-webchat-ux') !== false, 'rollout guards UX companion against duplicate injection');

if ($failures) {
    fwrite(STDERR, "Web consultant V2 regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEB CONSULTANT V2 REGRESSION PASSED\n";
