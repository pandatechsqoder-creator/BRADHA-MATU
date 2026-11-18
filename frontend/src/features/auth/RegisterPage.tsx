import { useQuery } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { useEffect, useState, type ChangeEvent, type FormEvent } from 'react'
import { z } from 'zod'
import { Alert, Button, Field } from '../../components/ui'
import { ApiError, api } from '../../lib/api/client'
import { AuthLayout } from './LoginPage'

const detailsSchema = z.object({
  business_name: z.string().trim().min(2, 'Business name must be at least 2 characters.').max(120),
  display_name: z.string().trim().max(120).optional(),
  industry: z.string().trim().max(100).optional(),
  country: z.string().length(2, 'Use a two-letter country code.'),
  county: z.string().trim().max(100).optional(),
  owner_name: z.string().trim().min(2, 'Enter the account owner’s full name.').max(120),
  owner_email: z.string().trim().email('Enter a valid email address.').max(255),
  owner_phone: z.string().trim().max(30).optional(),
  password: z
    .string()
    .min(8, 'Use at least 8 characters.')
    .regex(/[a-z]/, 'Include a lowercase letter.')
    .regex(/[A-Z]/, 'Include an uppercase letter.')
    .regex(/[0-9]/, 'Include a number.')
    .regex(/[^A-Za-z0-9]/, 'Include a symbol.'),
  password_confirmation: z.string(),
}).refine((value) => value.password === value.password_confirmation, {
  message: 'Passwords do not match.',
  path: ['password_confirmation'],
})

const registrationSchema = detailsSchema.extend({
  slug: z.string().min(2).max(63),
  domain_accepted: z.literal(true),
})

const domainSuggestionSchema = z.object({
  slug: z.string(),
  subdomain: z.string(),
  available: z.boolean(),
})

type FormValues = z.infer<typeof detailsSchema>
type DomainSuggestion = z.infer<typeof domainSuggestionSchema>
type RegistrationStep = 'details' | 'domain' | 'verification' | 'complete'

const initialValues: FormValues = {
  business_name: '',
  display_name: '',
  industry: '',
  country: 'KE',
  county: '',
  owner_name: '',
  owner_email: '',
  owner_phone: '',
  password: '',
  password_confirmation: '',
}

export function RegisterPage() {
  const [values, setValues] = useState(initialValues)
  const [step, setStep] = useState<RegistrationStep>('details')
  const [selectedSlug, setSelectedSlug] = useState('')
  const [acceptedDomain, setAcceptedDomain] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [suggestionOverride, setSuggestionOverride] = useState<DomainSuggestion[] | null>(null)
  const [attemptUuid, setAttemptUuid] = useState('')
  const [idempotencyKey] = useState(() => crypto.randomUUID())
  const [verificationExpiry, setVerificationExpiry] = useState('')
  const [resendAvailableAt, setResendAvailableAt] = useState(0)
  const [now, setNow] = useState(Date.now())
  const [submitting, setSubmitting] = useState(false)

  const suggestionsQuery = useQuery({
    queryKey: ['public-domain-suggestions', values.business_name, values.county],
    queryFn: async () => {
      const params = new URLSearchParams({ business_name: values.business_name })
      if (values.county) params.set('location', values.county)
      const result = await api.get<{
        suggestions: DomainSuggestion[]
        base_domain: string
      }>(`/public/domains/suggestions?${params.toString()}`)
      return {
        ...result,
        suggestions: z.array(domainSuggestionSchema).parse(result.suggestions),
      }
    },
    enabled: step === 'domain' && values.business_name.trim().length >= 2,
    retry: false,
  })

  const suggestions = suggestionOverride ?? suggestionsQuery.data?.suggestions ?? []
  const baseDomain = suggestionsQuery.data?.base_domain ?? 'communitygroup.co.ke'
  const resendSeconds = Math.max(0, Math.ceil((resendAvailableAt - now) / 1000))
  const codeExpired = verificationExpiry !== '' && new Date(verificationExpiry).getTime() <= now

  useEffect(() => {
    if (step !== 'verification') return
    const timer = window.setInterval(() => setNow(Date.now()), 1000)
    return () => window.clearInterval(timer)
  }, [step])

  useEffect(() => {
    if (selectedSlug === '' && suggestions.length > 0) {
      setSelectedSlug(suggestions[0].slug)
    }
  }, [selectedSlug, suggestions])

  function updateValue(key: keyof FormValues) {
    return (event: ChangeEvent<HTMLInputElement>) => {
      setValues((current) => ({ ...current, [key]: event.target.value }))
      setErrors((current) => ({ ...current, [key]: '' }))
      if (key === 'business_name' || key === 'county') {
        setSelectedSlug('')
        setSuggestionOverride(null)
        setAcceptedDomain(false)
      }
    }
  }

  function applyValidationErrors(error: z.ZodError) {
    const next: Record<string, string> = {}
    for (const issue of error.issues) {
      const field = String(issue.path[0] ?? 'form')
      next[field] ??= issue.message
    }
    setErrors(next)
  }

  function applyApiError(error: unknown) {
    if (!(error instanceof ApiError)) {
      setErrors({ form: 'We could not reach the service. Please try again.' })
      return
    }

    const next: Record<string, string> = {}
    for (const [field, messages] of Object.entries(error.details)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') {
        next[field] = messages[0]
      }
    }

    if (error.code === 'DOMAIN_CONFLICT') {
      const refreshed = z.array(domainSuggestionSchema).safeParse(error.details.suggestions)
      if (refreshed.success) {
        setSuggestionOverride(refreshed.data)
        setSelectedSlug(refreshed.data[0]?.slug ?? '')
        setAcceptedDomain(false)
        setErrors({
          form: 'That address was just claimed. Review and accept one of the refreshed suggestions.',
        })
        return
      }
    }

    setErrors({ ...next, form: error.message })
  }

  function continueToDomains(event: FormEvent) {
    event.preventDefault()
    const parsed = detailsSchema.safeParse(values)
    if (!parsed.success) {
      applyValidationErrors(parsed.error)
      return
    }

    setErrors({})
    setSuggestionOverride(null)
    setSelectedSlug('')
    setStep('domain')
  }

  async function submitRegistration(event: FormEvent) {
    event.preventDefault()
    if (!acceptedDomain) {
      setErrors({ form: 'Please confirm that you accept the selected business address.' })
      return
    }

    const parsed = registrationSchema.safeParse({
      ...values,
      slug: selectedSlug,
      domain_accepted: true,
    })
    if (!parsed.success) {
      applyValidationErrors(parsed.error)
      return
    }

    setSubmitting(true)
    setErrors({})
    try {
      const result = await api.postWithHeaders<{
        attempt_uuid: string
        slug?: string
        expires_at?: string | null
        completed?: boolean
      }>('/public/registration', parsed.data, { 'Idempotency-Key': idempotencyKey })
      setAttemptUuid(result.attempt_uuid)
      setVerificationExpiry(result.expires_at ?? '')
      setResendAvailableAt(Date.now() + 60_000)
      setStep(result.completed ? 'complete' : 'verification')
    } catch (error) {
      applyApiError(error)
    } finally {
      setSubmitting(false)
    }
  }

  async function verifyEmail(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const code = String(formData.get('verification_code') ?? '').trim()
    if (!/^\d{6}$/.test(code)) {
      setErrors({ code: 'Enter the six-digit code sent to your email.' })
      return
    }

    setSubmitting(true)
    setErrors({})
    try {
      await api.post('/public/registration/verify', { attempt_uuid: attemptUuid, code })
      setStep('complete')
    } catch (error) {
      applyApiError(error)
    } finally {
      setSubmitting(false)
    }
  }

  async function resendCode() {
    setSubmitting(true)
    setErrors({})
    try {
      const result = await api.post<{ expires_at?: string | null }>('/public/registration/resend-code', {
        attempt_uuid: attemptUuid,
      })
      setVerificationExpiry(result.expires_at ?? '')
      setResendAvailableAt(Date.now() + 60_000)
    } catch (error) {
      applyApiError(error)
    } finally {
      setSubmitting(false)
    }
  }

  if (step === 'verification') {
    return (
      <AuthLayout title="Verify your email" subtitle="One last step to create your business workspace">
        <form onSubmit={verifyEmail} className="space-y-4" noValidate>
          {errors.form && <Alert kind="error">{errors.form}</Alert>}
          <p className="text-sm text-gray-600">
            We sent a six-digit verification code to <strong>{values.owner_email}</strong>. The code is
            single-use and is not shown in this page.
          </p>
          {verificationExpiry && (
            <p className={`text-sm ${codeExpired ? 'text-red-700' : 'text-gray-500'}`}>
              {codeExpired
                ? 'This code has expired. Request a new code to continue.'
                : `Code expires at ${new Date(verificationExpiry).toLocaleTimeString()}.`}
            </p>
          )}
          <Field
            label="Verification code"
            name="verification_code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            maxLength={6}
            pattern="[0-9]{6}"
            required
            error={errors.code}
            onChange={(event) => {
              event.target.value = event.target.value.replace(/\D/g, '').slice(0, 6)
              setErrors((current) => ({ ...current, code: '' }))
            }}
          />
          <Button type="submit" loading={submitting} className="w-full">
            Verify and create workspace
          </Button>
          <Button
            type="button"
            variant="secondary"
            className="w-full"
            loading={submitting}
            disabled={resendSeconds > 0}
            onClick={resendCode}
          >
            {resendSeconds > 0 ? `Resend code in ${resendSeconds}s` : 'Resend verification code'}
          </Button>
        </form>
      </AuthLayout>
    )
  }

  if (step === 'complete') {
    return (
      <AuthLayout title="Your workspace is ready" subtitle="Your business address has been allocated">
        <div className="space-y-4">
          <Alert kind="success">
            Email verified and workspace created for {values.display_name || values.business_name}.
          </Alert>
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <p className="text-sm text-gray-600">Your tenant link</p>
            <p className="mt-1 break-all font-semibold text-gray-900">
              https://{selectedSlug}.{baseDomain}
            </p>
          </div>
          <p className="text-sm text-gray-600">
            Sign in with the email and password you created. If your administrator has not enabled the
            tenant subdomain yet, use the main sign-in page until it is available.
          </p>
          <Link
            to="/login"
            className="inline-flex w-full justify-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
          >
            Go to sign in
          </Link>
        </div>
      </AuthLayout>
    )
  }

  if (step === 'domain') {
    return (
      <AuthLayout title="Choose your business link" subtitle="Pick a unique address for your workspace">
        <form onSubmit={submitRegistration} className="space-y-4" noValidate>
          {errors.form && <Alert kind="error">{errors.form}</Alert>}
          <p className="text-sm text-gray-600">
            Suggestions are based on <strong>{values.business_name}</strong>. Your selected address will
            be reserved when you submit this step.
          </p>
          {suggestionsQuery.isPending && <p className="text-sm text-gray-500">Checking available names…</p>}
          {suggestionsQuery.isError && (
            <Alert kind="error">
              {suggestionsQuery.error instanceof ApiError
                ? suggestionsQuery.error.message
                : 'Could not load domain suggestions. Please try again.'}
            </Alert>
          )}
          {suggestions.length === 0 && !suggestionsQuery.isPending && !suggestionsQuery.isError && (
            <Alert kind="error">No available suggestions were found. Edit your business details and try again.</Alert>
          )}
          <div className="space-y-2" role="radiogroup" aria-label="Available business addresses">
            {suggestions.map((suggestion) => (
              <label
                key={suggestion.slug}
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 ${
                  selectedSlug === suggestion.slug ? 'border-brand-700 bg-brand-50' : 'border-gray-200'
                }`}
              >
                <input
                  type="radio"
                  name="tenant-domain"
                  value={suggestion.slug}
                  checked={selectedSlug === suggestion.slug}
                  onChange={() => {
                    setSelectedSlug(suggestion.slug)
                    setAcceptedDomain(false)
                    setErrors({})
                  }}
                  className="mt-1"
                />
                <span className="min-w-0">
                  <span className="block break-all font-medium text-gray-900">
                    {suggestion.subdomain || `${suggestion.slug}.${baseDomain}`}
                  </span>
                  <span className="text-xs text-green-700">Available to reserve</span>
                </span>
              </label>
            ))}
          </div>
          {selectedSlug && (
            <>
              <div className="rounded-lg bg-gray-50 p-3 text-sm text-gray-700">
                Selected link: <strong>{selectedSlug}.{baseDomain}</strong>
              </div>
              <label className="flex items-start gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  checked={acceptedDomain}
                  onChange={(event) => setAcceptedDomain(event.target.checked)}
                  className="mt-1"
                />
                <span>I accept this business link for my workspace.</span>
              </label>
            </>
          )}
          <Button
            type="submit"
            loading={submitting}
            disabled={!selectedSlug || suggestionsQuery.isPending || suggestionsQuery.isError}
            className="w-full"
          >
            Reserve link and send verification code
          </Button>
          <Button
            type="button"
            variant="secondary"
            className="w-full"
            onClick={() => {
              setStep('details')
              setErrors({})
            }}
          >
            Back to business details
          </Button>
        </form>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Create a business workspace" subtitle="Register your business and get its own BrathaMatu link">
      <form onSubmit={continueToDomains} className="space-y-4" noValidate>
        {errors.form && <Alert kind="error">{errors.form}</Alert>}
        <Field
          label="Business name"
          name="business_name"
          autoComplete="organization"
          value={values.business_name}
          error={errors.business_name}
          onChange={updateValue('business_name')}
        />
        <Field
          label="Display name (optional)"
          name="display_name"
          autoComplete="organization"
          value={values.display_name}
          error={errors.display_name}
          onChange={updateValue('display_name')}
        />
        <Field
          label="Industry (optional)"
          name="industry"
          value={values.industry}
          error={errors.industry}
          onChange={updateValue('industry')}
        />
        <div className="grid grid-cols-2 gap-3">
          <Field
            label="Country code"
            name="country"
            autoComplete="country"
            maxLength={2}
            value={values.country}
            error={errors.country}
            onChange={updateValue('country')}
          />
          <Field
            label="County / location"
            name="county"
            autoComplete="address-level1"
            value={values.county}
            error={errors.county}
            onChange={updateValue('county')}
          />
        </div>
        <Field
          label="Owner’s full name"
          name="owner_name"
          autoComplete="name"
          value={values.owner_name}
          error={errors.owner_name}
          onChange={updateValue('owner_name')}
        />
        <Field
          label="Owner’s email"
          name="owner_email"
          type="email"
          autoComplete="email"
          value={values.owner_email}
          error={errors.owner_email}
          onChange={updateValue('owner_email')}
        />
        <Field
          label="Owner’s phone (optional)"
          name="owner_phone"
          type="tel"
          autoComplete="tel"
          value={values.owner_phone}
          error={errors.owner_phone}
          onChange={updateValue('owner_phone')}
        />
        <Field
          label="Password"
          name="password"
          type="password"
          autoComplete="new-password"
          hint="At least 8 characters, including upper- and lowercase letters, a number and a symbol."
          value={values.password}
          error={errors.password}
          onChange={updateValue('password')}
        />
        <Field
          label="Confirm password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={values.password_confirmation}
          error={errors.password_confirmation}
          onChange={updateValue('password_confirmation')}
        />
        <Button type="submit" className="w-full">
          Continue to business link
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-gray-500">
        Already registered?{' '}
        <Link to="/login" className="font-medium text-brand-700 hover:underline">
          Sign in
        </Link>
      </p>
    </AuthLayout>
  )
}
