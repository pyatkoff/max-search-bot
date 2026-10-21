<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!defined('OWNER_PHOTO_CHOICE_OFFLINE_TEST')) define('OWNER_PHOTO_CHOICE_OFFLINE_TEST', true);
require_once __DIR__.'/owner_tour_photo_choice_trial.php';

/** One observed listing, not a booking, final fare or a general manager composer. */
function ownerLiveQuoteSource(): array
{
    return [
        'observed_at'=>'2026-10-21T19:54:00Z',
        'create_before'=>'2026-10-21T21:54:00Z',
        'url'=>'https://b2b.fstravel.com/search_tour?ADULT=2&CHECKIN_BEG=20270605&CHECKIN_END=20270605&CHILD=0&CURRENCY=1&DOLOAD=1&FILTER=1&FREIGHT=1&FREIGHTTYPE=1&HOTELS_ANY=1&LANG=rus&MEALS_ANY=1&NIGHTS_FROM=7&NIGHTS_TILL=7&PARTITION_PRICE=160&PRICEPAGE=1&PROGRAMINC=114&ROOMS_ANY=1&STARS_ANY=1&STATEINC=18803&TOWNFROMINC=274286&TOWNS_ANY=1',
        'row'=>['departure'=>'05.06.2027','nights'=>7,'hotel'=>'Grand Park Lara 5*','resort'=>'Лара',
            'meal'=>'Ultra All Inclusive','room'=>'Standard / 2AD','amount'=>'242154','currency'=>'RUB',
            'tour'=>'Turkey Antalya MOW','program'=>'EB MOW 1111183930AYT'],
    ];
}

function ownerLiveQuoteSpec(): array
{
    return ['id'=>'owner-live-quote-20261021-grand-park',
        'snapshot'=>['hotel'=>'Grand Park Lara 5★','departure'=>'Москва','date_from'=>'2027-06-05','date_to'=>'2027-06-12',
            'nights'=>7,'adults'=>2,'child_ages'=>[],'room'=>'Стандартный номер','meal'=>'Ультра всё включено',
            'flights'=>[],'amount'=>'242154','currency'=>'RUB','price_basis'=>'party','is_demo'=>false,'price_verified'=>false,
            'rationale'=>'','compromise'=>'Цена из выдачи FUN&SUN на 21.10.2026, 19:54 UTC. 12 июня — расчётная дата; рейсы, наличие и доплаты уточняются.'],
        'presentation'=>['destination'=>'Турция, Лара','inclusions'=>[],'room_illustrative'=>true,'photos'=>[
            ['role'=>'cover','url'=>'https://www.corendonhotels.com.tr/storage/slides/March2026/ZuI2cGTDetugSWKhnE93.jpg'],
            ['role'=>'beach','url'=>'https://www.corendonhotels.com.tr/storage/pages/May2026/iX5LgEU46J2oOMC8VFjS.jpg'],
            ['role'=>'room','url'=>'https://www.corendonhotels.com.tr/storage/rooms/March2026/hJ0wq9HCId31KoXNb6MI.jpg'],
        ]]];
}

function ownerLiveQuoteCanCreate(int $now): bool
{
    $source=ownerLiveQuoteSource();
    return $now >= strtotime($source['observed_at']) && $now < strtotime($source['create_before']);
}

function ownerLiveQuoteArguments(array $args): ?string
{
    if (!in_array('--confirm-owner-live-quote', $args, true)) return null;
    return ownerPreviewArguments(array_map(static fn($arg)=>$arg === '--confirm-owner-live-quote' ? '--confirm-owner-send' : $arg, $args));
}

if (!defined('OWNER_LIVE_QUOTE_OFFLINE_TEST')) {
    ini_set('display_errors','0');
    $result=['ok'=>false,'state'=>'invalid_arguments','send_attempted'=>false,'provider_requests'=>0];
    $expected=ownerLiveQuoteArguments(array_slice($argv,1));
    if ($expected !== null) {
        $level=ob_get_level(); ob_start();
        try {
            $root=dirname(__DIR__);
            $installed=trim((string)shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
            if ($installed !== $expected) $result['state']='installed_sha_mismatch';
            else {
                require_once $root.'/config.php'; require_once $root.'/services/ConversationDb.php';
                require_once $root.'/services/ProjectConfig.php'; require_once $root.'/services/TourChoiceService.php';
                $db=ConversationDb::connection();
                $result=ownerPhotoChoiceRun($expected,$installed,ProjectConfig::projectId(),
                    ['telegram'=>defined('TELEGRAM_BOT_TOKEN') ? (string)TELEGRAM_BOT_TOKEN : '', 'max'=>defined('MAX_SEARCH_TOKEN') ? (string)MAX_SEARCH_TOKEN : ''],
                    ['telegram'=>static fn()=>telegramOwnerPreviewTarget($db),'max'=>static fn()=>ownerPreviewTarget($db)],
                    ['telegram'=>'telegramOwnerPreviewRequest','max'=>'ownerPreviewRequest'],
                    '/var/www/anytoour/data/owner-tour-live-quote-trial',
                    static fn(int $conversationId,array $snapshot)=>TourChoiceService::prepare($db,$conversationId,$snapshot),
                    static fn(int $offerId,string $mid,string $chat)=>TourChoiceService::recordDelivered($db,$offerId,$mid,$chat),
                    ['can_create'=>static fn()=>ownerLiveQuoteCanCreate(time())],ownerLiveQuoteSpec());
                $source=ownerLiveQuoteSource();
                $result['quote_observed_at']=$source['observed_at'];
                $result['source_sha256']=ownerSelectionHash($source);
            }
        } catch (Throwable $ignored) { $result['state']='configuration_unavailable'; }
        finally { while (ob_get_level() > $level) ob_end_clean(); }
    }
    echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
    exit($result['ok'] ? 0 : 1);
}
