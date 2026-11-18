import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-5-leads-crm/screens'
const runId = Date.now().toString().slice(-6)

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

const leadEmail = 'playwright.lead@phase5.test'
const noteBody = `E2E pipeline note ${runId}`

async function logout(page: Page): Promise<void> {
  await page.evaluate(() => localStorage.clear())
}

async function token(page: Page): Promise<string> {
  const value = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
  expect(value).toBeTruthy()
  return value as string
}

async function submitQuote(page: Page): Promise<string> {
  await page.goto(`${PUBLIC}/request-quote?utm_source=playwright&utm_medium=e2e&utm_campaign=phase5-leads`)
  const form = page.locator('form[data-form="quote"]')
  await expect(form).toBeVisible()
  await page.screenshot({ path: `${outputDir}/01-public-quote-form.png`, fullPage: true })

  await form.getByLabel('Your name').fill('Playwright Lead')
  await form.getByLabel('Business name').fill(`Playwright Traders ${runId}`)
  await form.getByLabel('Email').fill(leadEmail)
  await form.getByLabel('Phone (optional)').fill('+254700123456')
  await form.getByLabel('Branches or stock locations (optional)').fill('4')
  await form.getByLabel('What do you need? (optional)').selectOption('Scheduled stock-taking')
  await form.getByLabel('Preferred frequency (optional)').selectOption('Monthly')
  await form.getByLabel('Town or city (optional)').fill('Nairobi')
  await form.getByLabel('Message (optional)').fill(`Phase 5 browser journey ${runId}`)
  await form.getByLabel(/I agree that BRATHAMATU may contact me/).check()
  await form.getByLabel(/Send me occasional stock-taking/).check()

  await page.waitForTimeout(2400)
  await form.getByRole('button', { name: 'Send request' }).click()

  const success = page.getByTestId('lead-success')
  await expect(success).toBeVisible()
  await expect(success).toContainText('Your reference is LD-')
  await page.screenshot({ path: `${outputDir}/02-public-quote-success.png`, fullPage: true })

  const text = await success.innerText()
  const match = text.match(/LD-\d{6}-[A-Z0-9]{5}/)
  expect(match).not.toBeNull()
  return match![0]
}

test.describe('lead pipeline journey', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(300_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('capture a quote, work the lead, withdraw consent and export CSV', async ({ page }) => {
    const reference = await submitQuote(page)

    await login(page, superAdmin)
    await page.goto('/platform/marketing/leads')
    await expect(page.getByRole('heading', { level: 1, name: 'Leads' })).toBeVisible()
    await expect(page.getByTestId('lead-row').first()).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-leads-list.png`, fullPage: true })

    await page.getByLabel('Search').fill(reference)
    const row = page.getByTestId('lead-row').filter({ hasText: reference })
    await expect(row).toBeVisible()
    await expect(row).toContainText('Quotation')
    await expect(row).toContainText('New')
    await expect(row).toContainText('playwright / e2e')
    await expect(row).toContainText('phase5-leads')
    await page.screenshot({ path: `${outputDir}/04-leads-filtered.png`, fullPage: true })

    await row.getByRole('link', { name: 'Work lead' }).click()
    await expect(page.getByRole('heading', { level: 1, name: reference })).toBeVisible()
    await expect(page.getByTestId('lead-activity-list')).toContainText('Lead captured from the quotation form.')
    await page.screenshot({ path: `${outputDir}/05-lead-detail.png`, fullPage: true })

    await page.locator('select[name="lead-status"]').selectOption('contacted')
    await page.getByRole('button', { name: 'Save status' }).click()
    await expect(page.locator('select[name="lead-status"]')).toHaveValue('contacted')

    const assigneeSelect = page.locator('select[name="lead-assignee"]')
    const assigneeName = (await assigneeSelect.locator('option').nth(1).innerText()).trim()
    await assigneeSelect.selectOption({ index: 1 })
    await page.getByLabel('Reason (optional)').fill('Assigned during Phase 5 browser journey')
    await page.getByRole('button', { name: 'Assign', exact: true }).click()
    await expect(page.getByText(`Assigned to ${assigneeName}`).first()).toBeVisible()

    await page.getByLabel('Note').fill(noteBody)
    await page.getByRole('button', { name: 'Add activity' }).click()
    await expect(page.getByTestId('lead-activity-list')).toContainText(noteBody)

    await page.locator('select[name="consent-channel"]').selectOption('sms')
    await page.locator('select[name="consent-status"]').selectOption('withdrawn')
    await page.getByRole('button', { name: 'Record consent' }).click()
    await expect(page.getByText('sms: withdrawn')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/06-lead-worked.png`, fullPage: true })

    const leadUrl = page.url()
    const leadId = leadUrl.split('/').pop() ?? ''
    const auth = { Authorization: `Bearer ${await token(page)}`, Accept: 'application/json' }
    const detail = await page.request.get(`${API}/platform/marketing/leads/${leadId}`, { headers: auth })
    expect(detail.status()).toBe(200)
    const body = await detail.json()
    expect(body.data.lead.status).toBe('contacted')
    expect(body.data.lead.assigned_to.name).toBe(assigneeName)
    expect(body.data.lead.activities.some((activity: { body: string | null }) => activity.body === noteBody)).toBe(true)
    expect(
      body.data.lead.consents.some(
        (consent: { channel: string; status: string }) => consent.channel === 'sms' && consent.status === 'withdrawn',
      ),
    ).toBe(true)
    expect(body.data.lead.utm_source).toBe('playwright')
    expect(body.data.lead.utm_campaign).toBe('phase5-leads')

    await page.goto('/platform/marketing/leads')
    await page.getByLabel('Search').fill(reference)
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('button', { name: 'Export CSV' }).click(),
    ])
    const exportPath = `${outputDir}/07-leads-export.csv`
    await download.saveAs(exportPath)
    const csv = fs.readFileSync(exportPath, 'utf8')
    expect(csv).toContain(reference)
    expect(csv).toContain(leadEmail)
    expect(csv).toContain('contacted')
    await page.screenshot({ path: `${outputDir}/08-leads-exported.png`, fullPage: true })
  })

  test('roles without export see masked contact details and no export control', async ({ page }) => {
    await login(page, editor)
    await page.goto('/platform/marketing/leads')
    await expect(page.getByRole('heading', { level: 1, name: 'Leads' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Export CSV' })).toHaveCount(0)
    await expect(page.getByText('Export requires the leads export permission.')).toBeVisible()

    await page.getByLabel('Search').fill('playwright.lead@phase5.test')
    const row = page.getByTestId('lead-row').filter({ hasText: 'Quotation' }).first()
    await expect(row).toBeVisible()
    await expect(row).toContainText(/p\*+@phase5\.test/)
    await expect(row).not.toContainText(leadEmail)
    await page.screenshot({ path: `${outputDir}/09-editor-masked-pii.png`, fullPage: true })

    await logout(page)
    await login(page, tenant)
    await page.goto('/platform/marketing/leads')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/10-tenant-denied.png`, fullPage: true })
  })
})
