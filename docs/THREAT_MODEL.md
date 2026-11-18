# Threat Model v1 — Phase 1 baseline

Scope: foundation + auth. Expanded each phase (STRIDE per domain).

## Assets
- User credentials and sessions/tokens.
- Tenant catalogue and inventory data (from Phase 4).
- Financial records: quotes, payments, ledger, payouts (from Phase 6/7).
- Worker identity documents and payout details (from Phase 8).
- Evidence files (from Phase 10).

## Trust boundaries
1. Browser ↔ API (HTTPS in production; local dev over loopback).
2. API ↔ MySQL / Redis (loopback locally; private network in production).
3. API ↔ payment providers (sandbox adapters until certified credentials).
4. Tenant A ↔ Tenant B (hard isolation boundary — every query/file/job).
5. Worker ↔ tenant commercial data (financial confidentiality boundary).

## Phase 1 threats and controls

| Threat | Vector | Control | Status |
|---|---|---|---|
| Credential stuffing / brute force | `/auth/login`, `/auth/register` | Rate limit 10/min per IP; generic credential error; bcrypt(12) | Implemented |
| Token theft | XSS → localStorage | Short token expiry (1440 min); logout revokes server-side; CSP + storage hardening in Phase 20 | Partial (documented) |
| User enumeration | registration/login responses | Unique-email validation reveals existence (accepted Phase 1 tradeoff; generic messaging review Phase 2) | Open (low) |
| IDOR | sequential IDs | ULID public IDs; policies from Phase 2 | Implemented (IDs) |
| Injection | SQL/XSS | Eloquent parameterisation; Form Requests; JSON-only API; output encoding in React | Implemented |
| CSRF | cookie auth | Not applicable — bearer token model (ADR 0001) | N/A |
| Info leak | stack traces, logs | JSON error envelope, no internals; APP_DEBUG=false in production; log redaction Phase 20 | Partial |
| CORS abuse | browser cross-origin | Allowlist = FRONTEND_URL only | Implemented |
| Privilege escalation | role tampering | No client-trusted roles; RBAC server-side from Phase 2 | Planned |
| Cross-tenant access | API queries | Tenant scoping + policies + isolation tests (Phase 2) | Planned |
| Payment spoofing | fake callbacks | Server-side verification, idempotency, signature checks (Phase 7) | Planned |
| Financial confidentiality | worker sees tenant charge | Separate quote/compensation models + policy tests (Phase 6+) | Planned |

## Standing rules
- Never trust client-supplied tenant ID, price, total, role, payment or workflow status.
- Audit all sensitive reads/writes (Phase 2 onward).
- Sandbox/test-mode adapters only until real credentials + certification.
- Kenya DPA review before production (spec §20.2).