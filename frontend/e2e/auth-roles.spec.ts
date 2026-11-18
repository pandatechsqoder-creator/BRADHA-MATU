import { expect, test } from '@playwright/test'
import { ACCOUNTS, expectNoCrash, login } from './helpers'

test.describe('role-aware access', () => {
  test('tenant owner reaches the tenant workspace and is denied platform pages', async ({ page }) => {
    await login(page, ACCOUNTS.owner)
    await expect(page.getByTestId('current-user')).toBeVisible()

    await page.goto('/platform')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()

    await page.goto('/business/catalogue')
    await expect(page.getByRole('heading', { level: 1, name: 'Catalogue' })).toBeVisible()
    await expect(page.getByText('Sugar 1kg').first()).toBeVisible()
    await expectNoCrash(page)
  })

  test('platform superadmin reaches platform operations, reports and support', async ({ page }) => {
    await login(page, ACCOUNTS.platform)

    await page.goto('/platform')
    await expect(page.getByRole('heading', { level: 1, name: 'Operations overview' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Tenants' })).toBeVisible()

    await page.goto('/platform/reports')
    await expect(
      page.getByRole('heading', { level: 1, name: 'Platform reports & analytics' }),
    ).toBeVisible()

    await page.goto('/platform/support')
    await expect(page.getByRole('heading', { level: 1, name: 'Support queue' })).toBeVisible()
    await expectNoCrash(page)
  })

  test('workforce worker reaches assignments, profile and earnings', async ({ page }) => {
    await login(page, ACCOUNTS.worker)

    await page.goto('/workforce/assignments')
    await expect(page.getByRole('heading', { level: 1, name: 'Assignments' })).toBeVisible()

    await page.goto('/workforce/profile')
    await expect(
      page.getByRole('heading', { level: 1, name: 'My workforce profile' }),
    ).toBeVisible()

    await page.goto('/workforce/earnings')
    await expect(page.getByRole('heading', { level: 1, name: 'Earnings' })).toBeVisible()
    await expectNoCrash(page)
  })
})
