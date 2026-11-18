import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { CataloguePage } from '../features/catalogue/CataloguePage'
import { ApiError } from '../lib/api/client'

const mocks = vi.hoisted(() => ({
  createProduct: vi.fn(),
}))

vi.mock('../lib/auth/hooks', () => ({
  useCurrentUser: () => ({
    data: {
      current_tenant: { id: 'tenant-1' },
      permissions: ['catalogue.view', 'catalogue.create'],
    },
    isLoading: false,
  }),
  useCatalogueSummary: () => ({ data: undefined }),
  useProducts: () => ({
    data: {
      products: [],
      pagination: { current_page: 1, per_page: 24, total: 0, last_page: 1 },
    },
    isLoading: false,
  }),
  useProductCategories: () => ({ data: [] }),
  useUnitDirectory: () => ({ data: { units: [{ code: 'piece', name: 'Piece' }] } }),
  useCreateProduct: () => ({ mutate: mocks.createProduct, isPending: false }),
  useCreateProductCategory: () => ({ mutate: vi.fn(), isPending: false }),
  useDiscontinueProduct: () => ({ mutate: vi.fn(), isPending: false }),
}))

function renderCatalogue() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <CataloguePage />
    </QueryClientProvider>,
  )
}

describe('Catalogue product creation', () => {
  beforeEach(() => {
    mocks.createProduct.mockReset()
  })

  it('clears every product field only after creation succeeds', async () => {
    mocks.createProduct.mockImplementation((_input, callbacks) => {
      callbacks.onSuccess({ name: 'Green Tea' })
    })
    renderCatalogue()

    await userEvent.type(screen.getByLabelText('Product name'), 'Green Tea')
    await userEvent.type(screen.getByLabelText('SKU'), 'TEA-001')
    await userEvent.type(screen.getByLabelText('Variant name'), '500 g')
    await userEvent.type(screen.getByLabelText('Brand'), 'Valley')
    await userEvent.type(screen.getByLabelText('Category'), 'Tea')
    await userEvent.type(screen.getByLabelText('Barcode'), '123456789')
    await userEvent.type(screen.getByLabelText('Packaging unit'), 'carton')
    await userEvent.type(screen.getByLabelText('Base units per pack'), '12')
    await userEvent.type(screen.getByLabelText('Cost price (KSh)'), '150')
    await userEvent.click(screen.getByRole('button', { name: 'Add product' }))

    expect(await screen.findByText('Green Tea added to the catalogue.')).toBeInTheDocument()
    for (const label of [
      'Product name',
      'SKU',
      'Variant name',
      'Brand',
      'Category',
      'Barcode',
      'Packaging unit',
    ]) {
      expect(screen.getByLabelText(label), label).toHaveValue('')
    }
    expect(screen.getByLabelText('Base unit')).toHaveValue('piece')
    expect((screen.getByLabelText('Base units per pack') as HTMLInputElement).value).toBe('')
    expect((screen.getByLabelText('Cost price (KSh)') as HTMLInputElement).value).toBe('')
  })

  it('preserves entered values and shows field errors when creation fails', async () => {
    mocks.createProduct.mockImplementation((_input, callbacks) => {
      callbacks.onError(
        new ApiError(422, 'VALIDATION_FAILED', 'The submitted data is invalid.', {
          sku: ['This SKU is already in use.'],
        }),
      )
    })
    renderCatalogue()

    await userEvent.type(screen.getByLabelText('Product name'), 'Green Tea')
    await userEvent.type(screen.getByLabelText('SKU'), 'TEA-001')
    await userEvent.click(screen.getByRole('button', { name: 'Add product' }))

    expect(await screen.findByText('This SKU is already in use.')).toBeInTheDocument()
    expect(screen.getByText('The submitted data is invalid.')).toBeInTheDocument()
    expect(screen.getByLabelText('Product name')).toHaveValue('Green Tea')
    expect(screen.getByLabelText('SKU')).toHaveValue('TEA-001')
  })
})
