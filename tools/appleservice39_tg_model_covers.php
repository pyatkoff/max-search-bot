<?php
declare(strict_types=1);

ini_set('display_errors', '0');
const ROOT = '/var/www/anytoour/data/www/app.anytoour.ru';
const PROJECT = 'appleservice39';
const BOT = 'Appleservice39_bot';
const CREATE_CAMPAIGN = 'appleservice39-telegram-catalogue-v1';
const EDIT_CAMPAIGN = 'appleservice39-telegram-cover-v1';
const OPERATION = 'cover_edit';
const OLD_COVER = '2f580bbf4f0963f5a37be34db455527aa8f650597801b7d0b6e81dce790a8681';

function check(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

function jsonValue(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Requests are limited to the approved asset server, public channel and its Telegram bot. */
function request(string $url, ?array $body = null, int $limit = 3_000_000): array
{
    $curl = curl_init($url);
    $content = '';
    $tooLarge = false;
    curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>25, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'AppleService39 media verification/1.0',
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$content, &$tooLarge, $limit): int {
            if (strlen($content) + strlen($chunk) > $limit) {
                $tooLarge = true;
                return 0;
            }
            $content .= $chunk;
            return strlen($chunk);
        }]);
    if ($body !== null) curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body]);
    try {
        $ok = curl_exec($curl);
        return [(int)curl_getinfo($curl, CURLINFO_HTTP_CODE), $ok && !$tooLarge ? $content : ''];
    } finally {
        curl_close($curl);
    }
}

function telegram(string $token, string $method, array $body): array
{
    check(in_array($method, ['getMe', 'getChat', 'getChatMember', 'editMessageMedia'], true), 'unexpected_method');
    [$http, $raw] = request('https://api.telegram.org/bot'.$token.'/'.$method, $body, 1024 * 1024);
    $data = json_decode($raw, true);
    return [$http, is_array($data) ? $data : []];
}

function telegramRead(string $token, string $method, array $body = []): array
{
    [$http, $data] = telegram($token, $method, $body);
    check($http === 200 && ($data['ok'] ?? false) === true && is_array($data['result'] ?? null), 'telegram_read_failed');
    return $data['result'];
}

function normalized(string $s): string
{
    return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $s));
}

function publicPosts(): array
{
    $posts = [];
    foreach (['?before=81', '?before=61'] as $query) {
        [$http, $html] = request('https://t.me/s/appleservice39'.$query);
        check($http === 200 && $html !== '', 'channel_preview_unavailable');
        $doc = new DOMDocument();
        check(@$doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING), 'channel_preview_invalid');
        $finder = new DOMXPath($doc);
        foreach ($finder->query("//*[@data-post]") as $post) {
            if (!preg_match('~\Aappleservice39/([1-9][0-9]*)\z~D', $post->getAttribute('data-post'), $m)) continue;
            $caption = $finder->query(".//*[contains(concat(' ',normalize-space(@class),' '),' tgme_widget_message_text ')]", $post)->item(0);
            $photo = $finder->query(".//*[contains(concat(' ',normalize-space(@class),' '),' tgme_widget_message_photo_wrap ')]", $post)->item(0);
            if (!$caption || !$photo || !preg_match("~url\\('?(https://[a-z0-9.]+/file/[A-Za-z0-9_./-]+)'?\\)~", $photo->getAttribute('style'), $image)) continue;
            $buttons = [];
            foreach ($finder->query(".//a[contains(concat(' ',normalize-space(@class),' '),' tgme_widget_message_inline_button ')]", $post) as $button) {
                $buttons[] = ['text'=>normalized($button->textContent),'url'=>$button->getAttribute('href')];
            }
            $captionHtml = $doc->saveHTML($caption);
            $captionText = html_entity_decode(strip_tags(preg_replace('~<br\s*/?>~i', ' ', $captionHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $posts[(int)$m[1]] = ['caption'=>normalized($captionText),'buttons'=>$buttons,'photo'=>$image[1]];
        }
    }
    return $posts;
}

function publishResult(PDO $db, int $id, string $state, array $outcome, ?int $mid = null): void
{
    $q = $db->prepare('UPDATE appleservice39_pilot_operations SET state=?,outcome_json=?,external_message_id=?,public_url=?,updated_at=?
        WHERE id=? AND state=?');
    $q->execute([$state,jsonValue($outcome),$mid,$mid === null ? null : 'https://t.me/appleservice39/'.$mid,
        gmdate('Y-m-d H:i:s'),$id,'write_started']);
    check($q->rowCount() === 1, 'edit_receipt_uncertain');
}

$assetFiles = [];
$failure = null;
try {
    $runtime = getenv('AS39_TARGET_APP') ?: '';
    check(PHP_SAPI === 'cli' && getcwd() === ROOT && realpath(ROOT) === ROOT && preg_match('/\A[a-f0-9]{40}\z/D',$runtime)
        && trim((string)shell_exec('git rev-parse HEAD 2>/dev/null')) === $runtime
        && trim((string)shell_exec('git status --porcelain --untracked-files=no 2>/dev/null')) === '', 'installed_source_changed');
    require_once ROOT.'/config.php';
    require_once ROOT.'/services/ConversationDb.php';
    require_once ROOT.'/services/AppleService39TelegramConnection.php';
    $map = json_decode('[{"post":"model-iphone-8","mid":42,"url":"https://t.me/appleservice39/42","asset":"iphone-8-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-8-family.png","bytes":1573352,"sha256":"438df12f2c28a48ea05923e28966dfd4cc0851d7653b98836450c33b50a12dae"},{"post":"model-iphone-8-plus","mid":43,"url":"https://t.me/appleservice39/43","asset":"iphone-8-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-8-family.png","bytes":1573352,"sha256":"438df12f2c28a48ea05923e28966dfd4cc0851d7653b98836450c33b50a12dae"},{"post":"model-iphone-xr","mid":44,"url":"https://t.me/appleservice39/44","asset":"iphone-x-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-x-family.png","bytes":1749071,"sha256":"d3034463639e9ae7e2dbdeaab63f733f037c23d408fcb25b9815900af57d21df"},{"post":"model-iphone-xs","mid":45,"url":"https://t.me/appleservice39/45","asset":"iphone-x-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-x-family.png","bytes":1749071,"sha256":"d3034463639e9ae7e2dbdeaab63f733f037c23d408fcb25b9815900af57d21df"},{"post":"model-iphone-xs-max","mid":46,"url":"https://t.me/appleservice39/46","asset":"iphone-x-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-x-family.png","bytes":1749071,"sha256":"d3034463639e9ae7e2dbdeaab63f733f037c23d408fcb25b9815900af57d21df"},{"post":"model-iphone-11","mid":47,"url":"https://t.me/appleservice39/47","asset":"iphone-11-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-11-family.png","bytes":1547899,"sha256":"14b2fe9796a5c02e9a8c157e71c68302bf1e7092b8251008d785febefe5d855b"},{"post":"model-iphone-11-pro","mid":48,"url":"https://t.me/appleservice39/48","asset":"iphone-11-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-11-family.png","bytes":1547899,"sha256":"14b2fe9796a5c02e9a8c157e71c68302bf1e7092b8251008d785febefe5d855b"},{"post":"model-iphone-11-pro-max","mid":49,"url":"https://t.me/appleservice39/49","asset":"iphone-11-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-11-family.png","bytes":1547899,"sha256":"14b2fe9796a5c02e9a8c157e71c68302bf1e7092b8251008d785febefe5d855b"},{"post":"model-iphone-12","mid":50,"url":"https://t.me/appleservice39/50","asset":"iphone-12-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-12-family.png","bytes":1642000,"sha256":"a9d34165f0a7c1108377b0c42c4771b425aa7cb4caa1330c08a90605ce1b5cdc"},{"post":"model-iphone-12-mini","mid":51,"url":"https://t.me/appleservice39/51","asset":"iphone-12-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-12-family.png","bytes":1642000,"sha256":"a9d34165f0a7c1108377b0c42c4771b425aa7cb4caa1330c08a90605ce1b5cdc"},{"post":"model-iphone-12-pro","mid":52,"url":"https://t.me/appleservice39/52","asset":"iphone-12-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-12-family.png","bytes":1642000,"sha256":"a9d34165f0a7c1108377b0c42c4771b425aa7cb4caa1330c08a90605ce1b5cdc"},{"post":"model-iphone-12-pro-max","mid":53,"url":"https://t.me/appleservice39/53","asset":"iphone-12-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-12-family.png","bytes":1642000,"sha256":"a9d34165f0a7c1108377b0c42c4771b425aa7cb4caa1330c08a90605ce1b5cdc"},{"post":"model-iphone-13","mid":54,"url":"https://t.me/appleservice39/54","asset":"iphone-13-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-13-family.png","bytes":1590461,"sha256":"bd30f92d1d1059565d43ed5b46b048b1533ad31f5231612728043f5d88d47848"},{"post":"model-iphone-13-mini","mid":55,"url":"https://t.me/appleservice39/55","asset":"iphone-13-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-13-family.png","bytes":1590461,"sha256":"bd30f92d1d1059565d43ed5b46b048b1533ad31f5231612728043f5d88d47848"},{"post":"model-iphone-13-pro","mid":56,"url":"https://t.me/appleservice39/56","asset":"iphone-13-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-13-family.png","bytes":1590461,"sha256":"bd30f92d1d1059565d43ed5b46b048b1533ad31f5231612728043f5d88d47848"},{"post":"model-iphone-13-pro-max","mid":57,"url":"https://t.me/appleservice39/57","asset":"iphone-13-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-13-family.png","bytes":1590461,"sha256":"bd30f92d1d1059565d43ed5b46b048b1533ad31f5231612728043f5d88d47848"},{"post":"model-iphone-14","mid":58,"url":"https://t.me/appleservice39/58","asset":"iphone-14-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-14-family.png","bytes":1617931,"sha256":"55cf7e9a2fb640c3a68cc1720d8a955c3ba718126e07a22384d25a80576d5e64"},{"post":"model-iphone-14-plus","mid":59,"url":"https://t.me/appleservice39/59","asset":"iphone-14-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-14-family.png","bytes":1617931,"sha256":"55cf7e9a2fb640c3a68cc1720d8a955c3ba718126e07a22384d25a80576d5e64"},{"post":"model-iphone-14-pro","mid":60,"url":"https://t.me/appleservice39/60","asset":"iphone-14-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-14-family.png","bytes":1617931,"sha256":"55cf7e9a2fb640c3a68cc1720d8a955c3ba718126e07a22384d25a80576d5e64"},{"post":"model-iphone-14-pro-max","mid":61,"url":"https://t.me/appleservice39/61","asset":"iphone-14-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-14-family.png","bytes":1617931,"sha256":"55cf7e9a2fb640c3a68cc1720d8a955c3ba718126e07a22384d25a80576d5e64"},{"post":"model-iphone-16","mid":62,"url":"https://t.me/appleservice39/62","asset":"iphone-16-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-16-family.png","bytes":1625801,"sha256":"acf464365b6417ba7be0061192b9668128ea01fb18a6f66fd9b269e69e6b50a0"},{"post":"model-iphone-16-plus","mid":63,"url":"https://t.me/appleservice39/63","asset":"iphone-16-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-16-family.png","bytes":1625801,"sha256":"acf464365b6417ba7be0061192b9668128ea01fb18a6f66fd9b269e69e6b50a0"},{"post":"model-iphone-16-pro","mid":64,"url":"https://t.me/appleservice39/64","asset":"iphone-16-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-16-family.png","bytes":1625801,"sha256":"acf464365b6417ba7be0061192b9668128ea01fb18a6f66fd9b269e69e6b50a0"},{"post":"model-iphone-16-pro-max","mid":65,"url":"https://t.me/appleservice39/65","asset":"iphone-16-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-16-family.png","bytes":1625801,"sha256":"acf464365b6417ba7be0061192b9668128ea01fb18a6f66fd9b269e69e6b50a0"},{"post":"model-iphone-17-pro","mid":66,"url":"https://t.me/appleservice39/66","asset":"iphone-17-pro-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-17-pro-family.png","bytes":1606781,"sha256":"8161cacacad3c70be56fa3b1969efef505cdb50eb55fa1912cb0d8790e01e79d"},{"post":"model-iphone-17-pro-max","mid":67,"url":"https://t.me/appleservice39/67","asset":"iphone-17-pro-family","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-17-pro-family.png","bytes":1606781,"sha256":"8161cacacad3c70be56fa3b1969efef505cdb50eb55fa1912cb0d8790e01e79d"},{"post":"model-iphone-air","mid":68,"url":"https://t.me/appleservice39/68","asset":"iphone-air","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/iphone-air.png","bytes":1587386,"sha256":"16def74b44466d2e4b36cc737c818a5987ca3b463f3f0feafb5fe52aa5366623"},{"post":"service-charging-port-replacement","mid":70,"url":"https://t.me/appleservice39/70","asset":"charging-port","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/charging-port.png","bytes":1765000,"sha256":"d329e15337c27b9e842faa1547f1b3a59b8acd5db8cc2ca5c18e23ec248e87b5"},{"post":"service-microphone-replacement","mid":71,"url":"https://t.me/appleservice39/71","asset":"microphone","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/microphone.png","bytes":1802417,"sha256":"e9e9ef51801171c17e6c5068704bcde66f6b8ac16f5dfeb98339167a2849145e"},{"post":"service-loudspeaker-replacement","mid":72,"url":"https://t.me/appleservice39/72","asset":"loudspeaker","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/loudspeaker.png","bytes":1651813,"sha256":"976314e671d862ffd7d74ded804b6e2a7f99954202b5e60f5ca31a41694c273a"},{"post":"service-front-camera-replacement","mid":73,"url":"https://t.me/appleservice39/73","asset":"front-camera","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/front-camera.png","bytes":1611601,"sha256":"035929e75f760a32b8d526720739bd14080bb7953787ad21f369bb8cb1068c5e"},{"post":"service-home-button-repair","mid":74,"url":"https://t.me/appleservice39/74","asset":"home-button","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/home-button.png","bytes":1596726,"sha256":"a60b5930bfda58b30e27f0c9c47d9e5c32a44951e019499344bed4ab2cead3c4"},{"post":"service-power-button-repair","mid":75,"url":"https://t.me/appleservice39/75","asset":"power-button","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/power-button.png","bytes":1652411,"sha256":"1814370a5c5084c5df8ac2dc1b277590393904c98bee6f969501592a0dfca087"},{"post":"service-antenna-repair","mid":76,"url":"https://t.me/appleservice39/76","asset":"antenna","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/antenna.png","bytes":1571242,"sha256":"0454c7cf6847ec5849130a8ac63397891a308835f5bad87b0251a997ee2137b0"},{"post":"service-software-recovery","mid":77,"url":"https://t.me/appleservice39/77","asset":"software","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/software.png","bytes":1576280,"sha256":"e617386b8a511b3fc80f6cada70b622ae0f235fdda22c11657008f86ee269c5e"},{"post":"service-diagnostics","mid":78,"url":"https://t.me/appleservice39/78","asset":"diagnostics","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/diagnostics.png","bytes":1700995,"sha256":"8e01c5c6fa9b0bcaf842d8e2c3856b262eae701f8cbecafeb1e9dec9e3c1f2be"},{"post":"service-housing-repair","mid":79,"url":"https://t.me/appleservice39/79","asset":"housing","image":"https://tour-max.ru/new/appleservice39/covers/ad-intent-v1/housing.png","bytes":1609362,"sha256":"10a00c5dafa862fb17829bab1c2f3d14fe81fafadcc37330a003344786f0407b"}]', true, 16, JSON_THROW_ON_ERROR);
    check(count($map) === 37 && count(array_unique(array_column($map,'mid'))) === 37, 'reviewed_map_invalid');
    $db = ConversationDb::connection();
    $guard = $db->prepare("SELECT * FROM appleservice39_pilot_campaigns WHERE campaign_key=?");
    $guard->execute([EDIT_CAMPAIGN]);
    check($guard->fetch(PDO::FETCH_ASSOC) === false, 'media_edits_already_started');
    $guard->execute([CREATE_CAMPAIGN]);
    $original = $guard->fetch(PDO::FETCH_ASSOC);
    check($original && $original['project_key'] === PROJECT && $original['bot_key'] === BOT, 'original_catalogue_required');
    $connection = AppleService39TelegramConnection::current();
    $connection->requireReadyForPublication((int)$original['admin_manager_id']);
    $binding = AppleService39TelegramConnection::runtimeBinding();
    $token = $binding['bot_token'] ?? '';
    $botId = (string)($binding['bot_id'] ?? '');
    $channel = $original['channel_id'];
    check(($binding['project_key'] ?? '') === PROJECT && ($binding['source_key'] ?? '') === 'telegram:appleservice39-main'
        && strcasecmp((string)($binding['bot_username'] ?? ''),BOT) === 0 && preg_match('/\A[1-9][0-9]*\z/D',$botId)
        && preg_match('/\A[0-9]+:[a-zA-Z0-9_-]+\z/D',$token) && preg_match('/\A-100[0-9]+\z/D',$channel)
        && (int)$binding['source_id'] === (int)$original['source_id']
        && $original['connection_sha256'] === hash('sha256',$botId.'|'.$channel.'|'.$binding['source_id']), 'telegram_connection_changed');

    $me = telegramRead($token,'getMe');
    $chat = telegramRead($token,'getChat',['chat_id'=>'@appleservice39']);
    $member = telegramRead($token,'getChatMember',['chat_id'=>$channel,'user_id'=>$botId]);
    check((string)$me['id'] === $botId && strcasecmp((string)$me['username'],BOT) === 0 && (string)$chat['id'] === $channel
        && $chat['type'] === 'channel' && strcasecmp((string)$chat['username'],PROJECT) === 0
        && (string)$member['user']['id'] === $botId && ($member['status'] === 'creator'
            || ($member['status'] === 'administrator' && (!empty($member['can_edit_messages']) || !empty($member['can_post_messages'])))), 'telegram_edit_access_required');

    $public = publicPosts();
    $rows = $db->prepare("SELECT request_json,state,external_message_id,public_url FROM appleservice39_pilot_operations
        WHERE campaign_key=? AND post_key=? AND operation='create'");
    $edits = [];
    foreach ($map as $target) {
        $id = $target['post'];
        $mid = $target['mid'];
        $rows->execute([CREATE_CAMPAIGN,$id]);
        $row = $rows->fetch(PDO::FETCH_ASSOC);
        $spec = $row ? json_decode($row['request_json'],true,16,JSON_THROW_ON_ERROR) : [];
        $visible = $public[$mid] ?? [];
        check($row && $row['state'] === 'verified' && (int)$row['external_message_id'] === $mid && $row['public_url'] === $target['url']
            && ($spec[0] ?? '') === $channel && ($spec[1] ?? '') === OLD_COVER
            && isset($spec[2],$spec[3]) && ($spec[3]['url'] ?? '') === 'https://t.me/'.BOT.'?start=as39_'.$id
            && ($visible['caption'] ?? null) === normalized($spec[2]) && ($visible['buttons'] ?? null) === [$spec[3]],
            'native_post_differs_'.$mid);
        $edits[] = $target + ['caption'=>$spec[2],'button'=>$spec[3],'previous_photo'=>$visible['photo']];

        if (!isset($assetFiles[$target['asset']])) {
            check(preg_match('~\Ahttps://tour-max\.ru/new/appleservice39/covers/ad-intent-v1/[a-z0-9-]+\.png\z~D',$target['image']) === 1, 'approved_image_url_invalid');
            [$status,$bytes] = request($target['image']);
            check($status === 200 && strlen($bytes) === $target['bytes'] && hash('sha256',$bytes) === $target['sha256']
                && str_starts_with($bytes,"\x89PNG\r\n\x1a\n"), 'approved_image_bytes_changed');
            $file = tempnam(sys_get_temp_dir(),'as39-cover-');
            check($file !== false && file_put_contents($file,$bytes) === strlen($bytes),'approved_image_temp_failed');
            $assetFiles[$target['asset']] = $file;
        }
    }
    echo jsonValue(['stage'=>'preflight','native_posts'=>count($edits),'original_images'=>count($assetFiles)]).PHP_EOL;

    $manifest = hash('sha256',jsonValue($map));
    $now = gmdate('Y-m-d H:i:s');
    $db->prepare("INSERT INTO appleservice39_pilot_campaigns
        (campaign_key,project_key,bot_key,channel_id,source_id,admin_manager_id,manifest_sha256,connection_sha256,created_at)
        VALUES (?,?,?,?,?,?,?,?,?)")->execute([EDIT_CAMPAIGN,PROJECT,BOT,$channel,$original['source_id'],$original['admin_manager_id'],
            $manifest,$original['connection_sha256'],$now]);
    $claim = $db->prepare("INSERT INTO appleservice39_pilot_operations
        (campaign_key,post_key,operation,payload_sha256,request_json,state,write_attempts,outcome_json,created_at,updated_at)
        VALUES (?,?,?,?,?,'write_started',1,'{}',?,?)");
    $confirmed = [];
    foreach ($edits as $n=>$edit) {
        if ($n > 0 && $n % 16 === 0) sleep(63);
        usleep(1_150_000);
        $mid = $edit['mid'];
        $spec = jsonValue([$edit['mid'],$edit['sha256'],$edit['caption'],$edit['button']]);
        $now = gmdate('Y-m-d H:i:s');
        $claim->execute([EDIT_CAMPAIGN,$edit['post'],OPERATION,hash('sha256',$spec),$spec,$now,$now]);
        $operationId = (int)$db->lastInsertId();
        [$http,$reply] = telegram($token,'editMessageMedia',[
            'chat_id'=>$channel,'message_id'=>$mid,
            'media'=>jsonValue(['type'=>'photo','media'=>'attach://replacement','caption'=>$edit['caption']]),
            'replacement'=>new CURLFile($assetFiles[$edit['asset']],'image/png','cover.png'),
            'reply_markup'=>jsonValue(['inline_keyboard'=>[[$edit['button']]]])
        ]);
        $message = $reply['result'] ?? [];
        $keyboard = $message['reply_markup']['inline_keyboard'] ?? [];
        $button = $keyboard[0][0] ?? [];
        $ok = $http === 200 && ($reply['ok'] ?? false) === true && (int)($message['message_id'] ?? 0) === $mid
            && (string)($message['chat']['id'] ?? '') === $channel && ($message['caption'] ?? null) === $edit['caption']
            && !empty($message['photo'][0]['file_id']) && count($keyboard) === 1 && count($keyboard[0]) === 1
            && ($button['url'] ?? null) === $edit['button']['url'] && ($button['text'] ?? null) === $edit['button']['text'];
        if (!$ok) {
            $rejected = ($reply['ok'] ?? null) === false && ($reply['error_code'] ?? null) === $http
                && in_array($http,[400,401,403,429],true);
            publishResult($db,$operationId,$rejected?'rejected':'unknown',['http'=>$http,'retry_after'=>$reply['parameters']['retry_after'] ?? null]);
            echo jsonValue(['stage'=>'edit_stopped','mid'=>$mid,'http'=>$http,'rejected'=>$rejected,'accepted_so_far'=>count($confirmed)]).PHP_EOL;
            throw new RuntimeException('telegram_edit_not_confirmed');
        }
        $photos = $message['photo'];
        $native = end($photos);
        publishResult($db,$operationId,'verified',['asset'=>$edit['asset'],'source_sha256'=>$edit['sha256'],
            'photo_unique_id'=>$native['file_unique_id'] ?? ''], $mid);
        $confirmed[$mid] = $edit;
        echo jsonValue(['stage'=>'photo_confirmed','post'=>$edit['post'],'url'=>$edit['url'],'asset'=>$edit['asset']]).PHP_EOL;
    }

    $observed = [];
    for ($n=0; $n<4; $n++) {
        if ($n > 0) sleep(10);
        $after = publicPosts();
        foreach ($confirmed as $mid=>$edit) {
            $p = $after[$mid] ?? [];
            if (($p['photo'] ?? $edit['previous_photo']) !== $edit['previous_photo']
                && ($p['caption'] ?? null) === normalized($edit['caption']) && ($p['buttons'] ?? null) === [$edit['button']]) {
                $observed[$mid] = true;
            }
        }
        if (count($observed) === count($confirmed)) break;
    }
    echo jsonValue(['ok'=>count($observed) === 37,'native_acknowledgements'=>count($confirmed),
        'public_photo_changes'=>count($observed),'public_missing'=>array_values(array_diff(array_keys($confirmed),array_keys($observed)))]).PHP_EOL;
    if (count($observed) !== 37) $failure = 'public_photo_readback_pending';
} catch (Throwable $error) {
    $reason = $error->getMessage();
    $failure = preg_match('/\A[a-z][a-z0-9_]{1,90}\z/D',$reason) ? $reason : 'media_operation_failed';
    echo jsonValue(['ok'=>false,'reason'=>$failure,'line'=>$error->getLine()]).PHP_EOL;
} finally {
    foreach ($assetFiles as $file) unlink($file);
}
exit($failure === null ? 0 : 1);
