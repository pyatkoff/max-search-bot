<?php
$widget = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget.js');
$enhancer = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget-a11y.js');
$preview = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/index.php');
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
$check(strpos($widget, '.welcome.is-hidden{display:none}') !== false, 'welcome screen has compact conversation state');
$check(strpos($widget, 'const hideWelcome=') !== false, 'widget hides welcome after real conversation messages arrive');
$check(strpos($widget, 'hideWelcome();const d=document.createElement') !== false, 'message rendering activates compact conversation state');
$check(strpos($widget, '100dvh') !== false, 'mobile consultant uses dynamic viewport height');
$check(strpos($widget, 'env(safe-area-inset-top)') !== false, 'mobile header respects top safe area');
$check(strpos($widget, 'env(safe-area-inset-bottom)') !== false, 'mobile composer respects bottom safe area');
$check(strpos($widget, "new URL('api.php'") !== false, 'widget still uses local canonical API endpoint');
$check(strpos($widget, "action:'send'") !== false, 'existing send transport is preserved');
$check(strpos($widget, "action:'poll'") !== false, 'existing polling transport is preserved');
$check(strpos($widget, "action:'profile'") !== false, 'existing contact handoff transport is preserved');

$check($enhancer !== '', 'accessibility enhancer exists');
$check(strpos($enhancer, "setAttribute('aria-expanded'") !== false, 'launcher exposes expanded state');
$check(strpos($enhancer, "setAttribute('aria-controls'") !== false, 'launcher is linked to dialog');
$check(strpos($enhancer, "event.key!=='Tab'") !== false, 'open dialog traps keyboard tab navigation');
$check(strpos($enhancer, 'prefers-reduced-motion:reduce') !== false, 'reduced motion preference is respected');
$check(strpos($enhancer, "document.body.style.overflow='hidden'") !== false, 'mobile fullscreen locks background page scroll');
$check(strpos($enhancer, 'previousBodyOverflow') !== false, 'background scroll state is restored after close');
$check(strpos($enhancer, 'MutationObserver') !== false, 'enhancer tracks open and closed dialog state');

$check(strpos($preview, "filemtime(__DIR__ . '/widget.js')") !== false, 'preview cache-busts widget from file modification time');
$check(strpos($preview, "filemtime(__DIR__ . '/widget-a11y.js')") !== false, 'preview cache-busts accessibility enhancer');
$check(strpos($preview, 'widget.js?v=<?=') !== false, 'preview emits a versioned widget URL');
$check(strpos($preview, 'widget-a11y.js?v=<?=') !== false, 'preview loads accessibility enhancer');
$check(strpos($rollout, "filemtime(__DIR__ . '/widget.js')") !== false, 'default rollout cache-busts widget from file modification time');
$check(strpos($rollout, "'/max-search/web-consultant/widget.js?v='") !== false, 'default rollout emits a versioned canonical widget URL');
$check(strpos($rollout, "'/max-search/web-consultant/widget-a11y.js?v='") !== false, 'rollout emits a versioned accessibility enhancer URL');
$check(strpos($rollout, 's.onload=ensureEnhancer') !== false, 'rollout loads enhancer after widget is ready');

if ($failures) {
    fwrite(STDERR, "Web consultant V2 regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEB CONSULTANT V2 REGRESSION PASSED\n";
