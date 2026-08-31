<?php
/**
 * Safe configuration template for MAX Search Bot / shared AI tour core.
 * Copy to config.php on a new environment and fill values there.
 * config.php is intentionally ignored by Git and must never be committed.
 */

define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-5.6-luna');
define('TOURVISOR_JWT', '');
define('MAX_SEARCH_TOKEN', '');
define('MAX_SEARCH_WEBHOOK_SECRET', '');
define('MAX_SEARCH_WEBHOOK_URL', '');
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_WEBHOOK_SECRET', '');
define('TELEGRAM_WEBHOOK_URL', '');

define('CONVERSATION_DB_HOST', '');
define('CONVERSATION_DB_NAME', '');
define('CONVERSATION_DB_USER', '');
define('CONVERSATION_DB_PASS', '');
define('CONVERSATION_DB_CHARSET', 'utf8mb4');

define('ANYTOUR_DATA_DB_HOST', '');
define('ANYTOUR_DATA_DB_NAME', '');
define('ANYTOUR_DATA_DB_USER', '');
define('ANYTOUR_DATA_DB_PASSWORD', '');

// Deployment-specific public hosts. Leave empty to use project.php legacy
// defaults. Standby/cutover hosts can override these without changing shared
// project identity or forcing the old production host to move early.
define('MAX_SEARCH_PUBLIC_BASE_URL', '');
define('MAX_SEARCH_TRACKING_BASE_URL', '');

// Standalone migration switches. Keep legacy defaults until the cutover doctor
// reports no blockers. Never enable these implicitly based on hostname/server.
define('MAX_SEARCH_STANDALONE_RUNTIME', false);
define('MAX_SEARCH_RUNTIME_STORAGE', 'legacy');
define('MAX_SEARCH_DESTINATION_STORAGE', 'bitrix');
// Preserve the existing AnyTour lead project marker when Bitrix CSiteParams is
// not loaded on the standalone host. Set this to the current production value.
define('MAX_SEARCH_IS_ANYTOUR_ONLINE', '');
// `bridge` uses the same canonical lead payload but sends it over authenticated
// server-to-server HTTP to lead-receiver.php on the legacy Bitrix host.
define('MAX_SEARCH_LEAD_DELIVERY', 'bitrix');
define('MAX_SEARCH_LEAD_RECEIVER_URL', '');
define('MAX_SEARCH_LEAD_BRIDGE_SECRET', '');

define('WEBSITE_ROLLOUT_PERCENT', 0);
define('WEBSITE_ROLLOUT_SALT', '');
define('WEBSITE_WIDGET_URL', '');

// IMPORTANT: production config.php may contain additional legacy/project keys.
// Never copy real secret values into this template.