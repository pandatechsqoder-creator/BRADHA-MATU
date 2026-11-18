BRATHAMATU STOCKTAKE
Master Implementation Plan: Public Website, SEO & Marketing Platform
This is the implementation blueprint for building the BRATHAMATU public website and its integrated marketing management system.

The objective is to build more than a normal company website. We are building a complete public-facing marketing and lead-generation platform, managed from the BRATHAMATU super-admin dashboard and connected to the existing stock-taking and BRATHAMATU POS systems.

The plan uses our established technology stack:
Component	Technology
Backend	Laravel 13
Frontend	React, TypeScript, TanStack
Database	MySQL 8
API	REST API /api/v1
Cache and queues	Redis + Laravel Queues
Media storage	S3-compatible storage
Integrations	Official Google and Meta APIs
Initial market	Kenya
Expansion	East Africa

Implementation principle: We first inspect the existing repositories, establish the architecture and API contract, and then implement one phase at a time. We do not rebuild existing operational features unnecessarily.

1. Project vision
The website must advertise two connected products.










BRATHAMATU Stocktake

Professional stock-taking services for businesses that need accurate physical stock counts, independent verification, cycle counts and recurring stock-taking.













BRATHAMATU POS

A business point-of-sale platform supporting sales, products, inventory workflows, branches and stock-taking service requests.



The public website must explain the products, attract customers, capture enquiries, provide useful information and make it easy for businesses to request services.
Primary business objectives
1. Generate stock-taking service enquiries.
2. Generate BRATHAMATU POS demo requests and registrations.
3. Advertise on-demand stock-taking.
4. Advertise monthly and annual stock-taking.
5. Advertise custom recurring stock-taking.
6. Establish BRATHAMATU's credibility and online presence.
7. Improve organic search visibility.
8. Manage Google and Meta integrations from super-admin.
9. Track marketing performance and conversions.
10. Give the marketing team control of website content without requiring developers.

2. The three system experiences
We should maintain three connected experiences.
Experience 1
Public website

What customers, prospects and search engines see.

Homepage, stock-taking pages, BRATHAMATU POS pages, industry pages, resources, contact and quote forms.


Experience 2
Customer and workforce application

Existing authenticated operational platform.

Business dashboards, stock-taking requests, job management, stock taker workflows and BRATHAMATU POS.


Experience 3
BRATHAMATU super-admin and marketing centre

The control centre for managing the public website and marketing.

CMS, SEO, structured data, Google, Meta, campaigns, leads, analytics, integrations and automation.


The public website and marketing system must integrate with the existing application instead of duplicating its business logic.

3. Public website structure
3.1 Main navigation
The proposed navigation is:
- Home
- Stock Taking
- BRATHAMATU POS
- How It Works
- Industries
- Pricing
- Locations
- Resources
- About Us
- Contact
- Request a Quote
- Login / Register
The navigation must be editable from the CMS.
3.2 Homepage






















12














The homepage should contain:
1. Professional hero section explaining what BRATHAMATU offers.
2. Request a Stock-Taking Quote CTA.
3. Explore BRATHAMATU POS CTA.
4. On-demand, monthly, annual and recurring service options.
5. Stock-taking service explanation.
6. BRATHAMATU POS product introduction.
7. Industries served.
8. How the service works.
9. Benefits and differentiators.
10. Approved testimonials and case studies.
11. Frequently asked questions.
12. Latest resources and articles.
13. Contact information.
14. Professional footer.
All important marketing content should be manageable through the CMS.
3.3 Stock-taking pages
We need dedicated landing pages for:
Page	Purpose
On-demand stock taking	Businesses requiring a count whenever needed
Monthly stock taking	Businesses requiring monthly counts
Annual stock taking	Businesses conducting yearly stock counts
Recurring stock taking	Businesses requiring custom schedules
Full stock taking	Complete physical stock counts
Cycle counting	Periodic counting of selected inventory
Branch stock taking	Multi-branch businesses
Warehouse stock taking	Warehouse and distribution operations
Independent verification	Independent physical count verification
Recounts	Discrepancy investigations and recounts

A recurring customer must still be able to request additional on-demand stock-taking jobs.
3.4 BRATHAMATU POS pages
We should create dedicated pages for:
- Retail POS.
- Supermarket POS.
- Pharmacy POS.
- Wholesale POS.
- Hardware POS.
- Restaurant POS.
- Multi-branch POS.
- Inventory management.
- Barcode and product catalogue.
- POS and stock-taking integration.
3.5 Industry pages
Create useful industry-specific pages for the businesses we intend to serve.

Each page should explain:
- The industry's inventory challenges.
- How BRATHAMATU helps.
- The relevant stock-taking process.
- Relevant BRATHAMATU POS capabilities.
- The benefits and limitations.
- How to request a quotation or demo.
Do not generate hundreds of repetitive location or industry pages merely to target keywords.

4. Super-admin marketing control centre
This is one of the most important components.

The super-admin should have a dedicated marketing management section in the existing administration dashboard.






















9











Proposed sidebar
BRATHAMATU — Marketing

Website Overview

CMS & Pages

Media Library

SEO Manager

Structured Data

Blog & Resources

Leads & Enquiries

Google Integrations

Meta Integrations

Campaign Manager

Analytics & Reports

Marketing Automation

Reviews & Reputation

Permissions & Audit


4.1 CMS and page management
Super-admin should be able to:
- Create and edit pages.
- Create landing pages.
- Edit text and images.
- Add and rearrange page sections.
- Manage navigation menus.
- Manage banners.
- Manage calls to action.
- Manage FAQs.
- Manage testimonials.
- Manage articles.
- Preview before publishing.
- Schedule publishing.
- Restore previous versions.
Publishing workflow:

Draft → Review → Approved → Scheduled → Published

Editing and publishing permissions must be separate.
4.2 Media library
Support:
- Image uploads.
- Document uploads.
- Folders and categories.
- Image optimisation.
- Alt text.
- Captions.
- File descriptions.
- Media usage tracking.
- File replacement and version management.
4.3 Lead management
Every enquiry from the website should appear in the internal CRM.

Lead types:
- Stock-taking quotation.
- BRATHAMATU POS demo.
- General contact.
- Callback request.
- Partnership enquiry.
- Other business enquiries.
Capture appropriate details, including:
- Business name.
- Contact person.
- Phone and email.
- Industry.
- Town or county.
- Number of branches, if supplied.
- Approximate product catalogue size, if supplied.
- Requested service.
- Stock-taking frequency.
- Message.
- Marketing source and campaign.
- Consent records.
Lead statuses should include New, Assigned, Contacted, Qualified, Quote Sent, Follow-up, Won, Lost and Archived.

A lead must never automatically create a paid stock-taking job.

5. SEO management system
SEO must be built into the application itself.

Super-admin should have an SEO manager that controls public page metadata and monitors technical SEO issues.
Required capabilities
SEO feature	Requirement
Page titles	Editable per page
Meta descriptions	Editable per page
Canonical URLs	Automatically generated and editable where authorised
Sitemap	Dynamically generated
Robots.txt	Managed safely
Open Graph	Social sharing metadata
Image alt text	Managed through media library
Breadcrumbs	Automatically generated
Redirects	Manage old and new URLs
Indexing	Control index/noindex
SEO diagnostics	Identify missing or duplicate metadata
Performance	Monitor Core Web Vitals

Search topics to target
Stock-taking services
- Stock taking services Kenya.
- Professional stock takers.
- Inventory counting services.
- Monthly stock taking.
- Annual stock taking.
- On-demand stock taking.
- Warehouse stock taking.
- Retail stock management.
BRATHAMATU POS
- POS system Kenya.
- Retail POS system.
- Supermarket POS.
- Pharmacy POS.
- Wholesale POS.
- Inventory management software.
- POS with stock management.
These are content-planning topics, not guaranteed ranking positions.
Important technical requirement
The public website must be server-rendered or reliably prerendered so search engines receive meaningful content in the delivered HTML.

A client-only application that exposes essential content only after JavaScript execution is not sufficient for our SEO requirements.

6. Structured data implementation
We must implement dynamic JSON-LD structured data.

The application should generate structured data from approved CMS records and business settings.
Schema	Where used
Organization	BRATHAMATU identity
WebSite	Main website
WebPage	Individual pages
Service	Stock-taking service pages
Product	Appropriate BRATHAMATU POS product information
SoftwareApplication	BRATHAMATU POS software pages
Article / BlogPosting	Blog articles
BreadcrumbList	Page navigation
FAQPage	Eligible visible FAQ content
LocalBusiness	Genuine eligible business locations only

Super-admin structured-data controls
- Select schema type.
- Preview generated JSON-LD.
- Validate schema.
- View missing fields.
- View errors.
- Review validation history.
- Manage approved business details.
- Enable or disable eligible schema templates.
Important: Structured data must match visible content. We must not invent reviews, ratings, locations, addresses, opening hours or prices. Search engines decide whether rich results are displayed.

7. Google integrations
Google integrations should be managed from the super-admin dashboard using official APIs and OAuth.


































7.1 Google Business Profile
Where supported by Google's APIs and account permissions:
- Connect authorised Google accounts.
- Manage eligible business locations.
- View profile information.
- Update supported profile information.
- Monitor available reviews.
- Support review response workflows where permitted.
- View available performance insights.
- Monitor integration errors.
We must not promise unsupported Business Profile features.
7.2 Google Search Console
Display:
- Search clicks.
- Impressions.
- Click-through rate.
- Average position.
- Search queries.
- Top-performing pages.
- Sitemap status.
- Available indexing information.
7.3 Google Analytics 4
Track:
- Website visits.
- Page views.
- Quote requests.
- POS demo requests.
- Contact submissions.
- Phone clicks.
- WhatsApp clicks.
- Resource downloads.
- Registration conversions.
7.4 Google Tag Manager
Support controlled GTM configuration and tracking changes.

Only authorised users should be allowed to modify tracking configuration. Consent preferences must control non-essential tracking.
7.5 Google integration dashboard
Show connection status, account/property, granted permissions, last successful synchronisation, errors, reconnect/disconnect and audit history.

8. Meta integrations
Meta integrations should support authorised Facebook, Instagram and advertising assets.


































Features
- Connect Meta Business assets.
- Connect Facebook Pages.
- Connect Instagram professional accounts.
- Publish supported content.
- Schedule approved posts.
- View available engagement insights.
- Connect authorised advertising accounts.
- View campaign performance.
- Create campaign drafts.
- Configure supported Pixel and Conversions API functionality.
Advertising safety
Campaign publishing and spending must require explicit approval.

We need:
- Campaign draft.
- Campaign review.
- Approval.
- Activation.
- Budget controls.
- Change history.
- Spend reporting.
An ordinary content editor or AI agent must never be able to spend money without the appropriate permission.

Meta features will depend on approved app permissions, account eligibility and current API capabilities.

9. Campaigns, analytics and automation
The marketing platform should eventually allow BRATHAMATU to coordinate marketing activities from one place.
Campaign channels
- Website landing pages.
- Email.
- SMS.
- WhatsApp.
- Facebook.
- Instagram.
- Meta advertising.
- Supported Google channels.
- Referral campaigns.
Email, SMS and WhatsApp require authorised providers and appropriate consent.
Campaign management
Each campaign should include:
- Name and objective.
- Channel.
- Target audience.
- Budget.
- Campaign period.
- Creative materials.
- Landing page.
- UTM tracking.
- Owner.
- Approver.
- Campaign status.
- Results.
Marketing automation examples
- Automatically acknowledge new enquiries.
- Notify sales staff about qualified leads.
- Remind staff to follow up.
- Send approved quote follow-ups.
- Send opted-in newsletters.
- Alert super-admin when integrations fail.
- Generate monthly marketing reports.
All automation must respect consent, permissions and approval requirements.

10. Technical architecture and database
10.1 Backend modules
Keep these as separate Laravel modules or clearly separated domains:
1. CMS.
2. Media management.
3. SEO.
4. Structured data.
5. Blog and resources.
6. Leads and CRM.
7. Google integrations.
8. Meta integrations.
9. Campaign management.
10. Marketing automation.
11. Analytics and attribution.
12. Reviews and reputation.
13. Marketing audit and permissions.
10.2 Database domains
The minimum database design must account for:
Domain	Example tables
CMS	pages, page_versions, page_sections
Navigation	menus, menu_items
Media	media_assets, media_folders
Blog	blog_posts, blog_categories, blog_tags
SEO	seo_metadata, redirects, sitemap_runs
Structured data	structured_data_templates, structured_data_validation_runs
Leads	leads, lead_activities, lead_assignments
Campaigns	marketing_campaigns, campaign_assets, campaign_approvals
Google	google_business_locations, google_search_properties, google_search_metrics
Meta	meta_pages, meta_ad_accounts, meta_campaign_records
Integrations	oauth_connections, integration_logs, integration_sync_runs
Analytics	analytics_events, conversion_events, campaign_attributions
Automation	marketing_workflows, workflow_steps, workflow_executions
Compliance	marketing_consents, unsubscribe_records, tracking_preferences
Audit	marketing_audit_logs

This is a logical starting schema. Relationships, indexes, foreign keys, retention and ownership must be finalised during Phase 0 before migrations are implemented.
10.3 API structure
Proposed API domains:
/api/v1/public/pages
/api/v1/public/navigation
/api/v1/public/services
/api/v1/public/industries
/api/v1/public/locations
/api/v1/public/articles
/api/v1/public/forms/contact
/api/v1/public/forms/quote
/api/v1/public/forms/pos-demo

/api/v1/admin/cms
/api/v1/admin/media
/api/v1/admin/seo
/api/v1/admin/structured-data
/api/v1/admin/leads
/api/v1/admin/campaigns
/api/v1/admin/marketing-workflows
/api/v1/admin/analytics
/api/v1/admin/integrations/google
/api/v1/admin/integrations/meta
/api/v1/admin/audit-logs


All API endpoints must have authentication, validation, authorisation, consistent responses, pagination, rate limits and audit requirements.

11. Security and privacy
The following are mandatory:
- Server-side role and permission enforcement.
- Encrypted OAuth tokens.
- No provider secrets in frontend code.
- Webhook signature validation.
- Idempotent webhook processing.
- Secure media uploads.
- Public form rate limits and spam protection.
- Audit logs for sensitive actions.
- Restricted lead exports.
- Consent management.
- Unsubscribe functionality.
- Privacy and retention workflows.
- Secure staging and production environments.
- No indexing of private application routes.
We should also review applicable Kenyan data protection and electronic-marketing requirements with qualified counsel before production launch.

12. Phased implementation roadmap
Each phase must be implemented, tested in a browser and accepted before the next phase begins.
Phase 0 — Repository audit and architecture
First assignment: no full website coding yet.
- Inspect Laravel repository.
- Inspect TanStack frontend.
- Map existing authentication, roles and APIs.
- Identify existing public website components.
- Review hosting and deployment constraints.
- Finalise public route map.
- Finalise CMS data model.
- Propose API contract changes.
- Decide SSR/prerender approach.
- Produce implementation backlog and risk register.
Exit gate: architecture approval

Phase 1 — Public website foundation
- Public layout.
- Header and footer.
- Responsive navigation.
- Design system.
- Reusable sections and components.
- Public route structure.
- Accessible responsive experience.
Exit gate: responsive browser tests pass

Phase 2 — Core marketing pages
- Homepage.
- About.
- Stock Taking.
- BRATHAMATU POS.
- How It Works.
- Industries.
- Pricing.
- Contact.
- FAQs and legal pages.
Exit gate: approved content and working navigation

Phase 3 — Service and product landing pages
- On-demand.
- Monthly.
- Annual.
- Recurring.
- Full stock take.
- Cycle counting.
- Branch and warehouse.
- Independent verification.
- BRATHAMATU POS industry pages.
Exit gate: unique pages and working CTAs

Phase 4 — CMS and media library
- Page editor.
- Reusable sections.
- Draft/review/publish workflow.
- Version history.
- Blog management.
- Media library.
- Preview and scheduled publishing.
Exit gate: publish and restore tested

Phase 5 — Lead capture and CRM
- Contact forms.
- Stock-taking quote forms.
- POS demo forms.
- Lead pipeline.
- Assignment.
- Notifications.
- Consent and UTM attribution.
Exit gate: leads stored and routed correctly

Phase 6 — Technical SEO
- Metadata.
- Canonicals.
- Sitemap.
- Robots.
- Redirects.
- Open Graph.
- Breadcrumbs.
- Index/noindex rules.
- SEO diagnostics.
Exit gate: crawl and metadata tests pass

Phase 7 — Structured data
- Organisation.
- Website.
- WebPage.
- Service.
- SoftwareApplication.
- BlogPosting.
- BreadcrumbList.
- Eligible FAQ and LocalBusiness.
- Admin preview and validation.
Exit gate: JSON-LD validated against visible content

Phase 8 — Blog and resources
- Blog index and details.
- Categories and tags.
- Authors.
- Editorial calendar.
- Related articles.
- Resource downloads.
Exit gate: content publishing and indexing tested

Phase 9 — Google integrations
- OAuth.
- Business Profile where supported.
- Search Console.
- GA4.
- GTM configuration.
- Sync jobs and logs.
- Integration health dashboard.
Exit gate: test accounts connect securely

Phase 10 — Meta integrations
- Meta OAuth.
- Business assets.
- Facebook Pages.
- Instagram professional accounts.
- Approved publishing.
- Insights.
- Ad reporting.
- Spend approval controls.
Exit gate: publishing and spend permissions tested

Phase 11 — Campaign management
- Campaign creation.
- Creative management.
- UTM builder.
- Budgets.
- Approvals.
- Scheduling.
- Attribution.
Exit gate: no campaign bypasses approval

Phase 12 — Analytics and reporting
- Event tracking.
- Conversion tracking.
- Lead funnel.
- Campaign performance.
- Landing-page reports.
- Search performance.
Exit gate: consent-aware reporting works

Phase 13 — Marketing automation
- Workflow engine.
- Triggers and conditions.
- Follow-up reminders.
- Approved notifications.
- Consent checks.
- Execution logs.
- Kill switch.
Exit gate: workflows execute safely

Phase 14 — Security and operational hardening
- Security review.
- Permissions review.
- Privacy and retention.
- Upload security.
- Backups.
- Recovery tests.
- Performance.
- Accessibility.
- Monitoring.
Exit gate: no unresolved critical/high security issue

Phase 15 — Production launch
- Domain and SSL.
- Production configuration.
- CDN and cache.
- Search Console.
- Analytics and consent.
- Approved Google/Meta connections.
- Initial content.
- Redirects.
- Staff training.
- Post-launch review.
Exit gate: production sign-off


13. Browser testing requirements
We must not accept a phase just because the developer says it is complete.

Every phase must be tested in a real browser.
Mandatory user journeys
1. A visitor opens the homepage.
2. The visitor explores stock-taking services.
3. The visitor reads the monthly and on-demand pages.
4. The visitor explores BRATHAMATU POS.
5. The visitor submits a stock-taking quotation request.
6. The visitor submits a POS demo request.
7. A lead agent receives and assigns the enquiry.
8. An editor creates a page draft.
9. A publisher approves and publishes it.
10. An SEO manager updates page metadata.
11. Structured data is generated and validated.
12. An administrator connects a Google test property.
13. An administrator connects Meta test assets.
14. A campaign is drafted and approved.
15. Analytics records permitted conversion events.
16. Unauthorised users are denied restricted actions.
17. Draft and private pages remain inaccessible to search engines.
Test desktop, tablet and mobile. Test loading, errors, empty states, form validation, accessibility and permissions.

14. Important decisions to keep configurable
We should not invent or hard-code the following:
- Official logo and brand identity.
- Official phone, WhatsApp and email.
- Confirmed service areas.
- Approved stock-taking prices.
- BRATHAMATU POS subscription pricing.
- Any bundled stock-taking benefits.
- Testimonials and customer logos.
- Marketing response-time promises.
- Email, SMS and WhatsApp providers.
- Google account and property ownership.
- Meta app permissions and ad accounts.
- Campaign spending limits.
- Data retention periods.
- Cookie and marketing consent policy.
These should be configurable and confirmed before the relevant production phase.

15. First implementation assignment
The first task for our coding agent should be Phase 0 only.

The agent must:
1. Clone and inspect the existing Laravel repository.
2. Inspect the existing TanStack frontend.
3. Map existing authentication and roles.
4. Map existing routes and layouts.
5. Identify current CMS and marketing functionality, if any.
6. Map the database and API conventions.
7. Identify deployment and rendering constraints.
8. Produce the public website route map.
9. Produce the proposed CMS database design.
10. Produce the API contract changes.
11. Recommend the SSR/prerender approach.
12. Identify Google and Meta integration prerequisites.
13. Produce a phased implementation backlog.
14. Report risks and dependencies.
15. Wait for our approval before Phase 1 coding.
Do not instruct the agent to build the entire website in one operation.

16. Final acceptance standard
The project is complete only when:
- The public website professionally advertises BRATHAMATU Stocktake and BRATHAMATU POS.
- All stock-taking frequencies are properly explained.
- Public enquiries are captured and managed.
- Super-admin can manage the website through the CMS.
- SEO controls work.
- Structured data is dynamic and valid.
- Google integrations work where API permissions allow.
- Meta integrations work where API permissions allow.
- Campaigns and ad spend are approval-controlled.
- Analytics and attribution work.
- Marketing automation respects permissions and consent.
- Private application routes remain protected.
- The website is responsive, accessible, secure and monitored.
- Every phase has passed browser testing and formal acceptance.
This public website plan must work alongside the main BRATHAMATU Stocktake Master Implementation Plan and Master API Contract.

My recommended implementation order
We should prioritise the website foundation, the actual marketing pages, CMS, lead CRM and SEO before moving into Google and Meta integrations.

That gives BRATHAMATU a usable public website and a functioning lead-generation system early, without delaying the business launch while complex third-party integrations are being developed.

The next concrete step is to give the coding agent the Phase 0 assignment and have it inspect both existing repositories before any implementation begins.