import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const outputDir = '../docs/evidence/phase-9-google-integrations/screens'

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

test.describe('Google integrations workspace', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('super admin sees the honest not-configured state and no invented Google metrics', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing')
    const shortcut = page.getByRole('link', { name: 'Google integrations' }).first()
    await expect(shortcut).toBeVisible()
    await shortcut.click()

    await expect(page.getByRole('heading', { level: 1, name: 'Google integrations' })).toBeVisible()
    await expect(page.getByTestId('google-config-missing')).toBeVisible()
    await expect(page.getByText('Owner setup required')).toBeVisible()
    await expect(page.getByTestId('google-connect-button')).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Sync all' })).toBeDisabled()
    await expect(page.getByTestId('google-reviews-unavailable')).toBeVisible()
    await expect(page.getByText('No Google account connected')).toBeVisible()
    await expect(page.getByText('No sync runs yet')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-google-integrations-unconfigured.png`, fullPage: true })

    const token = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    expect(token).toBeTruthy()
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }

    const status = await page.request.get(`${API}/platform/marketing/integrations/google`, { headers })
    expect(status.status()).toBe(200)
    const body = await status.json()
    expect(body.data.provider).toBe('google')
    expect(body.data.configured).toBe(false)
    expect(body.data.connection).toBeNull()
    expect(body.data.capabilities.reviews).toBe(false)
    expect(body.data.counts.search_properties).toBe(0)
    expect(body.data.counts.ga4_properties).toBe(0)
    expect(body.data.counts.business_locations).toBe(0)
    expect(body.data.runs).toEqual([])

    const sync = await page.request.post(`${API}/platform/marketing/integrations/google/sync`, {
      headers,
      data: { type: 'all' },
    })
    expect(sync.status()).toBe(409)
    expect((await sync.json()).error.code).toBe('GOOGLE_NOT_CONNECTED')

    const connect = await page.request.get(`${API}/platform/marketing/integrations/google/connect`, { headers })
    expect(connect.status()).toBe(422)
    expect((await connect.json()).error.code).toBe('GOOGLE_NOT_CONFIGURED')
  })

  test('editor roles do not see or open the Google integrations workspace', async ({ page }) => {
    await login(page, editor)
    await page.goto('/platform/marketing')
    await expect(page.getByRole('link', { name: 'Google integrations' })).toHaveCount(0)

    await page.goto('/platform/marketing/integrations/google')
    await expect(page.getByText('You do not have permission to view this page.')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/02-editor-permission-denied.png`, fullPage: true })
  })

  test('tenant accounts cannot open the Google integrations workspace', async ({ page }) => {
    await login(page, tenant)
    await page.goto('/platform/marketing/integrations/google')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-tenant-denied.png`, fullPage: true })
  })
})
