import { useState } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { SkillInput } from '../../lib/api/workforce'
import { formatDateTime, formatMoney, formatStatusLabel } from '../../lib/formatting'
import {
  useRecordStockTakerTraining,
  useReplaceStockTakerSkills,
  useStockTaker,
  useUpdateStockTaker,
  useVerifyStockTaker,
} from '../../lib/workforce/hooks'

const LEVELS = ['applicant', 'trainee', 'verified', 'professional', 'expert', 'senior_supervisor']
const CHECK_TYPES = ['identity', 'phone', 'background', 'reference', 'training']

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Try again.'
}

function verificationTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'verified') return 'green'
  if (status === 'rejected') return 'red'
  if (status === 'pending') return 'amber'
  return 'gray'
}

export function PlatformStockTakerDetailPage() {
  const params = useParams({ strict: false }) as { profileId?: string }
  const profileId = params.profileId ?? null
  const detail = useStockTaker(profileId)

  if (detail.isLoading) return <PageLoader label="Loading stock taker…" />
  if (detail.isError || !detail.data) {
    return (
      <div className="mx-auto max-w-2xl space-y-4">
        <EmptyState title="Stock taker not found" description="The profile may have been removed or the link is invalid." />
        <Link to="/platform/workforce" className="text-sm font-medium text-brand-700 hover:underline">
          ← Back to workforce
        </Link>
      </div>
    )
  }

  const { profile, assignments } = detail.data

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <div>
        <Link to="/platform/workforce" className="text-sm font-medium text-brand-700 hover:underline">
          ← Workforce
        </Link>
      </div>

      <PageHeader
        title={profile.legal_name ?? 'Stock taker'}
        description={`${profile.user?.email ?? ''} ${profile.city ? `· ${profile.city}` : ''}`}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={verificationTone(profile.verification_status)}>
              {formatStatusLabel(profile.verification_status)}
            </Badge>
            <Badge tone={profile.status === 'active' ? 'green' : 'red'}>{formatStatusLabel(profile.status)}</Badge>
            <Badge tone="gray">{profile.level_label}</Badge>
          </div>
        }
      />

      <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        <div className="space-y-4">
          <ManageProfileCard profileId={profile.id} profile={profile} />
          <VerificationCard profileId={profile.id} profile={profile} />
          <TrainingCard profileId={profile.id} />
          <SkillsCard profileId={profile.id} profile={profile} />
        </div>

        <div className="space-y-4">
          <Card title="Profile snapshot">
            <dl className="space-y-2 text-sm">
              {[
                ['Phone', profile.phone ?? '—'],
                ['County', profile.county ?? '—'],
                ['Country', profile.country ?? '—'],
                ['Experience', `${profile.experience_years ?? 0} years`],
                ['Languages', profile.languages.join(', ') || '—'],
                ['Completed jobs', String(profile.completed_jobs_count)],
                ['Accuracy', profile.verified_accuracy != null ? `${profile.verified_accuracy}%` : '—'],
                ['Reliability', profile.reliability_score != null ? String(profile.reliability_score) : '—'],
                ['Service areas', profile.service_areas.map((area) => area.area).join(', ') || '—'],
                ['Availability', `${profile.availability.length} weekly window(s)`],
                ['Terms accepted', profile.terms_accepted_at ? formatDateTime(profile.terms_accepted_at) : '—'],
              ].map(([label, value]) => (
                <div key={label} className="flex justify-between gap-3">
                  <dt className="text-gray-500">{label}</dt>
                  <dd className="text-right font-medium text-gray-900">{value}</dd>
                </div>
              ))}
            </dl>
            {profile.stats && (
              <div className="mt-4 grid grid-cols-2 gap-3 border-t border-gray-100 pt-3 text-sm">
                <div>
                  <p className="text-gray-500">Active assignments</p>
                  <p className="text-lg font-semibold text-gray-900">{profile.stats.active_assignments}</p>
                </div>
                <div>
                  <p className="text-gray-500">Completed assignments</p>
                  <p className="text-lg font-semibold text-gray-900">{profile.stats.completed_assignments}</p>
                </div>
              </div>
            )}
          </Card>

          <Card title="Verification history">
            {profile.verification_checks.length === 0 ? (
              <p className="text-sm text-gray-500">No checks recorded.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {profile.verification_checks.map((check, index) => (
                  <li key={`${check.check_type}-${index}`} className="rounded-lg border border-gray-100 px-3 py-2">
                    <div className="flex items-center justify-between gap-2">
                      <span className="capitalize text-gray-800">{check.check_type}</span>
                      <Badge tone={verificationTone(check.status)}>{formatStatusLabel(check.status)}</Badge>
                    </div>
                    <p className="mt-1 text-xs text-gray-500">
                      {check.verified_at ? formatDateTime(check.verified_at) : 'Pending'}
                      {check.reference ? ` · ${check.reference}` : ''}
                      {check.verified_by ? ` · ${check.verified_by}` : ''}
                    </p>
                    {check.notes && <p className="mt-1 text-xs text-gray-600">{check.notes}</p>}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card title="Training records">
            {profile.trainings.length === 0 ? (
              <p className="text-sm text-gray-500">No training recorded.</p>
            ) : (
              <ul className="space-y-2 text-sm">
                {profile.trainings.map((training) => (
                  <li key={training.module} className="flex items-center justify-between gap-3">
                    <span className="text-gray-800">{training.module}</span>
                    <span className="text-gray-500">
                      {formatStatusLabel(training.status)}
                      {training.score != null ? ` · ${training.score}%` : ''}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>

      <Card title={`Assignment history (${assignments.length})`}>
        {assignments.length === 0 ? (
          <p className="text-sm text-gray-500">This stock taker has not been offered work yet.</p>
        ) : (
          <Table headers={['Job', 'Role', 'Status', 'Compensation', 'Offered']}>
            {assignments.map((assignment) => (
              <tr key={assignment.id}>
                <td className="px-4 py-3">
                  <p className="font-medium text-gray-900">{assignment.job?.reference ?? '—'}</p>
                  <p className="text-xs text-gray-500">{assignment.job?.tenant ?? ''}</p>
                </td>
                <td className="px-4 py-3 capitalize text-gray-600">{assignment.role}</td>
                <td className="px-4 py-3">
                  <Badge tone={assignment.status === 'accepted' ? 'green' : assignment.status === 'offered' ? 'amber' : 'gray'}>
                    {formatStatusLabel(assignment.status)}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-gray-700">
                  {formatMoney(assignment.compensation.amount, assignment.compensation.currency)}
                </td>
                <td className="px-4 py-3 text-gray-600">
                  {assignment.offered_at ? formatDateTime(assignment.offered_at) : '—'}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  )
}

interface ProfileLike {
  id: string
  level: string
  status: string
  verification_status: string
  skills: Array<{ skill: string; level?: string | null; years_experience?: number | null }>
}

function ManageProfileCard({ profileId, profile }: { profileId: string; profile: ProfileLike }) {
  const update = useUpdateStockTaker(profileId)
  const [level, setLevel] = useState(profile.level)
  const [status, setStatus] = useState(profile.status)
  const [reason, setReason] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    update.mutate(
      { level, status, reason: reason || undefined },
      {
        onSuccess: () => {
          setNotice('Profile updated. The change is on the audit trail.')
          setReason('')
        },
      },
    )
  }

  return (
    <Card title="Progression and access">
      <form className="space-y-3" onSubmit={submit}>
        {update.isError && <Alert kind="error">{errorMessage(update.error)}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select label="Level" name="level" value={level} onChange={(event) => setLevel(event.target.value)}>
            {LEVELS.map((option) => (
              <option key={option} value={option}>
                {formatStatusLabel(option)}
              </option>
            ))}
          </Select>
          <Select label="Status" name="status" value={status} onChange={(event) => setStatus(event.target.value)}>
            {['active', 'suspended', 'inactive'].map((option) => (
              <option key={option} value={option}>
                {formatStatusLabel(option)}
              </option>
            ))}
          </Select>
        </div>
        <Field
          label="Reason"
          name="reason"
          hint="Required when changing the progression level."
          value={reason}
          onChange={(event) => setReason(event.target.value)}
        />
        <Button type="submit" loading={update.isPending}>
          Save progression
        </Button>
      </form>
    </Card>
  )
}

function VerificationCard({ profileId, profile }: { profileId: string; profile: ProfileLike }) {
  const verify = useVerifyStockTaker(profileId)
  const [form, setForm] = useState({
    check_type: 'identity',
    decision: 'verified',
    reference: '',
    notes: '',
  })
  const [notice, setNotice] = useState<string | null>(null)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    verify.mutate(
      {
        check_type: form.check_type as 'identity',
        decision: form.decision as 'verified',
        reference: form.reference || null,
        notes: form.notes || null,
      },
      {
        onSuccess: () => {
          setNotice('Verification recorded. Progression was re-evaluated.')
          setForm({ ...form, reference: '', notes: '' })
        },
      },
    )
  }

  return (
    <Card
      title="Record verification"
      actions={
        <Badge tone={verificationTone(profile.verification_status)}>
          {formatStatusLabel(profile.verification_status)}
        </Badge>
      }
    >
      <form className="space-y-3" onSubmit={submit}>
        {verify.isError && <Alert kind="error">{errorMessage(verify.error)}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label="Check type"
            name="check_type"
            value={form.check_type}
            onChange={(event) => setForm({ ...form, check_type: event.target.value })}
          >
            {CHECK_TYPES.map((type) => (
              <option key={type} value={type}>
                {formatStatusLabel(type)}
              </option>
            ))}
          </Select>
          <Select
            label="Decision"
            name="decision"
            value={form.decision}
            onChange={(event) => setForm({ ...form, decision: event.target.value })}
          >
            {['pending', 'verified', 'rejected'].map((decision) => (
              <option key={decision} value={decision}>
                {formatStatusLabel(decision)}
              </option>
            ))}
          </Select>
          <Field
            label="Reference"
            name="reference"
            hint="Never enter full identity document numbers."
            value={form.reference}
            onChange={(event) => setForm({ ...form, reference: event.target.value })}
          />
          <Field
            label="Notes"
            name="notes"
            value={form.notes}
            onChange={(event) => setForm({ ...form, notes: event.target.value })}
          />
        </div>
        <Button type="submit" loading={verify.isPending}>
          Save verification
        </Button>
      </form>
    </Card>
  )
}

function TrainingCard({ profileId }: { profileId: string }) {
  const record = useRecordStockTakerTraining(profileId)
  const [module, setModule] = useState('core_induction')
  const [score, setScore] = useState('90')
  const [notice, setNotice] = useState<string | null>(null)

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    record.mutate(
      { module, status: 'completed', score: Number(score) || null },
      { onSuccess: () => setNotice('Training recorded. Progression was re-evaluated.') },
    )
  }

  return (
    <Card title="Training completion">
      <form className="space-y-3" onSubmit={submit}>
        {record.isError && <Alert kind="error">{errorMessage(record.error)}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="grid gap-3 sm:grid-cols-[2fr_1fr_auto]">
          <Field label="Module" name="module" required value={module} onChange={(event) => setModule(event.target.value)} />
          <Field
            label="Score (%)"
            name="score"
            type="number"
            min={0}
            max={100}
            value={score}
            onChange={(event) => setScore(event.target.value)}
          />
          <div className="flex items-end">
            <Button type="submit" loading={record.isPending}>
              Record
            </Button>
          </div>
        </div>
        <p className="text-xs text-gray-500">
          Completing core_induction with verification moves a trainee to verified and makes them assignment-eligible.
        </p>
      </form>
    </Card>
  )
}

function SkillsCard({ profileId, profile }: { profileId: string; profile: ProfileLike }) {
  const replace = useReplaceStockTakerSkills(profileId)
  const [rows, setRows] = useState<SkillInput[]>(
    profile.skills.map((skill) => ({
      skill: skill.skill,
      level: (skill.level as SkillInput['level']) ?? 'intermediate',
      years_experience: skill.years_experience ?? null,
    })),
  )
  const [notice, setNotice] = useState<string | null>(null)

  const update = (index: number, patch: Partial<SkillInput>) =>
    setRows(rows.map((row, position) => (position === index ? { ...row, ...patch } : row)))

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    replace.mutate(
      rows.filter((row) => row.skill.trim() !== ''),
      { onSuccess: () => setNotice('Skills saved. Future recommendations use the updated list.') },
    )
  }

  return (
    <Card title="Skills">
      <form className="space-y-3" onSubmit={submit}>
        {replace.isError && <Alert kind="error">{errorMessage(replace.error)}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        {rows.map((row, index) => (
          <div key={index} className="grid gap-3 sm:grid-cols-[2fr_1fr_1fr_auto]">
            <Field
              label="Skill"
              name={`skill-${index}`}
              value={row.skill}
              onChange={(event) => update(index, { skill: event.target.value })}
            />
            <Select
              label="Level"
              name={`skill-level-${index}`}
              value={row.level}
              onChange={(event) => update(index, { level: event.target.value as SkillInput['level'] })}
            >
              {['basic', 'intermediate', 'advanced'].map((level) => (
                <option key={level} value={level}>
                  {formatStatusLabel(level)}
                </option>
              ))}
            </Select>
            <Field
              label="Years"
              name={`skill-years-${index}`}
              type="number"
              min={0}
              value={row.years_experience ?? ''}
              onChange={(event) =>
                update(index, { years_experience: event.target.value === '' ? null : Number(event.target.value) })
              }
            />
            <div className="flex items-end">
              <Button type="button" variant="secondary" onClick={() => setRows(rows.filter((_, position) => position !== index))}>
                Remove
              </Button>
            </div>
          </div>
        ))}
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            variant="secondary"
            onClick={() => setRows([...rows, { skill: '', level: 'intermediate', years_experience: null }])}
          >
            Add skill
          </Button>
          <Button type="submit" loading={replace.isPending}>
            Save skills
          </Button>
        </div>
      </form>
    </Card>
  )
}
