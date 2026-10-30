-- 30.10.2026 · #23 Range Rover Autobiography: новий формат індексу платежу.
-- Було:  DCI-bmw330-6955-trial-20261026-0120-d84c   (slug проєкту)
-- Стало: DCI-p23-18120-basic-20261029-1730-20b3     (p<номер циклу>)
-- Угода в SendPulse (Проект_deal), за словами Вадима: «range-10-30»; перші тестові угоди прийшли з «RANGE ROVER».
-- Застосовано на wotghlaehnvxyeacznvv 30.10.2026 (5 тестових угод по 10 грн перемічено на RANGE ROVER AUTOBIOGRAPHY).

-- 1. Тригер: загальне правило DCI-p<N>- → launches.cycle_no = N → перший deal_alias.
--    Більше не треба дописувати префікс на кожен новий цикл (див. traps: «префікса №20 тут не було»).
--    Регулярка вимагає цифри після p, тож старі DCI-promax-/DCI-phone… не зачіпаються
--    (перевірено: 0 історичних угод під ~* 'DCI-p[0-9]+-').
create or replace function public.tg_dashboard_deals_normalize_project()
 returns trigger
 language plpgsql
 set search_path to 'public', 'extensions', 'pg_temp'
as $function$
declare
  dn text := coalesce(NEW.raw_payload->>'deal_name', NEW.raw_payload->>'name', NEW.raw_payload->>'model', '');
  dt date := coalesce(
      to_date(nullif(substring(coalesce(NEW.raw_payload->>'deal_name',''), 'DCI-[^-]+-[^-]*-[^-]*-(\d{8})'), ''), 'YYYYMMDD'),
      NEW.paid_at::date, NEW.created_at::date, current_date);
  cyc int := nullif(substring(dn from '(?i)DCI-p([0-9]+)-'), '')::int;
  lbl text;
begin
  if cyc is not null then
    select l.deal_aliases[1] into lbl
    from launches l
    where l.cycle_no = cyc and l.deal_aliases is not null and array_length(l.deal_aliases,1) > 0
    order by l.starts_at desc nulls last
    limit 1;
    if lbl is not null then
      NEW.project := lbl;
    end if;
    return NEW;
  end if;

  if dn ilike '%DCI-iphone-%' then
    if dt >= date '2026-07-02' and dt <= date '2026-07-05' then
      NEW.project := 'IPHONE 17 PRO MAX 2';
    elsif dt < date '2026-07-02' then
      NEW.project := 'IPHONE 17 PRO MAX';
    end if;
  elsif dn ilike '%DCI-promax-%' then
    NEW.project := 'IPHONE 17 PRO MAX 2';
  elsif dn ilike '%DCI-moto-%' then
    NEW.project := 'MOTORCYCLE';
  elsif dn ilike '%DCI-hummer-%' then
    NEW.project := 'HUMMER H2';
  elsif dn ilike '%DCI-audi-%' then
    NEW.project := 'AUDI E-TRON';
  elsif dn ilike '%DCI-bmwx6m-%' then
    NEW.project := 'BMW X6M';
  end if;
  return NEW;
end;
$function$;

-- 2. Обидва реєстри: мітки угоди з CRM у всіх написаннях. Канонічна мітка лишається першою.
update launches
set deal_aliases = array['RANGE ROVER AUTOBIOGRAPHY','RANGE-10-30','RANGE 10-30','RANGE ROVER','RANGE'],
    updated_at = now()
where id = 'f5c91486-b53f-4096-8049-d4d2ca8f54f2';

update dashboard_projects
set deal_project_values = array['RANGE ROVER AUTOBIOGRAPHY','RANGE-10-30','range-10-30','RANGE 10-30','RANGE ROVER','RANGE'],
    updated_at = now()
where code = 'range';

-- 3. Перемітити вже наявні угоди #23 (UPDATE запускає тригер).
update dashboard_deals set raw_payload = raw_payload where raw_payload->>'deal_name' ilike 'DCI-p23-%';
