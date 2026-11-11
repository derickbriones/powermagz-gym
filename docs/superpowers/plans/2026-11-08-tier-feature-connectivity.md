# Tier Feature Connectivity — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every advertised tier feature real and keep member, partner, and admin reading the same rules.

**Architecture:** One server-side capability helper is the source of truth. Payments, classes, AI, and partner payouts read it. A small JSON API mirrors the same data for dynamic UI. Portals keep the existing dark design system.

**Tech Stack:** PHP 8, custom MVC (`App\Core`), MySQL (XAMPP), Tailwind CDN, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-11-08-tier-feature-connectivity-design.md`

## Global Constraints

- Tier pricing: Digital ₱500/30d, Standard ₱1,000/30d, Premium ₱2,000/30d.
- All tiers get QR time in/out.
- Server-side capability checks read the active enrollment, never form input.
- Every POST calls `csrf_verify()` and validates.
- No new dependencies. No external AI API.
- Keep the existing portal design system; no visual rebuild.
- 12px radii on new cards, teal `#00A6A6`, dark `#111` surfaces.
- All new controls keyboard accessible; locked states use text, not color alone.
- No git repo: do not assume commits; verify with PHP lint and Playwright.

## Review Focus

- A member with no active enrollment must see locked capabilities and no class booking.
- A member cannot book a full class or double-book the same class/date.
- An expired or pending enrollment must not unlock gym/class/coach features.
- A member cannot submit a payment method the admin disabled.
- A partner cannot request a payout above the available balance (already enforced) or with an empty method detail.

---

### Task 1: Capability Layer

**Files:**
- Create: `src/Helpers/capabilities.php`
- Modify: `src/bootstrap.php` (helper list)
- Test: `tests/capabilities_test.php`

**Interfaces:**
- Produces: `tier_capabilities(string $tier): array`, `tier_can(string $tier, string $cap): bool`, `member_capabilities(): array`, `member_can(string $cap): bool`, `capability_meta(): array`.

- [ ] Step 1: Write `tests/capabilities_test.php` asserting Digital has `qr_checkin`/`ai_recommender` but not `gym_access`, Standard has `group_classes` but not `ai_coach`, Premium has all, `b2b` has none.
- [ ] Step 2: Run `php tests/capabilities_test.php` → FAIL (function missing).
- [ ] Step 3: Implement the five functions with the matrix from the spec. `member_capabilities()` uses `Enrollment::activeForUser(current_user()['id'] ?? '')['tier'] ?? ''`.
- [ ] Step 4: Add `capabilities` to the bootstrap helper foreach list.
- [ ] Step 5: Run test → PASS; run `php -l` on both files.

### Task 2: Payment Methods + Reference

**Files:**
- Modify: `src/Controllers/MemberController.php` (`enroll`, `programs`)
- Modify: `src/Views/member/partials/enroll_modal.php`
- Modify: `src/Views/member/payments.php` (show method/reference)
- Test: `tests/payment_methods_test.php`

**Interfaces:**
- Consumes: `Payment::methodConfig()`, `Payment::METHODS`, `Payment::methodLabel()`, `Payment::methodIcon()`, `tier_capabilities()`.
- Produces: enrollment accepts `method`, `reference_no`.

- [ ] Step 1: Test: `Payment::methodConfig()['accepted_methods']` is a subset of `Payment::METHODS`; rejecting `paypal`; requiring reference for `gcash`.
- [ ] Step 2: In `MemberController::programs()` pass `paymentMethods` and `capabilities`; in `enroll()` validate method against accepted list and reference (required for non-cash, 6–80 chars `[A-Za-z0-9\-]`), store both, and guard with `tier_capabilities($program['tier'])`.
- [ ] Step 3: Rewrite the payment section of `enroll_modal.php`: radio cards per accepted method, reference input shown for non-cash, cash note. Keep the existing modal shell and classes.
- [ ] Step 4: Show chosen method + reference in member `payments.php` rows.
- [ ] Step 5: Run test; `php -l` changed files.

### Task 3: Partner Payout Method

**Files:**
- Modify: `src/Controllers/PartnerController.php` (`requestPayout`)
- Modify: `src/Views/partner/partials/payout_modal.php`
- Modify: `src/Views/admin/partials/payouts_tab.php`

- [ ] Step 1: Validate `method` in `['gcash','bank_transfer','cash']`, require `method_details` (6–120 chars), keep balance check.
- [ ] Step 2: Add method select + account detail input to the payout modal; keep amount.
- [ ] Step 3: Show method/details and capture `reference_no` in admin payout actions if the form lacks it.
- [ ] Step 4: `php -l` changed files.

### Task 4: Coach-Only Partners

**Files:**
- Create: `database/migrations/2026-11-08_tier_connectivity.sql`
- Modify: `database/schema.sql` (mirror)
- Modify: `src/Controllers/PartnerController.php` (`saveProfile`, `logSale`)
- Modify: `src/Views/partner/partials/sale_form.php`
- Modify: `src/Views/partner/partials/partner_profile_form.php`
- Modify: `src/Views/admin/partials/partners_tab.php`
- Modify: `src/Controllers/AdminController.php` (any supplier branch)

- [ ] Step 1: Migration: convert supplier rows to coach; narrow `partner_profiles.partner_type`, `partners.type`, widen `partner_sales.type` to `('client_payment','session')`; convert product rows. Mirror in schema.
- [ ] Step 2: Remove supplier from validation and UI labels; sale form only client payment/session.
- [ ] Step 3: Grep for `supplier` and `product` in views/controllers; remove remaining user-facing labels.
- [ ] Step 4: `php -l`; apply migration via `mysql` CLI after a `mysqldump` backup.

### Task 5: Group Classes

**Files:**
- Create: `src/Models/GymClass.php`, `src/Models/ClassBooking.php`
- Modify: `src/Controllers/MemberController.php` (`classes`, `bookClass`, `cancelClass`)
- Create: `src/Views/member/classes.php`
- Modify: `src/Controllers/AdminController.php` (`saveClass`, `deleteClass`, tabs)
- Create: `src/Views/admin/partials/classes_tab.php`
- Modify: `index.php` (routes)
- Modify: `src/Views/layouts/portal.php` (sidebar)

**Interfaces:**
- Produces: `GymClass::upcomingForTier(string $tier): array`, `ClassBooking::seatsLeft(string $classId, string $date): int`, `ClassBooking::forUser(string $userId, string $date): array`.

- [ ] Step 1: Migration creates `classes` + `class_bookings`; seed 4 classes (`tier_min='standard'`).
- [ ] Step 2: Models with fillable + the three helpers.
- [ ] Step 3: Member routes guarded by `member_can('group_classes')`; book inside a transaction with `SELECT ... FOR UPDATE` and unique key; cancel before start.
- [ ] Step 4: Member `classes.php` schedule list with seat count, Book/Cancel, tier-locked empty state.
- [ ] Step 5: Admin classes tab CRUD + member sidebar entry.
- [ ] Step 6: Test: booking twice fails; full class fails; Digital gets 403.

### Task 6: Recommender + AI Coach

**Files:**
- Create: `src/Helpers/ai.php` (or `src/Services/Recommender.php`)
- Modify: `src/Controllers/MemberController.php` (`recommender`, `aiCoach`)
- Create: `src/Views/member/recommender.php`
- Modify: `src/Views/member/home.php` (section)

**Interfaces:**
- Produces: `workout_recommendation(array $context): array`, `ai_coach_reply(string $question, array $context): string`.

- [ ] Step 1: Deterministic plan from tier + attendance count + goal; returns title, split, exercises, why.
- [ ] Step 2: Premium-gated coach reply from a keyword map; includes a not-medical-advice line.
- [ ] Step 3: Member route + view card; home section renders recommender for all tiers and locks coach for non-Premium.
- [ ] Step 4: Test: Digital plan has no gym equipment; Premium coach answers "schedule".
- [ ] Step 5: `php -l`.

### Task 7: API Endpoints

**Files:**
- Modify: `index.php`
- Modify: `src/Controllers/MemberController.php`, `PartnerController.php`, `AdminController.php`

- [ ] Step 1: Add `GET /api/member/capabilities`, `/api/member/payment-methods`, `/api/member/classes`, `POST /api/member/classes/book|cancel`, `GET /api/member/recommender`, `POST /api/member/ai-coach`.
- [ ] Step 2: Add `GET /api/partner/clients`, `/api/partner/classes`, `POST /api/partner/payout`.
- [ ] Step 3: Add `GET /api/admin/capabilities`, `POST /api/admin/classes/save`.
- [ ] Step 4: Each returns `{ ok, data, error }`, uses `auth` + role middleware, 403 on wrong role.
- [ ] Step 5: Smoke test with `curl` against the local server.

### Task 8: UI Capability States + Sidebar

**Files:**
- Modify: `src/Views/layouts/portal.php`
- Modify: `src/Views/member/home.php`
- Modify: `src/Views/admin/partials/programs_tab.php`

- [ ] Step 1: Add Classes + AI Coach sidebar entries with lock badges for ineligible tiers.
- [ ] Step 2: Home: show capability chips; hide Sessions Left unless Premium; add recommender/coach cards.
- [ ] Step 3: Programs tab: capability badges per tier from `capability_meta()`.
- [ ] Step 4: Verify contrast, focus, reduced motion; no layout overflow at 390.

### Task 9: Verification

- [ ] `php -l` every changed PHP file.
- [ ] Run `tests/*.php`.
- [ ] Playwright 1440/1024/768/390: member enroll (each method), admin confirm, capabilities unlock, class book/cancel, recommender, Premium coach lock/unlock, partner payout, admin class CRUD.
- [ ] Check console errors and horizontal overflow.

## Self-Review

- Spec coverage: capabilities (T1), payments (T2), payouts (T3), supplier removal (T4), classes (T5), AI (T6), API (T7), UI (T8), verification (T9).
- Type consistency: capability ids and helper names fixed in T1 and reused verbatim.
- Review Focus tests assigned to T1 (no enrollment), T2 (disabled method), T5 (full/double/tier), T3 (payout detail).

## Status

All tasks (1-9) are implemented and verified. Detailed evidence is recorded in
`docs/superpowers/ledger-tier-connectivity.md`. Last verification run: 2026-11-09
(4 test scripts pass, 120 PHP files lint clean, responsive checks at 390/768/1024/1440,
authenticated API smoke tests for member/partner/admin, test data cleaned up).

