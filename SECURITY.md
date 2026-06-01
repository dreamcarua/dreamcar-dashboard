# Security Policy

## ⚠️ History reminder

Original repo (`cemahalexandr/dashboard-dreamcar`) contained leaked secrets у git history.
**Цей репозиторій — clean copy без commit history**. Старі secrets вважати скомпрометованими:

| Type | Status | Action |
|---|---|---|
| `OPENAI_API_KEY` (sk-proj-elMu…) | 🔴 Compromised | **REVOKED** 2026-06-01 |
| `GITHUB_PAT` (ghp_ke29HtqV…) | 🔴 Compromised | **REVOKED** 2026-06-01 |
| `DB_PASS` (A8Ci4dC79n@fincheck) | 🔴 Compromised | **ROTATE на MySQL** + update у env обох серверів |
| `SSH_PASSWORD` (dA3lvB2tqW@serflow) | 🔴 Compromised | **ROTATE на ukraine.com.ua** |
| `TELEGRAM_BOT_TOKEN` (1769…AAHh…) | 🔴 Compromised | **REVOKE через @BotFather** + новий токен |
| `GITHUB_WEBHOOK_SECRET` | 🟠 Rotate | Перегенерувати у GitHub repo settings |

## How to onboard

1. Скопіюй `.env.example` → `.env`
2. Заповни справжніми значеннями (зі сховища паролів, **не з історії git**)
3. У production сервері — клади `.env` поряд з `config/app_config.php` або експортуй у webserver env
4. Перевір що `.env` у `.gitignore` (вже доданий)

## How to add new secrets

```php
// ❌ ПОГАНО — hardcode у config файл (буде закомічено)
define('NEW_API_KEY', 'sk-real-key-here');

// ✅ ДОБРЕ — getenv() з .env
define('NEW_API_KEY', getenv('NEW_API_KEY') ?: '');
```

Потім додай у `.env.example` (з порожнім value) щоб інші розробники знали що треба.

## If you accidentally commit a secret

1. **REVOKE** ключ негайно на провайдері (OpenAI/GitHub/Telegram/etc)
2. **Видали** з working tree + commit fix
3. **НЕ** покладайся на `git filter-branch` чи BFG — секрет уже у GitHub архіві + cache + clone-ах
4. Перегенеруй новий ключ і поклади у `.env` (не в код)

## Reporting

Знайшов вразливість? → vg@abrisart.com (приватно, не Issue)
