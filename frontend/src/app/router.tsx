import {
  createRootRoute,
  createRoute,
  createRouter,
  Outlet,
  redirect,
} from '@tanstack/react-router'
import { AppShell } from '../components/AppShell'
import {
  RequireAnyPermission,
  RequirePermission,
  RequirePlatform,
  RequireTenant,
  RequireWorkforce,
} from '../components/Guards'
import { getStoredToken } from '../lib/api/client'
import { NotificationsPage } from '../features/account/NotificationsPage'
import { ProfilePage } from '../features/account/ProfilePage'
import { ChangeInitialPasswordPage } from '../features/auth/ChangeInitialPasswordPage'
import { ForgotPasswordPage } from '../features/auth/ForgotPasswordPage'
import { LoginPage } from '../features/auth/LoginPage'
import { RegisterPage } from '../features/auth/RegisterPage'
import { VerifyPage } from '../features/auth/VerifyPage'
import { CataloguePage } from '../features/catalogue/CataloguePage'
import { ProductDetailPage } from '../features/catalogue/ProductDetailPage'
import { DashboardPage } from '../features/dashboard/DashboardPage'
import { FinanceWorkspacePage } from '../features/finance/FinanceWorkspacePage'
import { IntegrationsPage } from '../features/integrations/IntegrationsPage'
import { InventoryPage } from '../features/inventory/InventoryPage'
import { AcceptInvitationPage } from '../features/invitations/AcceptInvitationPage'
import { OnboardingPage } from '../features/onboarding/OnboardingPage'
import { TenantOnboardingPage } from '../features/onboarding/TenantOnboardingPage'
import { PlatformAuditPage } from '../features/platform/PlatformAuditPage'
import { PlatformCataloguePricingPage } from '../features/platform/PlatformCataloguePricingPage'
import { PlatformOverviewPage } from '../features/platform/PlatformOverviewPage'
import { PlatformSupportPage } from '../features/platform/PlatformSupportPage'
import { PlatformTenantDetailPage } from '../features/platform/PlatformTenantDetailPage'
import { PlatformTenantsPage } from '../features/platform/PlatformTenantsPage'
import { ContentLibraryPage } from '../features/marketing/ContentLibraryPage'
import { MarketingOverviewPage } from '../features/marketing/MarketingOverviewPage'
import { MarketingPageEditorPage } from '../features/marketing/MarketingPageEditorPage'
import { MarketingPagesPage } from '../features/marketing/MarketingPagesPage'
import { LeadDetailPage } from '../features/marketing/LeadDetailPage'
import { LeadsPage } from '../features/marketing/LeadsPage'
import { MediaLibraryPage } from '../features/marketing/MediaLibraryPage'
import { NavigationPage } from '../features/marketing/NavigationPage'
import { BlogPage } from '../features/marketing/BlogPage'
import { GoogleIntegrationsPage } from '../features/marketing/GoogleIntegrationsPage'
import { CampaignsPage } from '../features/marketing/CampaignsPage'
import { AnalyticsPage } from '../features/marketing/AnalyticsPage'
import { AutomationPage } from '../features/marketing/AutomationPage'
import { MetaIntegrationsPage } from '../features/marketing/MetaIntegrationsPage'
import { SocialPostsPage } from '../features/marketing/SocialPostsPage'
import { SeoPage } from '../features/marketing/SeoPage'
import { PlatformOperationsPage } from '../features/operations/PlatformOperationsPage'
import { SopTemplatesPage } from '../features/operations/SopTemplatesPage'
import { WorkerAssignmentOperationsPage } from '../features/operations/WorkerAssignmentOperationsPage'
import { WorkerCountPage } from '../features/count/WorkerCountPage'
import { PlatformStocktakeJobPage } from '../features/stocktake/PlatformStocktakeJobPage'
import { QAReviewPage } from '../features/qa/QAReviewPage'
import { PlatformStocktakeJobsPage } from '../features/stocktake/PlatformStocktakeJobsPage'
import { StocktakePolicyPage } from '../features/stocktake/StocktakePolicyPage'
import { StocktakeRequestPage } from '../features/stocktake/StocktakeRequestPage'
import { TenantStocktakeJobPage } from '../features/stocktake/TenantStocktakeJobPage'
import { TenantResultsReviewPage } from '../features/review/TenantResultsReviewPage'
import { TenantStocktakePage } from '../features/stocktake/TenantStocktakePage'
import { PlatformReportsPage, ReportsPage } from '../features/reports/ReportsPage'
import { PosTerminalPage } from '../features/pos/PosTerminalPage'
import { PosBackOfficePage } from '../features/pos/PosBackOfficePage'
import { PosTerminalsPage } from '../features/pos/PosTerminalsPage'
import { PosShiftsPage } from '../features/pos/PosShiftsPage'
import { PosStocktakePage } from '../features/pos/PosStocktakePage'
import { SupportPage } from '../features/support/SupportPage'
import { LocationsPage } from '../features/tenant/LocationsPage'
import { TeamPage } from '../features/tenant/TeamPage'
import { TenantSettingsPage } from '../features/tenant/TenantSettingsPage'
import { PlatformStockTakerDetailPage } from '../features/workforce/PlatformStockTakerDetailPage'
import { PlatformWorkforcePage } from '../features/workforce/PlatformWorkforcePage'
import { WorkforceAssignmentsPage } from '../features/workforce/WorkforceAssignmentsPage'
import { WorkforceEarningsPage } from '../features/workforce/WorkforceEarningsPage'
import { WorkforceProfilePage } from '../features/workforce/WorkforceProfilePage'
import { SchedulePage } from '../features/schedule/SchedulePage'
import { ScheduleDetailPage } from '../features/schedule/ScheduleDetailPage'

function requireAuth() {
  if (!getStoredToken()) throw redirect({ to: '/login' })
}

function redirectIfAuthed() {
  if (getStoredToken()) throw redirect({ to: '/app' })
}

const rootRoute = createRootRoute({
  component: () => <Outlet />,
})

const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  beforeLoad: () => {
    throw redirect({ to: getStoredToken() ? '/app' : '/login' })
  },
})

const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  beforeLoad: redirectIfAuthed,
  component: LoginPage,
})

const registerRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/register',
  beforeLoad: redirectIfAuthed,
  component: RegisterPage,
})

const forgotPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/forgot-password',
  beforeLoad: redirectIfAuthed,
  component: ForgotPasswordPage,
})

const changeInitialPasswordRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/change-initial-password',
  beforeLoad: requireAuth,
  component: ChangeInitialPasswordPage,
})

const verifyRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/verify',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <VerifyPage />
    </AppShell>
  ),
})

const onboardingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/onboarding',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <OnboardingPage />
    </AppShell>
  ),
})

const tenantOnboardingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/onboarding',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TenantOnboardingPage />
      </RequireTenant>
    </AppShell>
  ),
})

const appRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/app',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <DashboardPage />
    </AppShell>
  ),
})

const profileRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/profile',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <ProfilePage />
    </AppShell>
  ),
})

const settingsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TenantSettingsPage />
      </RequireTenant>
    </AppShell>
  ),
})

const notificationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/notifications',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <NotificationsPage />
    </AppShell>
  ),
})

const supportRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/support',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <SupportPage />
    </AppShell>
  ),
})

const platformRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformOverviewPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformTenantsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/tenants',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformTenantsPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformTenantDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/tenants/$tenantId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformTenantDetailPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformAuditRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/audit',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformAuditPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const businessTeamRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/team',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TeamPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessLocationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/locations',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <LocationsPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessCatalogueRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/catalogue',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <CataloguePage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessProductDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/catalogue/products/$productId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <ProductDetailPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessInventoryRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/inventory',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <InventoryPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessIntegrationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/integrations',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <RequirePermission permission="integration.manage">
          <IntegrationsPage />
        </RequirePermission>
      </RequireTenant>
    </AppShell>
  ),
})

const businessReportsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/reports',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <RequirePermission permission="report.view">
          <ReportsPage />
        </RequirePermission>
      </RequireTenant>
    </AppShell>
  ),
})

const platformReportsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/reports',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="report.view">
          <PlatformReportsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformSupportRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/support',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="support.view">
          <PlatformSupportPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <MarketingOverviewPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingPagesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/pages',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <MarketingPagesPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingPageEditorRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/pages/$pageId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <MarketingPageEditorPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingMediaRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/media',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="media.manage">
          <MediaLibraryPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingNavigationRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/navigation',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <NavigationPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingContentRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/content',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <ContentLibraryPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingSeoRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/seo',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="cms.view">
          <SeoPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingBlogRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/blog',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="blog.manage">
          <BlogPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingIntegrationsGoogleRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/integrations/google',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="integrations.google.manage">
          <GoogleIntegrationsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingIntegrationsMetaRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/integrations/meta',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="integrations.meta.manage">
          <MetaIntegrationsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingSocialRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/social',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="social.publish">
          <SocialPostsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingCampaignsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/campaigns',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="campaigns.view">
          <CampaignsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingAnalyticsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/analytics',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="analytics.view">
          <AnalyticsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingAutomationRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/automation',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="automation.view">
          <AutomationPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingLeadsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/leads',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="leads.view">
          <LeadsPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformMarketingLeadDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/marketing/leads/$leadId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="leads.view">
          <LeadDetailPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformCataloguePricingRoute = createRoute({  getParentRoute: () => rootRoute,
  path: '/platform/catalogue-pricing',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformCataloguePricingPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformStocktakingJobsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/stocktaking-jobs',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformStocktakeJobsPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformStocktakingJobRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/stocktaking-jobs/$jobId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <PlatformStocktakeJobPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformQaReviewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/stocktaking-jobs/$jobId/qa',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="qa.review">
          <QAReviewPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformStocktakePolicyRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/stocktake-policy',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <StocktakePolicyPage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformWorkforceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/workforce',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="workforce.view">
          <PlatformWorkforcePage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformStockTakerDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/workforce/$profileId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="workforce.view">
          <PlatformStockTakerDetailPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformFinanceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/finance',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <FinanceWorkspacePage />
      </RequirePlatform>
    </AppShell>
  ),
})

const platformOperationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/operations',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequireAnyPermission
          permissions={[
            'stocktake.job.monitor',
            'stocktake.job.assign',
            'stocktake.job.review',
            'sop.manage',
          ]}
        >
          <PlatformOperationsPage />
        </RequireAnyPermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const platformSopsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/platform/sops',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequirePlatform>
        <RequirePermission permission="sop.manage">
          <SopTemplatesPage />
        </RequirePermission>
      </RequirePlatform>
    </AppShell>
  ),
})

const businessStocktakingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/stocktaking',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TenantStocktakePage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessStocktakingNewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/stocktaking/new',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <StocktakeRequestPage mode="create" />
      </RequireTenant>
    </AppShell>
  ),
})

const businessStocktakingJobRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/stocktaking/$jobId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TenantStocktakeJobPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessStocktakingJobReviewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/stocktaking/$jobId/review',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <TenantResultsReviewPage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessStocktakingEditRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/stocktaking/$jobId/edit',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <StocktakeRequestPage mode="edit" />
      </RequireTenant>
    </AppShell>
  ),
})

const businessSchedulesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/schedules',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <SchedulePage />
      </RequireTenant>
    </AppShell>
  ),
})

const businessScheduleDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/business/schedules/$scheduleId',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <ScheduleDetailPage />
      </RequireTenant>
    </AppShell>
  ),
})

const acceptInvitationRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/invitations/accept',
  component: AcceptInvitationPage,
})

const workforceAssignmentsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/workforce/assignments',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireWorkforce>
        <WorkforceAssignmentsPage />
      </RequireWorkforce>
    </AppShell>
  ),
})

const workforceAssignmentOperationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/workforce/assignments/$assignmentId/operations',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireWorkforce>
        <WorkerAssignmentOperationsPage />
      </RequireWorkforce>
    </AppShell>
  ),
})

const workforceAssignmentCountRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/workforce/assignments/$assignmentId/count',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireWorkforce>
        <WorkerCountPage />
      </RequireWorkforce>
    </AppShell>
  ),
})

const workforceProfileRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/workforce/profile',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireWorkforce>
        <WorkforceProfilePage />
      </RequireWorkforce>
    </AppShell>
  ),
})

const workforceEarningsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/workforce/earnings',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireWorkforce>
        <WorkforceEarningsPage />
      </RequireWorkforce>
    </AppShell>
  ),
})

const posRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/pos',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <PosTerminalPage />
      </RequireTenant>
    </AppShell>
  ),
})

const posTerminalsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/pos/terminals',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <PosTerminalsPage />
      </RequireTenant>
    </AppShell>
  ),
})

const posShiftsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/pos/shifts',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <PosShiftsPage />
      </RequireTenant>
    </AppShell>
  ),
})

const posStocktakeRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/pos/stocktake',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <PosStocktakePage />
      </RequireTenant>
    </AppShell>
  ),
})

const posBackOfficeRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/pos/back-office',
  beforeLoad: requireAuth,
  component: () => (
    <AppShell>
      <RequireTenant>
        <PosBackOfficePage />
      </RequireTenant>
    </AppShell>
  ),
})

const routeTree = rootRoute.addChildren([
  indexRoute,
  loginRoute,
  registerRoute,
  forgotPasswordRoute,
  changeInitialPasswordRoute,
  verifyRoute,
  onboardingRoute,
  tenantOnboardingRoute,
  appRoute,
  profileRoute,
  settingsRoute,
  notificationsRoute,
  supportRoute,
  platformRoute,
  platformTenantsRoute,
  platformTenantDetailRoute,
  platformAuditRoute,
  businessTeamRoute,
  businessLocationsRoute,
  businessCatalogueRoute,
  businessProductDetailRoute,
  businessInventoryRoute,
  businessIntegrationsRoute,
  businessReportsRoute,
  platformReportsRoute,
  platformSupportRoute,
  platformMarketingRoute,
  platformMarketingPagesRoute,
  platformMarketingPageEditorRoute,
  platformMarketingMediaRoute,
  platformMarketingNavigationRoute,
  platformMarketingContentRoute,
  platformMarketingSeoRoute,
  platformMarketingBlogRoute,
  platformMarketingIntegrationsGoogleRoute,
  platformMarketingIntegrationsMetaRoute,
  platformMarketingSocialRoute,
  platformMarketingCampaignsRoute,
  platformMarketingAnalyticsRoute,
  platformMarketingAutomationRoute,
  platformMarketingLeadsRoute,
  platformMarketingLeadDetailRoute,
  platformCataloguePricingRoute,
  platformStocktakingJobsRoute,
  platformStocktakingJobRoute,
  platformQaReviewRoute,
  platformStocktakePolicyRoute,
  platformWorkforceRoute,
  platformStockTakerDetailRoute,
  platformFinanceRoute,
  platformOperationsRoute,
  platformSopsRoute,
  businessStocktakingRoute,
  businessStocktakingNewRoute,
  businessStocktakingJobRoute,
  businessStocktakingJobReviewRoute,
  businessStocktakingEditRoute,
  businessSchedulesRoute,
  businessScheduleDetailRoute,
  acceptInvitationRoute,
  workforceAssignmentsRoute,
  workforceAssignmentOperationsRoute,
  workforceAssignmentCountRoute,
  workforceProfileRoute,
  workforceEarningsRoute,
  posRoute,
  posTerminalsRoute,
  posShiftsRoute,
  posStocktakeRoute,
  posBackOfficeRoute,
])

export const router = createRouter({ routeTree })

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
