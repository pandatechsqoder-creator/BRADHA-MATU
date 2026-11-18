import { Button, Field, Select } from '../../components/ui'
import type { ProcedureChecklistInput, ProcedureTemplateStepInput } from '../../lib/api/operations'

export interface StepDraft {
  title: string
  instructions: string
  evidence_required: boolean
}

export interface ChecklistItemDraft {
  label: string
  response_type: 'boolean' | 'text' | 'select'
  required: boolean
  options: string
}

export interface ChecklistDraft {
  name: string
  kind: 'pre_start' | 'site' | 'close_out'
  required: boolean
  items: ChecklistItemDraft[]
}

export interface ProcedureDraft {
  steps: StepDraft[]
  checklists: ChecklistDraft[]
}

export function emptyProcedureDraft(): ProcedureDraft {
  return {
    steps: [{ title: '', instructions: '', evidence_required: false }],
    checklists: [],
  }
}

export function toProcedureInput(draft: ProcedureDraft): {
  steps: ProcedureTemplateStepInput[]
  checklists: ProcedureChecklistInput[]
} {
  const steps = draft.steps
    .filter((step) => step.title.trim())
    .map((step) => ({
      title: step.title.trim(),
      instructions: step.instructions.trim() || null,
      evidence_required: step.evidence_required,
    }))

  const checklists = draft.checklists
    .filter((checklist) => checklist.name.trim() && checklist.items.some((item) => item.label.trim()))
    .map((checklist) => ({
      name: checklist.name.trim(),
      kind: checklist.kind,
      required: checklist.required,
      items: checklist.items
        .filter((item) => item.label.trim())
        .map((item) => ({
          label: item.label.trim(),
          response_type: item.response_type,
          required: item.required,
          options:
            item.response_type === 'select'
              ? item.options
                  .split(',')
                  .map((option) => option.trim())
                  .filter(Boolean)
              : null,
        })),
    }))

  return { steps, checklists }
}

export function ProcedureEditor({
  draft,
  onChange,
}: {
  draft: ProcedureDraft
  onChange: (next: ProcedureDraft) => void
}) {
  const updateStep = (index: number, patch: Partial<StepDraft>) => {
    const steps = draft.steps.map((step, position) => (position === index ? { ...step, ...patch } : step))
    onChange({ ...draft, steps })
  }

  const updateChecklist = (index: number, patch: Partial<ChecklistDraft>) => {
    const checklists = draft.checklists.map((checklist, position) =>
      position === index ? { ...checklist, ...patch } : checklist,
    )
    onChange({ ...draft, checklists })
  }

  const updateItem = (checklistIndex: number, itemIndex: number, patch: Partial<ChecklistItemDraft>) => {
    const checklists = draft.checklists.map((checklist, position) =>
      position === checklistIndex
        ? {
            ...checklist,
            items: checklist.items.map((item, itemPosition) =>
              itemPosition === itemIndex ? { ...item, ...patch } : item,
            ),
          }
        : checklist,
    )
    onChange({ ...draft, checklists })
  }

  return (
    <div className="space-y-5">
      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-900">Procedure steps</h3>
          <Button
            type="button"
            variant="secondary"
            onClick={() =>
              onChange({ ...draft, steps: [...draft.steps, { title: '', instructions: '', evidence_required: false }] })
            }
          >
            Add step
          </Button>
        </div>
        {draft.steps.map((step, index) => (
          <div key={index} className="space-y-2 rounded-lg border border-gray-200 p-3">
            <div className="flex items-center justify-between">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-500">Step {index + 1}</p>
              {draft.steps.length > 1 && (
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => onChange({ ...draft, steps: draft.steps.filter((_, position) => position !== index) })}
                >
                  Remove
                </Button>
              )}
            </div>
            <Field
              label="Title"
              value={step.title}
              onChange={(event) => updateStep(index, { title: event.target.value })}
            />
            <Field
              label="Instructions"
              value={step.instructions}
              onChange={(event) => updateStep(index, { instructions: event.target.value })}
            />
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={step.evidence_required}
                onChange={(event) => updateStep(index, { evidence_required: event.target.checked })}
              />
              Evidence required
            </label>
          </div>
        ))}
      </section>

      <section className="space-y-3">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold text-gray-900">Checklists</h3>
          <Button
            type="button"
            variant="secondary"
            onClick={() =>
              onChange({
                ...draft,
                checklists: [
                  ...draft.checklists,
                  {
                    name: '',
                    kind: 'pre_start',
                    required: true,
                    items: [{ label: '', response_type: 'boolean', required: true, options: '' }],
                  },
                ],
              })
            }
          >
            Add checklist
          </Button>
        </div>

        {draft.checklists.length === 0 && (
          <p className="text-sm text-gray-500">No checklists. The SOP can still require acknowledgement.</p>
        )}

        {draft.checklists.map((checklist, checklistIndex) => (
          <div key={checklistIndex} className="space-y-3 rounded-lg border border-gray-200 p-3">
            <div className="flex items-center justify-between">
              <p className="text-xs font-medium uppercase tracking-wide text-gray-500">
                Checklist {checklistIndex + 1}
              </p>
              <Button
                type="button"
                variant="secondary"
                onClick={() =>
                  onChange({
                    ...draft,
                    checklists: draft.checklists.filter((_, position) => position !== checklistIndex),
                  })
                }
              >
                Remove checklist
              </Button>
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
              <Field
                label="Name"
                value={checklist.name}
                onChange={(event) => updateChecklist(checklistIndex, { name: event.target.value })}
              />
              <Select
                label="Kind"
                value={checklist.kind}
                onChange={(event) =>
                  updateChecklist(checklistIndex, { kind: event.target.value as ChecklistDraft['kind'] })
                }
              >
                <option value="pre_start">Pre-start</option>
                <option value="site">On site</option>
                <option value="close_out">Close-out</option>
              </Select>
            </div>

            <ul className="space-y-2">
              {checklist.items.map((item, itemIndex) => (
                <li key={itemIndex} className="space-y-2 rounded-lg bg-gray-50 p-2">
                  <div className="grid gap-2 sm:grid-cols-2">
                    <Field
                      label="Item label"
                      value={item.label}
                      onChange={(event) => updateItem(checklistIndex, itemIndex, { label: event.target.value })}
                    />
                    <Select
                      label="Response type"
                      value={item.response_type}
                      onChange={(event) =>
                        updateItem(checklistIndex, itemIndex, {
                          response_type: event.target.value as ChecklistItemDraft['response_type'],
                        })
                      }
                    >
                      <option value="boolean">Yes / No</option>
                      <option value="text">Text</option>
                      <option value="select">Select</option>
                    </Select>
                  </div>
                  {item.response_type === 'select' && (
                    <Field
                      label="Options (comma separated)"
                      value={item.options}
                      onChange={(event) => updateItem(checklistIndex, itemIndex, { options: event.target.value })}
                    />
                  )}
                  <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-gray-700">
                      <input
                        type="checkbox"
                        checked={item.required}
                        onChange={(event) =>
                          updateItem(checklistIndex, itemIndex, { required: event.target.checked })
                        }
                      />
                      Required
                    </label>
                    <Button
                      type="button"
                      variant="secondary"
                      onClick={() =>
                        updateChecklist(checklistIndex, {
                          items: checklist.items.filter((_, position) => position !== itemIndex),
                        })
                      }
                    >
                      Remove item
                    </Button>
                  </div>
                </li>
              ))}
            </ul>

            <Button
              type="button"
              variant="secondary"
              onClick={() =>
                updateChecklist(checklistIndex, {
                  items: [
                    ...checklist.items,
                    { label: '', response_type: 'boolean', required: true, options: '' },
                  ],
                })
              }
            >
              Add item
            </Button>
          </div>
        ))}
      </section>
    </div>
  )
}
