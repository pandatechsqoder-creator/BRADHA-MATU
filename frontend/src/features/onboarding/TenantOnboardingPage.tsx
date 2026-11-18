import { useState } from 'react'
import { useRouter } from '@tanstack/react-router'
import { Alert, Badge, Button, Card, PageHeader, PageLoader } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser, useTenantOnboarding, useUpdateTenantOnboarding } from '../../lib/auth/hooks'

export function TenantOnboardingPage() {
  const router = useRouter()
  const { data: user, isLoading: userLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const progress = useTenantOnboarding(tenantId)
  const update = useUpdateTenantOnboarding(tenantId ?? undefined)
  const [error, setError] = useState<string | null>(null)

  if (userLoading || (tenantId && progress.isLoading)) return <PageLoader label="Loading setup checklist…" />
  if (!tenantId) {
    return (
      <Alert kind="info">
        Create or select a business before configuring its setup. <a className="font-medium underline" href="/onboarding">Create an organisation</a>
      </Alert>
    )
  }
  if (progress.isError || !progress.data) {
    return <Alert kind="error">Your setup progress could not be loaded. Please try again.</Alert>
  }

  const requiredStepsComplete = progress.data.steps
    .filter((step) => step.required)
    .every((step) => step.status === 'completed')

  function changeStep(step: string, action: 'skip' | 'resume') {
    setError(null)
    update.mutate(
      { step, action },
      {
        onError: (cause) =>
          setError(cause instanceof ApiError ? cause.message : 'Setup progress could not be saved.'),
      },
    )
  }

  function finishSetup() {
    setError(null)
    update.mutate(
      { step: 'final-review', action: 'finish' },
      {
        onSuccess: () => router.navigate({ to: '/app' }),
        onError: (cause) =>
          setError(cause instanceof ApiError ? cause.message : 'Setup could not be completed.'),
      },
    )
  }

  function configureStep(step: string, href: string) {
    setError(null)
    update.mutate(
      { step, action: 'continue' },
      {
        onSuccess: () => window.location.assign(href),
        onError: (cause) =>
          setError(cause instanceof ApiError ? cause.message : 'Setup progress could not be saved.'),
      },
    )
  }

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader
        title="Business setup"
        description="Configure your workspace at your own pace. Optional steps can be skipped and completed later."
        actions={
          progress.data.recommended_step ? (
            <Button
              loading={update.isPending}
              onClick={() =>
                configureStep(progress.data.recommended_step!.id, progress.data.recommended_step!.href)
              }
            >
              Continue setup
            </Button>
          ) : undefined
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {update.isError && !error && (
        <Alert kind="error">Setup progress could not be saved. Please try again.</Alert>
      )}

      <Card title="Setup progress">
        <div className="space-y-3">
          <div className="flex items-center justify-between text-sm">
            <span>{progress.data.completion_percent}% complete</span>
            <span className="text-gray-500">
              {progress.data.steps.filter((step) => step.status === 'completed' || step.status === 'skipped').length}
              {' of '}
              {progress.data.steps.length} steps addressed
            </span>
          </div>
          <div
            className="h-2 overflow-hidden rounded-full bg-gray-100"
            role="progressbar"
            aria-label="Business setup progress"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={progress.data.completion_percent}
          >
            <div
              className="h-full rounded-full bg-brand-700 transition-all"
              style={{ width: `${progress.data.completion_percent}%` }}
            />
          </div>
          <p className="text-xs text-gray-500">
            Your changes are saved as you skip steps. You can leave this page and return later.
          </p>
        </div>
      </Card>

      <div className="space-y-3">
        {progress.data.steps.map((step, index) => (
          <Card key={step.id}>
            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
              <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-semibold text-gray-700">
                  {index + 1}
                </span>
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <h2 className="font-medium text-gray-900">{step.title}</h2>
                    <Badge tone={step.status === 'completed' ? 'green' : step.status === 'skipped' ? 'gray' : 'amber'}>
                      {step.status === 'pending' ? 'Not started' : step.status}
                    </Badge>
                    <span className="text-xs text-gray-500">{step.required ? 'Required' : 'Optional'}</span>
                  </div>
                  {step.id === 'stocktake-readiness' && (
                    <p className="mt-1 text-sm text-gray-600">
                      Prepare your catalogue and locations here. Stock declarations are completed separately for each stocktaking request.
                    </p>
                  )}
                </div>
              </div>
              <div className="flex shrink-0 gap-2">
                {step.status === 'pending' ? (
                  <>
                    <Button
                      variant="secondary"
                      disabled={update.isPending}
                      onClick={() => configureStep(step.id, step.href)}
                    >
                      Configure
                    </Button>
                    {!step.required && (
                      <Button
                        variant="secondary"
                        disabled={update.isPending}
                        onClick={() => changeStep(step.id, 'skip')}
                      >
                        Skip for now
                      </Button>
                    )}
                  </>
                ) : step.status === 'skipped' ? (
                  <>
                    <Button
                      variant="secondary"
                      disabled={update.isPending}
                      onClick={() => configureStep(step.id, step.href)}
                    >
                      Configure
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={update.isPending}
                      onClick={() => {
                        setError(null)
                        update.mutate(
                          { step: step.id, action: 'resume' },
                          {
                            onSuccess: () => window.location.assign(step.href),
                            onError: (cause) =>
                              setError(cause instanceof ApiError ? cause.message : 'Setup progress could not be saved.'),
                          },
                        )
                      }}
                    >
                      Resume step
                    </Button>
                  </>
                ) : (
                  <span className="px-3 py-2 text-sm font-medium text-green-700">Complete</span>
                )}
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Card title="Final review">
        <p className="text-sm text-gray-600">
          You can use BrathaMatu while completing optional setup. Finish when your required business profile, location, and catalogue steps are ready.
        </p>
        {progress.data.finished_at ? (
          <p className="mt-3 text-sm font-medium text-green-700">Setup finished. You can return to this checklist at any time.</p>
        ) : (
          <Button
            className="mt-4"
            loading={update.isPending}
            disabled={!requiredStepsComplete}
            onClick={finishSetup}
          >
            Finish setup
          </Button>
        )}
      </Card>
    </div>
  )
}
