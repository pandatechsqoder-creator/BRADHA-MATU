import { Link } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import { useCurrentUser } from '../lib/auth/hooks'
import { Alert, PageLoader } from './ui'

function GateFailure({ message }: { message: string }) {
  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <Alert kind="error">{message}</Alert>
      <Link to="/app" className="text-sm font-medium text-brand-700 hover:underline">
        Return to your dashboard
      </Link>
    </div>
  )
}

export function RequirePlatform({ children }: { children: ReactNode }) {
  const { data: user, isLoading } = useCurrentUser()

  if (isLoading) return <PageLoader />
  if (!user?.roles.some((role) => role.scope === 'platform' && !role.tenant_id)) {
    return <GateFailure message="Platform administration is only available to BRATHAMATU platform roles." />
  }

  return <>{children}</>
}

export function RequireWorkforce({ children }: { children: ReactNode }) {
  const { data: user, isLoading } = useCurrentUser()

  if (isLoading) return <PageLoader />
  if (!user?.roles.some((role) => role.scope === 'workforce')) {
    return <GateFailure message="The workforce workspace is only available to stock-taking worker accounts." />
  }

  return <>{children}</>
}

export function RequireTenant({ children }: { children: ReactNode }) {
  const { data: user, isLoading } = useCurrentUser()

  if (isLoading) return <PageLoader />
  if (!user?.current_tenant) {
    return <GateFailure message="Select or create a business before opening this page." />
  }

  return <>{children}</>
}

export function RequirePermission({
  permission,
  children,
}: {
  permission: string
  children: ReactNode
}) {
  return <RequireAnyPermission permissions={[permission]}>{children}</RequireAnyPermission>
}

export function RequireAnyPermission({
  permissions,
  children,
}: {
  permissions: string[]
  children: ReactNode
}) {
  const { data: user, isLoading } = useCurrentUser()

  if (isLoading) return <PageLoader />
  if (!permissions.some((permission) => user?.permissions.includes(permission))) {
    return <GateFailure message="You do not have permission to view this page." />
  }

  return <>{children}</>
}
