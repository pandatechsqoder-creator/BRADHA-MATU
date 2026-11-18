import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { ACCOUNTS, login, settle, trackRequests } from './helpers'

const outputDir = '../docs/evidence/phase-21/screens'

test.describe('acceptance evidence', () => {
  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('capture tenant, platform and workforce screens', async ({ page }) => {
    await trackRequests(page)
    await login(page, ACCOUNTS.owner)
    await settle(page)
    await page.screenshot({ path: `${outputDir}/01-tenant-dashboard.png`, fullPage: true })

    await page.goto('/business/reports')
    await expect(page.getByRole('heading', { level: 1, name: 'Reports & analytics' })).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/02-tenant-reports.png`, fullPage: true })

    await page.goto('/pos')
    await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/03-pos-terminal.png`, fullPage: true })

    await page.goto('/support')
    await expect(page.getByRole('heading', { level: 1, name: 'Support centre' })).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/04-tenant-support.png`, fullPage: true })

    await page.evaluate(() => localStorage.clear())

    await login(page, ACCOUNTS.platform)
    await page.goto('/platform')
    await expect(page.getByRole('heading', { level: 1, name: 'Operations overview' })).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/05-platform-overview.png`, fullPage: true })

    await page.goto('/platform/reports')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Platform reports & analytics' }),
    ).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/06-platform-reports.png`, fullPage: true })

    await page.evaluate(() => localStorage.clear())
    await login(page, ACCOUNTS.worker)
    await page.goto('/workforce/assignments')
    await expect(page.getByRole('heading', { level: 1, name: 'Assignments' })).toBeVisible()
    await settle(page)
    await page.screenshot({ path: `${outputDir}/07-workforce-assignments.png`, fullPage: true })
  })

  test('capture a completed POS sale receipt', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/pos')
    await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()

    const openShift = page.getByRole('button', { name: 'Open shift' })
    if ((await openShift.count()) > 0) {
      await page.getByLabel('Opening float').fill('500')
      await openShift.click()
      await expect(page.getByText('Shift open')).toBeVisible()
    }

    await page.getByLabel('Catalogue search').fill('Sugar')
    await page.getByRole('button', { name: 'Search', exact: true }).click()
    const product = page.locator('li', { hasText: 'SUG-1KG' }).first()
    await expect(product).toBeVisible({ timeout: 60_000 })
    await product.getByRole('button', { name: 'Add' }).click()

    const cartRow = page.locator('tr', { hasText: 'Sugar 1kg' }).first()
    await expect(cartRow).toBeVisible()
    const priceInput = cartRow.locator('input[type="number"]').nth(1)
    if ((await priceInput.inputValue()) === '0') {
      await priceInput.fill('140')
    }

    await expect(page.getByText('Searching…')).toBeHidden({ timeout: 60_000 })
    await page.getByLabel('Cash tendered').fill('1000')
    await page.getByRole('button', { name: 'Complete sale' }).click()
    await expect(page.getByText(/Sale \S+ completed — receipt/)).toBeVisible({ timeout: 90_000 })
    await page.screenshot({ path: `${outputDir}/08-pos-sale-receipt.png`, fullPage: true })
  })
})
