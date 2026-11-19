<?php
declare(strict_types=1);

if (!defined('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY')) define('APPLESERVICE39_PUBLISHER_FIXTURES_ONLY', true);
require_once __DIR__.'/run_appleservice39_publisher_regression.php';
require_once dirname(__DIR__).'/services/AppleService39TelegramDevicePublisher.php';

final class AppleDeviceTelegramFixture
{
    public const CAMPAIGN = 'appleservice39-telegram-device-v1';
    private const USERNAME = 'Appleservice39_bot';
    private const CHANNEL_ID = '-1001234567890';
    private const BOT_ID = '900000001';
    private const TOKEN = '900000001:synthetic-fixture-only';
    public PDO $db;
    public array $calls = [];
    public array $sends = [];
    public string $fault = '';
    public bool $canPost = true;
    public bool $wrongBot = false;
    public ?Closure $afterClaim = null;
    private int $messageId = 100;
    private array $binding;

    public function __construct()
    {
        $this->db = as39Sqlite();
        $this->db->exec("INSERT INTO conversation_sources VALUES
            (11,1,'telegram:appleservice39-main','telegram',1,'manager'),
            (21,2,'telegram:appleservice39-main','telegram',1,'manager')");
        $this->db->exec("INSERT INTO managers VALUES (4,'admin',1),(5,'manager',1)");
        $this->db->exec('INSERT INTO manager_projects VALUES (4,2),(5,1)');
        $this->binding = ['project_key'=>'appleservice39','source_key'=>'telegram:appleservice39-main',
            'source_id'=>11,'bot_id'=>self::BOT_ID,'bot_username'=>self::USERNAME,'bot_token'=>self::TOKEN,
            'webhook_secret'=>'synthetic-webhook-secret'];
    }

    public function publisher(array $overrides = []): AppleService39TelegramDevicePublisher
    {
        return new AppleService39TelegramDevicePublisher($this->db,
            fn(): array => array_replace($this->binding, $overrides), [$this,'telegram']);
    }

    public function telegram(string $token, string $method, array $params): array
    {
        as39ProviderAssert($token === self::TOKEN, 'only isolated Telegram fixture transport');
        $this->calls[] = $method;
        $me = ['id'=>(int)self::BOT_ID,'username'=>self::USERNAME,'is_bot'=>true];
        $channel = ['id'=>(int)self::CHANNEL_ID,'type'=>'channel','username'=>'appleservice39'];
        switch ($method) {
            case 'getMe':
                if ($this->wrongBot) $me['id']++;
                $result = $me;
                break;
            case 'getChat':
                as39ProviderAssert($params === ['chat_id'=>'@appleservice39'], 'resolve exact public channel handle');
                $result = $channel;
                break;
            case 'getChatMember':
                as39ProviderAssert((string)$params['chat_id'] === self::CHANNEL_ID && (string)$params['user_id'] === self::BOT_ID,
                    'verify membership of the bound Telegram bot in the resolved channel');
                $result = ['user'=>$me,'status'=>'administrator','can_post_messages'=>$this->canPost];
                break;
            case 'sendPhoto':
                $key = basename((string)$params['photo'], '.png');
                $mid = ++$this->messageId;
                $this->sends[$key][] = ['request'=>$params,'message_id'=>$mid];
                if ($this->afterClaim !== null) {
                    $hook = $this->afterClaim;
                    $this->afterClaim = null;
                    $hook();
                }
                $result = ['message_id'=>$mid,'date'=>1680000000,'chat'=>$channel,'caption'=>$params['caption'],
                    'photo'=>[['file_id'=>'fixture-photo-'.$mid,'file_unique_id'=>'fixture-unique-'.$mid,'width'=>512,'height'=>512]],
                    'reply_markup'=>$params['reply_markup']];
                switch ($this->fault) {
                    case 'timeout': throw new RuntimeException('fixture: Telegram reply lost after send');
                    case 'missing_photo': $result['photo'] = []; break;
                    case 'wrong_button': $result['reply_markup']['inline_keyboard'][0][0]['url'] = 'https://example.invalid/fixture'; break;
                    case 'foreign_channel': $result['chat']['id'] = -1001111111111; break;
                    case 'forbidden': return ['http'=>403,'ok'=>false,'error_code'=>403];
                }
                break;
            default: throw new RuntimeException('FAIL: unplanned Telegram method '.$method);
        }
        return ['http'=>200,'ok'=>true,'result'=>$result];
    }

    public function operations(): array
    {
        $query = $this->db->prepare("SELECT post_key,state,write_attempts,public_url FROM appleservice39_pilot_operations
            WHERE campaign_key=? AND operation='create' ORDER BY post_key");
        $query->execute([self::CAMPAIGN]);
        return array_column($query->fetchAll(PDO::FETCH_ASSOC), null, 'post_key');
    }
}

function as39RunTelegramDevicePublisherRegression(): void
{
    $fixture = new AppleDeviceTelegramFixture();
    $publisher = $fixture->publisher();
    $keys = AppleService39DeviceRevision::POSTS;
    $first = $keys[0];
    as39Expect($publisher->status() === array_fill_keys($keys, ['state'=>'pending','url'=>null])
        && $fixture->calls === [] && $fixture->operations() === [], 'Telegram status is independently readable without writing or sending');

    as39Refuses(fn()=>$publisher->publish(4), 'apple_project_admin_required');
    as39Refuses(fn()=>$publisher->publish(5), 'apple_project_admin_required');
    as39Refuses(fn()=>$fixture->publisher(['source_id'=>21])->publish(2), 'apple_project_admin_required');
    as39Refuses(fn()=>$fixture->publisher(['source_id'=>10])->publish(2), 'apple_project_admin_required');
    as39Expect($fixture->calls === [], 'foreign admin, local nonadmin, foreign source and MAX source cannot access Telegram');
    $fixture->wrongBot = true;
    as39Refuses(fn()=>$publisher->publish(2), 'telegram_identity_mismatch');
    $fixture->wrongBot = false;
    $fixture->canPost = false;
    as39Refuses(fn()=>$publisher->publish(2), 'telegram_channel_post_access_required');
    as39Expect($fixture->sends === [] && $fixture->operations() === [], 'identity and channel rights precede any Telegram claim');

    $fixture->canPost = true;
    $fixture->calls = [];
    $published = $publisher->publish(2);
    $revision = new AppleService39DeviceRevision();
    $rows = $fixture->operations();
    $urls = [];
    as39Expect(array_keys($published) === $keys && array_keys($fixture->sends) === $keys
        && $fixture->calls === ['getMe','getChat','getChatMember','sendPhoto','sendPhoto','sendPhoto','sendPhoto']
        && count($rows) === count($keys), 'one authorization check and exactly four Telegram photos in the approved order');
    foreach ($keys as $key) {
        $post = $revision->post($key);
        $sent = $fixture->sends[$key][0];
        $request = $sent['request'];
        $button = ['text'=>$post['button_label'],'url'=>'https://t.me/Appleservice39_bot?start=as39_'.$key];
        $url = 'https://t.me/appleservice39/'.$sent['message_id'];
        $urls[] = $url;
        as39Expect(count($fixture->sends[$key]) === 1 && (string)$request['chat_id'] === '-1001234567890'
            && $request['photo'] === $revision->artwork($key) && hash_file('sha256',$request['photo']) === $post['cover_sha256']
            && $request['caption'] === implode("\n\n", array_merge([$post['title']], $post['paragraphs']))
            && $request['disable_notification'] === true && $request['reply_markup'] === ['inline_keyboard'=>[[$button]]]
            && $published[$key] === ['state'=>'published','url'=>$url]
            && $rows[$key]['state'] === 'verified' && (int)$rows[$key]['write_attempts'] === 1 && $rows[$key]['public_url'] === $url,
            $key.' has its reviewed artwork, exact text, one bot CTA and a channel-specific receipt');
    }
    $calls = $fixture->calls;
    as39Expect(count(array_unique($urls)) === 4 && $fixture->publisher()->publish(2) === $published
        && $fixture->calls === $calls && $fixture->operations() === $rows, 'completed batch is idempotent on a fresh publisher');

    foreach (['timeout','missing_photo','wrong_button','foreign_channel'] as $fault) {
        $case = new AppleDeviceTelegramFixture();
        $case->fault = $fault;
        $state = $case->publisher()->publish(2);
        as39Expect($state[$first] === ['state'=>'unknown','url'=>null] && (int)$case->operations()[$first]['write_attempts'] === 1,
            $fault.' keeps the ambiguous original receipt without a public link');
        $case->fault = '';
        $case->publisher()->publish(2);
        as39Expect(count($case->sends[$first]) === 1 && (int)$case->operations()[$first]['write_attempts'] === 1
            && $case->publisher()->status()[$first] === ['state'=>'unknown','url'=>null],
            $fault.' cannot resend the original photo or create a destination on a later run');
    }

    $denied = new AppleDeviceTelegramFixture();
    $denied->fault = 'forbidden';
    as39Expect($denied->publisher()->publish(2)[$first] === ['state'=>'rejected','url'=>null],
        'explicit Telegram rejection is distinct from an ambiguous write');
    $denied->canPost = false;
    as39Refuses(fn()=>$denied->publisher()->publish(2), 'telegram_channel_post_access_required');
    as39Expect(count($denied->sends[$first]) === 1, 'posting rights are checked again before a later attempt');

    $overlap = new AppleDeviceTelegramFixture();
    $overlap->afterClaim = fn()=>$overlap->publisher()->publish(2);
    $final = $overlap->publisher()->publish(2);
    foreach ($keys as $key) {
        as39Expect(count($overlap->sends[$key]) === 1 && $final[$key]['state'] === 'published',
            'overlapping Telegram publication claims send '.$key.' once');
    }
}

as39RunTelegramDevicePublisherRegression();
