import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { LocationsPage } from '../features/tenant/LocationsPage'

function jsonResponse(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const branches = [
  {
    id: 'branch-ho',
    name: 'Head Office',
    code: 'HEAD-OFFICE',
    address: null,
    city: null,
    phone: null,
    email: null,
    timezone: 'Africa/Nairobi',
    status: 'active',
    is_default: true,
    departments_count: 0,
    warehouses_count: 0,
    locations_count: 1,
  },
  {
    id: 'branch-west',
    name: 'Westlands',
    code: null,
    address: null,
    city: null,
    phone: null,
    email: null,
    timezone: 'Africa/Nairobi',
    status: 'active',
    is_default: false,
    departments_count: 0,
    warehouses_count: 0,
    locations_count: 1,
  },
]

const stockLocations = [
  {
    id: 'location-main',
    name: 'Main Store',
    code: 'MAIN',
    type: 'zone',
    status: 'active',
    is_default: true,
    branch_id: 'branch-ho',
    branch_name: 'Head Office',
    warehouse_id: null,
    warehouse_name: null,
    zones_count: 0,
  },
  {
    id: 'location-west',
    name: 'Westlands Floor',
    code: null,
    type: 'floor',
    status: 'active',
    is_default: false,
    branch_id: 'branch-west',
    branch_name: 'Westlands',
    warehouse_id: null,
    warehouse_name: null,
    zones_count: 0,
  },
]

describe('LocationsPage defaults', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('brathamatu.auth.token', '1|test')
    localStorage.setItem('brathamatu.active.tenant', 'tenant-1')
    vi.restoreAllMocks()
  })

  it('shows the default Head Office and lets an authorised user change the default stock location', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      if (url.endsWith('/me')) {
        return jsonResponse({
          id: 'user-1',
          name: 'Owner',
          email: 'owner@example.test',
          email_verified_at: null,
          created_at: '2026-01-01T00:00:00Z',
          permissions: ['branch.view', 'branch.create', 'branch.update'],
          current_tenant: { id: 'tenant-1', name: 'Business', operating_mode: 'manual' },
        })
      }
      if (url.endsWith('/branches')) return jsonResponse(branches)
      if (url.endsWith('/departments') || url.endsWith('/warehouses')) return jsonResponse([])
      if (url.endsWith('/stock-locations')) return jsonResponse(stockLocations)
      if (url.endsWith('/stock-locations/location-west') && init?.method === 'PATCH') {
        return jsonResponse({ id: 'location-west', name: 'Westlands Floor', status: 'active', is_default: true })
      }
      throw new Error(`Unexpected request: ${init?.method ?? 'GET'} ${url}`)
    })

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    })
    render(
      <QueryClientProvider client={queryClient}>
        <LocationsPage />
      </QueryClientProvider>,
    )

    expect(await screen.findByText('Default Head Office')).toBeInTheDocument()
    expect(screen.getByText('Default stock location')).toBeInTheDocument()
    const defaultButtons = await screen.findAllByRole('button', { name: 'Make default' })
    await userEvent.click(defaultButtons[1])

    await waitFor(() => {
      const patch = fetchMock.mock.calls.find(
        ([input, init]) =>
          String(input).endsWith('/stock-locations/location-west') && init?.method === 'PATCH',
      )
      expect(patch).toBeDefined()
      expect(JSON.parse(String(patch?.[1]?.body))).toEqual({ is_default: true })
    })
  })
})
