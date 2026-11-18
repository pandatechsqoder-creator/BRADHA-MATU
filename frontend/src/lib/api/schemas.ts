import { z } from 'zod'

export const roleSchema = z.object({
  slug: z.string(),
  name: z.string(),
  scope: z.string(),
  tenant_id: z.string().nullable().optional(),
})
export type Role = z.infer<typeof roleSchema>

export const tenantSummarySchema = z.object({
  id: z.string(),
  name: z.string(),
  status: z.string(),
  operating_mode: z.string(),
  roles: z.array(z.string()).default([]),
})
export type TenantSummary = z.infer<typeof tenantSummarySchema>

export const currentTenantSchema = z.object({
  id: z.string(),
  name: z.string(),
  operating_mode: z.string(),
})
export type CurrentTenant = z.infer<typeof currentTenantSchema>

export const userSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string().email(),
  email_verified_at: z.string().nullable(),
  must_change_password: z.boolean().default(false),
  created_at: z.string(),
  roles: z.array(roleSchema).default([]),
  permissions: z.array(z.string()).default([]),
  tenants: z.array(tenantSummarySchema).default([]),
  current_tenant: currentTenantSchema.nullable().default(null),
})
export type User = z.infer<typeof userSchema>

export const authResponseSchema = z.object({
  user: userSchema,
  token: z.string(),
  verification_test_code: z.string().optional(),
})
export type AuthResponse = z.infer<typeof authResponseSchema>

export const healthSchema = z.object({
  status: z.string(),
  service: z.string(),
  version: z.string(),
  time: z.string(),
  checks: z.record(z.string(), z.string()),
})
export type Health = z.infer<typeof healthSchema>

export const loginInputSchema = z.object({
  email: z.string().email('Enter a valid email address.'),
  password: z.string().min(1, 'Password is required.'),
})
export type LoginInput = z.infer<typeof loginInputSchema>

export const registerInputSchema = z
  .object({
    name: z.string().min(2, 'Name must be at least 2 characters.'),
    email: z.string().email('Enter a valid email address.'),
    password: z
      .string()
      .min(8, 'Password must be at least 8 characters.')
      .regex(/[a-zA-Z]/, 'Password must contain a letter.')
      .regex(/[0-9]/, 'Password must contain a number.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    message: 'Passwords do not match.',
    path: ['password_confirmation'],
  })
export type RegisterInput = z.infer<typeof registerInputSchema>

export const tenantSchema = z.object({
  id: z.string(),
  name: z.string(),
  legal_name: z.string().nullable(),
  business_type: z.string().nullable(),
  industry: z.string().nullable(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  address: z.string().nullable(),
  timezone: z.string(),
  status: z.string(),
  is_default: z.boolean().default(false),
  operating_mode: z.string(),
  members_count: z.number().optional(),
  branches_count: z.number().optional(),
  created_at: z.string().nullable().optional(),
})
export type Tenant = z.infer<typeof tenantSchema>

export const dashboardSchema = z.object({
  workspace: z.enum(['platform', 'tenant', 'workforce', 'onboarding']),
  platform_roles: z.array(z.string()),
  tenant_roles: z.array(z.string()),
  worker_roles: z.array(z.string()),
  tenant: currentTenantSchema
    .extend({ status: z.string() })
    .nullable(),
  stats: z.object({
    accessible_tenants: z.number(),
    tenant_members: z.number(),
    pending_invitations: z.number(),
    unread_notifications: z.number(),
    branches: z.number().default(0),
    catalogue_products: z.number().default(0),
  }),
  onboarding: z.object({
    has_tenant: z.boolean(),
    email_verified: z.boolean(),
    catalogue_ready: z.boolean().default(false),
  }),
})
export type Dashboard = z.infer<typeof dashboardSchema>

export const onboardingStepSchema = z.object({
  id: z.string(),
  title: z.string(),
  required: z.boolean(),
  href: z.string(),
  status: z.enum(['pending', 'completed', 'skipped']),
})

export const onboardingProgressSchema = z.object({
  steps: z.array(onboardingStepSchema),
  completion_percent: z.number(),
  current_step: z.string(),
  recommended_step: onboardingStepSchema.nullable(),
  finished_at: z.string().nullable(),
})
export type OnboardingProgress = z.infer<typeof onboardingProgressSchema>

export const platformOverviewSchema = z.object({
  tenants: z.object({
    total: z.number(),
    active: z.number(),
    pending: z.number(),
    suspended: z.number(),
  }),
  users: z.object({ total: z.number(), verified: z.number() }),
  memberships: z.object({ active: z.number() }),
  audit_events_7d: z.number(),
})
export type PlatformOverview = z.infer<typeof platformOverviewSchema>

export const memberSchema = z.object({
  id: z.string(),
  name: z.string(),
  email: z.string(),
  status: z.string(),
  joined_at: z.string().nullable().optional(),
  roles: z.array(z.object({ slug: z.string(), name: z.string() })).default([]),
  branch_scopes: z.array(z.object({ id: z.string(), name: z.string() })).default([]),
})
export type Member = z.infer<typeof memberSchema>

export const tenantRoleSchema = z.object({
  slug: z.string(),
  name: z.string(),
  description: z.string().nullable().optional(),
})
export type TenantRole = z.infer<typeof tenantRoleSchema>

export const branchSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
  address: z.string().nullable(),
  city: z.string().nullable(),
  phone: z.string().nullable(),
  email: z.string().nullable(),
  timezone: z.string(),
  status: z.string(),
  is_default: z.boolean().default(false),
  departments_count: z.number().default(0),
  warehouses_count: z.number().default(0),
  locations_count: z.number().default(0),
  created_at: z.string().nullable().optional(),
})
export type Branch = z.infer<typeof branchSchema>

export const departmentSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
  status: z.string(),
  branch_id: z.string().nullable(),
  branch_name: z.string().nullable(),
})
export type Department = z.infer<typeof departmentSchema>

export const warehouseSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
  address: z.string().nullable().optional(),
  status: z.string(),
  branch_id: z.string().nullable(),
  branch_name: z.string().nullable(),
  locations_count: z.number().default(0),
})
export type Warehouse = z.infer<typeof warehouseSchema>

export const stockLocationSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
  type: z.string(),
  status: z.string(),
  is_default: z.boolean().default(false),
  branch_id: z.string().nullable(),
  branch_name: z.string().nullable().optional(),
  warehouse_id: z.string().nullable(),
  warehouse_name: z.string().nullable().optional(),
  zones_count: z.number().default(0),
})
export type StockLocation = z.infer<typeof stockLocationSchema>

export const stockZoneSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
  bins_count: z.number().default(0),
})
export type StockZone = z.infer<typeof stockZoneSchema>

export const stockBinSchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable(),
})
export type StockBin = z.infer<typeof stockBinSchema>

export const invitationSchema = z.object({
  id: z.string(),
  email: z.string(),
  role: z.string().nullable(),
  role_name: z.string().nullable(),
  branch_id: z.string().nullable(),
  branch_name: z.string().nullable(),
  status: z.string(),
  expires_at: z.string().nullable(),
  accepted_at: z.string().nullable(),
  created_at: z.string().nullable(),
  token: z.string().optional(),
  accept_url: z.string().optional(),
})
export type Invitation = z.infer<typeof invitationSchema>

export const invitationPreviewSchema = z.object({
  invitation_id: z.string(),
  organisation: z.string().nullable(),
  email: z.string(),
  role: z.string().nullable(),
  branch: z.string().nullable(),
  status: z.string(),
  expires_at: z.string().nullable(),
})
export type InvitationPreview = z.infer<typeof invitationPreviewSchema>

export const auditLogSchema = z.object({
  id: z.string(),
  action: z.string(),
  actor: z.string().nullable(),
  tenant_id: z.number().nullable(),
  tenant: z.string().nullable().optional(),
  subject_type: z.string().nullable(),
  subject_id: z.string().nullable(),
  reason: z.string().nullable(),
  request_id: z.string().nullable(),
  created_at: z.string().nullable(),
})
export type AuditLog = z.infer<typeof auditLogSchema>

export const paginatedMetaSchema = z.object({
  current_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  last_page: z.number(),
})
export type PaginatedMeta = z.infer<typeof paginatedMetaSchema>

export const catalogueVersionSchema = z.object({
  id: z.string(),
  version_number: z.number(),
  status: z.string(),
  product_count: z.number(),
  source: z.string(),
  notes: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
})
export type CatalogueVersion = z.infer<typeof catalogueVersionSchema>

export const catalogueImportSummarySchema = z.object({
  id: z.string(),
  original_name: z.string(),
  status: z.string(),
  total_rows: z.number().default(0),
  valid_rows: z.number().default(0),
  error_rows: z.number().default(0),
  duplicate_rows: z.number().default(0),
  created_at: z.string().nullable().optional(),
})
export type CatalogueImportSummary = z.infer<typeof catalogueImportSummarySchema>

export const catalogueDeclarationSchema = z.object({
  id: z.string(),
  declaration_version: z.string(),
  declaration_text: z.string(),
  exceptions: z.string().nullable(),
  accepted_at: z.string().nullable(),
  declared_by: z.string().nullable(),
  version_number: z.number().nullable().optional(),
  ip_address: z.string().nullable().optional(),
})
export type CatalogueDeclaration = z.infer<typeof catalogueDeclarationSchema>

export const catalogueInvoiceSchema = z.object({
  id: z.string(),
  number: z.string(),
  product_count: z.number(),
  subtotal: z.number(),
  tax: z.number(),
  total: z.number(),
  currency: z.string(),
  status: z.string(),
  issued_at: z.string().nullable().optional(),
})
export type CatalogueInvoice = z.infer<typeof catalogueInvoiceSchema>

export const catalogueSummarySchema = z.object({
  id: z.string().nullable(),
  name: z.string(),
  status: z.string(),
  confirmed: z.boolean(),
  confirmed_at: z.string().nullable(),
  current_version: catalogueVersionSchema.nullable(),
  versions: z.array(catalogueVersionSchema).default([]),
  product_count: z.number(),
  categories_count: z.number(),
  barcodes_count: z.number(),
  latest_import: catalogueImportSummarySchema.nullable(),
  latest_declaration: catalogueDeclarationSchema.nullable(),
  invoice: catalogueInvoiceSchema.nullable(),
  readiness: z.object({
    has_catalogue: z.boolean(),
    has_products: z.boolean(),
    confirmed: z.boolean(),
    ready: z.boolean(),
    reasons: z.array(z.string()).default([]),
  }),
})
export type CatalogueSummary = z.infer<typeof catalogueSummarySchema>

export const productCategorySchema = z.object({
  id: z.string(),
  name: z.string(),
  code: z.string().nullable().optional(),
  status: z.string(),
  products_count: z.number().default(0),
})
export type ProductCategory = z.infer<typeof productCategorySchema>

export const productBarcodeSchema = z.object({
  id: z.string(),
  barcode: z.string(),
  type: z.string(),
  is_primary: z.boolean().default(false),
})
export type ProductBarcode = z.infer<typeof productBarcodeSchema>

export const productVariantSchema = z.object({
  id: z.string(),
  name: z.string().nullable(),
  sku: z.string(),
  status: z.string(),
  base_unit: z.string().nullable(),
  packaging_unit: z.string().nullable(),
  packaging_factor: z.number().nullable(),
  attributes: z.record(z.string(), z.unknown()).default({}),
  barcodes: z.array(productBarcodeSchema).default([]),
})
export type ProductVariant = z.infer<typeof productVariantSchema>

export const productSchema = z.object({
  id: z.string(),
  name: z.string(),
  brand: z.string().nullable(),
  category: z.object({ id: z.string(), name: z.string() }).nullable(),
  description: z.string().nullable().optional(),
  status: z.string(),
  tracking_mode: z.string(),
  supplier_ref: z.string().nullable().optional(),
  source_system: z.string(),
  sku: z.string().nullable(),
  variant_name: z.string().nullable().optional(),
  barcode: z.string().nullable().optional(),
  barcode_type: z.string().nullable().optional(),
  base_unit: z.string().nullable().optional(),
  packaging_unit: z.string().nullable().optional(),
  packaging_factor: z.number().nullable().optional(),
  variants_count: z.number().default(1),
  cost_price: z.number().nullable().optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
  barcodes: z.array(productBarcodeSchema).default([]),
  variants: z.array(productVariantSchema).default([]),
})
export type Product = z.infer<typeof productSchema>

export const catalogueImportRowSchema = z.object({
  row_number: z.number(),
  status: z.string(),
  errors: z.array(z.string()).default([]),
  values: z.record(z.string(), z.string()).default({}),
})
export type CatalogueImportRow = z.infer<typeof catalogueImportRowSchema>

export const catalogueImportSchema = catalogueImportSummarySchema.extend({
  extension: z.string(),
  size: z.number(),
  partial_policy: z.string(),
  error_message: z.string().nullable(),
  committed_version_id: z.string().nullable().optional(),
  headers: z.array(z.string()).default([]),
  mapping: z.record(z.string(), z.string()).default({}),
  canonical_fields: z
    .array(z.object({ field: z.string(), label: z.string() }))
    .default([]),
  rows: z.array(catalogueImportRowSchema).default([]),
})
export type CatalogueImport = z.infer<typeof catalogueImportSchema>

export const unitDirectorySchema = z.object({
  units: z.array(
    z.object({
      id: z.string().nullable(),
      code: z.string(),
      name: z.string(),
      dimension: z.string(),
      factor_to_base: z.number(),
      is_base: z.boolean(),
      custom: z.boolean(),
    }),
  ),
  conversions: z.array(
    z.object({
      id: z.string(),
      from: z.string().nullable(),
      to: z.string().nullable(),
      factor: z.number(),
    }),
  ),
})
export type UnitDirectory = z.infer<typeof unitDirectorySchema>

export const pricingEstimateSchema = z.object({
  policy_id: z.string(),
  pricing_model: z.string(),
  product_count: z.number(),
  product_count_basis: z.string(),
  currency: z.string(),
  base_charge: z.number(),
  included_products: z.number(),
  scale_products: z.number(),
  max_charge: z.number(),
  subtotal: z.number(),
  tax: z.number(),
  total: z.number(),
  explanation: z.string(),
  policy: z
    .object({
      id: z.string(),
      name: z.string(),
      notes: z.string().nullable(),
      effective_from: z.string().nullable(),
      version: z.number(),
    })
    .optional(),
})
export type PricingEstimate = z.infer<typeof pricingEstimateSchema>

export const pricingPolicySchema = z.object({
  id: z.string(),
  name: z.string(),
  pricing_model: z.string(),
  currency: z.string(),
  product_count_basis: z.string(),
  base_charge: z.number(),
  included_products: z.number(),
  scale_products: z.number(),
  max_charge: z.number(),
  tax_rate: z.number(),
  notes: z.string().nullable(),
  effective_from: z.string().nullable(),
  versions: z
    .array(
      z.object({
        id: z.string(),
        version: z.number(),
        rules: z.record(z.string(), z.unknown()),
        effective_from: z.string().nullable(),
      }),
    )
    .default([]),
})
export type PricingPolicy = z.infer<typeof pricingPolicySchema>

export const platformPricingSchema = z.object({
  policy: pricingPolicySchema,
  estimates: z.array(pricingEstimateSchema),
})
export type PlatformPricing = z.infer<typeof platformPricingSchema>

export const scheduleSchema = z.object({
  id: z.string(),
  title: z.string(),
  description: z.string().nullable(),
  mode: z.enum(['reminder', 'proposed', 'prepaid']),
  frequency: z.enum([
    'on_demand',
    'weekly',
    'fortnightly',
    'monthly',
    'quarterly',
    'half_yearly',
    'annual',
    'custom',
  ]),
  scope_type: z.enum(['all', 'category', 'branch', 'custom']),
  scope_config: z.record(z.string(), z.unknown()).nullable().optional(),
  recurrence_config: z.record(z.string(), z.unknown()).nullable().optional(),
  active: z.boolean(),
  next_occurrence_at: z.string().nullable(),
  last_occurrence_at: z.string().nullable(),
  starts_at: z.string().nullable(),
  ends_at: z.string().nullable(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
})
export type Schedule = z.infer<typeof scheduleSchema>

export const scheduleOccurrenceSchema = z.object({
  id: z.string(),
  schedule_id: z.string().optional(),
  tenant_id: z.string().optional(),
  job_id: z.string().nullable().optional(),
  scheduled_at: z.string(),
  status: z.enum(['pending', 'skipped', 'job_created', 'cancelled']),
  skip_reason: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  updated_at: z.string().nullable().optional(),
})
export type ScheduleOccurrence = z.infer<typeof scheduleOccurrenceSchema>

export const scheduleWithOccurrencesSchema = scheduleSchema.extend({
  occurrences: z.array(scheduleOccurrenceSchema),
})
export type ScheduleWithOccurrences = z.infer<typeof scheduleWithOccurrencesSchema>

export const createScheduleInputSchema = z.object({
  title: z.string().min(1, 'Title is required').max(200),
  description: z.string().max(2000).optional(),
  mode: z.enum(['reminder', 'proposed', 'prepaid']),
  frequency: z.enum([
    'on_demand',
    'weekly',
    'fortnightly',
    'monthly',
    'quarterly',
    'half_yearly',
    'annual',
    'custom',
  ]),
  scope_type: z.enum(['all', 'category', 'branch', 'custom']).optional(),
  scope_config: z.record(z.string(), z.unknown()).optional(),
  recurrence_config: z.record(z.string(), z.unknown()).optional(),
  starts_at: z.string().optional(),
  ends_at: z.string().optional(),
})
export type CreateScheduleInput = z.infer<typeof createScheduleInputSchema>

export const updateScheduleInputSchema = z.object({
  title: z.string().max(200).optional(),
  description: z.string().max(2000).optional().nullable(),
  mode: z.enum(['reminder', 'proposed', 'prepaid']).optional(),
  frequency: z.enum([
    'on_demand',
    'weekly',
    'fortnightly',
    'monthly',
    'quarterly',
    'half_yearly',
    'annual',
    'custom',
  ]).optional(),
  active: z.boolean().optional(),
  recurrence_config: z.record(z.string(), z.unknown()).optional().nullable(),
  ends_at: z.string().optional().nullable(),
})
export type UpdateScheduleInput = z.infer<typeof updateScheduleInputSchema>

export const generateOccurrencesInputSchema = z.object({
  count: z.number().int().min(1).max(12).optional().default(3),
})
export type GenerateOccurrencesInput = z.infer<typeof generateOccurrencesInputSchema>

export const skipOccurrenceInputSchema = z.object({
  reason: z.string().min(3, 'Reason must be at least 3 characters').max(500),
})
export type SkipOccurrenceInput = z.infer<typeof skipOccurrenceInputSchema>
