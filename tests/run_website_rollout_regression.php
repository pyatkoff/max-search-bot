<?php
require_once __DIR__ . '/../services/WebsiteRolloutService.php';

function assertTrue($condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$off = new WebsiteRolloutService(0, 'test');
assertTrue($off->isEnabled('visitor-a') === false, '0 percent must disable every visitor');

$all = new WebsiteRolloutService(100, 'test');
assertTrue($all->isEnabled('visitor-a') === true, '100 percent must enable non-empty visitor');
assertTrue($all->isEnabled('') === false, 'empty visitor must never be enabled');

$rollout = new WebsiteRolloutService(10, 'stable-salt');
$bucket1 = $rollout->bucket('visitor-a');
$bucket2 = $rollout->bucket('visitor-a');
assertTrue($bucket1 === $bucket2, 'bucket assignment must be stable');
assertTrue($bucket1 >= 0 && $bucket1 <= 99, 'bucket must be in 0..99');
assertTrue($rollout->isEnabled('visitor-a') === ($bucket1 < 10), 'selection must match bucket threshold');

$clampedLow = new WebsiteRolloutService(-50, 'test');
$clampedHigh = new WebsiteRolloutService(500, 'test');
assertTrue($clampedLow->percent() === 0, 'negative percentage must clamp to 0');
assertTrue($clampedHigh->percent() === 100, 'percentage above 100 must clamp to 100');

$selected = 0;
for ($i = 0; $i < 1000; $i++) {
    if ($rollout->isEnabled('visitor-' . $i)) {
        $selected++;
    }
}
assertTrue($selected >= 60 && $selected <= 140, '10 percent rollout should select an approximately bounded share');

$loaderSource = file_get_contents(__DIR__ . '/../web-consultant/rollout.php');
assertTrue(is_string($loaderSource) && $loaderSource !== '', 'canonical rollout loader source must be readable');
assertTrue(strpos($loaderSource, 'window.localStorage.getItem') !== false, 'browser rollout must read a first-party localStorage bucket');
assertTrue(strpos($loaderSource, 'window.localStorage.setItem') !== false, 'browser rollout must persist a first-party localStorage bucket');
assertTrue(strpos($loaderSource, 'document.cookie=key') !== false, 'browser rollout must have a first-party cookie fallback');
assertTrue(strpos($loaderSource, "setcookie('anytour_webchat_rollout'") === false, 'cross-origin loader must not rely on a third-party response cookie');
assertTrue(strpos($loaderSource, "\$_COOKIE['anytour_webchat_rollout']") === false, 'cross-origin loader must not read server-side third-party assignment cookies');
assertTrue(strpos($loaderSource, "if(percent<=0)return") !== false, '0 percent rollout must short-circuit before loading widget');
assertTrue(strpos($loaderSource, "if(percent<100&&bucket>=percent)return") !== false, 'browser bucket must gate widget by configured percent');
assertTrue(strpos($loaderSource, '/max-search/web-consultant/widget.js') !== false, 'canonical rollout must default to canonical widget URL');

$legacyLoader = file_get_contents(__DIR__ . '/../website/rollout.php');
assertTrue(strpos((string)$legacyLoader, "web-consultant/rollout.php") !== false, 'legacy rollout path must delegate to canonical module');

echo "WEBSITE ROLLOUT REGRESSION PASS selected={$selected}/1000 first_party_sticky=1 canonical_module=1\n";
