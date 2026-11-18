import { useRouter } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Button, Field, PageHeader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCreateTenant, useCurrentUser } from '../../lib/auth/hooks'

export function OnboardingPage() {
  const router = useRouter()
  const { data: user } = useCurrentUser()
  const createTenant = useCreateTenant()
  const [values, setValues] = useState({
    name: '',
    industry: '',
    business_type: '',
    operating_mode: 'manual',
    timezone: 'Africa/Nairobi',
  })
  const [errors, setErrors] = useState<Record<string, string>>({})

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    setErrors({})
    if (values.name.trim().length < 2) {
      setErrors({ name: 'Enter your organisation name.' })
      return
    }
    createTenant.mutate(
      {
        name: values.name.trim(),
        industry: values.industry || undefined,
        business_type: values.business_type || undefined,
        operating_mode: values.operating_mode,
        timezone: values.timezone,
      },
      {
        onSuccess: () => router.navigate({ to: '/app' }),
        onError: (error) => {
          if (error instanceof ApiError) {
            const fieldErrors: Record<string, string> = {}
            for (const [field, messages] of Object.entries(error.details)) {
              fieldErrors[field] = messages[0]
            }
            setErrors({ ...fieldErrors, form: error.message })
          } else {
            setErrors({ form: 'Could not create the organisation. Try again.' })
          }
        },
      },
    )
  }

  const set = (key: keyof typeof values) => (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) =>
    setValues((current) => ({ ...current, [key]: event.target.value }))

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Create your organisation"
        description="Set up the business that will commission stock takes. You become the Business Owner."
      />

      {user?.tenants.length ? (
        <Alert kind="info">
          You already belong to {user.tenants.map((tenant) => tenant.name).join(', ')}. You can add
          another organisation below and switch between them from the header.
        </Alert>
      ) : null}

      <form onSubmit={onSubmit} className="space-y-5 rounded-xl border border-gray-200 bg-white p-6 shadow-sm" noValidate>
        {errors.form && <Alert kind="error">{errors.form}</Alert>}

        <Field
          label="Organisation name"
          name="name"
          value={values.name}
          error={errors.name}
          onChange={set('name')}
          placeholder="e.g. Nia Provisions Ltd"
        />
        <div className="grid gap-4 sm:grid-cols-2">
          <Field
            label="Industry"
            name="industry"
            value={values.industry}
            error={errors.industry}
            onChange={set('industry')}
            placeholder="Retail, pharmacy, logistics…"
          />
          <Field
            label="Business type"
            name="business_type"
            value={values.business_type}
            error={errors.business_type}
            onChange={set('business_type')}
            placeholder="Limited company, partnership…"
          />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select
            label="Operating mode"
            name="operating_mode"
            value={values.operating_mode}
            hint="How inventory records reach the platform. You can change this later."
            onChange={set('operating_mode')}
          >
            <option value="manual">Manual / spreadsheets</option>
            <option value="external">External POS or ERP</option>
            <option value="brathamatu_pos">BRATHAMATU POS</option>
          </Select>
          <Field
            label="Timezone"
            name="timezone"
            value={values.timezone}
            error={errors.timezone}
            onChange={set('timezone')}
          />
        </div>

        <div className="flex justify-end">
          <Button type="submit" loading={createTenant.isPending}>
            Create organisation
          </Button>
        </div>
      </form>

      <div className="rounded-xl border border-gray-200 bg-white p-5 text-sm text-gray-600 shadow-sm">
        <p className="font-medium text-gray-900">What happens next</p>
        <p className="mt-1">
          Add branches and stock locations, then upload and confirm your catalogue. A stock-taking
          request is only unlocked once the catalogue readiness requirements are satisfied.
        </p>
      </div>
    </div>
  )
}
