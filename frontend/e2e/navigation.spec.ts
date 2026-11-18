import { expect, test } from '@playwright/test'
import { ACCOUNTS, expectNoCrash, login, settle, trackRequests } from './helpers'

const tenantRoutes: Array<[string, string]> = [
  ['/business/locations', 'Branches & locations'],
  ['/business/catalogue', 'Catalogue'],
  ['/business/inventory', 'Inventory'],
  ['/business/stocktaking', 'Stock-taking jobs'],
  ['/business/team', 'Team members'],
  ['/business/schedules', 'Recurring stock-takes'],
  ['/business/reports', 'Reports & analytics'],
  ['/settings', 'Organisation settings'],
  ['/notifications', 'Notifications'],
  ['/profile', 'Your profile'],
  ['/support', 'Support centre'],
  ['/pos', 'POS Terminal'],
  ['/pos/back-office', 'POS back office'],
  ['/pos/shifts', 'POS shifts'],
  ['/pos/terminals', 'POS terminals'],
  ['/pos/stocktake', 'Commission a stock-take from POS'],
]

const platformRoutes: Array<[string, string]> = [
  ['/platform', 'Operations overview'],
  ['/platform/tenants', 'Tenants'],
  ['/platform/stocktaking-jobs', 'Stock-taking jobs'],
  ['/platform/operations', 'Field operations'],
  ['/platform/sops', 'SOP templates'],
  ['/platform/catalogue-pricing', 'Catalogue pricing'],
  ['/platform/stocktake-policy', 'Stocktake pricing & deposit policy'],
  ['/platform/finance', 'Finance'],
  ['/platform/reports', 'Platform reports & analytics'],
  ['/platform/support', 'Support queue'],
  ['/platform/workforce', 'Workforce'],
  ['/platform/audit', 'Audit trail'],
]

const workforceRoutes: Array<[string, string]> = [
  ['/workforce/assignments', 'Assignments'],
  ['/workforce/profile', 'My workforce profile'],
  ['/workforce/earnings', 'Earnings'],
]

test.describe('navigation smoke', () => {
  test.setTimeout(600_000)

  test('tenant navigation renders every workspace screen without crashing', async ({ page }) => {
    await trackRequests(page)
    await login(page, ACCOUNTS.owner)
    for (const [path, heading] of tenantRoutes) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible()
      await expectNoCrash(page)
      await settle(page)
    }
  })

  test('platform navigation renders every administration screen without crashing', async ({
    page,
  }) => {
    await trackRequests(page)
    await login(page, ACCOUNTS.platform)
    for (const [path, heading] of platformRoutes) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible()
      await expectNoCrash(page)
      await settle(page)
    }
  })

  test('workforce navigation renders every worker screen without crashing', async ({ page }) => {
    await trackRequests(page)
    await login(page, ACCOUNTS.worker)
    for (const [path, heading] of workforceRoutes) {
      await page.goto(path)
      await expect(page.getByRole('heading', { level: 1, name: heading })).toBeVisible()
      await expectNoCrash(page)
      await settle(page)
    }
  })

  test('sidebar links navigate to the target screens', async ({ page }) => {
    await trackRequests(page)
    await login(page, ACCOUNTS.owner)
    await page.getByRole('link', { name: 'Catalogue', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Catalogue' })).toBeVisible()
    await settle(page)
    await page.getByRole('link', { name: 'Inventory', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Inventory' })).toBeVisible()
    await settle(page)
    await page.getByRole('link', { name: 'POS Terminal', exact: true }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'POS Terminal' })).toBeVisible()
    await settle(page)
  })
})
