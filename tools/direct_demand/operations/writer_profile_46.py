"""Seal verified output of build_telegram_46 into the existing Telegram transfer editor.

This is a pure source transformation for the owner's release. No files, services or
providers are contacted here. Existing transfer profiles retain their own inputs.
"""
from datetime import datetime
import json
import re

from build_telegram_46 import PACKET, digest, raw_json


WRITER = "tools/anytour_existing_post_batch.php"
REGISTRY = "services/ChannelPostRegistryService.php"
TRANSPORT = "integrations/ChannelPostApiTransport.php"
SCOPE = "config/anytour_ranked_transfer_scope_20261123.php"
GUARDED_MEMBERS = {
    WRITER: (
        ['transferRegistryRow', 'transferPlanFromBytes', 'transferPublicAllowed', 'transferReadBytes', 'transferPublic', 'parseTransferPublic', 'transferPublicPreimage', 'transferRuntime', 'transferPrice', 'transferSource', 'transferNoTarget', 'transferChannelRights', 'transferJournal', 'transferReason', 'transferExecuteRow', 'applyTransferPlan'],
        '8cf5f31df90cf6a3225b1c9fc6038d57259a1fb315b0f9e1143f325abf5eb226',
    ),
    REGISTRY: (
        ['reserveTelegramHotelTransfer', 'assertTelegramHotelTransferReservation', 'completeTelegramHotelTransfer', 'telegramHotelTransferRow', 'telegramHotelTransferChannel', 'telegramHotelTransferSource', 'telegramHotelTransferReservation', 'telegramHotelTransferIdentity', 'telegramHotelTransferResponse', 'telegramHotelTransferObservedMatch'],
        '93d46e5a353a276745266d9fffc17122d5b39a294d894605b873e9b99d33be9e',
    ),
    TRANSPORT: (
        ['hotelMediaPayload', 'hotelMediaRequest', 'postRequest'],
        '622f7238e6fe02b99e110a162d967a4d2538fd7413346375ec38f111fb35e094',
    ),
}
REGISTRY_KEYS = [
    "hotel_id", "source_message_id", "source_publication_id", "source_version_id", "source_content_hash",
    "source_caption_sha256", "source_file_id_sha256", "source_file_unique_id_sha256", "target_message_id",
    "target_published_unixtime", "target_caption_sha256", "target_photo_marker_sha256", "target_config_version",
    "next_caption", "next_caption_sha256", "button_text", "bot_url", "price_record_id",
]


def require(value, reason):
    if not value:
        raise ValueError(reason)


def replace(source, old, new, count=1):
    require(source.count(old) == count, "editor instruction changed: " + old[:65])
    return source.replace(old, new)


def fixed_profiles(plans):
    allowed = {h: (str(s), str(t), int(datetime.fromisoformat(dt).timestamp())) for h, s, t, dt in PACKET}
    profiles = {}
    seen = []
    native = None
    for number, (path, raw) in enumerate(plans, 1):
        require(path == f"config/anytour_ranked_46_20261123_{number:02d}.json" and len(raw) <= 64000,
                "unexpected plan path or size")
        plan = json.loads(raw)
        require(raw == raw_json(plan) and plan["schema"] == 1 and plan["rows"]
                and plan["operation"] == f"anytour_hotel_transfer_46_20261123_{number:02d}"
                and plan["execution"] == "explicit_owner_apply_only" and plan["monitor_source_url"] is None
                and plan["price_policy"] == "omit_previous_price_keep_hotel_calculation_button", "unexpected transfer plan")
        proof = plan["native_preflight"]
        if native is None:
            require(set(proof) == {"run_id","run_attempt","artifact_id","artifact_zip_sha256","registry_sha256",
                                   "checkpoint_sha256","repository_sha","application_sha"}
                    and isinstance(proof["run_id"], str) and re.fullmatch("[1-9][0-9]{0,19}", proof["run_id"])
                    and type(proof["artifact_id"]) is int and proof["artifact_id"] > 0 and proof["run_attempt"] == 1,
                    "native receipt identity")
            for name in ["artifact_zip_sha256","registry_sha256","checkpoint_sha256","repository_sha","application_sha"]:
                require(re.fullmatch("[0-9a-f]{%d}" % (40 if name.endswith("_sha") else 64), proof[name]),
                        "native receipt digest")
            native = proof
        require(native == proof, "mixed preflights")
        pairs = {}
        for row in plan["rows"]:
            h = row["hotel_id"]
            require(type(h) is int and h in allowed and h not in seen and allowed[h] == (
                row["source_message_id"], row["target_message_id"], row["target_published_unixtime"]),
                "row outside admitted queue")
            require(set(row) == set(REGISTRY_KEYS) | {"price_template", "source_photo_marker_sha256"}
                    and type(row["source_publication_id"]) is int and row["source_publication_id"] > 0
                    and type(row["source_version_id"]) is int and row["source_version_id"] > 0
                    and row["price_template"] == "" and row["price_record_id"] is None
                    and digest(row["next_caption"].encode()) == row["next_caption_sha256"], "invalid editor row")
            for field in ["source_content_hash","source_caption_sha256","source_file_id_sha256","source_file_unique_id_sha256",
                          "target_caption_sha256","target_photo_marker_sha256","next_caption_sha256","source_photo_marker_sha256"]:
                require(re.fullmatch("[0-9a-f]{64}",row[field]), "invalid row digest")
            ordered = {key: row[key] for key in REGISTRY_KEYS}
            pairs[h] = dict(source=row["source_message_id"], source_publication=row["source_publication_id"],
                            target=row["target_message_id"], date=row["target_published_unixtime"],
                            registry_sha256=digest(raw_json(ordered)), caption_sha256=row["next_caption_sha256"],
                            file_id_sha256=row["source_file_id_sha256"])
            seen.append(h)
        profiles[digest(raw)] = dict(path=path, operation=plan["operation"], pairs=pairs)
    require(seen and seen == [h for h, *_ in PACKET if h in seen], "noncanonical ranked order")
    return {"proof": native, "plans": profiles}


def scope_source(data):
    # All keys/values embedded in executable PHP are a closed alphabet of identifiers,
    # positive integers, paths and digests. Caption bytes remain in the JSON plans.
    def literal(item, level=0):
        if type(item) is int:
            return str(item)
        if isinstance(item, str):
            require(re.fullmatch("[A-Za-z0-9_./:-]+", item), "unsafe scope literal")
            return "'" + item + "'"
        require(isinstance(item, dict), "invalid scope type")
        lines = ["    " * (level+1) + literal(key) + " => " + literal(val, level+1) + "," for key,val in item.items()]
        return "[\n" + "\n".join(lines) + "\n" + "    " * level + "]"
    return ("<?php\ndeclare(strict_types=1);\n\nfinal class AnyTourRankedHotelTransfer46\n{\n"
            + "    public const PROOF = " + literal(data["proof"],1) + ";\n"
            + "    public const PLANS = " + literal(data["plans"],1) + ";\n}\n")


def writer_source(source):
    source = replace(source, "use ChannelPostApiTransport;", "use AnyTourRankedHotelTransfer46;\nuse ChannelPostApiTransport;")
    source = replace(source, "const POST_OPS =", "require_once __DIR__ . '/../"+SCOPE+"';\n\nconst POST_OPS =")
    source = replace(source, "bool $next = false", "bool|string $next = false", 7)
    for old, function, count in [
        ("$next ? NEXT_TRANSFER_PAIRS : TRANSFER_PAIRS", "transferProfilePairs($next)", 4),
        ("$next ? NEXT_TRANSFER_OPERATION : TRANSFER_OPERATION", "transferProfileOperation($next)", 3),
        ("$next ? NEXT_TRANSFER_PLAN_FILE : TRANSFER_PLAN_FILE", "transferProfileFile($next)", 2)
    ]:
        source = replace(source, old, function, count)
    source = replace(source, "function transferRegistryRow", """function transferRankedProfile(string $digest): array
{
    return AnyTourRankedHotelTransfer46::PLANS[$digest] ?? throw new RuntimeException('transfer_ranked_plan_unknown');
}

function transferProfilePairs(bool|string $next): array
{
    if (is_string($next)) return transferRankedProfile($next)['pairs'];
    return $next ? NEXT_TRANSFER_PAIRS : TRANSFER_PAIRS;
}

function transferProfileOperation(bool|string $next): string
{
    if (is_string($next)) return transferRankedProfile($next)['operation'];
    return $next ? NEXT_TRANSFER_OPERATION : TRANSFER_OPERATION;
}

function transferProfileFile(bool|string $next): string
{
    if (is_string($next)) return transferRankedProfile($next)['path'];
    return $next ? NEXT_TRANSFER_PLAN_FILE : TRANSFER_PLAN_FILE;
}

function transferRegistryRow""")
    source = replace(source, "function transferRegistryRow", """function transferRankedOfferScope(array $rows): void
{
    require_once dirname(__DIR__) . '/hotel-posts-telegram-main19.php';
    $feed = anytourTelegramFeedOfferUrls(anytourTelegramFeedRead());
    requirePost($feed !== [], 'transfer_ranked_feed_unavailable');
    $routes = anytourTelegramFeedDestinationPosts($feed);
    $used = array_fill_keys(array_merge(array_values($routes), [
        'https://t.me/anytour/1780', 'https://t.me/anytour/1760',
    ]), true);
    foreach ($rows as $row) {
        $offer = 'tg_' . $row['hotel_id'];
        $source = 'https://t.me/any_toour/' . $row['source_message_id'];
        $target = 'https://t.me/anytour/' . $row['target_message_id'];
        requirePost(($feed[$offer] ?? null) === $source && ($routes[$offer] ?? null) === $source,
            'transfer_ranked_source_offer_changed');
        requirePost(!isset($used[$target]), 'transfer_ranked_target_in_use');
    }
}

function transferRegistryRow""")
    source = replace(source, "        $pdo = ConversationDb::connection();\n        requirePost($pdo->getAttribute",
        "        if (is_string($next)) transferRankedOfferScope($plan['rows']);\n        $pdo = ConversationDb::connection();\n        requirePost($pdo->getAttribute")
    source = replace(source, "                    $registry->assertTelegramHotelTransferReservation(",
                             "                    if (is_string($next)) transferRankedOfferScope([$row]);\n                    $registry->assertTelegramHotelTransferReservation(")
    source = replace(source, "    if ($next) {\n        // The read ran",
        """    if (is_string($next)) {
        requirePost(hash_equals($next, $approvedDigest) && $proof === AnyTourRankedHotelTransfer46::PROOF,
            'transfer_ranked_native_proof_changed');
    } elseif ($next) {
        // The read ran""")
    source = replace(source, "    foreach (['reserve','prepare','assert','request','complete','runtime']",
        """    if (is_string($next)) requirePost(hash_equals($next, $planDigest), 'transfer_ranked_digest_changed');
    foreach (['reserve','prepare','assert','request','complete','runtime']""")
    source = replace(source, "    try {\n        $root = dirname(__DIR__);",
        """    try {
        if (in_array($command, ['--check-ranked-transfer-plan','--apply-ranked-transfer'], true)) {
            $selected = $argv[2] ?? '';
            transferRankedProfile($selected);
            $next = $selected;
        }
        $root = dirname(__DIR__);""")
    source = replace(source, "['--check-transfer-plan','--check-next-transfer-plan']",
                             "['--check-transfer-plan','--check-next-transfer-plan','--check-ranked-transfer-plan']")
    source = replace(source, "['--apply-transfer','--apply-next-transfer']",
                             "['--apply-transfer','--apply-next-transfer','--apply-ranked-transfer']")
    return source


def registry_source(source):
    source = replace(source, "        if ($next) {\n            $pairs = [800", """        require_once __DIR__ . '/../"""+SCOPE+"""';
        $ranked = AnyTourRankedHotelTransfer46::PLANS[$planDigest] ?? null;
        if ($ranked !== null) {
            $next = true;
            $pairs = [];
            foreach ($ranked['pairs'] as $id => $pair) {
                $pairs[$id] = [$pair['source'], $pair['source_publication'], $pair['target'], $pair['date']];
            }
        } elseif ($next) {
            $pairs = [800""")
    return replace(source, "        return array_replace(array_fill_keys($keys, null), $row);",
        """        $validated = array_replace(array_fill_keys($keys, null), $row);
        if ($ranked !== null) {
            $actual = hash('sha256', json_encode($validated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!hash_equals($ranked['pairs'][$row['hotel_id']]['registry_sha256'], $actual)) {
                throw new InvalidArgumentException('hotel_transfer_ranked_row_changed');
            }
        }
        return $validated;""")


def transport_source(source):
    source = replace(source, "array $body, bool $next = false", "array $body, bool|string $next = false", 2)
    source = replace(source, "        $approvedMessages = $next ? ['1588', '997', '1101'] : ['862', '328', '1083', '288'];",
        """        $rankedRow = null;
        if (is_string($next)) {
            require_once __DIR__ . '/../"""+SCOPE+"""';
            $rows = AnyTourRankedHotelTransfer46::PLANS[$next]['pairs'] ?? [];
            $byTarget = array_column($rows, null, 'target');
            $rankedRow = $byTarget[$messageId] ?? null;
            if ($rankedRow === null) return null;
            $approvedMessages = [$rankedRow['target']];
        } else {
            $approvedMessages = $next ? ['1588', '997', '1101'] : ['862', '328', '1083', '288'];
        }""")
    return replace(source, "        return ['chat_id' => $channelId, 'message_id' => (int)$messageId] + $body;",
        """        if ($rankedRow !== null && ($button['text'] !== '✈️ Рассчитать на мои даты'
            || !hash_equals($rankedRow['caption_sha256'], hash('sha256', $media['caption']))
            || !hash_equals($rankedRow['file_id_sha256'], hash('sha256', $media['media'])))) return null;
        return ['chat_id' => $channelId, 'message_id' => (int)$messageId] + $body;""")



def guarded_sha(name, source, members):
    bodies = []
    indent = "" if name == WRITER else "    "
    for member in members:
        modifiers = "" if not indent else r"(?:(?:public|protected|private|static|final) )+"
        start = re.search("^" + indent + modifiers + r"function " + re.escape(member) + r"\(", source, re.M)
        require(start is not None, "missing editor method: " + member)
        end = re.search("^" + indent + r"\}\s*$", source[start.start():], re.M)
        require(end is not None, "incomplete editor method: " + member)
        bodies.append(source[start.start():start.start()+end.end()].rstrip())
    if name == WRITER:
        data = re.search(r"^const TRANSFER_OPERATION .*?^const NEXT_TRANSFER_PAIRS = \[.*?^\];", source, re.M | re.S)
        cli = re.search(r"^if \(PHP_SAPI === 'cli' && realpath\(\$_SERVER.*\Z", source, re.M | re.S)
        require(data is not None and cli is not None, "existing command scope missing")
        bodies.extend([data.group(), cli.group().rstrip()])
    return digest("\n".join(bodies).encode())


def bind_sources(base: dict[str,str], plans: list[tuple[str,bytes]]) -> dict[str,str]:
    """Produce only reviewed source replacements and their fixed profile. Plans are returned by the caller."""
    for name, (members, expected) in GUARDED_MEMBERS.items():
        require(guarded_sha(name, base[name], members) == expected, "reviewed transfer contract changed: " + name)
    profiles = fixed_profiles(plans)
    return {WRITER: writer_source(base[WRITER]), REGISTRY: registry_source(base[REGISTRY]),
            TRANSPORT: transport_source(base[TRANSPORT]), SCOPE: scope_source(profiles)}
