import { expect, type Page } from '@playwright/test'

export interface Account {
  email: string
  password: string
}

export const ACCOUNTS: Record<'owner' | 'platform' | 'worker', Account> = {
  owner: { email: 'owner@demosupermarket.test', password: 'Password1!' },
  platform: { email: 'superadmin@brathamatu.test', password: 'Password1!' },
  worker: { email: 'worker@brathamatu.test', password: 'Password1!' },
}

export async function login(page: Page, account: Account): Promise<void> {
  await page.goto('/login')
  await page.getByLabel('Email').fill(account.email)
  await page.getByLabel('Password').fill(account.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL('**/app')
  await expect(page.getByRole('heading', { level: 1, name: /Welcome, / })).toBeVisible()
}

export async function expectNoCrash(page: Page): Promise<void> {
  await expect(page.getByText('Something went wrong.')).toHaveCount(0)
}

export async function trackRequests(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const scope = window as unknown as { __inflight?: number }
    scope.__inflight = 0
    const original = window.fetch.bind(window)
    window.fetch = (...args: Parameters<typeof fetch>) => {
      scope.__inflight = (scope.__inflight ?? 0) + 1
      return original(...args).finally(() => {
        scope.__inflight = Math.max(0, (scope.__inflight ?? 1) - 1)
      })
    }
  })
}

export async function settle(page: Page): Promise<void> {
  await page.waitForTimeout(400)
  await page.waitForFunction(() => (window as unknown as { __inflight?: number }).__inflight === 0, undefined, {
    timeout: 120_000,
  })
}
