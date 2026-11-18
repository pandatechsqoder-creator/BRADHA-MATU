import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-12-analytics/screens'
const runId = Date.now().toString().slice(-6)

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

async function token(page: Page): Promise<string> {
  const value = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
  expect(value).toBeTruthy()
  return value as string
}

test.describe('consent-aware analytics', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(300_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('declined analytics never sends events and the choice can be reopened', async ({ page }) => {
    const eventRequests: string[] = []
    page.on('request', (request) => {
      if (request.url().includes('/public/analytics/events')) eventRequests.push(request.url())
    })

    await page.goto(`${PUBLIC}/`)
    const banner = page.getByRole('dialog', { name: 'Analytics consent' })
    await expect(banner).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-consent-banner.png`, fullPage: true })

    await banner.getByRole('button', { name: 'Necessary only' }).click()
    await expect(banner).toBeHidden()
    await expect(page.getByRole('button', { name: 'Cookie preferences' })).toBeVisible()

    await page.waitForTimeout(1200)
    expect(eventRequests).toHaveLength(0)

    await page.reload()
    await expect(banner).toBeHidden()
    await page.waitForTimeout(800)
    expect(eventRequests).toHaveLength(0)
  })

  test('accepted analytics stores consented traffic with campaign attribution', async ({ page }) => {
    const eventResponses: number[] = []
    page.on('response', (response) => {
      if (response.url().includes('/public/analytics/events')) eventResponses.push(response.status())
    })

    await page.goto(`${PUBLIC}/?utm_source=e2e&utm_medium=playwright&utm_campaign=demo-supermarket-launch`)
    const banner = page.getByRole('dialog', { name: 'Analytics consent' })
    await banner.getByRole('button', { name: 'Accept analytics' }).click()
    await expect(banner).toBeHidden()

    await expect.poll(() => eventResponses.length, { timeout: 30_000 }).toBeGreaterThan(0)
    expect(eventResponses).toContain(202)
    await expect(page.getByRole('button', { name: 'Cookie preferences' })).toBeVisible()

    await login(page, superAdmin)
    const headers = { Authorization: `Bearer ${await token(page)}`, Accept: 'application/json' }
    const overview = await page.request.get(`${API}/platform/marketing/analytics/overview?days=7`, { headers })
    expect(overview.status()).toBe(200)
    const body = await overview.json()
    expect(body.data.sessions).toBeGreaterThanOrEqual(1)
    expect(body.data.page_views).toBeGreaterThanOrEqual(1)
    expect(body.data.consent.analytics_granted).toBeGreaterThanOrEqual(1)

    const campaigns = await page.request.get(`${API}/platform/marketing/analytics/campaigns?days=7`, { headers })
    const campaignBody = await campaigns.json()
    const demo = campaignBody.data.campaigns.find(
      (campaign: { utm_campaign: string }) => campaign.utm_campaign === 'demo-supermarket-launch',
    )
    expect(demo).toBeTruthy()
    expect(demo.analytics_events).toBeGreaterThanOrEqual(1)
  })

  test('a public form submission becomes a reconciled server-side conversion', async ({ page }) => {
    await page.goto(
      `${PUBLIC}/request-quote?utm_source=e2e&utm_medium=playwright&utm_campaign=demo-supermarket-launch`,
    )
    const form = page.locator('form[data-form="quote"]')
    await form.getByLabel('Your name').fill('Analytics Conversion Lead')
    await form.getByLabel('Business name').fill(`Analytics Traders ${runId}`)
    await form.getByLabel('Email').fill(`analytics.${runId}@phase12.test`)
    await form.getByLabel('Branches or stock locations (optional)').fill('3')
    await form.getByLabel('Message (optional)').fill(`Phase 12 conversion journey ${runId}`)
    await form.getByLabel(/I agree that BRATHAMATU may contact me/).check()
    await page.waitForTimeout(2400)
    await form.getByRole('button', { name: 'Send request' }).click()
    await expect(page.getByTestId('lead-success')).toContainText('Your reference is LD-')

    await login(page, superAdmin)
    const headers = { Authorization: `Bearer ${await token(page)}`, Accept: 'application/json' }
    const funnel = await page.request.get(`${API}/platform/marketing/analytics/funnel?days=7`, { headers })
    expect(funnel.status()).toBe(200)
    const body = await funnel.json()
    expect(body.data.reconciled).toBe(true)
    expect(body.data.converted_leads).toBeGreaterThanOrEqual(1)
    expect(body.data.conversion_events.some((row: { event: string }) => row.event === 'quote_request')).toBe(true)
  })

  test('the analytics workspace shows funnel, campaign, landing-page and honest search states', async ({ page }) => {
    await login(page, superAdmin)
    await page.getByRole('link', { name: 'Analytics', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Analytics' })).toBeVisible()
    await expect(page.getByTestId('analytics-range')).toBeVisible()
    await expect(page.getByTestId('analytics-daily')).toBeVisible()
    await expect(page.getByTestId('analytics-funnel')).toBeVisible()
    await expect(page.getByTestId('analytics-search-unavailable')).toBeVisible()
    await expect(page.getByTestId('analytics-search-unavailable')).toContainText('never estimates or invents')

    const campaignRow = page.locator('[data-testid^="analytics-campaign-row-"]').filter({ hasText: 'Demo Supermarket Launch' })
    await expect(campaignRow.first()).toBeVisible()
    await page.screenshot({ path: `${outputDir}/02-analytics-dashboard.png`, fullPage: true })

    await page.getByTestId('analytics-range').selectOption('7')
    await expect(page.locator('[data-testid^="analytics-campaign-row-"]').first()).toBeVisible()
  })

  test('editors and tenants cannot open the analytics workspace', async ({ page }) => {
    await login(page, editor)
    await expect(page.getByRole('link', { name: 'Analytics', exact: true })).toHaveCount(0)
    await page.goto('/platform/marketing/analytics')
    await expect(page.getByText('You do not have permission to view this page.')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-editor-permission-denied.png`, fullPage: true })

    await page.evaluate(() => localStorage.clear())
    await login(page, tenant)
    await page.goto('/platform/marketing/analytics')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/04-tenant-denied.png`, fullPage: true })
  })
})
