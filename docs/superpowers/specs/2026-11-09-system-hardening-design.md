# System Hardening + Cleanup — Design & Implementation Plan

Date: 2026-11-09
Source: `docs/superpowers/audits/2026-11-09-system-audit.md`
Scope approved by user: Must fix + Should fix + cleanup (findings 1-16).

## Goal

Close the security holes, make expiry and payouts correct, remove the dead weight, and keep every portal working. No new features beyond wiring actions that already have handlers.

## Approach

Fix in five independent passes. Each pass lints, runs the 4 existing tests, and re-probes the affected pages before moving on. Add a focused test wherever the pass fixes money, auth, or date logic (per `test-driven-development`). Run `requesting-code-review` at the end.

---

## Pass 1 — Stop leaking files (finding 1, 13)

**Code**
- `.htaccess`: before the file-exists rule, deny:
  - `^(config|src|storage|tests|database)/` → `[F,L]`
  - `FilesMatch` for `.env*`, `*.sql`, `*.log`, `*.md`, `composer.json`, `composer.lock`, `docker-compose.yml`.
- `router.php`: mirror the same guard for the dev server (return 404 before `file_exists`).
- Create `public/offline.html` (same content as the current offline view, no PHP).
- `public/sw.js`: resolve `offline.html` from `self.location` instead of `/src/Views/...`.
- Delete `src/Views/errors/offline.php` once the service worker no longer points at it.

**Verify**
- `GET /.env`, `/storage/backups/*.sql`, `/storage/logs/php-error.log`, `/database/schema.sql`, `/ADMIN_GUIDE.md` all return 404.
- `GET /public/offline.html` returns 200.
- Normal pages still 200.

---

## Pass 2 — Correct access rules (findings 2, 3, 6)

**Code**
- `MemberController::timeOut()`: load the record, then 404 unless `record['user_id'] === current_user()['id']`. Add `return` for the not-found path.
- `Enrollment::activeForUser()`: include the date rule in SQL instead of relying on the admin-only `syncExpirations()`:
  `status = 'active' AND (end_date IS NULL OR grace_until >= CURDATE() OR (grace_until IS NULL AND end_date >= CURDATE() - INTERVAL 3 DAY))`.
  Keep `syncExpirations()` for the admin status labels.
- `index.php`: add `['auth']` to `GET /api/occupancy`.

**Tests (new `tests/enrollment_lifecycle_test.php`)**
- Active row with `end_date` in the past and no grace → `activeForUser()` returns null.
- Active row inside grace → returns the row.
- `Attendance::belongsTo($id, $userId)` (new helper) is true for the owner, false for another user; `timeOut` uses it. Test both cases.

---

## Pass 3 — Money correctness (findings 4, 7)

**Code**
- Move `src/backup.php` → `scripts/backup.php` and `src/keygen.php` → `scripts/keygen.php`. Both already use `dirname(__DIR__)`, so they keep working from `scripts/`.
- `PayoutRequest::requestPayout()`: wrap the insert in a transaction that first locks the partner's `users` row (`SELECT id FROM users WHERE id = ? FOR UPDATE`), then re-reads the balance inside the transaction and throws if the amount exceeds it.
- `PartnerController::requestPayout()` and `apiPayout()`: catch the exception and show the existing "exceeds available balance" message. Keep the pre-check for a fast, friendly error.
- Extract commission math to `PartnerSale::commissionFor(array $sale, float $defaultRate = 10): float` and use it from the controller, so it is testable.

**Tests (new `tests/payouts_test.php`)**
- `commissionFor()` handles a precomputed commission, a rate, and the 10% fallback.
- Two sequential payout requests cannot exceed the available balance (insert a temp approved sale, request the full amount twice, second throws; clean up).

---

## Pass 4 — Remove dead routes and wire the rest (finding 5, 9, 12)

**Code**
- Delete routes and methods that duplicate the member edit form:
  - `POST /admin-dashboard/members/role`
  - `POST /admin-dashboard/members/reset-password`
  - `POST /admin-dashboard/enrollments/update`
- Delete the two broken routes:
  - `POST /admin-dashboard/settings` (`saveSetting`)
  - `POST /admin-dashboard/notifications/read` (`markRead`)
- Delete `AdminController::occupancy()` (unused copy of `liveOccupancy`).
- Wire the remaining handlers with small POST buttons + confirm modals that reuse the existing modal pattern:
  - `enrollments/expire` and `enrollments/delete` in the enrollment row actions.
  - `partners/delete` in the partner row actions.
  (If a row already has an equivalent control, delete the route instead and say so.)

**Verify**
- Route/handler check reports zero missing.
- Every visible action button POSTs successfully on the scratch DB.
- No dead routes remain.

---

## Pass 5 — Load only the active tab (finding 10)

**Code**
- `AdminController::dashboard()`: build `$data` per active tab using the existing tab→key map (recorded in the audit work). Keep shared keys (`users` only where a tab reads them).
- Each branch runs only its tab's queries. `overview` keeps the summary set; `settings`, `audit`, `qr` get the minimum.
- No view changes: each partial only reads its own keys.

**Verify**
- Every tab still renders with no "undefined array key" warnings.
- Compare query count on `?tab=settings` before/after (log or `SHOW SESSION STATUS`); expect a large drop.
- Page probe all 13 tabs again.

---

## Pass 6 — Cleanup (findings 11, 14, 15, 16)

**Delete (verified unreferenced)**
- `src/Core/Request.php`, `src/Core/Response.php`, `src/Models/BaseModel.php`, `src/Models/Partner.php`
- `src/Helpers/auth.php`, `csrf.php`, `flash.php`, `format.php`, `url.php`
- `public/js/charts.js`, `qr-scanner.js`, `form-validation.js`, `theme.js`
- `public/css/app.css`, `dark.css`, `a11y.css`
- `src/Views/admin/partials/stat_card.php`, `src/Views/site/social_proof.php`, `src/Views/site/why_choose_us.php`, `src/Views/errors/500.php`

**Copy**
- Replace "GCash payments" in `NotificationController` and the auth sidebar with "pending payments".
- Fix mojibake in files that show it in visible copy (UTF-8 re-save + string repair).

**Verify**
- Tree-wide grep shows no reference to any deleted path.
- Lint clean, all tests pass, page probe clean.

---

## Final verification

- PHP lint every file.
- Run all tests (`tests/*_test.php`).
- Route check: 0 missing handlers.
- Scratch-server probe: every admin tab, member page/API, partner page/API returns 200 with no PHP warnings.
- Re-run the IDOR test: member cannot close another user's attendance.
- Re-run the exposure test: no secret file is reachable.
- Public files `.env`, `*.sql`, `*.log`, `*.md` all 404.
- `php scripts/backup.php --dump-only` and `php scripts/keygen.php` (no-op when key exists) run.
- `requesting-code-review` pass over the final diff.
- Record results in `docs/superpowers/audits/2026-11-09-system-audit.md` under "Verification done".

## Risks / notes

- Blocking `src/` breaks any hard-coded web path to a view. The only one found is the service worker, fixed in Pass 1.
- Deleting enrollment/partner actions: keep the handlers that now have buttons; only delete what is genuinely redundant.
- No git repo, so changes are not committed. Verification is recorded in the audit doc and ledger.
- Do not run `database/schema.sql` against the live DB.
- All functional testing stays on `powermagz_gym_audit` (port 8098).
