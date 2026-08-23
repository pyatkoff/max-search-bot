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

// IMPORTANT:
// The production config.php may contain additional legacy/project-specific
// constants required by Bitrix/U-ON or analytics code.
// When a new config key is introduced in source code, add its empty contract
// here at the same time, but never copy the real secret/value.
