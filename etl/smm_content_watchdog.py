#!/usr/bin/env python3
"""
SMM Content Watchdog — моніторинг виходу контенту @dreamcar.ua у Instagram.

Алерти в TG-чат SMM (через tg_notify_queue → tg-notify-queue-flush):
  • НЕМАЄ СТОРІЗ > 3 год поспіль        → алерт
  • НЕМАЄ ПОСТУ/РІЛЗ > 24 год           → алерт

Правила:
  • Тихі години 23:00–07:00 (Europe/Kyiv) — нічого не шлемо.
  • Відлік «3 год по сторіз» рахується наскрізь через ніч: о 07:00, якщо остання
    сторіз була >3 год тому — алерт одразу.
  • Повтор нагадування — раз на годину, поки контент не вийде.
  • Коли контент виходить — стан скидається, наступний розрив рахується заново.

Виконується у GitHub Action (має FB_ACCESS_TOKEN у secrets — на відміну від Edge).
Джерело правди — LIVE IG Graph API, з фолбеком на dashboard_ig_* якщо API щось не віддав.
Дедуп/повтори — dashboard_settings.

Env:
  FB_ACCESS_TOKEN, IG_USER_ID, FB_API_VERSION (default v21.0)
  SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY
  SMM_CHAT_ID           — опц., інакше dashboard_settings.smm_watchdog_chat_id
  STORY_GAP_HOURS=3, POST_GAP_HOURS=24
  WORK_START_HOUR=7, WORK_END_HOUR=23, REMIND_HOURS=1
  DRY_RUN=1             — рахувати й логувати, але НЕ писати в чергу/стан
"""
import os, sys, json, time
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
import requests

KYIV = ZoneInfo("Europe/Kyiv")

FB_TOKEN = os.getenv("FB_ACCESS_TOKEN", "")
IG_USER_ID = os.getenv("IG_USER_ID", "").strip() or "17841403783002317"
FB_API_VERSION = os.getenv("FB_API_VERSION", "v21.0")

SB_URL = os.getenv("SUPABASE_URL", "https://wotghlaehnvxyeacznvv.supabase.co").rstrip("/")
SB_KEY = os.getenv("SUPABASE_SERVICE_ROLE_KEY", "")

STORY_GAP_HOURS = float(os.getenv("STORY_GAP_HOURS", "3"))
POST_GAP_HOURS = float(os.getenv("POST_GAP_HOURS", "24"))
WORK_START_HOUR = int(os.getenv("WORK_START_HOUR", "7"))   # інклюзивно
WORK_END_HOUR = int(os.getenv("WORK_END_HOUR", "23"))      # ексклюзивно (23:00 = тиша)
REMIND_HOURS = float(os.getenv("REMIND_HOURS", "1"))
DRY_RUN = os.getenv("DRY_RUN", "") == "1"

STATE_KEY = "smm_watchdog_state"
CHAT_KEY = "smm_watchdog_chat_id"

HEADERS_SB = {
    "apikey": SB_KEY,
    "Authorization": f"Bearer {SB_KEY}",
    "Content-Type": "application/json",
}


def log(msg):
    ts = datetime.now(KYIV).strftime("%H:%M:%S")
    print(f"[{ts}] {msg}", flush=True)


def parse_ts(s):
    if not s:
        return None
    s = s.strip()
    try:
        if s.endswith("+0000"):
            return datetime.strptime(s[:19], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc)
        return datetime.fromisoformat(s.replace("Z", "+00:00"))
    except Exception:
        try:
            return datetime.strptime(s[:19], "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc)
        except Exception:
            return None


# ===== IG Graph =====
def fb_get(path, params=None):
    url = f"https://graph.facebook.com/{FB_API_VERSION}/{path}"
    params = dict(params or {})
    params["access_token"] = FB_TOKEN
    for attempt in range(4):
        try:
            r = requests.get(url, params=params, timeout=60)
        except Exception as e:
            log(f"  ⚠ net exc: {e}")
            time.sleep(2 * (attempt + 1))
            continue
        if r.status_code == 200:
            return r.json()
        if "rate limit" in r.text.lower() or "usage" in r.text.lower():
            time.sleep(2 ** attempt * 3)
            continue
        log(f"  ⚠ FB {r.status_code}: {r.text[:200]}")
        return None
    return None


def ig_last_story_ts(ig_id):
    d = fb_get(f"{ig_id}/stories", {"fields": "id,timestamp", "limit": 50})
    best = None
    for st in (d or {}).get("data", []):
        t = parse_ts(st.get("timestamp"))
        if t and (best is None or t > best):
            best = t
    return best


def ig_last_post_ts(ig_id):
    d = fb_get(f"{ig_id}/media", {"fields": "id,media_product_type,timestamp", "limit": 25})
    best = None
    for m in (d or {}).get("data", []):
        t = parse_ts(m.get("timestamp"))
        if t and (best is None or t > best):
            best = t
    return best


# ===== Supabase =====
def sb_get_setting(key):
    try:
        r = requests.get(
            f"{SB_URL}/rest/v1/dashboard_settings",
            headers=HEADERS_SB, params={"key": f"eq.{key}", "select": "value"}, timeout=30,
        )
        if r.ok and r.json():
            return r.json()[0]["value"]
    except Exception as e:
        log(f"  ⚠ get_setting {key}: {e}")
    return None


def sb_put_setting(key, value):
    now = datetime.now(timezone.utc).isoformat()
    try:
        r = requests.post(
            f"{SB_URL}/rest/v1/dashboard_settings?on_conflict=key",
            headers={**HEADERS_SB, "Prefer": "resolution=merge-duplicates,return=minimal"},
            json=[{"key": key, "value": value, "updated_at": now}], timeout=30,
        )
        if not r.ok:
            log(f"  ❌ put_setting {key} {r.status_code}: {r.text[:200]}")
    except Exception as e:
        log(f"  ⚠ put_setting {key}: {e}")


def sb_fallback_last(table, ig_id):
    try:
        r = requests.get(
            f"{SB_URL}/rest/v1/{table}",
            headers=HEADERS_SB,
            params={"ig_user_id": f"eq.{ig_id}", "select": "published_at",
                    "order": "published_at.desc", "limit": "1"}, timeout=30,
        )
        if r.ok and r.json():
            return parse_ts(r.json()[0]["published_at"])
    except Exception as e:
        log(f"  ⚠ fallback {table}: {e}")
    return None


def enqueue_tg(chat_id, text):
    if DRY_RUN:
        log(f"  [DRY] enqueue → {chat_id}: {text[:80]}...")
        return True
    try:
        r = requests.post(
            f"{SB_URL}/rest/v1/tg_notify_queue",
            headers={**HEADERS_SB, "Prefer": "return=minimal"},
            json=[{"chat_id": str(chat_id), "text": text, "parse_mode": "HTML",
                   "source": "smm-content-watchdog", "disable_web_page_preview": True}],
            timeout=30,
        )
        if r.ok:
            return True
        log(f"  ❌ enqueue {r.status_code}: {r.text[:200]}")
    except Exception as e:
        log(f"  ⚠ enqueue: {e}")
    return False


# ===== Pure logic =====
def in_quiet_hours(now_kyiv, start=WORK_START_HOUR, end=WORK_END_HOUR):
    return not (start <= now_kyiv.hour < end)


def should_alert(now_utc, last_ts, gap_hours, st, remind_hours=REMIND_HOURS):
    prev_alert = None
    if st and st.get("last_alert_at"):
        prev_alert = parse_ts(st["last_alert_at"])

    if last_ts is not None:
        gap = (now_utc - last_ts).total_seconds() / 3600.0
        if gap <= gap_hours:
            return False, {"last_alert_at": None,
                           "last_content_ts": last_ts.isoformat() if last_ts else None}

    if prev_alert is None:
        send = True
    else:
        send = (now_utc - prev_alert).total_seconds() / 3600.0 >= (remind_hours - 1/60)

    new_state = {"last_alert_at": now_utc.isoformat() if send else
                 (prev_alert.isoformat() if prev_alert else None),
                 "last_content_ts": last_ts.isoformat() if last_ts else None}
    return send, new_state


def fmt_gap(now_utc, last_ts):
    if last_ts is None:
        return "невідомо коли (немає даних за 24 год)"
    hrs = (now_utc - last_ts).total_seconds() / 3600.0
    last_k = last_ts.astimezone(KYIV).strftime("%d.%m %H:%M")
    if hrs < 24:
        return f"{hrs:.1f} год тому (остання: {last_k})"
    return f"{hrs/24:.1f} дн тому (остання: {last_k})"


def build_msg(kind, now_utc, last_ts):
    gap = fmt_gap(now_utc, last_ts)
    if kind == "stories":
        return (f"🟡 <b>SMM: немає сторіз</b>\n"
                f"@dreamcar.ua — сторіз не виходила вже {gap}.\n"
                f"Поріг: {STORY_GAP_HOURS:g} год. Час запостити 📲")
    return (f"🔴 <b>SMM: немає посту/рілз</b>\n"
            f"@dreamcar.ua — пост/рілз не виходив уже {gap}.\n"
            f"Поріг: {POST_GAP_HOURS:g} год. Потрібна публікація 🎬")


# ===== MAIN =====
def main():
    if not (FB_TOKEN and SB_KEY):
        log("❌ FB_ACCESS_TOKEN / SUPABASE_SERVICE_ROLE_KEY не задано"); sys.exit(1)

    now_utc = datetime.now(timezone.utc)
    now_kyiv = now_utc.astimezone(KYIV)

    if in_quiet_hours(now_kyiv):
        log(f"🌙 Тихі години ({now_kyiv:%H:%M} Kyiv) — пропускаю."); return

    chat_id = os.getenv("SMM_CHAT_ID", "").strip() or sb_get_setting(CHAT_KEY)
    if not chat_id:
        log("❌ Немає SMM_CHAT_ID"); sys.exit(1)

    live_story = ig_last_story_ts(IG_USER_ID)
    live_post = ig_last_post_ts(IG_USER_ID)
    last_story = live_story or sb_fallback_last("dashboard_ig_stories", IG_USER_ID)
    last_post = live_post or sb_fallback_last("dashboard_ig_media", IG_USER_ID)
    log(f"  ℹ story live={live_story} → {last_story} | post live={live_post} → {last_post}")

    if live_story is None and live_post is None:
        log("❌ IG API не віддав НІ сторіз, НІ постів — ймовірно токен/скоуп. НЕ шлю алерти (щоб не спамити хибним).")
        sys.exit(1)

    state = sb_get_setting(STATE_KEY) or {}
    if not isinstance(state, dict):
        state = {}

    new_state = dict(state)
    for kind, last_ts, thr in (("stories", last_story, STORY_GAP_HOURS),
                               ("posts", last_post, POST_GAP_HOURS)):
        send, st_new = should_alert(now_utc, last_ts, thr, state.get(kind) or {})
        new_state[kind] = st_new
        if send:
            ok = enqueue_tg(chat_id, build_msg(kind, now_utc, last_ts))
            log(f"  {'✅' if ok else '❌'} АЛЕРТ [{kind}] → {chat_id}")
        else:
            breaching = st_new.get("last_alert_at") is not None
            log(f"  · [{kind}] {'у розриві, чекаю повтору' if breaching else 'ok'}")

    if not DRY_RUN:
        sb_put_setting(STATE_KEY, new_state)
    log("✅ DONE")


if __name__ == "__main__":
    main()
