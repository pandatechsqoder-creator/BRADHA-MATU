import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  PageHeader,
  PageLoader,
  Select,
  StatCard,
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { useMarketingPages } from '../../lib/marketing/hooks'
import {
  useAddCampaignAsset,
  useCampaign,
  useCampaignAction,
  useCampaigns,
  useCreateCampaign,
  useRecordCampaignResults,
  useRemoveCampaignAsset,
  useUpdateCampaign,
  type Campaign,
  type CampaignAction,
  type CampaignInput,
  type CampaignResults,
} from '../../lib/marketing/campaignHooks'

const statuses = ['draft', 'review', 'approved', 'rejected', 'active', 'paused', 'completed', 'cancelled'] as const

const objectives = [
  ['lead_generation', 'Lead generation'],
  ['pos_demos', 'POS demos'],
  ['stocktake_requests', 'Stock-taking requests'],
  ['brand_awareness', 'Brand awareness'],
  ['retention', 'Retention'],
  ['other', 'Other'],
] as const

const channels = [
  ['website', 'Website'],
  ['email', 'Email'],
  ['sms', 'SMS'],
  ['whatsapp', 'WhatsApp'],
  ['facebook', 'Facebook organic'],
  ['instagram', 'Instagram organic'],
  ['meta_ads', 'Meta ads'],
  ['google_ads', 'Google ads'],
  ['referral', 'Referral'],
] as const

const emptyForm = {
  name: '',
  objective: 'stocktake_requests',
  channel: 'website',
  landingPageId: '',
  budget: '',
  currency: 'GBP',
  startsAt: '',
  endsAt: '',
  audienceSummary: '',
  utmSource: '',
  utmMedium: '',
  utmCampaign: '',
}

const emptyResults = { leads: '', conversions: '', spend: '', notes: '' }

function toneFor(status: string): 'green' | 'red' | 'amber' | 'blue' | 'gray' {
  switch (status) {
    case 'active':
    case 'approved':
    case 'completed':
      return 'green'
    case 'rejected':
      return 'red'
    case 'review':
    case 'paused':
      return 'amber'
    case 'draft':
      return 'gray'
    default:
      return 'blue'
  }
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—'
}

function money(campaign: Campaign): string {
  if (campaign.budget_amount === null) return 'No budget set'
  return `${campaign.budget_currency} ${campaign.budget_amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function toLocalInput(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export function CampaignsPage() {
  const user = useCurrentUser().data
  const canManage = user?.permissions.includes('campaigns.manage') ?? false
  const canApprove = user?.permissions.includes('campaigns.approve') ?? false
  const canSpend = user?.permissions.includes('campaigns.spend') ?? false

  const [filter, setFilter] = useState('')
  const campaigns = useCampaigns({ status: filter || undefined })
  const pages = useMarketingPages({ status: 'published' })
  const createCampaign = useCreateCampaign()
  const updateCampaign = useUpdateCampaign()
  const action = useCampaignAction()
  const addAsset = useAddCampaignAsset()
  const removeAsset = useRemoveCampaignAsset()
  const recordResults = useRecordCampaignResults()

  const [form, setForm] = useState(emptyForm)
  const [editingId, setEditingId] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [assetHeadline, setAssetHeadline] = useState('')
  const [results, setResults] = useState(emptyResults)
  const detail = useCampaign(selectedId)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const editing = campaigns.data?.campaigns.find((campaign) => campaign.id === editingId) ?? null
  const busy = createCampaign.isPending || updateCampaign.isPending || action.isPending || addAsset.isPending || removeAsset.isPending || recordResults.isPending

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function resetForm() {
    setEditingId(null)
    setForm(emptyForm)
  }

  function startEdit(campaign: Campaign) {
    setEditingId(campaign.id)
    setForm({
      name: campaign.name,
      objective: campaign.objective,
      channel: campaign.channel,
      landingPageId: campaign.landing_page?.id ?? '',
      budget: campaign.budget_amount !== null ? String(campaign.budget_amount) : '',
      currency: campaign.budget_currency,
      startsAt: toLocalInput(campaign.starts_at),
      endsAt: toLocalInput(campaign.ends_at),
      audienceSummary: campaign.audience.summary ?? '',
      utmSource: campaign.utm.source ?? '',
      utmMedium: campaign.utm.medium ?? '',
      utmCampaign: campaign.utm.campaign ?? '',
    })
    setError(null)
    setNotice(null)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  function saveCampaign() {
    setError(null)
    setNotice(null)

    if (form.name.trim() === '') {
      setError('Give the campaign a name before saving.')
      return
    }

    if (form.startsAt && form.endsAt && new Date(form.endsAt) < new Date(form.startsAt)) {
      setError('The end date must be after the start date.')
      return
    }

    const input: CampaignInput = {
      name: form.name.trim(),
      objective: form.objective,
      channel: form.channel,
      landing_page_id: form.landingPageId || null,
      budget_amount: form.budget.trim() === '' ? null : Number(form.budget),
      budget_currency: form.currency,
      starts_at: form.startsAt ? new Date(form.startsAt).toISOString() : null,
      ends_at: form.endsAt ? new Date(form.endsAt).toISOString() : null,
      audience: form.audienceSummary.trim() === ''
        ? null
        : { summary: form.audienceSummary.trim() },
      utm: {
        source: form.utmSource.trim() || undefined,
        medium: form.utmMedium.trim() || undefined,
        campaign: form.utmCampaign.trim() || undefined,
      },
    }

    const onSuccess = (saved: Campaign) => {
      setNotice(editingId ? 'Campaign updated and returned to draft.' : 'Draft campaign saved. Add creative, then submit it for approval.')
      resetForm()
      setSelectedId(saved.id)
    }

    if (editingId) {
      updateCampaign.mutate(
        { id: editingId, input },
        { onSuccess, onError: (err) => report(err, 'Could not update the campaign.') },
      )
    } else {
      createCampaign.mutate(input, {
        onSuccess,
        onError: (err) => report(err, 'Could not save the campaign.'),
      })
    }
  }

  function runAction(campaign: Campaign, next: CampaignAction, payload?: Record<string, unknown>, success?: string) {
    setError(null)
    setNotice(null)
    action.mutate(
      { id: campaign.id, action: next, payload },
      {
        onSuccess: () => {
          setNotice(success ?? `Campaign ${next.replace('-', ' ')}d.`)
          setSelectedId(campaign.id)
        },
        onError: (err) => report(err, 'The campaign action failed.'),
      },
    )
  }

  function approve(campaign: Campaign) {
    const notes = window.prompt('Approval note (optional). The campaign owner will see this.')
    if (notes === null) return
    runAction(campaign, 'approve', { notes: notes.trim() || null }, 'Campaign approved. It can now be activated by a user with spend permission.')
  }

  function reject(campaign: Campaign) {
    const notes = window.prompt('Why is this campaign being rejected? The owner will see this reason.')
    if (notes === null) return
    if (notes.trim() === '') {
      setError('A rejection reason is required.')
      return
    }
    runAction(campaign, 'reject', { notes: notes.trim() }, 'Campaign rejected and returned to the owner.')
  }

  function activate(campaign: Campaign) {
    if (!window.confirm(`Activate “${campaign.name}” with a budget of ${money(campaign)}? Activation records that spend is authorised; no provider spend is changed automatically.`)) return
    runAction(campaign, 'activate', {}, 'Campaign activated.')
  }

  function addCreative() {
    if (!selectedId || assetHeadline.trim() === '') {
      setError('Write a short creative headline before adding it.')
      return
    }
    setError(null)
    addAsset.mutate(
      { id: selectedId, input: { type: 'copy', content: { headline: assetHeadline.trim() } } },
      {
        onSuccess: () => {
          setNotice('Creative added to the campaign.')
          setAssetHeadline('')
        },
        onError: (err) => report(err, 'Could not add creative. The campaign may already be approved.'),
      },
    )
  }

  function saveResults() {
    if (!detail.data) return
    const payload: CampaignResults = {}
    if (results.leads.trim() !== '') payload.leads = Number(results.leads)
    if (results.conversions.trim() !== '') payload.conversions = Number(results.conversions)
    if (results.spend.trim() !== '') payload.spend = Number(results.spend)
    if (results.notes.trim() !== '') payload.notes = results.notes.trim()

    recordResults.mutate(
      { id: detail.data.id, results: payload },
      {
        onSuccess: () => {
          setNotice('Results recorded as manually verified figures.')
          setResults(emptyResults)
        },
        onError: (err) => report(err, 'Could not record results.'),
      },
    )
  }

  if (campaigns.isLoading) return <PageLoader label="Loading campaigns…" />

  if (campaigns.isError) {
    return <Alert kind="error">{(campaigns.error as ApiError).message}</Alert>
  }

  const counts = campaigns.data?.counts ?? {}
  const visible = campaigns.data?.campaigns ?? []
  const publishedPages = pages.data?.pages ?? []

  return (
    <div className="space-y-6" data-testid="campaigns-page">
      <PageHeader
        title="Campaigns"
        description="Plan owned campaigns with audiences, budgets, landing pages and UTMs. Nothing activates without approval, and activation itself requires spend permission."
        actions={
          editingId ? (
            <Button variant="secondary" onClick={resetForm} data-testid="campaign-cancel-edit">
              Cancel edit
            </Button>
          ) : undefined
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard label="Draft" value={counts.draft ?? 0} />
        <StatCard label="Awaiting approval" value={counts.review ?? 0} />
        <StatCard label="Approved" value={counts.approved ?? 0} />
        <StatCard label="Active" value={counts.active ?? 0} />
      </div>

      {canManage && (
        <Card title={editingId ? `Edit “${editing?.name ?? 'campaign'}”` : 'New campaign'}>
          <div className="space-y-4" data-testid="campaign-form">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field
                label="Campaign name"
                value={form.name}
                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                placeholder="Autumn stock-take campaign"
                data-testid="campaign-name"
              />
              <Select
                label="Objective"
                value={form.objective}
                onChange={(event) => setForm((current) => ({ ...current, objective: event.target.value }))}
                data-testid="campaign-objective"
              >
                {objectives.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </Select>
              <Select
                label="Channel"
                value={form.channel}
                onChange={(event) => setForm((current) => ({ ...current, channel: event.target.value }))}
                data-testid="campaign-channel"
              >
                {channels.map(([value, label]) => (
                  <option key={value} value={value}>{label}</option>
                ))}
              </Select>
              <Select
                label="Landing page"
                value={form.landingPageId}
                onChange={(event) => setForm((current) => ({ ...current, landingPageId: event.target.value }))}
                hint={publishedPages.length === 0 ? 'Publish a page first to attach a landing page.' : 'The UTM URL is built from this published page.'}
                data-testid="campaign-landing-page"
              >
                <option value="">No landing page</option>
                {publishedPages.map((page) => (
                  <option key={page.id} value={page.id}>{page.title} (/{page.effective_slug === 'home' ? '' : page.effective_slug})</option>
                ))}
              </Select>
              <Field
                label="Budget"
                type="number"
                min="0"
                step="0.01"
                value={form.budget}
                onChange={(event) => setForm((current) => ({ ...current, budget: event.target.value }))}
                placeholder="1500"
                data-testid="campaign-budget"
              />
              <Field
                label="Currency"
                value={form.currency}
                maxLength={3}
                onChange={(event) => setForm((current) => ({ ...current, currency: event.target.value.toUpperCase() }))}
                data-testid="campaign-currency"
              />
              <Field
                label="Starts"
                type="datetime-local"
                value={form.startsAt}
                onChange={(event) => setForm((current) => ({ ...current, startsAt: event.target.value }))}
                data-testid="campaign-starts-at"
              />
              <Field
                label="Ends"
                type="datetime-local"
                value={form.endsAt}
                onChange={(event) => setForm((current) => ({ ...current, endsAt: event.target.value }))}
                data-testid="campaign-ends-at"
              />
            </div>

            <Field
              label="Audience summary"
              value={form.audienceSummary}
              onChange={(event) => setForm((current) => ({ ...current, audienceSummary: event.target.value }))}
              placeholder="Independent retailers and supermarkets with 1–10 branches"
              hint="Describe the audience honestly. No purchased or inferred personal data."
              data-testid="campaign-audience"
            />

            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
              <Field
                label="UTM source"
                value={form.utmSource}
                onChange={(event) => setForm((current) => ({ ...current, utmSource: event.target.value }))}
                placeholder="google"
                data-testid="campaign-utm-source"
              />
              <Field
                label="UTM medium"
                value={form.utmMedium}
                onChange={(event) => setForm((current) => ({ ...current, utmMedium: event.target.value }))}
                placeholder="cpc"
                data-testid="campaign-utm-medium"
              />
              <Field
                label="UTM campaign"
                value={form.utmCampaign}
                onChange={(event) => setForm((current) => ({ ...current, utmCampaign: event.target.value }))}
                placeholder="autumn-stocktake"
                hint="Leave blank to use the campaign name."
                data-testid="campaign-utm-campaign"
              />
            </div>

            <div className="flex flex-wrap gap-2">
              <Button onClick={saveCampaign} loading={createCampaign.isPending || updateCampaign.isPending} disabled={busy && !(createCampaign.isPending || updateCampaign.isPending)} data-testid="campaign-save-draft">
                {editingId ? 'Save changes' : 'Save draft'}
              </Button>
            </div>
          </div>
        </Card>
      )}

      <Card
        title="Campaigns"
        actions={
          <Select
            label="Filter"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            data-testid="campaign-status-filter"
          >
            <option value="">All statuses</option>
            {statuses.map((status) => (
              <option key={status} value={status}>{status}</option>
            ))}
          </Select>
        }
      >
        <div data-testid="campaigns-table">
          <Table
            headers={['Campaign', 'Status', 'Budget', 'Approval', 'Actions']}
            empty={<EmptyState title="No campaigns yet" description="Create a draft above to plan your first campaign." />}
          >
            {visible.map((campaign) => {
              const latestApproval = campaign.approvals?.[0]
              return (
                <tr key={campaign.id} data-testid={`campaign-row-${campaign.id}`}>
                  <td className="max-w-md px-4 py-3">
                    <button
                      type="button"
                      className="text-left font-medium text-gray-900 underline-offset-2 hover:underline"
                      onClick={() => setSelectedId(campaign.id === selectedId ? null : campaign.id)}
                      data-testid={`campaign-details-${campaign.id}`}
                    >
                      {campaign.name}
                    </button>
                    <p className="text-xs capitalize text-gray-500">{campaign.objective.replaceAll('_', ' ')} · {campaign.channel.replaceAll('_', ' ')}</p>
                    {campaign.utm_url && (
                      <p className="truncate text-xs text-brand-700" data-testid={`campaign-utm-url-${campaign.id}`}>{campaign.utm_url}</p>
                    )}
                  </td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <span data-testid={`campaign-status-${campaign.id}`}>
                      <Badge tone={toneFor(campaign.status)}>{campaign.status}</Badge>
                    </span>
                  </td>
                  <td className="whitespace-nowrap px-4 py-3 text-xs text-gray-600">
                    <p>{money(campaign)}</p>
                    <p>{formatDate(campaign.starts_at)}</p>
                  </td>
                  <td className="px-4 py-3 text-xs text-gray-600">
                    {campaign.approved_at ? (
                      <p>Approved by {campaign.approver ?? 'approver'} · {formatDate(campaign.approved_at)}</p>
                    ) : latestApproval ? (
                      <p className="capitalize">{latestApproval.decision}</p>
                    ) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {(campaign.status === 'draft' || campaign.status === 'rejected') && canManage && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => startEdit(campaign)} data-testid={`campaign-edit-${campaign.id}`}>
                            Edit
                          </Button>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => runAction(campaign, 'submit-review', {}, 'Campaign submitted for approval.')} data-testid={`campaign-submit-${campaign.id}`}>
                            Submit for approval
                          </Button>
                        </>
                      )}
                      {campaign.status === 'review' && canApprove && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => approve(campaign)} data-testid={`campaign-approve-${campaign.id}`}>
                            Approve
                          </Button>
                          <Button variant="danger" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => reject(campaign)} data-testid={`campaign-reject-${campaign.id}`}>
                            Reject
                          </Button>
                        </>
                      )}
                      {campaign.status === 'review' && !canApprove && <span className="text-xs text-gray-500">Awaiting approver</span>}
                      {campaign.status === 'approved' && (
                        canSpend ? (
                          <Button variant="primary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => activate(campaign)} data-testid={`campaign-activate-${campaign.id}`}>
                            Activate
                          </Button>
                        ) : (
                          <span className="text-xs text-gray-500" data-testid={`campaign-spend-required-${campaign.id}`}>Spend permission required</span>
                        )
                      )}
                      {campaign.status === 'active' && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => runAction(campaign, 'pause', {}, 'Campaign paused.')} data-testid={`campaign-pause-${campaign.id}`}>
                            Pause
                          </Button>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => runAction(campaign, 'complete', {}, 'Campaign completed.')} data-testid={`campaign-complete-${campaign.id}`}>
                            Complete
                          </Button>
                        </>
                      )}
                      {campaign.status === 'paused' && (
                        <>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => runAction(campaign, 'resume', {}, 'Campaign resumed.')} data-testid={`campaign-resume-${campaign.id}`}>
                            Resume
                          </Button>
                          <Button variant="secondary" className="!px-2 !py-1 text-xs" disabled={busy} onClick={() => runAction(campaign, 'complete', {}, 'Campaign completed.')} data-testid={`campaign-complete-${campaign.id}`}>
                            Complete
                          </Button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              )
            })}
          </Table>
        </div>
      </Card>

      {selectedId && (
        <div data-testid="campaign-detail-panel">
          <Card title="Campaign detail">
            {detail.isLoading && <PageLoader label="Loading campaign detail…" />}
            {detail.isError && <Alert kind="error">{(detail.error as ApiError).message}</Alert>}
            {detail.data && (
              <div className="space-y-5">
              <div className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                  <p className="text-xs uppercase tracking-wide text-gray-500">Budget</p>
                  <p className="font-medium text-gray-900">{money(detail.data)}</p>
                </div>
                <div>
                  <p className="text-xs uppercase tracking-wide text-gray-500">Window</p>
                  <p className="font-medium text-gray-900">{formatDate(detail.data.starts_at)} → {formatDate(detail.data.ends_at)}</p>
                </div>
                <div>
                  <p className="text-xs uppercase tracking-wide text-gray-500">Owner</p>
                  <p className="font-medium text-gray-900">{detail.data.owner ?? '—'}</p>
                </div>
                <div>
                  <p className="text-xs uppercase tracking-wide text-gray-500">Audience</p>
                  <p className="font-medium text-gray-900">{detail.data.audience.summary ?? 'Not specified'}</p>
                </div>
              </div>

              {detail.data.utm_url && (
                <div className="rounded-lg bg-gray-50 p-3 text-sm">
                  <p className="text-xs uppercase tracking-wide text-gray-500">Tracking URL</p>
                  <p className="break-all text-brand-700" data-testid="campaign-detail-utm-url">{detail.data.utm_url}</p>
                </div>
              )}

              <div>
                <h3 className="text-sm font-semibold text-gray-900">Creative</h3>
                <ul className="mt-2 space-y-2 text-sm text-gray-700">
                  {(detail.data.assets ?? []).map((asset) => (
                    <li key={asset.id} className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2">
                      <span>
                        <span className="font-medium capitalize">{asset.type}</span>
                        {typeof asset.content.headline === 'string' && <span className="text-gray-600"> · {asset.content.headline}</span>}
                        {asset.media_asset && <span className="text-gray-500"> · {asset.media_asset.filename}</span>}
                      </span>
                      {canManage && ['draft', 'rejected', 'review'].includes(detail.data!.status) && (
                        <Button
                          variant="danger"
                          className="!px-2 !py-1 text-xs"
                          disabled={busy}
                          onClick={() => removeAsset.mutate({ id: detail.data!.id, assetId: asset.id }, { onError: (err) => report(err, 'Could not remove creative.') })}
                        >
                          Remove
                        </Button>
                      )}
                    </li>
                  ))}
                  {(detail.data.assets ?? []).length === 0 && <li className="text-gray-500">No creative attached yet.</li>}
                </ul>
                {canManage && ['draft', 'rejected', 'review'].includes(detail.data.status) && (
                  <div className="mt-3 flex flex-wrap items-end gap-2">
                    <div className="min-w-64 flex-1">
                      <Field
                        label="Add copy headline"
                        value={assetHeadline}
                        onChange={(event) => setAssetHeadline(event.target.value)}
                        placeholder="Count every shelf before year-end"
                        data-testid="campaign-asset-headline"
                      />
                    </div>
                    <Button variant="secondary" onClick={addCreative} loading={addAsset.isPending} disabled={busy && !addAsset.isPending} data-testid="campaign-add-asset">
                      Add creative
                    </Button>
                  </div>
                )}
              </div>

              <div>
                <h3 className="text-sm font-semibold text-gray-900">Approvals</h3>
                <div className="mt-2 space-y-1 text-sm text-gray-700" data-testid="campaign-approvals">
                  {(detail.data.approvals ?? []).map((approval, index) => (
                    <p key={`${approval.created_at}-${index}`}>
                      <span className="capitalize">{approval.decision}</span>
                      {approval.decided_by ? ` · ${approval.decided_by}` : ''}
                      {approval.notes ? ` · ${approval.notes}` : ''}
                    </p>
                  ))}
                  {(detail.data.approvals ?? []).length === 0 && <p className="text-gray-500">No approval requests yet.</p>}
                </div>
              </div>

              <div>
                <h3 className="text-sm font-semibold text-gray-900">Change history</h3>
                <ol className="mt-2 space-y-1 text-sm text-gray-700" data-testid="campaign-revisions">
                  {(detail.data.revisions ?? []).map((revision) => (
                    <li key={revision.revision_no}>
                      <span className="text-gray-500">#{revision.revision_no}</span> {revision.change_summary ?? 'Changed'}
                      {revision.status ? ` · ${revision.status}` : ''}
                      <span className="text-gray-500"> · {formatDate(revision.created_at)}</span>
                    </li>
                  ))}
                </ol>
              </div>

              {canManage && ['active', 'paused', 'completed'].includes(detail.data.status) && (
                <div>
                  <h3 className="text-sm font-semibold text-gray-900">Verified results</h3>
                  <p className="mt-1 text-xs text-gray-500">Enter manually verified figures only. Provider metrics appear in reporting when integrations are connected.</p>
                  <div className="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <Field label="Leads" type="number" min="0" value={results.leads} onChange={(event) => setResults((current) => ({ ...current, leads: event.target.value }))} data-testid="campaign-results-leads" />
                    <Field label="Conversions" type="number" min="0" value={results.conversions} onChange={(event) => setResults((current) => ({ ...current, conversions: event.target.value }))} data-testid="campaign-results-conversions" />
                    <Field label="Spend" type="number" min="0" step="0.01" value={results.spend} onChange={(event) => setResults((current) => ({ ...current, spend: event.target.value }))} data-testid="campaign-results-spend" />
                  </div>
                  <div className="mt-3 flex flex-wrap items-end gap-2">
                    <div className="min-w-64 flex-1">
                      <Field label="Notes" value={results.notes} onChange={(event) => setResults((current) => ({ ...current, notes: event.target.value }))} placeholder="Source of the figures" data-testid="campaign-results-notes" />
                    </div>
                    <Button onClick={saveResults} loading={recordResults.isPending} disabled={busy && !recordResults.isPending} data-testid="campaign-save-results">
                      Record results
                    </Button>
                  </div>
                  {Object.keys(detail.data.results ?? {}).length > 0 && (
                    <p className="mt-2 text-sm text-gray-700" data-testid="campaign-results-summary">
                      Recorded: {Object.entries(detail.data.results).map(([key, value]) => `${key} ${value}`).join(' · ')}
                    </p>
                  )}
                </div>
              )}
            </div>
            )}
          </Card>
        </div>
      )}
    </div>
  )
}
