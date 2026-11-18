import { useEffect, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Alert, Badge, Button, Card, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type {
  AvailabilityInput,
  ServiceAreaInput,
  SkillInput,
  WorkforceProfile,
} from '../../lib/api/workforce'
import {
  useReplaceWorkforceAvailability,
  useReplaceWorkforceServiceAreas,
  useReplaceWorkforceSkills,
  useSaveWorkforceProfile,
  useWorkforceProfile,
} from '../../lib/workforce/hooks'

const WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
const SKILL_LEVELS = ['basic', 'intermediate', 'advanced'] as const

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Try again.'
}

function StatusBadge({ tone, children }: { tone: 'green' | 'amber' | 'red' | 'gray'; children: string }) {
  return <Badge tone={tone}>{children}</Badge>
}

function verificationTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'verified') return 'green'
  if (status === 'rejected') return 'red'
  if (status === 'pending') return 'amber'
  return 'gray'
}

export function WorkforceProfilePage() {
  const { data: profile, isLoading, isError } = useWorkforceProfile()

  if (isLoading) return <PageLoader label="Loading your workforce profile…" />
  if (isError) {
    return (
      <div className="mx-auto max-w-3xl">
        <Alert kind="error">Your workforce profile could not be loaded.</Alert>
      </div>
    )
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader
        title="My workforce profile"
        description="Your verified details, skills, service areas and availability determine which jobs BRATHAMATU can offer you."
        actions={
          <Link to="/workforce/assignments" className="text-sm font-medium text-brand-700 hover:underline">
            View assignments →
          </Link>
        }
      />

      {profile ? <ProfileEditor profile={profile} /> : <OnboardingForm />}
    </div>
  )
}

function OnboardingForm() {
  const save = useSaveWorkforceProfile()
  const [form, setForm] = useState({
    legal_name: '',
    phone: '',
    city: '',
    county: '',
    country: 'Kenya',
    languages: 'English, Swahili',
    transport: '',
    experience_years: '0',
    bio: '',
    terms_accepted: false,
  })

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    save.mutate({
      profileId: null,
      values: {
        legal_name: form.legal_name,
        phone: form.phone || null,
        city: form.city || null,
        county: form.county || null,
        country: form.country || null,
        languages: form.languages
          .split(',')
          .map((language) => language.trim())
          .filter(Boolean),
        transport: form.transport || null,
        experience_years: Number(form.experience_years) || 0,
        bio: form.bio || null,
        terms_accepted: form.terms_accepted,
      },
    })
  }

  return (
    <Card title="Join the BRATHAMATU workforce">
      <form className="space-y-4" onSubmit={submit}>
        <p className="text-sm text-gray-600">
          Register as a stock taker. BRATHAMATU verifies your identity, phone and training before
          you become eligible for assignment offers.
        </p>

        {save.isError && <Alert kind="error">{errorMessage(save.error)}</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Legal name"
            name="legal_name"
            required
            value={form.legal_name}
            onChange={(event) => setForm({ ...form, legal_name: event.target.value })}
          />
          <Field
            label="Phone number"
            name="phone"
            value={form.phone}
            onChange={(event) => setForm({ ...form, phone: event.target.value })}
          />
          <Field
            label="City"
            name="city"
            value={form.city}
            onChange={(event) => setForm({ ...form, city: event.target.value })}
          />
          <Field
            label="County"
            name="county"
            value={form.county}
            onChange={(event) => setForm({ ...form, county: event.target.value })}
          />
          <Field
            label="Languages (comma separated)"
            name="languages"
            value={form.languages}
            onChange={(event) => setForm({ ...form, languages: event.target.value })}
          />
          <Field
            label="Transport"
            name="transport"
            placeholder="Own vehicle, matatu, motorbike…"
            value={form.transport}
            onChange={(event) => setForm({ ...form, transport: event.target.value })}
          />
          <Field
            label="Years of stock-taking experience"
            name="experience_years"
            type="number"
            min={0}
            max={60}
            value={form.experience_years}
            onChange={(event) => setForm({ ...form, experience_years: event.target.value })}
          />
          <Field
            label="Country"
            name="country"
            value={form.country}
            onChange={(event) => setForm({ ...form, country: event.target.value })}
          />
        </div>

        <Field
          label="Short bio"
          name="bio"
          value={form.bio}
          onChange={(event) => setForm({ ...form, bio: event.target.value })}
        />

        <label className="flex items-start gap-2 text-sm text-gray-700">
          <input
            type="checkbox"
            checked={form.terms_accepted}
            onChange={(event) => setForm({ ...form, terms_accepted: event.target.checked })}
            className="mt-0.5"
          />
          <span>
            I accept the stock-taker terms and confirm I will record physical quantities only — I will
            never see tenant charges or platform margins.
          </span>
        </label>

        <Button type="submit" loading={save.isPending} disabled={!form.legal_name || !form.terms_accepted}>
          Create profile
        </Button>
      </form>
    </Card>
  )
}

function ProfileEditor({ profile }: { profile: WorkforceProfile }) {
  return (
    <>
      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <PersonalDetails profile={profile} />
        <ProgressionPanel profile={profile} />
      </div>
      <SkillsEditor profile={profile} />
      <ServiceAreasEditor profile={profile} />
      <AvailabilityEditor profile={profile} />
    </>
  )
}

function PersonalDetails({ profile }: { profile: WorkforceProfile }) {
  const save = useSaveWorkforceProfile()
  const [saved, setSaved] = useState(false)
  const [form, setForm] = useState({
    legal_name: profile.legal_name ?? '',
    phone: profile.phone ?? '',
    city: profile.city ?? '',
    county: profile.county ?? '',
    country: profile.country ?? '',
    languages: profile.languages.join(', '),
    transport: profile.transport ?? '',
    experience_years: String(profile.experience_years ?? 0),
    bio: profile.bio ?? '',
  })

  useEffect(() => {
    setSaved(false)
  }, [profile.id])

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    save.mutate(
      {
        profileId: profile.id,
        values: {
          legal_name: form.legal_name,
          phone: form.phone || null,
          city: form.city || null,
          county: form.county || null,
          country: form.country || null,
          languages: form.languages
            .split(',')
            .map((language) => language.trim())
            .filter(Boolean),
          transport: form.transport || null,
          experience_years: Number(form.experience_years) || 0,
          bio: form.bio || null,
        },
      },
      { onSuccess: () => setSaved(true) },
    )
  }

  return (
    <Card title="Personal details">
      <form className="space-y-4" onSubmit={submit}>
        {save.isError && <Alert kind="error">{errorMessage(save.error)}</Alert>}
        {saved && <Alert kind="success">Profile details saved.</Alert>}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Legal name"
            name="legal_name"
            required
            value={form.legal_name}
            onChange={(event) => setForm({ ...form, legal_name: event.target.value })}
          />
          <Field
            label="Phone"
            name="phone"
            value={form.phone}
            onChange={(event) => setForm({ ...form, phone: event.target.value })}
          />
          <Field
            label="City"
            name="city"
            value={form.city}
            onChange={(event) => setForm({ ...form, city: event.target.value })}
          />
          <Field
            label="County"
            name="county"
            value={form.county}
            onChange={(event) => setForm({ ...form, county: event.target.value })}
          />
          <Field
            label="Languages"
            name="languages"
            value={form.languages}
            onChange={(event) => setForm({ ...form, languages: event.target.value })}
          />
          <Field
            label="Transport"
            name="transport"
            value={form.transport}
            onChange={(event) => setForm({ ...form, transport: event.target.value })}
          />
          <Field
            label="Experience (years)"
            name="experience_years"
            type="number"
            min={0}
            max={60}
            value={form.experience_years}
            onChange={(event) => setForm({ ...form, experience_years: event.target.value })}
          />
          <Field
            label="Country"
            name="country"
            value={form.country}
            onChange={(event) => setForm({ ...form, country: event.target.value })}
          />
        </div>
        <Field
          label="Bio"
          name="bio"
          value={form.bio}
          onChange={(event) => setForm({ ...form, bio: event.target.value })}
        />
        <Button type="submit" loading={save.isPending}>
          Save details
        </Button>
      </form>
    </Card>
  )
}

function ProgressionPanel({ profile }: { profile: WorkforceProfile }) {
  return (
    <div className="space-y-4">
      <Card title="Verification and progression">
        <dl className="space-y-3 text-sm">
          <div className="flex items-center justify-between gap-3">
            <dt className="text-gray-500">Level</dt>
            <dd className="font-medium text-gray-900">{profile.level_label}</dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-gray-500">Verification</dt>
            <dd>
              <StatusBadge tone={verificationTone(profile.verification_status)}>
                {profile.verification_status}
              </StatusBadge>
            </dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-gray-500">Training</dt>
            <dd>
              <StatusBadge tone={profile.training_completed ? 'green' : 'amber'}>
                {profile.training_completed ? 'Completed' : 'Pending'}
              </StatusBadge>
            </dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-gray-500">Account status</dt>
            <dd>
              <StatusBadge tone={profile.status === 'active' ? 'green' : 'red'}>{profile.status}</StatusBadge>
            </dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-gray-500">Completed jobs</dt>
            <dd className="font-medium text-gray-900">{profile.completed_jobs_count}</dd>
          </div>
        </dl>
      </Card>

      <Card title="Verification checks">
        {profile.verification_checks.length === 0 ? (
          <p className="text-sm text-gray-500">No checks recorded yet. BRATHAMATU will verify your identity and phone.</p>
        ) : (
          <ul className="space-y-2 text-sm">
            {profile.verification_checks.map((check, index) => (
              <li key={`${check.check_type}-${index}`} className="flex items-center justify-between gap-3">
                <span className="capitalize text-gray-600">{check.check_type.replaceAll('_', ' ')}</span>
                <StatusBadge tone={verificationTone(check.status)}>{check.status}</StatusBadge>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Training modules">
        {profile.trainings.length === 0 ? (
          <p className="text-sm text-gray-500">No training recorded yet.</p>
        ) : (
          <ul className="space-y-2 text-sm">
            {profile.trainings.map((training) => (
              <li key={training.module} className="flex items-center justify-between gap-3">
                <span className="text-gray-600">{training.module.replaceAll('_', ' ')}</span>
                <StatusBadge tone={training.status === 'completed' ? 'green' : 'red'}>
                  {`${training.status}${training.score != null ? ` · ${training.score}%` : ''}`}
                </StatusBadge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function SkillsEditor({ profile }: { profile: WorkforceProfile }) {
  const save = useReplaceWorkforceSkills()
  const [rows, setRows] = useState<SkillInput[]>(
    profile.skills.map((skill) => ({
      skill: skill.skill,
      level: (skill.level as SkillInput['level']) ?? 'intermediate',
      years_experience: skill.years_experience ?? null,
    })),
  )
  const [saved, setSaved] = useState(false)

  const update = (index: number, patch: Partial<SkillInput>) =>
    setRows(rows.map((row, position) => (position === index ? { ...row, ...patch } : row)))

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    save.mutate(
      rows.filter((row) => row.skill.trim() !== ''),
      { onSuccess: () => setSaved(true) },
    )
  }

  return (
    <Card title="Skills">
      <form className="space-y-3" onSubmit={submit}>
        {save.isError && <Alert kind="error">{errorMessage(save.error)}</Alert>}
        {saved && <Alert kind="success">Skills saved.</Alert>}
        <p className="text-sm text-gray-500">
          Job matching requires the skills requested by the tenant, for example barcode counting.
        </p>

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
              {SKILL_LEVELS.map((level) => (
                <option key={level} value={level}>
                  {level}
                </option>
              ))}
            </Select>
            <Field
              label="Years"
              name={`skill-years-${index}`}
              type="number"
              min={0}
              max={60}
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
          <Button type="submit" loading={save.isPending}>
            Save skills
          </Button>
        </div>
      </form>
    </Card>
  )
}

function ServiceAreasEditor({ profile }: { profile: WorkforceProfile }) {
  const save = useReplaceWorkforceServiceAreas()
  const [rows, setRows] = useState<ServiceAreaInput[]>(
    profile.service_areas.map((area) => ({
      area: area.area,
      city: area.city ?? null,
      county: area.county ?? null,
      is_primary: area.is_primary ?? false,
    })),
  )
  const [saved, setSaved] = useState(false)

  const update = (index: number, patch: Partial<ServiceAreaInput>) =>
    setRows(rows.map((row, position) => (position === index ? { ...row, ...patch } : row)))

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    save.mutate(
      rows.filter((row) => row.area.trim() !== ''),
      { onSuccess: () => setSaved(true) },
    )
  }

  return (
    <Card title="Service areas">
      <form className="space-y-3" onSubmit={submit}>
        {save.isError && <Alert kind="error">{errorMessage(save.error)}</Alert>}
        {saved && <Alert kind="success">Service areas saved.</Alert>}
        <p className="text-sm text-gray-500">
          You will only be matched to jobs whose branch or location falls inside these areas.
        </p>

        {rows.map((row, index) => (
          <div key={index} className="grid gap-3 sm:grid-cols-[1.4fr_1fr_1fr_auto_auto]">
            <Field
              label="Area"
              name={`area-${index}`}
              value={row.area}
              onChange={(event) => update(index, { area: event.target.value })}
            />
            <Field
              label="City"
              name={`area-city-${index}`}
              value={row.city ?? ''}
              onChange={(event) => update(index, { city: event.target.value || null })}
            />
            <Field
              label="County"
              name={`area-county-${index}`}
              value={row.county ?? ''}
              onChange={(event) => update(index, { county: event.target.value || null })}
            />
            <label className="flex items-end gap-2 pb-2 text-sm text-gray-600">
              <input
                type="radio"
                name="primary-area"
                checked={Boolean(row.is_primary)}
                onChange={() => setRows(rows.map((item, position) => ({ ...item, is_primary: position === index })))}
              />
              Primary
            </label>
            <div className="flex items-end">
              <Button type="button" variant="secondary" onClick={() => setRows(rows.filter((_, position) => position !== index))}>
                Remove
              </Button>
            </div>
          </div>
        ))}

        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="secondary" onClick={() => setRows([...rows, { area: '', city: null, county: null, is_primary: rows.length === 0 }])}>
            Add area
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save areas
          </Button>
        </div>
      </form>
    </Card>
  )
}

function AvailabilityEditor({ profile }: { profile: WorkforceProfile }) {
  const save = useReplaceWorkforceAvailability()
  const [rows, setRows] = useState<AvailabilityInput[]>(
    profile.availability.map((window) => ({
      weekday: window.weekday,
      start_time: window.start_time,
      end_time: window.end_time,
      timezone: window.timezone ?? 'Africa/Nairobi',
      active: window.active ?? true,
    })),
  )
  const [saved, setSaved] = useState(false)

  const update = (index: number, patch: Partial<AvailabilityInput>) =>
    setRows(rows.map((row, position) => (position === index ? { ...row, ...patch } : row)))

  const submit = (event: React.FormEvent) => {
    event.preventDefault()
    save.mutate(rows, { onSuccess: () => setSaved(true) })
  }

  return (
    <Card title="Weekly availability">
      <form className="space-y-3" onSubmit={submit}>
        {save.isError && <Alert kind="error">{errorMessage(save.error)}</Alert>}
        {saved && <Alert kind="success">Availability saved.</Alert>}
        <p className="text-sm text-gray-500">
          Offers are only made when your availability covers the job start time in Africa/Nairobi.
        </p>

        {rows.map((row, index) => (
          <div key={index} className="grid gap-3 sm:grid-cols-[1.2fr_1fr_1fr_auto_auto]">
            <Select
              label="Day"
              name={`weekday-${index}`}
              value={row.weekday}
              onChange={(event) => update(index, { weekday: Number(event.target.value) })}
            >
              {WEEKDAYS.map((day, weekday) => (
                <option key={day} value={weekday}>
                  {day}
                </option>
              ))}
            </Select>
            <Field
              label="Start"
              name={`start-${index}`}
              type="time"
              value={row.start_time}
              onChange={(event) => update(index, { start_time: event.target.value })}
            />
            <Field
              label="End"
              name={`end-${index}`}
              type="time"
              value={row.end_time}
              onChange={(event) => update(index, { end_time: event.target.value })}
            />
            <label className="flex items-end gap-2 pb-2 text-sm text-gray-600">
              <input
                type="checkbox"
                checked={row.active !== false}
                onChange={(event) => update(index, { active: event.target.checked })}
              />
              Active
            </label>
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
            onClick={() =>
              setRows([...rows, { weekday: 1, start_time: '08:00', end_time: '18:00', timezone: 'Africa/Nairobi', active: true }])
            }
          >
            Add window
          </Button>
          <Button type="submit" loading={save.isPending}>
            Save availability
          </Button>
        </div>
      </form>
    </Card>
  )
}
