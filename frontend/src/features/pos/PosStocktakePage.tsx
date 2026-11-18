import { useState } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, Select, StatCard } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { ApiError } from '../../lib/api/client'
import {
  useAuthorizeStocktakeJob,
  useCommissionStocktake,
  useDeclineStocktakeJob,
  useFetchPosStocktakeEligibility,
  usePosStocktakeJobs,
} from '../../lib/posStocktake/hooks'

const FREQUENCIES = [
  'on_demand',
  'weekly',
  'fortnightly',
  'monthly',
  'quarterly',
  'half_yearly',
  'annual',
  'custom',
] as const

function toneForStatus(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'commissioned_authorised' || status === 'completed') return 'green'
  if (status === 'commissioned_pending_authorisation') return 'amber'
  if (status === 'cancelled') return 'red'
  return 'gray'
}

export function PosStocktakePage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id

  const eligibilityQuery = useFetchPosStocktakeEligibility(tenantId)
  const jobsQuery = usePosStocktakeJobs(tenantId)
  const commission = useCommissionStocktake(tenantId)
  const authorize = useAuthorizeStocktakeJob(tenantId)
  const decline = useDeclineStocktakeJob(tenantId)

  const [title, setTitle] = useState('')
  const [mode, setMode] = useState<'proposed' | 'reminder'>('proposed')
  const [frequency, setFrequency] = useState<(typeof FREQUENCIES)[number]>('on_demand')
  const [teamSize, setTeamSize] = useState('1')
  const [durationHours, setDurationHours] = useState('4')
  const [posReference, setPosReference] = useState('')
  const [notes, setNotes] = useState('')
  const [declineReasons, setDeclineReasons] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  const eligibility = eligibilityQuery.data
  const jobs = jobsQuery.data ?? []
  const existingOpen = eligibility?.existing_commissioned_jobs ?? 0
  const canCommission = Boolean(eligibility?.can_commission) && existingOpen === 0

  function handleCommission() {
    if (title.trim() === '') return
    setError(null)
    commission.mutate(
      {
        title: title.trim(),
        mode,
        frequency,
        minimum_team_size: Number(teamSize || 1),
        estimated_duration_hours: Number(durationHours || 4),
        pos_reference: posReference.trim() || undefined,
        authorisation_notes: notes.trim() || undefined,
      },
      {
        onSuccess: () => {
          setTitle('')
          setPosReference('')
          setNotes('')
        },
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not commission the stock-take.'),
      },
    )
  }

  function handleAuthorize(jobId: string) {
    setError(null)
    authorize.mutate(jobId, {
      onError: (err) =>
        setError(err instanceof ApiError ? err.message : 'Could not authorise the job.'),
    })
  }

  function handleDecline(jobId: string) {
    const reason = (declineReasons[jobId] ?? '').trim()
    if (reason.length < 3) {
      setError('Enter a decline reason of at least 3 characters.')
      return
    }
    setError(null)
    decline.mutate(
      { jobId, reason },
      {
        onSuccess: () =>
          setDeclineReasons((current) => {
            const next = { ...current }
            delete next[jobId]
            return next
          }),
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not decline the job.'),
      },
    )
  }

  if (eligibilityQuery.isLoading) {
    return <div className="py-16 text-center text-gray-500">Loading POS stock-take eligibility…</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Commission a stock-take from POS"
        description="Raise a stock-taking job from the POS side, then authorise or decline it before work starts."
        actions={
          <Badge tone={canCommission ? 'green' : 'red'}>
            {canCommission ? 'Eligible to commission' : 'Not eligible'}
          </Badge>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Catalogue products"
          value={eligibility?.product_count ?? 0}
          hint={`Minimum ${eligibility?.minimum_required ?? 0} for commissioning`}
        />
        <StatCard
          label="Catalogue ready"
          value={eligibility?.catalogue_readiness ? 'Yes' : 'No'}
          hint={eligibility?.catalogue_readiness ? 'Threshold met' : 'Add more products'}
        />
        <StatCard
          label="Open commissions"
          value={existingOpen}
          hint={existingOpen > 0 ? 'Authorise or decline first' : 'No blocking jobs'}
        />
        <StatCard
          label="Prepaid agreement"
          value={eligibility?.prepaid_eligible ? 'Active' : 'None'}
          hint={eligibility?.prepaid_eligible ? 'Can commission below threshold' : 'Standard commissioning'}
        />
      </div>

      {eligibility && eligibility.blocks.length > 0 && (
        <Alert kind="info">
          Blocking conditions: {eligibility.blocks.join(', ')}.
          {eligibility.has_active_shift ? '' : ' No active cashier shift is open.'}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div className="lg:col-span-4">
          <Card title="New commission">
            <div className="space-y-4">
              <Field
                label="Title"
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                placeholder="Month-end cycle count"
              />
              <Select
                label="Mode"
                value={mode}
                onChange={(event) => setMode(event.target.value as 'proposed' | 'reminder')}
              >
                <option value="proposed">Proposed</option>
                <option value="reminder">Reminder</option>
              </Select>
              <Select
                label="Frequency"
                value={frequency}
                onChange={(event) =>
                  setFrequency(event.target.value as (typeof FREQUENCIES)[number])
                }
              >
                {FREQUENCIES.map((value) => (
                  <option key={value} value={value}>
                    {value.replaceAll('_', ' ')}
                  </option>
                ))}
              </Select>
              <div className="grid grid-cols-2 gap-3">
                <Field
                  label="Team size"
                  type="number"
                  min={1}
                  value={teamSize}
                  onChange={(event) => setTeamSize(event.target.value)}
                />
                <Field
                  label="Duration (hours)"
                  type="number"
                  min={1}
                  value={durationHours}
                  onChange={(event) => setDurationHours(event.target.value)}
                />
              </div>
              <Field
                label="POS reference"
                value={posReference}
                onChange={(event) => setPosReference(event.target.value)}
                placeholder="Optional external reference"
              />
              <Field
                label="Authorisation notes"
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                placeholder="Optional notes for the authoriser"
              />
              <Button
                onClick={handleCommission}
                loading={commission.isPending}
                disabled={!canCommission || title.trim() === ''}
              >
                Commission stock-take
              </Button>
              {!canCommission && (
                <p className="text-xs text-gray-500">
                  Commissioning unlocks when the catalogue threshold is met and no open commission is
                  pending authorisation.
                </p>
              )}
            </div>
          </Card>
        </div>

        <div className="lg:col-span-8">
          <Card title={`POS commissions (${jobs.length})`}>
            {jobs.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500">
                No POS commissions yet — raise one from the form.
              </p>
            ) : (
              <div className="space-y-4">
                {jobs.map((job) => (
                  <div key={job.job_id} className="rounded-lg border border-gray-200 p-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">{job.title}</p>
                        <p className="text-xs text-gray-500">
                          {job.reference} · {job.mode.replaceAll('_', ' ')} ·{' '}
                          {job.frequency.replaceAll('_', ' ')}
                          {job.pos_vendor_reference ? ` · ${job.pos_vendor_reference}` : ''}
                        </p>
                        {job.authorised_by && (
                          <p className="text-xs text-gray-500">
                            Authorised by {job.authorised_by}
                          </p>
                        )}
                        {job.cancellation_reason && (
                          <p className="text-xs text-red-600">
                            Declined: {job.cancellation_reason}
                          </p>
                        )}
                      </div>
                      <Badge tone={toneForStatus(job.status)}>
                        {job.status.replaceAll('_', ' ')}
                      </Badge>
                    </div>

                    {job.status === 'commissioned_pending_authorisation' && (
                      <div className="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3">
                        <Button
                          onClick={() => handleAuthorize(job.job_id)}
                          loading={authorize.isPending}
                        >
                          Authorise
                        </Button>
                        <div className="min-w-[220px] flex-1">
                          <Field
                            label="Decline reason"
                            value={declineReasons[job.job_id] ?? ''}
                            onChange={(event) =>
                              setDeclineReasons((current) => ({
                                ...current,
                                [job.job_id]: event.target.value,
                              }))
                            }
                            placeholder="Why is this commission declined?"
                          />
                        </div>
                        <Button
                          variant="danger"
                          onClick={() => handleDecline(job.job_id)}
                          loading={decline.isPending}
                        >
                          Decline
                        </Button>
                      </div>
                    )}

                    {job.status === 'commissioned_authorised' && (
                      <div className="mt-3 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-3">
                        <div className="min-w-[220px] flex-1">
                          <Field
                            label="Decline reason"
                            value={declineReasons[job.job_id] ?? ''}
                            onChange={(event) =>
                              setDeclineReasons((current) => ({
                                ...current,
                                [job.job_id]: event.target.value,
                              }))
                            }
                            placeholder="Optional cancellation reason"
                          />
                        </div>
                        <Button
                          variant="danger"
                          onClick={() => handleDecline(job.job_id)}
                          loading={decline.isPending}
                        >
                          Cancel commission
                        </Button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
