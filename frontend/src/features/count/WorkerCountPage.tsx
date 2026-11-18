import { Link, useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, PageLoader } from '../../components/ui'
import { OfflineBanner } from '../../components/OfflineBanner'
import type { CountEntry, CountSession, ScopeVariant } from '../../lib/api/count'
import { ApiError } from '../../lib/api/client'
import {
  useCountWorkspace,
  useReportException,
  useReportUnknownProduct,
  useReviseCountEntry,
  useStartCountSession,
  useSubmitCountSession,
  useUpsertCountEntry,
} from '../../lib/count/hooks'
import { useJobPackageDownload, useOfflineSync } from '../../lib/offline/hooks'
import type { QueuedEntry } from '../../lib/offline/db'
import { formatDateTime } from '../../lib/formatting'

const OBSERVATIONS = ['good', 'damaged', 'expired', 'quarantined', 'missing_label', 'inaccessible', 'unknown']

function obsLabel(obs: string) {
  return obs === 'missing_label' ? 'Missing label' : obs.charAt(0).toUpperCase() + obs.slice(1)
}

// ---- Product search + entry form ----

function EntryForm({
  assignmentId,
  sessionId,
  scope,
  existingEntry,
  online,
  onClose,
}: {
  assignmentId: string
  sessionId: string
  scope: ScopeVariant[]
  existingEntry?: CountEntry
  online: boolean
  onClose: (queuedItem?: QueuedEntry) => void
}) {
  const [variantId, setVariantId] = useState(existingEntry?.variant_id ?? '')
  const [qty, setQty] = useState(existingEntry ? String(parseFloat(existingEntry.raw_quantity)) : '')
  const [unitId, setUnitId] = useState(existingEntry?.raw_unit_id ?? '')
  const [observations, setObs] = useState<string[]>(existingEntry?.observations ?? [])
  const [notes, setNotes] = useState(existingEntry?.notes ?? '')
  const [reason, setReason] = useState('')
  const [search, setSearch] = useState('')
  const [error, setError] = useState<string | null>(null)

  const upsert = useUpsertCountEntry(assignmentId, sessionId)
  const revise = useReviseCountEntry(assignmentId, sessionId)

  const isRevision = Boolean(existingEntry)
  const selectedVariant = scope.find((v) => v.id === variantId)

  const filteredScope = scope.filter(
    (v) =>
      !search ||
      v.sku.toLowerCase().includes(search.toLowerCase()) ||
      v.product.name.toLowerCase().includes(search.toLowerCase()) ||
      v.barcodes.some((b) => b.barcode.includes(search)),
  )

  const toggleObs = (obs: string) =>
    setObs((prev) => (prev.includes(obs) ? prev.filter((o) => o !== obs) : [...prev, obs]))

  const idKey = existingEntry?.idempotency_key ?? `entry-${variantId}-${Date.now()}`

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (!variantId) { setError('Select a product.'); return }
    if (!qty || isNaN(parseFloat(qty))) { setError('Enter a valid quantity.'); return }
    if (!unitId) { setError('Select a unit.'); return }
    const qtyNum = parseFloat(qty)
    if (qtyNum < 0) { setError('Quantity cannot be negative.'); return }
    try {
      if (isRevision && existingEntry) {
        if (!reason.trim()) { setError('Enter a reason for the revision.'); return }
        await revise.mutateAsync({ entryId: existingEntry.id, raw_quantity: qtyNum, raw_unit_id: unitId, observations, notes: notes || null, reason })
      } else if (!online) {
        // Queue for offline sync
        const queueItem: QueuedEntry = {
          idempotency_key: idKey,
          type: 'entry',
          variant_id: variantId,
          raw_quantity: qtyNum,
          raw_unit_id: unitId,
          observations: observations.length > 0 ? observations : undefined,
          notes: notes || null,
          captured_at: new Date().toISOString(),
        }
        // addToQueue is called via props below — for offline we notify parent
        onClose(queueItem)
        return
      } else {
        await upsert.mutateAsync({
          idempotency_key: idKey,
          variant_id: variantId,
          raw_quantity: qtyNum,
          raw_unit_id: unitId,
          observations,
          notes: notes || null,
          captured_at: new Date().toISOString(),
        })
      }
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to save entry.')
    }
  }

  const isPending = upsert.isPending || revise.isPending

  return (
    <div className="space-y-4">
      <h3 className="font-semibold text-gray-900">{isRevision ? 'Revise entry' : 'Record count'}</h3>

      {error && <Alert kind="error">{error}</Alert>}

      {!isRevision && (
        <div>
          <Field label="Search product">
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="SKU, name or barcode…"
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          </Field>
          <div className="mt-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 bg-white">
            {filteredScope.length === 0 ? (
              <p className="p-3 text-sm text-gray-500">No products match.</p>
            ) : (
              filteredScope.map((v) => (
                <button
                  key={v.id}
                  type="button"
                  onClick={() => { setVariantId(v.id); setUnitId(v.approved_units[0]?.id ?? '') }}
                  className={`flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 ${variantId === v.id ? 'bg-brand-50' : ''}`}
                >
                  <span className="font-mono text-xs text-gray-500">{v.sku}</span>
                  <span className="flex-1 truncate font-medium text-gray-800">{v.product.name}</span>
                  {v.name && <span className="text-xs text-gray-500">{v.name}</span>}
                </button>
              ))
            )}
          </div>
        </div>
      )}

      {selectedVariant && (
        <div className="rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-800">
          <span className="font-semibold">{selectedVariant.product.name}</span>
          {selectedVariant.name && ` — ${selectedVariant.name}`}
          <span className="ml-2 font-mono text-xs text-brand-600">{selectedVariant.sku}</span>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-3">
        <div className="flex gap-3">
          <div className="flex-1">
            <Field label="Quantity">
              <input
                type="number"
                min="0"
                step="any"
                value={qty}
                onChange={(e) => setQty(e.target.value)}
                placeholder="0"
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
                required
              />
            </Field>
          </div>
          <div className="w-40">
            <Field label="Unit">
              <select
                value={unitId}
                onChange={(e) => setUnitId(e.target.value)}
                required
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              >
                <option value="">— select —</option>
                {(selectedVariant ?? (isRevision ? scope.find((v) => v.id === existingEntry?.variant_id) : undefined))
                  ?.approved_units.map((u) => (
                    <option key={u.id} value={u.id}>{u.name}</option>
                  ))}
              </select>
            </Field>
          </div>
        </div>

        <div>
          <p className="mb-1.5 text-xs font-medium text-gray-600">Condition flags</p>
          <div className="flex flex-wrap gap-2">
            {OBSERVATIONS.map((obs) => (
              <label key={obs} className="flex cursor-pointer items-center gap-1.5 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={observations.includes(obs)}
                  onChange={() => toggleObs(obs)}
                  className="rounded border-gray-300"
                />
                {obsLabel(obs)}
              </label>
            ))}
          </div>
        </div>

        <Field label="Notes (optional)">
          <textarea
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            rows={2}
            maxLength={2000}
            placeholder="Any notes about this count…"
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
        </Field>

        {isRevision && (
          <Field label="Reason for revision" required>
            <input
              type="text"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="e.g. Recounted after second pass"
              maxLength={500}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
              required
            />
          </Field>
        )}

        <div className="flex gap-2">
          <Button type="submit" loading={isPending}>Save entry</Button>
          <Button type="button" variant="secondary" onClick={() => onClose()}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}

// ---- Exception report form ----
function ExceptionForm({
  assignmentId,
  sessionId,
  onClose,
}: {
  assignmentId: string
  sessionId: string
  onClose: () => void
}) {
  const [type, setType] = useState('')
  const [desc, setDesc] = useState('')
  const [barcode, setBarcode] = useState('')
  const [location, setLocation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const report = useReportException(assignmentId, sessionId)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (!type) { setError('Select exception type.'); return }
    if (!desc.trim()) { setError('Enter a description.'); return }
    try {
      await report.mutateAsync({ type, description: desc, barcode: barcode || null, location: location || null })
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to report exception.')
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3">
      <h3 className="font-semibold text-gray-900">Report catalogue exception</h3>
      {error && <Alert kind="error">{error}</Alert>}
      <Field label="Type">
        <select
          value={type}
          onChange={(e) => setType(e.target.value)}
          required
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
          <option value="">— select —</option>
          <option value="mismatched_barcode">Mismatched barcode</option>
          <option value="wrong_label">Wrong label</option>
          <option value="unexpected_variant">Unexpected variant</option>
          <option value="packaging">Packaging issue</option>
          <option value="other">Other</option>
        </select>
      </Field>
      <Field label="Description">
        <textarea value={desc} onChange={(e) => setDesc(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required />
      </Field>
      <Field label="Barcode (if applicable)">
        <input type="text" value={barcode} onChange={(e) => setBarcode(e.target.value)} maxLength={200}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <Field label="Location">
        <input type="text" value={location} onChange={(e) => setLocation(e.target.value)} maxLength={200}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={report.isPending}>Submit</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

// ---- Unknown product form ----
function UnknownProductForm({
  assignmentId,
  sessionId,
  onClose,
}: {
  assignmentId: string
  sessionId: string
  onClose: () => void
}) {
  const [desc, setDesc] = useState('')
  const [barcode, setBarcode] = useState('')
  const [nameGuess, setNameGuess] = useState('')
  const [location, setLocation] = useState('')
  const [error, setError] = useState<string | null>(null)
  const report = useReportUnknownProduct(assignmentId, sessionId)

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    if (!desc.trim()) { setError('Enter a description.'); return }
    try {
      await report.mutateAsync({ description: desc, barcode: barcode || null, name_guess: nameGuess || null, location: location || null })
      onClose()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Failed to report unknown product.')
    }
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-3">
      <h3 className="font-semibold text-gray-900">Report unknown product</h3>
      {error && <Alert kind="error">{error}</Alert>}
      <Field label="Description">
        <textarea value={desc} onChange={(e) => setDesc(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required />
      </Field>
      <Field label="Barcode (if scanned)">
        <input type="text" value={barcode} onChange={(e) => setBarcode(e.target.value)} maxLength={200}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <Field label="Name guess">
        <input type="text" value={nameGuess} onChange={(e) => setNameGuess(e.target.value)} maxLength={200}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <Field label="Location">
        <input type="text" value={location} onChange={(e) => setLocation(e.target.value)} maxLength={200}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={report.isPending}>Submit</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

// ---- Entry list ----
function EntryList({
  assignmentId,
  session,
  scope,
  online,
}: {
  assignmentId: string
  session: CountSession
  scope: ScopeVariant[]
  online: boolean
}) {
  const [editing, setEditing] = useState<string | null>(null)

  if (session.entries.length === 0) {
    return <p className="text-sm text-gray-500">No entries yet. Tap "Record count" to start counting.</p>
  }

  return (
    <ul className="divide-y divide-gray-100">
      {session.entries.map((entry) => {
        const variant = scope.find((v) => v.id === entry.variant_id)
        const unit = variant?.approved_units.find((u) => u.id === entry.raw_unit_id)
        return (
          <li key={entry.id} className="py-3">
            {editing === entry.id && session.status === 'active' ? (
              <EntryForm
                assignmentId={assignmentId}
                sessionId={session.id}
                scope={scope}
                existingEntry={entry}
                online={online}
                onClose={() => setEditing(null)}
              />
            ) : (
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium text-gray-800 truncate">
                    {variant?.product.name ?? '—'}{variant?.name ? ` — ${variant.name}` : ''}
                  </p>
                  <p className="text-xs text-gray-500">{variant?.sku}</p>
                  <p className="mt-0.5 text-sm text-gray-700">
                    <span className="font-semibold">{parseFloat(entry.raw_quantity)}</span>
                    <span className="ml-1 text-gray-500">{unit?.name ?? 'units'}</span>
                    {entry.observations?.length > 0 && (
                      <span className="ml-2 text-xs text-amber-700">{entry.observations.map(obsLabel).join(', ')}</span>
                    )}
                  </p>
                  {entry.notes && <p className="mt-0.5 text-xs text-gray-500">{entry.notes}</p>}
                  {entry.revision_count > 0 && (
                    <p className="mt-0.5 text-xs text-gray-500">{entry.revision_count} revision{entry.revision_count !== 1 ? 's' : ''}</p>
                  )}
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                  <Badge tone={entry.status === 'submitted' ? 'green' : 'gray'}>
                    {entry.status === 'submitted' ? 'Submitted' : 'Draft'}
                  </Badge>
                  {session.status === 'active' && (
                    <button
                      onClick={() => setEditing(entry.id)}
                      className="text-xs font-medium text-brand-700 hover:underline"
                    >
                      Revise
                    </button>
                  )}
                </div>
              </div>
            )}
          </li>
        )
      })}
    </ul>
  )
}

// ---- Main page ----
export function WorkerCountPage() {
  const { assignmentId } = useParams({ strict: false }) as { assignmentId: string }
  const { data, isLoading, error } = useCountWorkspace(assignmentId)
  const startSession = useStartCountSession(assignmentId)
  const [panel, setPanel] = useState<'entry' | 'exception' | 'unknown' | null>(null)

  const session = data?.session
  const { state: syncState, online, addToQueue, triggerSync } = useOfflineSync(assignmentId, session?.id)
  const { download, downloading, downloaded, error: pkgError } = useJobPackageDownload(assignmentId)

  const submit = useSubmitCountSession(
    assignmentId,
    data?.session?.id ?? '',
  )

  if (isLoading) return <PageLoader label="Loading count workspace…" />
  if (error) return <Alert kind="error">{error instanceof ApiError ? error.message : 'Failed to load count workspace.'}</Alert>
  if (!data) return null

  const readiness = data.readiness as Record<string, unknown>
  const canStartCount = Boolean(readiness.can_start_count)

  return (
    <div className="mx-auto max-w-2xl space-y-6">
      <div className="flex items-start justify-between gap-4">
        <PageHeader
          title="Count workspace"
          description={`${data.job.reference} · ${data.job.title}${data.job.scope_label ? ` · ${data.job.scope_label}` : ''}`}
        />
        <Link to="/workforce/assignments" className="text-sm font-medium text-brand-700 hover:underline shrink-0">
          ← Back
        </Link>
      </div>

      {/* Offline sync banner */}
      <OfflineBanner online={online} syncState={syncState} onSync={triggerSync} />

      {/* Offline package download */}
      {session && (
        <div className="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm">
          <span className="text-gray-600">
            {downloaded
              ? '✓ Offline package cached — entries will queue locally if you lose connection.'
              : 'Download job package for offline counting.'}
          </span>
          {pkgError && <span className="text-xs text-red-600">{pkgError}</span>}
          {!downloaded && (
            <Button variant="secondary" loading={downloading} onClick={download}>
              Download for offline
            </Button>
          )}
        </div>
      )}

      {/* Gate — show blockers if not yet ready */}
      {!canStartCount && !session && (
        <Card title="Before you can count">
          <div className="space-y-2">
            {Array.isArray(readiness.blockers) && (readiness.blockers as string[]).length > 0 ? (
              <ul className="list-inside list-disc space-y-1 text-sm text-amber-700">
                {(readiness.blockers as string[]).map((b) => <li key={b}>{b}</li>)}
              </ul>
            ) : (
              <p className="text-sm text-gray-500">Complete your readiness checklist in the operations workspace first.</p>
            )}
            <Link to="/workforce/assignments/$assignmentId/operations"
              params={{ assignmentId }}
              className="inline-block text-sm font-medium text-brand-700 hover:underline">
              Go to operations →
            </Link>
          </div>
        </Card>
      )}

      {/* Mode badge */}
      <div className="flex items-center gap-3">
        <Badge tone={data.mode === 'blind' ? 'gray' : 'green'}>
          {data.mode === 'blind' ? 'Blind count' : 'Guided count'}
        </Badge>
        <span className="text-sm text-gray-500">
          {data.mode === 'blind' ? 'You will not see expected stock quantities.' : 'Expected quantities are visible.'}
        </span>
      </div>

      {/* No active session */}
      {!session && canStartCount && (
        <Card title="Start counting">
          <p className="mb-4 text-sm text-gray-600">
            You are cleared to begin counting. Starting a session pins the catalogue version and your assigned scope.
          </p>
          <Button
            loading={startSession.isPending}
            onClick={() =>
              startSession.mutate({ idempotency_key: `sess-${assignmentId}-${Date.now()}` })
            }
          >
            Start count session
          </Button>
        </Card>
      )}

      {/* Active / submitted session */}
      {session && (
        <>
          <Card
            title="Count session"
            actions={
              <Badge tone={session.status === 'submitted' ? 'green' : 'green'}>
                {session.status === 'submitted' ? 'Submitted' : 'Active'}
              </Badge>
            }
          >
            <div className="mb-3 flex items-center justify-between gap-3">
              <div className="text-sm text-gray-600">
                <span className="font-medium">{session.entries.length}</span> entries ·{' '}
                <span className="font-medium">{session.exceptions.length}</span> exceptions ·{' '}
                <span className="font-medium">{session.unknown_products.length}</span> unknown
              </div>
              {session.status === 'active' && (
                <div className="flex shrink-0 gap-2">
                  <Button variant="secondary" onClick={() => setPanel('exception')}>
                    Log exception
                  </Button>
                  <Button variant="secondary" onClick={() => setPanel('unknown')}>
                    Unknown product
                  </Button>
                  <Button onClick={() => setPanel('entry')}>
                    Record count
                  </Button>
                </div>
              )}
            </div>

            {/* Inline panel */}
            {panel === 'entry' && (
              <div className="mb-4 rounded-lg border border-brand-200 bg-brand-50 p-4">
                <EntryForm
                  assignmentId={assignmentId}
                  sessionId={session.id}
                  scope={data.scope}
                  online={online}
                  onClose={(queuedItem) => {
                    if (queuedItem) void addToQueue(queuedItem)
                    setPanel(null)
                  }}
                />
              </div>
            )}
            {panel === 'exception' && (
              <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <ExceptionForm assignmentId={assignmentId} sessionId={session.id} onClose={() => setPanel(null)} />
              </div>
            )}
            {panel === 'unknown' && (
              <div className="mb-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <UnknownProductForm assignmentId={assignmentId} sessionId={session.id} onClose={() => setPanel(null)} />
              </div>
            )}

            <EntryList assignmentId={assignmentId} session={session} scope={data.scope} online={online} />
          </Card>

          {/* Exceptions summary */}
          {session.exceptions.length > 0 && (
            <Card title="Catalogue exceptions">
              <ul className="divide-y divide-gray-100">
                {session.exceptions.map((x) => (
                  <li key={x.id} className="flex items-start gap-3 py-2 text-sm">
                <Badge tone={x.status === 'open' ? 'red' : 'green'}>{x.status}</Badge>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {/* Unknown products summary */}
          {session.unknown_products.length > 0 && (
            <Card title="Unknown products">
              <ul className="divide-y divide-gray-100">
                {session.unknown_products.map((u) => (
                  <li key={u.id} className="flex items-start gap-3 py-2 text-sm">
                    {u.barcode && <span className="font-mono text-xs text-gray-500">{u.barcode}</span>}
                    <span className="text-gray-700">{u.name_guess ?? 'No name'}</span>
                <Badge tone={u.status === 'open' ? 'red' : 'green'}>{u.status}</Badge>
                  </li>
                ))}
              </ul>
            </Card>
          )}

          {/* Submit */}
          {session.status === 'active' && (
            <Card title="Submit count">
              <p className="mb-4 text-sm text-gray-600">
                Submitting locks all entries. This action cannot be undone. Make sure you have recorded all products.
              </p>
              {submit.isError && (
                <Alert kind="error">
                  {submit.error instanceof ApiError ? submit.error.message : 'Failed to submit.'}
                </Alert>
              )}
              <Button
                loading={submit.isPending}
                onClick={() => submit.mutate()}
              >
                Submit count
              </Button>
            </Card>
          )}

          {session.status === 'submitted' && (
            <Alert kind="success">
              Count submitted on {session.submitted_at ? formatDateTime(session.submitted_at) : '—'}.
              Your entries are locked and will be reviewed by the BRATHAMATU quality team.
            </Alert>
          )}
        </>
      )}

      {/* Scope reference */}
      <details className="rounded-lg border border-gray-200">
        <summary className="cursor-pointer select-none rounded-lg px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
          Assigned scope ({data.scope.length} product{data.scope.length !== 1 ? 's' : ''})
        </summary>
        <div className="max-h-64 overflow-y-auto border-t border-gray-100">
          <table className="w-full text-xs">
            <thead className="bg-gray-50 text-left text-gray-500">
              <tr>
                <th className="px-3 py-2">SKU</th>
                <th className="px-3 py-2">Product</th>
                <th className="px-3 py-2">Units</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {data.scope.map((v) => (
                <tr key={v.id}>
                  <td className="px-3 py-1.5 font-mono">{v.sku}</td>
                  <td className="px-3 py-1.5">{v.product.name}{v.name ? ` — ${v.name}` : ''}</td>
                  <td className="px-3 py-1.5">{v.approved_units.map((u) => u.name).join(', ')}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </div>
  )
}
