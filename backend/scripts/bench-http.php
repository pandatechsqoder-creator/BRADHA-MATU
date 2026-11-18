<?php

// In-process request benchmark: warms the framework once, then measures handle() latency.
// Approximates a warmed PHP-FPM/Octane worker without the Windows PHP built-in server queue.
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$iterations = (int) ($argv[1] ?? 300);
$latencies = [];

// Warm-up (routes, config, Redis/DB connections).
for ($i = 0; $i < 5; $i++) {
    $kernel->handle(Illuminate\Http\Request::create('/api/v1/health', 'GET'))->getContent();
}

$started = microtime(true);
for ($i = 0; $i < $iterations; $i++) {
    $request = Illuminate\Http\Request::create('/api/v1/health', 'GET', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $t = microtime(true);
    $response = $kernel->handle($request);
    $latencies[] = (microtime(true) - $t) * 1000;

    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "Non-200 response: {$response->getStatusCode()}\n");
        exit(1);
    }
}
$elapsed = microtime(true) - $started;

sort($latencies);
$pct = fn (float $p) => $latencies[min(count($latencies) - 1, (int) floor(($p / 100) * count($latencies)))];

echo json_encode([
    'endpoint' => 'GET /api/v1/health',
    'mode' => 'in-process (warm framework)',
    'requests' => $iterations,
    'duration_seconds' => round($elapsed, 3),
    'throughput_rps' => round($iterations / $elapsed, 1),
    'latency_ms' => [
        'min' => round($latencies[0], 2),
        'p50' => round($pct(50), 2),
        'p95' => round($pct(95), 2),
        'p99' => round($pct(99), 2),
        'max' => round($latencies[count($latencies) - 1], 2),
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
