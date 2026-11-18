import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-6-technical-seo/screens'
const runId = Date.now().toString().slice(-6)

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const publisher = { email: 'publisher@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

async function logout(page: Page): Promise<void> {
  await page.evaluate(() => localStorage.clear())
}

async function createPage(page: Page, title: string, slug: string): Promise<void> {
  await page.goto('/platform/marketing/pages')
  await page.getByRole('button', { name: 'New page' }).click()
  await page.locator('input[name="page-title"]').fill(title)
  await page.locator('input[name="page-slug"]').fill(slug)
  await page.getByRole('button', { name: 'Create draft' }).click()
  await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
}

async function addRichText(page: Page, heading: string, paragraph: string): Promise<void> {
  await page.getByLabel('Section type').selectOption('rich_text')
  await page.getByRole('button', { name: 'Add section' }).click()
  const content = page.getByTestId('section-list').getByLabel('Content (JSON)').first()
  await content.fill(JSON.stringify({ heading, paragraphs: [paragraph], bullets: [] }, null, 2))
  await page.getByRole('button', { name: 'Save sections' }).click()
  await expect(page.getByText('Sections saved.')).toBeVisible()
}

async function openPage(page: Page, title: string): Promise<void> {
  await page.goto('/platform/marketing/pages')
  await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
}

async function addRedirect(page: Page, from: string, to: string, reason: string): Promise<void> {
  await page.locator('input[name="redirect-from"]').fill(from)
  await page.locator('input[name="redirect-to"]').fill(to)
  await page.locator('input[name="redirect-reason"]').fill(reason)
  await page.getByRole('button', { name: 'Add redirect' }).click()
  await expect(page.getByText('Redirect created.')).toBeVisible()
}

async function deleteRedirect(page: Page, fromPath: string): Promise<void> {
  const row = page.locator(`[data-testid="redirect-row"][data-from="${fromPath}"]`)
  await row.getByRole('button', { name: 'Delete' }).click()
  await expect(page.getByText('Redirect deleted.')).toBeVisible()
  await expect(row).toHaveCount(0)
}

test.describe('technical SEO operations journey', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(480_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('redirect CRUD, diagnostics, vitals and slug-change redirects', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing/seo')
    await expect(page.getByRole('heading', { level: 1, name: 'Technical SEO' })).toBeVisible()
    await expect(page.getByTestId('seo-diagnostics')).toBeVisible()
    await expect(page.getByTestId('seo-issue-counts')).toBeVisible()
    await expect(
      page.getByTestId('redirect-list').or(page.getByText('No redirects yet')),
    ).toBeVisible()
    await expect(
      page.getByTestId('vitals-summary').or(page.getByText('No vitals samples yet')),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-seo-overview.png`, fullPage: true })

    const legacyFrom = `/phase6-legacy-${runId}`
    await addRedirect(page, legacyFrom, '/pricing', 'Phase 6 evidence redirect')
    const row = page.getByTestId('redirect-row').filter({ hasText: legacyFrom })
    await expect(row).toContainText('301')
    await expect(row).toContainText('Phase 6 evidence redirect')
    await page.screenshot({ path: `${outputDir}/02-redirect-created.png`, fullPage: true })

    const redirectResponse = await page.request.get(`${PUBLIC}${legacyFrom}`, { maxRedirects: 0 })
    expect(redirectResponse.status()).toBe(301)
    expect(redirectResponse.headers()['location']).toContain('/pricing')

    await row.getByRole('button', { name: 'Disable' }).click()
    await expect(page.getByText('Redirect disabled.')).toBeVisible()
    await expect(row).toContainText('inactive')
    const disabled = await page.request.get(`${PUBLIC}${legacyFrom}`, { maxRedirects: 0 })
    expect(disabled.status()).toBe(404)

    await deleteRedirect(page, legacyFrom)

    const chainA = `/phase6-chain-a-${runId}`
    const chainB = `/phase6-chain-b-${runId}`
    await addRedirect(page, chainA, chainB, 'Chain hop one')
    await addRedirect(page, chainB, '/pricing', 'Chain hop two')

    await page.reload()
    await expect(
      page.locator(`[data-testid="redirect-row"][data-from="${chainB}"]`),
    ).toBeVisible()
    await expect(
      page.getByTestId('vitals-summary').or(page.getByText('No vitals samples yet')),
    ).toBeVisible()
    await expect(page.getByTestId('seo-issue-details')).toContainText(chainA)
    await expect(page.getByTestId('seo-issue-details')).toContainText(chainB)
    await page.screenshot({ path: `${outputDir}/03-diagnostics-chain.png`, fullPage: true })

    await deleteRedirect(page, chainA)
    await deleteRedirect(page, chainB)

    const vitalsPath = `/phase6-vitals-${runId}`
    for (const [index, lcp] of [1200, 1800, 2600].entries()) {
      const sample = await page.request.post(`${API}/public/web-vitals`, {
        data: {
          url: `${PUBLIC}${vitalsPath}`,
          device: index === 1 ? 'desktop' : 'mobile',
          lcp,
          cls: 0.04,
          inp: 90,
          ttfb: 280,
        },
      })
      expect(sample.status()).toBe(202)
    }

    await page.reload()
    await page.locator('input[name="vitals-path"]').fill(vitalsPath)
    await expect(page.getByTestId('vitals-summary')).toContainText(vitalsPath)
    await expect(page.getByTestId('vitals-summary')).toContainText('3')
    await page.screenshot({ path: `${outputDir}/04-core-web-vitals.png`, fullPage: true })

    const title = `Phase 6 slug ${runId}`
    const oldSlug = `phase6-old-${runId}`
    const newSlug = `phase6-new-${runId}`

    await logout(page)
    await login(page, editor)
    await createPage(page, title, oldSlug)
    await addRichText(page, 'Slug change evidence', 'Published before the URL change.')
    await page.locator('input[name="seo-title"]').fill('Phase 6 slug change evidence')
    await page.getByLabel('Meta description').fill('Published before the URL change.')
    await page.getByRole('button', { name: 'Save SEO' }).click()
    await expect(page.getByText('Page details and SEO saved.')).toBeVisible()
    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')

    await logout(page)
    await login(page, publisher)
    await openPage(page, title)
    await page.getByRole('button', { name: 'Approve', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('published')
    expect((await page.request.get(`${PUBLIC}/${oldSlug}`)).status()).toBe(200)

    await page.locator('input[name="page-slug"]').fill(newSlug)
    await page.getByRole('button', { name: 'Save details' }).click()
    await expect(page.getByText('Page details and SEO saved.')).toBeVisible()
    await expect(page.getByTestId('page-status')).toHaveText('draft')

    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')
    await page.getByRole('button', { name: 'Approve', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('published')

    const moved = await page.request.get(`${PUBLIC}/${oldSlug}`, { maxRedirects: 0 })
    expect(moved.status()).toBe(301)
    expect(moved.headers()['location']).toContain(`/${newSlug}`)
    const destination = await page.request.get(`${PUBLIC}/${newSlug}`)
    expect(destination.status()).toBe(200)
    expect(await destination.text()).toContain('Published before the URL change.')

    await page.goto('/platform/marketing/seo')
    const autoRow = page.getByTestId('redirect-row').filter({ hasText: `/${oldSlug}` })
    await expect(autoRow).toContainText(`/${newSlug}`)
    await expect(autoRow).toContainText('Page slug changed')
    await expect(
      page.getByTestId('vitals-summary').or(page.getByText('No vitals samples yet')),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/05-slug-change-redirect.png`, fullPage: true })

    await openPage(page, title)
    await page.getByRole('button', { name: 'Unpublish' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('draft')

    await page.goto('/platform/marketing/seo')
    await deleteRedirect(page, `/${oldSlug}`)
  })

  test('permission boundaries for the SEO workspace', async ({ page }) => {
    await login(page, editor)
    await page.goto('/platform/marketing/seo')
    await expect(page.getByRole('heading', { level: 1, name: 'Technical SEO' })).toBeVisible()
    await expect(page.getByTestId('seo-diagnostics')).toBeVisible()
    await expect(page.getByTestId('redirect-form')).toHaveCount(0)
    await expect(
      page.getByText('Adding or editing redirects requires the cms.publish permission.'),
    ).toBeVisible()

    await logout(page)
    await login(page, tenant)
    await page.goto('/platform/marketing/seo')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
  })
})
