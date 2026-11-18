import fs from 'node:fs'
import zlib from 'node:zlib'
import { expect, test, type Page } from '@playwright/test'
import { login } from './helpers'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const PUBLIC = process.env.E2E_PUBLIC_BASE_URL ?? 'http://127.0.0.1:8000'
const outputDir = '../docs/evidence/phase-4-cms-media/screens'
const runId = Date.now().toString().slice(-6)

const editor = { email: 'editor@brathamatu.test', password: 'Password1!' }
const publisher = { email: 'publisher@brathamatu.test', password: 'Password1!' }
const support = { email: 'support@brathamatu.test', password: 'Password1!' }
const tenant = { email: 'owner@demosupermarket.test', password: 'Password1!' }

function crc32(buffer: Buffer): number {
  let crc = 0xffffffff
  for (const byte of buffer) {
    crc ^= byte
    for (let bit = 0; bit < 8; bit += 1) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1
    }
  }
  return (crc ^ 0xffffffff) >>> 0
}

function pngChunk(type: string, data: Buffer): Buffer {
  const length = Buffer.alloc(4)
  length.writeUInt32BE(data.length)
  const typeBuffer = Buffer.from(type, 'ascii')
  const crc = Buffer.alloc(4)
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuffer, data])))
  return Buffer.concat([length, typeBuffer, data, crc])
}

function solidPng(width: number, height: number, [red, green, blue]: [number, number, number]): Buffer {
  const header = Buffer.alloc(13)
  header.writeUInt32BE(width, 0)
  header.writeUInt32BE(height, 4)
  header[8] = 8
  header[9] = 2
  const raw = Buffer.alloc((width * 3 + 1) * height)
  for (let y = 0; y < height; y += 1) {
    const row = y * (width * 3 + 1)
    raw[row] = 0
    for (let x = 0; x < width; x += 1) {
      const offset = row + 1 + x * 3
      raw[offset] = red
      raw[offset + 1] = green
      raw[offset + 2] = blue
    }
  }
  return Buffer.concat([
    Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
    pngChunk('IHDR', header),
    pngChunk('IDAT', zlib.deflateSync(raw)),
    pngChunk('IEND', Buffer.alloc(0)),
  ])
}

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
}

async function addRichText(page: Page, heading: string, paragraph: string): Promise<void> {
  await page.getByLabel('Section type').selectOption('rich_text')
  await page.getByRole('button', { name: 'Add section' }).click()
  const content = page.getByTestId('section-list').getByLabel('Content (JSON)').first()
  await content.fill(JSON.stringify({ heading, paragraphs: [paragraph], bullets: [] }, null, 2))
  await page.getByRole('button', { name: 'Save sections' }).click()
  await expect(page.getByText('Sections saved.')).toBeVisible()
}

async function editorPage(page: Page, title: string): Promise<void> {
  await page.goto('/platform/marketing/pages')
  await page.getByTestId('marketing-page-row').filter({ hasText: title }).click()
  await expect(page.getByRole('heading', { level: 1, name: title })).toBeVisible()
}

test.describe('marketing CMS admin journey', () => {
  test.describe.configure({ mode: 'serial' })
  test.setTimeout(420_000)

  test.beforeAll(() => {
    fs.mkdirSync(outputDir, { recursive: true })
  })

  test('publish, schedule, unpublish, restore, media replace and usage', async ({ page }) => {
    const title = `Phase 4 release ${runId}`
    const slug = `phase4-release-${runId}`

    await login(page, editor)
    await page.goto('/platform/marketing')
    await expect(page.getByRole('heading', { level: 1, name: 'Marketing' })).toBeVisible()
    await page.screenshot({ path: `${outputDir}/01-marketing-overview.png`, fullPage: true })

    await createPage(page, title, slug)
    await addRichText(page, 'Phase 4 release notes', 'Published from the marketing CMS.')
    await page.screenshot({ path: `${outputDir}/02-editor-draft.png`, fullPage: true })
    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')

    await logout(page)
    await login(page, publisher)
    await editorPage(page, title)
    await expect(page.getByTestId('page-status')).toHaveText('review')
    await page.getByRole('button', { name: 'Approve', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('published')
    await expect(page.getByRole('link', { name: 'Open live page' })).toBeVisible()
    await page.screenshot({ path: `${outputDir}/03-published.png`, fullPage: true })

    const publicPage = `${PUBLIC}/${slug}`
    const live = await page.request.get(publicPage)
    expect(live.status()).toBe(200)
    expect(await live.text()).toContain('Phase 4 release notes')

    await addRichText(page, 'Phase 4 release notes v2', 'Second revision of the release notes.')
    await expect(page.getByTestId('page-status')).toHaveText('draft')
    const stillLive = await page.request.get(publicPage)
    expect(await stillLive.text()).toContain('Published from the marketing CMS.')
    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')
    await page.getByRole('button', { name: 'Approve', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByRole('button', { name: 'Publish now' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('published')
    const updated = await page.request.get(publicPage)
    expect(await updated.text()).toContain('Phase 4 release notes v2')

    await page.getByRole('button', { name: 'Unpublish' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('draft')
    const gone = await page.request.get(publicPage)
    expect(gone.status()).toBe(404)

    await page.screenshot({ path: `${outputDir}/04-version-history.png`, fullPage: true })
    const firstPublished = page.getByTestId('version-list').locator('li').filter({ hasText: 'v3 · published' })
    await firstPublished.getByRole('button', { name: 'Restore' }).click()
    await expect(page.getByText('Restored version 3 as a draft.')).toBeVisible()
    await expect(page.getByTestId('section-list').getByLabel('Content (JSON)').first()).toHaveValue(
      /Published from the marketing CMS\./,
    )

    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Delete page' }).click()
    await page.waitForURL('**/platform/marketing/pages')

    const seed = Number(runId)
    const banner = solidPng(4, 4, [seed % 256, (seed >> 8) % 256, (seed >> 16) % 256])
    const replacement = solidPng(4, 4, [255 - (seed % 256), 60, 40])
    const bannerName = `phase4-banner-${runId}.png`

    await page.goto('/platform/marketing/media')
    await expect(page.getByRole('heading', { level: 1, name: 'Media library' })).toBeVisible()
    await page.getByLabel('File').setInputFiles({ name: bannerName, mimeType: 'image/png', buffer: banner })
    await page.getByLabel('Alt text', { exact: true }).first().fill('Phase 4 evidence banner')
    await page.getByRole('button', { name: /^Upload to/ }).click()
    await expect(page.getByText('Media uploaded.')).toBeVisible()
    await expect(page.getByTestId('media-asset-id')).toBeVisible()
    const assetId = (await page.getByTestId('media-asset-id').innerText()).trim()
    expect(assetId).toHaveLength(26)

    const mediaTitle = `Media reference ${runId}`
    const mediaSlug = `phase4-media-ref-${runId}`
    await createPage(page, mediaTitle, mediaSlug)
    await addRichText(page, 'Media reference', `${PUBLIC}/media/${assetId} used by this page.`)

    await page.goto('/platform/marketing/media')
    await page
      .getByTestId('media-grid')
      .getByRole('button')
      .filter({ hasText: bannerName })
      .click()
    await expect(page.getByText('Used in 1 place(s)')).toBeVisible()
    await expect(page.getByTestId('media-usages')).toContainText('CmsPage')

    await page.getByTestId('media-replace-input').setInputFiles({
      name: `phase4-banner-${runId}-v2.png`,
      mimeType: 'image/png',
      buffer: replacement,
    })
    await expect(page.getByText(/used in 1 place\(s\)/i)).toBeVisible()
    await expect(page.getByText(/^v2 ·/).first()).toBeVisible()
    await page.screenshot({ path: `${outputDir}/05-media-replaced.png`, fullPage: true })

    const image = page.locator(`img[alt="Phase 4 evidence banner"]`).first()
    const src = await image.getAttribute('src')
    expect(src).toBeTruthy()
    const delivered = await page.request.get(src as string)
    expect(delivered.status()).toBe(200)
    expect(delivered.headers()['content-type']).toContain('image/png')

    await editorPage(page, mediaTitle)
    page.once('dialog', (dialog) => dialog.accept())
    await page.getByRole('button', { name: 'Delete page' }).click()
    await page.waitForURL('**/platform/marketing/pages')
  })

  test('scheduling an approved page and permission denial per role', async ({ page }) => {
    const title = `Scheduled ${runId}`
    const slug = `phase4-scheduled-${runId}`

    await login(page, editor)
    await createPage(page, title, slug)
    await addRichText(page, 'Scheduled release', 'This page is scheduled from the admin UI.')
    await page.getByRole('button', { name: 'Submit for review' }).click()
    await expect(page.getByTestId('page-status')).toHaveText('review')
    await expect(page.getByRole('button', { name: 'Approve', exact: true })).toHaveCount(0)
    await expect(page.getByRole('button', { name: 'Publish now' })).toHaveCount(0)
    await page.screenshot({ path: `${outputDir}/06-editor-denied.png`, fullPage: true })

    const pageUrl = page.url()
    const pageId = pageUrl.split('/').pop() ?? ''
    const token = await page.evaluate(() => localStorage.getItem('brathamatu.auth.token'))
    const denied = await page.request.post(`${API}/platform/marketing/pages/${pageId}/approve`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    })
    expect(denied.status()).toBe(403)

    await logout(page)
    await login(page, publisher)
    await editorPage(page, title)
    await page.getByRole('button', { name: 'Approve', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('approved')
    await page.getByLabel('Schedule publish').fill('2027-01-15T10:00')
    await page.getByRole('button', { name: 'Schedule', exact: true }).click()
    await expect(page.getByTestId('page-status')).toHaveText('scheduled')
    await expect(page.getByText(/Scheduled for/).first()).toBeVisible()
    await page.screenshot({ path: `${outputDir}/07-scheduled.png`, fullPage: true })

    await logout(page)
    await login(page, support)
    await page.goto('/platform/marketing')
    await expect(page.getByText('You do not have permission to view this page.')).toBeVisible()
    await page.screenshot({ path: `${outputDir}/08-support-denied.png`, fullPage: true })

    await logout(page)
    await login(page, tenant)
    await page.goto('/platform/marketing')
    await expect(
      page.getByText('Platform administration is only available to BRATHAMATU platform roles.'),
    ).toBeVisible()
    await page.screenshot({ path: `${outputDir}/09-tenant-denied.png`, fullPage: true })
  })

  test('staff login links to the public site and super admin manages the website', async ({ page }) => {
    const home = await page.request.get('/')
    expect(home.status()).toBe(200)
    expect(await home.text()).toContain('Accurate stock counts, without slowing your business')

    await page.goto('/login')
    const websiteLink = page.getByRole('link', { name: /Visit the public BRATHAMATU website/ })
    await expect(websiteLink).toBeVisible()
    expect(await websiteLink.getAttribute('href')).toBe('/')

    await login(page, { email: 'superadmin@brathamatu.test', password: 'Password1!' })
    await page.goto('/platform')
    await expect(page.getByRole('heading', { name: 'Public website' })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Marketing overview' })).toBeVisible()
    await expect(
      page.getByRole('main').getByRole('link', { name: 'View public website ↗' }),
    ).toBeVisible()

    await page.getByRole('link', { name: 'Open marketing workspace →' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Marketing' })).toBeVisible()
    await page.screenshot({ path: `${outputDir}/10-super-admin-website.png`, fullPage: true })
  })
})
