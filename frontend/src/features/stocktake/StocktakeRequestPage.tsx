import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  PageHeader,
  PageLoader,
  Select,
} from '../../components/ui'
import { useBranches, useCurrentUser, useProductCategories, useStockLocations } from '../../lib/auth/hooks'
import { createTenantJob, submitTenantJob, updateTenantJob, type StocktakeJobInput } from '../../lib/api/stocktake'
import { formatMoney } from '../../lib/formatting'
import { useStocktakeEstimate, useTenantStocktakeJob } from '../../lib/stocktake/hooks'
import { describeError, fromDateInput, splitList, toDateInput } from './shared'

interface WizardValues {
  title: string
  description: string
  job_type: string
  priority: string
  count_method: string
  required_team_size: string
  preferred_start_at: string
  deadline_at: string
  branch_ids: string[]
  stock_location_ids: string[]
  product_scope: 'all' | 'category'
  category_ids: string[]
  safety_requirements: string
  access_requirements: string
  stock_movement_cutoff: string
  required_skills: string
  required_qualifications: string
  required_evidence: string
  custom_instructions: string
  request_declaration_confirmed: boolean
  request_declaration_exceptions: string
}

const emptyValues: WizardValues = {
  title: '',
  description: '',
  job_type: 'full',
  priority: 'normal',
  count_method: 'quantity',
  required_team_size: '1',
  preferred_start_at: '',
  deadline_at: '',
  branch_ids: [],
  stock_location_ids: [],
  product_scope: 'all',
  category_ids: [],
  safety_requirements: '',
  access_requirements: '',
  stock_movement_cutoff: '',
  required_skills: '',
  required_qualifications: '',
  required_evidence: '',
  custom_instructions: '',
  request_declaration_confirmed: false,
  request_declaration_exceptions: '',
}

function checkboxRow(checked: boolean, label: string, onChange: () => void, hint?: string) {
  return (
    <label key={label} className="flex cursor-pointer items-start gap-2 text-sm text-gray-700">
      <input type="checkbox" className="mt-0.5 h-4 w-4 rounded border-gray-300" checked={checked} onChange={onChange} />
      <span>
        {label}
        {hint && <span className="block text-xs text-gray-500">{hint}</span>}
      </span>
    </label>
  )
}

function StepMarker({ index, current, title }: { index: number; current: number; title: string }) {
  const state = index === current ? 'current' : index < current ? 'done' : 'todo'
  return (
    <div className="flex items-center gap-2">
      <span
        className={`flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold ${
          state === 'current'
            ? 'bg-brand-700 text-white'
            : state === 'done'
              ? 'bg-brand-100 text-brand-800'
              : 'bg-gray-100 text-gray-500'
        }`}
      >
        {state === 'done' ? '✓' : index + 1}
      </span>
      <span className={`text-sm ${state === 'todo' ? 'text-gray-500' : 'text-gray-800'}`}>{title}</span>
    </div>
  )
}

function Textarea({
  label,
  value,
  onChange,
  hint,
  rows = 3,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  hint?: string
  rows?: number
}) {
  return (
    <div className="space-y-1">
      <label className="block text-sm font-medium text-gray-700">{label}</label>
      <textarea
        value={value}
        onChange={(event) => onChange(event.target.value)}
        rows={rows}
        className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
      />
      {hint && <p className="text-xs text-gray-500">{hint}</p>}
    </div>
  )
}

function ReadinessPanel({ estimate }: { estimate: ReturnType<typeof useStocktakeEstimate>['data'] }) {
  if (!estimate) return <p className="text-sm text-gray-500">Calculating catalogue readiness…</p>
  if (estimate.catalogue.ready) {
    return (
      <div className="space-y-2 text-sm text-gray-700">
        <div className="flex items-center gap-2">
          <Badge tone="green">Catalogue ready</Badge>
          <span>Version {estimate.catalogue.version_number ?? '—'} · {estimate.catalogue.product_count} products</span>
        </div>
        <p className="text-xs text-gray-500">
          {estimate.counts.skus} SKUs · {estimate.counts.locations} locations · {estimate.counts.branches} branches
        </p>
      </div>
    )
  }
  return (
    <Alert kind="error">
      <p className="font-medium">Finish your catalogue before requesting a stock take.</p>
      <ul className="mt-2 list-disc space-y-1 pl-5">
        {estimate.catalogue.reasons.map((reason) => (
          <li key={reason}>{reason}</li>
        ))}
      </ul>
      <Link to="/business/catalogue" className="mt-2 inline-block font-medium underline">
        Open catalogue →
      </Link>
    </Alert>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card title={title}>
      <div className="space-y-4">{children}</div>
    </Card>
  )
}

export function StocktakeRequestPage({ mode }: { mode: 'create' | 'edit' }) {
  const params = useParams({ strict: false }) as { jobId?: string }
  const jobId = mode === 'edit' ? params.jobId : undefined
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const job = useTenantStocktakeJob(tenantId, jobId)
  const branches = useBranches(tenantId)
  const locations = useStockLocations(tenantId)
  const categories = useProductCategories(tenantId)
  const [step, setStep] = useState(0)
  const [form, setForm] = useState<WizardValues | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (form !== null) return
    if (mode === 'create') {
      setForm(emptyValues)
      return
    }
    if (job.data) {
      const data = job.data
      setForm({
        title: data.title,
        description: data.description ?? '',
        job_type: data.job_type,
        priority: data.priority,
        count_method: data.count_method,
        required_team_size: String(data.required_team_size),
        preferred_start_at: toDateInput(data.preferred_start_at),
        deadline_at: toDateInput(data.deadline_at),
        branch_ids: data.scope.branches.map((branch) => branch.id),
        stock_location_ids: data.scope.locations.map((location) => location.id),
        product_scope: data.product_scope === 'category' ? 'category' : 'all',
        category_ids: data.product_scope_payload ?? [],
        safety_requirements: data.requirements.safety ?? '',
        access_requirements: data.requirements.access ?? '',
        stock_movement_cutoff: data.requirements.stock_movement_cutoff ?? '',
        required_skills: (data.requirements.skills ?? []).join(', '),
        required_qualifications: data.requirements.qualifications ?? '',
        required_evidence: (data.requirements.evidence ?? []).join(', '),
        custom_instructions: data.sop.instructions ?? '',
        request_declaration_confirmed: false,
        request_declaration_exceptions: data.request_declaration?.exceptions ?? '',
      })
    }
  }, [form, job.data, mode])

  const estimateInput = useMemo(() => {
    if (!form) return null
    return {
      job_type: form.job_type,
      priority: form.priority,
      count_method: form.count_method,
      required_team_size: Number(form.required_team_size) || 1,
      product_scope: form.product_scope,
      product_scope_payload: form.product_scope === 'category' ? form.category_ids : [],
      branch_ids: form.branch_ids,
      stock_location_ids: form.stock_location_ids,
    }
  }, [form])

  const estimate = useStocktakeEstimate(tenantId, estimateInput)

  const save = useMutation({
    mutationFn: async (submit: boolean) => {
      if (!tenantId || !form) throw new Error('Select a business first.')
      const payload: StocktakeJobInput = {
        title: form.title.trim(),
        description: form.description.trim() || null,
        job_type: form.job_type,
        priority: form.priority,
        count_method: form.count_method,
        required_team_size: Number(form.required_team_size) || 1,
        preferred_start_at: fromDateInput(form.preferred_start_at),
        deadline_at: fromDateInput(form.deadline_at),
        branch_ids: form.branch_ids,
        stock_location_ids: form.stock_location_ids,
        product_scope: form.product_scope,
        product_scope_payload: form.product_scope === 'category' ? form.category_ids : [],
        safety_requirements: form.safety_requirements.trim() || null,
        access_requirements: form.access_requirements.trim() || null,
        stock_movement_cutoff: form.stock_movement_cutoff.trim() || null,
        required_skills: splitList(form.required_skills),
        required_qualifications: form.required_qualifications.trim() || null,
        required_evidence: splitList(form.required_evidence),
        custom_instructions: form.custom_instructions.trim() || null,
      }
      let saved =
        mode === 'edit' && jobId
          ? await updateTenantJob(tenantId, jobId, payload)
          : await createTenantJob(tenantId, payload)
      if (submit) {
        saved = await submitTenantJob(tenantId, saved.id, {
          confirm_accuracy: form.request_declaration_confirmed,
          exceptions: form.request_declaration_exceptions.trim() || null,
        })
      }
      return saved
    },
    onSuccess: (saved) => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'stocktake-jobs'] })
      void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', saved.id], saved)
      void navigate({ to: '/business/stocktaking/$jobId', params: { jobId: saved.id } })
    },
    onError: (err) => setError(describeError(err)),
  })

  if (isLoading) return <PageLoader label="Loading request…" />
  if (!tenantId) return <Alert kind="error">Select a business before creating a stock-taking request.</Alert>
  if (mode === 'edit' && (job.isLoading || !form)) return <PageLoader label="Loading request…" />
  if (mode === 'edit' && !job.isLoading && !job.data) {
    return <Alert kind="error">This request could not be found.</Alert>
  }
  if (!form) return <PageLoader label="Preparing request…" />

  const set = <K extends keyof WizardValues>(key: K, value: WizardValues[K]) =>
    setForm((current) => (current ? { ...current, [key]: value } : current))

  function toggle(key: 'branch_ids' | 'stock_location_ids' | 'category_ids', value: string) {
    setForm((current) => {
      if (!current) return current
      const selected = current[key]
      const next = selected.includes(value) ? selected.filter((item) => item !== value) : [...selected, value]
      if (key === 'branch_ids') {
        const allowedBranchIds = new Set(next)
        const keptLocations = current.stock_location_ids.filter((locationId) => {
          const location = locations.data?.find((item) => item.id === locationId)
          return !location?.branch_id || allowedBranchIds.has(location.branch_id)
        })
        return { ...current, branch_ids: next, stock_location_ids: keptLocations }
      }
      return { ...current, [key]: next }
    })
  }

  function nextStep(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!form) return
    if (step === 0 && form.title.trim().length < 3) {
      setError('Enter a title of at least 3 characters.')
      return
    }
    setStep((current) => Math.min(current + 1, 2))
  }

  const visibleLocations = (locations.data ?? []).filter(
    (location) => form.branch_ids.length === 0 || !location.branch_id || form.branch_ids.includes(location.branch_id),
  )
  const ready = estimate.data?.catalogue.ready ?? false
  const currency = estimate.data?.estimate.currency ?? 'KES'

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <Link to="/business/stocktaking" className="text-sm font-medium text-brand-700 hover:underline">
          ← Stock-taking jobs
        </Link>
      </div>
      <PageHeader
        title={mode === 'edit' ? `Update ${job.data?.reference ?? 'request'}` : 'New stock-taking request'}
        description="Define the scope, schedule and requirements. BRATHAMATU reviews every request and issues a quote."
      />

      <div className="flex flex-wrap gap-5 rounded-xl border border-gray-200 bg-white px-5 py-3 shadow-sm">
        <StepMarker index={0} current={step} title="Scope & schedule" />
        <StepMarker index={1} current={step} title="Count plan" />
        <StepMarker index={2} current={step} title="Estimate & submit" />
      </div>

      {error && <Alert kind="error">{error}</Alert>}

      <div className="grid gap-6 lg:grid-cols-[1.7fr_1fr]">
        <form onSubmit={nextStep} className="space-y-4">
          {step === 0 && (
            <>
              <Section title="Request details">
                <Field
                  label="Title"
                  name="title"
                  required
                  value={form.title}
                  onChange={(event) => set('title', event.target.value)}
                  hint="e.g. Quarterly stock take — Nairobi CBD store"
                />
                <Textarea
                  label="Description"
                  value={form.description}
                  onChange={(value) => set('description', value)}
                  hint="What should the stock-taking team know before arriving?"
                />
                <div className="grid gap-4 sm:grid-cols-2">
                  <Select label="Job type" name="job_type" value={form.job_type} onChange={(event) => set('job_type', event.target.value)}>
                    <option value="full">Full stock take</option>
                    <option value="cycle">Cycle count</option>
                    <option value="spot">Spot check</option>
                    <option value="recurring">Recurring</option>
                    <option value="on_demand">On demand</option>
                  </Select>
                  <Select label="Priority" name="priority" value={form.priority} onChange={(event) => set('priority', event.target.value)}>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="critical">Critical</option>
                  </Select>
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    label="Preferred start"
                    name="preferred_start_at"
                    type="datetime-local"
                    value={form.preferred_start_at}
                    onChange={(event) => set('preferred_start_at', event.target.value)}
                  />
                  <Field
                    label="Deadline"
                    name="deadline_at"
                    type="datetime-local"
                    value={form.deadline_at}
                    onChange={(event) => set('deadline_at', event.target.value)}
                  />
                </div>
              </Section>

              <Section title="Scope">
                <p className="text-sm text-gray-600">Leave branches empty to include every active stock location.</p>
                <div className="grid gap-2 sm:grid-cols-2">
                  {(branches.data ?? []).map((branch) =>
                    checkboxRow(form.branch_ids.includes(branch.id), branch.name, () => toggle('branch_ids', branch.id), branch.code ?? undefined),
                  )}
                  {(branches.data ?? []).length === 0 && (
                    <p className="text-sm text-gray-500">
                      No branches configured yet.{' '}
                      <Link to="/business/locations" className="font-medium text-brand-700 hover:underline">
                        Add branches and locations →
                      </Link>
                    </p>
                  )}
                </div>
                <div className="max-h-56 space-y-2 overflow-y-auto rounded-lg border border-gray-100 bg-gray-50 p-3">
                  {visibleLocations.map((location) =>
                    checkboxRow(
                      form.stock_location_ids.includes(location.id),
                      location.name,
                      () => toggle('stock_location_ids', location.id),
                      [location.branch_name, location.warehouse_name].filter(Boolean).join(' · ') || undefined,
                    ),
                  )}
                  {visibleLocations.length === 0 && <p className="text-sm text-gray-500">No stock locations available for the selected branches.</p>}
                </div>
              </Section>
            </>
          )}

          {step === 1 && (
            <>
              <Section title="Counting plan">
                <div className="grid gap-4 sm:grid-cols-2">
                  <Select label="Count method" name="count_method" value={form.count_method} onChange={(event) => set('count_method', event.target.value)}>
                    <option value="quantity">Quantity count</option>
                    <option value="barcode">Barcode scan</option>
                    <option value="guided">Guided count</option>
                    <option value="blind">Blind count</option>
                    <option value="manual">Manual sheets</option>
                    <option value="hybrid">Hybrid</option>
                  </Select>
                  <Field
                    label="Team size"
                    name="required_team_size"
                    type="number"
                    min={1}
                    max={500}
                    value={form.required_team_size}
                    onChange={(event) => set('required_team_size', event.target.value)}
                    hint="Stock takers BRATHAMATU should allocate."
                  />
                </div>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Select
                    label="Product scope"
                    name="product_scope"
                    value={form.product_scope}
                    onChange={(event) => {
                      set('product_scope', event.target.value as 'all' | 'category')
                      if (event.target.value === 'all') set('category_ids', [])
                    }}
                  >
                    <option value="all">All catalogue products</option>
                    <option value="category">Selected categories</option>
                  </Select>
                  <Field
                    label="Required skills"
                    name="required_skills"
                    value={form.required_skills}
                    onChange={(event) => set('required_skills', event.target.value)}
                    hint="Comma separated, e.g. barcode counting, forklift"
                  />
                </div>
                {form.product_scope === 'category' && (
                  <div className="space-y-2 rounded-lg border border-gray-100 bg-gray-50 p-3">
                    {(categories.data ?? []).map((category) =>
                      checkboxRow(
                        form.category_ids.includes(category.id),
                        category.name,
                        () => toggle('category_ids', category.id),
                        `${category.products_count} products`,
                      ),
                    )}
                    {(categories.data ?? []).length === 0 && <p className="text-sm text-gray-500">No product categories found.</p>}
                  </div>
                )}
              </Section>

              <Section title="Site requirements">
                <Textarea
                  label="Safety requirements"
                  value={form.safety_requirements}
                  onChange={(value) => set('safety_requirements', value)}
                  hint="PPE, restricted areas, working at height."
                />
                <Textarea
                  label="Access requirements"
                  value={form.access_requirements}
                  onChange={(value) => set('access_requirements', value)}
                  hint="Keys, gate codes, escort requirements."
                />
                <Textarea
                  label="Stock movement cut-off"
                  value={form.stock_movement_cutoff}
                  onChange={(value) => set('stock_movement_cutoff', value)}
                  hint="Freeze sales or transfers during the count?"
                />
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field
                    label="Required qualifications"
                    name="required_qualifications"
                    value={form.required_qualifications}
                    onChange={(event) => set('required_qualifications', event.target.value)}
                  />
                  <Field
                    label="Required evidence"
                    name="required_evidence"
                    value={form.required_evidence}
                    onChange={(event) => set('required_evidence', event.target.value)}
                    hint="Comma separated, e.g. signed count sheets, photos"
                  />
                </div>
                <Textarea
                  label="Special instructions"
                  value={form.custom_instructions}
                  onChange={(value) => set('custom_instructions', value)}
                  hint="Anything the stock-taking team must follow on site."
                />
              </Section>
            </>
          )}

          {step === 2 && (
            <>
              <Section title="Request summary">
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                  {[
                    ['Title', form.title || '—'],
                    ['Job type', form.job_type],
                    ['Priority', form.priority],
                    ['Count method', form.count_method],
                    ['Team size', form.required_team_size || '1'],
                    ['Preferred start', form.preferred_start_at.replace('T', ' ') || 'Not set'],
                    ['Deadline', form.deadline_at.replace('T', ' ') || 'Not set'],
                    ['Scope', estimate.data?.scope.summary.label ?? 'All stock locations'],
                  ].map(([label, value]) => (
                    <div key={label} className="flex justify-between gap-4">
                      <dt className="text-gray-500">{label}</dt>
                      <dd className="text-right font-medium text-gray-900">{value}</dd>
                    </div>
                  ))}
                </dl>
                {form.description && <p className="rounded-lg bg-gray-50 p-3 text-sm text-gray-600">{form.description}</p>}
              </Section>

              <Section title="Request-specific declaration">
                <p className="text-sm text-gray-700">
                  I confirm that this request’s scope, selected locations, catalogue version, product selection and stock movement cut-off are accurate and authorized as at submission.
                </p>
                <Textarea
                  label="Exceptions or exclusions"
                  value={form.request_declaration_exceptions}
                  onChange={(value) => set('request_declaration_exceptions', value)}
                  hint="Record any known gaps, excluded products, or scope exceptions for this request."
                />
                <label className="flex items-start gap-2 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    className="mt-0.5 h-4 w-4 rounded border-gray-300"
                    checked={form.request_declaration_confirmed}
                    onChange={(event) => set('request_declaration_confirmed', event.target.checked)}
                  />
                  <span>I am authorized to declare this specific stock-taking request accurate on behalf of this business.</span>
                </label>
              </Section>

              <div className="flex flex-wrap gap-3">
                <Button
                  type="button"
                  variant="secondary"
                  loading={save.isPending && save.variables === false}
                  disabled={!ready || !form.request_declaration_confirmed || save.isPending}
                  onClick={() => {
                    setError(null)
                    save.mutate(false)
                  }}
                >
                  Save draft
                </Button>
                <Button
                  type="button"
                  loading={save.isPending && save.variables === true}
                  disabled={!ready || save.isPending}
                  onClick={() => {
                    setError(null)
                    save.mutate(true)
                  }}
                >
                  Submit request
                </Button>
              </div>
              <p className="text-xs text-gray-500">
                Submitting locks the request for review. BRATHAMATU may ask for clarification and will issue a quote.
              </p>
            </>
          )}

          <div className="flex gap-2">
            {step > 0 && (
              <Button type="button" variant="secondary" onClick={() => setStep((current) => current - 1)}>
                Back
              </Button>
            )}
            {step < 2 && (
              <Button type="submit" disabled={!estimate.data?.catalogue.ready}>
                Continue
              </Button>
            )}
          </div>
        </form>

        <div className="space-y-4">
          <Card title="Catalogue readiness">
            <ReadinessPanel estimate={estimate.data} />
          </Card>

          <Card title="Provisional estimate">
            {estimate.isLoading && <PageLoader label="Calculating…" />}
            {estimate.isError && <Alert kind="error">The estimate could not be calculated.</Alert>}
            {estimate.data && (
              <div className="space-y-3 text-sm">
                <ul className="space-y-2">
                  {estimate.data.estimate.lines.map((line) => (
                    <li key={line.label} className="flex justify-between gap-3 text-gray-600">
                      <span>{line.label}</span>
                      <span>{formatMoney(line.amount, currency)}</span>
                    </li>
                  ))}
                </ul>
                <div className="flex justify-between border-t border-gray-100 pt-2 font-medium text-gray-900">
                  <span>Tax ({estimate.data.estimate.tax_rate}%)</span>
                  <span>{formatMoney(estimate.data.estimate.tax, currency)}</span>
                </div>
                <div className="flex justify-between text-base font-semibold text-gray-900">
                  <span>Provisional total</span>
                  <span>{formatMoney(estimate.data.estimate.total, currency)}</span>
                </div>
                <div className="rounded-lg bg-brand-50 p-3 text-sm text-brand-800">
                  <p className="font-medium">Deposit required</p>
                  <p className="text-lg font-semibold">{formatMoney(estimate.data.deposit.amount, currency)}</p>
                  <p className="mt-1 text-xs">{estimate.data.deposit.summary}</p>
                </div>
                <p className="text-xs text-gray-500">
                  This is a provisional estimate. BRATHAMATU confirms the final charge in a formal quote after review.
                </p>
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
