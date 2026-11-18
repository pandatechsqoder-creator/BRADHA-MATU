import fs from 'node:fs'
import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-14-hardening/screens'
const a11yDir = '../docs/evidence/phase-14-hardening/a11y'
const metricsFile = '../docs/evidence/phase-14-hardening/performance.json'

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }

function describeViolations(
  violations: Array<{ id: string; impact?: string | null; help: string; nodes: unknown[] }>,
): string {
  return violations
    .map((violation) => `${violation.id} (${violation.impact}): ${violation.help} — ${violation.nodes.length} node(s)`)
    .join('\n')
}

async function audit(page: Page, name: string) {
  const results = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .analyze()

  fs.writeFileSync(`${a11yDir}/${name}.json`, JSON.stringify({
    url: page.url(),
    violations: results.violations,
    passes: results.passes.length,
    incomplete: results.incomplete.length,
  }, null, 2))

  return results.violations
}

async function navigationMetrics(page: Page) {
  return page.evaluate(() => {
    const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming
    const resources = performance.getEntriesByType('resource') as PerformanceResourceTiming[]

    return {
      ttfb_ms: Math.round(navigation.responseStart),
      dom_content_loaded_ms: Math.round(navigation.domContentLoadedEventEnd),
      load_ms: Math.round(navigation.loadEventEnd),
      resource_requests: resources.length,
      resource_transfer_bytes: resources.reduce((total, entry) => total + (entry.transferSize ?? 0), 0),
    }
  })
}

test.describe('Phase 14 hardening', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(300_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
    fs.mkdirSync(a11yDir, { recursive: true })
  })

  test('public home and quote form pass automated WCAG A/AA checks', async ({ page }) => {
    await page.goto(`${PUBLIC}/`)
    const homeViolations = await audit(page, 'public-home')
    await page.screenshot({ path: `${outputDir}/01-public-home-accessible.png`, fullPage: true })
    expect(describeViolations(homeViolations)).toBe('')

    const banner = page.getByRole('dialog', { name: 'Analytics consent' })
    await banner.getByRole('button', { name: 'Necessary only' }).click()

    await page.goto(`${PUBLIC}/request-quote`)
    const formViolations = await audit(page, 'quote-form')
    await page.screenshot({ path: `${outputDir}/02-quote-form-accessible.png`, fullPage: true })
    expect(describeViolations(formViolations)).toBe('')
  })

  test('staff login and automation workspace pass automated WCAG A/AA checks', async ({ page }) => {
    await page.goto('/login')
    const loginViolations = await audit(page, 'staff-login')
    await page.screenshot({ path: `${outputDir}/03-staff-login-accessible.png`, fullPage: true })
    expect(describeViolations(loginViolations)).toBe('')

    await login(page, superAdmin)
    await page.goto('/platform/marketing/automation')
    await expect(page.getByTestId('automation-page')).toBeVisible()
    const workspaceViolations = await audit(page, 'automation-workspace')
    await page.screenshot({ path: `${outputDir}/04-automation-workspace-accessible.png`, fullPage: true })
    expect(describeViolations(workspaceViolations)).toBe('')
  })

  test('public entry pages stay inside the performance budget', async ({ page }) => {
    const metrics: Record<string, unknown> = {}

    for (const [name, path] of [['home', '/'], ['quote', '/request-quote']] as const) {
      const response = await page.goto(`${PUBLIC}${path}`)
      const html = await response!.text()
      const navigation = await navigationMetrics(page)
      const record = {
        ...navigation,
        html_bytes: Buffer.byteLength(html),
      }
      metrics[name] = record

      expect(record.ttfb_ms, `${name} TTFB`).toBeLessThan(1500)
      expect(record.dom_content_loaded_ms, `${name} DOMContentLoaded`).toBeLessThan(3000)
      expect(record.html_bytes, `${name} HTML bytes`).toBeLessThan(250_000)
      expect(
        record.html_bytes + record.resource_transfer_bytes,
        `${name} total transfer`,
      ).toBeLessThan(1_500_000)
    }

    fs.mkdirSync('../docs/evidence/phase-14-hardening', { recursive: true })
    fs.writeFileSync(metricsFile, JSON.stringify(metrics, null, 2))
  })

  test('private surfaces and non-indexable environments expose no indexable routes', async ({ page }) => {
    const robots = await page.request.get(`${PUBLIC}/robots.txt`)
    expect(robots.status()).toBe(200)
    const body = await robots.text()
    expect(body).toContain('User-agent: *')
    expect(body).toMatch(/Disallow: \//)

    await page.goto('/login')
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow')
    await page.screenshot({ path: `${outputDir}/05-private-spa-noindex.png`, fullPage: true })

    const appHtml = await page.request.get('http://127.0.0.1:5173/app')
    expect(await appHtml.text()).toContain('noindex, nofollow')
  })
})
