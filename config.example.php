<?php
/**
 * Safe configuration template for MAX Search Bot / shared AI tour core.
 *
 * Copy to config.php on a new environment and fill values there.
 * config.php is intentionally ignored by Git and must never be committed.
 *
 * Keep this file value-free: it documents names/contracts only.
 */

// OpenAI Responses API used by ai/AiClient.php.
define('OPENAI_API_KEY', '');
define('OPENAI_MODEL', 'gpt-5.6-luna');

// Tourvisor Search API JWT used by the route-cache updater on production.
define('TOURVISOR_JWT', '');

// Messenger secrets. Only the provider selected in project.php is required.
define('MAX_SEARCH_TOKEN', '');
define('MAX_SEARCH_WEBHOOK_SECRET', '');
define('TELEGRAM_BOT_TOKEN', '');
// Optional but recommended. Telegram sends it in X-Telegram-Bot-Api-Secret-Token.
define('TELEGRAM_WEBHOOK_SECRET', '');

// Dedicated omnichannel conversation store. Keep credentials only in config.php.
define('CONVERSATION_DB_HOST', '');
define('CONVERSATION_DB_NAME', '');
define('CONVERSATION_DB_USER', '');
define('CONVERSATION_DB_PASS', '');
define('CONVERSATION_DB_CHARSET', 'utf8mb4');

// Separate AnyTour catalog database used by departures/destinations/hotels.
define('ANYTOUR_DATA_DB_HOST', '');
define('ANYTOUR_DATA_DB_NAME', '');
define('ANYTOUR_DATA_DB_USER', '');
define('ANYTOUR_DATA_DB_PASSWORD', '');

// Standalone migration switches. Keep legacy defaults until the cutover doctor
// reports no blockers. Never enable these implicitly based on hostname/server.
define('MAX_SEARCH_STANDALONE_RUNTIME', false);
define('MAX_SEARCH_RUNTIME_STORAGE', 'legacy');
define('MAX_SEARCH_DESTINATION_STORAGE', 'bitrix');
define('MAX_SEARCH_LEAD_DELIVERY', 'bitrix');

// WEBSITE controlled rollout. Keep WEBSITE_ROLLOUT_PERCENT at 0 until the
// main AnyTour site intentionally embeds website/rollout.php. Increase it in
// small steps (for example 5 -> 10 -> 25) after production evidence is healthy.
define('WEBSITE_ROLLOUT_PERCENT', 0);
define('WEBSITE_ROLLOUT_SALT', '');
define('WEBSITE_WIDGET_URL', '');

// IMPORTANT:
// The production config.php may contain additional legacy/project-specific
// constants required by Bitrix/U-ON or analytics code.
// When a new config key is introduced in source code, add its empty contract
// here at the same time, but never copy the real secret/value.
