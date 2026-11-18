import type { CountSession, JobCounts, ScopeVariant } from '../../lib/api/count'
import { Badge, Card } from '../../components/ui'
import { formatDateTime } from '../../lib/formatting'

function obsLabel(obs: string) {
  return obs === 'missing_label' ? 'Missing label' : obs.charAt(0).toUpperCase() + obs.slice(1)
}

function VariantName({ scope, variantId }: { scope: ScopeVariant[]; variantId: string | null }) {
  if (!variantId) return <span className="text-gray-500">—</span>
  const v = scope.find((s) => s.id === variantId)
  if (!v) return <span className="font-mono text-xs text-gray-500">{variantId.slice(0, 8)}</span>
  return (
    <span>
      <span className="font-medium text-gray-800">{v.product.name}</span>
      {v.name && <span className="text-gray-500"> — {v.name}</span>}
      <span className="ml-1.5 font-mono text-xs text-gray-500">{v.sku}</span>
    </span>
  )
}

function UnitName({ scope, variantId, unitId }: { scope: ScopeVariant[]; variantId: string | null; unitId: string | null }) {
  if (!unitId) return null
  const v = scope.find((s) => s.id === variantId)
  const u = v?.approved_units.find((u) => u.id === unitId)
  return <span className="text-gray-500">{u?.name ?? unitId.slice(0, 8)}</span>
}

function SessionCard({ session, scope }: { session: CountSession; scope: ScopeVariant[] }) {
  return (
    <div className="rounded-lg border border-gray-200 bg-white">
      <div className="flex items-center gap-3 border-b border-gray-100 px-4 py-3">
        <Badge tone="green">
          {session.status === 'submitted' ? 'Submitted' : 'Active'}
        </Badge>
        <Badge tone={session.mode === 'blind' ? 'gray' : 'green'}>
          {session.mode === 'blind' ? 'Blind' : 'Guided'}
        </Badge>
        <span className="ml-auto text-xs text-gray-500">
          {session.started_at ? formatDateTime(session.started_at) : 'Not started'}
          {session.submitted_at && ` → ${formatDateTime(session.submitted_at)}`}
        </span>
      </div>

      <div className="flex gap-6 px-4 py-2 text-xs text-gray-500">
        <span><span className="font-semibold text-gray-800">{session.entries.length}</span> entries</span>
        <span><span className="font-semibold text-gray-800">{session.exceptions.length}</span> exceptions</span>
        <span><span className="font-semibold text-gray-800">{session.unknown_products.length}</span> unknown products</span>
        {session.locked && <span className="font-medium text-green-700">Locked</span>}
      </div>

      {session.entries.length > 0 && (
        <div className="border-t border-gray-100 px-4 pb-3">
          <p className="pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Entries</p>
          <table className="w-full text-xs">
            <thead className="text-left text-gray-500">
              <tr>
                <th className="py-1 font-medium">Product</th>
                <th className="py-1 text-right font-medium">Qty</th>
                <th className="py-1 font-medium">Unit</th>
                <th className="py-1 font-medium">Flags</th>
                <th className="py-1 text-right font-medium">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {session.entries.map((e) => (
                <tr key={e.id}>
                  <td className="py-1 pr-3">
                    <VariantName scope={scope} variantId={e.variant_id} />
                  </td>
                  <td className="py-1 pr-3 text-right font-semibold text-gray-800">
                    {parseFloat(e.raw_quantity)}
                  </td>
                  <td className="py-1 pr-3">
                    <UnitName scope={scope} variantId={e.variant_id} unitId={e.raw_unit_id} />
                  </td>
                  <td className="py-1 pr-3 text-amber-700">
                    {e.observations?.length > 0 ? e.observations.map(obsLabel).join(', ') : <span className="text-gray-300">—</span>}
                  </td>
                  <td className="py-1 text-right">
                    <Badge tone={e.status === 'submitted' ? 'green' : 'gray'}>{e.status}</Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {session.exceptions.length > 0 && (
        <div className="border-t border-gray-100 px-4 pb-3">
          <p className="pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Exceptions</p>
          <ul className="space-y-1">
            {session.exceptions.map((x) => (
              <li key={x.id} className="flex items-center gap-2 text-xs">
                <Badge tone="amber">{x.type.replace('_', ' ')}</Badge>
                <span className="text-gray-700">{x.description}</span>
                <Badge tone={x.status === 'open' ? 'red' : 'green'}>{x.status}</Badge>
              </li>
            ))}
          </ul>
        </div>
      )}

      {session.unknown_products.length > 0 && (
        <div className="border-t border-gray-100 px-4 pb-3">
          <p className="pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Unknown products</p>
          <ul className="space-y-1">
            {session.unknown_products.map((u) => (
              <li key={u.id} className="flex items-center gap-2 text-xs">
                {u.barcode && <span className="font-mono text-gray-500">{u.barcode}</span>}
                <span className="text-gray-700">{u.name_guess ?? 'No name'}</span>
                <Badge tone={u.status === 'open' ? 'red' : 'green'}>{u.status}</Badge>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

export function JobCountsPanel({ counts }: { counts: JobCounts }) {
  const scope = counts.sessions.flatMap((s) => s.scope)
  const uniqueScope = Array.from(new Map(scope.map((v) => [v.id, v])).values())

  return (
    <Card title="Count sessions">
      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-5">
        {[
          { label: 'Sessions', value: counts.summary.sessions },
          { label: 'Submitted', value: counts.summary.submitted },
          { label: 'Entries', value: counts.summary.entries },
          { label: 'Exceptions', value: counts.summary.exceptions },
          { label: 'Unknown products', value: counts.summary.unknown_products },
        ].map(({ label, value }) => (
          <div key={label} className="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2 text-center">
            <p className="text-lg font-bold text-gray-800">{value}</p>
            <p className="text-xs text-gray-500">{label}</p>
          </div>
        ))}
      </div>

      {counts.sessions.length === 0 ? (
        <p className="text-sm text-gray-500">No count sessions yet.</p>
      ) : (
        <div className="space-y-3">
          {counts.sessions.map((s) => (
            <SessionCard key={s.id} session={s} scope={uniqueScope} />
          ))}
        </div>
      )}
    </Card>
  )
}
