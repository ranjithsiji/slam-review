# Science Slam audience review system

Live audience and judge reviews for the Kerala Science Slam (4 regional slams and a final). Attendees check in by scanning the QR code on their badge and then score each performance on their phone while its review window is open. Admins run the event timeline live, and volunteers handle check-in at the desk. The system computes weighted marks and keeps immutable mark snapshots.

The full specification is in [CLAUDE.md](CLAUDE.md).

## Stack

| Part | Choice |
|---|---|
| Backend | PHP 8.2+ and CodeIgniter 4.7, organised as feature modules (see below) |
| Database | MySQL / MariaDB (InnoDB, utf8mb4), with CHECK and foreign-key constraints |
| UI | [Tabler](https://tabler.io) (Bootstrap 5.3), Tabler Icons as an SVG sprite, self-hosted Inter and Noto Sans Malayalam |
| Live screens | Vue 3 (no router, not a SPA), built with Vite into `public/assets/` |
| Admin widgets | [vue-tabler](https://github.com/dfpc-coe/vue-tabler): `Modal`, `Input` and `Loading`, imported one at a time through the `@vue-tabler` alias |
| Tables | DataTables 3.1 + Bootstrap 5 + Responsive, all server-side. **jQuery is not used anywhere**: DataTables 3 and its Responsive extension import no jQuery (checked at build time) |
| QR / PDF | `chillerlan/php-qrcode` (SVG/PNG plus a decoder used in the tests) and `mpdf/mpdf` |
| Charts | Chart.js (statistics screen) |

Node is needed only to build the frontend. Deploy the built `public/assets/`; the server never needs Node.

## Module layout

Each feature lives in `modules/{Module}/`, which contains `Config/Routes.php`, `Controllers`, `Services`, `DataTables`, `Views`, `Database/Migrations` and, where needed, `Commands`. CodeIgniter discovers routes, migrations, services and commands from every registered namespace (`app/Config/Autoload.php`).

| Module | Responsibility |
|---|---|
| Core | Clock (server time, display timezone), settings page, Actor, transaction/lock helpers, filters, DataTableService, layouts, view helpers, PDF factory, demo seeder |
| ActivityLog | Append-only `activity_log`, its writer service and the viewer |
| Accounts | Admin and volunteer login, login throttling, volunteer accounts |
| Slams | Slam details and times, the **phase state machine** (`SlamPhaseService`), review and edit windows, the control panel, cron tick |
| Schedule | Schedule maker: drafts, versions, re-flow, generate wizard, run-sheet |
| Presenters | Presenters (reusable across slams), performances, photo uploads |
| Attendees | Badge IDs, CSV import, QR codes, badge PDFs, ID reissue |
| Checkin | Public check-in pages, volunteer desk, force checkout, late entry, monitor |
| Reviews | Questions, review submit and revise, the reviewer screen, the live-state document |
| Scoring | Three-group weighted marks with a calculation breakdown, audience representatives (random draw + admin edits), mark runs (snapshots), statistics |
| PublicSite | Public schedule pages, JSON and `.ics` |

The frontend mirrors this layout: `frontend/modules/{reviewer,volunteer,control,schedule,questions}`, with shared code in `frontend/shared` and the theme in `frontend/theme`. The semantic colour and icon maps are `frontend/theme/semantic.json` and `icons.json`; the build copies them to `public/assets/ui-map.json` so PHP views use the same maps.

## Local setup

```bash
composer install
npm install
cp env.example .env              # then edit the database settings; set CI_ENVIRONMENT = development
php spark key:generate

# Database (as a MariaDB admin): creates slam + slam_test and the app user
sudo mariadb < database/create_databases.sql

php spark migrate --all
php spark db:seed 'Modules\Core\Database\Seeds\DemoSeeder'
npm run build                    # or: npm run dev (rebuilds on change)
php spark serve                  # http://localhost:8080
```

Demo accounts created by the seeder (**change them before any real use**):

| Role | Sign-in | Credentials |
|---|---|---|
| Admin | `/admin/login` | `admin` / `ChangeMe-2026!` |
| Volunteer (demo slam) | `/volunteer/login` | `volunteer1` / `Volunteer-2026!` |
| Reviewer | `/checkin` | any badge ID from the attendee list (the check-in gate must be open) |

The demo slam is set for tomorrow. To try the live flow now, edit the slam's times so the gates are open, or use **Control panel → Advance now**.

Create real admins with `php spark slam:create-admin <username> "<Full name>"`.

### Tests

```bash
vendor/bin/phpunit
```

The suite runs against the `database.tests.*` connection (`slam_test`), which is wiped on every run. There are 66 tests (797 assertions) covering the phase transition table and the automatic timeline (gate, windows, changeover, running late, hold, manual mode, hard stop, readiness), check-in rules (gate, single device, throttling, forced checkout, desk claim, late override), review submission (window boundaries, idempotency, 1–10 validation, question locking), revision, schedule validation, re-flow, reorder preview, 409 conflicts, live-mode locks, seeded shuffle and break extension, scoring (5-mark questions scaled to 10, the three groups, re-normalisation, ties, the representative draw and edits, mark runs and recalculation), timezone and 12/24-hour settings, CSV import (BOM, `;`, duplicates, both modes, 5,001 rows), QR decoding, Malayalam in badge PDFs, and the DataTable contract (paging cap, allow-lists, injection strings, volunteer scope, exports).

### Load test

The design target is 500–1,000 phones per slam polling every ~4 s (see CLAUDE.md, *Live polling design*).

```bash
php spark slam:loadtest-prepare 1000                       # throw-away slam + tests/load/badges.json
k6 run -e BASE=https://staging.example -e VUS=1000 tests/load/reviewers.js
```

Open a review window from the control panel during the run. Pass criteria: p95 state poll under 300 ms, under 1 % errors.

**Not yet run under k6.** A local measurement on the single-process dev server gave about 4 ms of server time per state poll (p95 5.2 ms), with 95 % answered as `304 Not Modified`. That suggests about one CPU core of PHP-FPM per 1,000 phones, but the k6 run against a production-like server is still to do.

## Deployment

1. Build on a developer machine or in CI: `composer install --no-dev -o` and `npm ci && npm run build`.
2. Rsync the project, **including `public/assets/`** and excluding `node_modules/`, `.env` and `writable/*`.
3. Write a `.env` on the server from `env.example`. Use `CI_ENVIRONMENT = production`, set `app.baseURL` and `slam.checkinUrl` (the public domain printed in badge QR codes) and `cookie.secure = true`.
4. Point the web root at `public/`, and serve over HTTPS only.
5. Run `php spark migrate --all`.
6. Add the cron backup for the timeline:
   ```
   * * * * * cd /var/www/slam-review && php spark slam:tick >> writable/logs/cron.log 2>&1
   ```
   Correctness never depends on cron: every request checks the server clock, and polls apply due steps within seconds.
7. Uploaded photos live in `public/uploads/presenters/`. `public/uploads/.htaccess` disables PHP there for Apache. For nginx add:
   ```nginx
   location ^~ /uploads/ { location ~ \.php$ { deny all; } add_header X-Content-Type-Options nosniff; }
   ```
8. Optional: an nginx microcache of 1–2 s on `/api/public/`.
9. Keep sessions in files (the default) or Redis, never in the database.

### Database privileges

The activity log is append-only, so in production the app's database user should only be able to `INSERT` and `SELECT` on `activity_log`. MariaDB cannot revoke a single table from a database-wide grant, so grant per table:

```sql
REVOKE ALL PRIVILEGES ON slam.* FROM 'slam'@'localhost';
GRANT SELECT, INSERT ON slam.activity_log TO 'slam'@'localhost';
-- every other table: full DML
SELECT CONCAT('GRANT SELECT, INSERT, UPDATE, DELETE ON slam.`', table_name, '` TO ''slam''@''localhost'';')
  FROM information_schema.tables WHERE table_schema = 'slam' AND table_name <> 'activity_log';
-- run the generated statements; run migrations with a separate admin account
```

The app also needs `CREATE TEMPORARY TABLES`, because provisional marks are computed into a per-connection temporary table so that sorting and paging stay in SQL.

## Decisions and notes

- **Organiser layout** is Tabler's *fluid vertical*: dark sidebar (Participants: Reviewers, Judges, Audience representatives), top bar with Sign out, breadcrumbs, and `data-bs-navbar-position="combined"` on `<html>` (without it this Tabler version hides the sidebar whenever a top bar exists).
- **Performance vs. review.** A performance goes on stage first; its review can open while it is still on stage and runs on after it ends (`performing_started_at` / `performing_ended_at` on schedule items). The old control panel is replaced by the Status screen: a Now card plus a vertical timeline with one node per performance.
- **Status screen and live bar.** `/admin/slams/{id}/status` is a vertical timeline (green done, orange now, grey upcoming) with action buttons on each card, backed by the `control-state` API; a live bar on every organiser page (`/api/admin/live`) shows what is going on in the running slams.
- **Slams run manually by default.** Only the slam date/start is required; gate times, reviews start and event end are optional; a blank hard stop becomes 24 h after the start. Automatic mode uses the planned times.
- **Check-in rules.** Self check-in only while the gates are open, with a name + masked mobile verification step. After Gate Close, volunteers (own slam) and admins do a late check-in. Public `/registered` tells a person whether their mobile number is registered.
- **Judges' marks** are entered by an admin at the end of the slam (default) or given live on the phones (detailed criteria 3+3+3+1, or one overall mark); one decimal, no 0.

- **Final marks use three groups.** General reviewers (audience users who are not representatives), audience representatives and judges, each with its own weight (Mark configuration, must add up to 100). Representatives are drawn at random from the audience users who reviewed every opened performance (*Representatives* tab); the admin can then add or remove people. Each person counts in one group only. Groups without reviews drop out and the other weights are re-normalised. The Marks page and every mark run show the calculation per performance (average, weight, share, points).
- **Questions have a maximum mark.** Audience questions are 5 marks each (two questions = 10), judge questions 10 by default; both are editable on the Questions page until the first review of that audience. Each review mark is scaled to 10: `10 × Σ(weight × score ÷ max) ÷ Σ weight`.
- **Timezone and clock are settings** (Admin → Settings): default Asia/Kolkata and 24-hour, overridable in `.env` as `slam.timezone` / `slam.timeFormat`. The database stores UTC, so changing the zone changes how times are typed and shown, never when things happen. The page also shows the server clock and this device's drift.

- **UI: Tabler** (owner's choice), replacing the earlier Bootswatch Cosmo plan. CLAUDE.md has been updated to match.
- **Malayalam in PDFs uses the Manjari font.** mPDF cannot parse the GPOS tables of current Noto Sans Malayalam builds. Manjari (SMC, OFL) shapes conjuncts and vowel signs correctly. Web pages use Noto Sans Malayalam, which browsers shape correctly.
- **Rate limits and venue NAT.** A whole hall usually shares one public IP, so poll and review limits are applied per check-in session, while the per-IP limits (check-in, public pages) are sized for a full hall. Failed check-ins are throttled per ID (5 per 10 minutes, then a 15-minute lockout) and per IP (higher limits, configurable in `modules/Core/Config/Slam.php`).
- **Check-in on a phone after a desk check-in.** A late check-in by a volunteer or admin (after Gate Close) creates an *unclaimed* check-in. The attendee's phone claims it on its first badge scan. A second phone using the same badge is rejected and sent to the volunteer desk.
- **`tick()`** compares the clock with a cached next-due time and takes a non-blocking `GET_LOCK` only when something is due. Cron and admin actions use the blocking path. All phase writes hold the slam row lock.
- **Auto mode opens the edit window by itself** after All Review Close (for `edit_window_minutes`; 0 skips it). The control panel offers manual open and skip only in manual mode or while the slam is held.
- **Tables that are not DataTables.** Like the schedule maker, the review-questions editor is a Vue card list (it reorders and edits inline). The reviewer and public screens are card layouts, as the spec requires.
- **API paths.** Review-window and edit-window actions include the slam ID (`/api/slams/{id}/window/open`) instead of the spec's slam-less `/api/window/open`, so the slam scope is explicit.
- **The CodeIgniter debug toolbar is disabled**, because it injects inline scripts that the strict Content-Security-Policy blocks.

## Still open (from CLAUDE.md §13)

- Final wording of the questions; the seeded questions are placeholders.
- Durations: review, changeover, edit window and breaks (the seeded values are placeholders).
- Public domain for `APP_CHECKIN_URL`, and the physical badge size.
- Whether finalists flow automatically from the regionals into the final. For now the final is set up by hand, reusing presenters through the presenter pool or "Add existing presenter".
- Expected number of phones per slam (this sizes the polling interval and caching).
