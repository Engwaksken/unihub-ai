<?php

/**
 * Pilot benchmark harness (PRD §11 "Performance", work plan P4-3/P4-4).
 *
 * Submits every prompt in a prompts file to a gateway as a normal project client
 * would (POST /v1/jobs, then poll GET /v1/jobs/{id} with backoff). It stays inside
 * the project's queue and quota: on 429 it waits for Retry-After and tries again
 * with the SAME idempotency key. Writes a JSON result file and a Markdown report.
 *
 * Usage (staging only, with a staging key; temporarily raise the project's rpm/day quota):
 *   php tests/benchmark/run.php --base=https://ai.kemmytech.com --key=uhk_test_... \
 *       [--prompts=tests/benchmark/prompts/daily_plan.json] [--out=benchmark-results] \
 *       [--concurrency=2] [--deadline=300] [--sample-cmd="free -m"]
 *
 * Outputs are NOT added to the repo automatically: model outputs are synthetic but
 * still review them before sharing. No third-party dependencies (curl extension only).
 */
$opt = getopt('', ['base:', 'key:', 'prompts::', 'out::', 'concurrency::', 'deadline::', 'limit::', 'sample-cmd::']);
$base = rtrim($opt['base'] ?? getenv('BASE') ?: '', '/');
$key = $opt['key'] ?? getenv('KEY') ?: '';
if ($base === '' || $key === '') {
    fwrite(STDERR, "Usage: php run.php --base=URL --key=PROJECT_KEY [--prompts=FILE] [--out=DIR] [--concurrency=2] [--deadline=300]\n");
    exit(2);
}
$promptsFile = $opt['prompts'] ?? __DIR__.'/prompts/daily_plan.json';
$outDir = $opt['out'] ?? 'benchmark-results';
$concurrency = max(1, (int) ($opt['concurrency'] ?? 2));
$deadline = max(30, (int) ($opt['deadline'] ?? 300));
$suite = json_decode((string) file_get_contents($promptsFile), true, flags: JSON_THROW_ON_ERROR);
$prompts = array_slice($suite['prompts'], 0, (int) ($opt['limit'] ?? PHP_INT_MAX));
$runId = gmdate('Ymd\THis\Z');

function http(string $method, string $url, string $key, ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer '.$key, 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);

        return ['status' => 0, 'json' => null, 'retry_after' => null, 'error' => $err];
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $retry = preg_match('/^Retry-After:\s*(\d+)/mi', substr($raw, 0, $hsize), $m) ? (int) $m[1] : null;

    return ['status' => $status, 'json' => json_decode(substr($raw, $hsize), true), 'retry_after' => $retry, 'error' => null];
}

function pct(array $v, int $p): ?int
{
    if ($v === []) {
        return null;
    }
    sort($v);

    return (int) $v[max(0, (int) ceil($p / 100 * count($v)) - 1)];
}

function sampleHost(?string $cmd): ?string
{
    return $cmd ? trim((string) shell_exec($cmd)) : null;
}

$pending = $prompts;
$active = [];   // id => state
$results = [];
$samples = [];
$startAll = microtime(true);
$sampleCmd = $opt['sample-cmd'] ?? null;
$lastSample = 0.0;

echo "Benchmark {$runId}: ".count($prompts)." prompts against {$base} (concurrency {$concurrency})\n";

while ($pending !== [] || $active !== []) {
    // Submit while below our own concurrency target.
    while ($pending !== [] && count($active) < $concurrency) {
        $p = $pending[0];
        $idem = "bench-{$runId}-{$p['id']}";
        $t0 = microtime(true);
        $r = http('POST', "{$base}/v1/jobs", $key, ['task' => $suite['task'], 'input' => $p['input'], 'max_tokens' => $suite['max_tokens'], 'idempotency_key' => $idem]);
        if ($r['status'] === 202) {
            array_shift($pending);
            $active[$p['id']] = ['prompt' => $p, 'job_id' => $r['json']['job_id'], 'submitted' => $t0, 'polls' => 0, 'next_poll' => microtime(true) + 3, 'backoff' => 3, 'rejections' => $results[$p['id']]['rejections'] ?? 0];
            echo "  submitted {$p['id']} -> {$r['json']['job_id']}\n";
        } elseif ($r['status'] === 429 || $r['status'] === 503) {
            $wait = $r['retry_after'] ?? 10;
            $results[$p['id']]['rejections'] = ($results[$p['id']]['rejections'] ?? 0) + 1;
            echo "  {$p['id']}: {$r['status']} {$r['json']['error']['code']} — waiting {$wait}s\n";
            sleep(min($wait, 60));
            break;
        } else {
            array_shift($pending);
            $results[$p['id']] = ['id' => $p['id'], 'length' => $p['length'], 'status' => 'rejected', 'http' => $r['status'], 'error' => $r['json']['error']['code'] ?? $r['error'], 'total_ms' => null];
            echo "  {$p['id']}: rejected HTTP {$r['status']}\n";
        }
    }

    // Poll active jobs (3 s initial, backoff ×1.5 up to 10 s, bounded deadline).
    foreach ($active as $id => $a) {
        if (microtime(true) < $a['next_poll']) {
            continue;
        }
        $r = http('GET', "{$base}/v1/jobs/{$a['job_id']}", $key);
        $active[$id]['polls']++;
        $state = $r['json']['status'] ?? null;
        $elapsed = microtime(true) - $a['submitted'];
        if (in_array($state, ['completed', 'failed', 'expired', 'cancelled'], true) || $elapsed > $deadline) {
            $j = $r['json'] ?? [];
            $queueMs = isset($j['created_at'], $j['started_at']) ? (strtotime($j['started_at']) - strtotime($j['created_at'])) * 1000 : null;
            $inferMs = isset($j['started_at'], $j['completed_at']) ? (strtotime($j['completed_at']) - strtotime($j['started_at'])) * 1000 : null;
            $results[$id] = [
                'id' => $id, 'length' => $a['prompt']['length'], 'job_id' => $a['job_id'],
                'status' => $elapsed > $deadline && ! in_array($state, ['completed', 'failed', 'expired', 'cancelled'], true) ? 'client_deadline' : $state,
                'error' => $j['error']['code'] ?? null,
                'total_ms' => (int) round($elapsed * 1000), 'queue_ms_server' => $queueMs, 'inference_ms_server' => $inferMs,
                'tokens_in' => $j['tokens_in'] ?? null, 'tokens_out' => $j['tokens_out'] ?? null,
                'input_chars' => mb_strlen($a['prompt']['input']), 'output_chars' => isset($j['output']) ? mb_strlen((string) $j['output']) : null,
                'output' => $j['output'] ?? null, 'polls' => $active[$id]['polls'], 'rejections' => $a['rejections'],
            ];
            unset($active[$id]);
            echo "  {$id}: {$results[$id]['status']} in ".round($elapsed, 1)."s\n";
        } else {
            $active[$id]['backoff'] = min(10, $a['backoff'] * 1.5);
            $active[$id]['next_poll'] = microtime(true) + $active[$id]['backoff'];
        }
    }

    if ($sampleCmd && microtime(true) - $lastSample > 30) {
        $samples[] = ['t' => gmdate('c'), 'out' => sampleHost($sampleCmd)];
        $lastSample = microtime(true);
    }
    usleep(250_000);
}

$all = array_values($results);
$done = array_filter($all, fn ($r) => $r['status'] === 'completed');
$accepted = array_filter($all, fn ($r) => $r['status'] !== 'rejected');
$totals = array_column($done, 'total_ms');
$summary = [
    'run_id' => $runId, 'base' => $base, 'task' => $suite['task'], 'prompts' => count($prompts),
    'accepted' => count($accepted), 'completed' => count($done),
    'success_rate_of_accepted' => count($accepted) ? round(count($done) / count($accepted), 4) : null,
    'p50_total_ms' => pct($totals, 50), 'p95_total_ms' => pct($totals, 95), 'max_total_ms' => $totals ? max($totals) : null,
    'p95_inference_ms_server' => pct(array_filter(array_column($done, 'inference_ms_server'), 'is_int'), 95),
    'wall_clock_s' => (int) round(microtime(true) - $startAll),
    'pass_success_ge_95pct' => count($accepted) ? count($done) / count($accepted) >= 0.95 : false,
    'pass_p95_le_120s' => pct($totals, 95) !== null && pct($totals, 95) <= 120_000,
];

@mkdir($outDir, 0750, true);
file_put_contents("{$outDir}/benchmark-{$runId}.json", json_encode(['summary' => $summary, 'results' => $all, 'host_samples' => $samples], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

$md = "# Benchmark {$runId}\n\n| Metric | Value |\n|---|---|\n";
foreach ($summary as $k => $v) {
    $md .= "| {$k} | ".(is_bool($v) ? ($v ? 'PASS' : 'FAIL') : ($v ?? 'n/a'))." |\n";
}
$md .= "\n## Per prompt\n\n| id | length | status | total s | server inference s | tokens in/out | 429s |\n|---|---|---|---|---|---|---|\n";
foreach ($all as $r) {
    $md .= sprintf("| %s | %s | %s | %s | %s | %s/%s | %d |\n", $r['id'], $r['length'], $r['status'] ?? '?',
        isset($r['total_ms']) ? round($r['total_ms'] / 1000, 1) : 'n/a',
        isset($r['inference_ms_server']) ? round($r['inference_ms_server'] / 1000, 1) : 'n/a',
        $r['tokens_in'] ?? 'n/a', $r['tokens_out'] ?? 'n/a', $r['rejections'] ?? 0);
}
$md .= "\nTimes are client-observed (submit → terminal poll), so they include polling granularity (≤10 s).\n"
    ."Edge prompts p26–p30 test robustness (very short, injection attempt, low mood, medication, emoji): review their outputs manually.\n";
file_put_contents("{$outDir}/benchmark-{$runId}.md", $md);

echo "\n".json_encode($summary, JSON_PRETTY_PRINT)."\nWrote {$outDir}/benchmark-{$runId}.{json,md}\n";
exit($summary['pass_success_ge_95pct'] && $summary['pass_p95_le_120s'] ? 0 : 1);
