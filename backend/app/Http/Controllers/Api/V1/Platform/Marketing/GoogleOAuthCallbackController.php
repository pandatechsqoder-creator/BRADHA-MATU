<?php

namespace App\Http\Controllers\Api\V1\Platform\Marketing;

use App\Exceptions\MarketingIntegrationException;
use App\Http\Controllers\Controller;
use App\Services\Marketing\Google\GoogleIntegrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthCallbackController extends Controller
{
    public function __construct(private readonly GoogleIntegrationService $google) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $redirect = rtrim((string) config('marketing.app_url', config('app.url')), '/')
            .'/platform/marketing/integrations/google';

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect()->away($redirect.'?google=error&reason=denied');
        }

        try {
            $this->google->handleCallback((string) $request->query('code'), (string) $request->query('state'));
        } catch (MarketingIntegrationException $e) {
            return redirect()->away($redirect.'?google=error&reason='.rawurlencode(strtolower($e->errorCode)));
        }

        return redirect()->away($redirect.'?google=connected');
    }
}
