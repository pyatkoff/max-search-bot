#!/usr/bin/env python3
"""Private two-phase technical monitoring. Never print a route or Telegram body."""
import argparse
import copy
import hashlib
import json
import os
import re
import tempfile
import time
import urllib.request
import uuid
from pathlib import Path
from pyrage import passphrase

PROJECT = "anytour"
COUNTER = "98615635"
DOMAIN = b"anytour-modeled-monitor-v1\0"
STATUSES = {"ok", "off", "degraded", "unavailable"}
FLAGS = {
    "delivery_tick_stale", "profile_tick_stale", "delivery_tick_errors",
    "profile_tick_errors", "profile_disabled", "ready_queue_stale",
    "upload_outcome_unknown", "api_row_rejected", "report_unavailable",
    "storage_unavailable", "configuration_unavailable", "invalid_clock",
    "health_metadata_unavailable", "monitor_transport_unavailable", "monitor_source_mismatch",
}
ID_RE = re.compile(r"[1-9][0-9]{0,19}\Z")
TOKEN_RE = re.compile(r"[1-9][0-9]{4,19}:[A-Za-z0-9_-]{30,100}\Z")

def canonical_bytes(value):
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")

def password(key_bytes):
    normalized = key_bytes.replace(b"\r\n", b"\n").strip() + b"\n"
    return hashlib.sha256(DOMAIN + normalized).hexdigest()

def encode_cache(state, key_bytes):
    return passphrase.encrypt(canonical_bytes(state), password(key_bytes))

def decode_cache(ciphertext, key_bytes):
    state = json.loads(passphrase.decrypt(ciphertext, password(key_bytes)))
    validate_state(state)
    return state

def scoped(value):
    return isinstance(value, dict) and value.get("schema_version") == 1 and value.get("project") == PROJECT and value.get("counter_id") == COUNTER

def clean_flags(flags):
    if not isinstance(flags, list) or any(not isinstance(f, str) or f not in FLAGS for f in flags):
        raise ValueError("invalid_flags")
    return sorted(set(flags))

def fingerprint(status, flags):
    return hashlib.sha256(canonical_bytes([status, clean_flags(flags)])).hexdigest()

def validate_state(state):
    if not scoped(state) or not ID_RE.fullmatch(str(state.get("recipient", ""))) or not TOKEN_RE.fullmatch(str(state.get("token", ""))):
        raise ValueError("invalid_cache_scope")
    if type(state.get("initialized")) is not bool or type(state.get("verified")) is not bool or (state["initialized"] and not state["verified"]):
        raise ValueError("invalid_cache")
    incident = state.get("incident")
    if incident is not None:
        if not isinstance(incident, dict) or incident.get("status") not in ("degraded", "unavailable") or incident.get("fingerprint") != fingerprint(incident["status"], incident.get("flags")):
            raise ValueError("invalid_incident")
    pending = state.get("pending")
    if pending is not None:
        if not isinstance(pending, dict) or pending.get("kind") not in {"bootstrap", "failure", "recovery"} or pending.get("state") not in {"prepared", "sending", "unknown"}:
            raise ValueError("invalid_intent")
        if pending.get("health_status") not in STATUSES:
            raise ValueError("invalid_intent")
        clean_flags(pending.get("flags"))
        if not re.fullmatch(r"[0-9a-f]{32}", str(pending.get("intent_id", ""))) or not re.fullmatch(r"[1-9][0-9]*", str(pending.get("owner_run", ""))):
            raise ValueError("invalid_intent")

def health_view(value):
    if not scoped(value):
        return "unavailable", ["monitor_source_mismatch"]
    try:
        status = value.get("status")
        flags = clean_flags(value.get("flags"))
        if status not in STATUSES or (status == "ok" and flags):
            raise ValueError("invalid_status")
        return status, flags
    except (TypeError, ValueError):
        return "unavailable", ["health_metadata_unavailable"]

def result(status, flags, notification="quiet", prepared=False, reason=None):
    out = {"schema_version": 1, "project": PROJECT, "counter_id": COUNTER,
           "status": status, "flags": clean_flags(flags), "notification": notification,
           "intent_prepared": prepared, "ok": notification != "unknown"}
    if reason:
        out["reason"] = reason
    return out

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError("redirect_denied")

def telegram_wire(token, method, payload):
    if method not in {"getChat", "sendMessage"}:
        raise ValueError("method")
    request = urllib.request.Request("https://api.telegram.org/bot" + token + "/" + method,
                                    data=canonical_bytes(payload), headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.build_opener(NoRedirect).open(request, timeout=5) as response:
        body = response.read(65537)
    if len(body) > 65536:
        raise ValueError("response_bound")
    return json.loads(body)

def verify_chat(state, wire):
    try:
        response = wire(state["token"], "getChat", {"chat_id": state["recipient"]})
        chat = response.get("result", {})
        return response.get("ok") is True and str(chat.get("id")) == state["recipient"] and chat.get("type") == "private"
    except Exception:
        return False

def prepare(health, route, state, recipient, wire, run_id, bootstrap=False, now=None):
    now = int(time.time()) if now is None else now
    status, flags = health_view(health)
    if not ID_RE.fullmatch(recipient):
        raise ValueError("invalid_recipient")
    original_exists = state is not None
    state = copy.deepcopy(state)
    if state is not None:
        validate_state(state)
        if state["recipient"] != recipient:
            raise ValueError("recipient_mismatch")
    if bootstrap and (original_exists or route is None):
        raise ValueError("bootstrap_guard")
    if route is not None:
        if not scoped(route) or not TOKEN_RE.fullmatch(str(route.get("token", ""))):
            raise ValueError("invalid_route")
        if state is None and route.get("previous_state_recorded") is True:
            raise ValueError("retained_state_unavailable")
        if state is None:
            state = {"schema_version": 1, "project": PROJECT, "counter_id": COUNTER,
                     "recipient": recipient, "token": route["token"], "verified": False,
                     "initialized": False, "incident": None, "pending": None}
        elif route["token"] != state["token"]:
            raise ValueError("route_changed")
    if state is None:
        raise ValueError("route_unavailable")
    if state.get("pending") is not None:
        state["pending"]["state"] = "unknown"
        return state, result(status, flags, "unknown", reason="notification_delivery_unknown")
    if not state["verified"]:
        if not verify_chat(state, wire):
            out = result("unavailable", ["configuration_unavailable"], reason="route_verification_failed")
            out["ok"] = False
            return state, out
        state["verified"] = True
    if not state["initialized"]:
        kind = "bootstrap"
    elif status == "off":
        state["incident"] = None
        return state, result(status, flags)
    elif status == "ok":
        if state["incident"] is None:
            return state, result(status, flags)
        kind = "recovery"
    else:
        current = {"status": status, "flags": flags, "fingerprint": fingerprint(status, flags)}
        if state["incident"] == current:
            return state, result(status, flags)
        kind = "failure"
    state["pending"] = {"intent_id": uuid.uuid4().hex, "owner_run": str(run_id), "kind": kind,
                        "state": "prepared", "prepared_at": now, "health_status": status,
                        "flags": flags, "fingerprint": fingerprint(status, flags)}
    return state, result(status, flags, prepared=True)

def message_for(pending):
    if pending["kind"] == "bootstrap":
        return "AnyTour: мониторинг подключён. Проверка доставки уведомлений."
    if pending["kind"] == "recovery":
        return "AnyTour: технический мониторинг — работа восстановлена."
    descriptions = {
        "delivery_tick_stale": "Обработка конверсий не обновлялась более 15 минут.",
        "profile_tick_stale": "Сохранение прогнозов не обновлялось более 15 минут.",
        "delivery_tick_errors": "Обнаружена ошибка обработки конверсий.",
        "profile_tick_errors": "Обнаружена ошибка сохранения прогнозов.",
        "profile_disabled": "Сохранение прогнозов выключено при включённой отправке.",
        "ready_queue_stale": "Готовые начисления ожидают отправки более двух циклов.",
        "upload_outcome_unknown": "Результат отправки в Метрику остаётся неподтверждённым.",
        "api_row_rejected": "Обнаружено начисление с ошибкой обработки.",
        "report_unavailable": "Отчёт Метрики недоступен более 15 минут.",
        "storage_unavailable": "Не удалось прочитать состояние обработки.",
        "configuration_unavailable": "Не удалось проверить настройки мониторинга.",
        "invalid_clock": "Обнаружено несогласованное время событий.",
        "health_metadata_unavailable": "Не удалось проверить служебные данные.",
        "monitor_transport_unavailable": "Независимая проверка не смогла связаться с сервером.",
        "monitor_source_mismatch": "Не удалось подтвердить версию проверяемого сервера.",
    }
    return "AnyTour: обнаружена техническая проблема.\n" + "\n".join(descriptions[f] for f in pending["flags"])

def send(state, recipient, wire, save, run_id, prepared_this_run=False, now=None):
    now = int(time.time()) if now is None else now
    state = copy.deepcopy(state)
    validate_state(state)
    if state["recipient"] != recipient:
        raise ValueError("recipient_mismatch")
    pending = state.get("pending")
    if pending is None:
        return state, result("ok", [])
    status, flags = pending["health_status"], pending["flags"]
    if not prepared_this_run or pending["owner_run"] != str(run_id) or pending["state"] != "prepared" or not state["verified"]:
        pending["state"] = "unknown"
        save(state)
        return state, result(status, flags, "unknown", reason="notification_delivery_unknown")
    pending["state"] = "sending"
    save(state)
    try:
        response = wire(state["token"], "sendMessage", {"chat_id": recipient, "text": message_for(pending), "disable_web_page_preview": True})
        sent = response.get("result", {})
        chat = sent.get("chat", {})
        message_id = sent.get("message_id")
        if response.get("ok") is not True or type(message_id) is not int or message_id <= 0 or str(chat.get("id")) != recipient or chat.get("type") != "private":
            raise ValueError("unconfirmed_delivery")
    except Exception:
        pending["state"] = "unknown"
        save(state)
        return state, result(status, flags, "unknown", reason="notification_delivery_unknown")
    if pending["kind"] == "bootstrap":
        state["initialized"] = True
    elif pending["kind"] == "recovery":
        state["incident"] = None
    else:
        state["incident"] = {"status": status, "flags": flags, "fingerprint": pending["fingerprint"]}
    state["last_notification"] = {"kind": pending["kind"], "intent_id": pending["intent_id"], "message_id": message_id, "confirmed_at": now}
    state["pending"] = None
    save(state)
    return state, result(status, flags, "sent")

def read_bounded(path, limit, private=False):
    target = Path(path)
    if private and target.stat().st_mode & 0o077:
        raise ValueError("private_file_mode")
    with target.open("rb") as handle:
        data = handle.read(limit + 1)
    if len(data) > limit:
        raise ValueError("file_bound")
    return data

def atomic_write(path, data):
    target = Path(path)
    fd, temporary = tempfile.mkstemp(prefix=".modeled-monitor-", dir=target.parent)
    try:
        with os.fdopen(fd, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, target)
        os.chmod(target, 0o600)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--phase", choices=("prepare", "send"), required=True)
    parser.add_argument("--health")
    parser.add_argument("--route")
    parser.add_argument("--cache", required=True)
    parser.add_argument("--key-file", required=True)
    parser.add_argument("--output-cache", required=True)
    parser.add_argument("--recipient-env", default="MODELED_CONVERSION_ALERT_CHAT_ID")
    parser.add_argument("--bootstrap-test", action="store_true")
    parser.add_argument("--prepared-this-run", action="store_true")
    parser.add_argument("--run-id", required=True)
    args = parser.parse_args()
    state, key, original_cipher = None, None, None
    try:
        if os.environ.get("GITHUB_RUN_ID") != args.run_id or os.environ.get("GITHUB_RUN_ATTEMPT") != "1" or not re.fullmatch(r"[1-9][0-9]*", args.run_id):
            raise ValueError("original_run_guard")
        recipient = os.environ.get(args.recipient_env, "").strip()
        if not ID_RE.fullmatch(recipient):
            raise ValueError("invalid_recipient")
        key = read_bounded(args.key_file, 65536, private=True)
        if Path(args.cache).exists():
            original_cipher = read_bounded(args.cache, 131072)
            state = decode_cache(original_cipher, key)
        def save(value):
            atomic_write(args.output_cache, encode_cache(value, key))
        if args.phase == "prepare":
            try:
                health = json.loads(read_bounded(args.health, 32768))
            except Exception:
                health = {"schema_version": 1, "project": PROJECT, "counter_id": COUNTER,
                          "status": "unavailable", "flags": ["health_metadata_unavailable"]}
            route = None
            if args.route and Path(args.route).exists():
                route = json.loads(read_bounded(args.route, 4096, private=True))
            state, output = prepare(health, route, state, recipient, telegram_wire, args.run_id, args.bootstrap_test)
            save(state)
        else:
            if args.bootstrap_test or args.route:
                raise ValueError("send_arguments")
            state, output = send(state, recipient, telegram_wire, save, args.run_id, args.prepared_this_run)
            save(state)
    except Exception:
        if original_cipher is not None:
            try:
                atomic_write(args.output_cache, original_cipher)
            except Exception:
                pass
        output = result("unavailable", ["configuration_unavailable"], "unknown", reason="monitor_execution_failed")
    print(json.dumps(output, sort_keys=True, separators=(",", ":")))
    return 0 if output["ok"] else 1

if __name__ == "__main__":
    raise SystemExit(main())
