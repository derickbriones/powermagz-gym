# SDD ledger — plan: docs/superpowers/plans/2026-11-08-tier-feature-connectivity.md

Note: no git repository in this workspace; commit steps are skipped and verification is recorded manually.
Pre-flight: T1 produces capability helpers consumed by T2–T8; signatures fixed in T1.


## Tasks 1-5
- T1 capabilities helper + test: PASS.
- T2 payment method/reference validation + modal + payments display + test: PASS.
- T3 partner payout method/details/note: lint PASS.
- T4 coach-only partners: model/controller/views/admin tab updated; migration 2026-11-08_tier_connectivity.sql applied (backup storage/backups/powermagz_gym_pre_tier_connectivity.sql); enums verified; lint PASS.
- T5 group classes: tables + 4 seeds migrated; GymClass/ClassBooking; member /classes book/cancel; admin classes tab; sidebar entries; tests/classes_test.php PASS.

## Tasks 6-9
- T6 deterministic AI: `src/Helpers/ai.php` (rule-based workout recommendation + AI coach replies); member `/recommender` and `/ai-coach`; `tests/ai_test.php` PASS.
- T7 JSON API: member (`capabilities`, `payment-methods`, `classes`, `classes/book`, `classes/cancel`, `recommender`, `ai-coach`), partner (`clients`, `wallet`, `payout`), admin (`capabilities`, `classes`, `kpis`, occupancy) routes registered; PHP lint PASS.
- T8 member capability UI: home capability chips (unlocked/locked) + 7 quick actions with lock badges; sidebar lock badges; `programs.php` lists each tier's real capabilities from `tier_capabilities()`; payment-method chip is dynamic; GCash-only copy removed. `MemberController::programs` passes `paymentMethods`.
- T9 verification:
  - Tests: ai, capabilities, classes, payment_methods all PASS. PHP lint: 120 files, 0 failures.
  - Fixed pre-existing `/api/occupancy` fatal by adding `Attendance::capacity()` (reads `system_settings.gym_capacity`, falls back to `GYM_CAPACITY`/50); endpoint now returns valid JSON.
  - Browser flows (Playwright): member login; programs capability cards; enrollment modal shows 4 methods with correct note/reference rules; Digital cash enrollment created pending; admin approved enrollment; admin confirmed payment (cash/paid, `confirmed_by` set); Digital home shows correct unlocked/locked caps; Digital `/recommender` works; Digital `/classes` locked; Standard classes book (seat 16->15) + cancel; Premium classes 14-day horizon; Premium AI coach nutrition answer with disclaimer; admin class create + delete (`QA Test Class`); partner payout modal Method/Account/Note; partner sales types only Coaching Session / Client Payment (no supplier/product).
  - Responsive/overflow: no horizontal scroll on member `/home`, `/programs`, `/classes`, `/recommender`, `/ai-coach`, `/payments` at 390/768/1024/1440; admin `?tab=classes|partners|payouts` and partner `/partner-dashboard/wallet` clean at 390.
  - Console sweep: only expected Tailwind CDN production warning; no app errors after occupancy fix.
  - API smoke tests (same-origin, authenticated): member GET capabilities/payment-methods/classes/recommender 200; member POST classes/book 201 -> cancel 200; member POST ai-coach 200; partner GET clients/wallet 200; partner POST payout CSRF accepted and returns 422 for over-balance; admin GET capabilities/classes/kpis/occupancy 200.
  - Cleanup: test enrollment, test payment and both test class bookings removed; member restored to original expired Premium enrollment `771495c1-fa7d-46f1-aabf-8d92f8607132`; original payment `c063afda-...` intact.


## Landing: services + tier parity (2026-11-09)
- Added `src/Views/site/services.php` (client's 8 services: Cardio, Zumba, Group Fitness, Boxing, Resistance Training, Personal Training, Diet Nutrition, Weight Control), included after features; nav/footer anchors added.
- Styled in `public/css/site.css`: two-column typographic block, sticky title at >=1024, 2-col service list, teal square marks, Premium tags; collapses to one column at 1023 and a single list at 639.
- Landing pricing now matches member Programs & Tiers: Digital 500, Standard 1000 initial (500 + 500), Premium 2000; feature lists match the member page.
- Payment copy updated from GCash-only to all methods (GCash, cash, bank transfer, card) in pricing/features/hero/final CTA/footer/meta.
- Verification: `php -l` clean on all changed files; 4 tests PASS; Playwright 1440/1024/768/390: no horizontal overflow, 8 services render, nav fits at 1024, pricing 3-up/1-up, 0 console errors, all in-page anchors resolve.

## Landing alignment + nav cleanup (2026-11-09)
- Visit section restructured: eyebrow/heading + lead now form a full-width section head (same pattern as the other sections); address card and map sit in a balanced two-column row with equal top/bottom edges (map stretches to the card height instead of floating).
- Kept: map min-height (380-520 desktop), 340 on <=1023, single column on mobile.
- Removed duplicate nav destinations: desktop/mobile "Home" (the logo already goes home) and "Join" (the "Join now" CTA already goes to registration). Nav is now Training, Services, About, Membership, Visit; mobile mirrors it.
- Verification: `php -l` clean; Playwright 1440/1024/390 no horizontal overflow, map and address card share top/bottom edges, 5 nav links, one active link, 20px gap under the fixed header after anchor jumps, 0 console errors.

## Register consent + stat colors (2026-11-09)
- Register: consent text is now a required checkbox placed before the submit button (`agree_terms`); links to Terms and Privacy open in a new tab. Server-side guard added in `AuthController::register` so the POST cannot skip consent.
- Stat rail: all values now use the same ink color; teal is reserved for the small `+` suffix. The opening-hours value no longer renders entirely teal.
- Verification: `php -l` clean; required checkbox + label present; unchecked POST via `form.submit()` (native validation bypassed) redirects back with the consent flash and creates no user; 390 and 1440 no horizontal overflow; 0 console errors.
