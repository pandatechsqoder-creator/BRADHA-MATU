import fs from 'node:fs'
import { expect, test, type APIRequestContext } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const outputDir = '../docs/evidence/phase-11-campaigns/screens'

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const marketing = { email: 'marketing@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

async function apiToken(request: APIRequestContext, account: { email: string; password: string }): Promise<string> {
  const response = await request.post(`${API}/auth/login`, { data: { ...account, device_name: 'e2e' } })
  expect(response.ok()).toBeTruthy()
  return (await response.json()).data.token
}

test.describe('Campaign management', () => {
  test.describe.configure({ mode: 'serial' })

  const suffix = Date.now().toString().slice(-6)
  const campaignName = `Autumn stock-take ${suffix}`
  const deniedCampaignName = `Spend denial ${suffix}`

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('super admin drafts a campaign with budget, audience and UTM tracking', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing')
    await page.getByRole('link', { name: 'Campaigns', exact: true }).first().click()

    await expect(page.getByRole('heading', { level: 1, name: 'Campaigns' })).toBeVisible()
    await expect(page.getByTestId('campaign-status-filter')).toBeVisible()

    await page.getByTestId('campaign-name').fill(campaignName)
    await page.getByTestId('campaign-objective').selectOption('stocktake_requests')
    await page.getByTestId('campaign-channel').selectOption('google_ads')

    const landingPage = page.getByTestId('campaign-landing-page')
    await expect(landingPage.locator('option').nth(1)).toBeAttached({ timeout: 30_000 })
    await landingPage.selectOption({ index: 1 })
    expect(await landingPage.inputValue()).not.toBe('')

    await page.getByTestId('campaign-budget').fill('1500')
    const start = new Date(Date.now() + 7 * 86_400_000)
    await page.getByTestId('campaign-starts-at').fill(
      new Date(start.getTime() - start.getTimezoneOffset() * 60_000).toISOString().slice(0, 16),
    )
    await page.getByTestId('campaign-audience').fill('Independent retailers and supermarkets with 1–10 branches')
    await page.getByTestId('campaign-utm-source').fill('google')
    await page.getByTestId('campaign-utm-medium').fill('cpc')
    await page.getByTestId('campaign-utm-campaign').fill('autumn-stocktake')
    await page.getByTestId('campaign-save-draft').click()

    await expect(page.getByText('Draft campaign saved. Add creative, then submit it for approval.')).toBeVisible()

    const row = page.locator('[data-testid^="campaign-row-"]').filter({ hasText: campaignName }).first()
    await expect(row).toBeVisible()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('draft')
    await expect(row.locator('[data-testid^="campaign-utm-url-"]')).toBeVisible()

    if ((await page.getByTestId('campaign-detail-panel').count()) === 0) {
      await row.locator('[data-testid^="campaign-details-"]').click()
    }
    await expect(page.getByTestId('campaign-detail-panel')).toBeVisible()
    await expect(page.getByTestId('campaign-revisions')).toContainText('Campaign created')
    await page.screenshot({ path: `${outputDir}/01-campaign-draft.png`, fullPage: true })

    const token = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const list = await page.request.get(`${API}/platform/marketing/campaigns`, { headers })
    expect(list.status()).toBe(200)
    const body = await list.json()
    const created = body.data.campaigns.find((campaign: { name: string }) => campaign.name === campaignName)
    expect(created).toBeTruthy()
    expect(created.budget_amount).toBe(1500)
    expect(created.utm.source).toBe('google')
    expect(created.landing_page).not.toBeNull()
    expect(created.utm_url).toContain('utm_source=google')
    expect(created.utm_url).toContain('utm_campaign=autumn-stocktake')
  })

  test('submission moves the campaign to review and the owner cannot approve it', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing/campaigns')

    const row = page.locator('[data-testid^="campaign-row-"]').filter({ hasText: campaignName }).first()
    await row.locator('[data-testid^="campaign-submit-"]').click()
    await expect(page.getByText('Campaign submitted for approval.')).toBeVisible()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('review')

    page.once('dialog', (dialog) => dialog.accept('Owner note'))
    await row.locator('[data-testid^="campaign-approve-"]').click()
    await expect(page.getByText(/cannot be approved or rejected by its owner or creator/)).toBeVisible()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('review')
    await page.screenshot({ path: `${outputDir}/02-campaign-review-owner-blocked.png`, fullPage: true })

    const token = await apiToken(page.request, superAdmin)
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const list = await page.request.get(`${API}/platform/marketing/campaigns`, { headers })
    const body = await list.json()
    const campaign = body.data.campaigns.find((item: { name: string }) => item.name === campaignName)
    expect(campaign.status).toBe('review')

    const denied = await page.request.post(`${API}/platform/marketing/campaigns/${campaign.id}/approve`, {
      headers,
      data: { notes: 'Self approval attempt' },
    })
    expect(denied.status()).toBe(422)
    expect((await denied.json()).error.code).toBe('CAMPAIGN_APPROVAL_CONFLICT')
  })

  test('a second approver approves, spend permission activates and results are recorded', async ({ page }) => {
    await login(page, marketing)
    await page.goto('/platform/marketing/campaigns')

    const row = page.locator('[data-testid^="campaign-row-"]').filter({ hasText: campaignName }).first()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('review')

    page.once('dialog', (dialog) => dialog.accept('Budget approved for Q4'))
    await row.locator('[data-testid^="campaign-approve-"]').click()
    await expect(page.getByText(/Campaign approved\./)).toBeVisible()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('approved')

    page.once('dialog', (dialog) => dialog.accept())
    await row.locator('[data-testid^="campaign-activate-"]').click()
    await expect(page.getByText('Campaign activated.')).toBeVisible()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('active')

    await expect(page.getByTestId('campaign-detail-panel')).toBeVisible()
    await page.getByTestId('campaign-results-leads').fill('12')
    await page.getByTestId('campaign-results-conversions').fill('4')
    await page.getByTestId('campaign-results-spend').fill('250.50')
    await page.getByTestId('campaign-results-notes').fill('Verified against CRM export')
    await page.getByTestId('campaign-save-results').click()

    await expect(page.getByTestId('campaign-results-summary')).toContainText('leads 12')
    await expect(page.getByTestId('campaign-revisions')).toContainText('Campaign activated')
    await page.screenshot({ path: `${outputDir}/03-campaign-active-results.png`, fullPage: true })

    const token = await apiToken(page.request, marketing)
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const list = await page.request.get(`${API}/platform/marketing/campaigns`, { headers })
    const body = await list.json()
    const campaign = body.data.campaigns.find((item: { name: string }) => item.name === campaignName)
    expect(campaign.status).toBe('active')
    expect(campaign.results.leads).toBe(12)
    expect(campaign.results.spend).toBe(250.5)

    const detail = await page.request.get(`${API}/platform/marketing/campaigns/${campaign.id}`, { headers })
    const detailBody = await detail.json()
    expect(detailBody.data.approvals[0].decision).toBe('approved')
    expect(detailBody.data.approvals[0].decided_by).toBe('Brathamatu Marketing Manager')
  })

  test('editors cannot activate campaigns and tenants cannot open the workspace', async ({ page }) => {
    const superToken = await apiToken(page.request, superAdmin)
    const superHeaders = { Authorization: `Bearer ${superToken}`, Accept: 'application/json' }

    const create = await page.request.post(`${API}/platform/marketing/campaigns`, {
      headers: superHeaders,
      data: {
        name: deniedCampaignName,
        objective: 'pos_demos',
        channel: 'email',
        budget_amount: 300,
        budget_currency: 'GBP',
        starts_at: new Date(Date.now() + 86_400_000).toISOString(),
      },
    })
    expect(create.status()).toBe(201)
    const campaignId = (await create.json()).data.id

    expect((await page.request.post(`${API}/platform/marketing/campaigns/${campaignId}/submit-review`, { headers: superHeaders })).status()).toBe(200)

    const marketingToken = await apiToken(page.request, marketing)
    const approve = await page.request.post(`${API}/platform/marketing/campaigns/${campaignId}/approve`, {
      headers: { Authorization: `Bearer ${marketingToken}`, Accept: 'application/json' },
      data: { notes: 'Approved' },
    })
    expect(approve.status()).toBe(200)

    await login(page, editor)
    await page.goto('/platform/marketing/campaigns')
    const row = page.locator('[data-testid^="campaign-row-"]').filter({ hasText: deniedCampaignName }).first()
    await expect(row.locator('[data-testid^="campaign-status-"]')).toContainText('approved')
    await expect(row.locator('[data-testid^="campaign-spend-required-"]')).toContainText('Spend permission required')
    await expect(row.locator('[data-testid^="campaign-activate-"]')).toHaveCount(0)
    await page.screenshot({ path: `${outputDir}/04-campaign-spend-permission-denied.png`, fullPage: true })

    const editorToken = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    const deny = await page.request.post(`${API}/platform/marketing/campaigns/${campaignId}/activate`, {
      headers: { Authorization: `Bearer ${editorToken}`, Accept: 'application/json' },
    })
    expect(deny.status()).toBe(403)

    await page.evaluate(() => localStorage.clear())
    await login(page, tenant)
    await page.goto('/platform/marketing/campaigns')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/05-tenant-denied.png`, fullPage: true })
  })
})
