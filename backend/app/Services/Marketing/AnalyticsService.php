<?php

namespace App\Services\Marketing;

use App\Models\Marketing\AnalyticsEvent;
use App\Models\Marketing\ConversionEvent;
use App\Models\Marketing\GoogleSearchMetric;
use App\Models\Marketing\MarketingCampaign;
use App\Models\Marketing\MarketingLead;
use App\Models\Marketing\TrackingPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsService
{
    public const EVENT_NAMES = ['page_view', 'cta_click', 'phone_click', 'whatsapp_click', 'form_start', 'download_start'];

    public const CONVERSIONS = [
        'quote' => 'quote_request',
        'pos_demo' => 'pos_demo_request',
        'contact' => 'contact_submission',
        'callback' => 'callback_request',
        'partnership' => 'partnership_request',
    ];

    public const POLICY_VERSION = '2026-11';

    private const PII_KEY_PATTERN = '/email|phone|name|address|contact|message|subject/i';

    public function recordConsent(string $sessionId, bool $analytics, bool $marketing, ?string $policyVersion): TrackingPreference
    {
        return TrackingPreference::updateOrCreate(
            ['session_id' => $sessionId],
            [
                'necessary' => true,
                'analytics' => $analytics,
                'marketing' => $marketing,
                'policy_version' => $policyVersion ?? self::POLICY_VERSION,
            ],
        );
    }

    public function hasAnalyticsConsent(string $sessionId): bool
    {
        return TrackingPreference::query()
            ->where('session_id', $sessionId)
            ->where('analytics', true)
            ->exists();
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return int stored events
     */
    public function ingest(string $sessionId, array $events, Request $request): int
    {
        $campaign = $this->resolveCampaign($events[0]['utm']['utm_campaign'] ?? null);
        $now = now();

        $rows = [];
        foreach ($events as $event) {
            $occurredAt = isset($event['occurred_at']) ? Carbon::parse($event['occurred_at']) : $now;
            if ($occurredAt->greaterThan($now->copy()->addMinutes(5))) {
                $occurredAt = $now;
            }
            if ($occurredAt->lessThan($now->copy()->subDay())) {
                $occurredAt = $now;
            }

            $path = $event['path'] ?? (isset($event['url']) ? parse_url((string) $event['url'], PHP_URL_PATH) : null);

            $rows[] = [
                'event_name' => $event['name'],
                'occurred_at' => $occurredAt,
                'session_id' => $sessionId,
                'campaign_id' => $campaign?->id,
                'path' => $path !== null ? Str::limit((string) $path, 500, '') : null,
                'referrer' => isset($event['referrer']) ? Str::limit((string) $event['referrer'], 2000, '') : null,
                'utm' => $this->encodeJson($this->sanitizeUtm($event['utm'] ?? null, $campaign)),
                'device' => $event['device'] ?? null,
                'consent_state' => $this->encodeJson(['analytics' => true, 'policy_version' => self::POLICY_VERSION, 'basis' => 'banner']),
                'payload' => $this->encodeJson(isset($event['payload']) ? $this->sanitizePayload($event['payload']) : null),
                'ip_hash' => $this->ipHash($request->ip()),
                'created_at' => $now,
            ];
        }

        AnalyticsEvent::query()->insert($rows);

        return count($rows);
    }

    public function recordConversion(
        string $eventName,
        ?MarketingLead $lead,
        ?string $utmCampaign,
        array $metadata = [],
        array $consentState = ['analytics' => true, 'basis' => 'form_submission'],
    ): ConversionEvent {
        $campaign = $this->resolveCampaign($utmCampaign);

        return ConversionEvent::create([
            'event_name' => $eventName,
            'lead_id' => $lead?->id,
            'campaign_id' => $campaign?->id,
            'occurred_at' => now(),
            'consent_state' => $consentState,
            'metadata' => $this->sanitizePayload($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $tracked = AnalyticsEvent::query()->where('occurred_at', '>=', $since);

        $sessions = (clone $tracked)->whereNotNull('session_id')->distinct()->count('session_id');
        $pageViews = (clone $tracked)->where('event_name', 'page_view')->count();
        $eventsTotal = (clone $tracked)->count();
        $conversions = ConversionEvent::query()->where('occurred_at', '>=', $since)->count();
        $leadConversions = ConversionEvent::query()->where('occurred_at', '>=', $since)->whereNotNull('lead_id')->count();

        $prefs = TrackingPreference::query();
        $decisions = (clone $prefs)->count();
        $consented = (clone $prefs)->where('analytics', true)->count();

        $byDay = $this->dailySeries($since, $days);

        $topEvents = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('event_name, count(*) as total')
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['event' => $row->event_name, 'count' => (int) $row->total])
            ->all();

        return [
            'period_days' => $days,
            'sessions' => $sessions,
            'page_views' => $pageViews,
            'events' => $eventsTotal,
            'conversions' => $conversions,
            'lead_conversions' => $leadConversions,
            'conversion_rate' => $sessions > 0 ? round($conversions / $sessions * 100, 2) : null,
            'consent' => [
                'decisions' => $decisions,
                'analytics_granted' => $consented,
                'necessary_only' => max(0, $decisions - $consented),
                'grant_rate' => $decisions > 0 ? round($consented / $decisions * 100, 1) : null,
                'policy_version' => self::POLICY_VERSION,
                'unconsented_excluded' => true,
            ],
            'daily' => $byDay,
            'top_events' => $topEvents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function funnel(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $counts = MarketingLead::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $stages = [];
        foreach (MarketingLead::STATUSES as $status) {
            $stages[] = ['status' => $status, 'count' => (int) ($counts[$status] ?? 0)];
        }

        $conversions = ConversionEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('event_name, count(*) as total')
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['event' => $row->event_name, 'count' => (int) $row->total])
            ->all();

        $leadTotal = (int) $counts->sum();
        $convertedLeads = ConversionEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('lead_id')
            ->distinct()
            ->count('lead_id');

        return [
            'period_days' => $days,
            'stages' => $stages,
            'leads' => $leadTotal,
            'conversion_events' => $conversions,
            'converted_leads' => $convertedLeads,
            'reconciled' => $convertedLeads <= $leadTotal,
            'reconciliation_note' => 'Server-side conversions are recorded from form submissions; unconsented browser sessions are never ingested.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function campaigns(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $campaigns = MarketingCampaign::query()->orderByDesc('id')->limit(50)->get();
        if ($campaigns->isEmpty()) {
            return [];
        }

        $ids = $campaigns->pluck('id')->all();

        $eventCounts = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, count(*) as total')
            ->groupBy('campaign_id')
            ->pluck('total', 'campaign_id');

        $sessions = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('campaign_id', $ids)
            ->whereNotNull('session_id')
            ->selectRaw('campaign_id, count(distinct session_id) as total')
            ->groupBy('campaign_id')
            ->pluck('total', 'campaign_id');

        $conversions = ConversionEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereIn('campaign_id', $ids)
            ->selectRaw('campaign_id, count(*) as total')
            ->groupBy('campaign_id')
            ->pluck('total', 'campaign_id');

        return $campaigns->map(function (MarketingCampaign $campaign) use ($eventCounts, $sessions, $conversions): array {
            $results = $campaign->results ?? [];
            $recordedLeads = (int) ($results['leads'] ?? 0);
            $spend = isset($results['spend']) ? (float) $results['spend'] : null;
            $analyticsConversions = (int) ($conversions[$campaign->id] ?? 0);
            $leads = $recordedLeads > 0 ? $recordedLeads : $analyticsConversions;

            return [
                'id' => $campaign->uuid,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'channel' => $campaign->channel,
                'budget_amount' => (float) $campaign->budget_amount,
                'budget_currency' => $campaign->budget_currency,
                'utm_campaign' => $campaign->utm['utm_campaign'] ?? Str::slug($campaign->name),
                'analytics_events' => (int) ($eventCounts[$campaign->id] ?? 0),
                'analytics_sessions' => (int) ($sessions[$campaign->id] ?? 0),
                'analytics_conversions' => $analyticsConversions,
                'recorded_results' => [
                    'reach' => (int) ($results['reach'] ?? 0),
                    'impressions' => (int) ($results['impressions'] ?? 0),
                    'clicks' => (int) ($results['clicks'] ?? 0),
                    'leads' => $recordedLeads,
                    'conversions' => (int) ($results['conversions'] ?? 0),
                    'spend' => $spend,
                ],
                'cost_per_lead' => $spend !== null && $leads > 0 ? round($spend / $leads, 2) : null,
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function landingPages(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $views = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('event_name', 'page_view')
            ->whereNotNull('path')
            ->selectRaw('path, count(*) as views, count(distinct session_id) as sessions')
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(25)
            ->get();

        $conversionCounts = [];
        ConversionEvent::query()
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('metadata')
            ->get(['metadata'])
            ->each(function (ConversionEvent $conversion) use (&$conversionCounts): void {
                $path = $conversion->metadata['path'] ?? null;
                if (is_string($path) && $path !== '') {
                    $conversionCounts[$path] = ($conversionCounts[$path] ?? 0) + 1;
                }
            });

        return $views->map(fn ($row) => [
            'path' => $row->path,
            'views' => (int) $row->views,
            'sessions' => (int) $row->sessions,
            'conversions' => $conversionCounts[$row->path] ?? 0,
        ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function search(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();
        $base = GoogleSearchMetric::query()->where('date', '>=', $since);

        $totals = (clone $base)->selectRaw('sum(clicks) as clicks, sum(impressions) as impressions, avg(ctr) as ctr, avg(position) as position')->first();

        if ($totals === null || (int) ($totals->impressions ?? 0) === 0) {
            return [
                'available' => false,
                'reason' => 'NO_SEARCH_DATA',
                'message' => 'No Search Console data has been imported for this period. Connect Google Search Console and run a search sync.',
                'clicks' => 0,
                'impressions' => 0,
                'ctr' => null,
                'position' => null,
                'top_queries' => [],
                'top_pages' => [],
            ];
        }

        $topQueries = (clone $base)
            ->whereNotNull('query')
            ->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions')
            ->groupBy('query')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['query' => $row->query, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions])
            ->all();

        $topPages = (clone $base)
            ->whereNotNull('page')
            ->selectRaw('page, sum(clicks) as clicks, sum(impressions) as impressions')
            ->groupBy('page')
            ->orderByDesc('clicks')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['page' => $row->page, 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions])
            ->all();

        return [
            'available' => true,
            'clicks' => (int) $totals->clicks,
            'impressions' => (int) $totals->impressions,
            'ctr' => round((float) $totals->ctr * 100, 2),
            'position' => round((float) $totals->position, 1),
            'top_queries' => $topQueries,
            'top_pages' => $topPages,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recentEvents(int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $rows = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['event_name', 'occurred_at', 'path', 'device', 'utm'])
            ->map(fn (AnalyticsEvent $event) => [
                'event' => $event->event_name,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'path' => $event->path,
                'device' => $event->device,
                'utm_campaign' => $event->utm['utm_campaign'] ?? null,
            ])
            ->all();

        return ['events' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function campaignPerformance(MarketingCampaign $campaign, int $days): array
    {
        $since = now()->subDays($days)->startOfDay();

        $events = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('campaign_id', $campaign->id);

        $byDay = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->where('campaign_id', $campaign->id)
            ->selectRaw('date(occurred_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return [
            'campaign' => [
                'id' => $campaign->uuid,
                'name' => $campaign->name,
                'status' => $campaign->status,
                'channel' => $campaign->channel,
                'utm_campaign' => $campaign->utm['utm_campaign'] ?? Str::slug($campaign->name),
            ],
            'period_days' => $days,
            'events' => (clone $events)->count(),
            'sessions' => (clone $events)->whereNotNull('session_id')->distinct()->count('session_id'),
            'page_views' => (clone $events)->where('event_name', 'page_view')->count(),
            'conversions' => ConversionEvent::query()->where('occurred_at', '>=', $since)->where('campaign_id', $campaign->id)->count(),
            'recorded_results' => $campaign->results ?? [],
            'daily' => $this->fillSeries($byDay->all(), $since, $days),
            'recent_conversions' => ConversionEvent::query()
                ->where('occurred_at', '>=', $since)
                ->where('campaign_id', $campaign->id)
                ->orderByDesc('occurred_at')
                ->limit(25)
                ->get(['event_name', 'occurred_at', 'lead_id'])
                ->map(fn (ConversionEvent $conversion) => [
                    'event' => $conversion->event_name,
                    'occurred_at' => $conversion->occurred_at?->toIso8601String(),
                    'lead' => $conversion->lead_id !== null,
                ])
                ->all(),
        ];
    }

    public function resolveCampaign(?string $utmCampaign): ?MarketingCampaign
    {
        if ($utmCampaign === null || trim($utmCampaign) === '') {
            return null;
        }

        $needle = Str::lower(trim($utmCampaign));

        return MarketingCampaign::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get()
            ->first(function (MarketingCampaign $campaign) use ($needle): bool {
                $stored = Str::lower((string) ($campaign->utm['utm_campaign'] ?? ''));

                return $needle === $stored
                    || $needle === Str::lower($campaign->name)
                    || $needle === Str::slug($campaign->name);
            });
    }

    /**
     * @return array<int, array{date: string, events: int, conversions: int}>
     */
    private function dailySeries(Carbon $since, int $days): array
    {
        $window = min($days, 30);

        $events = AnalyticsEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('date(occurred_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $conversions = ConversionEvent::query()
            ->where('occurred_at', '>=', $since)
            ->selectRaw('date(occurred_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $out = [];
        for ($i = $window - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[] = [
                'date' => $day,
                'events' => (int) ($events[$day] ?? 0),
                'conversions' => (int) ($conversions[$day] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, int|string>  $counts
     * @return array<int, array{date: string, events: int}>
     */
    private function fillSeries(array $counts, Carbon $since, int $days): array
    {
        $window = min($days, 30);
        $out = [];
        for ($i = $window - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $out[] = ['date' => $day, 'events' => (int) ($counts[$day] ?? 0)];
        }

        return $out;
    }

    /**
     * @return array<string, string>|null
     */
    private function sanitizeUtm(mixed $utm, ?MarketingCampaign $campaign): ?array
    {
        if (! is_array($utm)) {
            $utm = [];
        }

        $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
        $clean = [];
        foreach ($allowed as $key) {
            if (isset($utm[$key]) && is_scalar($utm[$key])) {
                $clean[$key] = Str::limit((string) $utm[$key], 190, '');
            }
        }

        if ($campaign !== null && (! isset($clean['utm_campaign']))) {
            $clean['utm_campaign'] = $campaign->utm['utm_campaign'] ?? Str::slug($campaign->name);
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Recursively drop fields that could contain direct PII and cap string lengths.
     */
    private function sanitizePayload(mixed $payload, int $depth = 0): ?array
    {
        if (! is_array($payload) || $depth > 3) {
            return null;
        }

        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::PII_KEY_PATTERN, $key)) {
                continue;
            }
            if (is_array($value)) {
                $nested = $this->sanitizePayload($value, $depth + 1);
                if ($nested !== null && $nested !== []) {
                    $clean[$key] = $nested;
                }
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$key] = $value;
            } elseif (is_string($value)) {
                $clean[$key] = Str::limit($value, 500, '');
            }
        }

        return $clean;
    }

    private function ipHash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip.'|'.(string) config('app.key'));
    }

    private function encodeJson(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
