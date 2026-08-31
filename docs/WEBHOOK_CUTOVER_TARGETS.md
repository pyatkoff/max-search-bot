# Webhook cutover targets

Webhook destinations are deployment configuration, not shared project identity.

- `TELEGRAM_WEBHOOK_URL` controls the Telegram webhook target used by `telegram_webhook_admin.php`.
- `MAX_SEARCH_WEBHOOK_URL` controls the MAX subscription target used by `repair_max_search_subscription.php`.
- Empty values preserve the legacy production targets for backward compatibility.
- Standby should be configured with its own HTTPS endpoints before activation.
- `tools/webhook_target_status.php` is read-only and prints only target URLs/hosts, never tokens or secrets.

Changing these constants alone does not switch messenger traffic. Actual Telegram/MAX API mutation remains an explicit cutover action after write freeze and final DB synchronization.