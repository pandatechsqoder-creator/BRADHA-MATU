import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-7-structured-data/screens'
const runId = Date.now().toString().slice(-6)

const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const publisher = { email: 'publisher@brathamatu.test', password: 'Password1!' }

async function logout(page: Page): Promise<void> {
  await page.evaluate(() => localStorage.clear())
}

async function createPage(page: Page, title: string, slug: string): Promise<void> {
  await page.goto('/platform/marketing/pages')
  await page.getByRole('button', { name: 'New page' }).click()
  await page.locator('input[name="page-title"]').fill(title)
  await page.locator('input[name="page-slug"]').fill(slug)
  await page.getByRole('button', { name: 'Create draft' }).click()
  await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
  await expect(page.getByTestId('structured-data-card')).toBeVisible()
}

async function addSection(page: Page, index: number, type: string, content: Record<string, unknown>): Promise<void> {
  await page.getByLabel('Section type').selectOption(type)
  await page.getByRole('button', { name: 'Add section' }).click()
  const textarea = page.getByTestId('section-list').getByLabel('Content (JSON)').nth(index)
  await textarea.fill(JSON.stringify(content, null, 2))
}

function readGraph(html: string): { '@context': string; '@graph': Record<string, unknown>[] } {
  const match = html.match(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/)
  expect(match, 'page must include a JSON-LD script').not.toBeNull()
  return JSON.parse(match![1])
}

test.describe('structured data journey', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(480_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('markup settings, validation gating and published JSON-LD', async ({ page, request }) => {
    const title = `Structured data demo ${runId}`
    const slug = `phase7-structured-${runId}`

    await login(page, editor)
    await createPage(page, title, slug)

    // Compose the visible content, then describe the page as a stock-taking landing page.
    await addSection(page, 0, 'hero', {
      headline: title,
      subheadline: 'Structured, verified stock counts.',
      bullets: ['Trained counting teams'],
    })
    await addSection(page, 1, 'service_grid', {
      heading: 'Stocktaking services',
      intro: 'Counts performed by trained teams.',
      items: [{ title: 'Full stocktake', body: 'A complete count of every location.', url: '/request-quote' }],
    })
    await addSection(page, 2, 'faq', {
      heading: 'Questions',
      items: [{ question: 'Who performs the count?', answer: 'Trained BRATHAMATU stocktaking teams.' }],
    })
    await page.getByRole('button', { name: 'Save sections' }).click()
    await expect(page.getByText('Sections saved.')).toBeVisible()

    await page.locator('select[name="page-type"]').selectOption('landing')
    await page.getByLabel('Meta description').fill('Independent stocktaking with counted results and a reconciled report.')
    await page.getByRole('button', { name: 'Save details' }).click()
    await expect(page.getByText('Page details and SEO saved.')).toBeVisible()

    const card = page.getByTestId('structured-data-card')
    await expect(page.getByTestId('structured-data-status')).toHaveText('valid')
    await expect(page.getByTestId('structured-data-types')).toContainText('WebPage')
    await expect(page.getByTestId('structured-data-types')).toContainText('Service')
    await expect(page.getByTestId('structured-data-types')).toContainText('FAQPage')
    await expect(page.getByTestId('structured-data-json')).toContainText('Service')
    await expect(page.getByTestId('structured-data-json')).toContainText('Who performs the count?')
    await card.scrollIntoViewIfNeeded()
    await card.screenshot({ path: `${outputDir}/01-valid-markup.png` })

    // Request Article markup: it must become a blocking validation issue.
    await page.getByTestId('sd-type-article').check()
    await page.getByTestId('structured-data-save').click()
    await expect(page.getByText('Structured data settings saved.')).toBeVisible()
    await page.getByTestId('structured-data-validate').click()
    await expect(page.getByText('Validation invalid. Publish requires a valid validation run.')).toBeVisible()
    await expect(page.getByTestId('structured-data-status')).toHaveText('invalid')
    await expect(page.getByTestId('structured-data-issues')).toContainText('Article/BlogPosting')
    await expect(page.getByTestId('structured-data-runs').getByText('invalid', { exact: true }).first()).toBeVisible()
    await card.screenshot({ path: `${outputDir}/02-invalid-article-markup.png` })

    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')

    // Publisher cannot publish while validation is invalid.
    await logout(page)
    await login(page, publisher)
    await page.goto('/platform/marketing/pages')
    await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
    await page.getByRole('button', { name: 'Approve' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByText(/Structured data must be valid before publishing/)).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-publish-blocked.png`, fullPage: true })

    // Removing the Article request clears the issue and publishing succeeds.
    const publisherCard = page.getByTestId('structured-data-card')
    await expect(page.getByTestId('structured-data-status')).toHaveText('invalid')
    await page.getByTestId('sd-type-article').uncheck()
    await page.getByTestId('structured-data-save').click()
    await expect(page.getByText('Structured data settings saved.')).toBeVisible()
    await page.getByTestId('structured-data-validate').click()
    await expect(page.getByText('Validation valid. Publish requires a valid validation run.')).toBeVisible()
    await expect(page.getByTestId('structured-data-status')).toHaveText('valid')
    await expect(page.getByTestId('structured-data-runs').getByText('valid', { exact: true }).first()).toBeVisible()
    await publisherCard.screenshot({ path: `${outputDir}/04-validation-run-valid.png` })

    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('published')

    // The public page renders the same facts as JSON-LD, without invented commercial claims.
    const publicResponse = await request.get(`${PUBLIC}/${slug}`)
    expect(publicResponse.status()).toBe(200)
    const html = await publicResponse.text()
    const graph = readGraph(html)
    const types = graph['@graph'].map((node) => node['@type'])

    expect(types).toEqual(expect.arrayContaining(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'Service', 'FAQPage']))
    const webpage = graph['@graph'].find((node) => node['@type'] === 'WebPage')
    expect(webpage?.name).toBe(title)
    expect(webpage?.description).toBe('Independent stocktaking with counted results and a reconciled report.')
    const faq = graph['@graph'].find((node) => node['@type'] === 'FAQPage') as { mainEntity: { name: string }[] }
    expect(faq.mainEntity.map((question) => question.name)).toContain('Who performs the count?')

    const serialised = JSON.stringify(graph)
    for (const forbidden of ['aggregateRating', 'ratingValue', 'offers', '"price"']) {
      expect(serialised).not.toContain(forbidden)
    }

    await page.goto(`${PUBLIC}/${slug}`)
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
    await expect(page.getByText('Who performs the count?')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/05-public-page-markup.png`, fullPage: true })

    // Leave the environment clean.
    await page.goto('/platform/marketing/pages')
    await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
    await page.getByRole('button', { name: 'Unpublish' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('draft')
  })

  test('seeded public pages expose valid structured data', async ({ request }) => {
    const home = await request.get(`${PUBLIC}/`)
    expect(home.status()).toBe(200)
    const homeGraph = readGraph(await home.text())
    const homeTypes = homeGraph['@graph'].map((node) => node['@type'])
    expect(homeTypes).toEqual(expect.arrayContaining(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList']))

    const service = await request.get(`${PUBLIC}/stock-taking/full`)
    expect(service.status()).toBe(200)
    const serviceGraph = readGraph(await service.text())
    const serviceTypes = serviceGraph['@graph'].map((node) => node['@type'])
    expect(serviceTypes).toEqual(expect.arrayContaining(['Service', 'FAQPage', 'WebPage', 'BreadcrumbList']))
    expect(serviceTypes).not.toContain('Article')
    expect(serviceTypes).not.toContain('LocalBusiness')
  })
})
