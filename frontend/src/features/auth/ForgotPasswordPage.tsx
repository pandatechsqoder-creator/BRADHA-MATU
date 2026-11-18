import { Link } from '@tanstack/react-router'
import { Alert } from '../../components/ui'
import { AuthLayout } from './LoginPage'

export function ForgotPasswordPage() {
  return (
    <AuthLayout title="Reset your password" subtitle="Recover access to your BRATHAMATU account">
      <Alert kind="info">
        Self-service password reset via email is being delivered with the notification phase. For now,
        contact BRATHAMATU support to reset a local or staging account. Demo accounts use the shared
        local password documented in the repository README.
      </Alert>
      <p className="mt-5 text-center text-sm text-gray-500">
        <Link to="/login" className="font-medium text-brand-700 hover:underline">
          ← Back to sign in
        </Link>
      </p>
    </AuthLayout>
  )
}
