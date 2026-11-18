# Phase 8 — Workforce onboarding and assignment

Status: **passed** (backend tests, frontend tests/build, browser acceptance)
Date: 2026-11-11

## Objective

Deliver the stock-taker workforce: onboarding and verification, skills, service areas and
availability, training and conservative progression, eligibility-checked assignment
recommendations, the offer/accept/decline/reassign/withdraw lifecycle, and compensation
visibility controls. Exit criterion: **a verified worker can receive and accept only eligible
assignments and sees only their own compensation.**

## Delivered — backend

- **Schema** (`2026_11_11_090000_create_workforce_tables.php`):
  - `stock_taker_profiles` (`applicant|trainee|verified|professional|expert|senior_supervisor`,
    status, verification status, phone/identity/training timestamps, accuracy and reliability
    counters).
  - `stock_taker_verifications`, `stock_taker_training_completions`, `stock_taker_skills`,
    `stock_taker_service_areas` and `stock_taker_availability` (weekday windows in
    `Africa/Nairobi`).
  - `job_assignments` (offer expiry, compensation, currency, declined reason, replacement link,
    metadata) and `job_assignment_events` (immutable transition trail with actor type).
  - `stock_taker_earnings` (`accrued|payable|paid|reversed`) linked to the assignment and job.
- **Profiles and progression** (`WorkforceProfileService`, `WorkforceProgressionService`):
  - Workers create or update their own profile; sensitive fields (level, verification status,
    training completion) are never accepted from the worker payload.
  - Platform staff record identity, phone, background, reference and training checks with
    verified-by and timestamps; the profile verification status is derived from the latest
    identity and phone checks.
  - Training records drive `training_completed_at`; progression only moves up on verified
    evidence: trainee after core induction, verified after identity + phone + core training,
    professional at 5 completed jobs and 95% accuracy, expert at 15 jobs and 97%.
  - Fixed the training/progression circular check so recording `core_induction` updates
    `training_completed_at` before evaluating the level, and an applicant with verified
    identity/phone then progresses to **verified** as intended.
- **Eligibility and assignment** (`AssignmentService`):
  - Hard gates: active profile, verified verification status, eligible level, completed training,
    fewer than three active assignments, required-skill match (normalised), service-area match,
    availability at the preferred start in `Africa/Nairobi`, and no same-day accepted conflict.
  - Ranked recommendations with score, reasons, blockers and proposed compensation; candidates
    are always evaluated server-side.
  - Offer enforces team capacity and duplicate active offers; an ineligible worker is rejected
    unless an override reason (min 5 chars) is recorded. Offers advance the job to
    `awaiting_worker_acceptance`; acceptance accrues a worker earning and moves the job to
    `scheduled` once the required team is complete.
  - Decline returns the job to `awaiting_assignment` when no active assignment remains.
    Withdraw/reassign record an event and reverse the worker earning; reassignment creates a
    fresh offer for the replacement.
  - Worker payloads contain only the worker's compensation and earning; they never include quote
    totals, tenant charges or platform margin.
- **Authorization and permissions**:
  - `workforce` middleware restricts `/workforce/*` to workforce-scoped roles, independent of
    tenant or platform membership.
  - Worker permissions: `workforce.profile.view`, `assignment.view`, `assignment.accept`,
    `earnings.view`.
  - Platform permissions: `workforce.view`, `workforce.manage`, `workforce.verify`,
    `stocktake.job.assign`, `assignment.view`.
  - Job detail is now visible to assignment managers (`stocktake.job.assign` added to the job
    index/show permission pipe) so the assignment panel can be reached without full review
    rights.
  - Worker assignment lookups are ownership-scoped (another worker's assignment returns 404);
    tenant users cannot call workforce or assignment endpoints.
  - Dashboard role detection now filters on `roles.scope = platform`, so workforce users no
    longer receive the platform workspace.
- **API surface** (`routes/api.php`):
  - `GET|POST|PATCH /workforce/profile`; `PUT /workforce/profile/skills`,
    `/service-areas`, `/availability`
  - `GET /workforce/assignments`, `GET /workforce/assignments/{assignment}`,
    `POST /workforce/assignments/{assignment}/accept|decline`
  - `GET /workforce/earnings`
  - `GET /platform/stocktake-jobs/{job}/assignment-recommendations`
  - `GET|POST /platform/stocktake-jobs/{job}/assignments`
  - `POST /platform/assignments/{assignment}/reassign|withdraw`
  - `GET /platform/stock-takers`, `GET|PATCH /platform/stock-takers/{profile}`,
    `POST /{profile}/verification`, `POST /{profile}/training`,
    `PUT /{profile}/skills|service-areas|availability`

## Delivered — frontend

- **Worker onboarding** (`/workforce/profile`): personal details, languages, transport,
  experience and terms acceptance, plus editors for skills, service areas and weekly
  availability. A progression panel shows level, verification checks, trainings, completed jobs
  and accuracy.
- **Worker assignments** (`/workforce/assignments`): profile gate, open offers with job facts,
  an emphasised **Your compensation** block, inline decline reason, accepted/scheduled cards with
  the earning reference, and assignment history.
- **Worker earnings** (`/workforce/earnings`): accrued/payable/paid totals and earning entries
  with job references and status.
- **Platform workforce console** (`/platform/workforce`): directory counts (profiles, awaiting
  verification, verified), search and filters, and review links.
- **Stock-taker detail** (`/platform/workforce/{profileId}`): manage level/status with a reason,
  record identity/phone/background/reference/training checks (with a "never enter full identity
  document numbers" instruction), record training, and edit skills, service areas and
  availability. Verification and training history are shown with restricted references.
- **Job assignment panel** (`AssignmentPanel` on the platform job page): team slots, proposed
  compensation, active assignments with a two-step withdraw, ranked recommendations with
  eligible/blocked badges, blockers and an inline offer form that requires an override reason for
  blocked workers.
- **Navigation and guard**: workforce shell links profile, assignments and earnings; the
  `RequireWorkforce` guard checks the workforce role scope; platform nav exposes Workforce behind
  `workforce.view`.

## Verification

- Backend: `php artisan test` — **59 passed (683 assertions)**.
  - `WorkforceTest` (4 tests, 49 assertions): onboarding ignores restricted fields and the
    verification/training pipeline promotes to verified; a verified worker receives an eligible
    offer, accepts it, accrues an earning and sees no tenant charge/margin keys; an ineligible
    worker is rejected without an override and offered with a recorded reason; workers cannot read
    another worker's assignment and tenants cannot use workforce or assignment APIs; withdrawal
    returns the job to awaiting assignment.
- Frontend: `npm run test` — **17 passed** (5 files); `npm run build` passes (TypeScript + Vite,
  chunk-size warning only).
- Browser acceptance (`http://localhost:5173`, in-app browser):
  1. `workforce@brathamatu.test` opened the demo job **STJ-20261111-GSILMC**
     (`awaiting_worker_acceptance`) and saw the assignment panel with **1/3 slots**, the active
     accepted assignment, and three ranked candidates (worker **93 Eligible**, senior **92
     Blocked – missing barcode counting**, applicant **77 Blocked**).
  2. Withdrew the accepted assignment from the panel (two-step confirm): the job returned to
     **Awaiting assignment**, the slot count reset to **0/3**, and the worker row became
     available with an **Offer** action.
  3. Sent an offer to `worker@brathamatu.test` (Counter, Ksh 700); the job moved to **Awaiting
     worker acceptance** and the panel showed the new **Offered** assignment.
  4. Signed in as `worker@brathamatu.test`: the workforce dashboard rendered (no platform
     workspace), and **Assignments** showed the open offer with **YOUR COMPENSATION Ksh 700.00**
     and the explicit note that tenant charges and platform margins are never shown.
  5. Accepted the offer: the assignment moved to **Accepted / scheduled**, the earning
     **EARN-20261111-THPE2V** appeared as **Accrued**, and **Earnings** showed accrued
     **Ksh 700.00** plus the two earlier reversed entries.
  6. `workforce@brathamatu.test` opened the workforce console (3 profiles, awaiting verification
     and verified counts) and the applicant detail; recorded identity and phone verification and
     `core_induction` training. Progression moved the applicant to **Verified** with
     `training_completed_at` set, confirming the fixed progression rule.
- Evidence: `docs/evidence/phase-08/01-worker-dashboard.png`,
  `02-worker-assignment-accepted.png`, `03-worker-earnings.png`,
  `04-platform-workforce-console.png`, `05-platform-stocktaker-detail.png`,
  `06-platform-job-assignment-panel.png`.

## Notes / deferred

- Recommendations are limited to active, verified, trained profiles in eligible levels; missing
  skills, service-area or availability blockers can be overridden with an audit reason, while
  unverified or untrained workers must complete onboarding first.
- Worker payouts, payable/paid transitions and maker-checker payout approval belong to Phase 14.
- Identity verification records a reference only; storage of identity documents, retention and
  privacy controls belong to the Phase 20 review.
- Demo accounts and credentials remain local/staging only and must never be enabled in
  production.
