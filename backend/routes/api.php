<?php

use App\Http\Controllers\Api\V1\Account\NotificationController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Integrations\WebhookController as IntegrationWebhookController;
use App\Http\Controllers\Api\V1\Payments\PaymentCallbackController;
use App\Http\Controllers\Api\V1\Platform\AssignmentController as PlatformAssignmentController;
use App\Http\Controllers\Api\V1\Platform\AuditLogController;
use App\Http\Controllers\Api\V1\Platform\CataloguePricingController as PlatformCataloguePricingController;
use App\Http\Controllers\Api\V1\Platform\DisputeController as PlatformDisputeController;
use App\Http\Controllers\Api\V1\Platform\DomainManagementController;
use App\Http\Controllers\Api\V1\Platform\EmailManagementController;
use App\Http\Controllers\Api\V1\Platform\FinanceController;
use App\Http\Controllers\Api\V1\Platform\JobOperationsController as PlatformJobOperationsController;
use App\Http\Controllers\Api\V1\Platform\Marketing\AutomationController;
use App\Http\Controllers\Api\V1\Platform\Marketing\BlogPostController;
use App\Http\Controllers\Api\V1\Platform\Marketing\BlogTaxonomyController;
use App\Http\Controllers\Api\V1\Platform\Marketing\CampaignController;
use App\Http\Controllers\Api\V1\Platform\Marketing\EditorialItemController;
use App\Http\Controllers\Api\V1\Platform\Marketing\GoogleIntegrationController;
use App\Http\Controllers\Api\V1\Platform\Marketing\GoogleOAuthCallbackController;
use App\Http\Controllers\Api\V1\Platform\Marketing\LeadController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingAnalyticsController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingBannerController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingCtaController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingFaqController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingMenuController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingOverviewController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingPageController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MarketingTestimonialController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MediaAssetController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MediaFolderController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MetaIntegrationController;
use App\Http\Controllers\Api\V1\Platform\Marketing\MetaOAuthCallbackController;
use App\Http\Controllers\Api\V1\Platform\Marketing\ResourceDownloadController;
use App\Http\Controllers\Api\V1\Platform\Marketing\SeoController;
use App\Http\Controllers\Api\V1\Platform\Marketing\SocialPostController;
use App\Http\Controllers\Api\V1\Platform\Marketing\StructuredDataController;
use App\Http\Controllers\Api\V1\Platform\PayoutController as PlatformPayoutController;
use App\Http\Controllers\Api\V1\Platform\PlatformCountController;
use App\Http\Controllers\Api\V1\Platform\PlatformReportController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantController;
use App\Http\Controllers\Api\V1\Platform\ProcedureTemplateController as PlatformProcedureTemplateController;
use App\Http\Controllers\Api\V1\Platform\QAController as PlatformQAController;
use App\Http\Controllers\Api\V1\Platform\StocktakeJobController as PlatformStocktakeJobController;
use App\Http\Controllers\Api\V1\Platform\StocktakePolicyController as PlatformStocktakePolicyController;
use App\Http\Controllers\Api\V1\Platform\SupportController as PlatformSupportController;
use App\Http\Controllers\Api\V1\Platform\WorkforceController as PlatformWorkforceController;
use App\Http\Controllers\Api\V1\Public\DomainController;
use App\Http\Controllers\Api\V1\Public\RegistrationController;
use App\Http\Controllers\Api\V1\PublicAnalyticsController;
use App\Http\Controllers\Api\V1\PublicArticleController;
use App\Http\Controllers\Api\V1\PublicFormController;
use App\Http\Controllers\Api\V1\PublicVitalsController;
use App\Http\Controllers\Api\V1\Tenant\BranchController;
use App\Http\Controllers\Api\V1\Tenant\CatalogueController;
use App\Http\Controllers\Api\V1\Tenant\CatalogueDeclarationController;
use App\Http\Controllers\Api\V1\Tenant\CatalogueImportController;
use App\Http\Controllers\Api\V1\Tenant\CataloguePricingController;
use App\Http\Controllers\Api\V1\Tenant\DepartmentController;
use App\Http\Controllers\Api\V1\Tenant\IntegrationController;
use App\Http\Controllers\Api\V1\Tenant\InventoryController;
use App\Http\Controllers\Api\V1\Tenant\InventoryCutoffController;
use App\Http\Controllers\Api\V1\Tenant\InventoryMovementController;
use App\Http\Controllers\Api\V1\Tenant\InventorySnapshotController;
use App\Http\Controllers\Api\V1\Tenant\InvitationController;
use App\Http\Controllers\Api\V1\Tenant\JobOperationsController as TenantJobOperationsController;
use App\Http\Controllers\Api\V1\Tenant\PosController;
use App\Http\Controllers\Api\V1\Tenant\PosStocktakeController;
use App\Http\Controllers\Api\V1\Tenant\ProductCategoryController;
use App\Http\Controllers\Api\V1\Tenant\ProductController;
use App\Http\Controllers\Api\V1\Tenant\ReportController;
use App\Http\Controllers\Api\V1\Tenant\ScheduleController;
use App\Http\Controllers\Api\V1\Tenant\StockBinController;
use App\Http\Controllers\Api\V1\Tenant\StockLocationController;
use App\Http\Controllers\Api\V1\Tenant\StocktakeJobController;
use App\Http\Controllers\Api\V1\Tenant\StockZoneController;
use App\Http\Controllers\Api\V1\Tenant\SupportTicketController;
use App\Http\Controllers\Api\V1\Tenant\TenantController;
use App\Http\Controllers\Api\V1\Tenant\TenantCountController;
use App\Http\Controllers\Api\V1\Tenant\TenantMemberController;
use App\Http\Controllers\Api\V1\Tenant\TenantOnboardingController;
use App\Http\Controllers\Api\V1\Tenant\TenantReviewController;
use App\Http\Controllers\Api\V1\Tenant\TenantRoleController;
use App\Http\Controllers\Api\V1\Tenant\UnitController;
use App\Http\Controllers\Api\V1\Tenant\WarehouseController;
use App\Http\Controllers\Api\V1\Workforce\AssignmentController as WorkforceAssignmentController;
use App\Http\Controllers\Api\V1\Workforce\CountController as WorkforceCountController;
use App\Http\Controllers\Api\V1\Workforce\OfflineSyncController;
use App\Http\Controllers\Api\V1\Workforce\OperationsController as WorkforceOperationsController;
use App\Http\Controllers\Api\V1\Workforce\ProfileController as WorkforceProfileController;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('public/domains')->group(function (): void {
        Route::get('/suggestions', [DomainController::class, 'suggestions'])
            ->middleware('throttle:30,1');
        Route::post('/check', [DomainController::class, 'check'])
            ->middleware('throttle:30,1');
    });

    Route::get('/public/invitations/{token}', [InvitationController::class, 'preview'])
        ->middleware('throttle:30,1');
    Route::post('/public/invitations/{token}/activate', [InvitationController::class, 'activate'])
        ->middleware('throttle:10,1');

    Route::prefix('public/registration')->middleware('throttle:10,1')->group(function (): void {
        Route::post('/', [RegistrationController::class, 'start']);
        Route::post('/verify', [RegistrationController::class, 'verify'])->middleware('throttle:10,1');
        Route::post('/resend-code', [RegistrationController::class, 'resend'])->middleware('throttle:5,1');
    });

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
        Route::post('/login', LoginController::class)->middleware('throttle:login');
        Route::post('/logout', LogoutController::class)->middleware(['auth:sanctum', 'password.changed']);
        Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
        Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1');
    });

    Route::prefix('payments')->middleware('throttle:120,1')->group(function (): void {
        Route::post('/mpesa/callback', [PaymentCallbackController::class, 'mpesa']);
        Route::post('/card/callback', [PaymentCallbackController::class, 'card']);
    });

    Route::post('/integrations/webhooks/{connection:uuid}', IntegrationWebhookController::class)
        ->middleware('throttle:60,1');

    // Phase 5 — public lead capture forms
    Route::prefix('public/forms')->middleware('throttle:public-forms')->group(function (): void {
        Route::post('/{form}', [PublicFormController::class, 'store'])
            ->whereIn('form', ['quote', 'pos-demo', 'contact', 'callback', 'partnership']);
    });

    // Phase 6 — public core web vitals beacon
    Route::post('/public/web-vitals', [PublicVitalsController::class, 'store'])
        ->middleware('throttle:web-vitals');

    // Phase 12 — consent-aware analytics
    Route::post('/public/analytics/consent', [PublicAnalyticsController::class, 'consent'])
        ->middleware('throttle:public-events');
    Route::post('/public/analytics/events', [PublicAnalyticsController::class, 'events'])
        ->middleware('throttle:public-events');

    // Phase 8 — public article reads
    Route::get('/public/articles', [PublicArticleController::class, 'index']);
    Route::get('/public/articles/{slug}', [PublicArticleController::class, 'show']);
    Route::get('/public/article-categories', [PublicArticleController::class, 'categories']);

    // Phase 9 — Google OAuth callback (state is single-use and user-bound)
    Route::get('/platform/marketing/integrations/google/callback', GoogleOAuthCallbackController::class)
        ->middleware('throttle:30,1');

    // Phase 10 — Meta OAuth callback (state is single-use and user-bound; no PKCE)
    Route::get('/platform/marketing/integrations/meta/callback', MetaOAuthCallbackController::class)
        ->middleware('throttle:30,1');

    Route::middleware(['auth:sanctum', 'password.changed', 'throttle:api'])->group(function (): void {
        Route::get('/me', fn (Request $request) => ApiResponse::success(
            new UserResource($request->user()),
        ));
        Route::post('/auth/change-initial-password', [PasswordController::class, 'changeInitial'])
            ->middleware('throttle:5,1');

        // Phase 19 — account notifications
        Route::get('/me/notifications', [NotificationController::class, 'index']);
        Route::post('/me/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('/me/notifications/{notification:uuid}/read', [NotificationController::class, 'markRead']);
        Route::get('/me/notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('/me/notification-preferences', [NotificationController::class, 'updatePreferences']);

        Route::get('/dashboard', DashboardController::class);

        Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1');
        Route::post('/auth/verify-email', [EmailVerificationController::class, 'confirm'])
            ->middleware('throttle:10,1');

        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store'])->middleware('throttle:10,1');
        Route::get('/tenants/{tenant:uuid}', [TenantController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}', [TenantController::class, 'update']);
        Route::get('/tenants/{tenant:uuid}/onboarding', [TenantOnboardingController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}/onboarding/{step}', [TenantOnboardingController::class, 'update']);
        Route::get('/tenants/{tenant:uuid}/members', [TenantMemberController::class, 'index']);
        Route::patch('/tenants/{tenant:uuid}/members/{user:uuid}', [TenantMemberController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/members/{user:uuid}', [TenantMemberController::class, 'destroy']);

        Route::get('/tenants/{tenant:uuid}/roles', [TenantRoleController::class, 'index']);

        Route::get('/tenants/{tenant:uuid}/branches', [BranchController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/branches', [BranchController::class, 'store']);
        Route::patch('/tenants/{tenant:uuid}/branches/{branch:uuid}', [BranchController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/branches/{branch:uuid}', [BranchController::class, 'destroy']);

        Route::get('/tenants/{tenant:uuid}/departments', [DepartmentController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/departments', [DepartmentController::class, 'store']);
        Route::patch('/tenants/{tenant:uuid}/departments/{department:uuid}', [DepartmentController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/departments/{department:uuid}', [DepartmentController::class, 'destroy']);

        Route::get('/tenants/{tenant:uuid}/warehouses', [WarehouseController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/warehouses', [WarehouseController::class, 'store']);
        Route::patch('/tenants/{tenant:uuid}/warehouses/{warehouse:uuid}', [WarehouseController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/warehouses/{warehouse:uuid}', [WarehouseController::class, 'destroy']);

        Route::get('/tenants/{tenant:uuid}/stock-locations', [StockLocationController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/stock-locations', [StockLocationController::class, 'store']);
        Route::patch('/tenants/{tenant:uuid}/stock-locations/{stockLocation:uuid}', [StockLocationController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/stock-locations/{stockLocation:uuid}', [StockLocationController::class, 'destroy']);

        Route::get('/tenants/{tenant:uuid}/stock-locations/{stockLocation:uuid}/zones', [StockZoneController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/stock-locations/{stockLocation:uuid}/zones', [StockZoneController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/stock-zones/{stockZone:uuid}/bins', [StockBinController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/stock-zones/{stockZone:uuid}/bins', [StockBinController::class, 'store']);

        Route::get('/tenants/{tenant:uuid}/invitations', [InvitationController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/invitations', [InvitationController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/tenants/{tenant:uuid}/invitations/{invitation:uuid}/resend', [InvitationController::class, 'resend'])
            ->middleware('throttle:10,1');
        Route::post('/tenants/{tenant:uuid}/invitations/{invitation:uuid}/revoke', [InvitationController::class, 'revoke']);

        Route::get('/tenants/{tenant:uuid}/catalogue', [CatalogueController::class, 'summary']);
        Route::get('/tenants/{tenant:uuid}/catalogues/{catalogue:uuid}/versions', [CatalogueController::class, 'versions']);
        Route::get('/tenants/{tenant:uuid}/catalogues/{catalogue:uuid}/declarations', [CatalogueController::class, 'declarations']);
        Route::post('/tenants/{tenant:uuid}/catalogues/{catalogue:uuid}/declarations', [CatalogueDeclarationController::class, 'store']);

        Route::get('/tenants/{tenant:uuid}/catalogue/imports', [CatalogueImportController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/catalogue/imports', [CatalogueImportController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/tenants/{tenant:uuid}/catalogue/imports/{catalogueImport:uuid}', [CatalogueImportController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}/catalogue/imports/{catalogueImport:uuid}/mapping', [CatalogueImportController::class, 'updateMapping']);
        Route::post('/tenants/{tenant:uuid}/catalogue/imports/{catalogueImport:uuid}/commit', [CatalogueImportController::class, 'commit']);
        Route::get('/tenants/{tenant:uuid}/catalogue/imports/{catalogueImport:uuid}/errors', [CatalogueImportController::class, 'errors']);

        Route::get('/tenants/{tenant:uuid}/products', [ProductController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/products', [ProductController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/products/{product:uuid}', [ProductController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}/products/{product:uuid}', [ProductController::class, 'update']);
        Route::delete('/tenants/{tenant:uuid}/products/{product:uuid}', [ProductController::class, 'destroy']);
        Route::post('/tenants/{tenant:uuid}/products/{product:uuid}/barcodes', [ProductController::class, 'addBarcode']);
        Route::delete('/tenants/{tenant:uuid}/products/{product:uuid}/barcodes/{barcode:uuid}', [ProductController::class, 'removeBarcode']);

        Route::get('/tenants/{tenant:uuid}/product-categories', [ProductCategoryController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/product-categories', [ProductCategoryController::class, 'store']);

        Route::get('/tenants/{tenant:uuid}/units', [UnitController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/units', [UnitController::class, 'store']);
        Route::post('/tenants/{tenant:uuid}/units/conversions', [UnitController::class, 'storeConversion']);

        Route::get('/catalogue-pricing/estimate', [CataloguePricingController::class, 'estimate']);

        Route::get('/tenants/{tenant:uuid}/inventory', [InventoryController::class, 'summary']);
        Route::get('/tenants/{tenant:uuid}/inventory/balances', [InventoryController::class, 'balances']);
        Route::get('/tenants/{tenant:uuid}/inventory/batches', [InventoryController::class, 'batches']);
        Route::get('/tenants/{tenant:uuid}/inventory/serials', [InventoryController::class, 'serials']);

        Route::get('/tenants/{tenant:uuid}/inventory/movements', [InventoryMovementController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/inventory/movements', [InventoryMovementController::class, 'store']);
        Route::post('/tenants/{tenant:uuid}/inventory/movements/import', [InventoryMovementController::class, 'import'])->middleware('throttle:20,1');
        Route::get('/tenants/{tenant:uuid}/inventory/movements/{movement:uuid}', [InventoryMovementController::class, 'show']);

        Route::get('/tenants/{tenant:uuid}/inventory/cutoffs', [InventoryCutoffController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/inventory/cutoffs', [InventoryCutoffController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/inventory/cutoffs/{cutoff:uuid}', [InventoryCutoffController::class, 'show']);
        Route::post('/tenants/{tenant:uuid}/inventory/cutoffs/{cutoff:uuid}/close', [InventoryCutoffController::class, 'close']);

        Route::get('/tenants/{tenant:uuid}/inventory/snapshots', [InventorySnapshotController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/inventory/snapshots', [InventorySnapshotController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/inventory/snapshots/{snapshot:uuid}', [InventorySnapshotController::class, 'show']);
        Route::get('/tenants/{tenant:uuid}/inventory/snapshots/{snapshot:uuid}/items', [InventorySnapshotController::class, 'items']);

        Route::get('/tenants/{tenant:uuid}/stocktake-jobs', [StocktakeJobController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/estimate', [StocktakeJobController::class, 'estimate']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs', [StocktakeJobController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}', [StocktakeJobController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}', [StocktakeJobController::class, 'update']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/submit', [StocktakeJobController::class, 'submit']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/cancel', [StocktakeJobController::class, 'cancel']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/deposits', [StocktakeJobController::class, 'payDeposit']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/payments', [StocktakeJobController::class, 'pay']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/payments/{payment}/simulate', [StocktakeJobController::class, 'simulatePayment']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/quote/accept', [StocktakeJobController::class, 'acceptQuote']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/quote/decline', [StocktakeJobController::class, 'declineQuote']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/amendments', [StocktakeJobController::class, 'amend']);
        Route::get('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/operations', [TenantJobOperationsController::class, 'show']);
        Route::get('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/counts', [TenantCountController::class, 'show']);
        Route::get('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review', [TenantReviewController::class, 'show']);
        // Phase 15 — schedules
        Route::get('/tenants/{tenant:uuid}/schedules', [ScheduleController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/schedules', [ScheduleController::class, 'store']);
        Route::get('/tenants/{tenant:uuid}/schedules/{schedule:uuid}', [ScheduleController::class, 'show']);
        Route::patch('/tenants/{tenant:uuid}/schedules/{schedule:uuid}', [ScheduleController::class, 'update']);
        Route::post('/tenants/{tenant:uuid}/schedules/{schedule:uuid}/generate', [ScheduleController::class, 'generate']);
        Route::post('/tenants/{tenant:uuid}/schedules/{schedule:uuid}/trigger', [ScheduleController::class, 'trigger']);
        Route::post('/tenants/{tenant:uuid}/schedule-occurrences/{occurrence:uuid}/skip', [ScheduleController::class, 'skipOccurrence']);
        // Phase 16 — POS core
        Route::get('/tenants/{tenant:uuid}/pos/terminals', [PosController::class, 'terminals']);
        Route::post('/tenants/{tenant:uuid}/pos/terminals', [PosController::class, 'storeTerminal']);
        Route::patch('/tenants/{tenant:uuid}/pos/terminals/{terminal:uuid}', [PosController::class, 'updateTerminal']);
        Route::get('/tenants/{tenant:uuid}/pos/products', [PosController::class, 'products']);
        Route::get('/tenants/{tenant:uuid}/pos/shifts', [PosController::class, 'shifts']);
        Route::post('/tenants/{tenant:uuid}/pos/terminals/{terminal:uuid}/shifts/open', [PosController::class, 'openShift']);
        Route::post('/tenants/{tenant:uuid}/pos/shifts/{shift:uuid}/close', [PosController::class, 'closeShift']);
        Route::post('/tenants/{tenant:uuid}/pos/shifts/{shift:uuid}/reconcile', [PosController::class, 'reconcileShift']);
        Route::get('/tenants/{tenant:uuid}/pos/shifts/{shiftId}/reconciliation', [PosController::class, 'shiftReconciliation']);
        Route::get('/tenants/{tenant:uuid}/pos/sales', [PosController::class, 'sales']);
        Route::post('/tenants/{tenant:uuid}/pos/sales', [PosController::class, 'storeSale']);
        Route::get('/tenants/{tenant:uuid}/pos/sales/{sale:uuid}', [PosController::class, 'showSale']);
        Route::get('/tenants/{tenant:uuid}/pos/suppliers', [PosController::class, 'suppliers']);
        Route::post('/tenants/{tenant:uuid}/pos/suppliers', [PosController::class, 'storeSupplier']);
        Route::get('/tenants/{tenant:uuid}/pos/purchases', [PosController::class, 'purchases']);
        Route::post('/tenants/{tenant:uuid}/pos/purchases', [PosController::class, 'storePurchase']);
        Route::get('/tenants/{tenant:uuid}/pos/customers', [PosController::class, 'customers']);
        Route::post('/tenants/{tenant:uuid}/pos/customers', [PosController::class, 'storeCustomer']);
        Route::patch('/tenants/{tenant:uuid}/pos/customers/{customer:uuid}', [PosController::class, 'updateCustomer']);
        Route::get('/tenants/{tenant:uuid}/pos/returns', [PosController::class, 'returns']);
        Route::post('/tenants/{tenant:uuid}/pos/returns', [PosController::class, 'storeReturn']);
        Route::get('/tenants/{tenant:uuid}/pos/transfers', [PosController::class, 'transfers']);
        Route::post('/tenants/{tenant:uuid}/pos/transfers', [PosController::class, 'storeTransfer']);
        Route::post('/tenants/{tenant:uuid}/pos/transfers/{transfer:uuid}/receive', [PosController::class, 'receiveTransfer']);

        // Phase 18 — external POS/ERP integrations
        Route::prefix('tenants/{tenant:uuid}/integrations')->group(function (): void {
            Route::get('/', [IntegrationController::class, 'index']);
            Route::post('/', [IntegrationController::class, 'store']);
            Route::patch('/{connection:uuid}', [IntegrationController::class, 'update']);
            Route::delete('/{connection:uuid}', [IntegrationController::class, 'destroy']);
            Route::post('/{connection:uuid}/test', [IntegrationController::class, 'test']);
            Route::get('/{connection:uuid}/mappings/{entity}', [IntegrationController::class, 'mapping']);
            Route::put('/{connection:uuid}/mappings/{entity}', [IntegrationController::class, 'saveMapping']);
            Route::post('/{connection:uuid}/sync', [IntegrationController::class, 'sync'])->middleware('throttle:30,1');
            Route::get('/{connection:uuid}/runs', [IntegrationController::class, 'runs']);
            Route::get('/{connection:uuid}/conflicts', [IntegrationController::class, 'conflicts']);
            Route::post('/{connection:uuid}/conflicts/{conflict:uuid}/resolve', [IntegrationController::class, 'resolveConflict']);
            Route::get('/{connection:uuid}/webhooks', [IntegrationController::class, 'webhooks']);
        });

        // Phase 19 — reporting, analytics, support
        Route::get('/tenants/{tenant:uuid}/reports', [ReportController::class, 'index']);
        Route::get('/tenants/{tenant:uuid}/reports/{type}/export', [ReportController::class, 'export']);
        Route::get('/tenants/{tenant:uuid}/reports/{type}', [ReportController::class, 'show']);
        Route::get('/tenants/{tenant:uuid}/analytics/overview', [ReportController::class, 'analytics']);
        Route::get('/tenants/{tenant:uuid}/support-tickets', [SupportTicketController::class, 'index']);
        Route::post('/tenants/{tenant:uuid}/support-tickets', [SupportTicketController::class, 'store'])->middleware('throttle:20,1');
        Route::get('/tenants/{tenant:uuid}/support-tickets/{ticket:uuid}', [SupportTicketController::class, 'show']);
        Route::post('/tenants/{tenant:uuid}/support-tickets/{ticket:uuid}/messages', [SupportTicketController::class, 'reply'])->middleware('throttle:30,1');
        Route::patch('/tenants/{tenant:uuid}/support-tickets/{ticket:uuid}', [SupportTicketController::class, 'update']);

        // Phase 17 — POS stock-take integration
        Route::prefix('tenants/{tenant:uuid}/pos/stocktake')->group(function (): void {
            Route::get('/check-catalogue-readiness', [PosStocktakeController::class, 'checkCatalogueReadiness'])
                ->middleware('permission:pos.manage|stocktake.request.create');
            Route::get('/fetch-eligibility', [PosStocktakeController::class, 'eligibility'])
                ->middleware('permission:pos.manage|stocktake.request.create');
            Route::get('/jobs', [PosStocktakeController::class, 'jobs'])
                ->middleware('permission:pos.manage|stocktake.request.create');
            Route::post('/commission', [PosStocktakeController::class, 'commissionStocktake'])
                ->middleware('permission:stocktake.request.create|pos.manage');
            Route::post('/{job:uuid}/authorize', [PosStocktakeController::class, 'authorizeStocktakeJob'])
                ->middleware('permission:pos.manage|stocktake.request.create');
            Route::post('/{job:uuid}/decline', [PosStocktakeController::class, 'declineStocktakeJob'])
                ->middleware('permission:pos.manage|stocktake.request.create');
            Route::post('/generate-quote/{sale:uuid}', [PosStocktakeController::class, 'generateQuote'])
                ->middleware('permission:pos.sales.create|pos.manage');
        });

        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/accept', [TenantReviewController::class, 'accept']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/correction', [TenantReviewController::class, 'requestCorrection']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/recount', [TenantReviewController::class, 'requestRecount']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/dispute', [TenantReviewController::class, 'raiseDispute']);
        Route::put('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/items/{item}/flag', [TenantReviewController::class, 'flagItem']);
        Route::post('/tenants/{tenant:uuid}/stocktake-jobs/{job:uuid}/review/reconciliation', [TenantReviewController::class, 'generateReconciliation']);

        Route::get('/invitations/{token}', [InvitationController::class, 'preview']);
        Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept']);

        Route::prefix('workforce')->middleware('workforce')->group(function (): void {
            Route::get('/profile', [WorkforceProfileController::class, 'show'])
                ->middleware('permission:workforce.profile.view');
            Route::post('/profile', [WorkforceProfileController::class, 'store'])
                ->middleware('permission:workforce.profile.view');
            Route::patch('/profile', [WorkforceProfileController::class, 'update'])
                ->middleware('permission:workforce.profile.view');
            Route::put('/profile/skills', [WorkforceProfileController::class, 'skills'])
                ->middleware('permission:workforce.profile.view');
            Route::put('/profile/service-areas', [WorkforceProfileController::class, 'serviceAreas'])
                ->middleware('permission:workforce.profile.view');
            Route::put('/profile/availability', [WorkforceProfileController::class, 'availability'])
                ->middleware('permission:workforce.profile.view');

            Route::get('/assignments', [WorkforceAssignmentController::class, 'index'])
                ->middleware('permission:assignment.view');
            Route::get('/assignments/{assignment}', [WorkforceAssignmentController::class, 'show'])
                ->middleware('permission:assignment.view');
            Route::post('/assignments/{assignment}/accept', [WorkforceAssignmentController::class, 'accept'])
                ->middleware('permission:assignment.accept');
            Route::post('/assignments/{assignment}/decline', [WorkforceAssignmentController::class, 'decline'])
                ->middleware('permission:assignment.accept');
            Route::get('/earnings', [WorkforceAssignmentController::class, 'earnings'])
                ->middleware('permission:earnings.view');

            Route::get('/assignments/{assignment}/operations', [WorkforceOperationsController::class, 'show'])
                ->middleware('permission:assignment.view');
            Route::post('/assignments/{assignment}/procedure/acknowledge', [WorkforceOperationsController::class, 'acknowledge'])
                ->middleware('permission:sop.acknowledge');
            Route::put('/assignments/{assignment}/checklists/{item}', [WorkforceOperationsController::class, 'checklist'])
                ->middleware('permission:sop.acknowledge');
            Route::post('/assignments/{assignment}/check-in', [WorkforceOperationsController::class, 'checkIn'])
                ->middleware('permission:job.checkin');
            Route::post('/assignments/{assignment}/check-out', [WorkforceOperationsController::class, 'checkOut'])
                ->middleware('permission:job.checkin');

            Route::get('/assignments/{assignment}/count', [WorkforceCountController::class, 'workspace'])
                ->middleware('permission:stocktake.count.view');
            Route::post('/assignments/{assignment}/count/sessions', [WorkforceCountController::class, 'start'])
                ->middleware('permission:stocktake.count.view');
            Route::get('/assignments/{assignment}/count/sessions/{session}', [WorkforceCountController::class, 'show'])
                ->middleware('permission:stocktake.count.view');
            Route::post('/assignments/{assignment}/count/sessions/{session}/entries', [WorkforceCountController::class, 'upsertEntry'])
                ->middleware('permission:stocktake.count.view');
            Route::patch('/assignments/{assignment}/count/sessions/{session}/entries/{entry}', [WorkforceCountController::class, 'reviseEntry'])
                ->middleware('permission:stocktake.count.view');
            Route::post('/assignments/{assignment}/count/sessions/{session}/exceptions', [WorkforceCountController::class, 'exception'])
                ->middleware('permission:count.exception.create');
            Route::post('/assignments/{assignment}/count/sessions/{session}/unknown-products', [WorkforceCountController::class, 'unknownProduct'])
                ->middleware('permission:count.exception.create');
            Route::post('/assignments/{assignment}/count/sessions/{session}/submit', [WorkforceCountController::class, 'submit'])
                ->middleware('permission:stocktake.count.submit');

            // Phase 11 — offline sync
            Route::get('/assignments/{assignment}/count/package', [OfflineSyncController::class, 'package'])
                ->middleware('permission:stocktake.count.view');
            Route::post('/assignments/{assignment}/count/heartbeat', [OfflineSyncController::class, 'heartbeat'])
                ->middleware('permission:stocktake.count.view');
            Route::post('/assignments/{assignment}/count/sessions/{session}/sync', [OfflineSyncController::class, 'sync'])
                ->middleware('permission:stocktake.count.view');
        });

        Route::prefix('platform')->middleware('platform')->group(function (): void {
            Route::get('/overview', [PlatformTenantController::class, 'overview'])
                ->middleware('permission:tenant.view');
            Route::get('/tenants', [PlatformTenantController::class, 'index'])
                ->middleware('permission:tenant.view');
            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:audit.view');
            Route::get('/domains', [DomainManagementController::class, 'index'])
                ->middleware('permission:domain.manage|tenant.view');
            Route::get('/domains/reservations', [DomainManagementController::class, 'reservations'])
                ->middleware('permission:domain.manage|tenant.view');
            Route::post('/domains/settings', [DomainManagementController::class, 'settings'])
                ->middleware('permission:domain.manage');
            Route::post('/domains/allocate', [DomainManagementController::class, 'allocate'])
                ->middleware('permission:domain.manage');
            Route::post('/domains/block', [DomainManagementController::class, 'block'])
                ->middleware('permission:domain.manage');
            Route::get('/email/settings', [EmailManagementController::class, 'settings'])
                ->middleware('permission:email.manage');
            Route::post('/email/settings', [EmailManagementController::class, 'update'])
                ->middleware('permission:email.manage');
            Route::post('/email/test', [EmailManagementController::class, 'test'])
                ->middleware('permission:email.manage|email.test');
            Route::get('/email/logs', [EmailManagementController::class, 'logs'])
                ->middleware('permission:email.manage|email.view');
            Route::post('/email/logs/{log:uuid}/retry', [EmailManagementController::class, 'retry'])
                ->middleware('permission:email.manage|email.retry');
            Route::get('/catalogue-pricing', [PlatformCataloguePricingController::class, 'show'])
                ->middleware('permission:pricing.manage');
            Route::patch('/catalogue-pricing', [PlatformCataloguePricingController::class, 'update'])
                ->middleware('permission:pricing.manage');

            Route::get('/stocktake-policy', [PlatformStocktakePolicyController::class, 'show'])
                ->middleware('permission:pricing.manage');
            Route::patch('/stocktake-policy', [PlatformStocktakePolicyController::class, 'update'])
                ->middleware('permission:pricing.manage');

            Route::get('/stocktake-jobs', [PlatformStocktakeJobController::class, 'index'])
                ->middleware('permission:stocktake.job.review|finance.payment.record|stocktake.quote.view|stocktake.job.assign|stocktake.job.monitor');
            Route::get('/stocktake-jobs/{job}', [PlatformStocktakeJobController::class, 'show'])
                ->middleware('permission:stocktake.job.review|finance.payment.record|stocktake.quote.view|stocktake.job.assign|stocktake.job.monitor');
            Route::post('/stocktake-jobs/{job}/review', [PlatformStocktakeJobController::class, 'startReview'])
                ->middleware('permission:stocktake.job.review');
            Route::post('/stocktake-jobs/{job}/request-information', [PlatformStocktakeJobController::class, 'requestInformation'])
                ->middleware('permission:stocktake.job.review');
            Route::post('/stocktake-jobs/{job}/quotes', [PlatformStocktakeJobController::class, 'issueQuote'])
                ->middleware('permission:stocktake.quote.issue');
            Route::post('/stocktake-jobs/{job}/deposits/{payment}/verify', [PlatformStocktakeJobController::class, 'verifyPayment'])
                ->middleware('permission:finance.payment.record');
            Route::post('/stocktake-jobs/{job}/deposit/waive', [PlatformStocktakeJobController::class, 'waiveDeposit'])
                ->middleware('permission:finance.payment.record');
            Route::post('/stocktake-jobs/{job}/amendments/{amendment}/decision', [PlatformStocktakeJobController::class, 'decideAmendment'])
                ->middleware('permission:stocktake.job.review');

            Route::get('/stocktake-jobs/{job}/assignment-recommendations', [PlatformAssignmentController::class, 'recommendations'])
                ->middleware('permission:stocktake.job.assign|assignment.view');
            Route::get('/stocktake-jobs/{job}/assignments', [PlatformAssignmentController::class, 'index'])
                ->middleware('permission:stocktake.job.assign|assignment.view');
            Route::post('/stocktake-jobs/{job}/assignments', [PlatformAssignmentController::class, 'store'])
                ->middleware('permission:stocktake.job.assign');
            Route::post('/assignments/{assignment}/reassign', [PlatformAssignmentController::class, 'reassign'])
                ->middleware('permission:stocktake.job.assign');
            Route::post('/assignments/{assignment}/withdraw', [PlatformAssignmentController::class, 'withdraw'])
                ->middleware('permission:stocktake.job.assign');

            Route::get('/stock-takers', [PlatformWorkforceController::class, 'index'])
                ->middleware('permission:workforce.view');
            Route::get('/stock-takers/{profile}', [PlatformWorkforceController::class, 'show'])
                ->middleware('permission:workforce.view');
            Route::patch('/stock-takers/{profile}', [PlatformWorkforceController::class, 'update'])
                ->middleware('permission:workforce.manage');
            Route::post('/stock-takers/{profile}/verification', [PlatformWorkforceController::class, 'verify'])
                ->middleware('permission:workforce.verify');
            Route::put('/stock-takers/{profile}/skills', [PlatformWorkforceController::class, 'skills'])
                ->middleware('permission:workforce.manage');
            Route::put('/stock-takers/{profile}/service-areas', [PlatformWorkforceController::class, 'serviceAreas'])
                ->middleware('permission:workforce.manage');
            Route::put('/stock-takers/{profile}/availability', [PlatformWorkforceController::class, 'availability'])
                ->middleware('permission:workforce.manage');
            Route::post('/stock-takers/{profile}/training', [PlatformWorkforceController::class, 'training'])
                ->middleware('permission:workforce.manage');

            Route::get('/procedure-templates', [PlatformProcedureTemplateController::class, 'index'])
                ->middleware('permission:sop.manage|stocktake.job.assign|stocktake.job.review|stocktake.job.monitor');
            Route::post('/procedure-templates', [PlatformProcedureTemplateController::class, 'store'])
                ->middleware('permission:sop.manage');
            Route::get('/procedure-templates/{template}', [PlatformProcedureTemplateController::class, 'show'])
                ->middleware('permission:sop.manage|stocktake.job.assign|stocktake.job.review|stocktake.job.monitor');
            Route::patch('/procedure-templates/{template}', [PlatformProcedureTemplateController::class, 'update'])
                ->middleware('permission:sop.manage');
            Route::post('/procedure-templates/{template}/versions', [PlatformProcedureTemplateController::class, 'publishVersion'])
                ->middleware('permission:sop.manage');

            Route::get('/operations', [PlatformJobOperationsController::class, 'dashboard'])
                ->middleware('permission:stocktake.job.monitor|stocktake.job.assign|stocktake.job.review|sop.manage');
            Route::get('/stocktake-jobs/{job}/operations', [PlatformJobOperationsController::class, 'show'])
                ->middleware('permission:stocktake.job.monitor|stocktake.job.assign|stocktake.job.review|sop.manage');
            Route::get('/stocktake-jobs/{job}/procedure', [PlatformJobOperationsController::class, 'procedure'])
                ->middleware('permission:stocktake.job.monitor|stocktake.job.assign|stocktake.job.review|sop.manage');
            Route::post('/stocktake-jobs/{job}/procedure', [PlatformJobOperationsController::class, 'applyProcedure'])
                ->middleware('permission:sop.manage|stocktake.job.assign');

            Route::get('/stocktake-jobs/{job}/counts', [PlatformCountController::class, 'show'])
                ->middleware('permission:stocktake.count.view|stocktake.job.monitor|qa.review');

            // Phase 12 — QA, verification and recount
            Route::post('/stocktake-jobs/{job}/qa/review', [PlatformQAController::class, 'startReview'])
                ->middleware('permission:qa.review');
            Route::get('/stocktake-jobs/{job}/qa/review', [PlatformQAController::class, 'getReview'])
                ->middleware('permission:qa.review');
            Route::post('/stocktake-jobs/{job}/qa/findings', [PlatformQAController::class, 'recordFinding'])
                ->middleware('permission:qa.review');
            Route::post('/stocktake-jobs/{job}/qa/complete', [PlatformQAController::class, 'completeReview'])
                ->middleware('permission:qa.review');
            Route::post('/stocktake-jobs/{job}/verification-tasks', [PlatformQAController::class, 'createVerificationTask'])
                ->middleware('permission:verification.manage');
            Route::put('/stocktake-jobs/{job}/verification-tasks/{task}/samples/{sample}', [PlatformQAController::class, 'recordVerificationSample'])
                ->middleware('permission:verification.manage');
            Route::post('/stocktake-jobs/{job}/verification-tasks/{task}/complete', [PlatformQAController::class, 'completeVerificationTask'])
                ->middleware('permission:verification.manage');
            Route::post('/stocktake-jobs/{job}/recount-requests', [PlatformQAController::class, 'requestRecount'])
                ->middleware('permission:stocktake.count.recount.request|qa.review');
            Route::post('/stocktake-jobs/{job}/recount-requests/{recount}/approve', [PlatformQAController::class, 'approveRecount'])
                ->middleware('permission:qa.review');
            Route::post('/stocktake-jobs/{job}/recount-requests/{recount}/complete', [PlatformQAController::class, 'completeRecount'])
                ->middleware('permission:qa.review');
            Route::post('/discrepancies/{discrepancy}/resolve', [PlatformQAController::class, 'resolveDiscrepancy'])
                ->middleware('permission:qa.review');

            // Phase 13 — dispute resolution
            Route::get('/stocktake-jobs/{job}/disputes/{dispute}', [PlatformDisputeController::class, 'show'])
                ->middleware('permission:stocktake.dispute.resolve|stocktake.job.monitor');
            Route::post('/stocktake-jobs/{job}/disputes/{dispute}/messages', [PlatformDisputeController::class, 'addMessage'])
                ->middleware('permission:stocktake.dispute.resolve');
            Route::post('/stocktake-jobs/{job}/disputes/{dispute}/evidence', [PlatformDisputeController::class, 'addEvidence'])
                ->middleware('permission:stocktake.dispute.resolve');
            Route::post('/stocktake-jobs/{job}/disputes/{dispute}/decide', [PlatformDisputeController::class, 'decide'])
                ->middleware('permission:stocktake.dispute.resolve');

            // Phase 14 — payout queue and completion
            Route::get('/payouts', [PlatformPayoutController::class, 'index'])
                ->middleware('permission:finance.payout.prepare|finance.payout.approve');
            Route::get('/payouts/{batch}', [PlatformPayoutController::class, 'show'])
                ->middleware('permission:finance.payout.prepare|finance.payout.approve');
            Route::post('/stocktake-jobs/{job}/payouts', [PlatformPayoutController::class, 'prepare'])
                ->middleware('permission:finance.payout.prepare');
            Route::post('/payouts/{batch}/approve', [PlatformPayoutController::class, 'approve'])
                ->middleware('permission:finance.payout.approve');
            Route::post('/payouts/{batch}/process', [PlatformPayoutController::class, 'process'])
                ->middleware('permission:finance.payout.approve');
            Route::post('/stocktake-jobs/{job}/close', [PlatformPayoutController::class, 'closeJob'])
                ->middleware('permission:finance.payout.approve');

            Route::prefix('finance')->group(function (): void {
                Route::get('/overview', [FinanceController::class, 'overview'])
                    ->middleware('permission:finance.ledger.view|finance.payment.record');
                Route::get('/options', [FinanceController::class, 'options'])
                    ->middleware('permission:finance.payment.record');

                Route::get('/payments', [FinanceController::class, 'payments'])
                    ->middleware('permission:finance.payment.record|finance.ledger.view');
                Route::post('/payments/{payment}/verify', [FinanceController::class, 'verifyPayment'])
                    ->middleware('permission:finance.payment.record');

                Route::get('/ledger', [FinanceController::class, 'ledger'])
                    ->middleware('permission:finance.ledger.view');

                Route::get('/reconciliations', [FinanceController::class, 'reconciliations'])
                    ->middleware('permission:finance.ledger.view');
                Route::post('/reconciliations', [FinanceController::class, 'storeReconciliation'])
                    ->middleware('permission:finance.payment.record');
                Route::get('/reconciliations/{reconciliation}', [FinanceController::class, 'showReconciliation'])
                    ->middleware('permission:finance.ledger.view');

                Route::get('/refunds', [FinanceController::class, 'refunds'])
                    ->middleware('permission:finance.refund.approve|finance.payment.record');
                Route::post('/refunds', [FinanceController::class, 'storeRefund'])
                    ->middleware('permission:finance.payment.record');
                Route::post('/refunds/{refund}/decision', [FinanceController::class, 'decideRefund'])
                    ->middleware('permission:finance.refund.approve');
            });

            // Phase 19 — platform reporting, analytics, support queue
            Route::get('/reports', [PlatformReportController::class, 'index'])
                ->middleware('permission:report.view');
            Route::get('/reports/{type}/export', [PlatformReportController::class, 'export'])
                ->middleware('permission:report.export');
            Route::get('/reports/{type}', [PlatformReportController::class, 'show'])
                ->middleware('permission:report.view');
            Route::get('/analytics/overview', [PlatformReportController::class, 'analytics'])
                ->middleware('permission:report.view');

            Route::get('/support-tickets', [PlatformSupportController::class, 'index'])
                ->middleware('permission:support.view');
            Route::get('/support-tickets/{ticket}', [PlatformSupportController::class, 'show'])
                ->middleware('permission:support.view');
            Route::post('/support-tickets/{ticket}/messages', [PlatformSupportController::class, 'reply'])
                ->middleware('permission:support.manage');
            Route::patch('/support-tickets/{ticket}', [PlatformSupportController::class, 'update'])
                ->middleware('permission:support.manage');

            // Phase 4 — marketing CMS, navigation and content libraries
            Route::prefix('marketing')->group(function (): void {
                Route::get('/overview', [MarketingOverviewController::class, 'show'])
                    ->middleware('permission:cms.view');

                Route::get('/pages', [MarketingPageController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/pages', [MarketingPageController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::get('/pages/{page}', [MarketingPageController::class, 'show'])
                    ->middleware('permission:cms.view');
                Route::patch('/pages/{page}', [MarketingPageController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/pages/{page}', [MarketingPageController::class, 'destroy'])
                    ->middleware('permission:cms.edit');
                Route::put('/pages/{page}/sections', [MarketingPageController::class, 'sections'])
                    ->middleware('permission:cms.edit');
                Route::post('/pages/{page}/submit-review', [MarketingPageController::class, 'submitReview'])
                    ->middleware('permission:cms.edit');
                Route::post('/pages/{page}/approve', [MarketingPageController::class, 'approve'])
                    ->middleware('permission:cms.review');
                Route::post('/pages/{page}/reject', [MarketingPageController::class, 'reject'])
                    ->middleware('permission:cms.review');
                Route::post('/pages/{page}/schedule', [MarketingPageController::class, 'schedule'])
                    ->middleware('permission:cms.publish');
                Route::post('/pages/{page}/publish', [MarketingPageController::class, 'publish'])
                    ->middleware('permission:cms.publish');
                Route::post('/pages/{page}/unpublish', [MarketingPageController::class, 'unpublish'])
                    ->middleware('permission:cms.publish');
                Route::get('/pages/{page}/versions', [MarketingPageController::class, 'versions'])
                    ->middleware('permission:cms.view');
                Route::post('/pages/{page}/versions/{version}/restore', [MarketingPageController::class, 'restoreVersion'])
                    ->middleware('permission:cms.edit');
                Route::get('/pages/{page}/preview', [MarketingPageController::class, 'preview'])
                    ->middleware('permission:cms.view');

                Route::get('/menus', [MarketingMenuController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/menus', [MarketingMenuController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::patch('/menus/{menu:key}', [MarketingMenuController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/menus/{menu:key}', [MarketingMenuController::class, 'destroy'])
                    ->middleware('permission:cms.edit');
                Route::post('/menus/{menu:key}/items', [MarketingMenuController::class, 'storeItem'])
                    ->middleware('permission:cms.edit');
                Route::patch('/menus/{menu:key}/items/{item}', [MarketingMenuController::class, 'updateItem'])
                    ->middleware('permission:cms.edit');
                Route::delete('/menus/{menu:key}/items/{item}', [MarketingMenuController::class, 'destroyItem'])
                    ->middleware('permission:cms.edit');

                Route::get('/banners', [MarketingBannerController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/banners', [MarketingBannerController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::patch('/banners/{banner}', [MarketingBannerController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/banners/{banner}', [MarketingBannerController::class, 'destroy'])
                    ->middleware('permission:cms.edit');

                Route::get('/ctas', [MarketingCtaController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/ctas', [MarketingCtaController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::patch('/ctas/{cta}', [MarketingCtaController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/ctas/{cta}', [MarketingCtaController::class, 'destroy'])
                    ->middleware('permission:cms.edit');

                Route::get('/faqs', [MarketingFaqController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/faqs', [MarketingFaqController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::patch('/faqs/{faq}', [MarketingFaqController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/faqs/{faq}', [MarketingFaqController::class, 'destroy'])
                    ->middleware('permission:cms.edit');

                Route::get('/testimonials', [MarketingTestimonialController::class, 'index'])
                    ->middleware('permission:cms.view');
                Route::post('/testimonials', [MarketingTestimonialController::class, 'store'])
                    ->middleware('permission:cms.edit');
                Route::patch('/testimonials/{testimonial}', [MarketingTestimonialController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::delete('/testimonials/{testimonial}', [MarketingTestimonialController::class, 'destroy'])
                    ->middleware('permission:cms.edit');

                Route::get('/media/folders', [MediaFolderController::class, 'index'])
                    ->middleware('permission:media.manage');
                Route::post('/media/folders', [MediaFolderController::class, 'store'])
                    ->middleware('permission:media.manage');
                Route::patch('/media/folders/{folder}', [MediaFolderController::class, 'update'])
                    ->middleware('permission:media.manage');
                Route::delete('/media/folders/{folder}', [MediaFolderController::class, 'destroy'])
                    ->middleware('permission:media.manage');

                Route::get('/media/assets', [MediaAssetController::class, 'index'])
                    ->middleware('permission:media.manage');
                Route::post('/media/assets', [MediaAssetController::class, 'store'])
                    ->middleware('permission:media.manage');
                Route::get('/media/assets/{asset}', [MediaAssetController::class, 'show'])
                    ->middleware('permission:media.manage');
                Route::patch('/media/assets/{asset}', [MediaAssetController::class, 'update'])
                    ->middleware('permission:media.manage');
                Route::delete('/media/assets/{asset}', [MediaAssetController::class, 'destroy'])
                    ->middleware('permission:media.manage');
                Route::post('/media/assets/{asset}/replace', [MediaAssetController::class, 'replace'])
                    ->middleware('permission:media.manage');
                Route::get('/media/assets/{asset}/usages', [MediaAssetController::class, 'usages'])
                    ->middleware('permission:media.manage');
                Route::get('/media/assets/{asset}/versions', [MediaAssetController::class, 'versions'])
                    ->middleware('permission:media.manage');

                // Phase 5 — lead pipeline, consent ledger and restricted export
                Route::get('/leads/export', [LeadController::class, 'export'])
                    ->middleware(['permission:leads.export', 'throttle:marketing-export']);
                Route::get('/leads/assignees', [LeadController::class, 'assignees'])
                    ->middleware('permission:leads.manage');
                Route::get('/leads', [LeadController::class, 'index'])
                    ->middleware('permission:leads.view');
                Route::get('/leads/{lead}', [LeadController::class, 'show'])
                    ->middleware('permission:leads.view');
                Route::patch('/leads/{lead}', [LeadController::class, 'update'])
                    ->middleware('permission:leads.manage');
                Route::post('/leads/{lead}/status', [LeadController::class, 'status'])
                    ->middleware('permission:leads.manage');
                Route::post('/leads/{lead}/assign', [LeadController::class, 'assign'])
                    ->middleware('permission:leads.manage');
                Route::get('/leads/{lead}/activities', [LeadController::class, 'activities'])
                    ->middleware('permission:leads.view');
                Route::post('/leads/{lead}/activities', [LeadController::class, 'addActivity'])
                    ->middleware('permission:leads.manage');
                Route::get('/leads/{lead}/consents', [LeadController::class, 'consents'])
                    ->middleware('permission:leads.view');
                Route::post('/leads/{lead}/consents', [LeadController::class, 'recordConsent'])
                    ->middleware('permission:leads.manage');

                // Phase 6 — technical SEO diagnostics, redirects and core web vitals
                Route::get('/seo/diagnostics', [SeoController::class, 'diagnostics'])
                    ->middleware('permission:cms.view');
                Route::get('/seo/vitals', [SeoController::class, 'vitals'])
                    ->middleware('permission:cms.view');
                Route::get('/seo/redirects', [SeoController::class, 'redirects'])
                    ->middleware('permission:cms.view');
                Route::post('/seo/redirects', [SeoController::class, 'storeRedirect'])
                    ->middleware('permission:cms.publish');
                Route::patch('/seo/redirects/{redirect}', [SeoController::class, 'updateRedirect'])
                    ->middleware('permission:cms.publish');
                Route::delete('/seo/redirects/{redirect}', [SeoController::class, 'destroyRedirect'])
                    ->middleware('permission:cms.publish');

                // Phase 7 — per-page JSON-LD structured data
                Route::get('/pages/{page}/structured-data', [StructuredDataController::class, 'show'])
                    ->middleware('permission:cms.view');
                Route::put('/pages/{page}/structured-data', [StructuredDataController::class, 'update'])
                    ->middleware('permission:cms.edit');
                Route::post('/pages/{page}/structured-data/validate', [StructuredDataController::class, 'validate'])
                    ->middleware('permission:cms.edit');
                Route::get('/pages/{page}/structured-data/runs', [StructuredDataController::class, 'runs'])
                    ->middleware('permission:cms.view');

                // Phase 8 — blog, resources and editorial planning
                Route::get('/posts', [BlogPostController::class, 'index'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts', [BlogPostController::class, 'store'])
                    ->middleware('permission:blog.manage');
                Route::get('/posts/{post}', [BlogPostController::class, 'show'])
                    ->middleware('permission:blog.manage');
                Route::patch('/posts/{post}', [BlogPostController::class, 'update'])
                    ->middleware('permission:blog.manage');
                Route::delete('/posts/{post}', [BlogPostController::class, 'destroy'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/submit-review', [BlogPostController::class, 'submitReview'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/approve', [BlogPostController::class, 'approve'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/reject', [BlogPostController::class, 'reject'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/schedule', [BlogPostController::class, 'schedule'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/publish', [BlogPostController::class, 'publish'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/unpublish', [BlogPostController::class, 'unpublish'])
                    ->middleware('permission:blog.manage');
                Route::post('/posts/{post}/archive', [BlogPostController::class, 'archive'])
                    ->middleware('permission:blog.manage');

                Route::get('/post-categories', [BlogTaxonomyController::class, 'categories'])
                    ->middleware('permission:blog.manage');
                Route::post('/post-categories', [BlogTaxonomyController::class, 'storeCategory'])
                    ->middleware('permission:blog.manage');
                Route::patch('/post-categories/{category}', [BlogTaxonomyController::class, 'updateCategory'])
                    ->middleware('permission:blog.manage');
                Route::get('/post-tags', [BlogTaxonomyController::class, 'tags'])
                    ->middleware('permission:blog.manage');
                Route::post('/post-tags', [BlogTaxonomyController::class, 'storeTag'])
                    ->middleware('permission:blog.manage');
                Route::patch('/post-tags/{tag}', [BlogTaxonomyController::class, 'updateTag'])
                    ->middleware('permission:blog.manage');

                Route::get('/resource-downloads', [ResourceDownloadController::class, 'index'])
                    ->middleware('permission:blog.manage');
                Route::post('/resource-downloads', [ResourceDownloadController::class, 'store'])
                    ->middleware('permission:blog.manage');
                Route::patch('/resource-downloads/{resourceDownload}', [ResourceDownloadController::class, 'update'])
                    ->middleware('permission:blog.manage');

                Route::get('/editorial-items', [EditorialItemController::class, 'index'])
                    ->middleware('permission:blog.manage');
                Route::post('/editorial-items', [EditorialItemController::class, 'store'])
                    ->middleware('permission:blog.manage');
                Route::patch('/editorial-items/{editorialItem}', [EditorialItemController::class, 'update'])
                    ->middleware('permission:blog.manage');

                // Phase 9 — Google integrations
                Route::get('/integrations/google', [GoogleIntegrationController::class, 'status'])
                    ->middleware('permission:integrations.google.manage');
                Route::get('/integrations/google/connect', [GoogleIntegrationController::class, 'connect'])
                    ->middleware('permission:integrations.google.manage');
                Route::delete('/integrations/google', [GoogleIntegrationController::class, 'disconnect'])
                    ->middleware('permission:integrations.google.manage');
                Route::post('/integrations/google/sync', [GoogleIntegrationController::class, 'sync'])
                    ->middleware('permission:integrations.google.manage');

                // Phase 10 — social publishing
                Route::get('/social-posts', [SocialPostController::class, 'index'])
                    ->middleware('permission:social.publish');
                Route::post('/social-posts', [SocialPostController::class, 'store'])
                    ->middleware('permission:social.publish');
                Route::get('/social-posts/{post}', [SocialPostController::class, 'show'])
                    ->middleware('permission:social.publish');
                Route::patch('/social-posts/{post}', [SocialPostController::class, 'update'])
                    ->middleware('permission:social.publish');
                Route::delete('/social-posts/{post}', [SocialPostController::class, 'destroy'])
                    ->middleware('permission:social.publish');
                Route::post('/social-posts/{post}/submit-review', [SocialPostController::class, 'submitReview'])
                    ->middleware('permission:social.publish');
                Route::post('/social-posts/{post}/approve', [SocialPostController::class, 'approve'])
                    ->middleware('permission:social.approve');
                Route::post('/social-posts/{post}/reject', [SocialPostController::class, 'reject'])
                    ->middleware('permission:social.approve');
                Route::post('/social-posts/{post}/schedule', [SocialPostController::class, 'schedule'])
                    ->middleware('permission:social.publish');
                Route::post('/social-posts/{post}/publish', [SocialPostController::class, 'publish'])
                    ->middleware('permission:social.publish');

                // Phase 10 — Meta integrations
                Route::get('/integrations/meta', [MetaIntegrationController::class, 'status'])
                    ->middleware('permission:integrations.meta.manage');
                Route::get('/integrations/meta/connect', [MetaIntegrationController::class, 'connect'])
                    ->middleware('permission:integrations.meta.manage');
                Route::delete('/integrations/meta', [MetaIntegrationController::class, 'disconnect'])
                    ->middleware('permission:integrations.meta.manage');
                Route::post('/integrations/meta/sync', [MetaIntegrationController::class, 'sync'])
                    ->middleware('permission:integrations.meta.manage');

                // Phase 11 — campaigns
                Route::get('/campaigns', [CampaignController::class, 'index'])
                    ->middleware('permission:campaigns.view');
                Route::post('/campaigns', [CampaignController::class, 'store'])
                    ->middleware('permission:campaigns.manage');
                Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])
                    ->middleware('permission:campaigns.view');
                Route::patch('/campaigns/{campaign}', [CampaignController::class, 'update'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/submit-review', [CampaignController::class, 'submitReview'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/approve', [CampaignController::class, 'approve'])
                    ->middleware('permission:campaigns.approve');
                Route::post('/campaigns/{campaign}/reject', [CampaignController::class, 'reject'])
                    ->middleware('permission:campaigns.approve');
                Route::post('/campaigns/{campaign}/activate', [CampaignController::class, 'activate'])
                    ->middleware(['permission:campaigns.manage', 'permission:campaigns.spend']);
                Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'pause'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'resume'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/complete', [CampaignController::class, 'complete'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/assets', [CampaignController::class, 'storeAsset'])
                    ->middleware('permission:campaigns.manage');
                Route::delete('/campaigns/{campaign}/assets/{asset}', [CampaignController::class, 'destroyAsset'])
                    ->middleware('permission:campaigns.manage');
                Route::post('/campaigns/{campaign}/results', [CampaignController::class, 'recordResults'])
                    ->middleware('permission:campaigns.manage');
                Route::get('/campaigns/{campaign}/performance', [CampaignController::class, 'performance'])
                    ->middleware(['permission:campaigns.view', 'permission:analytics.view']);

                // Phase 12 — consent-aware analytics and reporting
                Route::get('/analytics/overview', [MarketingAnalyticsController::class, 'overview'])
                    ->middleware('permission:analytics.view');
                Route::get('/analytics/funnel', [MarketingAnalyticsController::class, 'funnel'])
                    ->middleware('permission:analytics.view');
                Route::get('/analytics/campaigns', [MarketingAnalyticsController::class, 'campaigns'])
                    ->middleware('permission:analytics.view');
                Route::get('/analytics/landing-pages', [MarketingAnalyticsController::class, 'landingPages'])
                    ->middleware('permission:analytics.view');
                Route::get('/analytics/search', [MarketingAnalyticsController::class, 'search'])
                    ->middleware('permission:analytics.view');
                Route::get('/analytics/events', [MarketingAnalyticsController::class, 'events'])
                    ->middleware('permission:analytics.view');

                // Phase 13 — marketing automation
                Route::get('/workflows', [AutomationController::class, 'index'])
                    ->middleware('permission:automation.view');
                Route::post('/workflows', [AutomationController::class, 'store'])
                    ->middleware('permission:automation.manage');
                Route::get('/workflows/{workflow}', [AutomationController::class, 'show'])
                    ->middleware('permission:automation.view');
                Route::patch('/workflows/{workflow}', [AutomationController::class, 'update'])
                    ->middleware('permission:automation.manage');
                Route::post('/workflows/{workflow}/activate', [AutomationController::class, 'activate'])
                    ->middleware('permission:automation.manage');
                Route::post('/workflows/{workflow}/pause', [AutomationController::class, 'pause'])
                    ->middleware('permission:automation.manage');
                Route::get('/workflows/{workflow}/executions', [AutomationController::class, 'executions'])
                    ->middleware('permission:automation.view');
                Route::post('/automation/kill-switch', [AutomationController::class, 'killSwitch'])
                    ->middleware('permission:automation.manage');
                Route::post('/automation/run-due', [AutomationController::class, 'runDue'])
                    ->middleware('permission:automation.manage');
            });

        });
    });
});
