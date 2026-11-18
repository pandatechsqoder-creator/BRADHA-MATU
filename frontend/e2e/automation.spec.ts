import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-13-automation/screens'
const runId = Date.now().toString().slice(-6)
const workflowName = `Phase 13 E2E ${runId}`

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

async function token(page: Page): Promise<string> {
  const value = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
  expect(value).toBeTruthy()
  return value as string
}

function quotePayload(tag: string, marketingConsent: boolean) {
  return {
    contact_name: `Automation Lead ${tag}`,
    business_name: `Automation Traders ${tag} ${runId}`,
    email: `automation.${tag}.${runId}@phase13.test`,
    phone: '0712 000 000',
    branches_count: 2,
    catalogue_size: 3500,
    message: `Phase 13 automation journey ${tag}`,
    consent_transactional: true,
    ...(marketingConsent ? { consent_marketing: true } : {}),
  }
}

async function submitPublicLead(page: Page, payload: ReturnType<typeof quotePayload>) {
  const response = await page.request.post(`${API}/public/forms/quote`, {
    data: payload,
    headers: { Accept: 'application/json' },
  })
  expect(response.status()).toBe(201)
  return response.json() as Promise<{ data: { reference: string; created: boolean } }>
}

async function openExecutions(page: Page) {
  await login(page, superAdmin)
  await page.getByRole('link', { name: 'Automation', exact: true }).click()
  await expect(page.getByTestId('automation-page')).toBeVisible()
  const row = page.locator('[data-testid^="automation-workflow-"]').filter({ hasText: workflowName })
  await row.getByRole('button', { name: 'Executions' }).click()
  await expect(page.getByTestId('automation-executions')).toBeVisible()
}

test.describe('marketing automation', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(300_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('a workflow can be created and activated from the automation workspace', async ({ page }) => {
    await login(page, superAdmin)
    await page.getByRole('link', { name: 'Automation', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Automation' })).toBeVisible()
    const switchState = page.getByTestId('automation-kill-switch-state')
    if ((await switchState.innerText()).includes('paused')) {
      await page.getByTestId('automation-kill-switch').click()
    }
    await expect(switchState).toContainText('Automation is running.')
    await expect(page.getByTestId('automation-provider-notice')).toContainText('No outbound')
    await page.screenshot({ path: `${outputDir}/01-automation-overview.png`, fullPage: true })

    await page.getByTestId('automation-new').click()
    await page.getByTestId('automation-name').fill(workflowName)
    await page.getByTestId('automation-trigger').selectOption('lead.created')
    await page.getByTestId('automation-lead-type-quote').check()

    const firstStep = page.getByTestId('automation-step-0')
    await firstStep.getByLabel('Notification title').fill('New quote request {reference}')
    await firstStep.getByLabel('Message body').fill('Follow up with {contact_name} at {business_name}.')

    const secondStep = page.getByTestId('automation-step-1')
    await secondStep.getByTestId('automation-step-1-type').selectOption('add_activity')
    await secondStep.getByLabel('Activity note').fill('Automation logged the quote request for follow-up.')

    await page.getByTestId('automation-add-step').click()
    const thirdStep = page.getByTestId('automation-step-2')
    await thirdStep.getByTestId('automation-step-2-type').selectOption('notify_external')
    await thirdStep.getByLabel('Subject').fill('Next step for {reference}')
    await thirdStep.getByLabel('Message body').fill('Hello {contact_name}, the team will be in touch soon.')

    await page.getByTestId('automation-submit').click()
    await expect(page.getByText(`Workflow “${workflowName}” saved as a draft.`)).toBeVisible()

    const row = page.locator('[data-testid^="automation-workflow-"]').filter({ hasText: workflowName })
    await expect(row).toBeVisible()
    await row.getByRole('button', { name: 'Activate' }).click()
    await expect(row.locator('[data-testid^="automation-status-"]')).toHaveText('active')
    await expect(page.getByText(`Workflow “${workflowName}” is now active.`)).toBeVisible()
    await page.screenshot({ path: `${outputDir}/02-workflow-active.png`, fullPage: true })
  })

  test('lead events run the workflow once and expose the outbound provider block', async ({ page }) => {
    await page.goto(`${PUBLIC}/request-quote`)
    const form = page.locator('form[data-form="quote"]')
    await form.getByLabel('Your name').fill(`Browser Automation ${runId}`)
    await form.getByLabel('Business name').fill(`Browser Automation Ltd ${runId}`)
    await form.getByLabel('Email').fill(`automation.browser.${runId}@phase13.test`)
    await form.getByLabel('Branches or stock locations (optional)').fill('3')
    await form.getByLabel('Message (optional)').fill(`Phase 13 browser journey ${runId}`)
    await form.getByLabel(/I agree that BRATHAMATU may contact me/).check()
    await form.getByLabel(/Send me occasional stock-taking/).check()
    await page.waitForTimeout(2400)
    await form.getByRole('button', { name: 'Send request' }).click()
    await expect(page.getByTestId('lead-success')).toContainText('Your reference is LD-')
    const browserReference = ((await page.getByTestId('lead-success').innerText()).match(/LD-[A-Z0-9]+/) ?? [])[0]
    expect(browserReference).toBeTruthy()

    const payload = quotePayload('idem', true)
    const first = await submitPublicLead(page, payload)
    const second = await submitPublicLead(page, payload)
    expect(first.data.created).toBe(true)
    expect(second.data.created).toBe(false)
    expect(second.data.reference).toBe(first.data.reference)

    await openExecutions(page)
    const runRows = page.locator('[data-testid^="automation-run-"]').filter({ hasText: first.data.reference })
    await expect(runRows).toHaveCount(1)
    await expect(runRows.first()).toContainText('blocked')
    await expect(runRows.first()).toContainText('No outbound email, SMS or WhatsApp provider has been approved')
    await expect(page.locator('[data-testid^="automation-run-"]').filter({ hasText: browserReference }).first()).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-executions-idempotent.png`, fullPage: true })
  })

  test('a lead without marketing consent is blocked at the message step', async ({ page }) => {
    const submission = await submitPublicLead(page, quotePayload('noconsent', false))

    await openExecutions(page)
    const run = page.locator('[data-testid^="automation-run-"]').filter({ hasText: submission.data.reference })
    await expect(run).toHaveCount(1)
    await expect(run).toContainText('has not granted email marketing consent')
    await page.screenshot({ path: `${outputDir}/04-consent-blocked-step.png`, fullPage: true })
  })

  test('the kill switch blocks runs without dropping events and can be resumed', async ({ page }) => {
    await login(page, superAdmin)
    await page.getByRole('link', { name: 'Automation', exact: true }).click()
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByTestId('automation-kill-switch').click()
    await expect(page.getByTestId('automation-kill-switch-state')).toContainText('Automation is paused.')

    const submission = await submitPublicLead(page, quotePayload('killed', true))

    await page.reload()
    await expect(page.getByTestId('automation-page')).toBeVisible()
    await expect(page.getByTestId('automation-kill-switch-state')).toContainText('Automation is paused.')
    const row = page.locator('[data-testid^="automation-workflow-"]').filter({ hasText: workflowName })
    await row.getByRole('button', { name: 'Executions' }).click()
    await expect(page.getByTestId('automation-executions')).toBeVisible()
    const run = page.locator('[data-testid^="automation-run-"]').filter({ hasText: submission.data.reference })
    await expect(run).toHaveCount(1)
    await expect(run).toContainText('paused by the kill switch')
    await expect(run).toContainText('blocked')
    await page.screenshot({ path: `${outputDir}/05-kill-switch-blocked-run.png`, fullPage: true })

    await page.getByTestId('automation-kill-switch').click()
    await expect(page.getByTestId('automation-kill-switch-state')).toContainText('Automation is running.')
  })

  test('editors and tenants cannot open the automation workspace', async ({ page }) => {
    await login(page, editor)
    await expect(page.getByRole('link', { name: 'Automation', exact: true })).toHaveCount(0)
    await page.goto('/platform/marketing/automation')
    await expect(page.getByText('You do not have permission to view this page.')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/06-editor-permission-denied.png`, fullPage: true })

    await page.evaluate(() => localStorage.clear())
    await login(page, tenant)
    await page.goto('/platform/marketing/automation')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/07-tenant-denied.png`, fullPage: true })
  })
})
