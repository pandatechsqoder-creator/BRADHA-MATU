export class ApiError extends Error {
  readonly code: string
  readonly status: number
  readonly details: Record<string, string[]>
  readonly requestId?: string

  constructor(
    status: number,
    code: string,
    message: string,
    details: Record<string, string[]> = {},
    requestId?: string,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.requestId = requestId
  }

  fieldError(field: string): string | undefined {
    return this.details[field]?.[0]
  }
}

export interface PaginatedEnvelope<T> {
  data: T[]
  meta?: {
    request_id?: string
    timestamp?: string
    current_page?: number
    per_page?: number
    total?: number
    last_page?: number
    can_manage?: boolean
    buckets?: Record<string, number>
    status_counts?: Record<string, number>
    counts?: Record<string, number>
    trial_balance?: unknown
  }
}

interface Envelope<T> {
  data: T
  meta?: {
    request_id?: string
    timestamp?: string
    current_page?: number
    per_page?: number
    total?: number
    last_page?: number
    can_manage?: boolean
    buckets?: Record<string, number>
    status_counts?: Record<string, number>
    counts?: Record<string, number>
    trial_balance?: unknown
  }
}

interface ErrorEnvelope {
  error?: {
    code?: string
    message?: string
    details?: Record<string, string[]>
    request_id?: string
  }
}

const TOKEN_KEY = 'brathamatu.auth.token'
const ACTIVE_TENANT_KEY = 'brathamatu.active.tenant'

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

export function setStoredToken(token: string): void {
  localStorage.setItem(TOKEN_KEY, token)
}

export function clearStoredToken(): void {
  localStorage.removeItem(TOKEN_KEY)
}

export function getStoredTenantId(): string | null {
  return localStorage.getItem(ACTIVE_TENANT_KEY)
}

export function setStoredTenantId(tenantId: string | null): void {
  if (tenantId) localStorage.setItem(ACTIVE_TENANT_KEY, tenantId)
  else localStorage.removeItem(ACTIVE_TENANT_KEY)
}

function authHeaders(): Record<string, string> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getStoredToken()
  if (token) headers.Authorization = `Bearer ${token}`
  const tenantId = getStoredTenantId()
  if (tenantId) headers['X-Tenant-Id'] = tenantId
  return headers
}

async function parseResponse<T>(response: Response): Promise<{ data: T; meta?: Envelope<T>['meta'] }> {
  if (response.status === 204) return { data: undefined as T }

  const payload = (await response.json().catch(() => null)) as
    | Envelope<T>
    | ErrorEnvelope
    | null

  if (!response.ok) {
    const err = (payload as ErrorEnvelope | null)?.error
    if (response.status === 401) clearStoredToken()
    throw new ApiError(
      response.status,
      err?.code ?? 'HTTP_ERROR',
      err?.message ?? `Request failed (${response.status}).`,
      err?.details ?? {},
      err?.request_id,
    )
  }

  const envelope = payload as Envelope<T>
  return { data: envelope.data, meta: envelope.meta }
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  extraHeaders?: Record<string, string>,
): Promise<{ data: T; meta?: Envelope<T>['meta'] }> {
  const headers = { ...authHeaders(), ...extraHeaders }
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError(0, 'NETWORK_ERROR', 'Cannot reach the server. Check your connection and try again.')
  }

  return parseResponse<T>(response)
}

export const api = {
  get: async <T>(path: string) => (await request<T>('GET', path)).data,
  getWithMeta: <T>(path: string) => request<T>('GET', path),
  post: async <T>(path: string, body?: unknown) => (await request<T>('POST', path, body)).data,
  postWithHeaders: async <T>(path: string, body: unknown, headers: Record<string, string>) =>
    (await request<T>('POST', path, body, headers)).data,
  patch: async <T>(path: string, body?: unknown) => (await request<T>('PATCH', path, body)).data,
  put: async <T>(path: string, body?: unknown) => (await request<T>('PUT', path, body)).data,
  delete: async <T>(path: string) => (await request<T>('DELETE', path)).data,
  upload: async <T>(path: string, form: FormData) => {
    let response: Response
    try {
      response = await fetch(`/api/v1${path}`, {
        method: 'POST',
        headers: authHeaders(),
        body: form,
      })
    } catch {
      throw new ApiError(0, 'NETWORK_ERROR', 'Cannot reach the server. Check your connection and try again.')
    }

    return (await parseResponse<T>(response)).data
  },
  download: async (path: string): Promise<Blob> => {
    let response: Response
    try {
      response = await fetch(`/api/v1${path}`, { headers: authHeaders() })
    } catch {
      throw new ApiError(0, 'NETWORK_ERROR', 'Cannot reach the server. Check your connection and try again.')
    }

    if (!response.ok) {
      const payload = (await response.json().catch(() => null)) as ErrorEnvelope | null
      const err = payload?.error
      if (response.status === 401) clearStoredToken()
      throw new ApiError(
        response.status,
        err?.code ?? 'HTTP_ERROR',
        err?.message ?? `Download failed (${response.status}).`,
        err?.details ?? {},
        err?.request_id,
      )
    }

    return response.blob()
  },
}
