# Handoff

Updated: 15.09.2026 23:55 CEST

## Task verbatim
«окей, далі?» — Вадим, 15.09. Продовження аудиту dashboard.dreamcar.ua після проходу
«канонічний платний трафік». Взято перший пункт черги: UTM-фільтри у серверних RPC.

## Constraints
- Фінансові RPC чіпати лише окремим проходом із заміром до/після (після інциденту 19:27).
- Пуш із контейнера заблокований проксі → тільки з Mac (`~/Developer/dreamcar-dashboard`).
- Репо публічне: жодних хостів, IP, ID чатів у файлах.

## Done
- Міграція `20260915h` — 8 RPC приймають `p_utm_source/medium/campaign/term/content`.
- Міграція `20260915i` — те саме для `dashboard_hourly_heatmap`.
- `dashboard_traffic_type_summary` переведено на `is_paid_placement(utm_medium)`
  (четверте визначення платного трафіку, пропущене у 20260915g).
- Знято `SET search_path` з `utm_eq` і `is_paid_placement` — без цього вони не
  інлайнились і давали 7–8× просадку.
- Фронт: `_rpcParams()` передає UTM; банер прибрано; `extParams = params`;
  теплокарта отримує повний набір; `dltCell()` замість `Number(null)→0`;
  `narrowAdsByUtm()` у 5 місцях.
- Коміти: `b2c2722`, `a79d878`, `51e0a5b`, `9649013`, `0ddd696`. Усі задеплоєні.

## Handed over, waiting
- нічого нового; відкриті рішення — у `tasks.md` (розділ ⏸).

## Did not work
- Перша версія `utm_eq()` порівнювала на рівність — розходилась із клієнтським
  `ilike '%x%'`. Замінено на ILIKE.
- `REVOKE ALL ... FROM PUBLIC` не закрив `_core` для `anon` (автогранти Supabase) —
  довелось перелічити ролі поіменно.

## Numbers and sources
- `utm_medium~instagram` 25.08–15.09: 1 055 лідів / 904 оплат / 349 542 ₴ · RPC = прямий SQL · 15.09
- `utm_term~fortunatos` 25.08–15.09: 749 / 630 / 232 290 ₴ · RPC = прямий SQL = UI · 15.09
- «Тип трафіка» було 1 877 платних / 619 714 ₴, стало 1 316 / 439 419 ₴ · різниця 180 295 ₴ (+41 %) · 15.09
- Заміри RPC після змін: kpi_summary 12.7 мс, kpi_with_delta 52.0, traffic_type_summary 23.8 · 15.09

## State now
Робоча. Нічого тимчасового не лишилось. Фільтри на дашборді скинуто.

## Next single action
Спитати Вадима, що з черги брати далі: поверхня SECURITY DEFINER (105 функцій для
`anon`), непроварені маршрути (Комбінації, Cohort, Webhook логи, Manual Costs,
Settings, гранулярність Година/Місяць, мобільна верстка) чи бізнесове —
перечитати ціль по ROAS на чесних 2,4–3,5×.
