# PowerMagz Gym — Whole-System Audit

Date: 2026-11-09
Scope: member, partner, and admin portals (routes, controllers, models, views, JS, helpers).
Method: read every route handler, linted every PHP file, probed all portal pages on a scratch copy of the database (`powermagz_gym_audit`, port 8098), ran end-to-end POST flows, and tested the suspected holes.

## What this repo does

PowerMagz is a single-gym membership system. It has a public marketing site, a member portal (enroll, pay, QR check-in, attendance, classes, workout plan, AI coach), a coach/partner portal (clients, logged sales, commission wallet, payouts), and an admin portal (members, programs/tiers, enrollments, payments, attendance, partners, payouts, classes, audit log, settings). Data lives in MySQL; there is no framework and no Composer runtime dependency.

Assumed load: one gym, one or two admins, a few staff, a handful of coaches, maybe a few hundred members, around 50 people in the gym at peak. Not internet-scale. The findings below are judged against that load.

---

## Must fix

### 1. Secrets and database backups are downloadable by anyone
(`.htaccess`, `router.php`, `.env`, `storage/backups/…sql`, `database/schema.sql`)

- **What this is:** The document root is the project root. `.htaccess` only rewrites unknown URLs to `index.php`; it does not deny existing files.
- **Problem:** I fetched these with no login:
  - `/.env` → 200, contains the DB password, AWS keys, and `GYM_QR_DATA` (the secret that proves a check-in came from the gym poster).
  - `/storage/backups/powermagz_gym_pre_tier_connectivity.sql` → 200, contains user rows and password hashes.
  - `/storage/logs/php-error.log` and `/database/schema.sql` → 200.
  Anyone who knows `GYM_QR_DATA` can check in from home. Anyone with the backup can attack the password hashes.
- **Fix:** Add `Require all denied` (Apache 2.4) blocks for `.env`, `*.sql`, `*.log`, `storage/`, `database/`, `config/`, `src/`, `tests/`, and `*.md`, and add the same guard in `router.php` for the dev server. Move `.env` one level above the web root if the host allows it.
- **If we skip it:** Full database and gym-secret leak on day one of public hosting.

### 2. A member can clock out any other person's attendance
(`src/Controllers/MemberController.php:253-272`)

- **What this is:** `POST /time-in/out` takes a `record_id` and closes that record.
- **Problem:** `timeOut()` never checks that the record belongs to the logged-in user. I logged in as the demo member and sent the admin's attendance ID; the server replied `{"status":"success"}` and wrote the admin's `time_out` and `hours`. Any member can close or falsify anyone's session if they get an ID.
- **Fix:** After `Attendance::find($recordId)`, return 404 unless `(string)$record['user_id'] === (string)current_user()['id']`.
- **If we skip it:** Attendance and payroll-by-hours data can be tampered with by any member.

### 3. Expired memberships keep working until an admin opens the dashboard
(`src/Models/Enrollment.php:485`, `src/Controllers/AdminController.php:41`)

- **What this is:** `Enrollment::syncExpirations()` flips past-due `active` rows to `expired`.
- **Problem:** It is only called from `AdminController::dashboard()`. If no admin opens the dashboard, a member whose `end_date` passed days ago is still returned by `Enrollment::activeForUser()`, so `/time-in/scan`, class booking, and the AI coach keep working. I confirmed `activeForUser()` never looks at the date.
- **Fix:** Call `syncExpirations()` once early in the member request path (e.g. in `bootstrap.php` with a cheap "did it run today" guard), or make `activeForUser()` also require `end_date`/`grace_until` to be in the future.
- **If we skip it:** People get free gym access after their plan ends.

### 4. The documented backup and key commands do not exist
(`ADMIN_GUIDE.md`, `.env.example`, `src/backup.php`, `src/keygen.php`)

- **What this is:** The guide tells operators to run `php scripts/backup.php` and `php scripts/keygen.php`.
- **Problem:** Both files live in `src/`, and `scripts/` contains only `show-totp.php`. Following the guide fails with "Could not open input file". The scheduled backup therefore never runs, and no one notices until a restore is needed.
- **Fix:** Move the two files to `scripts/` (their own headers already say `scripts/…`) and verify the cron/Windows Task Scheduler command runs.
- **If we skip it:** No working backups; a bad migration or disk failure loses the gym's data.

### 5. Two routes call methods that do not exist
(`index.php:198`, `index.php:214`)

- **What this is:** `POST /admin-dashboard/settings` maps to `AdminController@saveSetting`, and `POST /admin-dashboard/notifications/read` maps to `NotificationController@markRead`.
- **Problem:** Neither method exists. Any POST to those URLs is a fatal error. The current Settings tab uses the per-setting routes, so the bug is hidden, but the URLs are live.
- **Fix:** Delete both routes (they have no caller), or implement the methods if the generic form is wanted.
- **If we skip it:** A stale form or bookmark produces a 500 with no useful message.

---

## Should fix

### 6. The live occupancy endpoint is public
(`index.php:206`, `MemberController::occupancy`)

- **What this is:** `GET /api/occupancy` returns how many people are in the gym and the hourly histogram.
- **Problem:** The route has no `auth` middleware. I fetched it with no cookie and got a full JSON body. It leaks when the gym is empty, which is useful to someone planning a theft.
- **Fix:** Add `['auth']` (the widget only renders on the logged-in home page).
- **If we skip it:** Publicly readable real-time occupancy.

### 7. Two payout requests at the same moment can overdraw the wallet
(`src/Controllers/PartnerController.php:317-333`)

- **What this is:** `requestPayout()` reads the available balance, checks the amount, then inserts a payout row.
- **Problem:** The check and the insert are separate statements outside a transaction. Two simultaneous requests both see the old balance and both insert, so the partner can reserve more than they earned.
- **Fix:** Move the balance check and insert into one transaction in `PayoutRequest::requestPayout()`, and lock the partner's payout rows (`SELECT … FOR UPDATE`) while recomputing.
- **If we skip it:** Low frequency, but it is direct money loss when it happens.

### 8. The risky paths have no tests
(`tests/`)

- **What this is:** There are 4 test files: payment methods, classes, capabilities, AI.
- **Problem:** Nothing tests commission math, payout limits, enrollment expiry, or attendance ownership — the exact places where findings 2, 3, and 7 live. A future edit can silently reintroduce them.
- **Fix:** Add one test per rule: earned commission for a sale, available balance after a pending payout, `activeForUser()` on a past-due row, and `timeOut()` on a foreign record.
- **If we skip it:** These are the bugs that come back.

### 9. Six admin routes have no UI
(`index.php:132-133,138,143,146,182`)

- **What this is:** `members/role`, `members/reset-password`, `enrollments/update`, `enrollments/delete`, `enrollments/expire`, `partners/delete` all have handlers but no button, form, or JS call anywhere in the views.
- **Problem:** Role change and password reset are covered by the member edit form, so those two routes are duplicates. But enrollment edit/delete/expire and partner delete cannot be performed at all from the admin UI, even though the code exists.
- **Fix:** Either add the missing controls, or delete the routes and methods. Do not leave both a working handler and no way to reach it.
- **If we skip it:** Admins keep asking for features that "already exist".

### 10. Every admin tab runs every module's analytics
(`src/Controllers/AdminController.php:39-208`)

- **What this is:** `dashboard()` builds one big `$data` array for all 13 tabs.
- **Problem:** Opening Settings still runs attendance heatmaps, 12-month trends, retention correlation, partner acquisition, and six 500-row filtered lists. As attendance and payment rows grow, every admin page slows down and uses more memory for data the page will not render.
- **Fix:** Compute only the arrays the active tab uses, or load the heavier tab data on demand via the existing JSON endpoints.
- **If we skip it:** Admin pages get slower every month, and the first one to feel it will be the busiest tab.

### 11. Five duplicate helper files sit next to the real one
(`src/Helpers/auth.php`, `csrf.php`, `flash.php`, `format.php`, `url.php`)

- **What this is:** `bootstrap.php` loads only `functions.php`, which already defines the same functions.
- **Problem:** The duplicates are dead, but `auth.php` is also *stale*: it lacks the session-fixation fix and CSRF-token reset that `functions.php` has. If anyone "helpfully" loads it later, login security regresses.
- **Fix:** Delete the five files. `format.php`'s one unique function, `peso_decimal()`, is unused.
- **If we skip it:** A loaded-once duplicate silently removes a security fix.

### 12. `AdminController::occupancy` is an unused copy of `liveOccupancy`
(`src/Controllers/AdminController.php:2242`)

- **What this is:** Two occupancy methods exist; the route uses `liveOccupancy`.
- **Problem:** The older copy is never called and can drift from the live one.
- **Fix:** Delete `occupancy()`.
- **If we skip it:** Future fixes land on the wrong copy.

### 13. The service worker caches a PHP view by a hard-coded path
(`public/sw.js:6`)

- **What this is:** The offline fallback URL is `/src/Views/errors/offline.php`.
- **Problem:** The app is deployed under `/powermagz-gym/`, so that path 404s; the install-time `cache.addAll()` rejects and the offline fallback is never available. It also bypasses the router and assumes `src/` stays web-visible — the opposite of finding 1.
- **Fix:** Serve a small offline HTML file from `public/` and build its URL from the base path; stop exposing `src/`.
- **If we skip it:** Members lose the offline page, and the service worker installs with an error.

---

## Nice to have

### 14. Dead files: about 1,600 lines to delete
- Empty: `src/Core/Request.php`, `src/Core/Response.php`, `src/Models/BaseModel.php`, `public/js/charts.js`.
- Unused JS: `public/js/qr-scanner.js`, `form-validation.js`, `theme.js` (the time-in page has its own inline scanner).
- Unused CSS: `public/css/app.css`, `dark.css`, `a11y.css`.
- Unused views: `src/Views/admin/partials/stat_card.php`, `src/Views/site/social_proof.php`, `src/Views/site/why_choose_us.php`, `src/Views/errors/500.php`.
- Unused model: `src/Models/Partner.php`.
- **Fix:** Delete them. Check each with a tree-wide grep first (already done), then run the page probe again.

### 15. Some UI text still says GCash only
(`src/Controllers/NotificationController.php:108`, auth layout sidebar)

- **What this is:** An admin alert says "confirm member GCash payments"; the auth sidebar mentions GCash.
- **Problem:** The gym now accepts every method the admin enables (cash, bank transfer, card), so the alert is wrong.
- **Fix:** Say "pending payments".

### 16. Source comments contain mojibake
(many files, e.g. `src/Controllers/NotificationController.php`, `src/Views/partner/partials/sale_form.php`)

- **What this is:** Sequences like `dY"?` and `�?"` appear where em dashes and symbols used to be.
- **Problem:** Some have leaked into visible copy. It looks unprofessional and can hide the real text.
- **Fix:** Re-save those files as UTF-8 and replace the broken sequences; do it during the cleanup pass, not separately.

---

## Verification done (pre-fix)

- PHP lint: clean across every project file.
- Route/handler check: 135 routes; only the two in finding 5 are missing.
- Page probe (scratch DB): all admin tabs, member pages/APIs, partner pages/APIs return 200 with no PHP warnings or fatal errors.
- End-to-end POST flows passed on the scratch DB: admin settings, class create, member enroll, profile save, enrollment approve, payment confirm, QR time-in/out, partner add client, log sale, admin sale approve (10% = ₱100), payout request.
- IDOR (finding 2) reproduced against the scratch DB.
- Public file exposure (finding 1) reproduced against the preview server.
- All 86 distinct `onclick` handlers checked: each one is defined.

## Verification done (post-fix, 2026-11-10)

- **Lint:** every `.php` file in the project lints clean.
- **Routes:** 127 routes after removing the dead ones; every handler class/method exists.
- **Admin:** logged in as `admin@powermagz.ph` on the scratch DB (:8098); all 13 dashboard tabs return 200 with no `Fatal error`, `Warning`, `Deprecated`, `Notice`, or `Uncaught`.
- **Member:** all 9 pages plus 5 JSON APIs return 200 with no PHP errors.
- **Partner:** all 5 pages plus 2 JSON APIs return 200 with no PHP errors.
- **IDOR (finding 2):** fixed — posting another member's attendance id returns `{"error":"Not found"}` and the admin record is unchanged.
- **Public exposure (finding 1):** fixed on both :8098 and :8099 — `/.env`, `/.env.example`, `/database/schema.sql`, `/storage/logs/app.log`, `/composer.json`, `/docker-compose.yml`, `/README.md`, and `/docs/**/*.md` all return 404, including with the app subfolder prefix (`/powermagz-gym/...`). `/public/offline.html` returns 200.
- **Routes removed:** the deleted admin endpoints now return 404.
- **Tests:** all 6 test files pass on `powermagz_gym_audit` (`ai`, `capabilities`, `classes`, `enrollment_lifecycle`, `payment_methods`, `payouts`).
- **Money/date paths:** payout balances are re-checked inside a transaction with the partner row locked; commission lives in one place (`PartnerSale::commissionFor()`); enrollment activity is decided in SQL against the end date and grace period.
- **Dead code:** `BaseModel`, `Partner`, `Request`, `Response`, the five old helper files, the unused JS/CSS, and the orphan views were deleted; grep finds no remaining references (only the audit/spec documents mention the old names).
- **Apache:** `http://localhost/powermagz-gym/` and `/login` return 200 with no PHP errors; `.env`, `database/schema.sql`, `composer.json`, `src/`, and `storage/` return 403. `RewriteBase` was removed so the same `.htaccess` works at a subfolder or at the domain root.
- **Service worker:** `offline.html` is resolved relative to `sw.js`, and only page navigations fall back to it; failed assets/APIs no longer receive HTML.

## Verdict

The 16 findings are fixed. The remaining caveats are deployment-level: on Render the file guard depends on the server honoring `.htaccess` or routing through `router.php` (the hard-coded `RewriteBase` was removed so the same `.htaccess` works at a subfolder or at the domain root), and real Google/Facebook login still needs OAuth credentials and routes. The user-owned `Dockerfile`, `start.sh`, `docker-compose.yml`, `.gitignore`, and `.dockerignore` were left untouched.

## Lean

- About 1,600 lines and 5 duplicate helper files were deleted in the cleanup pass.
- No dependency can be removed: the app already has no runtime Composer packages; Tailwind and fonts are CDN-only.

## Not checked

- Real Google/Facebook login (UI exists, no OAuth credentials or routes).
- SMTP delivery: `send_mail()` falls back to `error_log` locally; I did not send real mail.
- MFA TOTP flow beyond static review.
- Live Apache behavior under HTTPS; only the PHP dev server at `127.0.0.1:8099` and the scratch server at `:8098` were probed.
- Load testing; judged against the assumed small-gym load.
