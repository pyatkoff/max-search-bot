#!/usr/bin/env python3
"""Five existing Site edits and one import, using the original guarded client.

Plans are local. Execution is exposed separately only after actual publication,
native592 and update10 proofs are validated and the root authorizes this package.
"""
from __future__ import annotations

import copy
from pathlib import Path

import contract14 as c

EDIT_ALLOWED = {"photos", "maxText", "telegramText", "evidence", "versions", "revision", "status", "queued", "updatedAt"}
IMPORT_ALLOWED = {"published", "publicationSnapshots", "sourceRefs", "feedPresence", "photoSets", "status", "queued", "reuse", "updatedAt"}
IMPORT_ID = "batch14-source-20261113-import-v1"
BASELINE_FILE = c.ROOT.parent / "site-before.json"
BASELINE_FILE_SHA = c.sha(BASELINE_FILE.read_bytes())


def protected(row, allowed):
    return {k: v for k, v in row.items() if k not in allowed}


def edit_id(site_id):
    return "batch14-source-20261113-edit-" + site_id


def build_plan(registry, proof, require_feed=True):
    c.need(type(registry.get("version")) is int and registry["version"] >= 341
           and len(registry.get("hotels", [])) == 666, "registry_scope_changed")
    hotels = {h["id"]: h for h in registry["hotels"]}
    c.need(len(hotels) == 666, "registry_duplicate_identity")
    c.need(c.sha(BASELINE_FILE.read_bytes()) == BASELINE_FILE_SHA, "accepted_source_preimage_changed")
    baseline = {h["id"]: h for h in c.read(BASELINE_FILE)["hotels"]}
    operation_ids = {edit_id(site_id) for site_id in c.SITE_IDS.values()} | {IMPORT_ID}
    c.need(not operation_ids.intersection(e["id"] for e in registry["events"]), "existing_operation_requires_reconciliation")
    if require_feed:
        c.need(set(proof.get("feedBindings", {})) == set(c.IDS) and proof.get("routeProofSha256")
               and proof.get("feedSummarySha256"), "actual_native_and_feed_proofs_required")
    edits = []
    for source in proof["manifest"]["rows"]:
        hid = source["hotel_id"]; site_id = c.SITE_IDS[hid]; h = hotels[site_id]
        c.need(h == baseline[site_id], "accepted_four_preimage_changed:" + site_id)
        c.need(h["tourvisorId"] == str(hid) and h["revision"] == h["acceptedRevision"] == 2
               and h["status"] == "accepted" and h["published"] == {} and not h.get("feedPresence")
               and not h.get("publicationSnapshots") and h.get("ctaMode") == "publisher", "four_source_identity_or_state_changed")
        c.need(h["maxText"] != source["text"] and h["telegramText"] != source["text"], "four_edit_no_longer_needed")
        marker = f"[{c.OPERATION}/source-sync]"
        c.need(marker not in h["evidence"], "four_edit_already_present")
        addition = marker + "\nРабочие тексты приведены к подтверждённым публикациям batch14. " \
            "Редактура без новой приёмки фактов; исходная принятая версия 2 сохранена.\nПубликация и проверка: " + proof["publicationRun"]
        evidence = h["evidence"] + ("\n\n" if h["evidence"] else "") + addition
        c.need(len(evidence) <= 16000, "evidence_size_limit")
        receipts, snapshots = {}, []
        for channel in c.CHANNELS:
            p = proof["posts"][(hid, channel)]
            receipt = {"url": p["post_url"], "mid": p["message_id"], "at": proof["capturedAt"], "idKind": "provider",
                       "buttonProof": {"url": p["button_url"], "publicationId": c.decimal(p["publication_id"]),
                                       "hotelNativeId": str(hid), "verifiedAt": proof["capturedAt"], "sourceUrl": proof["publicationRun"]}}
            receipts[channel] = receipt
            # safeReceipt in the frozen publisher requires exactly one image.
            snapshots.append({"channel": channel, "text": source["text"], "textSha256": source["text_sha256"],
                              "capturedAt": proof["capturedAt"], "sourceUrl": proof["publicationRun"], "proofKind": "completed_package",
                              "post": copy.deepcopy(receipt), "publicationId": c.decimal(p["publication_id"]),
                              "versionId": c.decimal(p["version_id"]), "buttonText": p["button_text"],
                              "coverUrl": source["photo_url"], "mediaCount": 1})
        refs = [{"kind": "receipt", "label": "Batch14 · подтверждённые публикации MAX и Telegram", "url": proof["publicationRun"],
                 "at": proof["capturedAt"], "note": "Исходная операция " + c.OPERATION + ". Текст, одна обложка и кнопка каждого сообщения проверены. "
                  + "; ".join(f"{s['channel']}: publication {s['publicationId']}, version {s['versionId']}" for s in snapshots)}]
        feed_presence = None
        if require_feed:
            binding = proof["feedBindings"][hid]
            feed_presence = {"at": proof["feedCapturedAt"], "sourceDate": proof["feedCatalogDate"],
                             "url": "https://app.anytoour.ru/feed/hotel-posts-all.xml", "maxUrl": binding["destinations"]["max"],
                             "telegramUrl": binding["destinations"]["telegram"]}
            refs.append({"kind": "feed", "label": "Batch14 · три публичных фида", "url": feed_presence["url"],
                         "at": proof["feedCapturedAt"], "note": "Операция " + c.FEED_OPERATION + "; 592/592/1184 предложений. "
                         "Новые две пары и маршруты native592 подтверждены; прежние 590 блоков каждого канала сохранены. " + proof["feedRun"]})
        c.need(len(h.get("sourceRefs", [])) + len(refs) <= 100 and len(h.get("versions", [])) + 1 <= 500,
               "site_history_capacity_exceeded")
        edits.append({"id": site_id, "tourvisorId": str(hid), "name": h["name"], "operationId": edit_id(site_id),
                      "beforeSha256": c.digest(h), "patch": {"maxText": source["text"], "telegramText": source["text"], "photos": c.new_photos(hid), "evidence": evidence},
                      "published": receipts, "snapshots": snapshots, "sourceRefs": refs + c.photo_refs(hid, h), "photoSet": c.photo_set(hid), "feedPresence": feed_presence})
    return {"operation": c.OPERATION, "state": "READY_FOR_OWNER_SIGNAL" if require_feed else "WAITING_ACTUAL_NATIVE580_AND_UPDATE10",
            "initialVersion": registry["version"], "initialRegistrySha256": c.digest(registry), "hotelCount": 2, "sourceEdits": 2,
            "importOperationId": IMPORT_ID, "importOperations": 1, "publicationSnapshotCount": 4,
            "publicationSummarySha256": proof["publicationSummarySha256"], "publicationRun": proof["publicationRun"],
            "routeProofSha256": proof.get("routeProofSha256"), "feedSummarySha256": proof.get("feedSummarySha256"),
            "protectedFields": ["acceptedRevision", "original version-history prefix", "description", "advantages", "limitations",
                                "original photos in version history", "original photoSets prefix", "ctaMode", "cta", "ctaUrl", "name", "country", "region", "stars",
                                "anexId", "aiId", "tourvisorId", "categoryEvidence", "releaseHolds", "researchPackets",
                                "knowledgePackets", "identityHistory", "receiptHistory", "monitorPriceSnapshots", "sources", "premium"],
            "allowedEditFields": sorted(EDIT_ALLOWED), "allowedImportFields": sorted(IMPORT_ALLOWED), "edits": edits}


def import_rows(current, plan):
    c.need(plan["state"] == "READY_FOR_OWNER_SIGNAL", "actual_feed_proof_missing")
    hotels = {h["id"]: h for h in current["hotels"]}
    rows = []
    for entry in plan["edits"]:
        row = copy.deepcopy(hotels[entry["id"]])
        c.need(all(row[k] == value for k, value in entry["patch"].items()) and row["status"] == "ready"
               and row["revision"] == 3 and row["acceptedRevision"] == 2 and row["published"] == {}, "fresh_import_source_changed")
        row["published"] = copy.deepcopy(entry["published"])
        row["publicationSnapshots"] = row.get("publicationSnapshots", []) + copy.deepcopy(entry["snapshots"])
        row["sourceRefs"] = row.get("sourceRefs", []) + copy.deepcopy(entry["sourceRefs"])
        row["feedPresence"] = copy.deepcopy(entry["feedPresence"])
        row["photoSets"] = row.get("photoSets", []) + [copy.deepcopy(entry["photoSet"])]
        rows.append(row)
    c.need(len(rows) == 2, "import_scope_changed")
    return rows


def verify_after_edits(before, after, plan):
    c.need(after["version"] == before["version"] + 2, "edit_chain_version_changed")
    old, new = ({h["id"]: h for h in r["hotels"]} for r in (before, after))
    c.need(old.keys() == new.keys() and len(new) == 666, "edit_chain_identity_changed")
    entries = {p["id"]: p for p in plan["edits"]}
    client = c.source_client()
    for site_id, h in old.items():
        if site_id in entries:
            verify_photo_edit(h, new[site_id], entries[site_id])
        else:
            c.need(new[site_id] == h, "other_hotel_changed_during_edits:" + site_id)
    ids = [e["id"] for e in after["events"]]
    c.need(all(ids.count(p["operationId"]) == 1 for p in plan["edits"]), "edit_operation_journal_incomplete")


def verify_import(before, after, plan):
    c.need(after["version"] == before["version"] + 1, "import_version_changed")
    old, new = ({h["id"]: h for h in r["hotels"]} for r in (before, after))
    c.need(old.keys() == new.keys() and len(new) == 666, "import_card_identity_changed")
    entries = {p["id"]: p for p in plan["edits"]}
    for site_id, h in old.items():
        updated = new[site_id]
        if site_id not in entries:
            c.need(updated == h, "other_hotel_changed_during_import:" + site_id)
            continue
        p = entries[site_id]
        c.need(protected(h, IMPORT_ALLOWED) == protected(updated, IMPORT_ALLOWED), "import_changed_protected_fields:" + site_id)
        c.need(updated["published"] == p["published"] and updated["feedPresence"] == p["feedPresence"]
               and updated["publicationSnapshots"] == h.get("publicationSnapshots", []) + p["snapshots"]
               and updated["sourceRefs"] == h.get("sourceRefs", []) + p["sourceRefs"]
               and updated["photoSets"] == h.get("photoSets", []) + [p["photoSet"]]
               and updated["status"] == "published" and updated["queued"] is False
               and updated["reuse"] == h["reuse"] + 1, "import_result_changed:" + site_id)
        c.dated(updated["updatedAt"])
    c.need([e["id"] for e in after["events"]].count(IMPORT_ID) == 1, "import_event_missing_or_duplicate")


def apply(client, output, proof, approved_plan=None):
    """No caller invokes this during preparation. At most one POST per stable ID."""
    output = Path(output).resolve()
    c.need(output.is_relative_to(c.ROOT) and not output.exists(), "new_local_execution_directory_required")
    output.mkdir()
    initial = client.request("/api/registry?view=export")
    plan = build_plan(initial, proof)
    if approved_plan is not None:
        c.need(plan == approved_plan, "fresh_registry_or_approved_plan_changed")
    c.save_new(output / "initial-registry.json", initial)
    c.save_new(output / "plan.json", plan)
    hotels = {h["id"]: h for h in initial["hotels"]}
    original = c.source_client()
    version = initial["version"]
    for index, entry in enumerate(plan["edits"], 1):
        body = {"version": version, "operationId": entry["operationId"], "action": {"type": "edit", "id": entry["id"], "patch": entry["patch"]}}
        original.one_post(client, output, f"edit-{index:02d}-{entry['id']}", body, hotels[entry["id"]])
        version += 1
    current = client.request("/api/registry?view=export")
    c.save_new(output / "before-import-registry.json", current)
    verify_after_edits(initial, current, plan)
    for index, entry in enumerate(plan["edits"], 1):
        c.save_new(output / f"edit-{index:02d}-{entry['id']}.verified.json",
                   {"operationId": entry["operationId"], "version": initial["version"] + index,
                    "protectedFieldsPreserved": True, "historyPreimagePreserved": True})
    rows = import_rows(current, plan)
    body = {"version": version, "operationId": IMPORT_ID,
            "action": {"type": "import", "label": "Batch14 · два опубликованных отеля и подтверждённый feed", "rows": rows}}
    preview = original.one_post(client, output, "preview", body, {"version": version, "rowsSha256": c.digest(rows)}, preview=True)
    c.need(preview.get("version") == version and preview.get("added") == preview.get("rejected") == 0
           and preview.get("merged") == 2 and preview.get("details") == [], "import_preview_failed")
    original.one_post(client, output, "import", body, {"version": version, "rowsSha256": c.digest(rows)})
    final = client.request("/api/registry?view=export")
    c.save_new(output / "final-registry.json", final)
    verify_import(current, final, plan)
    receipt = {"ok": True, "operation": c.OPERATION, "initialVersion": initial["version"], "finalVersion": final["version"],
               "sourceEdits": 2, "importOperations": 1, "publicationSnapshotsAdded": 4,
               "otherHotelsCompared": 664, "otherHotelsUnchanged": True, "acceptedRevisionPreserved": 2,
               "currentRevision": 3, "priorContentVersionsPreserved": True, "privateFactsPreserved": True,
               "monitorPriceSnapshotsPreserved": True, "actualFeedPresenceAdded": 2, "automaticRetries": 0,
               "publicationSummarySha256": proof["publicationSummarySha256"], "feedSummarySha256": proof["feedSummarySha256"],
               "initialRegistrySha256": c.digest(initial), "finalRegistrySha256": c.digest(final)}
    c.save_new(output / "verification.json", receipt)
    return final, receipt


def verify_photo_edit(before, after, entry):
    allowed = EDIT_ALLOWED | {"photos"}
    c.need(protected(before, allowed) == protected(after, allowed), "photo_edit_changed_other_fields")
    c.need(all(after[k] == v for k, v in entry["patch"].items()) and after["revision"] == before["revision"] + 1 and after["status"] == "ready" and not after["queued"], "photo_edit_result_changed")
    c.need(after["versions"][:-1] == before["versions"] and len(after["versions"]) == len(before["versions"]) + 1, "old_versions_changed")
    v = after["versions"][-1]; client = c.source_client()
    c.need(v["revision"] == before["revision"] and v["reason"] == "Версия до редактирования" and v["content"] == client.content(before) and v["at"] == after["updatedAt"], "original_text_and_photos_not_archived")
