# Staff guide — BRATHAMATU website and marketing workspace

This guide covers the public website tools in the staff app. Sign in at `/login`; the sidebar shows
**Website** sections only for roles that hold the matching permission. The **View public website** link
opens the live site in a new tab.

## Roles at a glance

| Role | Can do |
| --- | --- |
| Super admin | Everything, including integration setup and audit trail |
| Content manager | Create and review pages, approve and publish, manage media, navigation, content library, SEO, blog, campaigns, automation |
| Content editor | Draft and submit pages, blog posts and content; cannot approve or publish |
| Marketer (marketing role) | Analytics, campaigns, leads, automation, social publishing as granted |
| Business owner / tenant roles | No website workspace; they use the stock-taking platform only |

Separation of duties is enforced: the author of a page, campaign or testimonial cannot approve it while
the setting is enabled.

## Pages

1. **Marketing pages** lists every page with its status. Create a page, choose a template and add
   sections (rich text, product cards, FAQ, testimonials and so on).
2. Save, then **Submit for review**. A reviewer with approval rights opens the same page and approves.
3. **Publish** makes the page live. A signed preview URL lets you check unpublished work; preview
   pages are always noindex.
4. Editing a live page starts a new draft while the published snapshot stays live until you publish
   again. Version history lets you restore an earlier version into a draft.
5. Unknown section types (for example raw HTML) are rejected by the API; use the provided section
   types.

## Media library

Upload JPEG, PNG, WebP, GIF or PDF up to 8 MB. SVG is not accepted. Give every image alt text. Replacing
a file keeps a version history and updates every page that references it; deleting a file warns you
when it is still in use.

## Navigation, content library and SEO

- **Navigation** builds the primary and footer menus; each item points to one page or one URL, with at
  most two levels.
- **Content library** holds FAQs, testimonials, CTAs and banners. Banners and testimonials need review
  approval before they appear publicly.
- **Technical SEO** shows redirects (301/302/307/308), metadata diagnostics, broken links, canonical
  issues and Core Web Vitals. Add redirects here when a slug changes.

## Blog and resources

**Blog & resources** manages articles, categories, tags, downloads and the editorial calendar with the
same draft → review → approve → publish workflow. Downloads can require a consent confirmation.

## Leads

Every quote/contact form submission lands in **Leads** with source, UTM attribution and consent
history. Use the pipeline to assign, update status and export a CSV. Do not add people to marketing
lists without a recorded consent.

## Campaigns

**Campaigns** manages objectives, audiences, budgets, creative and UTM links. The author cannot approve
their own campaign; activation requires approval plus a spend holder where configured. Provider ad
budgets stay inside Google/Meta, not here.

## Analytics

**Analytics** reports only consented data: consent overview, lead funnel, campaign performance, landing
pages and recent events. Search performance shows an honest unavailable state until Search Console is
connected — never treat it as zero traffic.

## Automation

**Automation** builds lead follow-up workflows: triggers (lead created/assigned/status changed),
conditions, ordered steps (in-app notification, lead activity, timed wait, outbound message) and an
executions log. Keep the **kill switch** paused until an outbound provider is approved. Outbound steps
never send today: without consent they record `CONSENT_MISSING`, with consent they record
`PROVIDER_NOT_APPROVED` and show the rendered preview.

## SEO and publishing habits

- Give every page a unique title and description; the diagnostics flag duplicates and missing values.
- Keep one H1 per page and meaningful alt text on images.
- Publish, then check the sitemap entry and Search Console coverage before promoting a page.
- Never paste customer names, emails or phone numbers into page content or analytics events.

## When something looks wrong

- A page is not visible: check status, publish time and whether it is live; unpublished pages 404 by
  design.
- A form fails: check the message under the field; validation errors map to specific fields, and
  consent must be given where requested.
- Integrations show setup-required: the owner credentials are missing or the connection expired. Ask
  the super admin; do not invent or paste tokens into templates.
- Outbound messages stay blocked: no provider is approved yet; this is expected behaviour.
