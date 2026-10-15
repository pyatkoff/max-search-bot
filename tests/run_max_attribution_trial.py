#!/usr/bin/env python3
"""Source safety contract; optionally run executable PHP fixture checks when PHP is present."""
from pathlib import Path
import hashlib
import json
import re
import shutil
import subprocess

helper = Path(__file__).resolve().parents[1] / "tools/max_attribution_trial.php"
source = helper.read_text()

assert "c78428948ff290ce5cb5ff1af3bdcb848519c364" in source
assert "SET TRANSACTION READ ONLY" in source and "$pdo->beginTransaction()" in source
assert re.search(r"finally\s*\{\s*if \(\$pdo->inTransaction\(\)\) \$pdo->rollBack\(\);", source)
assert "LIMIT 51" in source and "LIMIT 101" in source and "LIMIT 1'" in source
assert "array_slice($posts, 0, self::POST_LIMIT)" in source
assert "array_slice($fallback, 0, self::FALLBACK_LIMIT)" in source
assert "count($posts) < self::POST_LIMIT" in source
assert "INTERVAL 21 DAY" in source
assert "c.project_key='anytour' AND c.channel='max'" in source
assert "cc.project_key='anytour' AND cc.channel='max'" in source
assert "p.project_key='anytour' AND pc.platform='max'" in source
assert "s.handling_mode='manager'" in source
assert "cc.id=c.customer_channel_id AND cc.project_key=c.project_key AND cc.channel=c.channel" in source
assert "pv.id=e.version_id AND pv.publication_id=pp.id" in source
assert "JSON_VALID(m.metadata_json)" in source
assert "'$.type')) IN ('message','contact')" in source
assert "m.direction='inbound' AND m.sender_type='customer'" in source
assert "NOT LIKE '/start%'" in source
assert "m.direction='outbound' AND m.sender_type='manager'" in source
assert "m.created_at>? OR (m.created_at=? AND m.id>?)" in source
assert "SELECT m.id,m.created_at FROM messages" in source
# Message text is permitted only inside a suppression predicate, never as a projection/result.
assert source.count("m.text") == 1
assert not re.search(r"SELECT[^;\n]*m\.text", source)
projection = source.split("public static function projectRow", 1)[1].split("public static function run", 1)[0]
for forbidden in ["'external_user_id'=>", "'external_chat_id'=>", "'conversation_id'=>", "'entry_id'=>", "'text'=>", "'metadata_json'=>"]:
    assert forbidden not in projection, forbidden
assert "'internal_id_matches_expected'" in projection
assert "hash('sha256', self::HASH_DOMAIN . $externalUserId)" in source
assert "max-attribution-trial-20261015-v1|" in source
for forbidden in ["queueMetrikaGoal", "cron_metrika", "curl_", "MaxTransport", "ChannelPostRegistryService", "INSERT INTO", "UPDATE ", "DELETE FROM", "file_put_contents", "fopen(", "unlink("]:
    assert forbidden not in source, forbidden
assert "while (ob_get_level() > $bufferLevel) ob_end_clean();" in source
assert "'diagnostic_unavailable'" in source and "->getMessage()" not in source
assert "diff --quiet" in source and "diff --cached --quiet" in source
assert "database_clock_not_utc" in source

php = shutil.which("php")
if php:
    subprocess.run([php, "-l", str(helper)], check=True)
    fixture = r'''
require $argv[1];
$r=['entry_id'=>'17','conversation_id'=>'123456789123456789','external_user_id'=>'234567891234567891',
    'external_chat_id'=>'-234567891234567891','anchor_at'=>'2026-10-15 10:00:00',
    'conversation_started_at'=>'2026-10-14 09:00:00','channel_id'=>'-77309177991187'];
$stage=['first_customer_message_at'=>'2026-10-15 10:01:00','first_manager_reply_at'=>null,
    'customer_reply_after_manager_at'=>null,'text'=>'NEVER_OUTPUT','external_user_id'=>'NEVER_OUTPUT'];
$a=MaxAttributionTrial::projectRow($r,'channel_post',$stage);
$r['external_chat_id']='-999';
$b=MaxAttributionTrial::projectRow($r,'manager_source_fallback',$stage);
$invalid=false;try{MaxAttributionTrial::userKey('000123');}catch(Throwable $e){$invalid=true;}
echo json_encode(['post'=>$a,'fallback'=>$b,'invalid_rejected'=>$invalid,
    'sql'=>MaxAttributionTrial::fallbackSql(2)]);
'''
    result = subprocess.run([php, "-r", fixture, str(helper)], check=True, capture_output=True, text=True)
    data = json.loads(result.stdout)
    encoded = result.stdout
    for raw in ["234567891234567891", "123456789123456789", "NEVER_OUTPUT"]:
        assert raw not in encoded
    assert data["post"]["user_key"] == hashlib.sha256(b"max-attribution-trial-20261015-v1|234567891234567891").hexdigest()
    assert data["post"]["internal_id_matches_expected"] is True
    assert data["fallback"]["internal_id_matches_expected"] is False
    assert data["fallback"]["post_entry_at"] is None and "channel_id" not in data["fallback"]
    assert data["post"]["conversation_key"] == data["fallback"]["conversation_key"]
    assert data["post"]["record_key"] != data["fallback"]["record_key"]
    assert data["invalid_rejected"] is True
    assert "c.id NOT IN (?,?)" in data["sql"]
    print("PASS source safety, PHP syntax and executable pseudonym/projection fixture")
else:
    print("PASS source safety; PHP unavailable: syntax/runtime fixtures require runner PHP")
