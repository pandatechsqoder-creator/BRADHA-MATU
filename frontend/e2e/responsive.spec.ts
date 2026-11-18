import { expect, test } from '@playwright/test'
import { ACCOUNTS, expectNoCrash, login } from './helpers'

test.describe('responsive layouts', () => {
  test.describe('mobile (390x844)', () => {
    test.use({ viewport: { width: 390, height: 844 } })

    test('tenant owner can navigate the core workspace on a phone', async ({ page }) => {
      await login(page, ACCOUNTS.owner)
      await expect(page.getByRole('navigation', { name: 'Main' })).toBeVisible()

      await page.getByRole('link', { name: 'Notifications', exact: true }).click()
      await expect(page.getByRole('heading', { level: 1, name: 'Notifications' })).toBeVisible()

      await page.goto('/business/catalogue')
      await expect(page.getByRole('heading', { level: 1, name: 'Catalogue' })).toBeVisible()
      await expectNoCrash(page)

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
      )
      expect(overflow).toBeLessThanOrEqual(1)
    })

    test('POS terminal controls stack on a phone', async ({ page }) => {
      await login(page, ACCOUNTS.owner)
      await page.goto('/pos')
      await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()
      await expect(page.getByLabel('Catalogue search')).toBeVisible()
      await expect(page.getByRole('button', { name: 'Complete sale' })).toBeVisible()
    })
  })

  test.describe('tablet (768x1024)', () => {
    test.use({ viewport: { width: 768, height: 1024 } })

    test('reports and support remain usable on a tablet', async ({ page }) => {
      await login(page, ACCOUNTS.owner)
      await page.goto('/business/reports')
      await expect(page.getByRole('heading', { level: 1, name: 'Reports & analytics' })).toBeVisible()
      await expect(page.getByRole('button', { name: 'Generate' })).toBeVisible()

      await page.goto('/support')
      await expect(page.getByRole('heading', { level: 1, name: 'Support centre' })).toBeVisible()
      await expect(page.getByRole('button', { name: 'Create ticket' })).toBeVisible()
      await expectNoCrash(page)
    })
  })
})
