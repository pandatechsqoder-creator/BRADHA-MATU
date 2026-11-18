import { Link, useRouter } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Button, Field } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { loginInputSchema } from '../../lib/api/schemas'
import { useLogin } from '../../lib/auth/hooks'
import { publicSiteUrl } from '../../lib/publicSite'

export function LoginPage() {
  const router = useRouter()
  const login = useLogin()
  const [values, setValues] = useState({ email: '', password: '' })
  const [errors, setErrors] = useState<Record<string, string>>({})

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    const parsed = loginInputSchema.safeParse(values)
    if (!parsed.success) {
      const fieldErrors: Record<string, string> = {}
      for (const issue of parsed.error.issues) {
        const key = String(issue.path[0] ?? 'form')
        fieldErrors[key] ??= issue.message
      }
      setErrors(fieldErrors)
      return
    }
    setErrors({})
    login.mutate(parsed.data, {
      onSuccess: (user) => {
        if (user.must_change_password) {
          router.navigate({ to: '/change-initial-password' })
          return
        }
        const redirect = new URLSearchParams(window.location.search).get('redirect')
        if (redirect && redirect.startsWith('/')) {
          window.location.assign(redirect)
          return
        }
        router.navigate({ to: '/app' })
      },
      onError: (error) => {
        if (error instanceof ApiError) {
          const fieldErrors: Record<string, string> = {}
          for (const [field, messages] of Object.entries(error.details)) {
            fieldErrors[field] = messages[0]
          }
          setErrors({ ...fieldErrors, form: error.message })
        } else {
          setErrors({ form: 'Unexpected error. Please try again.' })
        }
      },
    })
  }

  return (
    <AuthLayout title="Sign in" subtitle="Access your BRATHAMATU Stocktake workspace">
      <form onSubmit={onSubmit} className="space-y-4" noValidate>
        {errors.form && !errors.email && !errors.password && (
          <Alert kind="error">{errors.form}</Alert>
        )}
        <Field
          label="Email"
          name="email"
          type="email"
          autoComplete="email"
          value={values.email}
          error={errors.email}
          onChange={(e) => setValues((v) => ({ ...v, email: e.target.value }))}
        />
        <Field
          label="Password"
          name="password"
          type="password"
          autoComplete="current-password"
          value={values.password}
          error={errors.password}
          onChange={(e) => setValues((v) => ({ ...v, password: e.target.value }))}
        />
        <Button type="submit" loading={login.isPending} className="w-full">
          Sign in
        </Button>
      </form>
      <p className="mt-6 text-center text-sm text-gray-500">
        New business or stock taker?{' '}
        <Link to="/register" className="font-medium text-brand-700 hover:underline">
          Create an account
        </Link>
      </p>
      <p className="mt-2 text-center text-xs text-gray-500">
        <Link to="/forgot-password" className="hover:underline">
          Forgot your password?
        </Link>
      </p>
      <div className="mt-6 border-t border-gray-100 pt-4 text-center">
        <a
          href={publicSiteUrl()}
          target="_blank"
          rel="noreferrer"
          className="text-sm font-medium text-brand-700 hover:underline"
        >
          Visit the public BRATHAMATU website →
        </a>
      </div>
    </AuthLayout>
  )
}

export function AuthLayout({
  title,
  subtitle,
  children,
}: {
  title: string
  subtitle: string
  children: React.ReactNode
}) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10">
      <div className="w-full max-w-md">
        <div className="mb-8 text-center">
          <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-ink-900 text-lg font-bold text-brand-400">
            P
          </span>
          <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
          <p className="mt-1 text-sm text-gray-500">{subtitle}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">{children}</div>
        <p className="mt-6 text-center text-xs text-gray-500">
          BRATHAMATU · Nairobi, Kenya · All amounts in KES
        </p>
      </div>
    </div>
  )
}