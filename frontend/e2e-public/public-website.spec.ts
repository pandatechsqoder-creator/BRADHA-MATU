import { expect, test, type Page } from '@playwright/test'
import fs from 'node:fs'
import path from 'node:path'

const evidenceDir = path.resolve(process.cwd(), '../docs/evidence/phase-2-core-pages')
const landingEvidenceDir = path.resolve(process.cwd(), '../docs/evidence/phase-3-landing-pages')

type PublicPage = {
    url: string
    heading: string
    cta?: string
}

const publicPages: PublicPage[] = [
    { url: '/', heading: 'Accurate stock counts, without slowing your business' },
    { url: '/stock-taking', heading: 'Stock-taking services for every frequency and scope' },
    { url: '/brathamatu-pos', heading: 'Sales, stock and branches in one place' },
    { url: '/how-it-works', heading: 'A clear process from enquiry to stock-count report' },
    { url: '/industries', heading: 'Stock-taking and POS for inventory-heavy businesses' },
    { url: '/pricing', heading: 'Quotations based on what actually needs counting' },
    { url: '/resources', heading: 'Practical guidance for better stock control' },
    { url: '/about', heading: 'Professional stock-taking, supported by practical software' },
    { url: '/contact', heading: 'Contact BRATHAMATU' },
    { url: '/request-quote', heading: 'Request a stock-taking quotation' },
    { url: '/book-demo', heading: 'Book a BRATHAMATU POS demonstration' },
    { url: '/faqs', heading: 'Frequently asked questions' },
    { url: '/privacy', heading: 'Privacy Notice' },
    { url: '/terms', heading: 'Terms of Service' },
    { url: '/cookies', heading: 'Cookie and Tracking Notice' },
    {
        url: '/stock-taking/on-demand',
        heading: 'Stock taking when you need it, not only on a fixed schedule',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/monthly',
        heading: 'Monthly stock taking that keeps records close to reality',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/annual',
        heading: 'Annual stock taking for a clear year-end position',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/recurring',
        heading: 'Recurring stock taking on a schedule that fits your operation',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/full',
        heading: 'A complete physical count across your agreed stock',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/cycle-counting',
        heading: 'Cycle counting for focused, ongoing stock control',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/branch',
        heading: 'Branch stock taking across every location in scope',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/warehouse',
        heading: 'Warehouse stock taking built around how goods move',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/independent-verification',
        heading: 'Independent verification for quantities you need to confirm',
        cta: '/request-quote',
    },
    {
        url: '/stock-taking/recounts',
        heading: 'Recounts and discrepancy investigation when numbers do not agree',
        cta: '/request-quote',
    },
    {
        url: '/brathamatu-pos/retail',
        heading: 'Retail POS for sales, stock and everyday control',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/supermarket',
        heading: 'Supermarket POS for high-volume selling and stock visibility',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/pharmacy',
        heading: 'Pharmacy POS for product and inventory control',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/wholesale',
        heading: 'Wholesale POS for bulk sales and distribution workflows',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/hardware',
        heading: 'Hardware POS for mixed units, bulk items and stock control',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/restaurant',
        heading: 'Restaurant POS for fast sales and consumable stock',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/multi-branch',
        heading: 'Multi-branch POS with one operational view',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/inventory-management',
        heading: 'Inventory management connected to sales and stock counts',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/barcode-catalogue',
        heading: 'Barcode and product catalogue management for POS',
        cta: '/book-demo',
    },
    {
        url: '/brathamatu-pos/stocktaking-integration',
        heading: 'Stock-taking integration inside the BRATHAMATU POS workflow',
        cta: '/book-demo',
    },
]

async function expectNoHorizontalOverflow(page: Page, context: string): Promise<void> {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )
    expect(overflow, `${context} overflows horizontally by ${overflow}px`).toBeLessThanOrEqual(1)
}

async function capture(page: Page, project: string, name: string, dir = evidenceDir): Promise<void> {
    await page.screenshot({
        path: path.join(dir, `${project}-${name}.png`),
        fullPage: true,
        scale: 'css',
    })
}

test.beforeAll(() => {
    fs.mkdirSync(evidenceDir, { recursive: true })
    fs.mkdirSync(landingEvidenceDir, { recursive: true })
})

test('every public page renders server-side content without layout overflow', async ({ page }, testInfo) => {
    const errors: string[] = []
    page.on('pageerror', (error) => errors.push(error.message))

    for (const entry of publicPages) {
        const response = await page.goto(entry.url)
        expect(response?.status(), `${entry.url} status`).toBe(200)
        await expect(page.getByRole('heading', { level: 1, name: entry.heading })).toBeVisible()
        await expect(page.locator('footer')).toContainText('BRATHAMATU')
        await expectNoHorizontalOverflow(page, entry.url)

        if (entry.cta) {
            await expect(page.locator('main a[href="' + entry.cta + '"]').first()).toBeVisible()
        }
    }

    expect(errors).toEqual([])

    await page.goto('/')
    await capture(page, testInfo.project.name, 'home')
    await page.goto('/stock-taking')
    await capture(page, testInfo.project.name, 'stock-taking')
    await page.goto('/contact')
    await capture(page, testInfo.project.name, 'contact')
    await page.goto('/how-it-works')
    await capture(page, testInfo.project.name, 'how-it-works')
    await page.goto('/privacy')
    await capture(page, testInfo.project.name, 'privacy')
})

test('primary navigation reaches public pages at every width', async ({ page }) => {
    await page.goto('/')
    const viewport = page.viewportSize()

    if (!viewport || viewport.width <= 1200) {
        await page.getByText('Menu', { exact: true }).click()
        await page
            .getByRole('navigation', { name: 'Mobile' })
            .getByRole('link', { name: 'Stock Taking' })
            .click()
    } else {
        await page
            .getByRole('navigation', { name: 'Primary' })
            .getByRole('link', { name: 'Stock Taking' })
            .click()
    }

    await expect(page).toHaveURL(/\/stock-taking$/)
    await expect(
        page.getByRole('heading', { level: 1, name: 'Stock-taking services for every frequency and scope' }),
    ).toBeVisible()
})

test('mobile menu opens from the keyboard', async ({ page }) => {
    test.skip((page.viewportSize()?.width ?? 0) > 1200, 'mobile menu is hidden on desktop')

    await page.goto('/')
    const summary = page.locator('.nav-mobile summary')
    await summary.focus()
    await page.keyboard.press('Enter')

    const mobileNav = page.getByRole('navigation', { name: 'Mobile' })
    await expect(mobileNav).toBeVisible()
    await expect(mobileNav.getByRole('link', { name: 'Home' })).toBeVisible()
})

test('skip link moves keyboard focus to the main content', async ({ page }) => {
    await page.goto('/')
    await page.keyboard.press('Tab')

    const skipLink = page.locator('.skip-link')
    await expect(skipLink).toBeFocused()
    await page.keyboard.press('Enter')

    await expect(page).toHaveURL(/#main$/)
    await expect(page.locator('#main')).toBeFocused()
})

test('content is readable without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 390, height: 844 } })
    const page = await context.newPage()

    await page.goto('/')
    await expect(
        page.getByRole('heading', { level: 1, name: 'Accurate stock counts, without slowing your business' }),
    ).toBeVisible()
    await expect(page.locator('nav[aria-label="Mobile"]')).toContainText('Stock Taking')
    await expect(page.locator('footer')).toContainText('BRATHAMATU')

    await page.goto('/how-it-works')
    await expect(page.getByRole('heading', { level: 1, name: 'A clear process from enquiry to stock-count report' })).toBeVisible()
    await expect(page.locator('main')).toContainText('Where BRATHAMATU POS fits')

    await page.goto('/privacy')
    await expect(page.getByRole('heading', { level: 1, name: 'Privacy Notice' })).toBeVisible()
    await expect(page.locator('main')).toContainText('Information we collect')

    await page.goto('/stock-taking/recounts')
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'Recounts and discrepancy investigation when numbers do not agree',
        }),
    ).toBeVisible()
    await expect(page.locator('main')).toContainText('Targeted lines')

    await page.goto('/brathamatu-pos/pharmacy')
    await expect(
        page.getByRole('heading', { level: 1, name: 'Pharmacy POS for product and inventory control' }),
    ).toBeVisible()
    await expect(page.locator('main')).toContainText('Batch tracking')

    await context.close()
})

test('footer legal links reach published noindex notices', async ({ page }) => {
    await page.goto('/')

    await page.locator('footer').getByRole('link', { name: 'Privacy Notice' }).click()

    await expect(page).toHaveURL(/\/privacy$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Privacy Notice' })).toBeVisible()
    await expect(page.getByText('Last updated: 16 November 2026')).toBeVisible()
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)
    await expect(page.locator('footer')).toContainText('Cookie Notice')
})

test('primary CTAs complete the quote and demo journeys', async ({ page }, testInfo) => {
    await page.goto('/')
    await page.locator('.hero').getByRole('link', { name: 'Request a stock-taking quote' }).click()
    await expect(page).toHaveURL(/\/request-quote$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Request a stock-taking quotation' })).toBeVisible()
    await expect(page.locator('.contact-card').getByRole('link', { name: 'Contact the team' })).toHaveAttribute(
        'href',
        /\/contact$/,
    )

    await page.goto('/brathamatu-pos')
    await page.locator('.hero').getByRole('link', { name: 'Book a BRATHAMATU POS demo' }).click()
    await expect(page).toHaveURL(/\/book-demo$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Book a BRATHAMATU POS demonstration' })).toBeVisible()
    await expect(page.locator('.contact-card').getByRole('link', { name: 'Contact the team' })).toHaveAttribute(
        'href',
        /\/contact$/,
    )

    await page.goto('/stock-taking/on-demand')
    await capture(page, testInfo.project.name, 'stock-taking-on-demand', landingEvidenceDir)
    await page.locator('.hero').getByRole('link', { name: 'Request a stock-taking quote' }).click()
    await expect(page).toHaveURL(/\/request-quote$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Request a stock-taking quotation' })).toBeVisible()

    await page.goto('/brathamatu-pos/retail')
    await capture(page, testInfo.project.name, 'brathamatu-pos-retail', landingEvidenceDir)
    await page.locator('.hero').getByRole('link', { name: 'Book a retail POS demo' }).click()
    await expect(page).toHaveURL(/\/book-demo$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Book a BRATHAMATU POS demonstration' })).toBeVisible()

    await capture(page, testInfo.project.name, 'book-demo')
})

test('internal links on core pages resolve without errors', async ({ page, request }) => {
    const corePages = [
        '/',
        '/about',
        '/stock-taking',
        '/brathamatu-pos',
        '/how-it-works',
        '/industries',
        '/pricing',
        '/contact',
        '/faqs',
        '/privacy',
        '/terms',
        '/cookies',
    ]
    const skip = new Set(['/login', '/register'])
    const links = new Set<string>()

    for (const url of corePages) {
        await page.goto(url)
        const origin = new URL(page.url()).origin
        const hrefs = await page
            .locator('a[href]')
            .evaluateAll((anchors) => anchors.map((anchor) => (anchor as HTMLAnchorElement).href))

        for (const href of hrefs) {
            const parsed = new URL(href)
            if (parsed.origin === origin && !skip.has(parsed.pathname)) {
                links.add(parsed.pathname)
            }
        }
    }

    for (const href of links) {
        const response = await request.get(href)
        expect(response.status(), `${href} link status`).toBe(200)
    }
})

test('accessibility tree exposes landmarks and labelled navigation', async ({ page }) => {
    await page.goto('/')

    const header = await page.locator('header').ariaSnapshot()
    expect(header).toContain('banner')

    if ((page.viewportSize()?.width ?? 0) <= 1200) {
        expect(header).toContain('group: Menu')
        await page.getByText('Menu', { exact: true }).click()
        const expanded = await page.locator('header').ariaSnapshot()
        expect(expanded).toContain('navigation "Mobile"')
        expect(expanded).toContain('link "Stock Taking"')
        expect(expanded).toContain('link "Request a quote"')
    } else {
        expect(header).toContain('navigation "Primary"')
        expect(header).toContain('link "Stock Taking"')
        expect(header).toContain('link "Request a quote"')
    }

    const main = await page.locator('main').ariaSnapshot()
    expect(main).toContain('heading "Accurate stock counts, without slowing your business"')

    const footer = await page.locator('footer').ariaSnapshot()
    expect(footer).toContain('contentinfo')
    expect(footer).toContain('navigation')
})

test('unknown public URLs return the branded 404 with noindex', async ({ page }, testInfo) => {
    const response = await page.goto('/this-page-does-not-exist')

    expect(response?.status()).toBe(404)
    await expect(page.getByRole('heading', { level: 1, name: 'Page not found' })).toBeVisible()
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/)
    await expect(page.getByRole('link', { name: 'Go to the homepage' })).toBeVisible()

    await capture(page, testInfo.project.name, '404')
})

test('robots and sitemap are served for search engines', async ({ request }) => {
    const robots = await request.get('/robots.txt')
    expect(robots.status()).toBe(200)
    expect(robots.headers()['content-type']).toContain('text/plain')
    expect(await robots.text()).toContain('User-agent: *')

    const sitemap = await request.get('/sitemap.xml')
    expect(sitemap.status()).toBe(200)
    expect(sitemap.headers()['content-type']).toContain('xml')

    const body = await sitemap.text()
    expect(body).toContain('<urlset')
    expect(body).toContain('<loc>')
    expect(body).toContain('/stock-taking')
    expect(body).not.toContain('/privacy')
    expect(body).not.toContain('/this-page-does-not-exist')
})
