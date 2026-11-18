# Phase 15 — recurring and on-demand schedules, browser walkthrough

Date: 2026-11-15
Environment: Vite `http://127.0.0.1:5173`, Laravel `http://127.0.0.1:8000`, MySQL `:3307`
Account: `owner@demosupermarket.test` (tenant owner)

## Steps

1. Opened **Schedules** (`/business/schedules`) and confirmed both demo schedules render.
2. Opened the monthly schedule (`/business/schedules/01M7FKAAZY6JZD3QX185G2023C`).
3. Checked occurrence generation, skip reason display and pause/resume actions.

## Captured state — list

- `On-demand emergency count` — mode `reminder`, frequency `on demand`, next occurrence `—`, `Active`.
- `Monthly supermarket full count` — mode `proposed`, frequency `monthly`, next occurrence `01/03/2027`, `Active`.
- No spurious empty-state row underneath the populated table.

## Captured state — detail

- Header: Monthly supermarket full count, description, mode `proposed`, frequency `monthly`,
  status `Active`, next occurrence `01/03/2027, 03:00:00`.
- Actions: **Pause schedule**, Generate (3) **Generate occurrences**.
- Occurrences (**3**):
  - `01/01/2027, 03:00:00` — `skipped`, reason "Skipped in the demo walkthrough because the branch was
    closed for stock delivery."
  - `01/02/2027, 03:00:00` — `skipped`, same reason (the demo rerun skips both past occurrences).
  - `01/03/2027, 03:00:00` — `pending`; next occurrence remains `01/03/2027`.

## Notes

- The schedules list previously rendered "No schedules yet" despite API data because the Zod schemas
  required fields the API omits; schemas were relaxed to nullable/optional and the page now renders.
- The shared `Table` component also rendered its empty row under populated tables; fixed centrally.

## Result

Pass. Recurring schedules, occurrence tracking (including skip with reason), pause/resume and on-demand
trigger surfaces are all present and operate on real data.
