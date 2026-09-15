-- 20260916c_kasa_access_ceo_cfo.sql
-- 16.09.2026 — на прохання Вадима: додати йому доступ до Каси.
--
-- Каса (kasa_accounts / kasa_transactions / kasa_transfers / kasa_bank_creds) закривалась
-- функцією kasa_is_allowed() з ХАРДКОДОМ двох пошт. vg@abrisart.com там не було, тож
-- екран «Каса» під основним акаунтом CEO показував самі нулі — без жодного повідомлення,
-- що доступу немає (RLS віддає порожній результат, а не помилку).
--
-- Замість того, щоб додати третю пошту у хардкод, переводимо перевірку на ролі —
-- саме так уже зроблено на сусідніх kasa-таблицях (kasa_fop_alert_log, kasa_mono_queue
-- дивляться на current_user_has_role(['ceo','coo','cfo'])). Ролі беремо ceo + cfo:
--   vg@abrisart.com      -> ceo (Вадим)
--   1avrybak@gmail.com   -> cfo (Артем)
-- coo (smth.mario@gmail.com) НЕ додаємо: Каса — це рух грошей, окреме рішення власника.
--
-- Старий список пошт лишаємо як OR, щоб ніхто не втратив доступ:
-- dreamcarua@gmail.com у таблиці users із роллю не заведений, але логінитись ним могли.
--
-- current_user_has_role() — SECURITY DEFINER, тож читання users з-під RLS працює;
-- EXECUTE для authenticated у неї є.

CREATE OR REPLACE FUNCTION public.kasa_is_allowed()
RETURNS boolean
LANGUAGE sql
STABLE
AS $function$
  SELECT
    coalesce(lower(auth.jwt() ->> 'email'), '') IN (
      '1avrybak@gmail.com', 'dreamcarua@gmail.com', 'vg@abrisart.com'
    )
    OR public.current_user_has_role(ARRAY['ceo', 'cfo']::public.user_role[]);
$function$;

-- Перевірено після накату під vg@abrisart.com: Каса показує 27 рахунків і 6 банків —
-- рівно стільки ж, скільки бачить postgres. Операційний баланс 548 935 грн.
