// anomaly-alerter v6 — 15.09.2026 (аудит): алерт більше не плутає «немає продажів»
// з «немає даних», і більше не радить вимикати рекламу.
//
// Що сталося 15.09.2026 о 19:27: ETL MySQL впав о 18:30 (таймаут upsert), і
// dashboard_deals замерз. Алерт побачив 0₴ за 2 години, ads spend 19 516₴ і
// надіслав «негайно призупинити всі рекламні кампанії». Насправді продажі йшли:
// власний трекер чекауту за ті самі 2 години показував 166 подій і 46 оплат.
// Порада коштувала б вечора продажів на живому трафіку.
//
// v6:
//   1) freshness-гейт: якщо dashboard_deals не оновлювались > 25 хв — це алерт
//      про ETL, а не про виручку. У тексті — чи йдуть продажі за checkout_events.
//   2) ШІ більше не пише рекомендацій про гроші: заборонено радити паузу,
//      зупинку чи зниження бюджетів. Тільки перший діагностичний крок.
//   3) той самий гейт для ROAS-алерта: на застарілих даних ROAS беззмістовний.
// v5 — 08.08.2026 (аудит): прибрано спалений FALLBACK_SECRET і небезпечний prefix-match; dry під секретом.

import "jsr:@supabase/functions-js/edge-runtime.d.ts";
import { createClient } from "jsr:@supabase/supabase-js@2";

const SB_URL = Deno.env.get("SUPABASE_URL")!;
const SB_KEY = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const ANTHROPIC_API_KEY = Deno.env.get("ANTHROPIC_API_KEY")!;
const TG_BOT_TOKEN = Deno.env.get("TG_BOT_TOKEN")!;
const VADYM_TG = Deno.env.get("DC_VADYM_TG_CHAT_ID") || "1138351072";
const CRON_SECRET = Deno.env.get("DC_CRON_SECRET") || Deno.env.get("CRON_SECRET") || Deno.env.get("HQ_CRON_SECRET") || "";
const MODEL = "claude-haiku-4-5-20251001";

// Скільки хвилин застою dashboard_deals вважаємо поломкою даних, а не тишею продажів.
// ETL MySQL ходить щогодини (pg_cron 30 * * * *) + резерв, тож 25 хв — це вже підозра,
// а 70 хв — точно пропущений прогін.
const STALE_WARN_MIN = 25;

const sb = createClient(SB_URL, SB_KEY, { auth: { persistSession: false } });

async function tgSend(text: string): Promise<void> {
  await fetch(`https://api.telegram.org/bot${TG_BOT_TOKEN}/sendMessage`, { method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify({ chat_id: VADYM_TG, text, parse_mode: 'HTML', disable_web_page_preview: true }) });
}

async function shouldAlert(key: string, intervalHours: number): Promise<boolean> {
  const { data } = await sb.from('dashboard_settings').select('value').eq('key', `anomaly_alert_${key}`).maybeSingle();
  if (!data?.value) return true;
  const last = new Date(typeof data.value === 'string' ? data.value : (data.value as any)?.sent_at || 0);
  return Date.now() - last.getTime() > intervalHours * 3600 * 1000;
}
async function markAlertSent(key: string): Promise<void> { await sb.from('dashboard_settings').upsert({ key: `anomaly_alert_${key}`, value: new Date().toISOString() }, { onConflict: 'key' }); }

async function rangeRevenue(startIso: string, endIso: string): Promise<number> {
  const { data } = await sb.from('dashboard_deals').select('amount').gte('created_at', startIso).lte('created_at', endIso).eq('status', 'pay').limit(20000);
  return (data || []).reduce((s: number, d: any) => s + Number(d.amount || 0), 0);
}
async function rangeAdsSpend(day: string): Promise<number> {
  const { data } = await sb.from('dashboard_ads_data').select('spend').eq('date_start', day).limit(5000);
  return (data || []).reduce((s: number, a: any) => s + Number(a.spend || 0), 0);
}

// v6: наскільки свіжі дані про угоди. null = не вдалось прочитати.
async function dealsStaleMinutes(): Promise<number | null> {
  const { data } = await sb.from('dashboard_deals').select('created_at').order('created_at', { ascending: false }).limit(1);
  const last = data?.[0]?.created_at;
  if (!last) return null;
  return Math.round((Date.now() - new Date(last).getTime()) / 60000);
}

// v6: незалежне від ETL джерело правди — трекер чекауту на самому сайті.
// Якщо тут є успішні оплати, а в dashboard_deals нуль — зламані дані, а не продажі.
async function checkoutSuccessLast2h(): Promise<number | null> {
  const since = new Date(Date.now() - 2 * 3600 * 1000).toISOString();
  const { count, error } = await sb.from('checkout_events')
    .select('id', { count: 'exact', head: true })
    .gte('ts', since).eq('step', 'success');
  if (error) return null;
  return count ?? 0;
}

async function aiExplain(context: string): Promise<string> {
  const r = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: { 'x-api-key': ANTHROPIC_API_KEY, 'anthropic-version': '2023-06-01', 'content-type': 'application/json' },
    body: JSON.stringify({
      model: MODEL, max_tokens: 150,
      // v6: заборона радити дії з грошима. Алерт може помилятись (і вже помилявся),
      // тож його перший крок — перевірка, а не незворотна дія на живому трафіку.
      system: 'Ти — ops-алерт бот DreamCar. Поверни ОДНЕ речення українською: який перший '
            + 'ДІАГНОСТИЧНИЙ крок зробити, щоб зрозуміти причину. СУВОРО ЗАБОРОНЕНО радити '
            + 'зупиняти, паузити, вимикати або зменшувати рекламу чи бюджети — рішення про '
            + 'гроші приймає лише людина. Не вигадуй причину, якої немає у вхідних даних.',
      messages: [{ role: 'user', content: context }],
    }),
  });
  if (!r.ok) return '';
  return (await r.json()).content?.[0]?.text || '';
}

Deno.serve(async (req) => {
  const url = new URL(req.url);
  const dry = url.searchParams.get('dry') === '1';
  const h = req.headers.get('x-cron-secret') ?? req.headers.get('x-hq-cron-secret');
  if (!CRON_SECRET) return new Response(JSON.stringify({ error: 'secret not configured' }), { status: 500 });
  if (h !== CRON_SECRET) return new Response(JSON.stringify({ error: 'unauthorized' }), { status: 401 });

  try {
    const alerts: any[] = [];
    const now = new Date();
    const today = now.toLocaleDateString('sv-SE', { timeZone: 'Europe/Kyiv' });

    const stale = await dealsStaleMinutes();
    const dataStale = stale !== null && stale > STALE_WARN_MIN;

    const ago2h = new Date(now.getTime() - 2 * 3600 * 1000).toISOString();
    const last2hRev = await rangeRevenue(ago2h, now.toISOString());
    const todaySpend = await rangeAdsSpend(today);

    if (last2hRev === 0 && todaySpend > 500) {
      if (dataStale) {
        // v6: це поломка даних, а не продажів. Окремий алерт, окремий ключ дедупу.
        if (await shouldAlert('deals_stale', 2)) {
          const checkoutOk = await checkoutSuccessLast2h();
          const salesLine = checkoutOk === null
            ? 'Трекер чекауту прочитати не вдалось.'
            : (checkoutOk > 0
                ? `Трекер чекауту за ці 2 години: <b>${checkoutOk} успішних оплат</b> — продажі ЙДУТЬ.`
                : 'Трекер чекауту теж показує 0 успішних оплат за 2 години.');
          const text = [
            `🟠 <b>ALERT — дані про угоди застаріли (${stale} хв)</b>`,
            ``,
            `dashboard_deals не оновлювалась ${stale} хв, тож «0₴ виручки» — це наслідок, а не причина.`,
            salesLine,
            ``,
            `<b>Рекламу НЕ чіпати.</b> Перевір прогін «ETL MySQL → Supabase» в Actions.`,
          ].join('\n');
          if (!dry) { await tgSend(text); await markAlertSent('deals_stale'); }
          alerts.push({ key: 'deals_stale', text });
        }
      } else if (await shouldAlert('zero_revenue_2h', 4)) {
        const checkoutOk = await checkoutSuccessLast2h();
        const ctx = `DreamCar: за останні 2 години 0₴ revenue. Сьогодні ads spend = ${todaySpend.toFixed(0)}₴. `
                  + `Дані свіжі (${stale ?? '?'} хв). Трекер чекауту за ці 2 години: `
                  + `${checkoutOk === null ? 'невідомо' : checkoutOk + ' успішних оплат'}.`;
        const ai = await aiExplain(ctx);
        const text = `🔴 <b>ALERT — Revenue завис (2h нуль)</b>\n\n${ctx}${ai ? '\n\n' + ai : ''}`;
        if (!dry) { await tgSend(text); await markAlertSent('zero_revenue_2h'); }
        alerts.push({ key: 'zero_revenue_2h', text });
      }
    }

    // v6: на застарілих даних ROAS беззмістовний — не рахуємо і не шлемо.
    if (!dataStale) {
      const yest = new Date(now.getTime() - 86400000).toLocaleDateString('sv-SE', { timeZone: 'Europe/Kyiv' });
      const yestStart = new Date(Date.parse(yest + 'T00:00:00+03:00')).toISOString();
      const yestEnd = new Date(Date.parse(yest + 'T23:59:59+03:00')).toISOString();
      const yestRev = await rangeRevenue(yestStart, yestEnd);
      const yestSpend = await rangeAdsSpend(yest);
      const yestROAS = yestSpend > 0 ? yestRev / yestSpend : 0;

      let weekRev = 0, weekSpend = 0;
      for (let i = 2; i <= 8; i++) {
        const d = new Date(now.getTime() - i * 86400000).toLocaleDateString('sv-SE', { timeZone: 'Europe/Kyiv' });
        const ds = new Date(Date.parse(d + 'T00:00:00+03:00')).toISOString();
        const de = new Date(Date.parse(d + 'T23:59:59+03:00')).toISOString();
        weekRev += await rangeRevenue(ds, de);
        weekSpend += await rangeAdsSpend(d);
      }
      const weekROAS = weekSpend > 0 ? weekRev / weekSpend : 0;

      if (yestROAS > 0 && weekROAS > 0 && (yestROAS / weekROAS) < 0.5) {
        if (await shouldAlert('roas_drop', 23)) {
          const drop = (((yestROAS - weekROAS) / weekROAS) * 100).toFixed(1);
          const ctx = `DreamCar: ROAS вчора ${yestROAS.toFixed(2)}, 7-day avg ${weekROAS.toFixed(2)}, падіння ${drop}%.`;
          const ai = await aiExplain(ctx);
          const text = `🔴 <b>ALERT — ROAS впав</b>\n\n${ctx}${ai ? '\n\n' + ai : ''}`;
          if (!dry) { await tgSend(text); await markAlertSent('roas_drop'); }
          alerts.push({ key: 'roas_drop', text });
        }
      }
    }

    return new Response(JSON.stringify({
      ok: true, dry, version: 'v6-freshness-gate',
      deals_stale_minutes: stale, data_stale: dataStale,
      alerts_triggered: alerts.length, alerts: alerts.map(a => a.key),
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });
  } catch (e: any) {
    console.error('[anomaly-alerter v6]', e);
    return new Response(JSON.stringify({ error: String(e?.message || e) }), { status: 500 });
  }
});
