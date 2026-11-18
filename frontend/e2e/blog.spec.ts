import fs from 'node:fs'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-8-blog-resources/screens'
const runId = Date.now().toString().slice(-6)

const superAdmin = { email: 'superadmin@brathamatu.test', password: 'Password1!' }
const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const publisher = { email: 'publisher@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

const slug = `phase8-browser-${runId}`
const title = `Phase 8 browser article ${runId}`

async function logout(page: Page): Promise<void> {
  await page.evaluate(() => localStorage.clear())
}

async function openPost(page: Page, postTitle: string): Promise<void> {
  await page.goto('/platform/marketing/blog')
  await page.getByTestId('blog-posts-table').waitFor()
  await page.getByTestId(`blog-post-row-${slug}`).getByRole('button', { name: 'Edit' }).click()
  await expect(page.getByTestId('blog-post-title')).toHaveValue(postTitle)
}

test.describe('blog and resources operations journey', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(480_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('editor drafts an article and publisher publishes it', async ({ page }) => {
    await login(page, editor)
    await page.goto('/platform/marketing/blog')
    await expect(page.getByRole('heading', { level: 1, name: 'Blog & resources' })).toBeVisible()
    await expect(page.getByTestId('blog-posts-table')).toBeVisible()
    await expect(page.getByTestId('blog-post-row-preparing-for-a-stock-count')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-blog-workspace.png`, fullPage: true })

    await page.getByTestId('blog-new-post').click()
    await page.getByTestId('blog-post-title').fill(title)
    await page.getByTestId('blog-post-slug').fill(slug)
    await page.getByTestId('blog-post-excerpt').fill('Evidence article covering the Phase 8 blog workflow.')
    await page.getByTestId('blog-post-body').fill(
      [
        '## Why count accuracy matters',
        '',
        'A stock count is only useful when the numbers are trusted by the people acting on them.',
        '',
        '- Count in the same unit you sell and buy',
        '- Investigate every variance above your tolerance',
        '- Share the results with the store team',
        '',
        'Contact the team when you need a second pair of eyes.',
      ].join('\n'),
    )
    await page.getByLabel('Primary category').selectOption({ label: 'Inventory accuracy' })
    await page.getByTestId('blog-post-tags').fill('Stock Count, Evidence')
    await page.getByTestId('blog-post-seo-description').fill('Phase 8 evidence article about stock count accuracy.')
    await page.getByTestId('blog-save-post').click()
    await expect(page.getByText('Article saved as a draft.')).toBeVisible()
    await expect(page.getByTestId('blog-post-status')).toHaveText('draft')
    await page.screenshot({ path: `${outputDir}/02-article-draft.png`, fullPage: true })

    await page.getByTestId('blog-action-submit-review').click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('review')
    await page.screenshot({ path: `${outputDir}/03-article-in-review.png`, fullPage: true })

    await logout(page)
    await login(page, publisher)
    await openPost(page, title)
    await page.getByTestId('blog-action-approve').click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('approved')
    await page.getByTestId('blog-action-publish').click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('published')
    await expect(page.getByTestId('blog-post-link')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/04-article-published.png`, fullPage: true })

    const article = await page.request.get(`${PUBLIC}/resources/${slug}`)
    expect(article.status()).toBe(200)
    const html = await article.text()
    expect(html).toContain(title)
    expect(html).toContain('"@type":"Article"')
    expect(html).toContain('Why count accuracy matters')
    expect(html).toContain('Related articles')

    await page.goto(`${PUBLIC}/resources/${slug}`)
    await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
    await expect(page.getByTestId('article-page')).toBeVisible()
    await expect(page.getByText('Related articles')).toBeVisible()
    const jsonLd = await page.locator('script[type="application/ld+json"]').allTextContents()
    expect(jsonLd.join('\n')).toContain('"@type":"Article"')
    await page.screenshot({ path: `${outputDir}/05-public-article.png`, fullPage: true })

    await page.goto(`${PUBLIC}/resources`)
    const card = page.getByTestId('article-card').filter({ hasText: title })
    await expect(card).toBeVisible()
    await page.screenshot({ path: `${outputDir}/06-resources-index.png`, fullPage: true })

    const tagPage = await page.request.get(`${PUBLIC}/resources/tags/stock-count`)
    expect(tagPage.status()).toBe(200)
    const tagHtml = await tagPage.text()
    expect(tagHtml).toContain('noindex')
    expect(tagHtml).toContain(title)
    await page.goto(`${PUBLIC}/resources/tags/stock-count`)
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)
    await page.screenshot({ path: `${outputDir}/07-tag-noindex.png`, fullPage: true })

    const download = await page.request.get(`${PUBLIC}/resources/downloads/stock-count-preparation-checklist`)
    expect(download.status()).toBe(200)
    expect(await download.text()).toContain('consent')

    await page.goto(`${PUBLIC}/resources/downloads/stock-count-preparation-checklist`)
    await page.screenshot({ path: `${outputDir}/08-download-consent.png`, fullPage: true })
    await page.locator('input[name="consent"]').check()
    await page.getByRole('button', { name: 'Agree and download' }).click()
    await page.waitForURL('**/media/**')

    const rss = await page.request.get(`${PUBLIC}/rss.xml`)
    expect(rss.status()).toBe(200)
    expect(rss.headers()['content-type']).toContain('application/rss+xml')
    expect(await rss.text()).toContain(title)

    const sitemap = await page.request.get(`${PUBLIC}/sitemap.xml`)
    expect(sitemap.status()).toBe(200)
    const sitemapXml = await sitemap.text()
    expect(sitemapXml).toContain(`/resources/${slug}`)
    expect(sitemapXml).toContain('/resources/downloads/stock-count-preparation-checklist')
    expect(sitemapXml).toContain('/resources/categories/inventory-accuracy')

    const publicApi = await page.request.get(`${API}/public/articles/${slug}`)
    expect(publicApi.status()).toBe(200)
    const apiJson = await publicApi.json()
    expect(apiJson.data.article.title).toBe(title)
    expect(JSON.stringify(apiJson.data.structured_data)).toContain('"Article"')
    expect(apiJson.data.related.length).toBeGreaterThan(0)

    await page.goto('/platform/marketing/blog')
    await page.getByTestId('blog-posts-table').waitFor()
    await page.getByRole('row').filter({ hasText: title }).getByRole('button', { name: 'Edit' }).click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('published')
    await page.getByTestId('blog-action-unpublish').click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('draft')
    await page.getByRole('button', { name: 'Archive' }).click()
    await expect(page.getByTestId('blog-post-status')).toHaveText('archived')
    await page.screenshot({ path: `${outputDir}/09-article-unpublished.png`, fullPage: true })

    await page.getByTestId('blog-tab-taxonomy').click()
    await expect(page.getByRole('heading', { name: 'Categories' })).toBeVisible()
    await page.getByTestId('tag-name').fill(`Phase 8 evidence ${runId}`)
    await page.getByTestId('tag-create').click()
    await expect(page.getByText('Tag created.')).toBeVisible()
    await expect(page.getByText(`phase-8-evidence-${runId}`)).toBeVisible()
    await page.screenshot({ path: `${outputDir}/10-taxonomy.png`, fullPage: true })

    await page.getByTestId('blog-tab-editorial').click()
    await expect(page.getByRole('heading', { name: 'Editorial calendar' })).toBeVisible()
    await page.getByTestId('editorial-title').fill(`Phase 8 follow-up ${runId}`)
    await page.getByTestId('editorial-create').click()
    await expect(page.getByText('Editorial item created.')).toBeVisible()
    await expect(page.getByText(`Phase 8 follow-up ${runId}`)).toBeVisible()
    await page.screenshot({ path: `${outputDir}/11-editorial-calendar.png`, fullPage: true })

    await page.getByTestId('blog-tab-downloads').click()
    await expect(page.getByRole('heading', { name: 'Downloads' })).toBeVisible()
    await expect(page.getByText('stock-count-preparation-checklist')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/12-downloads.png`, fullPage: true })

    const unreachable = await page.request.get(`${PUBLIC}/resources/${slug}`)
    expect(unreachable.status()).toBe(404)
  })

  test('super admin sees the blog workspace navigation', async ({ page }) => {
    await login(page, superAdmin)
    await page.goto('/platform/marketing')
    await expect(page.getByRole('link', { name: 'Blog & resources' }).first()).toBeVisible()
    await page.getByRole('link', { name: 'Blog & resources' }).first().click()
    await expect(page.getByRole('heading', { level: 1, name: 'Blog & resources' })).toBeVisible()
  })

  test('tenant accounts cannot manage the blog', async ({ page }) => {
    await login(page, tenant)
    await page.goto('/platform/marketing/blog')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
  })
})
