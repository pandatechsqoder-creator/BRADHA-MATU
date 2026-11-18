<?php

declare(strict_types=1);

/**
 * Local/staging demo driver. Walks the Demo Supermarket tenant through the
 * Phase 3-15 job lifecycle over the real HTTP API so every screen has coherent
 * data. Safe to re-run: jobs/schedules are matched by title and skipped once
 * they reach their target status.
 *
 * Usage: php scripts/demo_lifecycle.php
 */
$base = getenv('API_BASE') ?: 'http://127.0.0.1:8000/api/v1';
$tenantUuid = getenv('DEMO_TENANT') ?: '01M78B8C1J3HRW14MB5FB2PATA';

$accounts = [
    'owner' => 'owner@demosupermarket.test',
    'ops' => 'ops@brathamatu.test',
    'supervisor' => 'supervisor@brathamatu.test',
    'finance' => 'finance@brathamatu.test',
    'officer' => 'officer@brathamatu.test',
    'worker' => 'worker@brathamatu.test',
    'senior' => 'senior.worker@brathamatu.test',
];

function api(string $method, string $path, ?array $body, string $token = '', ?string $tenant = null): array
{
    global $base;

    $ch = curl_init($base.$path);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer '.$token;
    }
    if ($tenant !== null) {
        $headers[] = 'X-Tenant-Id: '.$tenant;
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 90,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function ok(array $res, string $label, array $expect = [200, 201]): array
{
    if (! in_array($res['code'], $expect, true)) {
        fwrite(STDERR, "FAIL {$label} (HTTP {$res['code']}): ".substr($res['raw'], 0, 1200)."\n");
        exit(1);
    }
    echo "  ok   {$label} ({$res['code']})\n";

    $body = $res['body'];

    return $body['data'] ?? $body;
}

function expectStatus(array $data, string $status, string $label): void
{
    $actual = $data['status'] ?? ($data['job']['status'] ?? null);
    if ($actual !== $status) {
        fwrite(STDERR, "FAIL {$label}: expected status {$status}, got ".json_encode($actual)."\n");
        exit(1);
    }
    echo "       -> {$status}\n";
}

echo "== Signing in demo accounts ==\n";
$demoPassword = getenv('DEMO_PASSWORD') ?: 'Password1!';
$tokens = [];
foreach ($accounts as $role => $email) {
    $res = api('POST', '/auth/login', [
        'email' => $email,
        'password' => $demoPassword,
        'device_name' => 'demo-lifecycle',
    ]);
    $token = $res['body']['data']['token'] ?? null;
    if ($res['code'] !== 200 || ! is_string($token) || $token === '') {
        fwrite(STDERR, "FAIL login {$email} (HTTP {$res['code']}): ".substr($res['raw'], 0, 800)."\n");
        exit(1);
    }
    $tokens[$role] = $token;
    echo "  ok   {$email}\n";
}

$T = $tenantUuid;
$owner = $tokens['owner'];

echo "== Resolving demo tenant resources ==\n";
$branches = ok(api('GET', "/tenants/{$T}/branches", null, $owner, $T), 'branches');
$branch = collectLike($branches, 'code', 'DSM-01') ?? $branches[0];

$locations = ok(api('GET', "/tenants/{$T}/stock-locations", null, $owner, $T), 'stock locations');
$location = collectLike($locations, 'code', 'DSM-A1') ?? $locations[0];

$units = ok(api('GET', "/tenants/{$T}/units", null, $owner, $T), 'units');
$piece = null;
$unitMap = [];
foreach (($units['units'] ?? []) as $unit) {
    if (empty($unit['id'])) {
        continue;
    }
    $unitMap[$unit['code']] = $unit['id'];
    if (($unit['code'] ?? '') === 'piece') {
        $piece = $unit;
    }
}
if ($piece === null) {
    fwrite(STDERR, "No 'piece' unit with an id found.\n");
    exit(1);
}

$products = ok(api('GET', "/tenants/{$T}/products?per_page=100", null, $owner, $T), 'products');
$variants = [];
foreach ($products as $row) {
    $detail = ok(api('GET', "/tenants/{$T}/products/{$row['id']}", null, $owner, $T), 'product '.$row['sku']);
    $variant = $detail['variants'][0] ?? null;
    if ($variant !== null) {
        $variants[] = [
            'id' => $variant['id'],
            'name' => $detail['name'],
            'sku' => $detail['sku'],
            'price' => $variant['attributes']['retail_price'] ?? 0,
            'unit' => $unitMap[$detail['base_unit']] ?? $piece['id'],
        ];
    }
}
echo '  '.count($variants)." variants in scope\n";

$profiles = ok(api('GET', '/platform/stock-takers', null, $tokens['ops']), 'stock-taker profiles');
$workerProfile = collectLike($profiles, 'user.email', 'worker@brathamatu.test');
$seniorProfile = collectLike($profiles, 'user.email', 'senior.worker@brathamatu.test');
if ($workerProfile === null || $seniorProfile === null) {
    fwrite(STDERR, "Demo worker profiles are missing. Run the DatabaseSeeder first.\n");
    exit(1);
}

$templateId = ensureTemplate($tokens, $tokens['supervisor']);

function collectLike(array $rows, string $dotKey, string $value): ?array
{
    foreach ($rows as $row) {
        $node = $row;
        foreach (explode('.', $dotKey) as $segment) {
            $node = is_array($node) ? ($node[$segment] ?? null) : null;
        }
        if ($node === $value) {
            return $row;
        }
    }

    return null;
}

function jobByTitle(string $title, string $token, string $tenant): ?array
{
    $list = ok(api('GET', "/tenants/{$tenant}/stocktake-jobs?search=".urlencode($title), null, $token, $tenant), 'search jobs');
    foreach ($list as $job) {
        if (($job['title'] ?? null) === $title) {
            return $job;
        }
    }

    return null;
}

function ensureTemplate(array $tokens, string $supervisor): string
{
    $list = api('GET', '/platform/procedure-templates', null, $supervisor);
    if ($list['code'] === 200) {
        foreach (($list['body']['data'] ?? []) as $row) {
            $template = $row['template'] ?? $row;
            if (($template['name'] ?? null) === 'Supermarket count SOP') {
                return $template['id'];
            }
        }
    }

    $created = ok(api('POST', '/platform/procedure-templates', [
        'name' => 'Supermarket count SOP',
        'description' => 'Standard aisle count procedure for supermarket stock takes.',
        'steps' => [
            ['title' => 'Confirm aisle is clear and counting sheet is ready'],
            ['title' => 'Scan or count each SKU in the agreed scope'],
            ['title' => 'Record exceptions and unknown products'],
            ['title' => 'Submit the count for supervisor QA'],
        ],
        'checklists' => [[
            'name' => 'Pre-start checks',
            'kind' => 'pre_start',
            'required' => true,
            'items' => [
                ['label' => 'Safety briefing completed', 'response_type' => 'boolean', 'required' => true],
                ['label' => 'Counting device charged', 'response_type' => 'boolean', 'required' => true],
            ],
        ]],
    ], $supervisor), 'procedure template');

    return $created['template']['id'] ?? $created['id'];
}

/**
 * Advance a draft job to "submitted" (deposit paid and settled).
 *
 * @return array{job: array, depositAmount: float}
 */
function submitWithDeposit(string $jobId, string $token, string $tenant): array
{
    $submitted = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/submit", [
        'request_declaration' => ['confirm_accuracy' => true],
    ], $token, $tenant), 'submit request');
    expectStatus($submitted, 'awaiting_deposit', 'submit request');

    $amount = (float) ($submitted['deposit']['amount'] ?? 0);
    $deposit = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/deposits", [
        'amount' => $amount,
        'method' => 'mpesa',
        'phone' => '254712345678',
        'reference' => 'MPESA-DEP-'.strtoupper(substr($jobId, -6)),
    ], $token, $tenant), 'record deposit payment');

    $paymentId = $deposit['payment']['id'];
    $simulated = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/payments/{$paymentId}/simulate", [
        'outcome' => 'success',
    ], $token, $tenant), 'simulate deposit success');
    expectStatus($simulated['job'], 'submitted', 'deposit settled');

    return ['job' => $simulated['job'], 'depositAmount' => $amount];
}

/**
 * Platform review, quote issue, tenant acceptance with balance payment.
 *
 * @return array{job: array, quote: array}
 */
function quoteAndApprove(string $jobId, float $depositAmount, array $tokens, string $tenant): array
{
    $review = ok(api('POST', "/platform/stocktake-jobs/{$jobId}/review", null, $tokens['ops']), 'platform review start');
    expectStatus($review, 'under_review', 'platform review start');

    $quote = ok(api('POST', "/platform/stocktake-jobs/{$jobId}/quotes", [
        'base_service_charge' => 12000,
        'scope_complexity_charge' => 1500,
        'travel_expenses' => 800,
        'other_expenses' => 300,
        'discount' => 600,
        'worker_compensation_budget' => 6000,
        'worker_compensation_breakdown' => [
            ['label' => 'Two stock takers', 'amount' => 6000],
        ],
        'internal_notes' => 'Margin is thin; do not share with the tenant.',
        'notes' => 'Quote is valid for seven days from issue.',
        'deposit_amount' => $depositAmount,
    ], $tokens['ops']), 'issue quote');

    $accepted = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/quote/accept", null, $tokens['owner'], $tenant), 'accept quote');
    expectStatus($accepted, 'awaiting_balance_payment', 'accept quote');

    $balance = (float) ($accepted['quote']['balance_due'] ?? ($quote['quote']['balance_due'] ?? 0));
    $payment = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/payments", [
        'purpose' => 'balance',
        'amount' => $balance,
        'method' => 'mpesa',
        'phone' => '254712345678',
        'reference' => 'MPESA-BAL-'.strtoupper(substr($jobId, -6)),
    ], $tokens['owner'], $tenant), 'pay balance');

    $settled = ok(api('POST', "/tenants/{$tenant}/stocktake-jobs/{$jobId}/payments/{$payment['payment']['id']}/simulate", [
        'outcome' => 'success',
    ], $tokens['owner'], $tenant), 'simulate balance success');
    expectStatus($settled['job'], 'approved_for_execution', 'balance settled');

    return ['job' => $settled['job'], 'quote' => $quote['quote'] ?? []];
}

function offerAssignment(string $jobId, string $profileId, string $role, int $compensation, array $tokens): string
{
    $offer = ok(api('POST', "/platform/stocktake-jobs/{$jobId}/assignments", [
        'profile_id' => $profileId,
        'role' => $role,
        'compensation_amount' => $compensation,
        'expires_in_hours' => 72,
        'override_reason' => 'Demo lifecycle seeding: eligibility override for local walkthrough data.',
    ], $tokens['ops']), 'offer '.$role.' assignment');

    return $offer['assignment']['id'];
}

/**
 * Acknowledge the SOP, complete required checklists and check in.
 */
function onboardWorker(string $jobId, string $assignmentId, string $workerToken, string $templateId, array $tokens): void
{
    ok(api('POST', "/platform/stocktake-jobs/{$jobId}/procedure", ['template_id' => $templateId], $tokens['ops']), 'apply job procedure');
    ok(api('POST', "/workforce/assignments/{$assignmentId}/procedure/acknowledge", null, $workerToken), 'acknowledge SOP');

    $ops = ok(api('GET', "/workforce/assignments/{$assignmentId}/operations", null, $workerToken), 'load checklist');
    foreach (($ops['checklists'] ?? []) as $checklist) {
        foreach (($checklist['items'] ?? []) as $item) {
            ok(api('PUT', "/workforce/assignments/{$assignmentId}/checklists/{$item['id']}", [
                'value' => 'yes',
            ], $workerToken), 'checklist item '.$item['label']);
        }
    }

    ok(api('POST', "/workforce/assignments/{$assignmentId}/check-in", [
        'method' => 'manual',
        'site_label' => 'Demo Supermarket — Mombasa Road',
        'notes' => 'Checked in for the demo lifecycle count.',
    ], $workerToken), 'worker check-in');
}

/**
 * Single-worker flow: offer, accept, onboard.
 *
 * @return array{assignment: string, job: array}
 */
function prepareAssignment(string $jobId, string $profileId, string $workerToken, string $templateId, array $tokens, string $role = 'counter'): array
{
    $assignmentId = offerAssignment($jobId, $profileId, $role, $role === 'lead' ? 3500 : 3000, $tokens);
    $accept = ok(api('POST', "/workforce/assignments/{$assignmentId}/accept", null, $workerToken), 'worker accepts assignment');
    onboardWorker($jobId, $assignmentId, $workerToken, $templateId, $tokens);

    return ['assignment' => $assignmentId, 'job' => $accept];
}

/**
 * @param  array<int, array<string, mixed>>  $variants
 * @return array{session: string, entries: array<int, string>, keys: array<int, string>}
 */
function countEntries(string $assignmentId, array $variants, string $workerToken, string $suffix, bool $submit = true): array
{
    $session = ok(api('POST', "/workforce/assignments/{$assignmentId}/count/sessions", [
        'idempotency_key' => 'demo-session-'.$suffix.'-'.$assignmentId,
        'device' => ['platform' => 'browser', 'app_version' => 'demo-lifecycle'],
    ], $workerToken), 'start count session');

    $sessionId = $session['id'];
    $entryIds = [];
    $keys = [];

    foreach (array_slice($variants, 0, 6) as $index => $variant) {
        $quantity = 40 + ($index * 12);
        $key = 'demo-entry-'.$suffix.'-'.$assignmentId.'-'.($index + 1);
        $entry = ok(api('POST', "/workforce/assignments/{$assignmentId}/count/sessions/{$sessionId}/entries", [
            'idempotency_key' => $key,
            'variant_id' => $variant['id'],
            'raw_quantity' => $quantity,
            'raw_unit_id' => $variant['unit'],
            'observations' => ['good'],
            'notes' => $index === 2 ? 'Two units had damaged packaging; counted as sellable.' : null,
        ], $workerToken), 'count entry '.$variant['sku']);
        $entryIds[] = $entry['id'] ?? '';
        $keys[] = $key;
    }

    ok(api('POST', "/workforce/assignments/{$assignmentId}/count/sessions/{$sessionId}/exceptions", [
        'variant_id' => $variants[0]['id'],
        'type' => 'mismatched_barcode',
        'description' => 'Barcode label on the shelf edge did not match the product label.',
        'location' => 'Aisle 1',
        'observed_unit_id' => $variants[0]['unit'],
    ], $workerToken), 'report count exception');

    ok(api('POST', "/workforce/assignments/{$assignmentId}/count/sessions/{$sessionId}/unknown-products", [
        'barcode' => '6009999999999',
        'name_guess' => 'Unlisted spice sachet',
        'description' => 'Found a product not present in the catalogue snapshot.',
        'location' => 'Aisle 1 — end cap',
        'observed_unit_id' => $variants[0]['unit'],
    ], $workerToken), 'report unknown product');

    if ($submit) {
        $submitted = ok(api('POST', "/workforce/assignments/{$assignmentId}/count/sessions/{$sessionId}/submit", null, $workerToken), 'submit count session');
        expectStatus($submitted, 'submitted', 'submit count session');
    }

    return ['session' => $sessionId, 'entries' => $entryIds, 'keys' => $keys];
}

function qaPass(string $jobId, array $variants, array $tokens): void
{
    $review = ok(api('POST', "/platform/stocktake-jobs/{$jobId}/qa/review", null, $tokens['supervisor']), 'QA review start');

    ok(api('POST', "/platform/stocktake-jobs/{$jobId}/qa/findings", [
        'variant_id' => $variants[2]['id'],
        'type' => 'excess',
        'counted_quantity' => 64,
        'verified_quantity' => 63,
        'severity' => 'minor',
        'classification' => 'expected_variance',
        'notes' => 'Single unit variance is within the expected supermarket shrinkage tolerance.',
    ], $tokens['supervisor']), 'QA finding recorded');

    $complete = ok(api('POST', "/platform/stocktake-jobs/{$jobId}/qa/complete", [
        'decision' => 'pass',
        'summary' => 'Counts verified against the sample. Variance is expected shrinkage, not worker error.',
    ], $tokens['supervisor']), 'QA review complete');
}

function createJob(string $title, string $description, int $teamSize, array $scope, string $owner, string $tenant): array
{
    return ok(api('POST', "/tenants/{$tenant}/stocktake-jobs", [
        'title' => $title,
        'description' => $description,
        'job_type' => 'full',
        'priority' => 'high',
        'business_criticality' => 'Month-end financial reporting',
        'count_method' => 'barcode',
        'expected_duration_minutes' => 180,
        'preferred_start_at' => gmdate('c', strtotime('+3 days')),
        'deadline_at' => gmdate('c', strtotime('+10 days')),
        'branch_ids' => [$scope['branch']],
        'stock_location_ids' => [$scope['location']],
        'required_team_size' => $teamSize,
        'required_skills' => ['barcode counting'],
        'required_evidence' => ['signed count sheets'],
        'safety_requirements' => 'Closed-toe shoes and high-visibility vests required on the floor.',
        'custom_instructions' => 'Count before the 07:00 delivery window. Leave a gap for fresh produce awaiting quality check.',
    ], $owner, $tenant), 'create job '.$title);
}

// ---------------------------------------------------------------------------
echo "\n== Job A: full lifecycle through payout and close ==\n";
$titleA = 'Q4 2026 supermarket full count';
$jobA = jobByTitle($titleA, $owner, $T);
$doneA = ['closed', 'payout_completed', 'payout_processing', 'payout_pending_authorisation', 'completion_confirmed'];
if ($jobA !== null && in_array($jobA['status'], $doneA, true)) {
    echo "  skip (already at {$jobA['status']})\n";
    $jobAId = $jobA['id'];
} else {
    $created = createJob($titleA, 'Full count of the main branch ahead of the December financial close.', 2, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    $jobAId = $created['id'];
    expectStatus($created, 'draft', 'create job A');

    $deposit = submitWithDeposit($jobAId, $owner, $T);
    $quoted = quoteAndApprove($jobAId, $deposit['depositAmount'], $tokens, $T);

    // Fill the second team slot first; the job only becomes scheduled once the team is complete.
    $leadAssignment = offerAssignment($jobAId, $seniorProfile['id'], 'lead', 3500, $tokens);
    $workerAssignment = offerAssignment($jobAId, $workerProfile['id'], 'counter', 3000, $tokens);
    ok(api('POST', "/workforce/assignments/{$leadAssignment}/accept", null, $tokens['senior']), 'lead accepts assignment');
    $acceptedWorker = ok(api('POST', "/workforce/assignments/{$workerAssignment}/accept", null, $tokens['worker']), 'counter accepts assignment');
    expectStatus($acceptedWorker['job'], 'scheduled', 'team complete');
    onboardWorker($jobAId, $workerAssignment, $tokens['worker'], $templateId, $tokens);

    $counting = countEntries($workerAssignment, $variants, $tokens['worker'], 'a');
    qaPass($jobAId, $variants, $tokens);

    $review = ok(api('GET', "/tenants/{$T}/stocktake-jobs/{$jobAId}/review", null, $owner, $T), 'publish tenant review');
    $recon = ok(api('POST', "/tenants/{$T}/stocktake-jobs/{$jobAId}/review/reconciliation", null, $owner, $T), 'generate reconciliation report');
    $accepted = ok(api('POST', "/tenants/{$T}/stocktake-jobs/{$jobAId}/review/accept", [
        'notes' => 'Results accepted. Proceed with the variance write-off.',
    ], $owner, $T), 'tenant accepts results');
    $jobAAfterReview = ok(api('GET', "/tenants/{$T}/stocktake-jobs/{$jobAId}", null, $owner, $T), 'reload job A');
    expectStatus($jobAAfterReview, 'completion_confirmed', 'tenant accepts results');

    // Phase 11: replay a submitted entry with a different quantity → server records a conflict.
    $conflict = ok(api('POST', "/workforce/assignments/{$workerAssignment}/count/sessions/{$counting['session']}/sync", [
        'items' => [[
            'idempotency_key' => $counting['keys'][0] ?? 'demo-entry-a-1',
            'type' => 'entry',
            'variant_id' => $variants[0]['id'],
            'raw_quantity' => 999,
            'raw_unit_id' => $variants[0]['unit'],
        ]],
    ], $tokens['worker']), 'offline sync replay');
    echo '       -> sync status: '.($conflict['items'][0]['status'] ?? 'unknown')."\n";

    $batch = ok(api('POST', "/platform/stocktake-jobs/{$jobAId}/payouts", ['method' => 'mpesa'], $tokens['officer']), 'prepare payout batch');
    $batchId = $batch['id'];
    ok(api('POST', "/platform/payouts/{$batchId}/approve", ['notes' => 'Maker-checker approved in demo walkthrough.'], $tokens['finance']), 'approve payout batch');
    $processed = ok(api('POST', "/platform/payouts/{$batchId}/process", null, $tokens['finance']), 'process payout batch');
    $closed = ok(api('POST', "/platform/stocktake-jobs/{$jobAId}/close", null, $tokens['finance']), 'close job A');
    expectStatus($closed, 'closed', 'close job A');
}

// ---------------------------------------------------------------------------
echo "\n== Job B: disputed after tenant review ==\n";
$titleB = 'Q4 2026 pharmacy shelf count';
$jobB = jobByTitle($titleB, $owner, $T);
$doneB = ['disputed', 'completed', 'closed', 'completion_confirmed'];
if ($jobB !== null && in_array($jobB['status'], $doneB, true)) {
    echo "  skip (already at {$jobB['status']})\n";
} else {
    $created = createJob($titleB, 'Shelf count for the pharmacy aisle with high-value lines.', 1, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    $jobBId = $created['id'];
    expectStatus($created, 'draft', 'create job B');

    $deposit = submitWithDeposit($jobBId, $owner, $T);
    quoteAndApprove($jobBId, $deposit['depositAmount'], $tokens, $T);

    $assignmentB = prepareAssignment($jobBId, $seniorProfile['id'], $tokens['senior'], $templateId, $tokens);
    countEntries($assignmentB['assignment'], $variants, $tokens['senior'], 'b');
    qaPass($jobBId, $variants, $tokens);

    ok(api('GET', "/tenants/{$T}/stocktake-jobs/{$jobBId}/review", null, $owner, $T), 'publish tenant review B');
    ok(api('POST', "/tenants/{$T}/stocktake-jobs/{$jobBId}/review/reconciliation", null, $owner, $T), 'generate reconciliation B');
    $dispute = ok(api('POST', "/tenants/{$T}/stocktake-jobs/{$jobBId}/review/dispute", [
        'category' => 'accuracy',
        'priority' => 'high',
        'reason' => 'Three high-value lines were counted below the till-reported sales for the day.',
        'message' => 'Please re-verify the cold chain cabinet lines before we accept the results.',
    ], $owner, $T), 'raise dispute');
    $jobBAfterDispute = ok(api('GET', "/tenants/{$T}/stocktake-jobs/{$jobBId}", null, $owner, $T), 'reload job B');
    expectStatus($jobBAfterDispute, 'disputed', 'raise dispute');
}

// ---------------------------------------------------------------------------
echo "\n== Job C: in progress with an open count session ==\n";
$titleC = 'Cycle count — fast movers aisle';
$jobC = jobByTitle($titleC, $owner, $T);
if ($jobC !== null && $jobC['status'] !== 'draft') {
    echo "  skip (already at {$jobC['status']})\n";
    $assignmentCId = null;
} else {
    $created = createJob($titleC, 'Targeted cycle count of the highest-velocity SKUs.', 1, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    $jobCId = $created['id'];
    expectStatus($created, 'draft', 'create job C');

    $deposit = submitWithDeposit($jobCId, $owner, $T);
    quoteAndApprove($jobCId, $deposit['depositAmount'], $tokens, $T);

    $assignmentC = prepareAssignment($jobCId, $workerProfile['id'], $tokens['worker'], $templateId, $tokens);
    $assignmentCId = $assignmentC['assignment'];

    // Download the offline package (Phase 11) and start an open session.
    ok(api('GET', "/workforce/assignments/{$assignmentCId}/count/package?device_fingerprint=demo-web-01&platform=browser&app_version=demo", null, $tokens['worker']), 'download offline package');
    ok(api('POST', "/workforce/assignments/{$assignmentCId}/count/heartbeat", [
        'device_fingerprint' => 'demo-web-01',
        'platform' => 'browser',
        'offline_capable' => true,
    ], $tokens['worker']), 'device heartbeat');

    countEntries($assignmentCId, $variants, $tokens['worker'], 'c', submit: false);
}

// ---------------------------------------------------------------------------
echo "\n== Job D: draft request awaiting tenant submission ==\n";
$titleD = 'Ad-hoc spot check — returns bay';
if (jobByTitle($titleD, $owner, $T) === null) {
    $created = createJob($titleD, 'Spot check of customer returns held in the back-of-house bay.', 1, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    expectStatus($created, 'draft', 'create job D');
} else {
    echo "  skip (already exists)\n";
}

// ---------------------------------------------------------------------------
echo "\n== Job E: completed count awaiting payout authorisation ==\n";
$titleE = 'November cycle count — Westlands outlet';
$jobE = jobByTitle($titleE, $owner, $T);
$doneE = ['payout_pending_authorisation', 'payout_processing', 'payout_completed', 'closed'];
if ($jobE !== null && in_array($jobE['status'], $doneE, true)) {
    echo "  skip (already at {$jobE['status']})\n";
} else {
    $created = createJob($titleE, 'Cycle count of the Westlands outlet ahead of the November board pack.', 1, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    $jobEId = $created['id'];
    expectStatus($created, 'draft', 'create job E');

    $deposit = submitWithDeposit($jobEId, $owner, $T);
    quoteAndApprove($jobEId, $deposit['depositAmount'], $tokens, $T);

    $assignmentE = prepareAssignment($jobEId, $seniorProfile['id'], $tokens['senior'], $templateId, $tokens);
    countEntries($assignmentE['assignment'], $variants, $tokens['senior'], 'e');
    qaPass($jobEId, $variants, $tokens);

    ok(api('GET', "/tenants/{$T}/stocktake-jobs/{$jobEId}/review", null, $owner, $T), 'publish tenant review E');
    ok(api('POST', "/tenants/{$T}/stocktake-jobs/{$jobEId}/review/accept", [
        'notes' => 'Accepted; awaiting payout authorisation.',
    ], $owner, $T), 'tenant accepts results E');

    $pendingBatch = ok(api('POST', "/platform/stocktake-jobs/{$jobEId}/payouts", ['method' => 'mpesa'], $tokens['officer']), 'prepare payout batch E');
    echo '       -> batch '.($pendingBatch['reference'] ?? '?').' awaiting checker approval'."\n";
}

// ---------------------------------------------------------------------------
echo "\n== Job F: submitted count waiting for supervisor QA ==\n";
$titleF = 'Q1 2027 dry goods sample count';
$jobF = jobByTitle($titleF, $owner, $T);
$doneF = ['quality_review', 'count_submitted', 'awaiting_tenant_review', 'completion_confirmed', 'disputed', 'completed', 'closed'];
if ($jobF !== null && in_array($jobF['status'], $doneF, true)) {
    echo "  skip (already at {$jobF['status']})\n";
} else {
    $created = createJob($titleF, 'Sample count of dry goods for the Q1 supervisor QA walkthrough.', 1, ['branch' => $branch['id'], 'location' => $location['id']], $owner, $T);
    $jobFId = $created['id'];
    expectStatus($created, 'draft', 'create job F');

    $deposit = submitWithDeposit($jobFId, $owner, $T);
    quoteAndApprove($jobFId, $deposit['depositAmount'], $tokens, $T);

    $assignmentF = prepareAssignment($jobFId, $workerProfile['id'], $tokens['worker'], $templateId, $tokens);
    countEntries($assignmentF['assignment'], $variants, $tokens['worker'], 'f');

    ok(api('POST', "/platform/stocktake-jobs/{$jobFId}/qa/review", null, $tokens['supervisor']), 'QA review start F');
    ok(api('POST', "/platform/stocktake-jobs/{$jobFId}/qa/findings", [
        'variant_id' => $variants[3]['id'],
        'type' => 'missing',
        'counted_quantity' => 76,
        'verified_quantity' => 74,
        'severity' => 'minor',
        'classification' => 'expected_variance',
        'notes' => 'Two units unaccounted for during the delivery window; treated as expected variance.',
    ], $tokens['supervisor']), 'QA finding recorded F');
    echo "       -> job F left in quality_review for the QA screen\n";
}

// ---------------------------------------------------------------------------
echo "\n== Phase 15: recurring and on-demand schedules ==\n";
$schedules = ok(api('GET', "/tenants/{$T}/schedules", null, $owner, $T), 'list schedules');
$monthly = null;
foreach ($schedules as $schedule) {
    if (($schedule['title'] ?? null) === 'Monthly supermarket full count') {
        $monthly = $schedule;
    }
}

if ($monthly === null) {
    $monthly = ok(api('POST', "/tenants/{$T}/schedules", [
        'title' => 'Monthly supermarket full count',
        'description' => 'Recurring full count on the first working day of each month.',
        'mode' => 'proposed',
        'frequency' => 'monthly',
        'scope_type' => 'branch',
        'scope_config' => ['branch_id' => $branch['id']],
        'recurrence_config' => ['day_of_month' => 1],
        'starts_at' => gmdate('Y-m-d', strtotime('first day of next month')),
    ], $owner, $T), 'create monthly schedule');
} else {
    echo "  skip monthly schedule create\n";
}

$generated = ok(api('POST', "/tenants/{$T}/schedules/{$monthly['id']}/generate", ['count' => 3], $owner, $T), 'generate 3 occurrences');
echo '       -> generated '.($generated['generated'] ?? '?')." occurrence(s)\n";

$detail = ok(api('GET', "/tenants/{$T}/schedules/{$monthly['id']}", null, $owner, $T), 'load schedule detail');
$occurrences = $detail['occurrences'] ?? [];
$pending = null;
foreach ($occurrences as $occurrence) {
    if (($occurrence['status'] ?? '') === 'pending') {
        $pending = $occurrence;
        break;
    }
}
if ($pending !== null && empty($pending['skipped_at'])) {
    ok(api('POST', "/tenants/{$T}/schedule-occurrences/{$pending['id']}/skip", [
        'reason' => 'Skipped in the demo walkthrough because the branch was closed for stock delivery.',
    ], $owner, $T), 'skip first occurrence');
}

$onDemand = null;
foreach ($schedules as $schedule) {
    if (($schedule['title'] ?? null) === 'On-demand emergency count') {
        $onDemand = $schedule;
    }
}
if ($onDemand === null) {
    $onDemand = ok(api('POST', "/tenants/{$T}/schedules", [
        'title' => 'On-demand emergency count',
        'description' => 'Triggered manually after a suspected shrinkage event.',
        'mode' => 'reminder',
        'frequency' => 'on_demand',
    ], $owner, $T), 'create on-demand schedule');
}
ok(api('POST', "/tenants/{$T}/schedules/{$onDemand['id']}/trigger", null, $owner, $T), 'trigger on-demand schedule');

echo "\nDone. Demo lifecycle data is ready.\n";
