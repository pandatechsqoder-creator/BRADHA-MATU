import { useRouter } from '@tanstack/react-router'
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { Alert, Button, Card, Field, PageHeader, PageLoader } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import {
  useConfirmVerification,
  useCurrentUser,
  useSendVerification,
} from '../../lib/auth/hooks'

export function VerifyPage() {
  const router = useRouter()
  const { data: user, isLoading } = useCurrentUser()
  const send = useSendVerification()
  const confirm = useConfirmVerification()
  const [code, setCode] = useState('')
  const [testCode, setTestCode] = useState<string | null>(
    sessionStorage.getItem('brathamatu.verify.test_code'),
  )
  const [error, setError] = useState<string | null>(null)
  const autoSendAttempted = useRef(false)

  const requestCode = useCallback(() => {
    setError(null)
    sessionStorage.setItem('brathamatu.verification_email_sent', 'pending')
    send.mutate(undefined, {
      onSuccess: (result) => {
        if (result.test_code) {
          setTestCode(result.test_code)
          sessionStorage.setItem('brathamatu.verify.test_code', result.test_code)
        }
      },
      onError: () => {
        sessionStorage.removeItem('brathamatu.verification_email_sent')
        setError('Could not send a verification code. Try again shortly.')
      },
    })
  }, [send])

  useEffect(() => {
    if (
      !user ||
      user.email_verified_at ||
      autoSendAttempted.current ||
      sessionStorage.getItem('brathamatu.verification_email_sent')
    ) {
      return
    }

    autoSendAttempted.current = true
    requestCode()
  }, [requestCode, user])

  if (isLoading) return <PageLoader />
  if (!user) return null

  if (user.email_verified_at) {
    return (
      <div className="mx-auto max-w-xl space-y-4">
        <PageHeader title="Email verified" description="Your account is fully activated." />
        <Alert kind="success">This email address is already verified.</Alert>
        <Button onClick={() => router.navigate({ to: '/app' })}>Go to dashboard</Button>
      </div>
    )
  }

  function onSend() {
    requestCode()
  }

  function onConfirm(event: FormEvent) {
    event.preventDefault()
    setError(null)
    if (!/^\d{6}$/.test(code)) {
      setError('Enter the six-digit code from your verification message.')
      return
    }
    confirm.mutate(code, {
      onSuccess: () => router.navigate({ to: '/app' }),
      onError: (err) => {
        setError(err instanceof ApiError ? err.message : 'The code could not be verified.')
      },
    })
  }

  return (
    <div className="mx-auto max-w-xl space-y-5">
      <PageHeader
        title="Verify your email"
        description={`Confirm ${user.email} to unlock approvals and payment flows.`}
      />

      {testCode && (
        <Alert kind="info">
          Test-mode verification code: <span className="font-mono font-semibold">{testCode}</span>. In
          production this is delivered by the email provider.
        </Alert>
      )}
      {error && <Alert kind="error">{error}</Alert>}

      <Card title="Verification code">
        <form onSubmit={onConfirm} className="space-y-4">
          <Field
            label="Six-digit code"
            name="code"
            inputMode="numeric"
            maxLength={6}
            value={code}
            onChange={(event) => setCode(event.target.value.replace(/\D/g, ''))}
            hint="Request a code if you do not have one yet."
          />
          <div className="flex flex-wrap gap-3">
            <Button type="submit" loading={confirm.isPending}>
              Verify email
            </Button>
            <Button type="button" variant="secondary" loading={send.isPending} onClick={onSend}>
              Send a new code
            </Button>
          </div>
        </form>
      </Card>
    </div>
  )
}
