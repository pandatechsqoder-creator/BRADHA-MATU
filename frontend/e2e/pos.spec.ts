import { expect, test } from '@playwright/test'
import { ACCOUNTS, login } from './helpers'

test.describe('POS sale acceptance', () => {
  test('cashier opens a shift, adds a catalogue product and completes a cash sale', async ({
    page,
  }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/pos')
    await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()

    const createTerminal = page.getByRole('button', { name: 'Create terminal' })
    if ((await createTerminal.count()) > 0) {
      await page.getByLabel('Terminal name').fill('E2E Till')
      await createTerminal.click()
      await expect(page.getByRole('button', { name: 'Create terminal' })).toHaveCount(0)
    }

    const openShift = page.getByRole('button', { name: 'Open shift' })
    if ((await openShift.count()) > 0) {
      await page.getByLabel('Opening float').fill('500')
      await openShift.click()
    }
    await expect(page.getByText('Shift open')).toBeVisible()

    await page.getByLabel('Catalogue search').fill('Sugar')
    await page.getByRole('button', { name: 'Search', exact: true }).click()
    const product = page.locator('li', { hasText: 'SUG-1KG' }).first()
    await expect(product).toBeVisible({ timeout: 20_000 })
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
    await expect(page.getByText('Empty cart — scan a barcode or add a catalogue product.')).toBeVisible()
  })

  test('barcode lookup rejects unknown codes with a clear message', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await page.goto('/pos')
    await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()

    const openShift = page.getByRole('button', { name: 'Open shift' })
    if ((await openShift.count()) > 0) {
      await openShift.click()
      await expect(page.getByText('Shift open')).toBeVisible()
    }

    await page.getByLabel('Barcode').fill('NO-SUCH-BARCODE-E2E')
    await page.getByRole('button', { name: 'Look up barcode' }).click()
    await expect(page.getByText(/No active product matches/)).toBeVisible({ timeout: 20_000 })
  })
})
