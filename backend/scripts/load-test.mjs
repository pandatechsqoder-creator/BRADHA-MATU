#!/usr/bin/env node
// Minimal dependency-free load probe for the public health endpoint.
// Usage: node scripts/load-test.mjs [total] [concurrency]
const total = Number(process.argv[2] ?? 500);
const concurrency = Number(process.argv[3] ?? 20);
const base = process.env.BASE_URL ?? 'http://127.0.0.1:8000/api/v1';
const targets = base.split(',').map((value) => value.trim());

const latencies = [];
const statuses = {};
let next = 0;

async function worker() {
  while (next < total) {
    next += 1;
    const started = performance.now();
    const target = targets[(next - 1) % targets.length];
    try {
      const res = await fetch(`${target}/health`, { headers: { Accept: 'application/json' } });
      statuses[res.status] = (statuses[res.status] ?? 0) + 1;
      await res.arrayBuffer();
    } catch {
      statuses.error = (statuses.error ?? 0) + 1;
    }
    latencies.push(performance.now() - started);
  }
}

const started = performance.now();
await Promise.all(Array.from({ length: concurrency }, worker));
const elapsed = performance.now() - started;
latencies.sort((a, b) => a - b);

const pct = (p) => latencies[Math.min(latencies.length - 1, Math.floor((p / 100) * latencies.length))];
console.log(JSON.stringify({
  target: targets.map((value) => `${value}/health`).join(', '),
  requests: total,
  concurrency,
  duration_seconds: Number((elapsed / 1000).toFixed(2)),
  throughput_rps: Number((total / (elapsed / 1000)).toFixed(1)),
  statuses,
  latency_ms: {
    min: Number(latencies[0].toFixed(1)),
    p50: Number(pct(50).toFixed(1)),
    p95: Number(pct(95).toFixed(1)),
    p99: Number(pct(99).toFixed(1)),
    max: Number(latencies[latencies.length - 1].toFixed(1)),
  },
}, null, 2));
