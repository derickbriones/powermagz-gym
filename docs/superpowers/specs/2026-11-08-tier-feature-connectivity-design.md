# Tier Feature Connectivity — Design Document

**Status:** Draft for approval
**Date:** 2026-11-08
**Scope:** Member, partner, and admin portals; tier capabilities; payments; coach-only partners; group classes; rule-based AI.
**Out of scope:** Public marketing redesign, auth redesign (both already shipped), real OAuth, external AI APIs, POS, video library, progress tracking.

---

## 1. Problem

The three portals disagree about what a membership includes.

- Tier features exist only as marketing copy in `programs.features` JSON; no code enforces or unlocks them.
- Enrollment hard-codes `method = 'gcash'` (`MemberController::enroll()`), while admin confirms cash/bank/card. Members can't choose or see those methods.
- Partner accounts still model a "supplier" who sells products, but partners are coaches.
- Standard advertises group classes; there are no class tables or routes.
- Digital advertises an AI workout recommender; Premium advertises a 24/7 AI coach. Neither exists.
- Partner payout stores only a free-text `note`; `payout_requests` already has `method`, `method_details`, `reference_no` unused.

Result: features are advertised but absent, and the three roles see different truths.

## 2. Requirements

Fixed pricing and entitlements (user-confirmed):

| Tier | Price | Entitlements |
|---|---|---|
| Digital | ₱500 / month | Digital workout system, QR time in/out, attendance calendar, AI workout recommender |
| Standard | ₱1,000 initial (₱500 membership + ₱500 monthly) | Full gym access, QR time in/out, attendance tracking, group class booking, GCash payments |
| Premium | ₱2,000 / month | Everything in Standard + personal coaching, nutrition guidance, priority class booking, AI coach 24/7 |

- **All tiers get QR time in/out.**
- Keep QR and AI features.
- Remove the supplier role entirely (schema, UI, labels, sale types).
- Members must see and choose the admin-configured payment methods (GCash, cash, bank transfer, credit card).
- Member/partner/admin must read the same source of truth.
- Public/auth surfaces keep the current design. Portals use the existing dark design system; add capability badges and honest locked states, not a visual rebuild.

## 3. Approaches

### A. One capability layer, small API, finish missing features — **chosen**
- Single server-side capability map consumed by all three roles.
- Minimal JSON API for cross-role sync (entitlements, methods, class seats, approvals).
- Finish the missing tier features (classes, rule-based recommender, Premium coach).
- Remove supplier.
- Smallest change that makes every advertised feature real.

### B. Wire only what exists — rejected
Fixes payments and supplier, but Standard's classes and Digital's AI stay fake. Fails the tier promise.

### C. Full reference-repo parity — rejected
Class scheduling, coach assignment, POS, video library, progress tracking. Large, duplicates modules, high risk without user demand.

## 4. Tier Capability Layer

### 4.1 Single source of truth

New helper `src/Helpers/capabilities.php`. Register it in the guarded helper list in `src/bootstrap.php` (`foreach (['audit', ..., 'mailer'])`), after `functions.php` since it depends on auth/enrollment helpers.

```php
/**
 * Canonical tier capability map. Every portal reads this — no duplicated conditionals.
 * Keys are capability ids; values are booleans.
 */
function tier_capabilities(string $tier): array
```

Capability ids:

| Capability id | Digital | Standard | Premium |
|---|---|---|---|
| `qr_checkin` | ✔ | ✔ | ✔ |
| `attendance_calendar` | ✔ | ✔ | ✔ |
| `digital_workouts` | ✔ | ✔ | ✔ |
| `ai_recommender` | ✔ | ✔ | ✔ |
| `gym_access` | – | ✔ | ✔ |
| `group_classes` | – | ✔ | ✔ |
| `class_priority_booking` | – | – | ✔ |
| `personal_coaching` | – | – | ✔ |
| `nutrition_guidance` | – | – | ✔ |
| `ai_coach` | – | – | ✔ |

- Digital gets `ai_recommender` because its tier copy promises it; Standard and Premium inherit it via the rule-based engine (same engine, no extra cost).
- `gym_access`, `group_classes` derive from `tierRank >= standard`; Premium adds priority, coaching, nutrition, AI coach.
- Unknown/`b2b` tier: treat as staff/internal — no member capabilities.

Helpers:

```php
function tier_can(string $tier, string $capability): bool;
function member_capabilities(): array;        // active enrollment tier or []
function member_can(string $capability): bool;
function capability_meta(): array;            // id => label, description, icon, upgrade hint
```

`member_capabilities()` reads `Enrollment::activeForUser(current_user()['id'])`. No active enrollment → all false (except profile-level access). Expired/grace uses the same rule as QR check-in today: only `active` counts.

### 4.2 Why a helper, not model methods
The capability map is cross-role: admin needs to render tier matrices, partner needs to know if a client can book a class, member needs locked states. One function avoids three drifting copies.

## 5. Payment Methods and References

### 5.1 Current state
- `Payment::methodConfig()['accepted_methods']` reads `system_settings.accepted_payment_methods` (JSON) and intersects with `Payment::METHODS = ['gcash','cash','credit_card','bank_transfer']`.
- Admin settings already writes this key (`AdminController::savePaymentMethods`).
- `MemberController::enroll()` hard-codes `'method' => 'gcash'` and ignores `reference_no`.

### 5.2 Target flow

**Member enrollment modal** (`src/Views/member/partials/enroll_modal.php`):
1. Load accepted methods via `Payment::methodConfig()`.
2. Render one radio card per accepted method with label + icon (`Payment::methodLabel`, `methodIcon`).
3. GCash / bank transfer / card: show a "Reference / transaction no." input (required, 6–80 chars, letters/digits/dashes).
4. Cash: show "Pay at the front desk within 24 hours" note; reference optional.
5. Submit `program_id`, `method`, `reference_no`.
6. Show the admin-updated methods immediately (no hard-coded GCash).

**MemberController::enroll()**
- Validate `method` against `Payment::methodConfig()['accepted_methods']` (reject otherwise).
- Validate `reference_no` conditionally (required for non-cash).
- Insert payment with the chosen method and reference.
- Create enrollment `pending` as today. Same transaction.
- Redirect to payments with a flash: "Payment submitted — waiting for admin confirmation."

**Admin confirmation** (`AdminController::confirmPayment`): unchanged lifecycle, but display and preserve the member's chosen method/reference. Cash confirmation stays manual at the desk.

### 5.3 Partner payouts
`payout_requests` already has `method`, `method_details`, `reference_no`. Wire them:
- Partner payout form: method select (GCash / bank transfer / cash), account/phone details, amount. Today it sends only `note`; replace with `method`, `method_details`, optional `note`.
- `PartnerController::requestPayout` validates the requested amount against `balanceBreakdown()` (already enforced), then stores `method` + `method_details`.
- Admin payout processing shows the method and records `reference_no` on approval.

## 6. Partner: Coach-Only Conversion

### 6.1 Schema
- `partner_profiles.partner_type`: drop `supplier` → `enum('coach')` (or keep column but only `coach`).
- `partners.type` (legacy): drop `supplier`.
- `partner_sales.type`: drop `product` → `enum('client_payment','session')`.
- Migration converts existing `supplier` rows to `coach`, `product` sales to `client_payment`.

### 6.2 Code
- `PartnerController::saveProfile()`: remove supplier validation/branch.
- `logSale()`: remove product type; accept `client_payment` and `session`.
- Views: `partner/partials/sale_form.php`, `partner_profile_form.php`, `admin/partials/partners_tab.php`, any "Supplier" label → "Coach".
- Sidebar: partner sections read Clients, Sessions, Earnings, Wallet/Payouts, Profile — no Products.

### 6.3 Partner scope
Partners can: view assigned clients, log coaching sessions/client payments, view earnings, request payouts, manage profile. Partners cannot: sell products, manage gym-wide settings, confirm member enrollments.

## 7. Group Classes and Bookings

### 7.1 Schema (minimal)

```sql
CREATE TABLE `classes` (
  id char(36) PK DEFAULT UUID(),
  name varchar(190) NOT NULL,
  description text,
  coach_user_id char(36) NULL,
  day_of_week tinyint NOT NULL,           -- 0=Sun..6=Sat
  start_time time NOT NULL,
  end_time time NOT NULL,
  capacity smallint NOT NULL DEFAULT 12,
  tier_min enum('digital','standard','premium') NOT NULL DEFAULT 'standard',
  active tinyint(1) NOT NULL DEFAULT 1,
  created_date datetime DEFAULT current_timestamp(),
  updated_date datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
);

CREATE TABLE `class_bookings` (
  id char(36) PK DEFAULT UUID(),
  class_id char(36) NOT NULL,
  user_id char(36) NOT NULL,
  booking_date date NOT NULL,
  status enum('booked','cancelled','attended','no_show') DEFAULT 'booked',
  priority tinyint(1) NOT NULL DEFAULT 0,       -- Premium priority
  created_date datetime DEFAULT current_timestamp(),
  updated_date datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY uniq_booking (class_id, user_id, booking_date)
);
```

`tier_min` gates visibility; `priority` is set when the member's tier is Premium (sorts first and reserves a small pool, see 7.3).

### 7.2 Routes
```
GET  /classes                  MemberController@classes
POST /classes/book             MemberController@bookClass
POST /classes/cancel           MemberController@cancelClass
GET  /partner-dashboard/classes PartnerController@classes   (read-only roster)
GET  /admin-dashboard?tab=classes AdminController@classesTab (CRUD)
POST /admin-dashboard/classes/save  AdminController@saveClass
POST /admin-dashboard/classes/delete AdminController@deleteClass
```
All member class routes require `capability:group_classes` (checked in controller after `auth`).

### 7.3 Booking rules
- Class must be `active`, `tier_min <= member tier`, and today must match `day_of_week` (same-day booking allowed up to start time).
- Capacity = `classes.capacity`. Premium priority: Premium members may book up to `capacity`, Standard up to `capacity - floor(capacity * 0.2)` until 24h before start; after that the reserve opens. Simplest correct rule: count `priority=1` first, allow Standard only when `booked < capacity` and no waitlist concept in v1.
- One booking per user per class per date (unique key).
- Cancel allowed until start time; status → `cancelled`.
- Member sees upcoming bookings and a "Book" / "Cancel" control with seat count.

### 7.4 Admin
- Classes tab: list, create/edit (name, coach, day/time, capacity, min tier), deactivate.
- No recurrence expansion in v1; `day_of_week` covers the weekly schedule. `shortcut: weekly recurring only, upgrade when ad-hoc sessions are needed.`

## 8. AI Features (rule-based, no external API)

### 8.1 Workout recommender (all tiers)
Deterministic rules from:
- Member goal (profile/notes if present, else "general fitness").
- Attendance frequency (last 30 days from `attendance`).
- Tier (`digital` → bodyweight/home-capable; `standard`/`premium` → full gym equipment).
- Sessions left / tenure.

Output: 3–5 exercises with sets/reps or duration, a weekly split, and a one-line "why". Pure PHP; no network calls. Render as cards via `MemberController@recommender` (member home section + `/recommender` route).

### 8.2 AI coach (Premium only)
Same rule engine plus:
- Encouragement/plan summary based on attendance streak.
- Rule-based FAQ: answers about schedule, classes, payments, coaching from a small keyword map.
- Explicit "not medical advice" note.
- Gated by `tier_can('premium','ai_coach')`; non-Premium sees a locked card with upgrade hint.

No chat persistence needed in v1. `shortcut: stateless rule replies, upgrade to stored conversation when history is requested.`

## 9. API Contract

Small JSON API for cross-role sync. All endpoints require `auth`; role/ownership enforced in the controller. Responses use `{ ok, data, error }`.

### Member
| Method | Path | Returns |
|---|---|---|
| GET | `/api/member/capabilities` | `{ tier, capabilities: {id: bool}, labels }` |
| GET | `/api/member/classes` | upcoming classes + seats left + booked flag |
| POST | `/api/member/classes/book` | booking or error (`full`, `tier`, `duplicate`) |
| POST | `/api/member/classes/cancel` | updated booking |
| GET | `/api/member/recommender` | workout plan JSON |
| POST | `/api/member/ai-coach` | rule reply (Premium only) |
| GET | `/api/member/payment-methods` | accepted methods + labels/icons |

### Partner
| Method | Path | Returns |
|---|---|---|
| GET | `/api/partner/clients` | assigned clients + membership/tier/status |
| GET | `/api/partner/classes` | roster for coach's classes |
| POST | `/api/partner/payout` | payout request result |

### Admin
Existing: `/api/admin/occupancy`, `/api/admin/kpis`.
Add:
| Method | Path | Returns |
|---|---|---|
| GET | `/api/admin/capabilities` | tier capability matrix |
| POST | `/api/admin/classes/save` | create/update result |
| POST | `/api/admin/payments/confirm` | confirm result (reuse `confirmPayment` logic) |

Server-rendered pages remain the primary UI; the API feeds dynamic bits and future clients. No API replaces existing forms in v1.

## 10. UI States

### 10.1 Member portal
- Sidebar: add **Classes** (locked badge if tier lacks it) and **AI Coach** (locked badge for non-Premium). Keep QR, Attendance, Payments, Profile.
- Home: show tier name, capability chips, "Sessions Left" only for tiers where coaching/sessions apply (Premium); otherwise hide.
- Locked capability card: title, one-line reason, "Available on Standard/Premium" + link to programs. Uses existing dark tokens; no new design language.
- Classes page: schedule grouped by day, seat count, Book/Cancel, disabled + explanation when full/tier-locked.
- Recommender/Coach: card sections, reduced-motion safe, keyboard accessible.

### 10.2 Partner portal
- Sidebar: Dashboard, Clients, Classes, Sessions/Earnings, Payouts, Profile. Remove Products/Sales-supplier entry.
- Sale form: type = client payment / session only. No product fields.
- Payout form: method + account details; shows status/history.

### 10.3 Admin portal
- Programs: show capability badges per tier (read from `capability_meta()`).
- Payments: render member-chosen method and reference; confirm/refund unchanged.
- Settings: accepted payment methods remain the source; save reflects to member.
- Partners tab: "Coach" only.
- Classes tab: CRUD table + roster link.

### 10.4 Accessibility
- Locked states are conveyed by text, not color alone.
- Badges use `aria-label` where meaningful; decorative icons `aria-hidden`.
- Class booking controls are real buttons with disabled semantics.
- Focus visible on all new controls; reduced motion respected.

## 11. Migrations

Single migration file `database/migrations/2026-11-08_tier_connectivity.sql` (and mirror in `schema.sql`):

1. `partner_profiles.partner_type` → `enum('coach')`; `UPDATE ... SET partner_type='coach' WHERE partner_type='supplier'`.
2. `partners.type` → `enum('coach')`; convert rows.
3. `partner_sales.type` → `enum('client_payment','session')`; convert `product` → `client_payment`.
4. Create `classes`, `class_bookings`.
5. Seed 3–4 sample classes (Yoga, HIIT, Strength, Boxing) with `tier_min='standard'`.
6. No destructive column drops in v1 beyond enum narrowing; keep data.

Rollback notes: enums can be widened back; dropping classes tables is safe if empty.

## 12. Security

- Validate every POST: CSRF (`csrf_verify()`), method allow-list, reference length/charset, class ownership, booking capacity under a transaction/row lock.
- Never trust client tier: capability checks read active enrollment, not form input.
- Booking race: `SELECT ... FOR UPDATE` on class row or count inside the transaction; rely on unique key for duplicates.
- API: auth middleware + ownership; 403 for wrong role; no tier escalation via parameters.
- Payment reference is user-supplied data — escape on output, never log secrets.
- AI: no user input reaches shell/network; outputs are static templates.

## 13. Tests / Verification

- PHP lint every changed file.
- Unit-ish checks (CLI or inline asserts):
  - `tier_capabilities()` matrix for all tiers.
  - Payment method validation rejects unaccepted method.
  - Booking capacity/duplicate/tier rules.
  - Recommender returns non-empty deterministic plan.
- Playwright at 1440, 1024, 768, 390:
  - Member: enroll with each accepted method; locked/unlocked capability states; book/cancel class; recommender; Premium AI coach.
  - Partner: coach-only labels, log session, payout with method.
  - Admin: payment method display, class CRUD, capability matrix.
- End-to-end: member enrolls (cash) → admin confirms → member gains capabilities → books Standard class.
- Check console errors and overflow at all widths.

## 14. Rollout Order

1. Capability helper + `bootstrap.php` registration + tests.
2. Supplier removal + migration + UI labels.
3. Payment methods + reference flow (member/admin) and partner payout method.
4. Classes schema + member booking + admin CRUD.
5. Recommender + Premium AI coach.
6. API endpoints.
7. UI locked states + sidebar entries + responsive polish.
8. Full verification.

Each step must leave the app runnable; no half-migrated state.

## 15. Risks

- Enum narrowing on live data: migrate values before altering; back up first.
- Booking races: transaction + unique key required, not optional.
- "AI" expectations: keep it honest (rules, not an LLM); label it "recommendations".
- Tier copy vs capability drift: `programs.features` stays display-only; capabilities are the enforcement source.
- No git repo: changes are uncommitted; take a DB backup before the migration.

## 16. Open Questions

None blocking. Assumptions:
- Standard initial ₱1,000 = ₱500 membership + ₱500 first month (billing display only; schema keeps a single price/duration).
- Cash enrollment stays pending until admin confirms at the desk.
- Group classes are weekly recurring; one booking per class per date.
