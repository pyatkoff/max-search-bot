"""Offline FEED55 compiler.

It authenticates the seven original publication artifacts, their original route
installation receipts, a fresh bounded publication read and the three current
public XML preimages.  It only renders seven existing native writer programs;
it never connects to GitHub, SSH or a provider and never writes production.
"""
from __future__ import annotations

import argparse
import base64
import contextlib
import hashlib
import io
import json
import pathlib
import re
import subprocess
import types

PREFIX = b"<?php\ndeclare(strict_types=1);\n"
NAMES = ("hotel-posts-telegram.xml", "hotel-posts-max.xml", "hotel-posts-all.xml")
ORDER = (1, 2, 4, 5, 9, 12, 13)


def need(value, reason):
    if not value:
        raise ValueError(reason)


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


def compact(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode()


def pin(path, digest, reason="pinned_input_changed"):
    raw = pathlib.Path(path).read_bytes()
    need(sha(raw) == digest, reason + ":" + pathlib.Path(path).name)
    return raw


def inside(root, name):
    root = pathlib.Path(root).resolve()
    path = (root / name).resolve()
    need(root in path.parents and not path.is_symlink(), "unsafe_input_path")
    return path


def publication_line(raw):
    line = raw.splitlines()[-1]
    return line, json.loads(line)


def preserve(before, after, name, previous, added):
    blocks = lambda raw: re.findall(rb"<offer\b[^>]*>.*?</offer>", raw, re.S)
    old, new = blocks(before), blocks(after)
    factor = 2 if name == "hotel-posts-all.xml" else 1
    need(len(old) == previous * factor, "previous_offer_count_changed")
    need(len(new) == (previous + added) * factor, "next_offer_count_changed")
    need(new[: len(old)] == old, "previous_offer_bytes_changed")
    need(
        all(b"<price" not in block.lower() and b"<oldprice" not in block.lower()
            for block in new[len(old):]),
        "unconfirmed_new_price",
    )
    ids = re.findall(rb'<offer\b[^>]*\bid="([^"]+)"', after)
    need(len(ids) == len(set(ids)) == len(new), "duplicate_offer_id")
    return {
        "name": name,
        "previous_offers": len(old),
        "new_offers": len(new) - len(old),
        "old_offer_bytes_identical": True,
    }


def adapted_tail(source, current_runtime, publication_runtime):
    text = source.decode()
    changes = [
        (
            'read.get("installed_sha")==a["expected_installed_sha"]',
            'read.get("installed_sha")==' + repr(current_runtime),
        ),
        (
            'pub.get("installed_sha")==a["expected_installed_sha"]',
            'pub.get("installed_sha")==' + repr(publication_runtime),
        ),
        (
            're.fullmatch(r"commercial-channel-hotel-feeds-\\d{8}-read[1-9]\\d*",read.get("operation",""))',
            're.fullmatch(r"commercial-channel-hotel-feeds-20261118-next174-read(?:1|2|4|5|9|12|13)",read.get("operation",""))',
        ),
        (
            'require(result.returncode==0,"native_append_validation_failed:"+result.stderr.decode()[:100])',
            'require(result.returncode==0,"native_append_validation_failed:rc="+str(result.returncode)+":stderr="+result.stderr.decode()[:1000]+":stdout="+result.stdout.decode()[:1000])',
        ),
    ]
    for old, new in changes:
        need(text.count(old) == 1, "tail_adapter_anchor_changed")
        text = text.replace(old, new, 1)
    inverse = text
    for old, new in reversed(changes):
        need(inverse.count(new) == 1, "tail_adapter_inverse_anchor_changed")
        inverse = inverse.replace(new, old, 1)
    need(inverse == source.decode(), "tail_adapter_not_byte_reversible")
    module = types.ModuleType("exact_feed55_tail_adapter")
    exec(compile(text, "exact_feed55_tail_adapter", "exec"), module.__dict__)
    return module


def validate_current_receipts(read, published):
    expected = {(p["hotel_id"], p["platform"]): p for p in published["posts"]}
    receipts = read["current_registry_receipts"]
    need(len(receipts) == len(expected), "current_receipt_count_changed")
    seen = set()
    for item in receipts:
        key = (item["hotel_id"], item["platform"])
        need(key in expected and key not in seen, "current_receipt_pair_changed")
        seen.add(key)
        original = expected[key]
        need(
            item.get("current_registry_receipt_verified") is True
            and item.get("provider_read_performed") is False,
            "current_receipt_unverified",
        )
        for field in ("hotel_id", "publication_id", "version_id"):
            need(int(item[field]) == int(original[field]), "current_receipt_version_changed")
        for field in ("post_url", "text_sha256", "button_url", "button_text"):
            need(item[field] == original[field], "current_receipt_content_changed")
        need(
            item["original_photo_sha256"] == original["photo_sha256"]
            and item["image_count"] == 1,
            "current_receipt_photo_changed",
        )


def main():
    parser = argparse.ArgumentParser()
    for name in ("repo", "config", "originals", "reads", "preimages", "out"):
        parser.add_argument("--" + name, required=True, type=pathlib.Path)
    parser.add_argument("--config-sha256", required=True)
    parser.add_argument("--execution-run-id", required=True, type=int)
    args = parser.parse_args()

    config_raw = pin(args.config, args.config_sha256, "reviewed_config_changed")
    config = json.loads(config_raw)
    need(config["schema_version"] == 1 and config["state"] == "REVIEWED_FEED55_OFFLINE_COMPILER", "config_state_changed")
    need([g["batch"] for g in config["groups"]] == list(ORDER), "group_order_changed")
    need(sum(len(g["hotel_ids"]) for g in config["groups"]) == 55, "hotel_scope_changed")
    need(len({hotel for g in config["groups"] for hotel in g["hotel_ids"]}) == 55, "duplicate_hotel_scope")
    need(args.execution_run_id > 0, "execution_run_missing")
    need(not args.out.exists(), "output_exists_no_overwrite")
    args.out.mkdir(mode=0o700, parents=True)

    ops = args.repo / "tools/direct_demand/operations"
    sources = {}
    for name, digest in config["source_sha256"].items():
        sources[name] = pin(inside(args.repo, name), digest, "source_changed")
    route_groups = {}
    need(
        [g["batch"] for g in config["route_projection"]["groups"]] == list(ORDER),
        "route_projection_order_changed",
    )
    for descriptor in config["route_projection"]["groups"]:
        raw = pin(
            inside(args.repo, descriptor["path"]),
            descriptor["sha256"],
            "route_projection_changed",
        )
        item = json.loads(raw)
        need(
            item["state"] == "ROOT_VERIFIED_FEED55_ROUTE_GROUP_PROJECTION"
            and item["root_verified_actual_private_artifact"] is True
            and item["batch"] == descriptor["batch"],
            "route_projection_scope_changed",
        )
        route_groups[item["batch"]] = item

    current_runtime = config["runtime_sha"]
    previous_count = 1000
    previous = args.preimages
    results = []
    all_ids = []

    for group in config["groups"]:
        batch = group["batch"]
        ids = group["hotel_ids"]
        all_ids.extend(ids)
        original = inside(args.originals, group["original_dir"])
        dest = args.out / ("p%02d" % batch)
        dest.mkdir(mode=0o700)

        manifest_raw = pin(original / "manifest.json", group["manifest_sha256"], "manifest_changed")
        approval_raw = pin(original / "approval.json", group["approval_sha256"], "approval_changed")
        publication_raw = pin(
            original / "original-native-output.jsonl",
            group["publication_output_sha256"],
            "publication_output_changed",
        )
        summary, published = publication_line(publication_raw)
        need(sha(summary) == group["publication_summary_sha256"], "publication_summary_changed")
        manifest = json.loads(manifest_raw)
        approval = json.loads(approval_raw)
        need(manifest["operation"] == published["operation"] == group["publication_operation"], "publication_operation_changed")
        need(approval["manifest_sha256"] == sha(manifest_raw), "approval_manifest_changed")
        need(approval["hotel_ids"] == ids == [row["hotel_id"] for row in manifest["rows"]], "publication_scope_changed")
        need(len(published["posts"]) == 2 * len(ids), "publication_pair_count_changed")
        need({p["hotel_id"] for p in published["posts"]} == set(ids), "publication_hotel_set_changed")

        read_path = inside(args.reads, group["read_file"])
        read_raw = pin(read_path, group["read_sha256"], "fresh_read_changed")
        read = json.loads(read_raw)
        need(read["installed_sha"] == current_runtime, "fresh_read_runtime_changed")
        need([row["hotel_id"] for row in read["rows"]] == ids, "fresh_read_scope_changed")
        validate_current_receipts(read, published)

        route_projection = route_groups[batch]
        receipt_raw = route_projection["receipt_utf8"].encode()
        route_manifest_raw = route_projection["manifest_projection_utf8"].encode()
        need(sha(receipt_raw) == group["route_receipt_sha256"], "route_receipt_changed")
        need(sha(route_manifest_raw) == group["route_manifest_sha256"], "route_manifest_changed")
        receipt = json.loads(receipt_raw)
        route_manifest = json.loads(route_manifest_raw)
        need(receipt["ok"] is True and receipt["state"] == "INSTALLED_AND_NATIVE_VERIFIED", "route_not_installed")
        need(route_manifest["previous_count"] == previous_count, "route_previous_count_changed")
        need(route_manifest["logical_hotels"] == previous_count + len(ids), "route_total_changed")
        need(route_manifest["new_hotel_ids"] == ids, "route_hotel_scope_changed")
        need(route_manifest["files"][0]["sha256"] == group["route_map_sha256"], "route_manifest_map_changed")
        expected_tuples = [
            {
                "hotel_id": binding["hotel_id"],
                "alias": binding["public_route_alias"],
                "offer_id": binding["public_offer_id"],
                "post_id": binding["publication_id"],
                "post_url": binding["post_url"],
            }
            for binding in read["private_max_bindings"]
        ]
        proof = dict(route_projection["proof"])
        need(
            sha(json.dumps(expected_tuples, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode())
            == proof["new_binding_tuples_sha256"],
            "route_projection_identity_changed",
        )
        proof["bound_read_sha256"] = sha(read_raw)
        proof_raw = compact(proof)

        for name, raw in (
            ("manifest.json", manifest_raw),
            ("approval.json", approval_raw),
            ("publication.jsonl", publication_raw),
            ("bound-read.json", read_raw),
            ("route-receipt.json", receipt_raw),
            ("route-manifest.json", route_manifest_raw),
        ):
            (dest / name).write_bytes(raw)
        (dest / "route-proof.json").write_bytes(proof_raw)

        operation = "commercial-channel-hotel-feeds-20261120-update%d" % (
            args.execution_run_id * 100 + batch
        )
        previous_hashes = {name: sha((previous / name).read_bytes()) for name in NAMES}
        admission = {
            "schema_version": 1,
            "operation": operation,
            "expected_installed_sha": approval["expected_installed_sha"],
            "previous_count": previous_count,
            "manifest_sha256": sha(manifest_raw),
            "publication_summary_sha256": sha(summary),
            "bound_read_sha256": sha(read_raw),
            "route_receipt_sha256": sha(receipt_raw),
            "route_manifest_sha256": sha(route_manifest_raw),
            "route_proof_sha256": sha(proof_raw),
            "original_route_manifest_sha256": proof["original_route_manifest_sha256"],
            "previous_feed_sha256": previous_hashes,
            "writer_sha256": config["source_sha256"]["tools/direct_demand/operations/publish-commercial-channel-hotel-feeds-20261103-1.php"],
            "monitor_contract_sha256": config["source_sha256"]["tools/direct_demand/operations/monitor-price-contract.php"],
        }
        (dest / "admission.json").write_bytes(compact(admission))

        module = adapted_tail(
            sources["tools/direct_demand/operations/prepare-publication-tail.py"],
            current_runtime,
            published["installed_sha"],
        )
        ns = argparse.Namespace(
            manifest=dest / "manifest.json",
            approval=dest / "approval.json",
            admission=dest / "admission.json",
            publication=dest / "publication.jsonl",
            bound_read=dest / "bound-read.json",
            route_receipt=dest / "route-receipt.json",
            route_manifest=dest / "route-manifest.json",
            route_map=None,
            route_proof=dest / "route-proof.json",
            previous_feed_dir=previous,
            writer=ops / "publish-commercial-channel-hotel-feeds-20261103-1.php",
            monitor_contract=ops / "monitor-price-contract.php",
            output=dest / "writer-original.php",
        )
        with contextlib.redirect_stdout(io.StringIO()):
            module.feed(ns)

        writer_original = (dest / "writer-original.php").read_bytes()
        old = ("const EXPECTED_INSTALLED_SHA='" + approval["expected_installed_sha"] + "';").encode()
        new = ("const EXPECTED_INSTALLED_SHA='" + current_runtime + "';").encode()
        need(writer_original.startswith(PREFIX + old) and writer_original.count(old) == 1, "writer_header_changed")
        writer_current = writer_original.replace(old, new, 1)
        need(writer_current.replace(new, old, 1) == writer_original, "writer_body_changed")

        guard = sources["tools/direct_demand/operations/commercial-channel-hotel-feeds-20261113-update10-nightly-guard.php"]
        need(guard.startswith(PREFIX), "guard_prefix_changed")
        program = PREFIX + guard[len(PREFIX):] + b"\n" + writer_current[len(PREFIX):]
        (dest / "write.php").write_bytes(program)
        subprocess.run(["php", "-l", str(dest / "write.php")], check=True, capture_output=True)

        input_raw = (dest / "writer-original.php.input.json").read_bytes()
        rendered = json.loads(input_raw)
        need(rendered["schema_version"] == 4 and rendered["operation"] == operation, "writer_input_changed")
        after = dest / "after"
        after.mkdir(mode=0o700)
        preservation = []
        for file in rendered["files"]:
            before = base64.b64decode(file["previous_content_base64"], validate=True)
            next_bytes = base64.b64decode(file["content_base64"], validate=True)
            need(sha(before) == file["expected_previous_sha256"], "render_previous_hash_changed")
            need(sha(next_bytes) == file["sha256"], "render_next_hash_changed")
            preservation.append(preserve(before, next_bytes, file["name"], previous_count, len(ids)))
            (after / file["name"]).write_bytes(next_bytes)

        results.append(
            {
                "batch": batch,
                "operation": operation,
                "hotels": len(ids),
                "hotel_ids": ids,
                "script_sha256": sha(program),
                "input_sha256": sha(input_raw),
                "previous_feed_sha256": previous_hashes,
                "files": [
                    {k: file[k] for k in ("name", "sha256", "bytes", "rows", "expected_previous_sha256")}
                    for file in rendered["files"]
                ],
                "route_receipt_sha256": sha(receipt_raw),
                "route_manifest_sha256": sha(route_manifest_raw),
                "route_map_sha256": group["route_map_sha256"],
                "route_proof_sha256": sha(proof_raw),
                "publication_summary_sha256": sha(summary),
                "preservation": preservation,
            }
        )
        previous = after
        previous_count += len(ids)

    need(previous_count == 1055 and len(set(all_ids)) == 55, "final_scope_changed")
    intent = {
        "schema_version": 1,
        "state": "FEED55_PREPARED_NOT_WRITTEN",
        "runtime_sha": current_runtime,
        "execution_run_id": args.execution_run_id,
        "previous_pairs": 1000,
        "final_pairs": 1055,
        "hotels": 55,
        "groups": results,
        "new_prices": 0,
        "new_messages": 0,
        "provider_calls": 0,
        "production_writes": 0,
        "requires_fresh_route_readback_and_owner_permit": True,
    }
    (args.out / "intent.json").write_bytes(compact(intent))
    print(json.dumps({k: intent[k] for k in ("state", "previous_pairs", "final_pairs", "hotels", "production_writes")}))


if __name__ == "__main__":
    main()
