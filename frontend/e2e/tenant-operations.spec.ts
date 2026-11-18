import { expect, test } from '@playwright/test'
import { ACCOUNTS, login } from './helpers'

test.describe('tenant operations', () => {
  test('reports can be generated and exported as CSV', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/business/reports')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Reports & analytics' }),
    ).toBeVisible()

    await page.getByRole('button', { name: 'Generate' }).click()
    await expect(page.getByText(/generated with \d+ rows\./)).toBeVisible({ timeout: 20_000 })

    const downloadPromise = page.waitForEvent('download')
    await page.getByRole('button', { name: 'Export CSV' }).click()
    const download = await downloadPromise
    expect(download.suggestedFilename()).toMatch(/\.csv$/)
    await expect(page.getByText('CSV export downloaded and audit-logged.')).toBeVisible()
  })

  test('support ticket can be opened, selected and replied to', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/support')
    await expect(page.getByRole('heading', { level: 1, name: 'Support centre' })).toBeVisible()

    const subject = `E2E acceptance ${Date.now()}`
    await page.getByLabel('Subject').fill(subject)
    await page.getByLabel('Category').selectOption('pos')
    await page.getByLabel('Priority').selectOption('normal')
    await page.getByLabel('Description').fill(
      'Playwright acceptance run: verifying the ticket lifecycle end to end.',
    )
    await page.getByRole('button', { name: 'Create ticket' }).click()
    await expect(page.getByText(/Ticket [A-Z0-9]{8} created/)).toBeVisible({ timeout: 20_000 })

    await page.getByTestId('support-ticket-row').filter({ hasText: subject }).first().click()
    await expect(page.getByRole('heading', { name: subject })).toBeVisible()

    await page.getByLabel('Reply').fill('Additional detail from the acceptance suite.')
    await page.getByRole('button', { name: 'Send reply' }).click()
    await expect(page.getByText('Reply sent.')).toBeVisible({ timeout: 20_000 })
    await expect(page.getByText('Additional detail from the acceptance suite.')).toBeVisible()
  })

  test('notification centre loads with preferences', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/notifications')
    await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Notification centre' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Preferences' })).toBeVisible()
  })
})
