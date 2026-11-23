#!/usr/bin/env python3
"""Offline integration checks for the optional AnyTour cover renderer.

Uses the three preserved real controls by default. --pilot-root additionally
proves all eleven saved PNGs against the untouched external pilot package.
No network, publication, server operation or fixture update is performed.
"""
import argparse
import ast
import copy
import hashlib
import json
import shutil
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[3]
FIXTURES = HERE / "fixtures"
GENERATOR = HERE.parent / "prepare-commercial-covers.py"
BRIDGE = HERE.parent / "prepare-commercial-covers-satori.mjs"
RENDERER = HERE / "src/render.mjs"
LOGO = Path(".github/traffic-publish/brand/anytour-logo-white.png")
MANIFEST = json.loads((FIXTURES / "manifest.json").read_text())
CASES = {case["hotel_id"]: case for case in MANIFEST["cases"]}
RESULTS = []


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def read_json(path):
    return json.loads(path.read_text())


def write_json(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n")


def require(condition, reason):
    if not condition:
        raise AssertionError(reason)


def test(name, function):
    try:
        RESULTS.append({"name": name, "passed": True, "detail": function()})
    except Exception as error:
        RESULTS.append({"name": name, "passed": False, "error": str(error)})


def run(output, *, case=CASES[4185], fixtures=FIXTURES, script=GENERATOR,
        renderer="satori", input_sha=None, editorial_sha=None, editorial_name=None):
    source = fixtures / case["input"]
    editorial = fixtures / (editorial_name or case["editorial"])
    command = [sys.executable, str(script), "--input", str(source),
               "--expected-input-sha256", input_sha or digest(source.read_bytes()),
               "--output-dir", str(output)]
    if renderer is not None:
        command += ["--renderer", renderer]
    if renderer == "satori":
        command += ["--editorial-input", str(editorial), "--expected-editorial-sha256",
                    editorial_sha or digest(editorial.read_bytes())]
    return subprocess.run(command, cwd=REPO, text=True, capture_output=True, timeout=60)


def successful(process):
    require(process.returncode == 0, process.stderr[-2500:])
    result = json.loads(process.stdout)
    require(result.get("ok") is True and result.get("prepared") == 1, "one-card CLI result required")
    return result


def rejected(process, output, reason):
    require(process.returncode != 0 and reason in process.stderr,
            "wrong rejection: " + process.stderr[-1800:])
    require(not output.exists(), "rejected input left a final output directory")
    require(not list(output.parent.glob(".satori-stage-*")), "rejected input left a staging directory")


def fixtures_unchanged():
    for item in MANIFEST["source_photos"] + MANIFEST["source_documents"]:
        require(digest((FIXTURES / item["path"]).read_bytes()) == item["sha256"],
                "fixture changed: " + item["path"])
    for case in MANIFEST["cases"]:
        for key in ["input", "editorial"]:
            require(digest((FIXTURES / case[key]).read_bytes()) == case[key + "_sha256"],
                    "control input changed: " + case[key])
    candidate = CASES[4185]
    require(digest((FIXTURES / candidate["candidate_editorial"]).read_bytes())
            == candidate["candidate_editorial_sha256"], "candidate editorial changed")
    require(len(MANIFEST["preserved_pilot_cards"]) == 11, "pilot manifest incomplete")
    return {"real_cases": 3, "exact_source_photos": 9, "preserved_pilot_png_hashes": 11,
            "royal_new_copy_separate_from_default": True}


def pillow_unchanged():
    baseline = MANIFEST["baseline_generator"]
    process = subprocess.run(["git", "show", baseline["ref"] + ":" + baseline["path"]],
                             cwd=REPO, capture_output=True, timeout=15)
    require(process.returncode == 0,
            "baseline commit unavailable; fetch the pinned CONTENT base before testing")
    original = process.stdout
    require(digest(original) == baseline["sha256"], "baseline generator hash differs")
    expected_ast = ast.parse(original.decode())
    actual_ast = ast.parse(GENERATOR.read_text())
    names = ["digest", "require", "accepted_sources", "render_cover", "prepare"]
    for name in names:
        old = next(node for node in expected_ast.body if isinstance(node, ast.FunctionDef) and node.name == name)
        new = next(node for node in actual_ast.body if isinstance(node, ast.FunctionDef) and node.name == name)
        require(ast.dump(old, include_attributes=False) == ast.dump(new, include_attributes=False),
                "Pillow function changed: " + name)
    with tempfile.TemporaryDirectory(prefix="anytour-pillow-") as directory:
        temp = Path(directory)
        legacy = temp / "legacy" / baseline["path"]
        legacy.parent.mkdir(parents=True)
        legacy.write_bytes(original)
        logo = temp / "legacy" / LOGO
        logo.parent.mkdir(parents=True)
        shutil.copy2(REPO / LOGO, logo)
        folders = [temp / "original", temp / "default", temp / "explicit"]
        successful(run(folders[0], script=legacy, renderer=None))
        successful(run(folders[1], renderer=None))
        successful(run(folders[2], renderer="pillow"))
        hashes = [digest((folder / "4185.png").read_bytes()) for folder in folders]
        receipts = [read_json(folder / "cover-receipt.json") for folder in folders]
        require(len(set(hashes)) == 1, "Pillow pixels changed")
        require(receipts[0] == receipts[1] == receipts[2], "Pillow receipts changed")
        return {"base": baseline["ref"], "unchanged_functions": names,
                "identical_pngs": 3, "identical_receipts": 3, "png_sha256": hashes[0]}


def real_controls():
    checked = []
    for case in MANIFEST["cases"]:
        with tempfile.TemporaryDirectory(prefix="anytour-control-") as directory:
            output = Path(directory) / "output"
            successful(run(output, case=case))
            source = read_json(FIXTURES / case["input"])
            editorial = read_json(FIXTURES / case["editorial"])
            receipt = read_json(output / "cover-receipt.json")
            require(receipt["state"] == "PREPARED_AWAITING_VISUAL_ACCEPTANCE", "draft state changed")
            require(receipt["publication_allowed"] is False and receipt["production_installed"] is False,
                    "permission or installation changed")
            require(receipt["provider_calls"] == receipt["remote_writes"] == 0, "unexpected external activity")
            for key, value in source.items():
                if key != "rows":
                    require(receipt[key] == value, "input metadata lost: " + key)
            row = receipt["rows"][0]
            for key, value in source["rows"][0].items():
                require(row[key] == value, "source row changed: " + key)
            require(row["editorial"] == editorial["rows"][0], "editorial copy, rights or holds changed")
            require(row["dimensions"] == [1000, 1000], "dimensions changed")
            require(row["layout"]["layout_errors"] == [], "layout overflow")
            actual = digest((output / row["filename"]).read_bytes())
            require(actual == row["sha256"] == case["expected_png_sha256"],
                    "preserved control PNG changed: " + case["id"])
            roles = [p["role"] for p in row["source_photos"]]
            require(roles == ["hero", "detail_left", "detail_right"], "photo roles changed")
            expected = {p["role"]: p for p in source["rows"][0]["photos"]}
            for photo in row["source_photos"]:
                old = expected[photo["role"]]
                require(photo["sha256"] == photo["actual_sha256"] == old["sha256"]
                        and photo["source_url"] == old["source_url"], "photo binding changed")
                require(photo["crop_center"] == old["crop_center"], "crop intent changed")
            checked.append({"id": case["id"], "png_sha256": actual,
                            "source_metadata_preserved": True, "layout_errors": 0})
    return checked


def rejected_mutation(case, mutate, reason):
    with tempfile.TemporaryDirectory(prefix="anytour-negative-") as directory:
        temp = Path(directory)
        fixtures = temp / "fixtures"
        shutil.copytree(FIXTURES, fixtures)
        settings = mutate(fixtures, case) or {}
        rejected(run(temp / "output", case=case, fixtures=fixtures, **settings), temp / "output", reason)
        return {"rejection": reason, "no_final_output": True, "no_staging_output": True}


def input_hash_change(fixtures, case):
    file = fixtures / case["input"]
    old = digest(file.read_bytes())
    file.write_bytes(file.read_bytes() + b" ")
    return {"input_sha": old}


def editorial_hash_change(fixtures, case):
    file = fixtures / case["editorial"]
    old = digest(file.read_bytes())
    file.write_bytes(file.read_bytes() + b" ")
    return {"editorial_sha": old}


def wrong_identity(fixtures, case):
    file = fixtures / case["editorial"]
    data = read_json(file)
    data["rows"][0]["hotel_id"] += 1
    write_json(file, data)


def source_bytes_change(fixtures, case):
    data = read_json(fixtures / case["input"])
    file = fixtures / data["rows"][0]["photos"][0]["path"]
    raw = bytearray(file.read_bytes())
    raw[100] ^= 1
    file.write_bytes(raw)


def source_reference_change(fixtures, case):
    file = fixtures / case["editorial"]
    data = read_json(file)
    data["editorial_source_sha256"] = "0" * 64
    write_json(file, data)


def modify_input(fixtures, case, transform):
    source_file = fixtures / case["input"]
    editorial_file = fixtures / case["editorial"]
    source, editorial = read_json(source_file), read_json(editorial_file)
    transform(source, editorial)
    write_json(source_file, source)
    editorial["source_input_sha256"] = digest(source_file.read_bytes())
    write_json(editorial_file, editorial)


def duplicate_id(fixtures, case):
    def change(source, editorial):
        source["rows"].append(copy.deepcopy(source["rows"][0]))
        unrelated = copy.deepcopy(editorial["rows"][0])
        unrelated["hotel_id"] = 999999
        editorial["rows"].append(unrelated)
    modify_input(fixtures, case, change)


def duplicate_photo(fixtures, case):
    def change(source, editorial):
        copied = copy.deepcopy(source["rows"][0]["photos"][0])
        copied["role"] = "detail_left"
        source["rows"][0]["photos"][1] = copied
    modify_input(fixtures, case, change)


def asymmetric_crop_matches_actual_nodes():
    results = []
    # Long-title landscape and portrait verify both adaptive compositions.
    for hotel_id in [27481, 88169]:
        case = CASES[hotel_id]
        with tempfile.TemporaryDirectory(prefix="anytour-crop-") as directory:
            temp = Path(directory)
            fixtures = temp / "fixtures"
            shutil.copytree(FIXTURES, fixtures)
            centers = {"hero": [.2, .8], "detail_left": [.8, .2], "detail_right": [.15, .85]}
            def change(source, editorial):
                for photo in source["rows"][0]["photos"]:
                    photo["crop_center"] = centers[photo["role"]]
            modify_input(fixtures, case, change)
            successful(run(temp / "output", case=case, fixtures=fixtures))
            row = read_json(temp / "output/cover-receipt.json")["rows"][0]
            layout = row["layout"]
            checked = []
            for role, node_role, photo in zip(centers, ["photo-hero", "photo-detail-1", "photo-detail-2"], layout["photos"]):
                node = next(n for n in layout["nodes"] if n["role"] == node_role)
                require(all(abs(a - b) <= .5 for a, b in zip(photo["target"], [node["width"], node["height"]])),
                        "bridge target differs from rendered node")
                width, height = photo["source_dimensions"]
                crop, center = photo["crop"], centers[role]
                expected = [(width - crop["width"]) * center[0], (height - crop["height"]) * center[1]]
                actual = [crop["left"], crop["top"]]
                require(all(abs(a - b) <= 1 for a, b in zip(actual, expected)), "crop alignment drift")
                naive = [max(0, min(width - crop["width"], round(center[0] * width - crop["width"] / 2))),
                         max(0, min(height - crop["height"], round(center[1] * height - crop["height"] / 2)))]
                require(actual != naive, "asymmetric case does not detect a naive focal rename")
                checked.append({"role": role, "target": photo["target"], "actual_origin": actual,
                                "expected_origin": expected, "naive_origin": naive})
            results.append({"hotel_id": hotel_id, "checks": checked})
    return results


def existing_output_is_preserved():
    with tempfile.TemporaryDirectory(prefix="anytour-existing-") as directory:
        output = Path(directory) / "output"
        output.mkdir()
        (output / "keep.txt").write_text("existing preparation must survive\n")
        write_json(output / "cover-receipt.json", {"isolated_preview": True, "renderer": {"engine": "satori"}})
        before = {p.name: digest(p.read_bytes()) for p in output.iterdir()}
        process = run(output)
        require(process.returncode != 0 and "output_directory_already_exists" in process.stderr,
                "existing output not rejected: " + process.stderr[-1200:])
        require(before == {p.name: digest(p.read_bytes()) for p in output.iterdir()}, "existing files changed")
        require(not list(output.parent.glob(".satori-stage-*")), "existing output left staging data")
        return {"existing_files_unchanged": len(before), "prior_preview_receipt_does_not_authorize_deletion": True}


def typography_cannot_change_words():
    code = """import fs from 'node:fs/promises';
const {renderCard}=await import(process.argv[1]);
const source=JSON.parse(await fs.readFile(process.argv[2]));
const card=structuredClone(source.cards.find(c=>c.hotel_id===27481));
card.layout={title_lines:['Anantara Sir Bani Yas Island','Al Sahel Villa Hotel']};
for(const photo of card.photos)photo.path='assets/photos/nonexistent-typography-probe.png';
let error=null;try{await renderCard(card,{pngOnly:true,previews:false});}catch(e){error=e.message;}
console.log(JSON.stringify({error}));if(error!=='layout_override_changes_copy')process.exitCode=1;"""
    process = subprocess.run(["node", "--input-type=module", "-e", code, RENDERER.as_uri(),
                              str(FIXTURES / "anantara-source-cards.json")], cwd=REPO,
                             text=True, capture_output=True, timeout=20)
    require(process.returncode == 0, "wrong typography failure: " + process.stderr[-1500:] + process.stdout)
    return {"rejection": json.loads(process.stdout)["error"], "failed_before_image_access": True}


def candidate_copy_stays_separate():
    case = CASES[4185]
    old = read_json(FIXTURES / case["editorial"])["rows"][0]
    candidate = read_json(FIXTURES / case["candidate_editorial"])["rows"][0]
    require(old["description"] == "Семейный отдых у песчаного пляжа.", "default control copy changed")
    require(candidate["description"] == "Свой песчаный пляж и клубы для детей.", "candidate copy changed")
    require(old["title"] == candidate["title"] and old["hotel_id"] == candidate["hotel_id"], "candidate identity differs")
    with tempfile.TemporaryDirectory(prefix="anytour-candidate-") as directory:
        output = Path(directory) / "output"
        successful(run(output, editorial_name=case["candidate_editorial"]))
        receipt = read_json(output / "cover-receipt.json")
        require(receipt["rows"][0]["sha256"] == case["candidate_expected_png_sha256"], "saved candidate image changed")
        require(receipt["owner_visual_acceptance"] is False and receipt["publication_allowed"] is False,
                "candidate copy became accepted")
        return {"preserved_default_text": old["description"], "separate_candidate_text": candidate["description"],
                "candidate_png_sha256": case["candidate_expected_png_sha256"], "publication_allowed": False}


def all_eleven_from_pilot(pilot):
    for item in MANIFEST["pilot_source_files"]:
        require(digest((pilot / item["path"]).read_bytes()) == item["sha256"], "pilot source differs: " + item["path"])
    code = """import fs from 'node:fs/promises';import path from 'node:path';
const {renderCard,sha256}=await import(process.argv[1]);
const pilot=process.argv[2],manifest=JSON.parse(await fs.readFile(process.argv[3]));
const read=async p=>JSON.parse(await fs.readFile(path.join(pilot,p)));
const cards=(await read('fixtures/layout-cards.json')).cards;
const hints=(await read('fixtures/typography-overrides.json')).overrides;
const row=(await read('output/satori/cover-receipt.json')).rows[0];
const royal={id:'anytour-4185',brand:'anytour',hotel_id:4185,title:row.title,location:row.editorial.location,
description:row.editorial.description,demo:false,verified_identity:true,
photos:row.source_photos.map(p=>({...p,path:path.relative(pilot,path.resolve(pilot,'fixtures',p.path)),
width:p.oriented_dimensions[0],height:p.oriented_dimensions[1],focal:p.converted_focal}))};
const all=[royal,...cards.map(c=>({...c,...(hints[c.id]?{layout:hints[c.id]}:{})}))],results=[];
if(all.length!==11)throw new Error('pilot_case_count_changed');
for(const card of all){const expected=manifest.preserved_pilot_cards.find(x=>x.id===card.id);
if(!expected)throw new Error('unrecognized_pilot_case');
const stored=await fs.readFile(path.join(pilot,'output/refined',card.id+'.png'));
if(sha256(stored)!==expected.png_sha256)throw new Error('stored_pilot_png_changed:'+card.id);
const result=await renderCard(card,{assetRoot:pilot,pngOnly:true,previews:false});
const actual=sha256(result.png);if(actual!==expected.png_sha256)throw new Error('transplanted_render_changed:'+card.id);
results.push({id:card.id,png_sha256:actual,identical_to_preserved_pilot:true});}
console.log(JSON.stringify({cases:results.length,matched:results.length,results}));"""
    process = subprocess.run(["node", "--input-type=module", "-e", code, RENDERER.as_uri(),
                              str(pilot), str(FIXTURES / "manifest.json")], cwd=REPO,
                             text=True, capture_output=True, timeout=90)
    require(process.returncode == 0, "pilot preservation failed: " + process.stderr[-2500:])
    return json.loads(process.stdout)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pilot-root", type=Path)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    test("three fixtures, photographs and archived golden hashes stay pinned", fixtures_unchanged)
    test("Pillow base/default/explicit PNGs and receipts match; five function ASTs unchanged", pillow_unchanged)
    test("three real Satori controls preserve photos, copy, identifiers, metadata and pixels", real_controls)
    test("wrong input hash fails without output or fallback", lambda: rejected_mutation(CASES[4185], input_hash_change, "input:snapshot_changed"))
    test("wrong editorial hash fails without output or fallback", lambda: rejected_mutation(CASES[4185], editorial_hash_change, "editorial:snapshot_changed"))
    test("wrong editorial identity fails despite matching updated hash", lambda: rejected_mutation(CASES[4185], wrong_identity, "editorial_hotel:expected_exactly_one"))
    test("changed source image bytes fail before output", lambda: rejected_mutation(CASES[4185], source_bytes_change, "accepted_source_photo_changed"))
    test("editorial parent references must agree", lambda: rejected_mutation(CASES[4185], source_reference_change, "editorial_source_reference_mismatch"))
    test("duplicate hotel IDs are rejected", lambda: rejected_mutation(CASES[27481], duplicate_id, "hotel_id_invalid_or_duplicate"))
    test("duplicate source photograph is rejected", lambda: rejected_mutation(CASES[27481], duplicate_photo, "source_photos_not_distinct"))
    test("asymmetric centering follows actual landscape and portrait nodes", asymmetric_crop_matches_actual_nodes)
    test("existing output is refused without deleting previous files", existing_output_is_preserved)
    test("manual line hints cannot change words before image access", typography_cannot_change_words)
    test("new Royal copy remains a separate unaccepted preview", candidate_copy_stays_separate)
    if args.pilot_root:
        test("all eleven preserved pilot PNGs match the transplanted AnyTour renderer",
             lambda: all_eleven_from_pilot(args.pilot_root.resolve()))
    implementation = []
    for file in [GENERATOR, BRIDGE, RENDERER, Path(__file__).resolve(), HERE / "package.json",
                 HERE / "package-lock.json", FIXTURES / "manifest.json", REPO / LOGO,
                 *sorted((HERE / "assets/fonts").glob("*.ttf"))]:
        raw = file.read_bytes()
        implementation.append({"path": str(file.relative_to(REPO)), "sha256": digest(raw), "bytes": len(raw)})
    report = {"schema_version": 1, "created_at": datetime.now(timezone.utc).isoformat(),
              "scope": "AnyTour optional local preparation only; no publishing, server or commercial writes",
              "cases": len(RESULTS), "passed": sum(result["passed"] for result in RESULTS),
              "pilot_all_eleven_requested": bool(args.pilot_root), "results": RESULTS,
              "implementation": implementation}
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        write_json(args.report, report)
    print(json.dumps({"ok": report["passed"] == report["cases"], "cases": report["cases"],
                      "passed": report["passed"], "pilot_all_eleven_requested": bool(args.pilot_root),
                      "report": str(args.report) if args.report else None,
                      "failures": [r for r in RESULTS if not r["passed"]]}, ensure_ascii=False))
    return 0 if report["passed"] == report["cases"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
