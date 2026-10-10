#!/usr/bin/env python3
"""
Ad Watchdog: сторож реклами DreamCar у Meta (фаза 0 «Центру керування рекламою», рішення 27.09.2026).

ЛИШЕ ЧИТАННЯ. Нічого не змінює в Meta: тільки GET до Graph API, тривоги в Telegram і
рядки в ad_alerts (дедуп). Дії (пауза, бюджети) робить виконавець ad-director після ✓.

Перевірки (кожна дає тривогу з ключем дедупу; коли стан виправився, шле «вирішено» один раз):
  1. issue      — оголошення в живій групі (кампанія і група ACTIVE) має effective_status
                  WITH_ISSUES або DISAPPROVED і витрачало гроші за 7 днів. Сюди падає
                  заархівований IG-пост (16.09: «Instagram Ads Archived Organic Media»).
  2. no_buy     — оголошення сьогодні витратило ≥ NO_BUY_SPEND ₴ і має 0 покупок за пікселем
                  (omni_purchase). Нагадування про kill-switch; real з CRM перевіряє людина або чек.
  3. overspend  — спенд акаунта сьогодні > стеля × OVERSPEND_X (Meta може перебрати денний бюджет).
  4. budget_cap — сума денних бюджетів живих кампаній і груп > стеля (раз на день).
  5. stall      — жива кампанія з бюджетом ≥ STALL_MIN_BUDGET ₴ після STALL_AFTER_HOUR за Києвом
                  має 0 ₴ спенду сьогодні.
  6. calendar   — першим запуском дня: події календаря ad_events на сьогодні й завтра.
  7. silent     — режим ad-director мовчить: найновіший рядок запуску в ad_journal (action=note,
                  reason express-check / daily-diagnostic / event-run / event-stop) старший за свій
                  поріг. Перевірка вмикається лише після першого такого рядка (режим, якого ще
                  немає в розкладі, тривоги не дає). Мовчання ≠ справність (рішення 10.10.2026).
  8. stuck      — рядок ad_journal висить у status=approved довше STUCK_HOURS: запис у Meta почато,
                  а результат ніхто не зафіксував.

Тихі години 23:00–07:00 Києва: шлемо лише overspend.
Стеля: DAILY_CAP_UAH (деф. 20000) або dashboard_settings.ad_director_daily_cap (перекриває env).

Env: FB_ACCESS_TOKEN, AD_ACCOUNT_ID, SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, TG_BOT_TOKEN, TG_CHAT_ID,
     DRY_RUN=1 (рахує і друкує, нічого не шле і не пише).
"""
import os, sys, json
from datetime import datetime, timedelta, timezone
from zoneinfo import ZoneInfo
import requests

KYIV = ZoneInfo("Europe/Kyiv")
API = "https://graph.facebook.com/" + os.getenv("FB_API_VERSION", "v21.0")
FB_TOKEN = os.getenv("FB_ACCESS_TOKEN", "")
ACCOUNT = os.getenv("AD_ACCOUNT_ID", "4136058269783354").replace("act_", "")
SB_URL = os.getenv("SUPABASE_URL", "https://wotghlaehnvxyeacznvv.supabase.co").rstrip("/")
SB_KEY = os.getenv("SUPABASE_SERVICE_ROLE_KEY", "")
TG_TOKEN = os.getenv("TG_BOT_TOKEN", "")
TG_CHAT = os.getenv("TG_CHAT_ID", "")
DRY_RUN = os.getenv("DRY_RUN", "") == "1"

DAILY_CAP_UAH = float(os.getenv("DAILY_CAP_UAH", "20000"))
OVERSPEND_X = float(os.getenv("OVERSPEND_X", "1.25"))
NO_BUY_SPEND = float(os.getenv("NO_BUY_SPEND", "1500"))
STALL_MIN_BUDGET = float(os.getenv("STALL_MIN_BUDGET", "500"))
STALL_AFTER_HOUR = int(os.getenv("STALL_AFTER_HOUR", "12"))
REMIND_HOURS = float(os.getenv("REMIND_HOURS", "6"))
QUIET_START, QUIET_END = 23, 7
# скільки годин може мовчати кожен режим ad-director (інтервал розкладу + запас)
SILENCE_HOURS = {
    "express-check": float(os.getenv("SILENCE_EXPRESS_H", "4")),      # раз на 3 год
    "daily-diagnostic": float(os.getenv("SILENCE_DAILY_H", "26")),    # раз на добу
    "event-run": float(os.getenv("SILENCE_EVENT_RUN_H", "9")),        # найдовша пауза між запусками 7 год
    "event-stop": float(os.getenv("SILENCE_EVENT_STOP_H", "26")),     # раз на добу
}
STUCK_HOURS = float(os.getenv("STUCK_HOURS", "1"))
PROJECT = "dreamcar"

SB_H = {"apikey": SB_KEY, "Authorization": f"Bearer {SB_KEY}", "Content-Type": "application/json"}


def log(m):
    print(f"[{datetime.now(KYIV):%H:%M:%S}] {m}", flush=True)


# ---------- Graph API (GET only) ----------
def fb_get_all(path, params):
    """GET з пагінацією. Лише читання."""
    out, url, p = [], f"{API}/{path}", dict(params, access_token=FB_TOKEN, limit=500)
    for _ in range(40):
        r = requests.get(url, params=p, timeout=60)
        if not r.ok:
            raise RuntimeError(f"GET {path}: {r.status_code} {r.text[:300]}")
        j = r.json()
        out.extend(j.get("data", []))
        nxt = j.get("paging", {}).get("next")
        if not nxt:
            break
        url, p = nxt, None
    return out


def num(x):
    try:
        return float(x)
    except (TypeError, ValueError):
        return 0.0


def purchases(actions):
    for a in actions or []:
        if a.get("action_type") == "omni_purchase":
            return num(a.get("value"))
    return 0.0


# ---------- Supabase ----------
def sb_get(table, query):
    r = requests.get(f"{SB_URL}/rest/v1/{table}?{query}", headers=SB_H, timeout=30)
    r.raise_for_status()
    return r.json()


def sb_upsert(table, rows, conflict):
    """По одному рядку: PostgREST вимагає однакових ключів у пакеті, а тут вони різні."""
    if DRY_RUN or not rows:
        return
    for row in rows:
        r = requests.post(f"{SB_URL}/rest/v1/{table}?on_conflict={conflict}",
                          headers={**SB_H, "Prefer": "resolution=merge-duplicates,return=minimal"},
                          json=row, timeout=30)
        if not r.ok:
            log(f"  ⚠ upsert {table} {row.get('key')}: {r.status_code} {r.text[:200]}")


def daily_cap():
    try:
        rows = sb_get("dashboard_settings", "key=eq.ad_director_daily_cap&select=value")
        if rows:
            v = rows[0]["value"]
            v = v.get("uah") if isinstance(v, dict) else v
            if num(v) > 0:
                return num(v)
    except Exception as e:
        log(f"  ⚠ cap setting: {e}")
    return DAILY_CAP_UAH


# ---------- Telegram ----------
def tg(text):
    if DRY_RUN:
        log("  [DRY] TG:\n" + text)
        return True
    if not (TG_TOKEN and TG_CHAT):
        log("  ⚠ нема TG_BOT_TOKEN/TG_CHAT_ID")
        return False
    r = requests.post(f"https://api.telegram.org/bot{TG_TOKEN}/sendMessage",
                      json={"chat_id": TG_CHAT, "text": text, "parse_mode": "HTML",
                            "disable_web_page_preview": True}, timeout=30)
    if not r.ok:
        log(f"  ❌ TG {r.status_code}: {r.text[:200]}")
    return r.ok


def money(v):
    return f"{v:,.0f}".replace(",", " ")


def esc(s):
    return str(s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


# ---------- pure logic (тестується без мережі) ----------
def in_quiet(now_kyiv):
    return now_kyiv.hour >= QUIET_START or now_kyiv.hour < QUIET_END


def find_issues(ads, spend7):
    """ads: список з effective_status, campaign/adset effective_status, issues_info."""
    out = []
    for a in ads:
        if a.get("effective_status") not in ("WITH_ISSUES", "DISAPPROVED"):
            continue
        camp = (a.get("campaign") or {}).get("effective_status")
        aset = (a.get("adset") or {}).get("effective_status")
        if camp != "ACTIVE" or aset != "ACTIVE":
            continue
        if spend7.get(a["id"], 0) <= 0:
            continue
        why = "; ".join(esc(i.get("error_summary") or i.get("error_message") or "") for i in (a.get("issues_info") or []))
        out.append({
            "key": f"issue:{a['id']}", "kind": "issue", "entity_id": a["id"], "entity_name": a.get("name"),
            "message": f"⚠️ <b>Оголошення не крутиться</b>: {esc(a.get('name'))}\n"
                       f"статус {a.get('effective_status')} · група «{esc((a.get('adset') or {}).get('name'))}»\n"
                       f"причина: {why or 'Meta не вказала'}\n"
                       f"витрати за 7 днів: {money(spend7.get(a['id'], 0))} ₴",
        })
    return out


def find_no_buy(ad_rows):
    out = []
    for r in ad_rows:
        sp, pur = num(r.get("spend")), purchases(r.get("actions"))
        if sp >= NO_BUY_SPEND and pur == 0:
            out.append({
                "key": f"no_buy:{r['ad_id']}:{r.get('date_start')}", "kind": "no_buy",
                "entity_id": r["ad_id"], "entity_name": r.get("ad_name"),
                "message": f"🟠 <b>{money(sp)} ₴ і 0 покупок за пікселем</b> сьогодні: {esc(r.get('ad_name'))}\n"
                           f"кампанія «{esc(r.get('campaign_name'))}». Звір real з CRM перед рішенням (kill-switch {money(NO_BUY_SPEND)} ₴).",
            })
    return out


def find_overspend(spend_today, cap, x=OVERSPEND_X):
    if spend_today > cap * x:
        return [{"key": f"overspend:{datetime.now(KYIV):%Y-%m-%d}", "kind": "overspend", "entity_id": ACCOUNT,
                 "entity_name": "акаунт",
                 "message": f"🔴 <b>Перевитрата</b>: сьогодні {money(spend_today)} ₴ при стелі {money(cap)} ₴ (×{x})."}]
    return []


def find_budget_cap(budget_sum, cap, parts):
    if budget_sum > cap + 0.5:
        top = "\n".join(f"• {esc(n)}: {money(v)} ₴" for n, v in sorted(parts, key=lambda t: -t[1])[:6])
        return [{"key": f"budget_cap:{datetime.now(KYIV):%Y-%m-%d}", "kind": "budget_cap", "entity_id": ACCOUNT,
                 "entity_name": "акаунт",
                 "message": f"🟠 <b>Сума денних бюджетів {money(budget_sum)} ₴ більша за стелю {money(cap)} ₴</b>\n{top}"}]
    return []


def find_stall(campaigns, spend_by_campaign, now_kyiv):
    if now_kyiv.hour < STALL_AFTER_HOUR:
        return []
    out = []
    for c in campaigns:
        b = c.get("_budget_uah", 0)
        if b >= STALL_MIN_BUDGET and spend_by_campaign.get(c["id"], 0) <= 0:
            out.append({"key": f"stall:{c['id']}:{now_kyiv:%Y-%m-%d}", "kind": "stall", "entity_id": c["id"],
                        "entity_name": c.get("name"),
                        "message": f"🟡 <b>Кампанія не витрачає</b>: {esc(c.get('name'))} "
                                   f"(бюджет {money(b)} ₴, сьогодні 0 ₴ станом на {now_kyiv:%H:%M})"})
    return out


def find_silence(note_rows, now_utc, limits=None):
    """note_rows: рядки ad_journal action=note з полями reason, at. Тривога, коли найновіший
    рядок режиму старший за поріг. Режим без жодного рядка пропускаємо: його ще не запускали."""
    limits = SILENCE_HOURS if limits is None else limits
    last = {}
    for r in note_rows:
        at = datetime.fromisoformat(r["at"])
        if r.get("reason") in limits and (r["reason"] not in last or at > last[r["reason"]]):
            last[r["reason"]] = at
    out = []
    for reason, at in sorted(last.items()):
        age_h = (now_utc - at).total_seconds() / 3600
        if age_h > limits[reason]:
            out.append({"key": f"silent:{reason}", "kind": "silent", "entity_id": reason, "entity_name": reason,
                        "message": f"🔴 <b>Реклама-директор мовчить</b>: режим {esc(reason)} востаннє відпрацював "
                                   f"{at.astimezone(KYIV):%d.%m %H:%M} Kyiv ({age_h:.0f} год тому, норма до {limits[reason]:g} год). "
                                   f"Перевір Mac Studio і заплановану задачу."})
    return out


def find_stuck(approved_rows, now_utc, hours=None):
    """approved_rows: рядки ad_journal зі status=approved (id, action, entity_name, at)."""
    hours = STUCK_HOURS if hours is None else hours
    out = []
    for r in approved_rows:
        age_h = (now_utc - datetime.fromisoformat(r["at"])).total_seconds() / 3600
        if age_h > hours:
            out.append({"key": f"stuck:{r['id']}", "kind": "stuck", "entity_id": str(r["id"]),
                        "entity_name": r.get("entity_name"),
                        "message": f"🟠 <b>Запис у Meta не завершено</b>: {esc(r.get('action'))} · {esc(r.get('entity_name'))} "
                                   f"(журнал id {r['id']}, {age_h:.0f} год у статусі approved). "
                                   f"Наступний запуск режиму має звірити факт; якщо тривога повторюється, глянь сам."})
    return out


def calendar_msg(events, now_kyiv):
    today, tomorrow = now_kyiv.date(), now_kyiv.date() + timedelta(days=1)
    rows = {today: [], tomorrow: []}
    for e in events:
        d = datetime.fromisoformat(e["starts_at"]).astimezone(KYIV)
        if d.date() in rows:
            rows[d.date()].append(f"{d:%H:%M} · {esc(e['title'])}" + (f" ({esc(e['status'])})" if e.get("status") != "planned" else ""))
    if not rows[today] and not rows[tomorrow]:
        return None
    lines = ["🗓 <b>Календар реклами</b>"]
    for d, label in ((today, "Сьогодні"), (tomorrow, "Завтра")):
        if rows[d]:
            lines.append(f"<b>{label} {d:%d.%m}</b>")
            lines += [f"• {x}" for x in rows[d]]
    return "\n".join(lines)


# ---------- main ----------
def main():
    if not FB_TOKEN:
        log("❌ нема FB_ACCESS_TOKEN"); return 2
    now_k = datetime.now(KYIV)
    quiet = in_quiet(now_k)
    cap = daily_cap()
    log(f"Ad watchdog · act_{ACCOUNT} · {now_k:%d.%m %H:%M} Kyiv · стеля {cap:,.0f} ₴ · тихо={quiet} · dry={DRY_RUN}")

    # 1) оголошення з проблемами
    ads = fb_get_all(f"act_{ACCOUNT}/ads", {
        "fields": "id,name,status,effective_status,issues_info,adset{id,name,effective_status},campaign{id,name,effective_status}",
        "effective_status": json.dumps(["WITH_ISSUES", "DISAPPROVED"]),
    })
    ins7 = fb_get_all(f"act_{ACCOUNT}/insights", {"level": "ad", "date_preset": "last_7d", "fields": "ad_id,spend"})
    spend7 = {r["ad_id"]: num(r.get("spend")) for r in ins7}
    found = find_issues(ads, spend7)

    # 2) сьогодні по оголошеннях
    ins_today = fb_get_all(f"act_{ACCOUNT}/insights", {
        "level": "ad", "date_preset": "today",
        "fields": "ad_id,ad_name,campaign_id,campaign_name,spend,actions"})
    found += find_no_buy(ins_today)
    spend_today = sum(num(r.get("spend")) for r in ins_today)
    by_camp = {}
    for r in ins_today:
        by_camp[r["campaign_id"]] = by_camp.get(r["campaign_id"], 0) + num(r.get("spend"))
    found += find_overspend(spend_today, cap)

    # 3) бюджети живих кампаній (CBO) і груп (ABO)
    camps = fb_get_all(f"act_{ACCOUNT}/campaigns", {
        "fields": "id,name,effective_status,daily_budget",
        "effective_status": json.dumps(["ACTIVE"])})
    asets = fb_get_all(f"act_{ACCOUNT}/adsets", {
        "fields": "id,name,campaign_id,effective_status,daily_budget",
        "effective_status": json.dumps(["ACTIVE"])})
    parts, live_ids = [], {c["id"] for c in camps}
    for c in camps:
        c["_budget_uah"] = num(c.get("daily_budget")) / 100
        if c["_budget_uah"] > 0:
            parts.append((c["name"], c["_budget_uah"]))
    for s in asets:
        b = num(s.get("daily_budget")) / 100
        if b > 0 and s.get("campaign_id") in live_ids:
            parts.append((s["name"], b))
            for c in camps:
                if c["id"] == s["campaign_id"]:
                    c["_budget_uah"] = c.get("_budget_uah", 0) + b
    budget_sum = sum(v for _, v in parts)
    found += find_budget_cap(budget_sum, cap, parts)
    found += find_stall(camps, by_camp, now_k)
    log(f"  спенд сьогодні {spend_today:,.0f} ₴ · бюджети {budget_sum:,.0f} ₴ · живих кампаній {len(camps)} · тривог {len(found)}")

    # 3а) мовчання режимів і завислі записи ad-director
    now_utc = datetime.now(timezone.utc)
    try:
        notes = sb_get("ad_journal", f"project=eq.{PROJECT}&action=eq.note&select=reason,at&order=at.desc&limit=300")
        stuck = sb_get("ad_journal", f"project=eq.{PROJECT}&status=eq.approved&select=id,action,entity_name,at")
        silent_found = find_silence(notes, now_utc) + find_stuck(stuck, now_utc)
        found += silent_found
        log(f"  журнал: рядків запуску {len(notes)} · approved {len(stuck)} · тривог {len(silent_found)}")
    except Exception as e:
        log(f"  ⚠ ad_journal: {e}")

    # 4) дедуп і відправка
    try:
        prev = {r["key"]: r for r in sb_get("ad_alerts", f"project=eq.{PROJECT}&resolved_at=is.null&select=*")}
    except Exception as e:
        log(f"  ⚠ ad_alerts: {e}"); prev = {}
    upserts, keys_now = [], set()
    for a in found:
        keys_now.add(a["key"])
        p = prev.get(a["key"])
        due = (p is None or not p.get("last_sent") or
               now_utc - datetime.fromisoformat(p["last_sent"]) >= timedelta(hours=REMIND_HOURS))
        send = due and (not quiet or a["kind"] == "overspend")
        sent = tg(a["message"]) if send else False
        upserts.append({"key": a["key"], "project": PROJECT, "kind": a["kind"], "entity_id": a["entity_id"],
                        "entity_name": a["entity_name"], "message": a["message"], "last_seen": now_utc.isoformat(),
                        "resolved_at": None,
                        **({"first_seen": now_utc.isoformat()} if p is None else {}),
                        **({"last_sent": now_utc.isoformat(), "sent_count": (p or {}).get("sent_count", 0) + 1} if sent else {})})
    # вирішені: були відкриті, зараз не знайдені (для денних ключів лише в межах того ж дня)
    for k, p in prev.items():
        if k in keys_now or k.startswith("calendar:"):
            continue
        same_day = k.endswith(f"{now_k:%Y-%m-%d}")
        if p.get("last_sent") and not quiet and (p["kind"] in ("issue", "silent", "stuck") or same_day):
            tg(f"✅ <b>Вирішено</b>: {esc(p.get('entity_name'))} ({p.get('kind')})")
        upserts.append({"key": k, "project": PROJECT, "kind": p["kind"], "resolved_at": now_utc.isoformat()})
    sb_upsert("ad_alerts", upserts, "key")

    # 5) календар першим запуском дня
    # 08.10.2026: вимкнено за замовчуванням. План дня тепер шле в робочі чати команди
    # pg_cron 'general-team-digest-morning' → general_team_digest_enqueue() (dreamcar-team, міграція 042),
    # з аудиторіями, багатоденними акціями, розсилками і шапкою. CALENDAR_DM=1 повертає старий DM.
    if not quiet and os.getenv("CALENDAR_DM", "") == "1":
        ck = f"calendar:{now_k:%Y-%m-%d}"
        try:
            done = sb_get("ad_alerts", f"key=eq.{ck}&select=key")
        except Exception:
            done = [1]
        if not done:
            frm = (now_k.replace(hour=0, minute=0, second=0, microsecond=0)).astimezone(timezone.utc)
            to = frm + timedelta(days=2)
            # UTC як ...Z: "+00:00" у query string перетворюється на пробіл → PostgREST 400
            evs = sb_get("ad_events", f"project=eq.{PROJECT}&status=neq.cancelled"
                                      f"&starts_at=gte.{frm:%Y-%m-%dT%H:%M:%SZ}&starts_at=lt.{to:%Y-%m-%dT%H:%M:%SZ}"
                                      f"&order=starts_at&select=title,starts_at,status")
            msg = calendar_msg(evs, now_k)
            if msg and tg(msg):
                sb_upsert("ad_alerts", [{"key": ck, "project": PROJECT, "kind": "calendar", "message": msg,
                                         "last_sent": now_utc.isoformat(), "sent_count": 1,
                                         "resolved_at": now_utc.isoformat()}], "key")
    log("  готово")
    return 0


if __name__ == "__main__":
    sys.exit(main())
