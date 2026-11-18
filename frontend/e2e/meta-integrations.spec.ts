import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const outputDir = '../docs/evidence/phase-10-meta-integrations/screens'

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

test.describe('Meta integrations and social publishing', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('super admin sees the honest Meta not-configured state with no invented metrics', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing')
    const shortcut = page.getByRole('link', { name: 'Meta integrations' }).first()
    await expect(shortcut).toBeVisible()
    await shortcut.click()

    await expect(page.getByRole('heading', { level: 1, name: 'Meta integrations' })).toBeVisible()
    await expect(page.getByTestId('meta-config-missing')).toBeVisible()
    await expect(page.getByText('Owner setup required')).toBeVisible()
    await expect(page.getByTestId('meta-connect-button')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Sync all' })).toBeDisabled()
    await expect(page.getByTestId('meta-spend-actions-unavailable')).toContainText('Read-only')
    await expect(page.getByText('No Meta account connected')).toBeVisible()
    await expect(page.getByText('No Pages imported')).toBeVisible()
    await expect(page.getByText('No sync runs yet')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-meta-integrations-unconfigured.png`, fullPage: true })

    const token = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    expect(token).toBeTruthy()
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    const status = await page.request.get(`${API}/platform/marketing/integrations/meta`, { headers })
    expect(status.status()).toBe(200)
    const body = await status.json()
    expect(body.data.provider).toBe('meta')
    expect(body.data.configured).toBe(false)
    expect(body.data.connection).toBeNull()
    expect(body.data.capabilities.spend_actions).toBe(false)
    expect(body.data.counts.pages).toBe(0)
    expect(body.data.counts.ad_accounts).toBe(0)
    expect(body.data.counts.campaigns).toBe(0)
    expect(body.data.runs).toEqual([])

    const sync = await page.request.post(`${API}/platform/marketing/integrations/meta/sync`, {
      headers,
      data: { type: 'all' },
    })
    expect(sync.status()).toBe(409)
    expect((await sync.json()).error.code).toBe('META_NOT_CONNECTED')

    const connect = await page.request.get(`${API}/platform/marketing/integrations/meta/connect`, { headers })
    expect(connect.status()).toBe(422)
    expect((await connect.json()).error.code).toBe('META_NOT_CONFIGURED')
  })

  test('drafting stays available without Meta and submitting for review needs a Page', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing/social')

    await expect(page.getByRole('heading', { level: 1, name: 'Social publishing' })).toBeVisible()
    await expect(page.getByText('Meta is not connected')).toBeVisible()
    await expect(page.getByTestId('social-page-select')).toContainText('No Page (draft only)')

    await page.getByTestId('social-caption').fill(
      'Stock-taking that protects margin: draft social post for the launch checklist.',
    )
    await page.getByTestId('social-save-draft').click()
    await expect(page.getByText('Draft saved. Submit it for review when it is ready.')).toBeVisible()

    const row = page.locator('[data-testid^="social-post-row-"]').first()
    await expect(row).toBeVisible()
    await expect(row).toContainText('draft')
    await expect(row.getByRole('button', { name: 'Submit for review' })).toBeDisabled()
    await page.screenshot({ path: `${outputDir}/02-social-draft-workflow.png`, fullPage: true })

    const token = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
    const list = await page.request.get(`${API}/platform/marketing/social-posts`, { headers })
    expect(list.status()).toBe(200)
    const body = await list.json()
    expect(body.data.counts.draft).toBeGreaterThanOrEqual(1)
    expect(body.data.options.pages).toEqual([])

    const created = body.data.posts.find((post: { caption: string }) => post.caption.includes('launch checklist'))
    expect(created).toBeTruthy()
    const cleanup = await page.request.delete(`${API}/platform/marketing/social-posts/${created.id}`, { headers })
    expect(cleanup.status()).toBe(200)
  })

  test('content editors can draft but cannot open Meta integrations', async ({ page }) => {
    await login(page, editor)
    await page.goto('/platform/marketing')
    await expect(page.getByRole('link', { name: 'Meta integrations' })).toHaveCount(0)
    await expect(page.getByRole('link', { name: 'Social publishing' }).first()).toBeVisible()

    await page.goto('/platform/marketing/integrations/meta')
    await expect(page.getByText('You do not have permission to view this page.')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-editor-meta-permission-denied.png`, fullPage: true })

    await page.goto('/platform/marketing/social')
    await expect(page.getByRole('heading', { level: 1, name: 'Social publishing' })).toBeVisible()
  })

  test('tenant accounts cannot open Meta integrations or social publishing', async ({ page }) => {
    await login(page, tenant)
    await page.goto('/platform/marketing/integrations/meta')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/04-tenant-denied.png`, fullPage: true })
  })
})
