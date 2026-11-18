import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  api,
  clearStoredToken,
  getStoredToken,
  setStoredTenantId,
  setStoredToken,
} from '../api/client'
import {
  auditLogSchema,
  authResponseSchema,
  branchSchema,
  catalogueImportSchema,
  catalogueSummarySchema,
  dashboardSchema,
  departmentSchema,
  invitationPreviewSchema,
  invitationSchema,
  memberSchema,
  onboardingProgressSchema,
  paginatedMetaSchema,
  platformOverviewSchema,
  platformPricingSchema,
  pricingEstimateSchema,
  pricingPolicySchema,
  productCategorySchema,
  productSchema,
  stockBinSchema,
  stockLocationSchema,
  stockZoneSchema,
  tenantRoleSchema,
  tenantSchema,
  unitDirectorySchema,
  userSchema,
  warehouseSchema,
  type AuditLog,
  type Branch,
  type CatalogueDeclaration,
  type CatalogueImport,
  type CatalogueInvoice,
  type CatalogueSummary,
  type Dashboard,
  type Department,
  type Invitation,
  type InvitationPreview,
  type LoginInput,
  type Member,
  type OnboardingProgress,
  type PlatformOverview,
  type PlatformPricing,
  type PricingEstimate,
  type Product,
  type ProductCategory,
  type RegisterInput,
  type StockBin,
  type StockLocation,
  type StockZone,
  type Tenant,
  type TenantRole,
  type UnitDirectory,
  type User,
  type Warehouse,
} from '../api/schemas'

export function useCurrentUser() {
  return useQuery({
    queryKey: ['me'],
    queryFn: async () => userSchema.parse(await api.get<User>('/me')),
    enabled: getStoredToken() !== null,
    retry: false,
    staleTime: 60_000,
  })
}

export function useTenantOnboarding(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'onboarding'],
    queryFn: async () =>
      onboardingProgressSchema.parse(
        await api.get<OnboardingProgress>(`/tenants/${tenantId}/onboarding`),
      ),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useUpdateTenantOnboarding(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({ step, action }: { step: string; action: 'skip' | 'resume' | 'continue' | 'finish' }) =>
      onboardingProgressSchema.parse(
        await api.patch(`/tenants/${tenantId}/onboarding/${step}`, { action }),
      ),
    onSuccess: async (progress) => {
      queryClient.setQueryData(['tenants', tenantId, 'onboarding'], progress)
      await queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })
}

export function useLogin() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: LoginInput) => {
      const result = authResponseSchema.parse(
        await api.post('/auth/login', { ...input, device_name: 'web' }),
      )
      setStoredToken(result.token)
      setStoredTenantId(result.user.current_tenant?.id ?? result.user.tenants[0]?.id ?? null)
      return result.user
    },
    onSuccess: (user) => {
      queryClient.setQueryData(['me'], user)
    },
  })
}

export function useRegister() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: RegisterInput) => {
      const result = authResponseSchema.parse(await api.post('/auth/register', input))
      setStoredToken(result.token)
      setStoredTenantId(result.user.current_tenant?.id ?? null)
      sessionStorage.setItem('brathamatu.verification_email_sent', 'sent')
      if (result.verification_test_code) {
        sessionStorage.setItem('brathamatu.verify.test_code', result.verification_test_code)
      }
      return result.user
    },
    onSuccess: (user) => {
      queryClient.setQueryData(['me'], user)
    },
  })
}

export function useChangeInitialPassword() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: { password: string; password_confirmation: string }) =>
      api.post<{ password_changed: boolean }>('/auth/change-initial-password', input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: ['me'] })
    },
  })
}

export function useLogout() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      try {
        await api.post('/auth/logout')
      } finally {
        clearStoredToken()
        setStoredTenantId(null)
        sessionStorage.removeItem('brathamatu.verify.test_code')
        sessionStorage.removeItem('brathamatu.verification_email_sent')
      }
    },
    onSuccess: () => {
      queryClient.clear()
    },
  })
}

export function useSwitchTenant() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (tenantId: string) => {
      setStoredTenantId(tenantId)
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries()
    },
  })
}

export function useDashboard() {
  return useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => dashboardSchema.parse(await api.get<Dashboard>('/dashboard')),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function usePlatformOverview(enabled = true) {
  return useQuery({
    queryKey: ['platform', 'overview'],
    queryFn: async () => platformOverviewSchema.parse(await api.get<PlatformOverview>('/platform/overview')),
    enabled,
    retry: false,
  })
}

export function useTenants() {
  return useQuery({
    queryKey: ['tenants'],
    queryFn: async () => {
      const result = await api.get<Tenant[]>('/tenants')
      return result.map((tenant) => tenantSchema.parse(tenant))
    },
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateTenant() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: {
      name: string
      industry?: string
      business_type?: string
      operating_mode?: string
      timezone?: string
    }) => tenantSchema.parse(await api.post('/tenants', input)),
    onSuccess: async (tenant) => {
      setStoredTenantId(tenant.id)
      await queryClient.invalidateQueries()
    },
  })
}

export function useUpdateTenant(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: Partial<Tenant>) =>
      tenantSchema.parse(await api.patch(`/tenants/${tenantId}`, input)),
    onSuccess: async () => {
      await queryClient.invalidateQueries()
    },
  })
}

export function useMembers(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'members'],
    queryFn: async () => {
      const result = await api.get<Member[]>(`/tenants/${tenantId}/members`)
      return result.map((member) => memberSchema.parse(member))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function usePlatformTenants(params: { search?: string; status?: string; page?: number }) {
  return useQuery({
    queryKey: ['platform', 'tenants', params],
    queryFn: async () => {
      const query = new URLSearchParams()
      if (params.search) query.set('search', params.search)
      if (params.status) query.set('status', params.status)
      if (params.page) query.set('page', String(params.page))
      const { data, meta } = await api.getWithMeta<Tenant[]>(`/platform/tenants?${query.toString()}`)
      return {
        tenants: data.map((tenant) => tenantSchema.parse(tenant)),
        pagination: paginatedMetaSchema.parse({
          current_page: meta?.current_page ?? 1,
          per_page: meta?.per_page ?? 20,
          total: meta?.total ?? data.length,
          last_page: meta?.last_page ?? 1,
        }),
      }
    },
    retry: false,
  })
}

export function usePlatformTenant(tenantId: string | undefined) {
  return useQuery({
    queryKey: ['platform', 'tenants', tenantId],
    queryFn: async () => tenantSchema.parse(await api.get<Tenant>(`/tenants/${tenantId}`)),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useAuditLogs(params: { action?: string; page?: number }) {
  return useQuery({
    queryKey: ['platform', 'audit', params],
    queryFn: async () => {
      const query = new URLSearchParams()
      if (params.action) query.set('action', params.action)
      if (params.page) query.set('page', String(params.page))
      const { data, meta } = await api.getWithMeta<AuditLog[]>(`/platform/audit-logs?${query.toString()}`)
      return {
        logs: data.map((log) => auditLogSchema.parse(log)),
        pagination: paginatedMetaSchema.parse({
          current_page: meta?.current_page ?? 1,
          per_page: meta?.per_page ?? 25,
          total: meta?.total ?? data.length,
          last_page: meta?.last_page ?? 1,
        }),
      }
    },
    retry: false,
  })
}

export function useSendVerification() {
  return useMutation({
    mutationFn: async () => {
      const result = await api.post<{
        sent?: boolean
        already_verified?: boolean
        test_code?: string
      }>('/auth/email/verification-notification')
      if (result.sent || result.already_verified) {
        sessionStorage.setItem('brathamatu.verification_email_sent', 'sent')
      }
      return result
    },
  })
}

export function useConfirmVerification() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (code: string) =>
      userSchema.parse(await api.post<User>('/auth/verify-email', { code })),
    onSuccess: (user) => {
      sessionStorage.removeItem('brathamatu.verify.test_code')
      sessionStorage.removeItem('brathamatu.verification_email_sent')
      queryClient.setQueryData(['me'], user)
    },
  })
}

function useTenantStructureInvalidator(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'team'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export interface BranchInput {
  name: string
  code?: string | null
  address?: string | null
  city?: string | null
  phone?: string | null
  email?: string | null
  timezone?: string | null
  status?: string
  is_default?: boolean
}

export function useBranches(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'branches'],
    queryFn: async () => {
      const result = await api.get<Branch[]>(`/tenants/${tenantId}/branches`)
      return result.map((branch) => branchSchema.parse(branch))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateBranch(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: BranchInput) =>
      branchSchema.parse(await api.post(`/tenants/${tenantId}/branches`, input)),
    onSuccess: invalidate,
  })
}

export function useUpdateBranch(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({ branchId, input }: { branchId: string; input: Partial<BranchInput> }) =>
      branchSchema.parse(await api.patch(`/tenants/${tenantId}/branches/${branchId}`, input)),
    onSuccess: invalidate,
  })
}

export function useDeactivateBranch(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (branchId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/branches/${branchId}`),
    onSuccess: invalidate,
  })
}

export function useDepartments(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'departments'],
    queryFn: async () => {
      const result = await api.get<Department[]>(`/tenants/${tenantId}/departments`)
      return result.map((department) => departmentSchema.parse(department))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateDepartment(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: { name: string; code?: string | null; branch_id?: string | null }) =>
      departmentSchema.parse(await api.post(`/tenants/${tenantId}/departments`, input)),
    onSuccess: invalidate,
  })
}

export function useUpdateDepartment(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      departmentId,
      input,
    }: {
      departmentId: string
      input: { name?: string; code?: string | null; branch_id?: string | null; status?: string }
    }) => api.patch<{ id: string; name: string; status: string }>(
      `/tenants/${tenantId}/departments/${departmentId}`,
      input,
    ),
    onSuccess: invalidate,
  })
}

export function useDeactivateDepartment(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (departmentId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/departments/${departmentId}`),
    onSuccess: invalidate,
  })
}

export function useWarehouses(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'warehouses'],
    queryFn: async () => {
      const result = await api.get<Warehouse[]>(`/tenants/${tenantId}/warehouses`)
      return result.map((warehouse) => warehouseSchema.parse(warehouse))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateWarehouse(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: {
      name: string
      code?: string | null
      branch_id?: string | null
      address?: string | null
    }) => warehouseSchema.parse(await api.post(`/tenants/${tenantId}/warehouses`, input)),
    onSuccess: invalidate,
  })
}

export function useUpdateWarehouse(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      warehouseId,
      input,
    }: {
      warehouseId: string
      input: {
        name?: string
        code?: string | null
        branch_id?: string | null
        address?: string | null
        status?: string
      }
    }) => api.patch<{ id: string; name: string; status: string }>(
      `/tenants/${tenantId}/warehouses/${warehouseId}`,
      input,
    ),
    onSuccess: invalidate,
  })
}

export function useDeactivateWarehouse(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (warehouseId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/warehouses/${warehouseId}`),
    onSuccess: invalidate,
  })
}

export function useStockLocations(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stock-locations'],
    queryFn: async () => {
      const result = await api.get<StockLocation[]>(`/tenants/${tenantId}/stock-locations`)
      return result.map((location) => stockLocationSchema.parse(location))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateStockLocation(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: {
      name: string
      code?: string | null
      branch_id?: string | null
      warehouse_id?: string | null
      type?: string
      is_default?: boolean
    }) => stockLocationSchema.parse(await api.post(`/tenants/${tenantId}/stock-locations`, input)),
    onSuccess: invalidate,
  })
}

export function useUpdateStockLocation(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      locationId,
      input,
    }: {
      locationId: string
      input: { is_default?: boolean }
    }) => api.patch<{ id: string; name: string; status: string; is_default: boolean }>(
      `/tenants/${tenantId}/stock-locations/${locationId}`,
      input,
    ),
    onSuccess: invalidate,
  })
}

export function useDeactivateStockLocation(tenantId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (locationId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/stock-locations/${locationId}`),
    onSuccess: invalidate,
  })
}

export function useStockZones(tenantId: string | null | undefined, locationId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stock-locations', locationId, 'zones'],
    queryFn: async () => {
      const result = await api.get<StockZone[]>(
        `/tenants/${tenantId}/stock-locations/${locationId}/zones`,
      )
      return result.map((zone) => stockZoneSchema.parse(zone))
    },
    enabled: Boolean(tenantId && locationId),
    retry: false,
  })
}

export function useCreateStockZone(tenantId: string | undefined, locationId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: { name: string; code?: string | null }) =>
      stockZoneSchema.parse(
        await api.post(`/tenants/${tenantId}/stock-locations/${locationId}/zones`, input),
      ),
    onSuccess: invalidate,
  })
}

export function useStockBins(tenantId: string | null | undefined, zoneId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stock-zones', zoneId, 'bins'],
    queryFn: async () => {
      const result = await api.get<StockBin[]>(`/tenants/${tenantId}/stock-zones/${zoneId}/bins`)
      return result.map((bin) => stockBinSchema.parse(bin))
    },
    enabled: Boolean(tenantId && zoneId),
    retry: false,
  })
}

export function useCreateStockBin(tenantId: string | undefined, zoneId: string | undefined) {
  const invalidate = useTenantStructureInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: { name: string; code?: string | null }) =>
      stockBinSchema.parse(await api.post(`/tenants/${tenantId}/stock-zones/${zoneId}/bins`, input)),
    onSuccess: invalidate,
  })
}

export function useInvitations(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'invitations'],
    queryFn: async () => {
      const result = await api.get<Invitation[]>(`/tenants/${tenantId}/invitations`)
      return result.map((invitation) => invitationSchema.parse(invitation))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateInvitation(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: { email: string; role: string; branch_id?: string | null }) =>
      invitationSchema.parse(await api.post(`/tenants/${tenantId}/invitations`, input)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'invitations'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })
}

export function useRevokeInvitation(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (invitationId: string) =>
      api.post<{ status: string }>(`/tenants/${tenantId}/invitations/${invitationId}/revoke`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'invitations'] })
    },
  })
}

export function useInvitationPreview(token: string | undefined) {
  return useQuery({
    queryKey: ['invitations', 'preview', token],
    queryFn: async () =>
      invitationPreviewSchema.parse(await api.get<InvitationPreview>(`/public/invitations/${token}`)),
    enabled: Boolean(token),
    retry: false,
  })
}

export function useActivateInvitation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({
      token,
      ...input
    }: {
      token: string
      name: string
      password: string
      password_confirmation: string
    }) => authResponseSchema.parse(await api.post(`/public/invitations/${token}/activate`, input)),
    onSuccess: async (result) => {
      setStoredToken(result.token)
      setStoredTenantId(result.user.current_tenant?.id ?? result.user.tenants[0]?.id ?? null)
      await queryClient.invalidateQueries()
    },
  })
}

export function useAcceptInvitation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (token: string) =>
      tenantSchema.parse(await api.post(`/invitations/${token}/accept`)),
    onSuccess: async (tenant) => {
      setStoredTenantId(tenant.id)
      await queryClient.invalidateQueries()
    },
  })
}

export function useTenantRoles(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'roles'],
    queryFn: async () => {
      const result = await api.get<TenantRole[]>(`/tenants/${tenantId}/roles`)
      return result.map((role) => tenantRoleSchema.parse(role))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useMemberDirectory(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'team'],
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<Member[]>(`/tenants/${tenantId}/members`)
      return {
        members: data.map((member) => memberSchema.parse(member)),
        canManage: Boolean(meta?.can_manage),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useUpdateMember(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async ({
      userId,
      roles,
      branch_ids,
    }: {
      userId: string
      roles?: string[]
      branch_ids?: string[]
    }) =>
      memberSchema.parse(
        await api.patch(`/tenants/${tenantId}/members/${userId}`, {
          ...(roles !== undefined ? { roles } : {}),
          ...(branch_ids !== undefined ? { branch_ids } : {}),
        }),
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'team'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'members'] })
      void queryClient.invalidateQueries({ queryKey: ['me'] })
    },
  })
}

export function useRemoveMember(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (userId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/members/${userId}`),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'team'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'members'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
    },
  })
}

export interface ProductInput {
  name: string
  sku: string
  variant_name?: string | null
  barcode?: string | null
  barcode_type?: string | null
  brand?: string | null
  category?: string | null
  category_id?: string | null
  description?: string | null
  base_unit?: string | null
  packaging_unit?: string | null
  packaging_factor?: number | null
  tracking_mode?: string
  status?: string
  supplier_ref?: string | null
  cost_price?: number | null
  attributes?: Record<string, unknown> | null
}

export interface ProductFilters {
  search?: string
  status?: string
  category?: string
  include?: 'variants'
  page?: number
}

function useCatalogueInvalidator(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'catalogue'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'catalogue-imports'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'products'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'product-categories'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'units'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export function useCatalogueSummary(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'catalogue'],
    queryFn: async () =>
      catalogueSummarySchema.parse(await api.get<CatalogueSummary>(`/tenants/${tenantId}/catalogue`)),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useProducts(tenantId: string | null | undefined, filters: ProductFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'products', filters],
    queryFn: async () => {
      const query = new URLSearchParams()
      if (filters.search) query.set('search', filters.search)
      if (filters.status) query.set('status', filters.status)
      if (filters.category) query.set('category', filters.category)
      if (filters.include) query.set('include', filters.include)
      if (filters.page) query.set('page', String(filters.page))
      const { data, meta } = await api.getWithMeta<Product[]>(
        `/tenants/${tenantId}/products?${query.toString()}`,
      )
      return {
        products: data.map((product) => productSchema.parse(product)),
        pagination: paginatedMetaSchema.parse({
          current_page: meta?.current_page ?? 1,
          per_page: meta?.per_page ?? 24,
          total: meta?.total ?? data.length,
          last_page: meta?.last_page ?? 1,
        }),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useProduct(tenantId: string | null | undefined, productId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'products', productId],
    queryFn: async () =>
      productSchema.parse(await api.get<Product>(`/tenants/${tenantId}/products/${productId}`)),
    enabled: Boolean(tenantId && productId),
    retry: false,
  })
}

export function useCreateProduct(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: ProductInput) =>
      productSchema.parse(await api.post(`/tenants/${tenantId}/products`, input)),
    onSuccess: invalidate,
  })
}

export function useUpdateProduct(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({ productId, input }: { productId: string; input: Partial<ProductInput> }) =>
      productSchema.parse(await api.patch(`/tenants/${tenantId}/products/${productId}`, input)),
    onSuccess: invalidate,
  })
}

export function useDiscontinueProduct(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async (productId: string) =>
      api.delete<{ status: string }>(`/tenants/${tenantId}/products/${productId}`),
    onSuccess: invalidate,
  })
}

export function useAddProductBarcode(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      productId,
      barcode,
      type,
      is_primary,
    }: {
      productId: string
      barcode: string
      type?: string
      is_primary?: boolean
    }) =>
      api.post(`/tenants/${tenantId}/products/${productId}/barcodes`, {
        barcode,
        ...(type ? { type } : {}),
        ...(is_primary !== undefined ? { is_primary } : {}),
      }),
    onSuccess: invalidate,
  })
}

export function useRemoveProductBarcode(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({ productId, barcodeId }: { productId: string; barcodeId: string }) =>
      api.delete<{ status: string }>(
        `/tenants/${tenantId}/products/${productId}/barcodes/${barcodeId}`,
      ),
    onSuccess: invalidate,
  })
}

export function useProductCategories(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'product-categories'],
    queryFn: async () => {
      const result = await api.get<ProductCategory[]>(`/tenants/${tenantId}/product-categories`)
      return result.map((category) => productCategorySchema.parse(category))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateProductCategory(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: { name: string; code?: string | null; parent_id?: string | null }) =>
      productCategorySchema.parse(
        await api.post(`/tenants/${tenantId}/product-categories`, input),
      ),
    onSuccess: invalidate,
  })
}

export function useUnitDirectory(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'units'],
    queryFn: async () =>
      unitDirectorySchema.parse(await api.get<UnitDirectory>(`/tenants/${tenantId}/units`)),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateUnit(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: {
      code: string
      name: string
      dimension?: string
      factor_to_base?: number
      is_base?: boolean
    }) => api.post(`/tenants/${tenantId}/units`, input),
    onSuccess: invalidate,
  })
}

export function useCreateUnitConversion(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: { from: string; to: string; factor: number }) =>
      api.post(`/tenants/${tenantId}/units/conversions`, input),
    onSuccess: invalidate,
  })
}

export function useCatalogueImports(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'catalogue-imports'],
    queryFn: async () => {
      const result = await api.get<CatalogueImport[]>(`/tenants/${tenantId}/catalogue/imports`)
      return result.map((item) => catalogueImportSchema.parse(item))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCatalogueImport(
  tenantId: string | null | undefined,
  importId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'catalogue-imports', importId],
    queryFn: async () =>
      catalogueImportSchema.parse(
        await api.get<CatalogueImport>(`/tenants/${tenantId}/catalogue/imports/${importId}`),
      ),
    enabled: Boolean(tenantId && importId),
    retry: false,
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status === 'queued' || status === 'processing' ? 1500 : false
    },
  })
}

export function useUploadCatalogueImport(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      file,
      partialPolicy,
    }: {
      file: File
      partialPolicy?: 'partial' | 'all_or_nothing'
    }) => {
      const form = new FormData()
      form.append('file', file)
      if (partialPolicy) form.append('partial_policy', partialPolicy)
      return catalogueImportSchema.parse(
        await api.upload<CatalogueImport>(`/tenants/${tenantId}/catalogue/imports`, form),
      )
    },
    onSuccess: invalidate,
  })
}

export function useUpdateImportMapping(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({ importId, mapping }: { importId: string; mapping: Record<string, string> }) =>
      catalogueImportSchema.parse(
        await api.patch(`/tenants/${tenantId}/catalogue/imports/${importId}/mapping`, { mapping }),
      ),
    onSuccess: invalidate,
  })
}

export function useCommitCatalogueImport(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      importId,
      strategy,
    }: {
      importId: string
      strategy: 'skip' | 'update'
    }) =>
      api.post<{ import: CatalogueImport; version: Record<string, unknown> }>(
        `/tenants/${tenantId}/catalogue/imports/${importId}/commit`,
        { duplicate_strategy: strategy },
      ),
    onSuccess: invalidate,
  })
}

export function useDeclareCatalogue(tenantId: string | undefined) {
  const invalidate = useCatalogueInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({
      catalogueId,
      declarationText,
      exceptions,
      declarationVersion,
    }: {
      catalogueId: string
      declarationText: string
      exceptions?: string
      declarationVersion?: string
    }) =>
      api.post<{ declaration: CatalogueDeclaration; invoice: CatalogueInvoice | null }>(
        `/tenants/${tenantId}/catalogues/${catalogueId}/declarations`,
        {
          declaration_text: declarationText,
          ...(exceptions ? { exceptions } : {}),
          ...(declarationVersion ? { declaration_version: declarationVersion } : {}),
          confirm_accuracy: true,
        },
      ),
    onSuccess: invalidate,
  })
}

export function usePricingEstimate(tenantId: string | null | undefined, productCount?: number) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pricing-estimate', productCount ?? 'current'],
    queryFn: async () => {
      const query = new URLSearchParams()
      if (productCount !== undefined) query.set('product_count', String(productCount))
      return pricingEstimateSchema.parse(
        await api.get<PricingEstimate>(`/catalogue-pricing/estimate?${query.toString()}`),
      )
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function usePlatformPricing(enabled = true) {
  return useQuery({
    queryKey: ['platform', 'catalogue-pricing'],
    queryFn: async () =>
      platformPricingSchema.parse(await api.get<PlatformPricing>('/platform/catalogue-pricing')),
    enabled,
    retry: false,
  })
}

export interface PricingPolicyInput {
  name?: string
  pricing_model?: string
  currency?: string
  product_count_basis?: string
  base_charge?: number
  included_products?: number
  scale_products?: number
  max_charge?: number
  tax_rate?: number
  notes?: string | null
}

export function useUpdatePricingPolicy() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: async (input: PricingPolicyInput) =>
      pricingPolicySchema.parse(await api.patch('/platform/catalogue-pricing', input)),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'catalogue-pricing'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants'] })
    },
  })
}
