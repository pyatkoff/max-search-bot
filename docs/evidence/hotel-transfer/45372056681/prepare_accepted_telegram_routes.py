"""Prepare the exact Telegram route source for the accepted subset of the 17 outstanding posts.

Inputs come from the original transfer and its independent public readback. Verify the
GitHub run, job and artifact digest before invoking this offline preparation.
The program never publishes a post, changes a route in production, or calls a bot.
"""

from pathlib import Path
import argparse, hashlib, json, re, subprocess, tempfile, time, zipfile

catalog_php = r"""declare(strict_types=1);
require $argv[1];
$xml = file_get_contents($argv[2]);
$source = anytourTelegramFeedOfferUrls($xml);
$routes = anytourTelegramFeedDestinationPosts($source);
$projected = anytourTelegramFeedProject($xml);
echo json_encode(['source' => $source, 'routes' => $routes, 'input_hash' => hash('sha256', $xml),
                  'projection_hash' => hash('sha256', $projected)], JSON_THROW_ON_ERROR);
"""
native_php = r"""declare(strict_types=1);
$verifier = $argv[7].'/tools/verify_ranked_telegram_public.php';
$tool = $argv[7].'/tools/anytour_existing_post_batch.php';
foreach ([$verifier => '293f14cb42a86890439374609eee0d3186320a45', $tool => 'b73168941342e88ed5ba6ca44f58571fceb1e9c5'] as $file => $blob) {
    $bytes = file_get_contents($file);
    if (sha1('blob '.strlen($bytes)."\0".$bytes) !== $blob) throw new RuntimeException('Validator changed');
}
require $verifier;
use function AnyTour\RankedPublicReadback\verifiedCandidates;
use function AnyTour\ExistingPostBatch\transferPlanFromBytes;
[$script, $folder, $planFile, $digest, $operation, $run, $application] = $argv;
$json = static fn(string $file) => json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
$plan = transferPlanFromBytes(file_get_contents($planFile), $digest, $digest);
$items = verifiedCandidates($plan, $json("$folder/check-$digest.json"), $json("$folder/result-$digest.json"),
    $json("$folder/summary.json")[$digest], ['plan_sha256' => $digest, 'write_operation' => $operation,
    'run_id' => $run, 'application_sha' => $application, 'parent_run_id' => '45372056681']);
echo json_encode(array_map(static fn ($pair) => [
    'hotel_id'=>$pair[1]['hotel_id'], 'message_id'=>$pair[1]['message_id'], 'url'=>$pair[1]['url'],
    'publication_id'=>$pair[1]['publication_id'], 'version_id'=>$pair[1]['version_id'],
    'source_url'=>'https://t.me/any_toour/'.$pair[0]['source_message_id']
], $items), JSON_THROW_ON_ERROR);
"""
plan_sha = "12d7accbf56f3a5a159ac08209168e34b34c3bcd61fdac15702b432eaa7864ce"

def need(test, message):
    if not test:
        raise ValueError(message)

def read(path):
    return json.loads(Path(path).read_bytes())

def php_json(code, *args):
    return json.loads(subprocess.check_output(["php", "-r", code, "--", *map(str,args)]))

def unique_by_id(rows):
    result = {r["hotel_id"]:r for r in rows}
    need(len(result) == len(rows), "duplicate hotel")
    return result

def main(args):
    start = time.monotonic()
    pub = read(args.public)
    native = unique_by_id(php_json(native_php, args.native, args.plan, plan_sha, args.operation, args.run, args.app, args.verifier_repo))
    need(pub.get("read_only") is True and pub.get("provider_write_attempts") == 0, "public read context")
    need(args.operation == "hotel_ranked_unreached" and args.run == pub.get("run_id") and args.app == pub.get("application_sha"), "native/public context")
    need(pub.get("write_operation") == args.operation and pub.get("parent_run_id") == "45372056681", "wrong continuation")
    need(hashlib.sha256(args.public.read_bytes()).hexdigest() == args.public_sha256, "public report bytes changed")
    archive=args.native/"original-native.zip"
    need(hashlib.sha256(archive.read_bytes()).hexdigest() == args.artifact_sha256,"native archive bytes changed")
    need(0 < archive.stat().st_size <= 2_000_000, "native archive size invalid")
    with zipfile.ZipFile(archive) as files:
        expected_names={"summary.json", f"check-{plan_sha}.json", f"result-{plan_sha}.json"}
        need(len(files.infolist())==3 and set(files.namelist())==expected_names,"unknown native archive")
        for name in expected_names:
            need(0 < files.getinfo(name).file_size <= 1_000_000, "native entry size invalid")
            need(files.read(name)==(args.native/name).read_bytes(), "extracted native bytes changed")
    parent=args.parent_zip
    need(hashlib.sha256(parent.read_bytes()).hexdigest()=="3f0e0462ddd4fd0ab0e186de8e2b17f4f92752cd5700525f54e9cf9f0dd3034c","parent changed")
    with zipfile.ZipFile(parent) as previous:
        allowed=json.loads(previous.read("summary.json"))[plan_sha]["unreached"]
    summary=read(args.native/"summary.json")[plan_sha]
    need(len(allowed)==len(set(allowed))==17 and summary["hotel_ids"]==allowed and set(native)<=set(allowed), "outside original 17")
    need(pub.get("repository_sha") == args.repository_sha and args.repository_sha is not None, "native repository SHA changed")
    need(pub.get("artifact_id") == args.artifact_id and args.artifact_id is not None, "native archive identity changed")
    need(pub.get("artifact_sha256") == args.artifact_sha256 and args.artifact_sha256 is not None, "native archive digest changed")
    block = pub["plans"][plan_sha]
    checks = ["native_photo_identity_verified","caption","public_new_photo_marker","original_date","hotel_button"]
    rows = unique_by_id(block["results"])
    need(set(rows) == set(native), "native/public coverage mismatch")
    accepted = {}
    holds = []
    for hid,p in rows.items():
        n = native[hid]
        need(all(p.get(k)==n[k] for k in ["hotel_id","message_id","url","publication_id","version_id"]), "public/native row mismatch")
        if p.get("applied") is not True:
            holds.append(hid)
            continue
        need(all(p.get(k) is True for k in checks), "public binding incomplete")
        need(re.fullmatch("[a-f0-9]{64}",p.get("html_sha256","")) is not None, "public HTML hash")
        accepted[hid] = n
    need(block.get("public_verified") == len(accepted), "public total mismatch")
    selected=[{"hotel_id":r["hotel_id"],"source_url":r["source_url"],"post_url":r["url"],
                   "publication_id":r["publication_id"],"version_id":r["version_id"]} for r in accepted.values()]
    need(block.get("verified_destinations")==selected,"verified destination mismatch")
    need(block.get("public_unverified")==len(holds),"public unavailable total mismatch")
    base = php_json(catalog_php, args.resolver, args.feed)
    current = base["routes"]
    edits = {}
    for hid,r in accepted.items():
        offer = "tg_" + str(hid)
        if current.get(offer) == r["url"]:
            continue
        if (base["source"].get(offer) != r["source_url"] or current.get(offer) != r["source_url"] or
            any(url == r["url"] and existing != offer for existing,url in current.items())):
            holds.append(hid)
            continue
        edits[offer] = r
    need(len(set(r["url"] for r in edits.values()))==len(edits),"target overlap")
    text = args.resolver.read_bytes()
    head=b"    ];\n    $landing = $source;\n"
    need(text.count(head)==1,"map changed")
    additions = "".join("        '%s' => ['%s', '%s'],\n" % (offer, r["source_url"], r["url"]) for offer,r in edits.items())
    candidate = text.replace(head, additions.encode()+head)
    with tempfile.TemporaryDirectory() as temp:
        path = Path(temp)/"candidate.php"; path.write_bytes(candidate)
        after=php_json(catalog_php,path,args.feed)
    need(after["input_hash"]==base["input_hash"] and after["projection_hash"]==base["projection_hash"] and after["source"]==base["source"],"feed mutated")
    expected=current | {offer:r["url"] for offer,r in edits.items()}
    need(list(after["routes"].items())==list(expected.items()),"other destination changed")
    if args.output.resolve() == args.resolver.resolve():
        raise ValueError("Output must be an isolated candidate file")
    args.output.write_bytes(candidate)
    return {"new_rows":list(edits),"already_routed":len(accepted)-len(edits)-len([x for x in holds if x in accepted]),
            "held":holds,"source_count":len(current),"preserved":len(current)-len(edits),"source_sha256":base["input_hash"],
            "projection_sha256":base["projection_hash"],"seconds":round(time.monotonic()-start, 3)}

if __name__=="__main__":
    p=argparse.ArgumentParser(description=__doc__)
    for name in ("native","public","plan","resolver","feed","output","verifier-repo","parent-zip"):
        p.add_argument("--"+name, type=Path, required=True)
    for name in ("operation","run","app"):
        p.add_argument("--"+name,required=True)
    for name in ("repository-sha","artifact-id","artifact-sha256","public-sha256"):
        p.add_argument("--"+name,required=True)
    print(json.dumps(main(p.parse_args()), ensure_ascii=False))
