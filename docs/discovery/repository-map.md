# Repository Map

> Milestone 0 — Discovery. Source: uploaded archive `14050813.zip` (563 files), extracted and committed as local git baseline `30d3577`.
> Every statement below was verified against files in the archive on 2026-11-04 unless marked otherwise.
> **No live server, database or provider was available in this session.**

## 1. Technology stack (verified from files)

| Item | Finding | Evidence |
|---|---|---|
| Language | PHP, no framework. Linted clean with PHP 8.3.6 CLI (338 files, 0 syntax errors). Minimum supported version not declared for the core. | `php -l` run in session |
| Package manager | None for core ("raw PHP, no composer" per `_CONTINUE_GUIDE.md`). `crm/` has `composer.json` + `composer.lock` + committed `crm/vendor/` (1 MB). | `crm/composer.json` |
| Database | MySQL/MariaDB via PDO (`core/Database.php`), InnoDB, utf8mb4. Single shared database for Elanban core **and** CRM. | `core/Database.php`, `crm/config.php` (`DB_*` taken from parent `config.php`) |
| Web server | Apache assumed (`.htaccess` ×8). | `.htaccess` |
| Frontend | Server-rendered PHP views + vanilla JS (`views/pages/*.php` + `*.js.php`, `assets/*.js`). Embeddable widgets: `widget.js`, `eb-embed.js`, `eb-pulse.js`, `notice.js`. | `views/`, root JS |
| Scheduler | Single HTTP/CLI cron entry `cron.php` (+ `crm/cron_sync.php`, `crm/cron/transcribe_calls.php`). | `cron.php` |
| Tests | **None.** No test directory, no PHPUnit config, no CI. Prior practice: bracket-balance and regex checks in Python (`_CONTINUE_GUIDE.md`). | repo search |
| Version | `ELB_VERSION = 2.1.4-jk` (Jakojast build). Patch notes up to `PATCH-2.1.1.md`. | `index.php:35` |
| Localisation | Persian UI, RTL, Jalali dates (`toFa`, `crm/core/Jalali.php`), timezone `Asia/Tehran` hard-coded in CRM. | `crm/config.php` |

## 2. Size by layer (non-vendor PHP)

| Directory | Files | Lines | Role |
|---|---:|---:|---|
| `core/` | 10 | 2,309 | DB, Auth, Schema/migrations, CSRF, cache, viewer role, URL, TZ |
| `channels/` | 20 | 3,537 | Channel drivers behind `ChannelInterface` |
| `modules/` | 74 | 24,626 | Business logic |
| `api/` | 12 | 4,452 | JSON API (`?api=NAME`), 442 action cases |
| `views/` | 62 | 13,209 | Admin panel pages |
| `webhook/` | 7 | 1,107 | Inbound provider webhooks |
| `crm/` | 70 | 31,065 | Jakojast CRM (calls, leads, hosts, staff, payroll, tasks…) |
| `cli/` | 2 | 64 | Licence tooling |
| Root entry points | ~20 | — | `index.php`, `cron.php`, `chat.php`, `r.php`, `media.php`, `install.php` (115 KB), OAuth callbacks, embed feeds |

## 3. Entry points

| Entry | Purpose | Auth |
|---|---|---|
| `index.php?page=` | Admin panel (28 whitelisted pages) | Session (`Auth::requireLogin`) |
| `index.php?api=` | JSON API → `api/handler.php` (+ `api/*.php` includes) | Session + CSRF on POST + `Viewer::gateApi` |
| `index.php?export=` | CSV export | Session + CSRF + viewer block |
| `crm/index.php`, `crm/api.php` (`/api/{resource}`) | CRM SPA + REST-ish API (32 resources) | Bearer token (`auth_tokens`, hashed) bridged to `eb_users` |
| `webhook/receive.php` | Bale/Telegram/Rubika/SMS inbound | Per-channel secret |
| `webhook/roboclick.php`, `webhook/apichi.php` | Instagram via RoboClick / YarAPI | HMAC-SHA256 + timestamp |
| `webhook/directsaz*.php`, `webhook/connector.php` | Directsaz Instagram, generic connectors | Token / HMAC |
| `crm/webhook.php` | Telefonchy call events | **Token logged but not enforced** (see risks R-03) |
| `chat.php`, `widget.js` | Public website live-chat | Public, session-scoped |
| `eb-feed.php`, `eb-pulse.php`, `notices.php` | Public embeddable widgets | Public |
| `r.php` | Tracked short-link redirect | Public |
| `api/internal_lookup.php`, `crm-bridge.php`, `crm/api_external/elanban_lookup.php` | Server-to-server Jakojast ⇄ Elanban ⇄ CRM | Shared HMAC/token |
| `cron.php`, `crm/cron_sync.php` | Scheduled work | `?key=` — **fails open if key unset** (R-06) |
| `install.php` | Installer | Must be removed/locked after install (unverified on server) |

## 4. Documentation found in the archive

| Document | Topic |
|---|---|
| `README.md`, `README_تغییرات.md`, `FEATURES.md` (80 KB), `REFERENCE.md` (104 KB), `API_FUNCTIONS.md` | Elanban product & internal API reference |
| `ELANBAN_GUIDE.md` (102 KB) | Elanban (the "Elam-Ban" notification system) user/admin guide |
| `_CONTINUE_GUIDE.md`, `_PROJECT_SETUP.md`, `ROADMAP.md` | Working method, architecture summary, roadmap |
| `CHANGELOG_*.md`, `PATCH-*.md`, `UPGRADE_*.md` | Change history 3.9 → 2.1.1-jk |
| `crm/docs/TELEFONCHY_API.md` | **Telefonchy ("Telephonechi") integration reference** (base URL `https://panel.telefonchy.com/webservice/v1`, endpoints, webhook payloads verified against raw samples) |
| `crm/docs/PROJECT_OVERVIEW.md`, `crm/README.md`, `crm/GUIDE.md`, `crm/docs/CRM_ANALYSIS_20-PERSON-TEAM.md` | Jakojast CRM overview and a prior security/scale review |
| `crm/docs/*TRANSCRIPTION*.md` | Call transcription (Vosk) setup |

Supplied separately in this session: `YarAPI` developer guide (Persian), `roboclick` capability/API document (Persian, partial — 3 panel tabs missing), the Enterprise master prompt. `https://telefonchy.com/help` could **not** be fetched (blocked by session network policy).

## 5. Data/runtime material shipped inside the archive (confidential)

`config.php` (live DB password, `app_key`, Jakojast integration tokens), `uploads/` (~15 MB customer media), `crm/logs/error.log` (2.4 MB), `logs/cache/*.cache` (63 files), `crm/logs/.telefonchy_*`. These were **excluded from git** via `.gitignore` and were not copied into any generated document. See risk R-01.
