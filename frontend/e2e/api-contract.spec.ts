import { expect, test } from '@playwright/test'

const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'

test.describe('API acceptance contract', () => {
  test('health endpoint reports ok with envelope, request id and security headers', async ({
    request,
  }) => {
    const response = await request.get(`${API}/health`)
    expect(response.status()).toBe(200)
    expect(response.headers()['x-request-id']).toBeTruthy()
    expect(response.headers()['x-content-type-options']).toBe('nosniff')
    expect(response.headers()['x-frame-options']).toBe('DENY')
    expect(response.headers()['referrer-policy']).toBe('no-referrer')

    const body = (await response.json()) as {
      data: { status: string; service: string; checks: Record<string, string> }
      meta: { request_id: string; timestamp: string }
    }
    expect(body.data.status).toBe('ok')
    expect(body.data.service).toBe('brathamatu-stocktake-api')
    expect(body.data.checks.database).toBe('ok')
    expect(body.data.checks.cache).toBe('ok')
    expect(body.meta.request_id).toBeTruthy()
    expect(body.meta.timestamp).toBeTruthy()
  })

  test('unknown API route returns the JSON error envelope', async ({ request }) => {
    const response = await request.get(`${API}/does-not-exist`)
    expect(response.status()).toBe(404)
    const body = (await response.json()) as {
      error: { code: string; message: string; request_id: string }
    }
    expect(body.error.code).toBe('NOT_FOUND')
    expect(body.error.message).toBeTruthy()
    expect(body.error.request_id).toBeTruthy()
  })

  test('unauthenticated tenant endpoint is rejected', async ({ request }) => {
    const response = await request.get(`${API}/tenants/1/products`)
    expect(response.status()).toBe(401)
    const body = (await response.json()) as { error: { code: string } }
    expect(body.error.code).toBe('UNAUTHENTICATED')
  })
})
