<?php

namespace App\Services\Marketing;

use App\Models\Marketing\CoreWebVital;
use Illuminate\Http\Request;

final class SeoVitalsService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, Request $request): CoreWebVital
    {
        $url = (string) $data['url'];
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $path = '/'.trim($path, '/');

        return CoreWebVital::create([
            'path' => $path === '/' ? '/' : $path,
            'url' => mb_substr($url, 0, 500),
            'device' => $data['device'] ?? 'unknown',
            'lcp' => $data['lcp'] ?? null,
            'cls' => $data['cls'] ?? null,
            'inp' => $data['inp'] ?? null,
            'ttfb' => $data['ttfb'] ?? null,
            'metric' => $data['metric'] ?? null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'ip' => $request->ip(),
            'measured_at' => $data['measured_at'] ?? now(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $days = 7, ?string $path = null): array
    {
        $since = now()->subDays($days);
        $query = CoreWebVital::query()->where('measured_at', '>=', $since);

        if ($path !== null && $path !== '') {
            $query->where('path', '/'.trim($path, '/'));
        }

        $samples = $query->orderByDesc('measured_at')->get();

        $byPath = $samples
            ->groupBy('path')
            ->map(fn ($group, $groupPath) => [
                'path' => $groupPath,
                'samples' => $group->count(),
                ...$this->metrics($group->all()),
            ])
            ->sortByDesc('samples')
            ->values()
            ->all();

        return [
            'window_days' => $days,
            'summary' => [
                'samples' => $samples->count(),
                'devices' => $samples->groupBy('device')->map->count()->all(),
                ...$this->metrics($samples->all()),
            ],
            'by_path' => $byPath,
            'recent' => $samples->take(20)->map(fn (CoreWebVital $vital) => [
                'path' => $vital->path,
                'device' => $vital->device,
                'lcp' => $vital->lcp,
                'cls' => $vital->cls,
                'inp' => $vital->inp,
                'ttfb' => $vital->ttfb,
                'measured_at' => $vital->measured_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<int, CoreWebVital>  $samples
     * @return array<string, float|null>
     */
    private function metrics(array $samples): array
    {
        $values = [
            'lcp_p75' => $this->percentile($samples, 'lcp', 75),
            'cls_p75' => $this->percentile($samples, 'cls', 75),
            'inp_p75' => $this->percentile($samples, 'inp', 75),
            'ttfb_p75' => $this->percentile($samples, 'ttfb', 75),
        ];

        $lcpGood = 0;
        $lcpTotal = 0;
        foreach ($samples as $sample) {
            if ($sample->lcp !== null) {
                $lcpTotal++;
                if ($sample->lcp <= 2500) {
                    $lcpGood++;
                }
            }
        }

        $values['lcp_good_pct'] = $lcpTotal > 0 ? round($lcpGood / $lcpTotal * 100, 1) : null;

        return $values;
    }

    /**
     * @param  array<int, CoreWebVital>  $samples
     */
    private function percentile(array $samples, string $metric, int $percentile): ?float
    {
        $values = array_values(array_filter(
            array_map(fn (CoreWebVital $vital) => $vital->{$metric}, $samples),
            fn ($value) => $value !== null,
        ));

        if ($values === []) {
            return null;
        }

        sort($values);
        $index = (int) ceil(count($values) * $percentile / 100) - 1;
        $index = max(0, min($index, count($values) - 1));

        return round((float) $values[$index], 2);
    }
}
