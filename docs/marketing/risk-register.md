# Risk register — public website and marketing platform

Status: Phase 0 baseline (2026-11-16). Reviewed at every phase exit.
Scale: likelihood and impact are Low / Medium / High.

| ID | Risk | Likelihood | Impact | Mitigation | Owner | Phase |
| --- | --- | --- | --- | --- | --- | --- |
| R1 | Brand mismatch between public copy, routes and internal identifiers | Low | Medium | **Resolved 2026-11-16:** BRATHAMATU applied across stock-taking, POS, public content and slugs; the seeder migrates legacy `panda-pos` pages and menu labels | Owner/engineering | 0–1 |
| R2 | SSR approach rejected or changed after build starts | Medium | High | ADR 0002 approval is a Phase 1 gate; keep CMS read model rendering-agnostic | Engineering + owner | 1 |
| R3 | Google Business Profile API allowlist delays Phase 9 | High | Medium | Apply immediately; feature-flag unavailable capabilities; show honest empty states | Owner/ops | 9 |
| R4 | Meta app review delays publishing/spend | High | Medium | Start review early; build behind flags; in-app flows usable without external publishing | Owner/ops | 10–11 |
| R5 | Public content duplicates existing tenant data or leaks it | Low | High | Marketing tables are platform-owned; public endpoints select only published CMS records; contract tests assert no tenant fields | Engineering | 1–8 |
| R6 | Form spam, scraping or abuse | High | Medium | Throttle per IP + hashed contact, honeypot, Turnstile adapter, WAF, idempotency, no autocomplete of lead existence | Engineering | 5 |
| R7 | Consent/privacy non-compliance (Kenya DPA, cookies, marketing messages) | Medium | High | Consent ledger, unsubscribe, retention jobs, privacy text review by counsel before launch; tracking blocked until consent | Owner/counsel | 5, 12–15 |
| R8 | Malicious media upload or stored XSS | Medium | High | Server-side MIME sniff, re-encode images, strip metadata, size limits, private S3 + signed URLs, CSP, no raw HTML from unprivileged editors | Engineering | 4 |
| R9 | Lead PII export leakage | Medium | High | Dedicated `leads.export`, audit rows, rate limit, masked lists, no public lead lookup | Engineering | 5 |
| R10 | Unauthorised ad spend or campaign activation | Medium | High | Separate `campaigns.approve` and `campaigns.spend`; maker-checker; approval required for activation and budget changes; provider caps; audit | Owner + engineering | 11 |
| R11 | SEO thin/duplicate pages or keyword spam | Medium | Medium | Curated page inventory only; no bulk location/industry generation; content review; diagnostics for duplicates and thin pages | Marketing | 2–8 |
| R12 | Public traffic degrades POS/API latency | Medium | High | Separate cache/CDN, dedicated public rate limiter, queue isolation, load test before launch | Engineering | 14–15 |
| R13 | OAuth token or provider secret compromise | Low | High | Encrypted columns, least scopes, secret manager, rotation, revoke on disconnect, redacted logs, audit | Engineering | 9–10 |
| R14 | Automation sends without consent or loops | Medium | High | Consent checks, approved templates, idempotency keys, retry limits, kill switch, execution logs | Engineering | 13 |
| R15 | Permission model too coarse; editors gain publish/spend rights | Medium | High | Granular permissions, seed roles reviewed, denial tests for every role, separation of duties | Engineering | 4, 11 |
| R16 | CMS publish breaks public site or SEO | Medium | High | Versioned snapshots, preview, validation, atomic publish, cache purge, rollback/restore, post-publish smoke test | Engineering | 4, 6 |
| R17 | Structured data mismatches visible content | Medium | Medium | Templates constrained to approved settings, validation blocks publish, no invented ratings/prices | Engineering | 7 |
| R18 | OpenAPI contract drifts from routes | Medium | Medium | Contract test extended in the same change; CI failure is a release blocker | Engineering | All |
| R19 | Performance/accessibility regressions | Medium | Medium | CWV budgets, Lighthouse gates, responsive/a11y browser tests each phase | Engineering | 1–15 |
| R20 | No hosted staging; browser tests only local | High | Medium | Provision staging before Phase 9; Playwright in CI; document local-only limits until then | Owner/ops | 9–15 |
| R21 | Provider API version changes break syncs | Medium | Medium | Pin versions, schedule upgrades, integration health alerts | Engineering | 9–10 |
| R22 | Scope creep into operational platform | Medium | High | Explicit non-goals; no changes to tenant/POS/finance/workforce without separate approval; code-review checklist | Engineering | All |
| R23 | Editorial workflow bypass or self-approval | Medium | Medium | State machine enforced server-side; separation-of-duties setting; audit of every transition | Engineering | 4 |
| R24 | Backup/restore or retention job failure | Low | High | Phase 14 recovery test; monitoring and alerts; retention dry-run | Engineering | 14 |
| R25 | Duplicate/conflicting marketing permissions with existing `content.manage` | Medium | Low | Introduce granular permissions, migrate the unused umbrella grant, document mapping in `RbacSeeder` | Engineering | 4 |

## Top risks requiring owner action before Phase 1

1. **R1 brand and naming** — blocking for content, logo and legal pages.
2. **R2 rendering decision** — ADR 0002 must be accepted or replaced.
3. **R19/R20 staging and CI** — plan hosting and staging early enough for Phases 9–10.
4. **R3/R4 Google and Meta access** — start external applications now; they are the longest lead items.

## Review cadence

- Every phase exit: update likelihood/impact, close resolved risks and add new ones.
- Any High/High risk requires an explicit owner decision before the next phase starts.
