# ERD v1 — Phase 1 baseline

Source of truth: master spec §15. This ERD covers **implemented** tables only;
the full canonical domain inventory is tracked in `docs/BACKLOG.md` and will be
elaborated phase by phase. Do not create duplicate/overlapping tables.

## Implemented (Phase 1)

```
users
├── id                bigint PK (internal)
├── uuid              char(26) UNIQUE  ← public identifier (ULID)
├── name              varchar
├── email             varchar UNIQUE
├── email_verified_at timestamp NULL
├── password          varchar (hashed)
├── remember_token    varchar
└── created_at / updated_at

personal_access_tokens   (Sanctum)
├── id, tokenable_type, tokenable_id → users.id
├── name, token UNIQUE, abilities JSON
├── last_used_at, expires_at, timestamps

cache / cache_locks        (Laravel; Redis is primary store)
jobs / job_batches / failed_jobs  (Laravel; Redis is primary queue)
sessions                   (Laravel session driver)
password_reset_tokens
```

## Planned next (Phase 2 — identity & tenancy)

```
roles ──< role_permissions >── permissions
users ──< user_roles >── roles          (scoped: platform | tenant:{id} | workforce)
users ──< user_permission_overrides
tenants ──< tenant_memberships >── users
tenants ──< tenant_settings
invitations, user_verifications, user_consents,
terms_versions ──< user_terms_acceptances
audit_logs (actor, tenant_id, action, subject, before/after JSON, request_id)
login_attempts, security_events
```

## Standards (spec §15.17)

- InnoDB, utf8mb4, UTC timestamps.
- DECIMAL for money (never float); ISO currency code per monetary value.
- ULID public IDs; internal bigint PKs never exposed where enumeration matters.
- Composite indexes lead with `tenant_id` on tenant-owned tables.
- Immutable history tables for status changes, financial journals, audit.
- Soft deletes only where reversible hiding is valid; never for journals/audit/callbacks.