-- anomaly-alerter (job 95) біг о :27, за 3 хв ДО щогодинного ETL (job 75, :30):
-- heartbeat у цей момент ~57 хв при порозі 25 хв = хибний «дані застаріли».
-- Rollback: select cron.alter_job(95, schedule => '27 * * * *');
select cron.alter_job(95, schedule => '40 * * * *');
