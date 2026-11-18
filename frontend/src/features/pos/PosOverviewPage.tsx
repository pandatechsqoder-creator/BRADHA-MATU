import { Link } from '@tanstack/react-router'
import { Alert, Card, PageHeader } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'

export function PosOverviewPage() {
  const { data: user } = useCurrentUser()
  const tenant = user?.current_tenant

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="BRATHAMATU POS"
        description="Point-of-sale workspace for businesses operating with BRATHAMATU POS."
      />

      {tenant?.operating_mode !== 'brathamatu_pos' ? (
        <Alert kind="info">
          {tenant ? `${tenant.name} is configured for ${tenant.operating_mode.replace('_', ' ')} inventory.` : 'Select a business first.'}{' '}
          Change the operating mode in organisation settings to enable BRATHAMATU POS.
        </Alert>
      ) : (
        <Alert kind="info">
          Sales, receipts, shifts, purchases, suppliers, customers, returns and transfers are live.
          Every sale, purchase, return and transfer posts an inventory movement.
        </Alert>
      )}

      <Card title="POS workspaces">
        <ul className="space-y-2 text-sm">
          <li>
            <Link to="/pos" className="font-medium text-brand-700 hover:underline">
              Terminal
            </Link>{' '}
            — barcode sales, cart and checkout.
          </li>
          <li>
            <Link to="/pos/back-office" className="font-medium text-brand-700 hover:underline">
              Back office
            </Link>{' '}
            — customers, suppliers, purchases, returns and transfers.
          </li>
          <li>
            <Link to="/pos/shifts" className="font-medium text-brand-700 hover:underline">
              Shifts
            </Link>{' '}
            — opening floats, closing counts and cash reconciliation.
          </li>
          <li>
            <Link to="/pos/stocktake" className="font-medium text-brand-700 hover:underline">
              POS stock-taking
            </Link>{' '}
            — commission and authorise stock-taking jobs.
          </li>
        </ul>
      </Card>
    </div>
  )
}
