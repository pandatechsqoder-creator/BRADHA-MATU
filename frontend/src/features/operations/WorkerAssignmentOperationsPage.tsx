import { Link, useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  PageHeader,
  PageLoader,
  Select,
  StatCard,
} from '../../components/ui'
import type { Checklist, ChecklistItem, Readiness, WorkerOperations } from '../../lib/api/operations'
import { formatDateTime, formatMoney, formatStatusLabel } from '../../lib/formatting'
import {
  useAcknowledgeProcedure,
  useCheckIn,
  useCheckOut,
  useRespondChecklistItem,
  useWorkerOperations,
} from '../../lib/operations/hooks'
import { describeError, ProgressBar, StageBadge, stageLabel } from './shared'

function ReadinessChecklist({ readiness }: { readiness: Readiness }) {
  const rows = [
    ['Offer accepted', readiness.accepted],
    ['SOP acknowledged', readiness.sop_acknowledged],
    [
      `Required checklist (${readiness.checklist_answered}/${readiness.checklist_required})`,
      readiness.checklist_complete,
    ],
    ['Checked in at site', readiness.checked_in],
    ['Counting started', readiness.count_in_progress],
  ] as const

  return (
    <ul className="space-y-2 text-sm">
      {rows.map(([label, done]) => (
        <li key={label} className="flex items-center gap-2">
          <span
            className={`flex h-5 w-5 items-center justify-center rounded-full text-xs font-semibold ${
              done ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
            }`}
            aria-hidden
          >
            {done ? '✓' : '•'}
          </span>
          <span className={done ? 'text-gray-700' : 'text-gray-500'}>{label}</span>
        </li>
      ))}
    </ul>
  )
}

function itemValue(item: ChecklistItem, drafts: Record<string, string>): string {
  return drafts[item.id] ?? item.response?.value ?? ''
}

function ChecklistItemRow({
  assignmentId,
  item,
  drafts,
  setDraft,
}: {
  assignmentId: string
  item: ChecklistItem
  drafts: Record<string, string>
  setDraft: (itemId: string, value: string) => void
}) {
  const respond = useRespondChecklistItem(assignmentId)
  const pending = respond.isPending && respond.variables?.itemId === item.id
  const value = itemValue(item, drafts)

  const save = (next: string) => {
    respond.mutate({ itemId: item.id, value: next })
  }

  return (
    <li className="space-y-2 border-b border-gray-100 py-3 last:border-0">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-gray-900">
            {item.label}
            {item.required && <span className="ml-1 text-xs font-normal text-red-600">required</span>}
          </p>
          {item.instructions && <p className="text-xs text-gray-500">{item.instructions}</p>}
        </div>
        {item.response && (
          <Badge tone="green">Saved {item.response.responded_at ? formatDateTime(item.response.responded_at) : ''}</Badge>
        )}
      </div>

      {item.response_type === 'boolean' ? (
        <div className="flex gap-2">
          {(['yes', 'no'] as const).map((option) => (
            <Button
              key={option}
              type="button"
              variant={value === option ? 'primary' : 'secondary'}
              loading={pending && value !== option}
              onClick={() => save(option)}
            >
              {formatStatusLabel(option)}
            </Button>
          ))}
        </div>
      ) : item.response_type === 'select' ? (
        <div className="flex flex-wrap items-end gap-2">
          <Select
            label="Response"
            value={value}
            onChange={(event) => setDraft(item.id, event.target.value)}
          >
            <option value="">Choose an option</option>
            {item.options.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </Select>
          <Button
            type="button"
            loading={pending}
            disabled={!value}
            onClick={() => save(value)}
          >
            Save response
          </Button>
        </div>
      ) : (
        <div className="flex flex-wrap items-end gap-2">
          <Field
            label="Response"
            value={value}
            onChange={(event) => setDraft(item.id, event.target.value)}
          />
          <Button type="button" loading={pending} disabled={!value.trim()} onClick={() => save(value)}>
            Save response
          </Button>
        </div>
      )}

      {respond.isError && <p className="text-xs text-red-600">{describeError(respond.error)}</p>}
    </li>
  )
}

function ChecklistCard({
  assignmentId,
  checklist,
  drafts,
  setDraft,
}: {
  assignmentId: string
  checklist: Checklist
  drafts: Record<string, string>
  setDraft: (itemId: string, value: string) => void
}) {
  return (
    <Card
      title={checklist.name}
      actions={
        <div className="flex items-center gap-2">
          <Badge tone="gray">{formatStatusLabel(checklist.kind)}</Badge>
          {checklist.required && <Badge tone="amber">Required</Badge>}
        </div>
      }
    >
      <ul>
        {checklist.items.map((item) => (
          <ChecklistItemRow
            key={item.id}
            assignmentId={assignmentId}
            item={item}
            drafts={drafts}
            setDraft={setDraft}
          />
        ))}
      </ul>
    </Card>
  )
}

function CheckInCard({ assignmentId, data }: { assignmentId: string; data: WorkerOperations }) {
  const checkIn = useCheckIn(assignmentId)
  const checkOut = useCheckOut(assignmentId)
  const [siteLabel, setSiteLabel] = useState(data.assignment.job?.scope_label ?? '')
  const [method, setMethod] = useState<'gps' | 'manual'>('manual')
  const [notes, setNotes] = useState('')
  const [location, setLocation] = useState<{ latitude: number; longitude: number } | null>(null)
  const [geoError, setGeoError] = useState<string | null>(null)
  const [checkoutNotes, setCheckoutNotes] = useState('')

  const canCheckIn = data.readiness.can_check_in
  const prerequisitesComplete =
    data.readiness.sop_acknowledged && data.readiness.checklist_complete
  const fieldWorkBlocker = data.readiness.blockers.find((blocker) =>
    blocker.startsWith('The job is not ready for field work'),
  )

  const captureLocation = () => {
    setGeoError(null)
    if (!navigator.geolocation) {
      setGeoError('This browser does not expose a location. Check in manually instead.')
      return
    }
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setLocation({
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
        })
        setMethod('gps')
      },
      () => setGeoError('Location permission was denied. Check in manually instead.'),
      { enableHighAccuracy: true, timeout: 10_000 },
    )
  }

  const submitCheckIn = (event: FormEvent) => {
    event.preventDefault()
    checkIn.mutate({
      site_label: siteLabel.trim() || null,
      method,
      notes: notes.trim() || null,
      latitude: location?.latitude ?? null,
      longitude: location?.longitude ?? null,
    })
  }

  const openCheckin = data.checkin && (!data.checkout || !data.checkout.checked_out_at)

  if (openCheckin) {
    return (
      <Card
        title="On site"
        actions={<Badge tone="green">Checked in {data.checkin?.checked_in_at ? formatDateTime(data.checkin.checked_in_at) : ''}</Badge>}
      >
        <div className="space-y-3 text-sm">
          <p className="text-gray-700">
            Site: <strong>{data.checkin?.site_label ?? '—'}</strong> · Method {data.checkin?.method.toUpperCase()}
          </p>
          {checkOut.isError && <Alert kind="error">{describeError(checkOut.error)}</Alert>}
          <Field
            label="Check-out notes"
            value={checkoutNotes}
            onChange={(event) => setCheckoutNotes(event.target.value)}
            hint="Optional handover notes for the supervisor."
          />
          <Button
            loading={checkOut.isPending}
            onClick={() => checkOut.mutate(checkoutNotes.trim() || null)}
          >
            Check out
          </Button>
        </div>
      </Card>
    )
  }

  if (data.checkout) {
    return (
      <Card title="Site attendance">
        <dl className="space-y-2 text-sm">
          <div className="flex justify-between">
            <dt className="text-gray-500">Checked in</dt>
            <dd className="text-gray-800">{data.checkin?.checked_in_at ? formatDateTime(data.checkin.checked_in_at) : '—'}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">Checked out</dt>
            <dd className="text-gray-800">{data.checkout.checked_out_at ? formatDateTime(data.checkout.checked_out_at) : '—'}</dd>
          </div>
          <div className="flex justify-between">
            <dt className="text-gray-500">Minutes on site</dt>
            <dd className="text-gray-800">{data.attendance?.minutes_on_site ?? '—'}</dd>
          </div>
        </dl>
      </Card>
    )
  }

  return (
    <Card
      title="Site check-in"
      actions={<Badge tone={canCheckIn ? 'green' : 'amber'}>{canCheckIn ? 'Ready to check in' : prerequisitesComplete ? 'Awaiting job schedule' : 'Prerequisites pending'}</Badge>}
    >
      <form className="space-y-3" onSubmit={submitCheckIn}>
        {!canCheckIn && (
          <Alert kind="info">
            {prerequisitesComplete && fieldWorkBlocker
              ? `${fieldWorkBlocker} Check-in opens once the job is scheduled.`
              : 'Acknowledge the SOP and complete every required checklist item before checking in.'}
          </Alert>
        )}
        {geoError && <Alert kind="error">{geoError}</Alert>}
        {checkIn.isError && <Alert kind="error">{describeError(checkIn.error)}</Alert>}

        <Field label="Site / location" value={siteLabel} onChange={(event) => setSiteLabel(event.target.value)} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Method" value={method} onChange={(event) => setMethod(event.target.value as 'gps' | 'manual')}>
            <option value="manual">Manual</option>
            <option value="gps">GPS</option>
          </Select>
          <div className="flex items-end">
            <Button type="button" variant="secondary" onClick={captureLocation}>
              Use my current location
            </Button>
          </div>
        </div>
        {location && (
          <p className="text-xs text-gray-500">
            Captured {location.latitude.toFixed(5)}, {location.longitude.toFixed(5)}
          </p>
        )}
        <Field label="Notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
        <Button type="submit" loading={checkIn.isPending} disabled={!canCheckIn}>
          Check in on site
        </Button>
        {data.readiness.blockers.length > 0 && (
          <p className="text-xs text-gray-500">Still outstanding: {data.readiness.blockers.join(' ')}</p>
        )}
      </form>
    </Card>
  )
}

export function WorkerAssignmentOperationsPage() {
  const params = useParams({ strict: false }) as { assignmentId?: string }
  const assignmentId = params.assignmentId
  const operations = useWorkerOperations(assignmentId)
  const acknowledge = useAcknowledgeProcedure(assignmentId ?? '')
  const [drafts, setDrafts] = useState<Record<string, string>>({})

  if (operations.isLoading) return <PageLoader label="Loading field operations…" />

  if (operations.isError || !operations.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <Alert kind="error">
          {operations.error ? describeError(operations.error) : 'This assignment could not be opened.'}
        </Alert>
        <Link to="/workforce/assignments" className="text-sm font-medium text-brand-700 hover:underline">
          ← Back to assignments
        </Link>
      </div>
    )
  }

  const data = operations.data
  const job = data.assignment.job
  const readiness = data.readiness
  const setDraft = (itemId: string, value: string) =>
    setDrafts((current) => ({ ...current, [itemId]: value }))

  if (data.assignment.status !== 'accepted') {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <PageHeader title={job?.title ?? 'Assignment'} description={job?.reference ?? undefined} />
        <Alert kind="info">Accept this assignment offer before opening the field operations workspace.</Alert>
        <Link to="/workforce/assignments" className="text-sm font-medium text-brand-700 hover:underline">
          ← Review the offer
        </Link>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <Link to="/workforce/assignments" className="text-sm font-medium text-brand-700 hover:underline">
          ← Assignments
        </Link>
      </div>

      <PageHeader
        title={job?.title ?? 'Field operations'}
        description={`${job?.reference ?? ''} · ${job?.tenant ?? ''} · ${job?.scope_label ?? 'All locations'}`}
        actions={
          <div className="flex items-center gap-2">
            <StageBadge stage={readiness.stage} />
            {job && <Badge tone={job.status === 'in_progress' ? 'green' : 'gray'}>{job.status_label}</Badge>}
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Readiness" value={`${readiness.percent}%`} hint={stageLabel(readiness.stage)} />
        <StatCard
          label="Checklist"
          value={`${readiness.checklist_answered}/${readiness.checklist_required}`}
          hint={readiness.checklist_complete ? 'Required items complete' : 'Required items pending'}
        />
        <StatCard
          label="Your fee"
          value={formatMoney(data.assignment.compensation.amount, data.assignment.compensation.currency)}
          hint="Only your disclosed compensation is shown."
        />
      </div>

      <Card title="Start-of-count gate">
        <div className="space-y-3">
          <ProgressBar percent={readiness.percent} />
          <ReadinessChecklist readiness={readiness} />
          {readiness.can_start_count ? (
            <div className="space-y-3">
              <Alert kind="success">All requirements met. Counting is ready to begin.</Alert>
              <Link
                to="/workforce/assignments/$assignmentId/count"
                params={{ assignmentId: assignmentId ?? '' }}
                className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                Open count workspace →
              </Link>
            </div>
          ) : (
            <Alert kind="info">
              {readiness.blockers[0] ?? 'Complete the outstanding requirements to start counting.'}
            </Alert>
          )}
        </div>
      </Card>

      <Card
        title={data.procedure ? `Job SOP · v${data.procedure.version}` : 'Job SOP'}
        actions={
          data.procedure && (
            <Badge tone={data.procedure.acknowledged ? 'green' : 'amber'}>
              {data.procedure.acknowledged ? 'Acknowledged' : 'Acknowledgement required'}
            </Badge>
          )
        }
      >
        {!data.procedure ? (
          <p className="text-sm text-gray-500">
            BRATHAMATU has not assigned a procedure yet. You cannot check in until the SOP is available.
          </p>
        ) : (
          <div className="space-y-4">
            <div>
              <p className="font-medium text-gray-900">{data.procedure.title}</p>
              {data.procedure.summary && <p className="mt-1 text-sm text-gray-600">{data.procedure.summary}</p>}
            </div>
            <ol className="space-y-3">
              {data.procedure.steps.map((step) => (
                <li key={step.position} className="flex gap-3 text-sm">
                  <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-semibold text-brand-800">
                    {step.position}
                  </span>
                  <div>
                    <p className="font-medium text-gray-900">{step.title}</p>
                    {step.instructions && <p className="text-gray-600">{step.instructions}</p>}
                  </div>
                </li>
              ))}
            </ol>

            {acknowledge.isError && <Alert kind="error">{describeError(acknowledge.error)}</Alert>}

            {data.procedure.acknowledged ? (
              <p className="text-xs text-gray-500">
                Acknowledged {data.procedure.acknowledged_at ? formatDateTime(data.procedure.acknowledged_at) : ''}. The
                acknowledgement is recorded with your user, timestamp and IP address.
              </p>
            ) : (
              <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                <p className="text-sm text-amber-900">
                  Confirm you have read the procedure and will follow it on site. This is required before check-in.
                </p>
                <Button
                  className="mt-3"
                  loading={acknowledge.isPending}
                  onClick={() => acknowledge.mutate(undefined)}
                >
                  Acknowledge SOP
                </Button>
              </div>
            )}
          </div>
        )}
      </Card>

      {data.checklists.map((checklist) => (
        <ChecklistCard
          key={checklist.id}
          assignmentId={data.assignment.id}
          checklist={checklist}
          drafts={drafts}
          setDraft={setDraft}
        />
      ))}

      <CheckInCard assignmentId={data.assignment.id} data={data} />

      <Card title="Activity">
        {data.progress.events.length === 0 ? (
          <p className="text-sm text-gray-500">No progress recorded yet.</p>
        ) : (
          <ol className="space-y-3">
            {[...data.progress.events].reverse().map((event, index) => (
              <li key={`${event.stage}-${event.occurred_at}-${index}`} className="flex gap-3 text-sm">
                <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500" aria-hidden />
                <div>
                  <p className="font-medium text-gray-900">{event.message ?? formatStatusLabel(event.stage)}</p>
                  <p className="text-xs text-gray-500">
                    {event.percent}% · {event.occurred_at ? formatDateTime(event.occurred_at) : '—'}
                  </p>
                </div>
              </li>
            ))}
          </ol>
        )}
      </Card>
    </div>
  )
}
