-- =====================================================================
-- 022 — Каса: книги «Крипта» та «Інше» (доходи поза банківськими рахунками)
-- Запит Вадима, 10.08.2026.
-- kind вже text (bank/cash/dividends) → додаємо 'crypto' і 'other' без зміни enum.
-- currency вже є на kasa_accounts. Додаємо qty на транзакції — кількість крипти
-- (сума в крипті), amount_uah лишається гривневою оцінкою (сумується в баланс Каси).
-- =====================================================================

alter table public.kasa_transactions
  add column if not exists qty numeric;   -- кількість у крипті (для kind='crypto'); null для UAH

comment on column public.kasa_transactions.qty is
  'Кількість у валюті рахунку (крипта). amount_uah — гривнева оцінка. Для UAH-книг null.';
