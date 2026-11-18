import { Link, useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import {
  LEAD_ACTIVITY_TYPES,
  LEAD_STATUSES,
  leadStatusLabels,
  leadTypeLabels,
  useAddLeadActivity,
  useAssignLead,
  useLead,
  useLeadAssignees,
  useRecordLeadConsent,
  useUpdateLeadStatus,
  type LeadStatus,
} from '../../lib/marketing/leadHooks'

const statusTone: Record<LeadStatus, 'gray' | 'green' | 'red' | 'amber'> = {
  new: 'amber',
  assigned: 'gray',
  contacted: 'gray',
  qualified: 'green',
  quote_sent: 'green',
  follow_up: 'amber',
  won: 'green',
  lost: 'red',
  archived: 'gray',
}

export function LeadDetailPage() {
  const { leadId } = useParams({ from: '/platform/marketing/leads/$leadId' })
  const { data: user } = useCurrentUser()
  const canManage = user?.permissions.includes('leads.manage') ?? false
  const leadQuery = useLead(leadId)
  const assignees = useLeadAssignees()
  const assignLead = useAssignLead(leadId)
  const updateStatus = useUpdateLeadStatus(leadId)
  const addActivity = useAddLeadActivity(leadId)
  const recordConsent = useRecordLeadConsent(leadId)

  const [status, setStatus] = useState<LeadStatus | ''>('')
  const [assignee, setAssignee] = useState('')
  const [reason, setReason] = useState('')
  const [activityType, setActivityType] = useState('note')
  const [activityBody, setActivityBody] = useState('')
  const [consentChannel, setConsentChannel] = useState('marketing')
  const [consentStatus, setConsentStatus] = useState<'granted' | 'withdrawn'>('granted')
  const [error, setError] = useState<string | null>(null)

  if (leadQuery.isLoading) return <PageLoader label="Loading lead…" />
  if (leadQuery.isError || !leadQuery.data) {
    return <Alert kind="error">{leadQuery.error instanceof ApiError ? leadQuery.error.message : 'Could not load this lead.'}</Alert>
  }

  const lead = leadQuery.data.lead
  const currentStatus = (status || lead.status) as LeadStatus

  function handleError(err: unknown) {
    setError(err instanceof ApiError ? err.message : 'The change could not be saved.')
  }

  function submitStatus(event: FormEvent) {
    event.preventDefault()
    setError(null)
    updateStatus.mutate(currentStatus, { onSuccess: () => setStatus(''), onError: handleError })
  }

  function submitAssignment(event: FormEvent) {
    event.preventDefault()
    if (!assignee) return
    setError(null)
    assignLead.mutate(
      { assigned_to: assignee, reason: reason || undefined },
      {
        onSuccess: () => {
          setAssignee('')
          setReason('')
        },
        onError: handleError,
      },
    )
  }

  function submitActivity(event: FormEvent) {
    event.preventDefault()
    setError(null)
    addActivity.mutate({ type: activityType, body: activityBody }, { onSuccess: () => setActivityBody(''), onError: handleError })
  }

  function submitConsent(event: FormEvent) {
    event.preventDefault()
    setError(null)
    recordConsent.mutate({ channel: consentChannel, status: consentStatus }, { onError: handleError })
  }

  const pending = assignLead.isPending || updateStatus.isPending || addActivity.isPending || recordConsent.isPending

  return (
    <div className="space-y-6">
      <div>
        <Link to="/platform/marketing/leads" className="text-sm font-medium text-brand-700 hover:underline">
          ← All leads
        </Link>
      </div>

      <PageHeader
        title={lead.reference}
        description={`${leadTypeLabels[lead.type] ?? lead.type} · received ${lead.created_at ? formatDateTime(lead.created_at) : '—'}`}
        actions={
          <Badge tone={statusTone[lead.status] ?? 'gray'}>{leadStatusLabels[lead.status] ?? lead.status}</Badge>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <div className="space-y-6">
          <Card title="Enquiry details">
            <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Business</dt>
                <dd className="mt-1 font-medium text-gray-900">{lead.business_name ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Contact</dt>
                <dd className="mt-1 text-gray-900">{lead.contact_name}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Email</dt>
                <dd className="mt-1 text-gray-900">{lead.email ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Phone</dt>
                <dd className="mt-1 text-gray-900">{lead.phone ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Industry</dt>
                <dd className="mt-1 text-gray-900">{lead.industry ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Location</dt>
                <dd className="mt-1 text-gray-900">{[lead.town, lead.county].filter(Boolean).join(', ') || '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Branches</dt>
                <dd className="mt-1 text-gray-900">{lead.branches_count ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Catalogue size</dt>
                <dd className="mt-1 text-gray-900">{lead.catalogue_size ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Service requested</dt>
                <dd className="mt-1 text-gray-900">{lead.service_requested ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Preferred frequency</dt>
                <dd className="mt-1 text-gray-900">{lead.stocktake_frequency ?? '—'}</dd>
              </div>
            </dl>
            {lead.message && (
              <div className="mt-5 border-t border-gray-100 pt-4">
                <p className="text-xs uppercase tracking-wide text-gray-500">Message</p>
                <p className="mt-1 whitespace-pre-line text-sm text-gray-800">{lead.message}</p>
              </div>
            )}
          </Card>

          <Card title="Attribution">
            <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
              {[
                ['Source', lead.source],
                ['UTM source', lead.utm_source],
                ['UTM medium', lead.utm_medium],
                ['UTM campaign', lead.utm_campaign],
                ['UTM term', lead.utm_term],
                ['UTM content', lead.utm_content],
                ['Landing page', lead.landing_page],
                ['Referrer', lead.referrer],
              ].map(([label, value]) => (
                <div key={label}>
                  <dt className="text-xs uppercase tracking-wide text-gray-500">{label}</dt>
                  <dd className="mt-1 break-words text-gray-900">{value ?? '—'}</dd>
                </div>
              ))}
            </dl>
          </Card>

          <Card title="Work the lead">
            {canManage ? (
              <div className="space-y-5">
                <form className="flex flex-wrap items-end gap-3" onSubmit={submitStatus}>
                  <div className="w-52">
                    <Select label="Pipeline status" name="lead-status" value={currentStatus} onChange={(event) => setStatus(event.target.value as LeadStatus)}>
                      {LEAD_STATUSES.map((value) => (
                        <option key={value} value={value}>
                          {leadStatusLabels[value]}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <Button type="submit" loading={updateStatus.isPending} disabled={pending}>
                    Save status
                  </Button>
                </form>

                <form className="grid grid-cols-1 items-end gap-3 border-t border-gray-100 pt-5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]" onSubmit={submitAssignment}>
                  <Select label="Assign to" name="lead-assignee" value={assignee} onChange={(event) => setAssignee(event.target.value)}>
                    <option value="">Select a team member</option>
                    {(assignees.data?.assignees ?? []).map((person) => (
                      <option key={person.id} value={person.id}>
                        {person.name}
                      </option>
                    ))}
                  </Select>
                  <Field label="Reason (optional)" name="lead-assign-reason" value={reason} onChange={(event) => setReason(event.target.value)} />
                  <Button type="submit" loading={assignLead.isPending} disabled={!assignee || pending}>
                    Assign
                  </Button>
                </form>

                <form className="space-y-3 border-t border-gray-100 pt-5" onSubmit={submitActivity}>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-[160px_minmax(0,1fr)]">
                    <Select label="Activity type" name="activity-type" value={activityType} onChange={(event) => setActivityType(event.target.value)}>
                      {LEAD_ACTIVITY_TYPES.map((value) => (
                        <option key={value} value={value}>
                          {value}
                        </option>
                      ))}
                    </Select>
                    <Field label="Note" name="activity-body" value={activityBody} onChange={(event) => setActivityBody(event.target.value)} placeholder="What happened on this contact?" required />
                  </div>
                  <Button type="submit" loading={addActivity.isPending} disabled={!activityBody || pending}>
                    Add activity
                  </Button>
                </form>
              </div>
            ) : (
              <p className="text-sm text-gray-500">Your role can view this lead but not change its status or assignment.</p>
            )}
          </Card>

          <Card title="Activity history">
            {lead.activities.length === 0 ? (
              <p className="text-sm text-gray-500">No activity recorded yet.</p>
            ) : (
              <ol className="space-y-4" data-testid="lead-activity-list">
                {lead.activities.map((activity) => (
                  <li key={activity.id} className="border-l-2 border-brand-200 pl-4">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{activity.type}</p>
                    <p className="mt-1 text-sm text-gray-800">{activity.body ?? '—'}</p>
                    <p className="mt-1 text-xs text-gray-500">
                      {activity.author?.name ?? 'System'} · {activity.created_at ? formatDateTime(activity.created_at) : '—'}
                    </p>
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          <Card title="Assignment">
            {lead.assigned_to ? (
              <p className="text-sm text-gray-800">
                Assigned to <span className="font-medium">{lead.assigned_to.name}</span>
              </p>
            ) : (
              <p className="text-sm text-gray-500">Unassigned.</p>
            )}
            {lead.assignments.length > 0 && (
              <ul className="mt-4 space-y-3 border-t border-gray-100 pt-4 text-sm">
                {lead.assignments.map((assignment) => (
                  <li key={assignment.id}>
                    <p className="font-medium text-gray-900">{assignment.assigned_to?.name ?? 'Removed user'}</p>
                    <p className="text-xs text-gray-500">
                      {assignment.assigned_at ? formatDateTime(assignment.assigned_at) : '—'}
                      {assignment.unassigned_at ? ` → ${formatDateTime(assignment.unassigned_at)}` : ''}
                    </p>
                    {assignment.reason && <p className="text-xs text-gray-500">{assignment.reason}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="Consent ledger">
            <div className="flex flex-wrap gap-2">
              <Badge tone={lead.consent_marketing ? 'green' : 'gray'}>
                Marketing: {lead.consent_marketing ? 'granted' : 'not granted'}
              </Badge>
              <Badge tone={lead.consent_transactional ? 'green' : 'gray'}>
                Transactional: {lead.consent_transactional ? 'granted' : 'not granted'}
              </Badge>
            </div>
            <p className="mt-2 text-xs text-gray-500">
              Captured {lead.consent_at ? formatDateTime(lead.consent_at) : '—'}
            </p>
            {lead.consents.length > 0 ? (
              <ul className="mt-4 space-y-3 border-t border-gray-100 pt-4 text-sm">
                {lead.consents.map((consent, index) => (
                  <li key={`${consent.channel}-${index}`}>
                    <p className="font-medium text-gray-900">
                      {consent.channel}: {consent.status}
                    </p>
                    <p className="text-xs text-gray-500">
                      {consent.source ?? '—'} · {consent.policy_version ?? '—'} ·{' '}
                      {consent.occurred_at ? formatDateTime(consent.occurred_at) : '—'}
                    </p>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="mt-4 text-sm text-gray-500">No consent records yet.</p>
            )}

            {canManage && (
              <form className="mt-4 space-y-3 border-t border-gray-100 pt-4" onSubmit={submitConsent}>
                <Select label="Channel" name="consent-channel" value={consentChannel} onChange={(event) => setConsentChannel(event.target.value)}>
                  {['marketing', 'email', 'sms', 'whatsapp', 'analytics'].map((channel) => (
                    <option key={channel} value={channel}>
                      {channel}
                    </option>
                  ))}
                </Select>
                <Select label="Decision" name="consent-status" value={consentStatus} onChange={(event) => setConsentStatus(event.target.value as 'granted' | 'withdrawn')}>
                  <option value="granted">Granted</option>
                  <option value="withdrawn">Withdrawn</option>
                </Select>
                <Button type="submit" variant="secondary" loading={recordConsent.isPending} disabled={pending}>
                  Record consent
                </Button>
              </form>
            )}
          </Card>

          <Card title="Record">
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Created</dt>
                <dd className="text-gray-900">{lead.created_at ? formatDateTime(lead.created_at) : '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Last activity</dt>
                <dd className="text-gray-900">{lead.last_activity_at ? formatDateTime(lead.last_activity_at) : '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Converted</dt>
                <dd className="text-gray-900">{lead.converted_at ? formatDateTime(lead.converted_at) : '—'}</dd>
              </div>
              <div>
                <dt className="text-xs uppercase tracking-wide text-gray-500">Owner</dt>
                <dd className="text-gray-900">{lead.owner?.name ?? '—'}</dd>
              </div>
            </dl>
          </Card>
        </div>
      </div>
    </div>
  )
}
