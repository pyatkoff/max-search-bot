<?php
declare(strict_types=1);

ini_set('display_errors', '0');
const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';

try {
    $expected = '2e7c8db9d64fea552a813b07279e55c32e9e69da';
    if (PHP_SAPI !== 'cli' || getcwd() !== ROOT || realpath(ROOT) !== ROOT
        || trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')) !== $expected
        || trim((string)shell_exec('git status --porcelain --untracked-files=no 2>/dev/null')) !== '') {
        throw new RuntimeException('installed_checkout_changed');
    }
    require_once ROOT.'/config.php';
    require_once ROOT.'/services/AppleService39TelegramDevicePublisher.php';
    $db = ConversationDb::connection();
    $actor = $db->prepare("SELECT admin_manager_id FROM appleservice39_pilot_campaigns
        WHERE campaign_key=? AND project_key='appleservice39' AND bot_key='Appleservice39_bot'");
    $actor->execute([AppleService39TelegramDevicePublisher::DEVICE_CAMPAIGN]);
    $managerId = (int)$actor->fetchColumn();
    if ($managerId <= 0) throw new RuntimeException('apple_project_admin_required');
    $publisher = new AppleService39TelegramDevicePublisher($db);
    $connection = AppleService39TelegramConnection::current();
    $catalogue = new AppleService39TelegramCatalogue();
    $state = $publisher->catalogueStatus($catalogue);
    $first = json_decode('{"repair-iphone-x-battery-replacement":{"state":"published","url":"https://t.me/appleservice39/12"},"repair-iphone-x-front-glass-replacement":{"state":"published","url":"https://t.me/appleservice39/13"},"repair-iphone-x-display":{"state":"published","url":"https://t.me/appleservice39/14"},"repair-iphone-15-battery-replacement":{"state":"published","url":"https://t.me/appleservice39/15"},"repair-iphone-15-front-glass-replacement":{"state":"published","url":"https://t.me/appleservice39/16"},"repair-iphone-15-display":{"state":"published","url":"https://t.me/appleservice39/17"},"repair-iphone-15-plus-battery-replacement":{"state":"published","url":"https://t.me/appleservice39/18"},"repair-iphone-15-plus-front-glass-replacement":{"state":"published","url":"https://t.me/appleservice39/19"},"repair-iphone-15-plus-display":{"state":"published","url":"https://t.me/appleservice39/20"},"repair-iphone-15-pro-battery-replacement":{"state":"published","url":"https://t.me/appleservice39/21"},"repair-iphone-15-pro-front-glass-replacement":{"state":"published","url":"https://t.me/appleservice39/22"},"repair-iphone-15-pro-display":{"state":"published","url":"https://t.me/appleservice39/23"},"repair-iphone-15-pro-max-battery-replacement":{"state":"published","url":"https://t.me/appleservice39/24"},"repair-iphone-15-pro-max-front-glass-replacement":{"state":"published","url":"https://t.me/appleservice39/25"},"repair-iphone-15-pro-max-display":{"state":"published","url":"https://t.me/appleservice39/26"},"model-iphone-x":{"state":"published","url":"https://t.me/appleservice39/27"},"model-iphone-15":{"state":"published","url":"https://t.me/appleservice39/28"},"model-iphone-15-plus":{"state":"published","url":"https://t.me/appleservice39/29"},"model-iphone-15-pro":{"state":"published","url":"https://t.me/appleservice39/30"},"model-iphone-15-pro-max":{"state":"published","url":"https://t.me/appleservice39/31"},"service-display":{"state":"rejected","url":null},"service-front-glass-replacement":{"state":"pending","url":null},"service-battery-replacement":{"state":"pending","url":null},"menu":{"state":"pending","url":null},"model-iphone-17":{"state":"pending","url":null},"repair-iphone-16-display":{"state":"pending","url":null},"service-rear-glass-replacement":{"state":"pending","url":null},"symptom-not-charging":{"state":"pending","url":null},"service-rear-camera-replacement":{"state":"pending","url":null},"service-earpiece-replacement":{"state":"pending","url":null},"model-iphone-8":{"state":"pending","url":null},"model-iphone-8-plus":{"state":"pending","url":null},"model-iphone-xr":{"state":"pending","url":null},"model-iphone-xs":{"state":"pending","url":null},"model-iphone-xs-max":{"state":"pending","url":null},"model-iphone-11":{"state":"pending","url":null},"model-iphone-11-pro":{"state":"pending","url":null},"model-iphone-11-pro-max":{"state":"pending","url":null},"model-iphone-12":{"state":"pending","url":null},"model-iphone-12-mini":{"state":"pending","url":null},"model-iphone-12-pro":{"state":"pending","url":null},"model-iphone-12-pro-max":{"state":"pending","url":null},"model-iphone-13":{"state":"pending","url":null},"model-iphone-13-mini":{"state":"pending","url":null},"model-iphone-13-pro":{"state":"pending","url":null},"model-iphone-13-pro-max":{"state":"pending","url":null},"model-iphone-14":{"state":"pending","url":null},"model-iphone-14-plus":{"state":"pending","url":null},"model-iphone-14-pro":{"state":"pending","url":null},"model-iphone-14-pro-max":{"state":"pending","url":null},"model-iphone-16":{"state":"pending","url":null},"model-iphone-16-plus":{"state":"pending","url":null},"model-iphone-16-pro":{"state":"pending","url":null},"model-iphone-16-pro-max":{"state":"pending","url":null},"model-iphone-17-pro":{"state":"pending","url":null},"model-iphone-17-pro-max":{"state":"pending","url":null},"model-iphone-air":{"state":"pending","url":null},"models-index":{"state":"pending","url":null},"service-charging-port-replacement":{"state":"pending","url":null},"service-microphone-replacement":{"state":"pending","url":null},"service-loudspeaker-replacement":{"state":"pending","url":null},"service-front-camera-replacement":{"state":"pending","url":null},"service-home-button-repair":{"state":"pending","url":null},"service-power-button-repair":{"state":"pending","url":null},"service-antenna-repair":{"state":"pending","url":null},"service-software-recovery":{"state":"pending","url":null},"service-diagnostics":{"state":"pending","url":null},"service-housing-repair":{"state":"pending","url":null},"services-index":{"state":"pending","url":null}}', true, 16, JSON_THROW_ON_ERROR);
    if ($state !== $first) throw new RuntimeException('original_receipt_changed');

    // The original run accepted 20 posts before Telegram explicitly rejected the next write.
    // Retry only the rejected write and use smaller waves with a cooldown.
    for ($wave = 0; $wave < 5; $wave++) {
        if ($wave > 0) sleep(63);
        for ($batch = 0; $batch < 2; $batch++) {
            $connection->requireReadyForPublication($managerId);
            $before = $state;
            $state = $publisher->publishCatalogue($managerId, $catalogue);
            $counts = array_count_values(array_column($state, 'state'));
            echo json_encode(['published'=>$counts['published'] ?? 0,
                'pending'=>$counts['pending'] ?? 0,'rejected'=>$counts['rejected'] ?? 0,
                'unknown'=>$counts['unknown'] ?? 0], JSON_THROW_ON_ERROR).PHP_EOL;
            if (($counts['published'] ?? 0) === count($state)
                || isset($counts['rejected']) || isset($counts['unknown']) || $state === $before) break 2;
        }
    }
    $counts = array_count_values(array_column($state, 'state'));
    $ok = ($counts['published'] ?? 0) === count($state);
    echo json_encode(['ok'=>$ok,'channel'=>'@appleservice39','counts'=>$counts,'posts'=>$state,
        'application_sha'=>$expected], JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    $allow = ['installed_checkout_changed','apple_project_admin_required','original_receipt_changed',
        'telegram_connection_required','telegram_read_failed','telegram_receipt_uncertain','telegram_campaign_changed',
        'telegram_channel_post_access_required','telegram_catalogue_artwork_invalid','connection_required'];
    $reason = in_array($error->getMessage(), $allow, true) ? $error->getMessage() : 'telegram_catalogue_unavailable';
    echo json_encode(['ok'=>false,'reason'=>$reason], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}
