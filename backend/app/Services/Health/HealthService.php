<?php

namespace App\Services\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class HealthService
{
    /**
     * @return array{status: string, checks: array<string, string>, diagnostics: array<string, mixed>}
     */
    public function run(): array
    {
        $started = microtime(true);
        $diagnostics = [];
        $checks = [];

        $checks['database'] = $this->probe(function () use (&$diagnostics, $started): void {
            DB::select('SELECT 1');
            $diagnostics['database_latency_ms'] = round((microtime(true) - $started) * 1000, 2);
        });

        $checks['cache'] = $this->probe(function (): void {
            $key = 'health:'.uniqid('', true);
            Cache::put($key, '1', 5);
            if (Cache::get($key) !== '1') {
                throw new \RuntimeException('Cache read-back failed.');
            }
            Cache::forget($key);
        });

        $checks['queue'] = $this->probe(function () use (&$diagnostics): void {
            $diagnostics['queue_depth'] = Queue::size();
        });

        $checks['storage'] = $this->probe(function (): void {
            $disk = Storage::disk('local');
            $path = 'healthcheck/'.uniqid('', true).'.txt';
            $disk->put($path, 'ok');
            if ($disk->get($path) !== 'ok') {
                throw new \RuntimeException('Storage read-back failed.');
            }
            $disk->delete($path);
        });

        $checks['migrations'] = $this->probe(function () use (&$diagnostics): void {
            $files = count(glob(database_path('migrations/*.php')) ?: []);
            $ran = DB::table('migrations')->pluck('migration')->all();
            $pending = array_diff(
                array_map(fn (string $file) => basename($file, '.php'), glob(database_path('migrations/*.php')) ?: []),
                $ran,
            );
            $diagnostics['migrations_total'] = $files;
            $diagnostics['migrations_pending'] = count($pending);
            if ($pending !== []) {
                throw new \RuntimeException('Pending migrations: '.implode(', ', $pending));
            }
        });

        $checks['configuration'] = $this->probe(function () use (&$diagnostics): void {
            if (! app()->environment('production')) {
                $diagnostics['configuration'] = 'non-production';

                return;
            }

            $issues = [];
            if (config('app.debug')) {
                $issues[] = 'APP_DEBUG must be false in production.';
            }
            if (! str_starts_with((string) config('app.url'), 'https://')) {
                $issues[] = 'APP_URL must use https in production.';
            }
            if (! config('session.secure')) {
                $issues[] = 'SESSION_SECURE_COOKIE must be true in production.';
            }

            $diagnostics['configuration_issues'] = $issues;

            if ($issues !== []) {
                throw new \RuntimeException(implode(' ', $issues));
            }
        });

        $healthy = ! in_array('fail', $checks, true);

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'diagnostics' => $diagnostics,
        ];
    }

    private function probe(callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            report($e);

            return 'fail';
        }
    }
}
