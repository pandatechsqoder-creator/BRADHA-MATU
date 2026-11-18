import { useRouter } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Button, Field } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useChangeInitialPassword } from '../../lib/auth/hooks'
import { AuthLayout } from './LoginPage'

export function ChangeInitialPasswordPage() {
  const router = useRouter()
  const changePassword = useChangeInitialPassword()
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState<string | null>(null)

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (password.length < 8) {
      setError('Your new password must be at least 8 characters.')
      return
    }
    if (password !== confirmation) {
      setError('The password confirmation does not match.')
      return
    }

    changePassword.mutate(
      { password, password_confirmation: confirmation },
      {
        onSuccess: () => router.navigate({ to: '/app' }),
        onError: (err) => setError(err instanceof ApiError ? err.message : 'Could not change your password.'),
      },
    )
  }

  return (
    <AuthLayout title="Create your new password" subtitle="This first-login password change is required">
      {error && <Alert kind="error">{error}</Alert>}
      <form onSubmit={submit} className="space-y-4" noValidate>
        <Field
          label="New password"
          name="password"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          hint="Use at least 8 characters with upper and lower case letters, a number and a symbol."
        />
        <Field
          label="Confirm new password"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          value={confirmation}
          onChange={(event) => setConfirmation(event.target.value)}
        />
        <Button type="submit" loading={changePassword.isPending} className="w-full">
          Save and continue
        </Button>
      </form>
    </AuthLayout>
  )
}
