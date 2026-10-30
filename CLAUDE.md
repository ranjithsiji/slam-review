# CLAUDE.md — Science Slam Audience Review System

Guidance for Claude Code when building and maintaining this project.

## 1. Project summary

A live audience-review system for **Science Slam** events (4 regional slams + 1 final). Audience members and judges check in at the venue by scanning the QR code on their badge, then score each performance (marks out of 10) on their phone while the performance's review window is open. Admins run the event live (slam phases, review windows, extensions), volunteers handle check-in/out and registration lookup, and the system computes weighted marks and statistics.

## 2. Stack and framework decision

- **PHP 8.2+**, **MySQL/MariaDB (InnoDB, utf8mb4)** — server is already configured for this.
- **Framework: CodeIgniter 4** (no Laravel).
  - Why CI4 over Yii2: small footprint, runs well on an ordinary PHP/MySQL server, easy to deploy (rsync + `.env`), built-in Filters (for role/rate-limit gates), Throttler, Migrations, Validation, CSRF. Yii2's gii/RBAC is nice but heavier than this app needs.
- **Frontend (decision): Bootstrap 5 + Vue 3, with no jQuery in our own code.** Mobile-first applies to **all** screens (admin, volunteer, reviewer), see section 7.
  - Bootstrap 5 is mobile-first and does not need jQuery. Ordinary pages (CRUD forms, lists, import, settings, logs) stay server-rendered CI4 views with Bootstrap.
  - **Vue 3 (Composition API, no router, not a SPA)** is mounted only on the live, state-driven screens: the reviewer screen, the volunteer check-in/lookup screen, and the admin phase control panel, live monitor and edit-phase list. These screens have timers, polling, phase-dependent views and drag-and-drop reorder, which become tangled in jQuery DOM code.
  - Why not a full Vue SPA: on a plain PHP/MySQL server, sessions, CSRF and role filters are simpler with server-rendered pages, and the deployment stays small.
  - **Tables: DataTables 2.x with server-side processing (AJAX) on every table screen**, using the Bootstrap 5 styling integration and the Responsive extension (section 7, *Data tables*). DataTables 2 no longer requires jQuery, so run it without jQuery; if a chosen extension still needs jQuery, load jQuery **only on admin/volunteer pages that contain a table**, never on the reviewer, public or live Vue screens. Verify this at build time and record the decision in the repo README.
  - **Look and feel: Tabler (`@tabler/core`, built on Bootstrap 5.3) with its built-in dark mode for dim venues, Tabler Icons as an SVG sprite, and self-hosted fonts** (section 7a). *(Changed from Bootswatch Cosmo by the project owner.)*
  - Admin Vue screens may use **vue-tabler** (`@tak-ps/vue-tabler`) components imported individually by path (never the whole library, which pulls in an editor); the reviewer screen uses plain Tabler classes to stay small.
  - Vue sources are built with Vite into `public/assets/`; only compiled files are deployed (Node is needed on the developer machine or CI, never on the server). Keep the reviewer-screen bundle small (target under 150 KB gzipped).
- QR codes: **generated server-side** with `chillerlan/php-qrcode` (pure PHP, SVG/PNG output) and badge PDFs with `mpdf/mpdf` (handles Malayalam shaping with Noto Sans Malayalam; Dompdf does not). **Scanning**: attendees scan with their phone's own camera app (the QR holds a check-in URL); volunteers use an in-page scanner (`html5-qrcode`) that accepts both the URL form and a plain ID. See *Check-in entry and QR codes*.
- Live updates: **short polling** (every 3–5 s, jittered) against a lightweight JSON endpoint with ETag support; see *Live polling design* (section 6). No WebSockets or SSE on a plain PHP-FPM host.
- Charts on stats screen: Chart.js.
- Dependencies via Composer. Keep them minimal.

## 3. Roles and authentication

| Role | Login | Notes |
|------|-------|-------|
| `admin` | username + password | Full control. Passwords via `password_hash(PASSWORD_ARGON2ID or BCRYPT)`. |
| `volunteer` | username + password | Bound to **one slam**. Check-in/out, search, force checkout. |
| `user` (audience reviewer) | **unique badge ID** (QR scan or typed) | Bound to one slam. Login = check-in. |
| `judge` | unique badge ID | Same flow as user, but `user_type = judge`. Judges are just another set of users. |

Rules:
- Admin and volunteer use session auth with CSRF. Regenerate session ID on login.
- Reviewer (user/judge) "login" is a **check-in**: scanning/typing the ID creates an open check-in row and starts a session. Reviewer sessions require an *open* check-in; if a volunteer/admin force-checks-out the user, the next request must be rejected and the session ended.
- **Login attempt limiting (anti-fraud)**: throttle per IP *and* per submitted ID. e.g. 5 failed attempts / 10 min → lockout 15 min. Log failures in `login_attempts`. Use generic error messages (don't reveal whether an ID exists). Also cap how many different IDs one IP may try.
- A badge ID can only have **one active session/check-in at a time** (prevents sharing a badge across phones). Second device attempt → rejected with message to visit the volunteer desk.
- Reviewers can only access their own slam. Volunteers only their own slam. Enforce in a Filter, never only in the UI.

## 4. Unique ID format

- Format: **2 letters + 5 digits**, e.g. `KR48213`.
- Letters from an unambiguous set (exclude `I`, `O`; optionally `Q`, `S`, `Z` to avoid look-alikes); digits 0–9 (consider avoiding leading patterns like `00000`).
- Generate with `random_int()` (CSPRNG), never `rand()`/`mt_rand()`. Retry on collision.
- `UNIQUE` index on `badge_id`. Normalize input: trim, uppercase.
- IDs are unguessable-enough only with rate limiting (space ≈ 24² × 100,000 ≈ 57M), so throttling in section 3 is mandatory.
- Generation is done by admin in bulk (CSV import of attendees → IDs assigned → badge PDF/CSV export for printing QR).

## 5. Domain model (draft schema)

Use CI4 migrations; all tables InnoDB, utf8mb4, `created_at/updated_at`.

- `admins` (id, username, password_hash, name, is_active)
- `slams` (id, name, type ENUM('regional','final'), slug VARCHAR(80) UNIQUE, schedule_version INT DEFAULT 1, schedule_is_public BOOLEAN DEFAULT 0, schedule_published_at DATETIME NULL, description NULL, venue_name, address_line, city, district, state DEFAULT 'Kerala', pincode NULL, latitude DECIMAL(9,6) NULL, longitude DECIMAL(9,6) NULL, map_url NULL, contact_name NULL, contact_phone NULL, contact_email NULL, banner_path NULL, opens_at DATETIME, checkin_opens_at DATETIME NULL, checkin_closes_at DATETIME NULL, closes_at DATETIME, status ENUM('draft','scheduled','open','checkin_open','checkin_closed','reviewing','reviews_complete','revision','revision_closed','closed','marks_calculated','ended') DEFAULT 'draft', activated_at, ended_at) — `status` is the current phase of the *Slam timeline*; the four slam times are configurable per slam (UTC in DB, entered and shown in Asia/Kolkata); DB `CHECK (closes_at > opens_at)` plus application validation of the whole chain `opens_at ≤ checkin_opens_at < checkin_closes_at ≤ reviews_start_at < closes_at` (`reviews_start_at` optional). **Automation settings** (per slam, see *Automation and admin overrides*): `auto_mode ENUM('auto','manual') DEFAULT 'auto'`, `hold_next_step` BOOLEAN DEFAULT 0, `reviews_start_at` DATETIME NULL, `default_review_minutes`, `changeover_minutes`, `edit_window_minutes` (0 = skip the edit phase), `close_grace_minutes`, `default_tea_break_minutes`, `default_lunch_break_minutes`, `event_ends_at` DATETIME NULL.
- `volunteers` (id, slam_id, username, password_hash, name, is_active)
- `attendees` (id, slam_id, badge_id UNIQUE, name, mobile, email NULL, user_type ENUM('user','judge'), is_active, import_batch_id NULL, badge_print_count INT DEFAULT 0, badge_last_printed_at NULL) — pre-registered people; `UNIQUE(slam_id, mobile)`; mobile and email indexed for lookup.
- `import_batches` (id, slam_id, filename, file_sha256, uploaded_by, mode ENUM('skip_existing','update_existing'), rows_total, rows_created, rows_updated, rows_skipped, rows_failed, status ENUM('previewed','committed','failed','cancelled'), error_report_path NULL, created_at, committed_at NULL)
- `import_batch_rows` (id, batch_id, line_no, status ENUM('new','duplicate','invalid'), name, mobile, email NULL, user_type NULL, badge_id NULL, error NULL) — parsed rows of an import preview, served to the preview table server-side; deleted on commit or cancel and after 7 days.
- `checkins` (id, attendee_id, checked_in_at, checked_out_at NULL, checked_in_by NULL, checked_out_by NULL, checkout_type ENUM('manual','forced','auto') NULL) — at most one row with `checked_out_at IS NULL` per attendee.
- `presenters` (id, name, designation_or_institution NULL, profile TEXT, photo_path NULL, photo_thumb_path NULL, photo_alt NULL, is_active) — the person; reusable across slams (e.g. a regional presenter who reaches the final).
- `performances` (id, slam_id, presenter_id, topic VARCHAR(200), abstract TEXT, is_active) — a presenter's talk in a specific slam; `UNIQUE(slam_id, presenter_id)`. Reviews and schedule items always attach to the performance, not the presenter.
- `schedule_items` (id, slam_id, item_type ENUM('performance','break') DEFAULT 'performance', break_type ENUM('tea','lunch','other') NULL, performance_id NULL, label NULL, position INT, planned_start_at DATETIME, duration_minutes INT, is_pinned BOOLEAN DEFAULT 0, status ENUM('pending','open','closed','skipped')) — the slam schedule (see *Slam schedule*). `performance_id` is NULL for breaks; `duration_minutes` is the review-window length of a performance (prefilled from the slam's `default_review_minutes`) or the length of a break. Planned times strictly ascend with `position` and never overlap; enforced in `ScheduleService` inside a transaction (no DB unique key on `position`, so swaps and re-flows are simple).
- `schedule_snapshots` (id, slam_id, version INT, saved_at, saved_by, note NULL, snapshot JSON) — one row per saved version of the schedule (before the slam goes live), used for history and restore; `slams.schedule_version` is the current version number and is also the optimistic-concurrency token.
- `review_windows` (id, schedule_item_id, opened_at, closes_at, extended_minutes_total, closed_at NULL, closed_reason ENUM('timer','manual') NULL) — one row per opening; extensions append to an audit table `review_extensions` (id, review_window_id, minutes, by_admin_id, at).
- `reviews` (id, attendee_id, schedule_item_id, review_window_id, comment NULL, submitted_at, edited_at NULL, edit_count INT DEFAULT 0) — `UNIQUE(attendee_id, schedule_item_id)`. One row per reviewer per performance; the marks live in `review_answers` (one per question). Scoring always uses the **current** answers.
- `review_questions` (id, slam_id NULL, audience ENUM('user','judge'), text, text_ml NULL, help_text NULL, weight DECIMAL(5,2) DEFAULT 1, position INT, is_active, locked_at NULL) — `slam_id NULL` rows are the **default templates**; creating a slam copies them into that slam so later template changes never alter an existing slam.
- `review_answers` (id, review_id, question_id, score TINYINT) — `UNIQUE(review_id, question_id)`, `CHECK (score BETWEEN 1 AND 10)`.
- `review_revisions` (id, review_id, question_id NULL, old_score, new_score, old_comment, new_comment, revision_window_id, changed_at) — history of every edit (one row per changed answer; comment changes have `question_id NULL`).
- `revision_windows` (id, slam_id, opened_at, closes_at, extended_minutes_total, closed_at NULL, closed_reason ENUM('timer','manual') NULL) — the slam-level edit phase; extensions logged in `review_extensions` (add nullable `revision_window_id`).
- `score_config` (id, slam_id, w_user, w_judge, w_complete_reviewer, updated_by, updated_at)
- `slam_phase_log` (id, slam_id, from_status, to_status, changed_at, changed_by_type ENUM('admin','system'), changed_by_id NULL, trigger ENUM('timer','auto','manual_override','hard_stop'), note NULL) — append-only history of the timeline.
- `mark_runs` (id, slam_id, calculated_at, calculated_by, weights_snapshot JSON, question_weights_snapshot JSON, counts_snapshot JSON, is_current, is_final, note NULL) and `mark_results` (id, mark_run_id, performance_id, avg_user, avg_judge, avg_complete, final_mark DECIMAL(6,4), rank, n_user, n_judge, n_complete, per_question JSON) — immutable snapshots, see *Marks calculation*.
- `login_attempts` (id, ip, badge_id_or_username, success, created_at)
- `activity_log` (see section 6a for full column list) — append-only record of every action in the system.

Constraints to enforce in both DB and code: every answer score is an integer from **1 to 10 inclusive (0 is not valid)**, enforced with a `CHECK (score BETWEEN 1 AND 10)` constraint plus server-side validation; a review must contain an answer for **every active question of the reviewer's type** (no partial submissions); one review row per attendee per performance (edits update that row and write history, see revision phase); reviews only insertable while the window is open and attendee has an open check-in.

## 6. Core behaviours

### Slam lifecycle
Admin creates the slam (4 regional, 1 final) and, while it is in setup (`draft` → `scheduled`), adds presenters/performances, review questions, attendees and the schedule. On the event day the slam runs through the **phase timeline** below. Exactly one phase is current at a time, phases only move in the order shown, and every change is recorded.

### Slam timeline (phases)
Terminology: the **check-in gate** is the entrance ("Gates Open / Gate Close"); a **review window** is the timed window for one performance ("Review Open / Review Close"); the **edit window** is the Review Edit phase. The word "gate" on its own always means the check-in gate.

| # | Timeline step | `slams.status` | Started by | Ends when | What is allowed |
|---|---|---|---|---|---|
| 0 | Setup | `draft`, `scheduled` | Admin creates the slam, then marks it ready | `opens_at` reached, or admin activates it earlier | Admin configures everything. No check-in, no reviews |
| 1 | **Slam Open** | `open` | `opens_at` reached (automatic, only if the readiness check passes) or admin activates early/late (override). Readiness: performances, schedule items, questions for both users and judges, and attendees | Check-in gate opens | Admin and volunteer panels are live; attendee lookup works. A badge scan is answered with "check-in not open yet" and the time it opens |
| 2 | **Gates Open** (check-ins allowed) | `checkin_open` | `checkin_opens_at` reached (automatic) or admin opens it now | Gate closes | Check-in, manual checkout, force checkout. Checked-in reviewers see a waiting screen |
| 3 | **Gate Close** (check-in stops) | `checkin_closed` | `checkin_closes_at` reached (automatic) or admin closes it | First review window opens | No new check-ins. People already inside stay; checkout still works. Admin can reopen the gate (back to step 2) until the first review window opens |
| 4 | **Performance review open → Review close → next performance review open …** (repeats for every scheduled performance) | `reviewing` | Automatic at the first performance's planned start time (after Gate Close); each next performance opens at its planned start time, or as soon as the previous one has closed + changeover if the event is running late. Admin can open, close or extend any window by hand (the check-in gate must already be closed) | No pending performance is left | One review window at a time; see *Review window*. Between windows reviewers see the waiting screen |
| 5 | **All Review Close** | `reviews_complete` | Automatic when the last window closes and no pending item remains (or admin ends reviewing early after skipping the rest, with confirmation) | Admin opens the edit window, or skips it | Reviewers see "reviews closed". No edits yet. Marks are provisional |
| 6 | **Review Edit** | `revision` | Automatic right after All Review Close for `edit_window_minutes` (0 = skip edit); admin can open, extend, close or skip it by hand | Edit window closes | Reviewers edit their own answers (see *Score revision phase*) |
| 7 | **Edit Close** | `revision_closed` | Edit timer ends, or admin closes it, or admin chooses "skip edit" from step 5 | Admin closes the slam | Scores are **locked permanently** |
| 8 | **Slam Close** | `closed` | Automatic `close_grace_minutes` after Edit Close; admin can close earlier or later by hand; hard stop at `closes_at` | Admin calculates marks | Reviewer sessions end with a thank-you screen; anyone still checked in is auto-checked-out (`checkout.auto`); volunteer panel becomes read-only |
| 9 | **Marks Calculated** | `marks_calculated` | Runs automatically right after Slam Close; admin can recalculate by hand (see *Marks calculation*) | Event ends | Official marks and statistics (snapshot). Recalculation still possible |
| 10 | **Event Ends** | `ended` | Admin ends the event, or automatically at `event_ends_at` if one is set (blank by default, because ending is irreversible) | Never | Everything is read-only; only exports and the activity log remain; the current mark run is marked final |

Rules:
- **One state machine.** All phase changes go through `SlamPhaseService` with an explicit transition table; controllers never write `status` directly. Forbidden moves return an error. The only backward moves are reopening the check-in gate (step 3 → 2, before step 4) and recalculating marks.
- **Timer-driven, with admin manual overrides.** By default the whole timeline advances by itself from the configured times and durations; the admin can override any step by hand (advance now, extend, hold, skip, switch to manual). Details in *Automation and admin overrides*. If the check-in times are left blank, the admin opens and closes the gate by hand.
- Every transition runs in a transaction, writes a `slam_phase_log` row and an activity-log entry (`slam.phase_changed`, with trigger `timer`, `auto`, `manual_override` or `hard_stop`). A once-a-minute cron applies due timer transitions idempotently, but each request also checks the server clock, so a missed cron run never lets an action through after its deadline.
- **Late arrivals**: after Gate Close a normal check-in is rejected. Only an **admin** can use a *late-entry override* for an already-registered attendee (for example someone force-checked-out by mistake); a reason is required and it is logged as `checkin.late_override`. Volunteers cannot override. This never creates registrations (no walk-ins).
- Opening the first review window requires the check-in gate to be closed; the admin screen offers a one-click "Close check-in and open review".
- Reviewers can check in only in step 2, submit reviews only in step 4 while their performance's window is open, and edit only in step 6.
- Skipping: admin may skip the edit phase (step 5 → 7).

### Automation and admin overrides
**Principle: the timeline is timer-driven by default, and the admin can override any step by hand.** The system moves the slam forward by itself from the configured times and durations; the admin watches the control panel and steps in when the real event drifts from the plan.

**Per-slam automation settings**: `auto_mode` (`auto` or `manual`), `reviews_start_at` (optional; prefills the first slot's start time in the schedule builder), `default_review_minutes`, `changeover_minutes` (gap between performances), `edit_window_minutes` (0 = skip the edit phase), `close_grace_minutes` (Edit Close → Slam Close), `event_ends_at` (optional). Each schedule slot has its own planned start time and duration (see *Slam schedule*). Seed values are placeholders for the admin to confirm; do not hide defaults in code.

**The automatic chain (auto mode)**
1. `opens_at` → **Slam Open**, but only if the readiness check passes (performances, schedule items, questions for both audiences, attendees). If it fails the slam stays put, the control panel shows what is missing, and the check repeats every minute.
2. `checkin_opens_at` → **Gates Open**; `checkin_closes_at` → **Gate Close**.
3. The first performance's **planned start time** (once Gate Close has happened) → its review window opens for the slot's duration.
4. When a window's deadline passes → **Review Close**; the next pending performance opens at the later of **its planned start time** and the previous close + `changeover_minutes`, so it never opens early and never overlaps. Breaks are shown to reviewers and the public while they run, and skipped items are passed over; the clock simply continues to the next performance's planned start.
5. No pending performance left → **All Review Close**; the edit window opens automatically for `edit_window_minutes` (0 jumps straight to Edit Close).
6. Edit deadline → **Edit Close**; after `close_grace_minutes` → **Slam Close** (auto check-out, thank-you screens).
7. Slam Close → the first **marks run** is calculated automatically.
8. **Event Ends** only at `event_ends_at` if the admin set one; otherwise the admin ends it.

**Planned times and drift.** The schedule has planned start times (the plan the audience sees). If the event runs late, each later performance opens as soon as the previous one has closed + changeover, and the control panel shows a **projected timeline** with a "running late by N min" indicator and an alert if the projection would pass the hard stop at `closes_at`. The admin can press **Re-flow remaining** to shift all remaining planned times by the current delay, or leave the plan as is. Running early does not pull performances forward: each one waits for its planned start unless the admin uses **Advance now**.

**Admin overrides** (all logged with actor, time and `trigger = manual_override`; a reason is required for skip, reopen-gate and late-entry, optional otherwise):
- **Advance now**: start the next step immediately (open the gate early, open the next performance now, close a window now, open the edit window now, close the slam now, calculate marks now).
- **Extend / shorten**: change the current deadline (check-in close, a review window, the edit window); the projection updates.
- **Hold**: stops the system from *starting* the next step automatically until the admin presses Resume or Advance (also available as "hold after the current performance"). Hold **never changes a deadline that is already running**: an open review window or edit window still ends on its own deadline unless the admin extends it, so reviewers always see an honest countdown.
- **Resume auto**: re-enables the chain; a held step whose planned time has passed starts immediately, or after the changeover if the admin chooses that.
- **Skip** a pending performance, **reorder** pending ones, **extend a break or end it early**, **reopen** the check-in gate (before performance 1 opens).
- **Switch to manual mode** (`auto_mode = manual`): every step then waits for the admin. Windows already open still end at their deadlines.
- Overrides never bypass guards: forbidden transitions still fail (no review window while the check-in gate is open, no edits after Edit Close, nothing after the hard stop).

**How it runs (correctness).** `SlamPhaseService::tick(slam)` applies whatever is due. It runs (a) lazily on live-state polls and every admin request, so transitions happen within seconds while phones are polling, and (b) from a cron job every minute as a backup. **On polls it must stay cheap**: it first compares the server clock with a cached *next due* time for the slam (cleared on every phase, schedule or window change) and returns at once if nothing is due; only when something is due does it try a **non-blocking** lock (`GET_LOCK(..., 0)`), so exactly one request does the work and the others just read state. Under the lock it takes the slam row lock (`SELECT … FOR UPDATE`), re-reads the state and applies only transitions that are due, idempotently. Cron and admin requests use the blocking path. Admin overrides use the same lock, so a timer and an admin click at the same moment cannot both apply (the second one gets "already advanced"). A review window always gets its **full configured duration from the moment it actually opens**: after a server outage the chain resumes from the current time rather than opening a window whose time already passed, and the admin gets a `phase.alert`.

**Trigger values** in `slam_phase_log`: `timer` (a configured time arrived), `auto` (chained from the previous step plus changeover), `manual_override` (the admin did it by hand), `hard_stop` (the slam's `closes_at` forced it).

### Slam details (per slam)
Each slam record carries its own event details, entered when the admin creates it and editable later:
- **Identity**: name, type (regional or final), short description, optional banner image (same upload rules as presenter photos).
- **Date and time**: slam **date**, open time and close time. The admin form takes a date plus the four slam times (see *Configurable times per slam*), stored as `opens_at`, `checkin_opens_at`, `checkin_closes_at` and `closes_at`; the displayed slam date is the date of `opens_at`. A slam spanning midnight is allowed.
- **Place and location**: venue name, address, city, district, state (default Kerala), pincode, optional latitude/longitude and a map link (Google Maps or OpenStreetMap URL, validated as http/https).
- **Contact**: organiser contact name, phone, email (shown to reviewers on the "not started" and help screens, not public).
- Shown on: admin slam list/dashboard, volunteer panel header (so volunteers know which slam they serve), reviewer screens (slam name, venue and date on the waiting, not-started and finished states), and badge exports.
- Changes to any of these are logged as `slam.updated` with old and new values.

### Configurable times per slam
- Four times are set per slam by the admin when creating it and are editable later; each of the 4 regional slams and the final is configured independently: **Slam Open** (`opens_at`), **Gates Open** (`checkin_opens_at`), **Gate Close** (`checkin_closes_at`) and **Slam Close** (`closes_at`, the hard stop). The check-in times are optional; if left blank the admin opens and closes the gate by hand.
- Validation: `opens_at ≤ checkin_opens_at < checkin_closes_at ≤ reviews_start_at < closes_at`; `reviews_start_at` (when the first performance's review window opens automatically) is optional. Entered as a date plus IST times; a slam may cross midnight.
- Review window and edit window close times are **not** pre-configured; the admin sets them live when opening each one (see *Review window* and *Score revision phase*).
- Slam Open happens at `opens_at` (if the readiness check passes) or earlier/later when the admin activates the slam; the other times and durations drive the automatic transitions in *Automation and admin overrides*. All checks use the **server clock** on every check-in, state poll and review submission.
- Before Gates Open a badge scan gets "check-in opens at HH:MM". Volunteers and admin can still search attendees.
- **Hard stop at `closes_at`**: if the slam has not been closed yet, anything still open (check-in gate, review window, edit window) ends at that moment, new actions are rejected, and the phase moves to `closed` (`trigger = hard_stop`, logged as `slam.hard_stop`). A review window's own close time is capped at `closes_at`.
- Admin can move or extend these times from the slam page. A time that has already passed cannot be changed to another past time, the chain above must still hold, and extending `closes_at` while the slam is live is allowed. Every change is logged as `slam.times_changed` with old and new values.
- The live-state endpoint returns `phase`, the four times and `server_now`, so phones show the right screen and countdown.

### Presenters and performances
- Admin adds each **presenter** with: **name**, **topic**, **abstract**, **profile** (short bio) and **photo**; optional designation/institution and photo alt-text. The add form (inside a slam) creates the presenter, the performance and its schedule slot (start time and duration) together; admin can also pick an existing presenter to add to another slam (for the final) with a new or the same topic and abstract.
- Limits: topic ≤ 200 chars, abstract ≤ 2,000 chars, profile ≤ 1,000 chars; UTF-8 throughout (Malayalam must render and sort correctly). Store as plain text; render escaped with line breaks, no raw HTML.
- **Photo upload**: JPG, PNG or WebP, max 3 MB. Validate by real content (`finfo` + image decode), not file extension; re-encode with GD/Imagick to strip EXIF/metadata and any embedded payload; save a display size (≈ 800 px) and a thumbnail (≈ 200 px); name files with random names (never user-supplied names); store under a dedicated uploads folder where PHP execution is disabled. Photo is optional, with a neutral placeholder avatar when missing. Replacing a photo deletes the old files.
- Presenter and performance details are shown to reviewers on the live review card (photo, name, topic, collapsible abstract and profile), on the schedule view, and on the Marks screen.
- A performance that already has a schedule item or reviews cannot be deleted; use `is_active = 0` (and skip it in the schedule) so history and marks stay intact. Editing topic/abstract after reviews exist is allowed and logged.

### Check-in / check-out
- Check-in by QR scan or by typing the ID (volunteer panel, or the reviewer's own scan at the entrance screen). Normal check-in works **only while the check-in gate is open** (phase `checkin_open`); at any other time it is rejected with the reason and, if it has not opened yet, the opening time. After Gate Close only the admin late-entry override applies (see *Slam timeline*).
- **No walk-in registration.** Only attendees pre-registered by admin (CSV import / manual add before the event) can check in. There is no "register on the spot" screen or endpoint for volunteers. An unregistered person gets a clear "not registered" result and is directed to the organisers; any exception means an admin adds them to `attendees` first.
- Manual check-out available to the user themselves, and **force check-out** by volunteer or admin (audit-logged).
- Volunteer lookup: search attendees by **name, mobile, email**; result shows registered? + current check-in status; one-click check-in / force check-out. If the mobile/name/email is not found, show "Not registered" with no create option.
- Reviewer can see their own registration + check-in status.

### Check-in entry and QR codes
**Two ways for an attendee to check in**, both ending at the same server check (registered, check-in gate open, throttling, single active session):
1. **Scan the QR on the badge** with the phone's normal camera app. The QR encodes a URL: `{APP_CHECKIN_URL}/in/{BADGE_ID}` (for example `https://scienceslam.example/in/KR48213`). The page opens in the browser with the ID pre-filled and a large **"Check in as KR48213"** button. The check-in happens only on that button press (a `POST`), never on page load, so link-preview bots, QR-scanner apps and prefetching cannot check people in by accident.
2. **Open the check-in URL and type the ID**: `{APP_CHECKIN_URL}/checkin` shows one big input (auto-uppercase, format hint "2 letters + 5 digits", numeric keypad after the letters, 16 px+ font) and a Check in button. This URL is printed on the badge, on the entrance poster and shown on a venue screen.
- Volunteers can also check someone in by scanning with the in-page scanner or typing the ID; the scanner accepts the URL form and the plain ID.
- The ID in the URL is a credential: serve these pages with `Cache-Control: no-store` and `Referrer-Policy: no-referrer`, do not log the full URL in web-server access logs where avoidable, and never put the ID in the activity-log `target` as plain text beyond what is already stored for the attendee. Unknown or wrong IDs show the same generic message and count toward throttling.
- Check-in pages are public routes (no login), CSRF-protected on the POST, throttled per IP and per ID (section 3), and show the slam name, date and venue after success, then continue to the reviewer waiting screen.

**QR code generator screen** (admin, `/admin/qr`, mobile-first like everything else):
- **Single attendee**: search by name/mobile/ID, preview the QR on screen with name, type and ID, download as **SVG** or **PNG** (large, for print), copy the check-in link.
- **Batch**: choose a slam and filter (all attendees, one import batch, user or judge only, not yet printed, or a hand-picked selection) and generate a **printable badge PDF**: A4 sheets with a configurable badge size (default 4 badges per page, with presets for common badge sizes), each badge showing slam name, attendee name (Malayalam and English names render correctly), type (USER / JUDGE), the unique ID in large type, the QR code, and the short check-in URL. Cut marks optional.
- **Entrance poster**: a generic QR (no ID) that points to `{APP_CHECKIN_URL}/checkin`, as a printable A4/A3 PDF with the URL in large type, for people who lost their badge QR or want to type the ID.
- **Reissue ID**: if a badge is lost or an ID is exposed, the admin can issue a new ID for that attendee (the old ID stops working immediately, any open session of that attendee is ended, and the action is logged). Reprinting an unchanged badge is allowed and counted.
- QR settings: error correction **M** (or **Q** if a logo is added), quiet zone of at least 4 modules, minimum printed size 2.5 cm, high contrast (black on white), no gradient or inverted colours. Generation is deterministic from the ID, so reprints are identical.
- Each print/export updates `badge_print_count` and `badge_last_printed_at` and is logged (`badge.exported`, `qr.downloaded`). Output files are streamed to the admin, not stored on the server (they contain IDs). Admin only; a volunteer-visible "reprint lost badge" can be added later if wanted.
- Test: generated QR decodes (use a decoder library in tests) to the exact URL; URL with unknown ID does not reveal whether the ID exists; scanning does not check in without the button press; Malayalam name renders in the PDF.

### CSV import of attendees (the people who will check in)
Admin bulk-registers all audience users and judges from a CSV. This is the **only** way people get into the system besides the admin's single-add form (there are no walk-ins).

**Screen**: Admin → Slam → Attendees → *Import CSV*. Admin picks the target slam, the mode, and uploads the file. Download-able **template CSV** provided.

**Columns** (header row required, case-insensitive, extra columns ignored):
| Column | Required | Rules |
|--------|----------|-------|
| `name` | yes | Trimmed, 2–120 chars, UTF-8 (Malayalam names must work) |
| `mobile` | yes | Normalised to 10 digits (strip spaces, dashes, `+91`, leading `0`); must match `^[6-9]\d{9}$` |
| `email` | no | Lowercased; valid format if present |
| `type` | no | `user` or `judge`; blank → `user` |
| `badge_id` | no | If given, must match the ID format (2 letters + 5 digits, allowed letter set) and be globally unique; blank → generated by `BadgeIdService` |

**Flow (two steps, nothing is saved until confirmed)**
1. **Upload + validate (dry run)**: parse, normalise, validate every row, detect duplicates (within the file and against existing attendees of that slam by mobile). Show a preview: counts of *new / duplicate / invalid* and a server-side DataTable of all parsed rows (filter by status, per-row error messages with line numbers). Offer **download of error rows as CSV**.
2. **Confirm + commit**: runs in **one DB transaction**; either all valid rows are applied or none (on failure, nothing is written and an error is shown). Generated badge IDs are assigned in this step, retrying on collision.

**Duplicate handling (admin chooses)**
- `skip_existing` (default): rows whose mobile already exists in that slam are skipped and reported.
- `update_existing`: updates `name`, `email`, `type` of the existing attendee. **Never changes an existing `badge_id`**, never changes a user who is currently checked in without a visible warning, and never touches check-ins or reviews.
- Duplicate mobiles inside the same file: first row wins, others reported as errors.

**Limits and safety**
- UTF-8 (with or without BOM) and Excel-exported CSV; auto-detect `,` vs `;`. Max 5,000 rows and 2 MB per file; reject other file types by content, not just extension.
- Store `file_sha256`; if the same file was already committed for this slam, warn before allowing a re-import.
- Treat every cell as plain text; strip leading `= + - @` characters on **export** (CSV-injection protection) and never evaluate cell content.
- Admin only, CSRF-protected, throttled. Import is allowed while a slam is live (to add pre-registered late entries), and it is logged like any other change.
- Importing does **not** check anyone in. Check-in still happens only by scanning/typing the badge ID.

**After import**: admin can export the new attendees (name, mobile, type, badge ID, QR image/URL) as CSV/PDF for badge printing; the export is logged. Imported rows carry `import_batch_id` so a mistaken batch can be reviewed (and deactivated in bulk if nobody in it has checked in or reviewed).

**Endpoints**: `GET /admin/attendees/import/template`, `POST /admin/attendees/import/preview`, `POST /admin/attendees/import/commit/{batch}`, `GET /admin/attendees/import/{batch}/errors.csv`.

**Tests**: mobile normalisation variants, BOM and `;`-delimited files, duplicate within file, duplicate vs DB in both modes, invalid badge ID, ID collision retry, transaction rollback on failure, 5,001-row rejection, Malayalam names round-trip.

### Review window (timed window per performance)
*Terminology: this is the "Review Open / Review Close" step of the timeline. It is not the check-in gate.*
- Admin opens the review window for the *current* schedule item (phase `reviewing`; the check-in gate must already be closed) and **sets the close time at opening** (either "N minutes" or an absolute time). In auto mode the system opens each window by itself with the planned duration (the slot's `duration_minutes`) and the admin only overrides when the event drifts.
- When open, the active performance appears on **all checked-in reviewers' phones** (via polling) with the questions for their type and an optional comment.
- **Auto-close must not rely on cron alone.** Compute `effective_close = review_windows.closes_at + extensions` (never later than the slam's hard-stop `closes_at`) on the server for every state read and every review submission; reject submissions after it. A cron/scheduled task (every minute) may additionally flip `status` to `closed` and write `closed_at`, but correctness comes from the server-time check.
- Admin can **extend** by N minutes (updates window, writes `review_extensions`), and can close early manually.
- Use **server time only** — never trust client clocks. Send `server_now` and `closes_at` in the state endpoint so clients render a countdown (corrected by offset).
- Only one review window open per slam at a time.

### Slam schedule (adding presenters with times, reordering)
- **Building the schedule**: on the slam's *Schedule* page the admin adds presenters to the slam. Each addition creates (or reuses) the presenter, the performance and a **schedule slot with a planned start time and duration** in one form (presenter details as in *Presenters and performances*). Prefill: start = previous slot's end + `changeover_minutes` (first slot: `reviews_start_at`, else `checkin_closes_at`), duration = `default_review_minutes`. The admin can type any time.
- **Breaks (tea break, lunch break, other)**: the schedule builder has quick-add buttons *Add tea break*, *Add lunch break* and *Add other break*, available at the end of the list and between any two rows. Each break has a `break_type`, a label (default "Tea break" / "Lunch break"; editable, Malayalam allowed), a duration (prefilled from `default_tea_break_minutes` / `default_lunch_break_minutes`) and an optional **pinned start** (for example lunch at 13:00). A break takes part in ordering, drag/up-down moves, re-flow and validation exactly like any other slot; the slot after a break starts when the break ends. A break has no review window and no questions, and never changes the slam phase (it stays `reviewing`). The builder warns if a break is the first or last item.
- **During a break**: it is "in progress" from the moment the previous performance has closed and the break's planned start has arrived, until its end. Reviewers' waiting screens show the break (type icon, label, countdown) and "next: {presenter} at {time}"; the public schedule shows the same. The check-in gate stays closed; late arrivals still need the admin override.
- **Live control of breaks**: the admin can **extend** a break by N minutes (later planned times shift; a warning appears if a pinned slot would be pushed), **end it early** (the next performance then opens at once, subject to the usual guards), and add, move or remove a break while it is still pending.
- **Validation (server side, on every save)**: slot starts ascend with the running order; slots never overlap (a slot starts at or after the previous slot's end); the first slot starts after Gate Close; the last slot plus the edit window ends before `closes_at`; a performance appears once per slam.
- **View**: a list in running order showing presenter photo thumbnail, name, topic, start–end time, duration, pin icon and status. On phones it is a card list with up/down buttons; on larger screens a table with drag handles.
- **Editing a slot**: change the start time or duration, or **pin** the slot so it keeps its exact clock time during re-flow (for example the first slot after an interval).
- **Reordering** (drag-and-drop on desktop, up/down buttons everywhere, same endpoint): moving a performance changes the running order and the times are **re-flowed** from it: the first slot keeps its start, each next slot starts at the previous slot's end + `changeover_minutes`, every performance keeps its own duration, and pinned slots stay at their time (an error is shown if a pinned slot cannot fit). The screen shows a **preview** (old time → new time for each affected row) and applies it only on confirm. A switch "keep slot times, swap presenters only" is offered when the durations are equal.
- **Live changes**: once the slam is in `reviewing`, only `pending` slots can be edited, moved, skipped, inserted or removed; `open` and `closed` slots are fixed. Every change recomputes the projected timeline at once. **Re-flow remaining** shifts all remaining planned times by the current delay (pinned slots stay unless the admin confirms).
- **Planned vs actual**: each slot shows its planned start/end and, once run, the actual open/close times from its review window.
- **Audience view**: checked-in reviewers see "up next" (presenter, topic, planned time) on the waiting screen, and the full running order on their status page.
- A performance that has reviews cannot be removed from the schedule, only skipped or deactivated.
- All schedule writes run in a transaction under the same per-slam row lock as the phase machine, so a reorder can never collide with an automatic window opening.
- Tests: ascending and no-overlap validation, re-flow with pinned slots and breaks, preview equals applied result, reorder during `reviewing` rejects non-pending slots, reorder racing an automatic open, break extension shifts later slots, a pinned lunch break holds its time during re-flow, reviewers and the public see the break screen.

### Schedule maker screen (admin)
The full-screen tool at `/admin/slams/{id}/schedule` that implements the rules in *Slam schedule*. It is a Vue 3 screen (state-heavy: drafts, undo, drag, live validation) and is **mobile-first**: it must be fully usable on a phone.

**Layout**
- **Header**: slam name, date and status chip; a summary strip with first start, projected end, number of performances and breaks, total running time, and the margin before the edit window and `closes_at` (turns red when negative).
- **Toolbar** (collapses into a menu on phones): Add presenter, Add tea break, Add lunch break, Add other break, Generate, Re-flow, Undo, Redo, Save, Export/Print, History. On phones the primary actions (Add, Save) sit in a sticky bottom bar.
- **Timeline**: the running order as cards with a time ruler. A toggle switches between *compact list* and *time-scaled* (card height proportional to duration). Each card shows the planned start–end, duration, presenter photo thumbnail, name, topic, pin icon and a type icon for breaks; tapping opens an inline editor (time, duration, pin, skip, remove, edit presenter details).
- **Desktop only**: a left **pool** of presenters not yet in this slam (search by name or topic; includes presenters from other slams, for example regional presenters for the final) that can be dragged or clicked into the timeline. On phones the same pool opens as "Add existing presenter".
- **Validation panel**: lists errors (overlap, a slot before Gate Close, the schedule running past the limit, a pinned slot that cannot fit, duplicate presenter, zero duration) and warnings (break first or last, very short or very long duration, little margin before the edit window). Each item jumps to its row. Saving is blocked while errors remain.

**Actions**
1. **Add presenter** (new or existing) and **add break** with quick buttons; new slots are prefilled with the next time and the default duration; every field is editable.
2. **Generate schedule** wizard: first start time (prefilled from `reviews_start_at`), default duration, changeover, tea-break rule (after every N performances or at a clock time, with duration), lunch-break rule (at a clock time, with duration), and the order for unscheduled performances (as listed, alphabetical, or a **seeded random shuffle** whose seed is stored and logged). It shows a full preview and then either *replaces all unpinned pending slots* or *appends* to the existing schedule.
3. **Reorder**: drag handles (long-press on touch), up/down buttons, and "move to position N"; **multi-select** rows for bulk change of duration, pin, skip or remove. Times re-flow with a preview as in *Slam schedule*.
4. **Draft, then save**: edits are held as a draft on the client with an "Unsaved changes" banner and local autosave; **Undo/Redo** keeps the last 50 steps. **Save** sends the whole draft with `base_version`; the server validates, writes in one transaction, increments `schedule_version` and stores a snapshot. If another admin saved first the server returns 409 with the current version, and the screen offers a diff and "reload" or "reapply my changes".
5. **History**: list of snapshots (time, who, note); preview and **restore** (restore creates a new draft, which is saved as a new version). Available before the slam goes live.
6. **Copy structure from another slam**: copy slot durations, breaks and pinned times (without presenters) to reuse the regional layout for the final.
7. **Run-sheet export**: A4 PDF (time, presenter, topic, duration, breaks; Malayalam renders correctly via mPDF) for stage managers and volunteers, plus CSV. Shortcuts to the public schedule switch, its link and QR.

**Live mode**: once the slam is in `reviewing`, the same screen switches to live mode: closed and open slots are locked and coloured by status, the current slot is highlighted with its countdown, pending slots stay editable, the projected timeline and "running late by N min" show, and the controls are Re-flow remaining, skip, extend or end a break, and Advance now. Saving in live mode applies only to pending slots and is rejected if it touches a locked slot.

**Access**: admin only. Volunteers get a read-only run-sheet view for their own slam.

**Rules**: all saves run under the per-slam row lock used by the phase machine; every save is logged; unsaved drafts never affect the live system; the public schedule and reviewer waiting screens only see saved versions.

**Tests**: generate-then-save round trip; 409 on stale `base_version`; undo/redo; restore creates a new version; random shuffle is reproducible from its seed; live-mode save rejects locked slots; run-sheet PDF contains Malayalam names; the whole screen works at 360 px.

### Public schedule screen (no login)
- **Purpose**: anyone (audience, presenters' families, press) can open a slam's schedule on a phone before and during the event, without a badge or login.
- **URLs**: `/slams` lists published slams (name, date, venue, badge: Upcoming / Today / Live / Finished); `/slams/{slug}` is one slam's schedule. The admin sets the `slug` (default from name and date). The slam's public link and a QR for it are available on the QR generator screen (poster QR) and can be printed on the entrance poster.
- **Publishing**: visible only when the admin switches on **Publish schedule** for that slam (`schedule_is_public`, off by default). Unpublished slams return 404. Publishing and unpublishing are logged.
- **Content**: slam banner, name, date, venue with address and map link, "doors open" time (`checkin_opens_at`), organiser contact, and the running order: for each slot its planned start time, presenter photo thumbnail, name, designation, topic, with abstract and profile expandable on tap; breaks (tea, lunch, other) appear with a type icon, label and time. Mobile card list first; a wider timeline layout from `md` up.
- **Live status**: a small cached JSON refreshes the page every ~10 s (paused while the tab is hidden). Each slot shows Upcoming / **Presenting now** / Done; a top banner shows "Now: {presenter}" or "Up next: {presenter} at {time}", "Running about N min behind schedule" when applicable, and "Slam finished, thank you" after Slam Close. Reordering, new times and re-flows show up within seconds.
- **Never public**: review windows and deadlines, review questions, scores and marks (results are not shown; publishing results would be a separate decision), attendee or reviewer data, check-in counts, badge IDs, admin and volunteer information, skipped or inactive performances.
- **Performance and safety**: the public JSON is cached server-side per slam for about 5 s and purged immediately on any schedule or phase change; ETag/304 support; per-IP rate limiting; read-only; all text escaped (no raw HTML); only the ~200 px thumbnails are served, lazy-loaded. The page renders on the server without JavaScript and uses JS only for the live refresh. Pages are `noindex` until published and indexable with Open Graph/Twitter meta (banner, name, date) once published.
- **Optional**: an "Add to calendar" `.ics` link per slam (start at Gates Open, end at `closes_at`).
- **Tests**: unpublished slam returns 404; the JSON contains only an allow-list of fields; status badges follow phase and schedule changes; cache is purged on reorder; rate limit works; renders at 360 px.

### Review questions (configurable, different for users and judges)
- Audience users and judges answer **different question sets**. **Users get 2 questions by default**; judges get their own set (number and wording set by admin). Counts are not hard-coded anywhere; the UI, validation and scoring all read the configured questions.
- Every question is scored **1–10** (integers, no 0). Optional shared free-text comment per review.
- Admin screen: **Review questions** (per slam, two tabs: *Users* / *Judges*): add, edit text (English + Malayalam), help text, reorder, activate/deactivate, set **question weight** (default 1 = equal). Includes a *Default templates* area that new slams copy from, and "copy questions from another slam" (e.g. regional → final).
- **Per-review mark** = weighted average of its answers: `review_mark = Σ(weight_q × score_q) / Σ(weight_q)` over the active questions for that audience, giving a value from 1 to 10. With equal weights this is the plain average. This `review_mark` is what feeds the final-mark calculation below.
- **Locking**: until the first review of an audience is submitted in a slam, questions can be freely added, removed, reweighted. After that, for that audience: no add/remove/deactivate/reweight (it would make earlier reviews incomparable); only wording/typo fixes are allowed (logged with old and new text). Locking is stamped in `locked_at` and enforced on the server.
- A reviewer sees only questions for their own type; the live-state endpoint returns them with the open performance, and the submit endpoint rejects missing, extra or out-of-range answers.
- Seed data uses clearly marked **placeholder** questions ("Question 1", "Question 2" for users; a few for judges) for the admin to replace before the first slam. Do not invent final wording.

### Score revision phase (edit after all submissions)
Scores **cannot be edited while performances are still being reviewed**. They can be edited only at the end, after every performance's review window has been closed (or skipped).
- The **revision phase** (`status` → `revision`) starts automatically after All Review Close for `edit_window_minutes` in auto mode, or the admin starts it by hand and sets its close time, same as the per-performance review window: auto-close enforced by server time on every read/write, admin can extend by N minutes or close early. It starts from phase `reviews_complete`; only one window (review window or edit window) is active at a time, and it cannot start while any review window is open.
- During revision, checked-in reviewers (users and judges) see a list of **only their own** submitted scores with performance names, and can change their answers (each 1–10) for performances they already reviewed. Revision is **not** a way to review performances they skipped or missed; those stay unreviewed (no new rows are created).
- Each edit updates `reviews` and writes a `review_revisions` row; `edit_count` and `edited_at` are updated. Edits are logged as `review.revised`.
- Check-in is still required; forced-checkout users cannot edit.
- When the edit window closes (**Edit Close**, phase `revision_closed`), scores are **locked permanently**. Marks and statistics shown before **Marks Calculated** carry a "provisional" badge; they become official only when the admin runs the marks calculation (see *Marks calculation*).
- If the admin skips the edit phase, scores lock at the same point (`reviews_complete` → `revision_closed`), logged as `scores.locked` with the reason "edit skipped".

### Scoring
- Marks are out of **10**; every answer is an integer 1–10 (no 0). **Final marks are calculated from the question answers**: answers → per-review mark (weighted average, see *Review questions*) → group averages → weighted final.
- Judges and users are scored from their own question sets; because every question uses the same 1–10 scale, a judge's `review_mark` and a user's `review_mark` are directly comparable and are combined only through the group weights below.
- Per performance, from the per-review marks compute three averages (each is the mean of `review_mark` across that group's reviews; also keep **per-question averages** for the stats screen): audience users (`A_u`), judges (`A_j`), and "complete reviewers" (`A_c`) = audience users who reviewed **every** performance in the slam.
- Admin configures weights per slam in the **Mark configuration** screen: `w_user`, `w_judge`, `w_complete` (must sum to 100; validate).
- Final mark = `(w_user·A_u + w_judge·A_j + w_complete·A_c) / (sum of weights for groups that actually have votes)` — i.e. re-normalise if a group has no votes (document and test this). Round to 2 decimals for display, keep full precision in calc.
- "Users who voted for all performances" list available to admin (also usable for prizes/raffle). A reviewer counts as complete only if they have a review for every performance that was opened (exclude `skipped`).
- Scores must be computed from `reviews` on demand (or cached with explicit invalidation); no hand-edited totals.

### Marks calculation (phase `marks_calculated`)
- Until Marks Calculated, the Marks and Statistics screens show **provisional** values computed live from the current answers. Scores stop changing at Edit Close, but results are **official only once a marks run exists**: the first run happens automatically right after Slam Close (or when the admin runs "Calculate marks" by hand), and runs are allowed only from phase `closed` onwards.
- A run reads the locked answers, group weights and question weights, computes per-review marks, the group averages `A_u`, `A_j`, `A_c`, the final mark and the rank for every performance, and stores all of it as an immutable snapshot (`mark_runs` + `mark_results`): the weights used, how many reviewers were in each group, and per-question averages. It runs in one transaction.
- The admin may **recalculate** (for example after correcting a weight) any time before Event Ends. Each run is a new `mark_runs` row; the newest is `is_current`, older runs are kept for audit, and the screen shows the difference from the previous run. Only the current run feeds the official Marks screen, exports and result pages. Changing weights afterwards never alters an existing run.
- Ranking: equal final marks share a rank; for ordering within a tie, default to higher `A_j`, then higher `A_u` (to be confirmed by the organisers).
- Event Ends flags the current run `is_final` and locks `score_config`.

## 6a. Full activity log

Every meaningful action by any actor (admin, volunteer, reviewer, system/cron) is written to one append-only table, `activity_log`. This is the single source for audit, fraud investigation, dispute handling and post-event reports.

### Table: `activity_log`
| Column | Notes |
|--------|-------|
| `id` | BIGINT auto-increment |
| `occurred_at` | DATETIME(3), UTC, server time |
| `slam_id` | NULL for global actions (e.g. admin login) |
| `actor_type` | ENUM('admin','volunteer','user','judge','system','anonymous') |
| `actor_id` | NULL for system/anonymous |
| `actor_label` | Snapshot of username / badge ID / name at that moment (so history survives renames and deletions) |
| `action` | Dotted code, e.g. `checkin.created` (see list below) |
| `target_type` / `target_id` | e.g. `attendee`, `schedule_item`, `review_window`, `slam` |
| `outcome` | ENUM('success','failure','denied') |
| `reason` | Short text, e.g. `window_closed`, `locked_out`, `invalid_id`; required for force checkout, manual close, skip |
| `old_values` / `new_values` | JSON, only the changed fields (never passwords or hashes) |
| `meta` | JSON: extra context (minutes extended, score submitted, search term, etc.) |
| `ip` | VARBINARY(16) |
| `user_agent` | VARCHAR(255) |
| `session_ref` | Hashed session identifier, to correlate one session's actions |

Indexes: `(slam_id, occurred_at)`, `(actor_type, actor_id, occurred_at)`, `(action, occurred_at)`, `(target_type, target_id)`, `(ip, occurred_at)`.

### Rules
- **Append-only.** No update or delete code paths anywhere in the app. The DB user used by the app should have `INSERT, SELECT` only on this table; archiving is a separate admin-run job.
- Write through one `ActivityLogService::log(...)` so format is consistent. Log **inside the same transaction** as the action it describes (so a rolled-back action leaves no success entry); failures and denials are logged outside the transaction so they survive.
- A logging failure must never break the user's action for read-type events, but must fail the request for state-changing events on `review.*`, `window.*`, `phase.*` and `checkout.forced` (integrity first).
- Never store passwords, hashes, CSRF tokens or full session IDs. For failed reviewer logins store the submitted ID only if it matches the ID format, otherwise a truncated/hashed value.
- Log **server time** only.

### Actions to log (minimum)
**Authentication**: `auth.login.success`, `auth.login.failure`, `auth.lockout`, `auth.logout`, `auth.session_rejected` (e.g. after forced checkout, second device attempt).

**Check-in/out**: `checkin.created`, `checkin.duplicate_rejected`, `checkin.rejected` (reasons: `gate_not_open`, `gate_closed`, `not_registered`, `slam_closed`), `checkin.late_override` (admin only, reason required), `checkout.manual`, `checkout.forced` (with reason + who), `checkout.auto` (at Slam Close), `attendee.lookup` (volunteer search: store the query type, not full PII; log result count), `attendee.status_viewed`.

**Review**: `review.submitted` (answers per question in `meta`), `review.rejected` (reasons: `window_closed`, `not_checked_in`, `already_reviewed`, `invalid_score`, `no_open_window`, `edit_not_allowed`, `scores_locked`), `review.revised` (old and new score), `revision.opened`, `revision.extended`, `revision.closed.manual`, `revision.closed.timer`, `scores.locked`, `review.state_polled` is **not** logged (too noisy).

**Review windows/schedule**: `window.opened` (with close time), `window.extended` (minutes, new close time), `window.closed.manual`, `window.closed.timer` (system), `schedule.reordered` (old and new order and times), `schedule.item_added` (with planned time), `schedule.item_time_changed`, `schedule.item_pinned|unpinned`, `schedule.break_added|updated|extended|ended_early`, `schedule.reflowed` (delay, preview vs applied), `schedule.saved` (version, counts), `schedule.generated` (wizard settings and seed), `schedule.conflict_detected`, `schedule.snapshot_restored`, `schedule.copied_from_slam`, `schedule.exported` (pdf/csv), `schedule.item_skipped`, `schedule.item_removed`.

**Slam/setup**: `slam.created|updated|activated|completed`, `slam.times_changed` (old/new values of the four slam times), `slam.schedule_published|unpublished`, `presenter.created|updated|deactivated`, `presenter.photo_replaced`, `performance.created|updated|deactivated|added_from_existing_presenter`, `attendee.created|updated|deactivated`, `attendee.import.previewed`, `attendee.import.committed` (batch ID, filename, hash, created/updated/skipped/failed counts), `attendee.import.failed`, `attendee.import.batch_deactivated`, `badge.generated`, `badge.exported`, `badge.id_reissued`, `qr.generated`, `qr.downloaded`, `volunteer.created|updated|deactivated`, `admin.created|updated`.

**Phases**: `slam.phase_changed` (from, to, trigger `timer|auto|manual_override|hard_stop`), `phase.hold`, `phase.resume`, `phase.mode_changed` (auto/manual), `phase.override_advance`, `phase.override_extend`, `phase.alert` (readiness failed, projected end after hard stop, server restart recovery), `checkin_gate.opened|closed|extended|reopened`, `slam.hard_stop`, `marks.calculated`, `marks.recalculated`, `event.ended`.

**Questions**: `question.created|updated|deleted|reordered|deactivated`, `question.reweighted`, `question.locked`, `question.copied_from_slam`, `question.template_updated` (old/new text and weights in `old_values`/`new_values`).

**Scoring**: `score_config.updated` (old/new weights), `marks.viewed`, `marks.exported`, `complete_reviewers.exported`.

**System**: `system.cron_run`, `system.error` (unexpected exceptions with reference ID, no stack trace in `meta`).

### Admin screen: Activity log
- Filter by slam, actor type/actor, action (multi-select), outcome, target, IP, date-time range.
- Free-text search over `actor_label`, `reason`.
- Per-row detail drawer showing old/new values and meta JSON.
- **Live tail** mode on the live-slam dashboard (polls for new rows by `id > last_id`).
- Per-attendee timeline view (check-ins, check-outs, reviews, failed attempts, who did what).
- Per-performance timeline (review window opened, extended, closed, review counts).
- CSV export (itself logged as `activitylog.exported`).
- Volunteers see a limited view: only check-in/out entries for their slam. Reviewers never see the log.

### Retention
Keep for at least the life of the event series plus a year. Archive per slam to CSV after completion; do not purge without an explicit admin action.

### Live polling design (hundreds of phones)
Polling load is a server and protocol problem; Vue 3 only affects the one-time bundle download. Size for about **500–1,000 phones per slam polling every ~4 s (≈125–250 requests/s)**; each request must be cheap.

**Client** (shared poller used by the reviewer, volunteer, control-panel and public screens):
- Chain `setTimeout` (never `setInterval`), keep **one request in flight**, add **±20 % random jitter** to every delay.
- Send `If-None-Match`; an unchanged state returns a tiny **304**.
- Pause while the tab is hidden; poll at once when it becomes visible again.
- Exponential back-off after errors (cap ~30 s) with the "reconnecting" banner.
- Run every countdown locally from the server-time offset (`server_now`), so the timer is accurate without fast polling.
- When the local clock reaches a known deadline (window close, break end, next planned start), fetch the next state immediately, spread over 1–2 s by jitter.

**Server**:
- One **shared state document per slam** (phase, times, open window and deadline, current performance or break, next item, question sets) cached in APCu or the file cache, cleared on any phase, schedule or window change. The per-user part is only the checked-in and already-reviewed flags.
- ETag = hash of shared-document version + per-user flags.
- Sessions in files or Redis, never the database; call `session_write_close()` as early as possible in poll endpoints so a user's parallel requests do not queue behind each other.
- `tick()` on polls uses the cached next-due time and a non-blocking lock (see *How it runs*).
- Optional: an nginx 1–2 s microcache on the public schedule JSON.
- **No SSE or WebSockets** on this host: each open connection pins a PHP-FPM worker, so a few hundred phones would exhaust the pool.

**Load test**: run a k6 test of about **1,000 simulated phones** right after the reviewer screen is built (build-order step 5), not only in the final hardening step. Script lives in `tests/load/`.

## 7. Screens

### Mobile-first rules (apply to every screen: reviewer, volunteer and admin)
- Design and test at **360 px width first**, then enhance with Bootstrap `min-width` breakpoints (sm, md, lg). Desktop is the enhancement, not the base. Admin pages must be fully usable on a phone, because organisers will run the event from phones and tablets.
- `<meta name="viewport" content="width=device-width, initial-scale=1">`; no horizontal page scroll at any width; text inputs at least 16 px font (prevents iOS zoom on focus).
- Touch targets at least 44 × 44 px. The 1–10 score buttons form a large grid (two rows of five). Primary actions (Submit, Check in) sit in a sticky bottom bar. Nothing depends on hover or right-click.
- Tables are DataTables with the **Responsive** extension: on small screens low-priority columns collapse into an expandable child row, with the key fields always visible (see *Data tables*). The schedule maker timeline and the reviewer and public screens are card layouts, not tables.
- Forms are single-column on mobile; use native `date`/`time` inputs (IST); photo upload works from the camera or gallery.
- Drag-and-drop schedule reorder always has **up/down buttons** as the touch alternative.
- QR scanning uses the rear camera with a visible typed-ID fallback.
- Flaky venue network: every poll and submit shows its state (sending / sent / retrying); submits retry safely (the endpoint is idempotent); the countdown keeps running from the last server-time offset; a clear "reconnecting" banner replaces any blank screen.
- Responsive images (`srcset` with the thumbnail and display sizes), lazy-loaded; system fonts or one self-hosted font with Malayalam support (for example Noto Sans Malayalam). Verify Malayalam rendering on Android Chrome and iOS Safari.
- Test matrix before each slam: low-end Android Chrome, recent iPhone Safari, a tablet and a desktop browser, at 360, 390, 768 and 1280 px.
- Acceptance rule: a screen is not done until it works at 360 px.

### Data tables (server-side DataTables on every table screen)
**Rule: every tabular list in the admin and volunteer panels is a DataTables 2.x table with server-side processing (`serverSide: true`, AJAX).** Nothing loads a whole table into the page. Exceptions (not tables): the schedule maker timeline, the reviewer screens and the public screens, which are card layouts.

**Tables covered** (endpoint name in brackets): slams list [`slams`], presenters and performances [`presenters`, `performances`], attendees per slam [`attendees`], import preview rows and errors [`import-rows`], import batches [`import-batches`], volunteers [`volunteers`], check-in monitor and volunteer search results [`checkins`, `attendee-search`], review questions [`questions`], marks (per performance, per group, final) [`marks`], per-question statistics [`mark-stats`], complete-reviewers list [`complete-reviewers`], mark runs and their diff [`mark-runs`], activity log and per-attendee/per-performance timelines [`activity-log`], schedule history [`schedule-snapshots`], phase log [`phase-log`].

**Request/response contract**
- Endpoint `POST /api/tables/{name}` (JSON body, CSRF header, session auth; role and slam scope enforced server-side on every call). Request is the standard DataTables server-side format (`draw`, `start`, `length`, `search.value`, `order[]`, `columns[]`) plus a `filters` object for the screen's own filters (slam, status, type, date range, actor, and so on).
- Response: `{draw, recordsTotal, recordsFiltered, data: [...], error?}`.
- **Safety**: `length` is capped at 100; sortable and searchable columns come from a per-endpoint **allow-list** that maps the client's column index/name to a real SQL column, so client strings never reach `ORDER BY` or `WHERE`; all queries use the CI4 Query Builder with bound parameters; unknown columns or filters return 422. Volunteers only ever receive rows of their own slam; reviewers have no table endpoints.
- **Output**: the server returns plain data (no HTML). The client renders text escaped (`textContent`/DataTables `render` returning escaped text); action buttons are built client-side from row IDs. PII columns (mobile, email) appear only where the role is allowed to see them.
- **Search**: debounced (about 400 ms), at least 2 characters for global search (3 for the volunteer attendee search by name/mobile/email); the supporting indexes exist (`attendees`: `(slam_id, mobile)`, name and email indexed, `badge_id` unique; `activity_log`: the section 6a indexes; `checkins`: `(attendee_id, checked_out_at)`). Malayalam sorting and search must be verified against the chosen collation.
- **Big tables**: for `activity-log`, `recordsTotal` may be an approximate cached count (about 30 s) and an exact `recordsFiltered` is computed only when filters are applied; always filter by slam and time range first.
- **Marks and statistics tables** read from the current `mark_results` snapshot, or, while provisional, from an aggregate query; they use the same contract, so sorting and paging happen in SQL.
- **Import preview**: the parsed rows are stored in `import_batch_rows` (status new/duplicate/invalid, error text) so the preview and error tables are paged server-side; rows are deleted when the batch is committed or cancelled, and after 7 days otherwise.

**Behaviour and mobile-first**
- Bootstrap 5 styling plus the **Responsive** extension: set `responsivePriority` per column so that name/ID/status and the row action stay visible at 360 px and the rest move into the child row. Default page length 10 on phones, 25 on desktop (selectable up to 100). Search box and page-length control use 16 px text; paging controls are at least 44 px; each row has one 44 px action button (kebab menu) instead of a row of small buttons.
- Show a `processing` indicator, a clear empty state and an error state with a Retry button (never a browser `alert()`); failed AJAX keeps the previous rows on screen.
- Live tables (check-in monitor, activity-log tail, phase log on the control panel) refresh with `ajax.reload(null, false)` every 5–10 s only while the tab is visible and keep the current page and sort.
- `stateSave` remembers page length and sort per table, but **never saves the search text** on tables with personal data.
- **Exports** are server endpoints that reuse the same filters and sort (`GET /admin/tables/{name}/export.csv`), not the client-side Buttons (which would export only the current page). Exports are admin only, capped (for example 50,000 rows), protected against CSV injection, and logged (`table.exported`).
- One shared implementation: a CI4 `DataTableService` (parse request, apply allow-listed search/sort/filters, count, page, build response) with one small class per endpoint declaring its columns, joins and scope rule, and one shared JS wrapper that applies the common options (Bootstrap 5, Responsive, language, error handling, CSRF header).

**Tests**: paging cap enforced; unknown column or filter rejected; SQL-injection strings in search/order are harmless; volunteer cannot read another slam's rows; reviewer gets 403; column allow-list matches the screen; export applies the same filters; the table is usable at 360 px.

**Admin panel** (`/admin`): dashboard; slams CRUD (with details, the four slam times, slug and the public-schedule publish switch); presenters and performances CRUD (name, topic, abstract, profile, photo); **slam phase control panel** (the timeline with the current phase highlighted, an AUTO / HELD / MANUAL badge, planned and projected times, and the allowed overrides per step (advance now, extend, hold, resume): open slam, open/close/extend/reopen check-in gate, open and close each performance's review window, set close time, extend, reorder, skip, open/close the edit window, close slam, calculate marks, end event); **schedule maker screen** (section 6: generate, add presenters and breaks, drag to reorder, validate, versions, run-sheet export, live mode); attendee import/ID generation; **QR code generator and badge PDF screen**; volunteers CRUD; check-in monitor (counts, force checkout); **Marks screen** (performers and marks out of 10, per group + final); **Mark statistics screen** (distribution, participation rate, group comparison, per-question averages by group, per-performance charts); **Mark configuration screen** (group weights for users, judges, complete reviewers; question weights per audience) and **Review questions** screen (section 6); "complete reviewers" list; **activity log viewer** (section 6a).

**Volunteer panel** (`/volunteer`): scan/type ID check-in (enabled only while the check-in gate is open; shows the current phase); search (name/mobile/email); registered + checked-in status; manual/force check-out.

**Reviewer screen** (`/review`): mobile-first, one big card — current performance with presenter photo, name, topic and expandable abstract/profile, countdown, the questions for their type (2 for users by default, judges' own set), each with 1–10 tap buttons and all required before submit, "already reviewed" state, waiting state between performances (showing the next presenter, topic and planned start time, or the current tea/lunch break with a countdown), own status page. The screen follows the slam phase: "check-in not open yet" (with time) → waiting for performances → current performance → "reviews closed, edit opens soon" → edit list with countdown → "slam closed, thank you".

## 7a. Visual theme, colour palette and icons

**Theme decision: Tabler** (https://tabler.io, `@tabler/core` 1.x on Bootstrap 5.3) — chosen by the project owner, replacing the earlier Bootswatch Cosmo choice. Screens depend only on the semantic tokens below, never on theme-specific class tricks.

**How the theme is applied**
- Install `@tabler/core` and use its compiled `tabler.min.css` / `tabler.min.js` bundled by Vite (never a CDN). Overrides live in `frontend/theme/custom.css` and use Tabler/Bootstrap CSS variables only (`--tblr-primary`, `--tblr-font-sans-serif`, ...), so upgrades stay easy.
- **Dark mode**: Tabler supports `data-bs-theme="dark"` natively. The reviewer screen follows `prefers-color-scheme` and has a toggle; the choice is remembered in `localStorage` (non-sensitive). Dark mode must be fully tested on the reviewer, waiting and edit screens (admin and volunteer panels support it too).
- **Fonts: self-host** **Inter** (Tabler's typeface) plus **Noto Sans Malayalam** via `@fontsource`, with `font-display: swap`. No external requests are needed to render any screen, so a strict Content-Security-Policy is possible.
- **Contrast**: status badges use Tabler's light variants (`bg-{colour}-lt`, coloured text on a tinted surface), and solid buttons use the `-fg` text colours Tabler computes; check every button, badge and alert in both modes (4.5:1 for text, 3:1 for large text and UI borders).

**Colour palette** (Tabler defaults; replace the primary when the organisers supply brand colours or a logo)

| Token | Colour | Used for |
|---|---|---|
| `primary` | `#066FD1` (Tabler blue) | Navbar accents, primary buttons, links, selected score |
| `secondary` | `#6B7280` (grey) | Secondary buttons, inactive, past, skipped, "not started" |
| `success` | `#2FB344` (green) | Checked in, saved, gate open, completed, official marks |
| `info` | `#4299E1` / `purple` `#AE3EC9` | Information, upcoming, edit phase (purple) |
| `warning` | `#F76707` (orange) | Needs attention, gate closed, running late, breaks, provisional |
| `danger` | `#D63939` (red) | Errors, force checkout, live state, destructive actions |

**Semantic colour map** (centralised in one config so phases, statuses and badges look the same everywhere, including the public page and the control panel)

| Thing | Value → colour and icon |
|---|---|
| Slam phase | draft/scheduled: neutral, `calendar-event` · open: info, `door-open` · checkin_open: success, `person-check` · checkin_closed: warning, `person-x` · reviewing: danger (live), `broadcast` · reviews_complete: neutral, `check2-all` · revision: info, `pencil-square` · revision_closed: neutral, `lock` · closed: secondary, `flag` · marks_calculated: success, `trophy` · ended: secondary, `archive` |
| Check-in state | checked in: success · checked out: neutral · force checked out: danger · not registered: warning |
| Schedule slot | pending: neutral/outline · open ("presenting now"): danger (live) · closed/done: success · skipped: neutral strike-through · break: warning with its type icon |
| Review window timer | more than 30 s left: primary · 30 s or less: warning · closed: neutral |
| Provisional vs official marks | provisional: warning badge · official: success badge |

Rules: **never rely on colour alone**: every status badge also has an icon and a text label; the 1–10 score buttons use shape and number (selected = filled primary), not red-to-green colouring; badge and poster headers may use the brand colour, but **QR codes stay pure black on white**.

**Icon set: Tabler Icons** (`@tabler/icons`, outline set, MIT licence; matches Tabler).
- Delivered as a **single SVG sprite** of only the icons we use (built by a small script into `public/assets/icons.svg` and used with `<svg><use href="…#name"></use></svg>`), not the icon font: smaller, no font-loading flicker on a weak network, and icons can follow `currentColor`.
- **One central map** `frontend/theme/icons` (semantic name → icon id) used by PHP views and Vue alike, so a screen asks for `phase.checkin_open` or `action.print`, never a raw icon name. The map lives in `frontend/theme/icons.json` (Tabler names such as `qrcode`, `scan`, `user-check`, `coffee` for tea, `tools-kitchen-2` for lunch, `broadcast` for live); the sprite build copies it to `public/assets/ui-map.json` for PHP.
- Accessibility: decorative icons get `aria-hidden="true"`; icon-only buttons get an `aria-label` and a tooltip; icons are at least 20 px (24 px in touch controls) and every touch target stays 44 px.
- Presenter photos and the slam banner are the only raster images; there are no icon PNGs.

**Tests/acceptance**: all screens checked at 360, 390, 768, 1280 px in light and dark; contrast audit passes for the colour map; screens render with no external network request (fonts, icons, theme); phase badge shows the same colour and icon on admin, volunteer, reviewer and public screens.

## 8. Suggested structure (CI4)

```
app/
  Config/ (Routes, Filters, App, Database)
  Controllers/ Admin/ Volunteer/ Review/ Api/
  Filters/ AuthFilter, RoleFilter, SlamScopeFilter, ThrottleFilter
  Models/
  Services/ BadgeIdService, CheckinService, ReviewWindowService, ScoringService, ScheduleService, SlamPhaseService, MarkRunService, ActivityLogService, DataTableService
  Views/ admin/ volunteer/ review/ layouts/
  Database/Migrations/ Seeds/
frontend/ (Vue 3 + Vite sources for the live screens, built into public/assets/)
frontend/theme/ (Tabler CSS-variable overrides, semantic colour/icon maps and sprite build)
modules/{Module}/ (Config/Routes.php, Controllers, Services, Models, Views, Database/Migrations — one folder per feature module)
public/ (assets, index.php)
tests/
```

Keep controllers thin; business rules (phase transitions, review-window timing, scoring, check-in) live in `Services/` so they are unit-testable.

## 9. API endpoints (JSON, session-authenticated)

- `GET /api/live/state` — for reviewers: slam `phase` and times, current open performance, the question set for the reviewer's type, `closes_at`, `server_now`, `already_reviewed`, checked-in status (support `If-None-Match`/ETag to keep polling cheap).
- `POST /api/review` — submit answers `{schedule_item_id, answers: [{question_id, score}], comment?}` (validates review window, check-in, uniqueness, that exactly the active questions for the reviewer's type are answered, scores 1–10).
- Admin: `GET/POST/PUT/DELETE /api/questions`, `POST /api/questions/reorder`, `POST /api/questions/copy-from/{slam}`
- Public (no login): `GET /slams`, `GET /slams/{slug}`, `GET /api/public/slams/{slug}/schedule` (cached JSON with ETag; only for slams with `schedule_is_public`)
- Admin schedule: `GET /api/slams/{id}/schedule`, `POST /api/slams/{id}/schedule/items` (presenter + time + duration, or a break), `PUT /api/schedule/items/{id}` (time, duration, pin), `DELETE /api/schedule/items/{id}`, `POST /api/schedule/reorder` (body: new order, `reflow` true/false, `preview` true to dry-run), `POST /api/slams/{id}/schedule/reflow-remaining`, `POST /api/schedule/items/{id}/extend-break`, `POST /api/schedule/items/{id}/end-break`, `PUT /api/slams/{id}/schedule` (save whole draft with `base_version`; 409 on conflict), `POST /api/slams/{id}/schedule/validate`, `POST /api/slams/{id}/schedule/generate` (preview or apply), `GET /api/slams/{id}/schedule/snapshots`, `POST /api/slams/{id}/schedule/snapshots/{version}/restore`, `POST /api/slams/{id}/schedule/copy-structure/{from_slam}`, `GET /admin/slams/{id}/schedule/export.pdf|csv`
- Admin: `PUT /api/slams/{id}/times` (open/close times), `GET/POST/PUT /api/presenters`, `POST /api/presenters/{id}/photo` (multipart), `POST /api/slams/{id}/performances` (new or existing presenter)
- Public: `GET /checkin` (type-the-ID page), `GET /in/{id}` (QR landing page, confirm button), `POST /api/checkin` (throttled, CSRF); `POST /api/checkout`, `POST /api/force-checkout/{attendee}`
- Admin: `GET /admin/qr` (generator screen), `GET /admin/qr/{attendee}.svg|png`, `POST /admin/qr/batch` (returns badge PDF), `GET /admin/qr/entrance.pdf`, `POST /admin/attendees/{id}/reissue-id`
- `GET /api/attendees/search?q=` (volunteer/admin)
- Admin: `POST /api/slams/{id}/open` (Slam Open), `POST /api/slams/{id}/checkin-gate/open|extend|close|reopen`, `POST /api/window/open|extend|close` (per-performance review window), `POST /api/schedule/reorder`, `POST /api/revision/open|extend|close|skip`, `POST /api/slams/{id}/close`, `POST /api/slams/{id}/marks/calculate`, `POST /api/slams/{id}/end`, `POST /api/checkin/late-override`
- Reviewer: `GET /api/my-reviews` (own scores; editable flag), `PUT /api/review/{id}` (only during revision phase)

- Tables (DataTables server-side, `POST` JSON, admin and volunteer only): `POST /api/tables/{name}` for every list in section 7, and `GET /admin/tables/{name}/export.csv` for filtered exports.

All state-changing routes: CSRF + role filter + slam scope check.

## 10. Security and integrity checklist

- Prepared statements / CI4 Query Builder only; escape output; CSRF on every POST; secure + HttpOnly + SameSite session cookies; HTTPS.
- Rate limiting on reviewer login, on `/api/review`, on search and on the public schedule pages (which are also cached).
- Review endpoint must be **idempotent** (double-tap safe): unique key + friendly "already submitted" response; wrap in a transaction.
- Activity log (section 6a): every action by every actor is logged through `ActivityLogService`; the table is append-only and the app's DB user has no UPDATE/DELETE on it.
- Don't expose attendee PII (mobile/email) to reviewers; volunteers see only their slam.
- Secrets in `.env`, never committed.
- Server-side tables: allow-listed columns for sort/search, bound parameters, page-length cap, scope and role checks on every call, escaped output, filtered exports only for admins and logged.
- Uploads: content-validated, re-encoded, random filenames, size-capped, PHP execution disabled in the uploads directory, admin-only upload endpoint.
- Scores are editable **only** during the admin-opened revision phase (section 6), never earlier, and are locked afterwards. Enforce on the server; every edit keeps its history.

## 11. Conventions

- PSR-12, `declare(strict_types=1)`, typed properties and return types.
- Migrations for every schema change; seeders for demo data (1 admin, 1 slam, 5 performances, 50 attendees).
- Tests (PHPUnit): badge ID generation/uniqueness, DataTable endpoint contract (paging cap, allow-listed sort/search, scope filtering, injection attempts), throttle/lockout, phase-transition table (allowed and forbidden moves), check-in rejected after Gate Close, hard stop at `closes_at`, review window open/extend/auto-close boundaries, marks calculation snapshots and recalculation, review rejection after close, single active check-in, scoring with missing groups, "complete reviewer" logic.
- All dates stored in UTC; display in `Asia/Kolkata`.
- Commit small, one feature per commit; run tests and `php spark migrate:status` before finishing a task.

## 12. Suggested build order

1. Project scaffold, `.env`, auth (admin/volunteer), roles/filters, layout, and the shared `DataTableService` + JS table wrapper (every later list uses it).
2. Slams (with configurable times), performances, the schedule maker screen (generate, times, reordering, breaks, versions, run-sheet, live mode), and the `SlamPhaseService` state machine with `slam_phase_log`.
3. Attendees import + badge ID generation + QR code generator screen and badge PDF export.
4. Check-in/out (volunteer panel, search, force checkout) + reviewer login with throttling.
5. Review window + live state endpoint + reviewer mobile-first screen (Vue 3), then the k6 load test of ~1,000 simulated phones (see *Live polling design*).
6. Live schedule reorder, extend/close controls, public schedule screen.
7. Scoring service, marks screen, statistics screen, mark configuration, mark runs (calculate/recalculate snapshots), Slam Close and Event Ends.
8. Hardening: audit log, repeat the load test against the full system, backup/export, deployment notes.

## 13. Open questions (confirm before building)

Decided:
- Walk-ins **cannot** be registered on the spot; only pre-registered attendees can check in.
- Scores are editable only at the end, after all performances are submitted (revision phase), then locked.
- 0 is **not** a valid score; scores are integers 1–10.
- The slam runs through this timeline: Slam Open → Gates Open (check-in allowed) → Gate Close (check-in stops) → performance review open/close, repeated per performance → All Review Close → Review Edit → Edit Close → Slam Close → Marks Calculated → Event Ends (section 6, *Slam timeline*).
- Frontend: Bootstrap 5 + Vue 3 (no jQuery), and **every screen is mobile-first** (section 7).

Assumptions to confirm:
- "Edit at the end of all submissions" = an admin-controlled revision phase after the last performance window closes, with its own timer and extension (section 6). Say if you'd rather it be open-ended until the admin locks it.
- Judges can also revise their scores in the revision phase.
- Can a reviewer see their own score right after submitting (before revision)? (default: yes, own score only)
- Judges are a separate group from audience (own weight).
- Every question is scored 1–10 and the per-review mark is the (optionally weighted) average of its answers; equal weights by default.
- Questions lock for an audience once its first review is submitted (only wording fixes afterwards).
- "Gates" in your timeline = the check-in gate at the entrance (open and close times are configurable per slam, or opened and closed by hand); "Review open/close" = the per-performance review windows, whose times the admin sets live; Slam Close comes after Edit Close.
- Slam-level times configured in advance: slam open, check-in open, check-in close, slam close (a hard stop that ends anything still open if the admin has not closed the slam by then).
- After Gate Close only an admin can late-check-in an already-registered attendee, with a reason; volunteers cannot. The first review window cannot open while the check-in gate is open.
- Everyone still checked in is auto-checked-out at Slam Close.
- The timeline is **timer-driven with admin manual overrides** (section 6, *Automation and admin overrides*): relative scheduling (each performance opens after the previous one closes plus a changeover), "hold" never changes a running countdown, and Event Ends is automatic only if `event_ends_at` is set.
- Need from you: default review minutes per performance, changeover minutes between performances, edit-window minutes (0 to skip), and the grace minutes between Edit Close and Slam Close. Seed values are placeholders until you confirm them.
- The schedule is built by adding presenters to the slam **with a planned start time and duration**, and can be reordered (times re-flow from the new order; slots can be pinned; breaks are supported). Performances never open before their planned start, and open late (not overlapping) if the event runs behind.
- Need from you: should breaks/intervals between performances be part of the schedule (supported), and should reviewers see the full running order or only "up next"? (default: both, as described)
- Public schedule screen: published per slam by the admin, shows presenters, topics and live "now / up next" status, and never shows marks, questions, review deadlines or attendee data. Need from you: should results (final marks) ever be shown publicly, and should the public page show abstracts and profiles (default: yes, expandable)?
- Breaks: tea, lunch and other breaks are schedule slots (quick-add buttons, pinned start, extend or end early). Need from you: default tea-break and lunch-break minutes (placeholders are seeded until confirmed).
- Schedule maker: edits are drafts until Save, each save is a restorable version, and a seeded random order is available in the Generate wizard. Need from you: should the running order ever be randomised (default: optional, off), and who besides admins may edit the schedule (default: admins only; volunteers read-only)?
- Tables: DataTables 2.x with server-side AJAX on every table screen, run without jQuery where possible. If an extension you want (for example Buttons or Select) turns out to need jQuery, jQuery will be loaded only on admin/volunteer table pages; say if you would rather avoid that and drop the extension.
- Look and feel: **Tabler** (your choice) with its dark mode for the reviewer screens, Tabler Icons as an SVG sprite, self-hosted fonts (Inter, Noto Sans Malayalam). Need from you: brand colours and logo if you have them (otherwise the Tabler palette is used).
- Badge QR encodes a check-in URL (`/in/{ID}`) rather than the bare ID, so any phone camera works; the check-in itself needs one button press. The plain-ID typing page stays as the fallback.
- Need from you: the public domain for check-in URLs (`APP_CHECKIN_URL`) and the physical badge size, so the PDF layout can match.
- "Marks Calculated" is an admin-triggered snapshot that can be recalculated until Event Ends; Event Ends makes everything read-only. Tie-break for equal final marks defaults to judges' average, then users' average.
- Presenters are reusable across slams (final can reuse a regional presenter) with a topic and abstract per slam; profile and photo belong to the presenter.
- Need from you: the actual wording of the 2 user questions, and the number and wording of the judges' questions (placeholders are seeded until then).

Still open:
- Do the 4 regional slams feed finalists into the final automatically, or is the final configured manually?
- Expected number of simultaneous phones per slam (drives polling interval and caching)?
- Should the complete-reviewers weightage apply only to audience users, or also to judges?
