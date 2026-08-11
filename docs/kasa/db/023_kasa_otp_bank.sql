-- =====================================================================
-- 023 — Каса: додати банк ОТП до безготівки (запит Вадима, 11.08.2026)
-- ОТП/ПУМБ не мають простого API як моно/Приват → операції заводяться
-- через імпорт виписки (CSV/XLSX), рахунки типу kind='bank'.
-- Розширюємо CHECK на bank, щоб дозволити 'otp'.
-- =====================================================================
alter table public.kasa_accounts drop constraint if exists kasa_accounts_bank_check;
alter table public.kasa_accounts add constraint kasa_accounts_bank_check
  check (bank = any (array['monobank'::text,'privatbank'::text,'pumb'::text,'otp'::text]));
