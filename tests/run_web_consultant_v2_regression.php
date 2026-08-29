<?php
$widget = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget.js');
$enhancer = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget-a11y.js');
$context = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/widget-context.js');
$preview = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/index.php');
$rollout = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/rollout.php');
$api = (string) file_get_contents(dirname(__DIR__) . '/web-consultant/api.php');
$pageContextService = (string) file_get_contents(dirname(__DIR__) . '/services/WebsitePageContextService.php');
$aiInvocation = (string) file_get_contents(dirname(__DIR__) . '/services/AiInvocationService.php');
$aiRouter = (string) file_get_contents(dirname(__DIR__) . '/ai/AiRouter.php');
$migration = (string) file_get_contents(dirname(__DIR__) . '/migrations/017_website_page_context.sql');
$structuredMigration = (string) file_get_contents(dirname(__DIR__) . '/migrations/018_website_structured_page_context.sql');

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

$check($context !== '', 'page context reporter exists');
$check(strpos($context, "action:'context'") !== false, 'page context reporter posts dedicated context action');
$check(strpos($context, 'url:location.href') !== false, 'page context reporter captures current page URL');
$check(strpos($context, 'title:document.title') !== false, 'page context reporter captures current page title');
$check(strpos($context, 'anytour_consultant_token_v1') !== false, 'page context reporter reuses canonical consultant session token');
$check(strpos($context, 'window.AnyTourPageContext') !== false, 'page context reporter accepts explicit structured site context');
$check(strpos($context, 'application/ld+json') !== false, 'page context reporter can fall back to standard JSON-LD');
$check(strpos($context, "['Hotel','LodgingBusiness','Product','TouristTrip']") !== false, 'JSON-LD extraction is limited to travel/product entity types');
$check(strpos($context, "window.addEventListener('anytour:page-context'") !== false, 'site can notify consultant when selected tour changes');
$check(strpos($context, "window.addEventListener('popstate'") !== false, 'page context reporter notices client-side navigation');
$check(strpos($context, 'setInterval') !== false, 'page context reporter refreshes SPA page context');

$check(strpos($preview, "filemtime(__DIR__ . '/widget.js')") !== false, 'preview cache-busts widget from file modification time');
$check(strpos($preview, "filemtime(__DIR__ . '/widget-a11y.js')") !== false, 'preview cache-busts accessibility enhancer');
$check(strpos($preview, 'widget.js?v=<?=') !== false, 'preview emits a versioned widget URL');
$check(strpos($preview, 'widget-a11y.js?v=<?=') !== false, 'preview loads accessibility enhancer');
$check(strpos($rollout, "filemtime(__DIR__ . '/widget.js')") !== false, 'default rollout cache-busts widget from file modification time');
$check(strpos($rollout, "'/max-search/web-consultant/widget.js?v='") !== false, 'default rollout emits a versioned canonical widget URL');
$check(strpos($rollout, "'/max-search/web-consultant/widget-a11y.js?v='") !== false, 'rollout emits a versioned accessibility enhancer URL');
$check(strpos($rollout, "'/max-search/web-consultant/widget-context.js?v='") !== false, 'rollout emits a versioned page context reporter URL');
$check(strpos($rollout, 's.onload=ensureExtras') !== false, 'rollout loads consultant extras after widget is ready');
$check(strpos($rollout, 'data-anytour-webchat-context') !== false, 'rollout loads page context reporter once');

$check(strpos($api, "\$action === 'context'") !== false, 'API exposes dedicated page context action');
$check(strpos($api, 'WebsitePageContextService::save') !== false, 'API stores page context separately from dialogue transport');
$check(strpos($pageContextService, 'WebsiteOriginPolicy::configuredOrigins()') !== false, 'page context URL is limited to configured website origins');
$check(strpos($pageContextService, "strpos(\$lower, 'utm_') === 0") !== false, 'page context strips UTM parameters');
$check(strpos($pageContextService, "\$lower === 'yclid'") !== false, 'page context strips Yandex click id');
$check(strpos($pageContextService, 'sanitizeStructured') !== false, 'structured page data passes a server whitelist');
$check(strpos($pageContextService, "'hotel_name'=>220") !== false, 'structured context explicitly allows hotel name');
$check(strpos($pageContextService, "['price'=>1000000000,'stars'=>5,'nights'=>60]") !== false, 'numeric structured fields have hard bounds');
$check(strpos($pageContextService, 'context_json') !== false, 'structured context is stored outside tour search state');
$check(strpos($pageContextService, 'ON DUPLICATE KEY UPDATE') !== false, 'page context follows current page within one session');
$check(strpos($aiInvocation, "\$aiCurrent['_page_context']") !== false, 'AI receives website page context only at invocation boundary');
$check(strpos($aiRouter, '_page_context') !== false, 'AI prompt defines safe page context semantics');
$check(strpos($aiRouter, '_page_context.structured') !== false, 'AI understands structured hotel and tour context');
$check(strpos($aiRouter, 'не должен молча менять search-state') !== false, 'structured context cannot silently mutate booking/search state');
$check(strpos($migration, 'CREATE TABLE IF NOT EXISTS website_page_context') !== false, 'page context has isolated migration-backed storage');
$check(strpos($structuredMigration, 'ADD COLUMN context_json TEXT NULL') !== false, 'structured context storage has an additive migration');

if ($failures) {
    fwrite(STDERR, "Web consultant V2 regression failed: " . implode('; ', $failures) . "\n");
    exit(1);
}

echo "WEB CONSULTANT V2 REGRESSION PASSED\n";
