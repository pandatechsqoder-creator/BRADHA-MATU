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
import {
  useAutomationExecutions,
  useAutomationKillSwitch,
  useAutomationWorkflowAction,
  useAutomationWorkflows,
  useCreateAutomationWorkflow,
  useRunDueAutomation,
  type AutomationRunView,
  type AutomationStepInput,
  type AutomationWorkflowInput,
  type AutomationWorkflowView,
} from '../../lib/marketing/automationHooks'
import { formatDateTime } from '../../lib/formatting'

const LEAD_TYPES = ['quote', 'pos_demo', 'contact', 'callback', 'partnership', 'other']
const EXTERNAL_CHANNELS = ['email', 'sms', 'whatsapp']

interface StepDraft {
  type: string
  recipients: string
  channel: string
  title: string
  body: string
  minutes: string
}

const emptyStep = (type: string): StepDraft => ({
  type,
  recipients: 'lead_managers',
  channel: 'email',
  title: '',
  body: '',
  minutes: '30',
})

function buildConfig(step: StepDraft): Record<string, unknown> {
  if (step.type === 'wait') return { minutes: Number(step.minutes) }
  if (step.type === 'add_activity') return { body: step.body }
  if (step.type === 'notify_external') {
    return { channel: step.channel, title: step.title, body: step.body }
  }
  return { recipients: step.recipients, title: step.title, body: step.body }
}

function stepSummary(step: { type: string; config: Record<string, unknown> }): string {
  switch (step.type) {
    case 'wait':
      return `Wait ${String(step.config.minutes ?? '?')} minutes`
    case 'add_activity':
      return 'Add a lead activity'
    case 'notify_external':
      return `Message the lead via ${String(step.config.channel ?? 'email')}`
    default:
      return `Notify ${String(step.config.recipients ?? 'lead_managers').replace(/_/g, ' ')}`
  }
}

function statusTone(status: string): 'green' | 'amber' | 'red' | 'gray' | 'blue' {
  if (['completed', 'active'].includes(status)) return 'green'
  if (['waiting', 'queued'].includes(status)) return 'blue'
  if (['blocked', 'paused'].includes(status)) return 'amber'
  if (status === 'failed') return 'red'
  return 'gray'
}

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Try again.'
}

function WorkflowRow({
  workflow,
  canManage,
  onSelect,
  onAction,
  busy,
}: {
  workflow: AutomationWorkflowView
  canManage: boolean
  onSelect: (id: string) => void
  onAction: (id: string, action: 'activate' | 'pause') => void
  busy: boolean
}) {
  return (
    <tr data-testid={`automation-workflow-${workflow.id}`}>
      <td className="px-4 py-3">
        <p className="font-medium text-gray-900">{workflow.name}</p>
        <p className="text-xs text-gray-500">{workflow.trigger_label}</p>
        <p className="mt-1 text-xs text-gray-500">
          {workflow.steps.map((step) => stepSummary(step)).join(' → ')}
        </p>
      </td>
      <td className="px-4 py-3">
        <span data-testid={`automation-status-${workflow.id}`}>
          <Badge tone={statusTone(workflow.status)}>{workflow.status}</Badge>
        </span>
      </td>
      <td className="px-4 py-3 text-gray-600">
        {workflow.conditions?.lead_type
          ? (workflow.conditions.lead_type as string[]).join(', ')
          : 'Any lead'}
      </td>
      <td className="px-4 py-3 text-gray-600">
        <p>{workflow.stats.completed} completed</p>
        <p className="text-xs text-gray-500">
          {workflow.stats.waiting} waiting · {workflow.stats.blocked} blocked · {workflow.stats.failed} failed
        </p>
      </td>
      <td className="px-4 py-3 text-gray-500">
        {workflow.stats.last_run?.created_at ? formatDateTime(workflow.stats.last_run.created_at) : '—'}
      </td>
      <td className="px-4 py-3">
        <div className="flex flex-wrap gap-2">
          {canManage && workflow.status !== 'active' && (
            <Button
              variant="secondary"
              onClick={() => onAction(workflow.id, 'activate')}
              disabled={busy}
              data-testid={`automation-activate-${workflow.id}`}
            >
              Activate
            </Button>
          )}
          {canManage && workflow.status === 'active' && (
            <Button
              variant="secondary"
              onClick={() => onAction(workflow.id, 'pause')}
              disabled={busy}
              data-testid={`automation-pause-${workflow.id}`}
            >
              Pause
            </Button>
          )}
          <Button
            variant="secondary"
            onClick={() => onSelect(workflow.id)}
            data-testid={`automation-executions-${workflow.id}`}
          >
            Executions
          </Button>
        </div>
      </td>
    </tr>
  )
}

function runDetail(run: AutomationRunView): string {
  const blocked = run.steps.find((step) => step.status === 'blocked' || step.last_error)
  if (blocked) {
    return String(blocked.output?.message ?? blocked.last_error ?? 'Step blocked.')
  }
  return run.last_error ?? '—'
}

export function AutomationPage() {
  const { data: user } = useCurrentUser()
  const permissions = new Set(user?.permissions ?? [])
  const canManage = permissions.has('automation.manage')

  const workflows = useAutomationWorkflows()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const executions = useAutomationExecutions(selectedId)
  const createWorkflow = useCreateAutomationWorkflow()
  const action = useAutomationWorkflowAction()
  const killSwitch = useAutomationKillSwitch()
  const runDue = useRunDueAutomation()

  const [message, setMessage] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [trigger, setTrigger] = useState('lead.created')
  const [leadTypes, setLeadTypes] = useState<string[]>([])
  const [utmCampaign, setUtmCampaign] = useState('')
  const [steps, setSteps] = useState<StepDraft[]>([emptyStep('notify_internal'), emptyStep('add_activity')])

  if (workflows.isLoading) return <PageLoader label="Loading automation…" />

  if (workflows.isError) {
    return <Alert kind="error">{errorMessage(workflows.error)}</Alert>
  }

  const settings = workflows.data?.settings
  const list = workflows.data?.workflows ?? []
  const enabled = settings?.enabled ?? true
  const selectedName = list.find((workflow) => workflow.id === selectedId)?.name

  const toggleLeadType = (type: string) => {
    setLeadTypes((current) =>
      current.includes(type) ? current.filter((item) => item !== type) : [...current, type],
    )
  }

  const updateStep = (index: number, patch: Partial<StepDraft>) => {
    setSteps((current) => current.map((step, position) => (position === index ? { ...step, ...patch } : step)))
  }

  const submit = () => {
    setMessage('')
    const conditions: AutomationWorkflowInput['conditions'] = {}
    if (leadTypes.length > 0) conditions.lead_type = leadTypes
    if (utmCampaign.trim() !== '') conditions.utm_campaign = utmCampaign.trim()

    const input: AutomationWorkflowInput = {
      name: name.trim(),
      description: description.trim() === '' ? undefined : description.trim(),
      trigger,
      conditions: Object.keys(conditions).length > 0 ? conditions : undefined,
      steps: steps.map((step): AutomationStepInput => ({ type: step.type, config: buildConfig(step) })),
    }

    createWorkflow.mutate(input, {
      onSuccess: (data) => {
        setMessage(`Workflow “${data.workflow.name}” saved as a draft. Activate it to start listening for events.`)
        setName('')
        setDescription('')
        setLeadTypes([])
        setUtmCampaign('')
        setSteps([emptyStep('notify_internal'), emptyStep('add_activity')])
        setFormOpen(false)
      },
    })
  }

  const toggleKillSwitch = () => {
    const next = !enabled
    if (!next && !window.confirm('Pause all marketing automation? Events are still recorded but no steps will run.')) {
      return
    }
    setMessage('')
    killSwitch.mutate(next, {
      onSuccess: (data) => setMessage(data.enabled ? 'Automation resumed.' : 'Automation paused by the kill switch.'),
    })
  }

  return (
    <div className="space-y-6" data-testid="automation-page">
      <PageHeader
        title="Automation"
        description="Event-driven follow-up for leads: internal notifications, activities and timed waits. Outbound email, SMS and WhatsApp stay blocked until an approved provider is connected."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="secondary" onClick={() => runDue.mutate(undefined, {
              onSuccess: (data) => setMessage(`${data.processed} due step(s) processed.`),
              onError: (error) => setMessage(errorMessage(error)),
            })} loading={runDue.isPending} data-testid="automation-run-due">
              Run due steps
            </Button>
            {canManage && (
              <Button
                variant={enabled ? 'danger' : 'primary'}
                onClick={toggleKillSwitch}
                loading={killSwitch.isPending}
                data-testid="automation-kill-switch"
              >
                {enabled ? 'Pause automation' : 'Resume automation'}
              </Button>
            )}
          </div>
        }
      />

      {message && <Alert kind="success">{message}</Alert>}

      <div
        className={`rounded-lg border px-4 py-3 text-sm ${
          enabled ? 'border-brand-200 bg-brand-50 text-brand-800' : 'border-amber-200 bg-amber-50 text-amber-800'
        }`}
        data-testid="automation-kill-switch-state"
      >
        <span className="font-medium">{enabled ? 'Automation is running.' : 'Automation is paused.'}</span>{' '}
        {enabled
          ? 'Active workflows advance as lead events are recorded.'
          : 'Events are still recorded as blocked runs, but no steps execute until automation is resumed.'}
      </div>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Workflows" value={list.length} hint={`${list.filter((w) => w.status === 'active').length} active`} />
        <StatCard label="Runs recorded" value={list.reduce((total, w) => total + w.stats.runs, 0)} hint="Every trigger is logged" />
        <StatCard label="Completed" value={list.reduce((total, w) => total + w.stats.completed, 0)} hint="All steps executed" />
        <StatCard
          label="Blocked or failed"
          value={list.reduce((total, w) => total + w.stats.blocked + w.stats.failed, 0)}
          hint="Consent, provider or errors"
        />
      </div>

      {settings && !settings.outbound_provider.approved && (
        <Alert kind="info" >
          <span data-testid="automation-provider-notice">{settings.outbound_provider.message}</span>
        </Alert>
      )}

      <Card
        title="Workflows"
        actions={
          canManage ? (
            <Button variant="secondary" onClick={() => setFormOpen((open) => !open)} data-testid="automation-new">
              {formOpen ? 'Close form' : 'New workflow'}
            </Button>
          ) : null
        }
      >
        <Table
          headers={['Workflow', 'Status', 'Conditions', 'Runs', 'Last run', 'Actions']}
          empty={<EmptyState title="No workflows yet" description="Create a workflow to follow up leads automatically." />}
        >
          {list.map((workflow) => (
            <WorkflowRow
              key={workflow.id}
              workflow={workflow}
              canManage={canManage}
              onSelect={setSelectedId}
              onAction={(id, next) => {
                setMessage('')
                action.mutate({ id, action: next }, {
                  onSuccess: (data) => setMessage(`Workflow “${data.workflow.name}” is now ${data.workflow.status}.`),
                  onError: (error) => setMessage(errorMessage(error)),
                })
              }}
              busy={action.isPending}
            />
          ))}
        </Table>
      </Card>

      {formOpen && canManage && settings && (
        <Card title="New workflow">
          <div className="space-y-4" data-testid="automation-form">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Name" id="automation-name-field" value={name} onChange={(event) => setName(event.target.value)} data-testid="automation-name" />
              <Select label="Trigger" id="automation-trigger-field" value={trigger} onChange={(event) => setTrigger(event.target.value)} data-testid="automation-trigger">
                {settings.triggers.map((item) => (
                  <option key={item.value} value={item.value}>{item.label}</option>
                ))}
              </Select>
            </div>
            <Field label="Description" id="automation-description" hint="Optional internal note." value={description} onChange={(event) => setDescription(event.target.value)} />
            <div>
              <p className="mb-2 text-xs font-medium text-gray-600">Only run for these lead types</p>
              <div className="flex flex-wrap gap-3">
                {LEAD_TYPES.map((type) => (
                  <label key={type} className="flex items-center gap-2 text-sm text-gray-700">
                    <input
                      type="checkbox"
                      checked={leadTypes.includes(type)}
                      onChange={() => toggleLeadType(type)}
                      data-testid={`automation-lead-type-${type}`}
                    />
                    {type.replace(/_/g, ' ')}
                  </label>
                ))}
              </div>
              <p className="mt-1 text-xs text-gray-500">Leave all unchecked to run for every lead type.</p>
            </div>
            <Field
              label="UTM campaign filter"
              id="automation-utm-campaign"
              hint="Optional. Only leads attributed to this campaign run the workflow."
              value={utmCampaign}
              onChange={(event) => setUtmCampaign(event.target.value)}
            />

            <div className="space-y-3">
              <p className="text-sm font-medium text-gray-800">Steps run in order</p>
              {steps.map((step, index) => (
                <div key={index} className="rounded-lg border border-gray-200 p-3" data-testid={`automation-step-${index}`}>
                  <div className="flex flex-wrap items-end gap-3">
                    <Select
                      label={`Step ${index + 1}`}
                      value={step.type}
                      onChange={(event) => setSteps((current) => current.map((item, position) => (
                        position === index ? emptyStep(event.target.value) : item
                      )))}
                      data-testid={`automation-step-${index}-type`}
                    >
                      {settings.step_types.map((item) => (
                        <option key={item.value} value={item.value}>{item.label}</option>
                      ))}
                    </Select>
                    {steps.length > 1 && (
                      <Button
                        variant="secondary"
                        onClick={() => setSteps((current) => current.filter((_, position) => position !== index))}
                      >
                        Remove
                      </Button>
                    )}
                  </div>

                  <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                    {step.type === 'wait' && (
                      <Field
                        label="Minutes to wait"
                        id={`automation-step-${index}-minutes`}
                        type="number"
                        min={1}
                        max={43200}
                        value={step.minutes}
                        onChange={(event) => updateStep(index, { minutes: event.target.value })}
                      />
                    )}
                    {step.type === 'notify_internal' && (
                      <Select
                        label="Recipients"
                        id={`automation-step-${index}-recipients`}
                        value={step.recipients}
                        onChange={(event) => updateStep(index, { recipients: event.target.value })}
                      >
                        <option value="lead_managers">Lead managers</option>
                        <option value="lead_owner">Lead owner</option>
                      </Select>
                    )}
                    {step.type === 'notify_external' && (
                      <Select
                        label="Channel"
                        id={`automation-step-${index}-channel`}
                        value={step.channel}
                        onChange={(event) => updateStep(index, { channel: event.target.value })}
                      >
                        {EXTERNAL_CHANNELS.map((channel) => (
                          <option key={channel} value={channel}>{channel}</option>
                        ))}
                      </Select>
                    )}
                    {step.type !== 'add_activity' && step.type !== 'wait' && (
                      <Field
                        label={step.type === 'notify_external' ? 'Subject' : 'Notification title'}
                        id={`automation-step-${index}-title`}
                        hint="Supports {contact_name}, {business_name}, {reference}, {type}."
                        value={step.title}
                        onChange={(event) => updateStep(index, { title: event.target.value })}
                      />
                    )}
                  </div>

                  {step.type !== 'wait' && (
                    <div className="mt-3">
                      <Field
                        label={step.type === 'add_activity' ? 'Activity note' : 'Message body'}
                        id={`automation-step-${index}-body`}
                        hint="Supports the same placeholders."
                      >
                        <textarea
                          id={`automation-step-${index}-body`}
                          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                          rows={2}
                          value={step.body}
                          onChange={(event) => updateStep(index, { body: event.target.value })}
                        />
                      </Field>
                    </div>
                  )}
                </div>
              ))}
              <div className="flex flex-wrap gap-2">
                {steps.length < 10 && (
                  <Button variant="secondary" onClick={() => setSteps((current) => [...current, emptyStep('add_activity')])} data-testid="automation-add-step">
                    Add step
                  </Button>
                )}
                <Button onClick={submit} loading={createWorkflow.isPending} data-testid="automation-submit">
                  Save workflow
                </Button>
              </div>
              {createWorkflow.isError && <Alert kind="error">{errorMessage(createWorkflow.error)}</Alert>}
            </div>
          </div>
        </Card>
      )}

      {selectedId && (
        <Card
          title={`Executions${selectedName ? `: ${selectedName}` : ''}`}
          actions={<Button variant="secondary" onClick={() => setSelectedId(null)}>Close</Button>}
        >
          {executions.isLoading && <PageLoader label="Loading executions…" />}
          {executions.isError && <Alert kind="error">{errorMessage(executions.error)}</Alert>}
          {executions.data && (
            <div data-testid="automation-executions">
              <Table
                headers={['Lead', 'Trigger', 'Status', 'Steps', 'Reason', 'Started', 'Finished']}
                empty={<EmptyState title="No runs yet" description="Runs appear when a matching lead event is recorded." />}
              >
                {executions.data.runs.map((run) => (
                  <tr key={run.id} data-testid={`automation-run-${run.id}`}>
                    <td className="px-4 py-3">
                      <p className="font-medium text-gray-900">{run.lead?.reference ?? '—'}</p>
                      <p className="text-xs text-gray-500">{run.lead?.contact_name ?? 'Lead removed'}</p>
                    </td>
                    <td className="px-4 py-3 text-gray-600">{run.trigger}</td>
                    <td className="px-4 py-3"><Badge tone={statusTone(run.status)}>{run.status}</Badge></td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        {run.steps.map((step) => (
                          <Badge key={step.position} tone={statusTone(step.status)}>
                            {step.type}: {step.status}
                          </Badge>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-600">
                      <span data-testid={`automation-run-reason-${run.id}`}>{runDetail(run)}</span>
                    </td>
                    <td className="px-4 py-3 text-xs text-gray-500">{run.started_at ? formatDateTime(run.started_at) : '—'}</td>
                    <td className="px-4 py-3 text-xs text-gray-500">{run.finished_at ? formatDateTime(run.finished_at) : '—'}</td>
                  </tr>
                ))}
              </Table>
              <p className="mt-3 text-xs text-gray-500">
                Every trigger is logged with a unique event key, so retries and duplicate form submissions never run a workflow twice.
                Waiting runs resume through the scheduled <code>marketing:automation:run</code> command.
              </p>
            </div>
          )}
        </Card>
      )}
    </div>
  )
}
