<?php
declare(strict_types=1);
define('OWNER_LIVE_QUOTE_OFFLINE_TEST',true);
require_once dirname(__DIR__,2).'/tools/owner_tour_live_quote_trial.php';
$checks=0;
$assert=static function(bool $condition,string $message) use (&$checks): void {
    $checks++; if (!$condition) throw new RuntimeException($message);
};
$source=ownerLiveQuoteSource(); $spec=ownerLiveQuoteSpec();
$normalized=ownerPhotoChoiceSpec($spec);
$assert($normalized !== null && $normalized['snapshot']['amount']===$source['row']['amount']
    && $normalized['snapshot']['currency']===$source['row']['currency'],'The archived fixed listing and saved party price agree');
$assert($normalized['snapshot']['nights']===$source['row']['nights'] && $normalized['snapshot']['adults']===2
    && $source['row']['room']==='Standard / 2AD','Fixed listing occupancy and length remain the actual observed conditions');
$assert(!$normalized['snapshot']['is_demo'] && !$normalized['snapshot']['price_verified']
    && $normalized['snapshot']['flights']===[] && $normalized['presentation']['inclusions']===[],
    'A real listing cannot claim a verified fare, selected flight or unspecified inclusions');
$card=ownerPhotoChoiceCard('tour_like_17_'.str_repeat('a',24),$spec);
$assert(str_contains($card['plain_text'],'242 154 ₽ за двоих') && str_contains($card['plain_text'],'Актуальность цены не проверена.')
    && str_contains($card['plain_text'],'12 июня — расчётная дата; рейсы, наличие и доплаты уточняются.')
    && str_contains($card['plain_text'],'Фото номера — пример интерьера отеля.'),
    'Native card discloses preliminary conditions and illustrative room rather than a final booking');
$assert(!str_contains($card['plain_text'],'Демо:') && !str_contains($card['plain_text'],'страховка включены'),
    'Observed listing is distinguished from historical demos without inventing package inclusions');
$observed=strtotime($source['observed_at']); $deadline=strtotime($source['create_before']);
$assert(!ownerLiveQuoteCanCreate($observed-1) && ownerLiveQuoteCanCreate($observed)
    && ownerLiveQuoteCanCreate($deadline-1) && !ownerLiveQuoteCanCreate($deadline),
    'Creation is allowed only in the explicit observation window, including its exact boundaries');
$sha=str_repeat('a',40);
$assert(ownerLiveQuoteArguments(['--expected-sha='.$sha,'--confirm-owner-live-quote'])===$sha,
    'Exact fixed owner live-quote invocation is accepted');
foreach ([[],['--expected-sha='.$sha,'--confirm-owner-photo-choice'],
    ['--expected-sha='.$sha,'--confirm-owner-live-quote','--recipient=1'],
    ['--expected-sha='.$sha,'--confirm-owner-live-quote','--url=https://example.com'],
    ['--expected-sha='.$sha,'--confirm-owner-live-quote','--confirm-owner-live-quote']] as $args) {
    $assert(ownerLiveQuoteArguments($args)===null,'Recipient, source substitution, old mode or ambiguous arguments cannot authorize this send');
}
$bad=$spec; $bad['snapshot']['hotel']='Delphin Imperial 5★';
try { ownerPhotoChoiceSpec($bad); $accepted=true; } catch (InvalidArgumentException $ignored) { $accepted=false; }
$assert(!$accepted,'Actual hotel snapshot cannot be replaced while retaining Grand Park photos');
$bad=$spec; $bad['presentation']['photos'][0]['url'].='?changed=1';
try { ownerPhotoChoiceSpec($bad); $accepted=true; } catch (InvalidArgumentException $ignored) { $accepted=false; }
$assert(!$accepted,'Fixed official asset source cannot be replaced by a request argument');
echo "OWNER LIVE QUOTE: $checks checks PASSED\n";
