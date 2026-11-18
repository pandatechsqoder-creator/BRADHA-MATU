# Proposed CMS, marketing and integration data model

Status: proposal for Phase 1 approval (2026-11-16)
Conventions: InnoDB, utf8mb4, UTC; bigint PK; ULID `uuid` for external references; `DECIMAL` money;
platform-owned records have no `tenant_id`; immutable audit for sensitive changes.

This is the starting schema for Phases 4–13. Relationships, indexes and retention are finalised per
phase before the migration is written.

## 1. Global settings and shared primitives

### `marketing_settings`
Key/value configuration for brand assets, contact details, social links, SEO defaults, consent text,
provider selection and business details. Replaces hard-coded values from master-plan §14.

Columns: `id`, `group`, `key` (unique), `value` (JSON), `is_public` (bool), `updated_by`, timestamps.
Indexes: `(group, key)`, `(is_public)`.

### `marketing_audit_logs`
Columns: `id`, `actor_id` FK users null, `action`, `subject_type`, `subject_id`, `before` JSON null,
`after` JSON null, `ip`, `user_agent`, `request_id`, `created_at`.
Indexes: `(subject_type, subject_id)`, `(actor_id, created_at)`, `(action, created_at)`.
No soft deletes; append-only.

## 2. CMS and navigation

### `cms_pages`
Columns: `id`, `uuid`, `slug` unique, `title`, `type` (`page|landing|legal|system`), `template`,
`status` (`draft|review|approved|scheduled|published|archived`), `locale` default `en`,
`is_home` bool, `requires_auth` bool, `published_at`, `scheduled_at`, `noindex` bool,
`current_version_id` null, `created_by`, `reviewed_by`, `approved_by`, `published_by`,
`timestamps`, `deleted_at`.
Indexes: `(status, published_at)`, `(type, status)`, `(slug)` unique.
Rules: only `published` rows with `published_at <= now()` are publicly routable; slug changes write a
`seo_redirects` row.

### `cms_page_versions`
Columns: `id`, `uuid`, `page_id` FK cascade, `version_no`, `status`, `snapshot` JSON (page + sections
+ metadata), `change_summary`, `created_by`, `reviewed_by`, `approved_by`, `published_at`,
`created_at`. Unique `(page_id, version_no)`. Index `(page_id, created_at)`.
Rules: immutable; restore copies a snapshot into a new draft version.

### `cms_page_sections`
Columns: `id`, `page_id` FK cascade, `type` (hero, rich_text, service_grid, pos_features, industries,
process, testimonials, faq, latest_articles, cta, contact, media, custom), `sort_order`, `content`
JSON, `settings` JSON, `is_visible` bool, `timestamps`.
Index `(page_id, sort_order)`.
Rules: type-specific JSON is validated by Form Requests and rendered by one Blade component per type.

### `cms_menus` / `cms_menu_items`
`cms_menus`: `id`, `key` unique (`primary|footer|utility`), `name`, `timestamps`.
`cms_menu_items`: `id`, `menu_id` FK cascade, `parent_id` self-FK null, `label`, `page_id` FK null,
`url` null, `target` (`_self|_blank`), `sort_order`, `is_visible`, `timestamps`.
Constraint: exactly one of `page_id` or `url`; depth limited to 2.

### `cms_banners` / `cms_ctas`
`cms_banners`: `id`, `key`, `title`, `body`, `media_asset_id` null, `link_url`, `starts_at`, `ends_at`,
`is_active`, `timestamps`.
`cms_ctas`: `id`, `key`, `label`, `url`, `form_type` null, `style`, `is_active`, `timestamps`.
Only one active banner per key at a time; activation is audit-logged.

### `cms_faqs` / `cms_testimonials`
`cms_faqs`: `id`, `page_id` null FK, `category`, `question`, `answer`, `sort_order`, `is_visible`.
Index `(page_id, sort_order)`.
`cms_testimonials`: `id`, `page_id` null, `quote`, `author_name`, `author_role`, `business_name`,
`media_asset_id` null, `is_approved`, `approved_by`, `sort_order`.
Rules: only approved testimonials render; no invented ratings or logos.

## 3. Media library

### `media_folders`
`id`, `uuid`, `parent_id` self-FK null, `name`, `slug`, `path`, `created_by`, `timestamps`.
Unique `(parent_id, slug)`.

### `media_assets`
`id`, `uuid`, `folder_id` null FK, `disk` (`public|s3`), `path`, `filename`, `mime`, `extension`,
`size_bytes`, `width` null, `height` null, `checksum` unique, `version` int default 1,
`alt_text`, `caption`, `description`, `focal_point` JSON null, `uploaded_by`, `timestamps`,
`deleted_at`.
Indexes: `(folder_id)`, `(mime)`, `(created_at)`.
Rules: uploads validate MIME by content, re-encode images, strip metadata, cap size/dimensions; private
files use signed URLs.

### `media_asset_versions`
`id`, `media_asset_id` FK cascade, `version`, `path`, `size_bytes`, `checksum`, `created_by`,
`created_at`. Unique `(media_asset_id, version)`.

### `media_usages`
`id`, `media_asset_id` FK cascade, `usable_type`, `usable_id`, `field`, `created_at`.
Index `(usable_type, usable_id)`, `(media_asset_id)`. Used to warn before deletion/replacement.

## 4. Blog and resources

### `blog_posts`
`id`, `uuid`, `slug` unique, `title`, `excerpt`, `body` longtext, `format` (`markdown|html`),
`status` (`draft|review|approved|scheduled|published|archived`), `author_id`, `featured_asset_id` null,
`reading_minutes`, `published_at`, `scheduled_at`, `noindex`, `created_by`, `reviewed_by`,
`approved_by`, `timestamps`, `deleted_at`.
Indexes: `(status, published_at)`, `(author_id)`, `(slug)`.

### `blog_categories` / `blog_tags` / pivots
`blog_categories`: `id`, `uuid`, `slug` unique, `name`, `description`, `parent_id`, `sort_order`.
`blog_tags`: `id`, `uuid`, `slug` unique, `name`.
`blog_post_category`: `blog_post_id`, `blog_category_id`, primary key both.
`blog_post_tag`: `blog_post_id`, `blog_tag_id`, primary key both.
`blog_post_related`: `blog_post_id`, `related_post_id`, `sort_order`, primary key both.
Rules: one primary category per post; tags are noindex archives.

### `resource_downloads`
`id`, `uuid`, `title`, `slug` unique, `media_asset_id`, `description`, `requires_consent` bool,
`download_count`, `is_published`, `timestamps`.

### `editorial_items`
Editorial calendar entries: `id`, `title`, `brief`, `assignee_id`, `due_on`, `status`, `blog_post_id`
null, `timestamps`.

## 5. SEO and structured data

### `seo_metadata`
Polymorphic per page/post/landing: `id`, `seoable_type`, `seoable_id`, `title`, `description`,
`canonical_url` null, `og_title`, `og_description`, `og_asset_id` null, `twitter_card`,
`robots_index` bool, `robots_follow` bool, `schema_type` null, `priority`, `change_frequency`,
`updated_by`, `timestamps`. Unique `(seoable_type, seoable_id)`.
Rules: title/description length limits; duplicate detection feeds diagnostics.

### `seo_redirects`
`id`, `from_path` unique, `to_path` null, `to_url` null, `status_code` (`301|302|410`), `is_active`,
`hit_count`, `last_hit_at`, `created_by`, `timestamps`.
Constraint: exactly one target; loops rejected at write time.

### `seo_sitemap_runs`
`id`, `status` (`queued|running|completed|failed`), `url_count`, `error`, `started_at`,
`finished_at`. Index `(created_at)`.

### `seo_issues`
`id`, `seoable_type` null, `seoable_id` null, `url`, `type` (missing_title, duplicate_title,
missing_description, canonical_conflict, broken_link, redirect_chain, orphan_page, noindex_conflict),
`severity` (`info|warning|error`), `details` JSON, `resolved_at`, `timestamps`.
Indexes: `(type, resolved_at)`, `(url)`.

### `core_web_vitals`
`id`, `url`, `device` (`mobile|desktop`), `lcp_ms`, `inp_ms`, `cls`, `ttfb_ms`, `measured_at`,
`source`. Index `(url, measured_at)`.

### `structured_data_templates`
`id`, `schema_type` (`Organization|WebSite|WebPage|Service|Product|SoftwareApplication|Article|
BlogPosting|BreadcrumbList|FAQPage|LocalBusiness`), `name`, `is_enabled`, `applies_to_type` null,
`applies_to_id` null, `mapping` JSON, `updated_by`, `timestamps`.
Rules: templates can only reference approved business settings; no invented ratings, prices or
addresses.

### `structured_data_validation_runs`
`id`, `structured_data_template_id` null, `seoable_type`, `seoable_id`, `schema_type`, `status`
(`valid|warning|invalid`), `errors` JSON, `warnings` JSON, `validated_by`, `created_at`.

## 6. Leads and CRM

### `leads`
`id`, `uuid`, `reference` unique, `type` (`quote|pos_demo|contact|callback|partnership|other`),
`status` (`new|assigned|contacted|qualified|quote_sent|follow_up|won|lost|archived`),
`business_name`, `contact_name`, `email`, `phone`, `industry`, `town`, `county`,
`branches_count` null, `catalogue_size` null, `service_requested`, `stocktake_frequency`, `message`,
`source`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `landing_page`,
`referrer`, `consent_marketing` bool, `consent_transactional` bool, `consent_ip`, `consent_at`,
`assigned_to` null FK users, `owner_id` null, `last_activity_at`, `converted_at`,
`timestamps`, `deleted_at`.
Indexes: `(status, created_at)`, `(type, created_at)`, `(assigned_to, status)`, `(email)`, `(phone)`,
`(utm_campaign)`.
Rules: creating a lead never creates a stock-taking job; exports require `leads.export` and are audited;
PII retention follows `marketing_settings` and the privacy runbook.

### `lead_activities`
`id`, `lead_id` FK cascade, `type` (`note|call|email|sms|whatsapp|status_change|assignment|consent`),
`body`, `metadata` JSON, `created_by`, `created_at`. Index `(lead_id, created_at)`.

### `lead_assignments`
`id`, `lead_id` FK cascade, `assigned_to`, `assigned_by`, `assigned_at`, `unassigned_at`, `reason`.
Index `(lead_id, assigned_at)`, `(assigned_to, unassigned_at)`.

### `lead_exports`
`id`, `requested_by`, `filters` JSON, `row_count`, `checksum`, `created_at`. Audit trail for exports.

## 7. Campaigns, creative and attribution

### `marketing_campaigns`
`id`, `uuid`, `name`, `objective`, `channel` (`website|email|sms|whatsapp|facebook|instagram|
meta_ads|google_ads|referral`), `status` (`draft|review|approved|active|paused|completed|cancelled`),
`audience` JSON, `budget_amount` DECIMAL, `budget_currency`, `starts_at`, `ends_at`,
`landing_page_id` null FK cms_pages, `utm` JSON, `owner_id`, `approver_id` null, `approved_at`,
`activated_at`, `results` JSON, `created_by`, `timestamps`.
Indexes: `(status, starts_at)`, `(channel)`, `(owner_id)`.
Rules: activation requires an approved `campaign_approvals` row and `campaigns.spend`; budgets are
enforced by validation and provider-side caps.

### `campaign_assets`
`id`, `campaign_id` FK cascade, `media_asset_id` null, `type` (`image|video|copy|email|sms|whatsapp`),
`content` JSON, `status` (`draft|approved|rejected`), `created_by`, `timestamps`.

### `campaign_approvals`
`id`, `campaign_id` FK cascade, `requested_by`, `decided_by` null, `decision` (`pending|approved|
rejected`), `notes`, `decided_at`, `created_at`. Index `(campaign_id, created_at)`.

### `campaign_spend_records`
`id`, `campaign_id` FK, `provider` (`meta|google`), `external_id`, `amount` DECIMAL, `currency`,
`spent_on`, `metadata` JSON, `synced_at`. Unique `(provider, external_id)`.

### `campaign_attributions`
`id`, `lead_id` null FK, `campaign_id` null FK, `channel`, `utm` JSON, `first_touch` bool,
`attributed_at`, `metadata` JSON. Index `(campaign_id, attributed_at)`, `(lead_id)`.

## 8. Google and Meta integrations

### `oauth_connections`
`id`, `uuid`, `provider` (`google|meta`), `account_label`, `credentials` encrypted:array
(access/refresh tokens, expiry), `scopes` JSON, `status` (`connected|expired|revoked|error`),
`connected_by`, `last_synced_at`, `last_error`, `timestamps`.
Indexes: `(provider, status)`. Tokens never leave the backend and are never logged.

### Google
- `google_business_accounts`: `id`, `connection_id`, `external_id`, `name`, `metadata` JSON.
- `google_business_locations`: `id`, `connection_id`, `account_id`, `external_id`, `name`,
  `address` JSON, `phone`, `status`, `insights` JSON, `synced_at`.
- `google_search_properties`: `id`, `connection_id`, `site_url`, `permission_level`, `synced_at`.
- `google_search_metrics`: `id`, `property_id`, `date`, `query` null, `page` null, `clicks`,
  `impressions`, `ctr`, `position`. Unique `(property_id, date, query, page)`.
- `ga4_properties`: `id`, `connection_id`, `property_id`, `display_name`, `synced_at`.
- `gtm_containers`: `id`, `connection_id`, `container_id`, `public_id`, `name`, `workspace_id`,
  `last_published_at`.

### Meta
- `meta_business_accounts`: `id`, `connection_id`, `external_id`, `name`.
- `meta_pages`: `id`, `connection_id`, `business_id` null, `external_id` unique, `name`, `category`,
  `page_token` encrypted, `status`, `synced_at`.
- `meta_instagram_accounts`: `id`, `connection_id`, `meta_page_id`, `external_id`, `username`,
  `account_type`, `status`.
- `meta_ad_accounts`: `id`, `connection_id`, `external_id`, `name`, `currency`, `spend_cap` null,
  `status`, `synced_at`.
- `meta_campaign_records`: `id`, `marketing_campaign_id` null, `ad_account_id`, `external_id`,
  `status`, `budget_amount`, `spend_amount`, `impressions`, `clicks`, `results` JSON, `synced_at`.
  Unique `(ad_account_id, external_id)`.
- `social_posts`: `id`, `marketing_campaign_id` null, `meta_page_id`, `instagram_account_id` null,
  `media_asset_id` null, `caption`, `scheduled_at`, `published_at`, `external_id`, `status`
  (`draft|approved|scheduled|published|failed`), `insights` JSON, `created_by`.
- `social_post_approvals`: `id`, `social_post_id`, `requested_by`, `decided_by`, `decision`,
  `decided_at`, `notes`.

### Shared integration operations
- `integration_sync_runs` (marketing-specific): `id`, `connection_id`, `provider`, `type`
  (`metrics|reviews|publishing|spend|assets`), `status`, `started_at`, `finished_at`, `counts` JSON,
  `error`. Index `(connection_id, type, started_at)`.
- `integration_logs`: `id`, `connection_id`, `level`, `event`, `message`, `context` JSON,
  `created_at`. Redact tokens/PII.
- `webhook_events`: `id`, `provider`, `external_id` unique per provider, `signature_valid` bool,
  `payload` JSON, `processed_at`, `error`. Idempotent processing.

## 9. Analytics, consent and automation

### `analytics_events`
`id`, `event_name`, `occurred_at`, `session_id`, `lead_id` null, `user_id` null, `url`, `referrer`,
`utm` JSON, `device`, `consent_state` JSON, `payload` JSON. Index `(event_name, occurred_at)`,
`(session_id)`, `(lead_id)`.
Rules: ingest only after consent; no raw IP stored beyond a truncated/hashed value; retention window
configured.

### `conversion_events`
`id`, `event_name` (`quote_request|pos_demo_request|contact_submission|phone_click|
whatsapp_click|download|registration`), `lead_id` null, `campaign_id` null, `value` null, `currency`,
`occurred_at`, `consent_state`, `metadata` JSON. Index `(event_name, occurred_at)`, `(campaign_id)`.

### `marketing_consents`
`id`, `subject_type` (`lead|user`), `subject_id`, `channel` (`email|sms|whatsapp|analytics|marketing`),
`status` (`granted|withdrawn`), `source`, `policy_version`, `ip`, `user_agent`, `occurred_at`.
Index `(subject_type, subject_id, channel, occurred_at)`.

### `unsubscribe_records`
`id`, `channel`, `email_hash` null, `phone_hash` null, `token` unique, `reason`, `unsubscribed_at`.
No plaintext PII required; resolution uses hashes.

### `tracking_preferences`
`id`, `session_id` or `user_id`, `necessary` bool, `analytics` bool, `marketing` bool,
`policy_version`, `updated_at`.

### `marketing_workflows`
`id`, `uuid`, `name`, `trigger_type` (`lead_created|lead_status_changed|lead_assigned|form_submitted|
campaign_activated|schedule`), `status` (`draft|active|paused|archived`), `conditions` JSON,
`created_by`, `approved_by`, `timestamps`.
Rules: activation requires approval; a global kill switch pauses all workflows.

### `workflow_steps`
`id`, `workflow_id` FK cascade, `sort_order`, `action_type` (`email|sms|whatsapp|notify_user|
create_task|webhook`), `config` JSON, `delay_minutes`, `timestamps`.

### `workflow_executions`
`id`, `workflow_id`, `subject_type`, `subject_id`, `status`
(`queued|running|completed|failed|skipped|cancelled`), `idempotency_key` unique, `started_at`,
`finished_at`, `error`, `context` JSON. Index `(workflow_id, status)`.

### `workflow_execution_steps`
`id`, `workflow_execution_id`, `workflow_step_id`, `status`, `attempts`, `ran_at`, `error`.

## 10. Permissions

New platform permissions, replacing the unused umbrella `content.manage`:

`cms.view`, `cms.edit`, `cms.review`, `cms.publish`, `media.manage`, `seo.manage`,
`structured_data.manage`, `blog.manage`, `leads.view`, `leads.manage`, `leads.export`,
`campaigns.view`, `campaigns.manage`, `campaigns.approve`, `campaigns.spend`,
`integrations.google.manage`, `integrations.meta.manage`, `analytics.view`, `automation.manage`,
`automation.approve`, `marketing.audit.view`, `marketing.settings.manage`.

Suggested roles: `marketing-manager`, `content-editor`, `content-publisher`, `seo-manager`,
`lead-agent`, `campaign-approver`, `marketing-analyst`, `marketing-integration-admin`.
Super Admin keeps all permissions. Editing, publishing, approving spend and exporting leads are
deliberately separate.

## 11. Retention and deletion

- Leads: configurable window; anonymise PII after retention, keep aggregate attribution.
- Analytics events: configurable (default 14 months) with consent state retained.
- Audit logs, approvals, spend records: never deleted.
- Media: soft delete with usage warnings; versions retained.
- Published page versions: retained; drafts purged after a configured window.
