import { expect, test } from '@playwright/test'
import { ACCOUNTS, login } from './helpers'

test('tenant can follow setup into a stock-taking request and sign its request-specific declaration', async ({
  page,
}) => {
  await login(page, ACCOUNTS.owner)

  await page.goto('/business/onboarding')
  await expect(page.getByRole('heading', { level: 1, name: 'Business setup' })).toBeVisible()
  await expect(page.getByText('First stock-taking request')).toBeVisible()

  await page.goto('/business/stocktaking/new')
  await expect(page.getByRole('heading', { level: 1, name: 'New stock-taking request' })).toBeVisible()
  await page.getByLabel('Title').fill(`E2E stock take ${Date.now()}`)

  await page.getByRole('button', { name: 'Continue', exact: true }).click()
  await page.getByRole('button', { name: 'Continue', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Request-specific declaration' })).toBeVisible()
  const declaration = page.getByLabel(
    'I am authorized to declare this specific stock-taking request accurate on behalf of this business.',
  )
  await expect(declaration).toBeVisible()
  await declaration.check()
  await page.getByRole('button', { name: 'Submit request' }).click()

  await expect(page).toHaveURL(/\/business\/stocktaking\/[A-Za-z0-9]+$/)
  await expect(page.getByRole('heading', { name: 'Request-specific declaration' })).toBeVisible()
  await expect(page.getByText('Revision', { exact: true })).toBeVisible()
})
