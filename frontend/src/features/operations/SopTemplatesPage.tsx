import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader } from '../../components/ui'
import type { ProcedureTemplate, ProcedureTemplateVersion } from '../../lib/api/operations'
import { formatDate } from '../../lib/formatting'
import {
  useCreateProcedureTemplate,
  useProcedureTemplates,
  usePublishProcedureVersion,
} from '../../lib/operations/hooks'
import {
  emptyProcedureDraft,
  ProcedureEditor,
  toProcedureInput,
  type ProcedureDraft,
} from './ProcedureEditor'
import { describeError } from './shared'

function draftsFromVersion(version: ProcedureTemplateVersion | null): ProcedureDraft {
  const base = emptyProcedureDraft()
  if (!version) return base

  const steps = Array.isArray(version.steps)
    ? version.steps.map((step) => ({
        title: step.title,
        instructions: step.instructions ?? '',
        evidence_required: step.evidence_required,
      }))
    : []

  return {
    steps: steps.length > 0 ? steps : base.steps,
    checklists: version.checklists.map((checklist) => ({
      name: checklist.name,
      kind: checklist.kind,
      required: checklist.required,
      items: checklist.items.map((item) => ({
        label: item.label,
        response_type: item.response_type,
        required: item.required,
        options: (item.options ?? []).join(', '),
      })),
    })),
  }
}

function TemplateCard({
  template,
  onPublish,
}: {
  template: ProcedureTemplate
  onPublish: (template: ProcedureTemplate) => void
}) {
  const current = template.current_version
  const stepCount = current ? (Array.isArray(current.steps) ? current.steps.length : current.steps) : 0
  const checklistItems = current
    ? current.checklists.reduce((total, checklist) => total + checklist.items.length, 0)
    : 0

  return (
    <li className="rounded-lg border border-gray-200 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="font-medium text-gray-900">{template.name}</p>
          <p className="text-xs text-gray-500">
            {template.slug} · {template.category} · {template.scope === 'platform' ? 'Platform-wide' : 'Tenant-specific'}
          </p>
          {template.description && <p className="mt-1 text-sm text-gray-600">{template.description}</p>}
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={template.status === 'active' ? 'green' : 'gray'}>{template.status}</Badge>
          {current && <Badge tone="gray">v{current.version}</Badge>}
          <Button variant="secondary" onClick={() => onPublish(template)}>
            Publish version
          </Button>
        </div>
      </div>

      {current && Array.isArray(current.steps) && current.steps.length > 0 && (
        <div className="mt-3 grid gap-3 lg:grid-cols-2">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
              Current version · {stepCount} steps
            </p>
            <ol className="mt-1 space-y-1 text-sm text-gray-600">
              {current.steps.map((step) => (
                <li key={step.title}>
                  {step.title}
                  {step.evidence_required && <span className="ml-1 text-xs text-amber-700">evidence</span>}
                </li>
              ))}
            </ol>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">
              Checklists · {current.checklists.length} · {checklistItems} items
            </p>
            <ul className="mt-1 space-y-1 text-sm text-gray-600">
              {current.checklists.map((checklist) => (
                <li key={checklist.name}>
                  {checklist.name}{' '}
                  <span className="text-xs text-gray-500">({checklist.kind.replace('_', ' ')})</span>
                </li>
              ))}
              {current.checklists.length === 0 && <li className="text-gray-500">No checklists</li>}
            </ul>
          </div>
        </div>
      )}

      <p className="mt-3 text-xs text-gray-500">
        {template.versions.length} version(s) · updated {template.updated_at ? formatDate(template.updated_at) : '—'}
      </p>
    </li>
  )
}

function PublishVersionForm({
  template,
  onClose,
}: {
  template: ProcedureTemplate
  onClose: () => void
}) {
  const publish = usePublishProcedureVersion(template.id)
  const current = template.current_version
  const [title, setTitle] = useState(current?.title ?? template.name)
  const [summary, setSummary] = useState(current?.summary ?? '')
  const [requireAcknowledgement, setRequireAcknowledgement] = useState(
    current?.require_acknowledgement ?? true,
  )
  const [draft, setDraft] = useState<ProcedureDraft>(() => draftsFromVersion(current))
  const [notice, setNotice] = useState<string | null>(null)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const { steps, checklists } = toProcedureInput(draft)
    publish.mutate(
      {
        title: title.trim(),
        summary: summary.trim() || null,
        require_acknowledgement: requireAcknowledgement,
        steps,
        checklists,
      },
      {
        onSuccess: (template) => {
          setNotice(`Version ${template.current_version?.version ?? ''} published. Jobs keep their assigned snapshot.`)
        },
      },
    )
  }

  return (
    <Card
      title={`Publish new version · ${template.name}`}
      actions={
        <div className="flex items-center gap-2">
          <Badge tone="amber">Draft</Badge>
          <Button type="button" variant="secondary" onClick={onClose}>
            Close
          </Button>
        </div>
      }
    >
      <form className="space-y-4" onSubmit={submit}>
        {notice && <Alert kind="success">{notice}</Alert>}
        {publish.isError && <Alert kind="error">{describeError(publish.error)}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Version title" value={title} onChange={(event) => setTitle(event.target.value)} />
          <Field label="Summary" value={summary} onChange={(event) => setSummary(event.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={requireAcknowledgement}
            onChange={(event) => setRequireAcknowledgement(event.target.checked)}
          />
          Workers must acknowledge this SOP before check-in
        </label>

        <ProcedureEditor draft={draft} onChange={setDraft} />

        <Button
          type="submit"
          loading={publish.isPending}
          disabled={toProcedureInput(draft).steps.length === 0}
        >
          Publish version
        </Button>
        <p className="text-xs text-gray-500">
          New versions do not rewrite jobs already assigned; assign the new version from the job’s operations panel.
        </p>
      </form>
    </Card>
  )
}

function CreateTemplateForm({ onClose }: { onClose: () => void }) {
  const create = useCreateProcedureTemplate()
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('stocktake')
  const [title, setTitle] = useState('')
  const [summary, setSummary] = useState('')
  const [requireAcknowledgement, setRequireAcknowledgement] = useState(true)
  const [draft, setDraft] = useState<ProcedureDraft>(emptyProcedureDraft)
  const [notice, setNotice] = useState<string | null>(null)

  const submit = (event: FormEvent) => {
    event.preventDefault()
    const { steps, checklists } = toProcedureInput(draft)
    create.mutate(
      {
        name: name.trim(),
        description: description.trim() || null,
        category: category.trim() || null,
        title: title.trim() || null,
        summary: summary.trim() || null,
        require_acknowledgement: requireAcknowledgement,
        steps,
        checklists,
      },
      {
        onSuccess: (template) => {
          setNotice(`${template.name} created with version 1.`)
          setName('')
          setDescription('')
          setDraft(emptyProcedureDraft())
        },
      },
    )
  }

  return (
    <Card
      title="New SOP template"
      actions={
        <Button type="button" variant="secondary" onClick={onClose}>
          Close
        </Button>
      }
    >
      <form className="space-y-4" onSubmit={submit}>
        {notice && <Alert kind="success">{notice}</Alert>}
        {create.isError && <Alert kind="error">{describeError(create.error)}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Template name" value={name} onChange={(event) => setName(event.target.value)} />
          <Field label="Category" value={category} onChange={(event) => setCategory(event.target.value)} />
        </div>
        <Field label="Description" value={description} onChange={(event) => setDescription(event.target.value)} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Version title" value={title} onChange={(event) => setTitle(event.target.value)} />
          <Field label="Version summary" value={summary} onChange={(event) => setSummary(event.target.value)} />
        </div>
        <label className="flex items-center gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={requireAcknowledgement}
            onChange={(event) => setRequireAcknowledgement(event.target.checked)}
          />
          Workers must acknowledge this SOP before check-in
        </label>

        <ProcedureEditor draft={draft} onChange={setDraft} />

        <Button
          type="submit"
          loading={create.isPending}
          disabled={!name.trim() || toProcedureInput(draft).steps.length === 0}
        >
          Create template
        </Button>
      </form>
    </Card>
  )
}

export function SopTemplatesPage() {
  const [search, setSearch] = useState('')
  const templates = useProcedureTemplates(search)
  const [creating, setCreating] = useState(false)
  const [publishing, setPublishing] = useState<ProcedureTemplate | null>(null)

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="SOP templates"
        description="Versioned procedure templates. Publishing a version never rewrites jobs that already hold an assigned snapshot."
        actions={
          <Button onClick={() => setCreating((open) => !open)}>{creating ? 'Close editor' : 'New SOP template'}</Button>
        }
      />

      {creating && <CreateTemplateForm onClose={() => setCreating(false)} />}
      {publishing && <PublishVersionForm template={publishing} onClose={() => setPublishing(null)} />}

      <Card
        title="Template library"
        actions={
          <div className="w-64">
            <Field
              label="Search"
              name="sop-search"
              placeholder="Name or description"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
        }
      >
        {templates.isLoading && <PageLoader label="Loading SOP templates…" />}
        {templates.isError && <Alert kind="error">{describeError(templates.error)}</Alert>}
        {!templates.isLoading && !templates.isError && (templates.data?.length ?? 0) === 0 && (
          <EmptyState
            title="No SOP templates"
            description="Create a template and publish version 1 before assigning procedures to jobs."
          />
        )}
        <ul className="space-y-3">
          {(templates.data ?? []).map((template) => (
            <TemplateCard key={template.id} template={template} onPublish={setPublishing} />
          ))}
        </ul>
        <p className="mt-3 text-xs text-gray-500">
          Checklist options are stored with each version. Required checklist items block site check-in until answered.
        </p>
      </Card>
    </div>
  )
}
