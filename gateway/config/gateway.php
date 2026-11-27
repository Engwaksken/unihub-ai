<?php

/*
| Unihub gateway configuration. Operational limits here are DEFAULTS only;
| the live values are stored in the `settings` table and edited through the
| management API (see App\Services\Settings).
*/

return [

    // Which route surface this PHP-FPM pool serves. nginx sets the FastCGI param
    // GATEWAY_SURFACE=public|internal per vhost (ADR D6). When the param is absent
    // the request is refused, unless allow_any_surface is true (local dev/tests only).
    'allow_any_surface' => (bool) env('GATEWAY_ALLOW_ANY_SURFACE', false),

    // Directory holding key_pepper, payload_keys/, mgmt_token_digests, cms_assertion.pub.
    // Production: /etc/unihub-gateway/secrets (0700, owned by the gateway user).
    'secrets_path' => env('GATEWAY_SECRETS_PATH', storage_path('secrets')),

    'host_id' => env('GATEWAY_HOST_ID', gethostname() ?: 'gateway'),

    'ollama' => [
        // Must be loopback (enforced in OllamaClient). Never user-supplied (SSRF control).
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'connect_timeout' => 5,
        'num_ctx' => 2048,
        'num_thread' => (int) env('OLLAMA_NUM_THREAD', 2),
        'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
    ],

    'worker' => [
        'lock_path' => env('GATEWAY_WORKER_LOCK', storage_path('framework/worker.lock')),
        'idle_sleep_ms' => 1000,
        'max_attempts' => 2,          // 1 original + 1 retry for transient failures
        'retry_backoff_seconds' => 5,
        'lease_grace_seconds' => 30,  // lease = inference deadline + grace
        'post_timeout_cooldown_seconds' => 3,
    ],

    'management' => [
        // Optional app-level IP allowlist (nginx is the primary control). Comma separated.
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('GATEWAY_MGMT_ALLOWED_IPS', ''))))),
        'assertion_issuer' => 'unihub-cms',
        'assertion_audience' => 'unihub-gateway-mgmt',
        'assertion_max_ttl' => 60,
        'assertion_clock_skew' => 30,
    ],

    'tls_cert_path' => env('GATEWAY_TLS_CERT_PATH'),

    // systemd units whose state is sampled with each telemetry run (audit G14). Comma separated.
    // A unit reported inactive/failed opens a critical `service_down` alert.
    'monitored_services' => array_values(array_filter(array_map('trim', explode(',', (string) env('GATEWAY_MONITORED_SERVICES', 'ollama,mariadb'))))),

    // Defaults for the settings table (PRD §08). All values provisional until benchmark.
    'defaults' => [
        'global_queue_limit' => 8,
        'default_project_queue_limit' => 2,
        'default_rpm_limit' => 5,
        'default_daily_limit' => 100,
        'max_body_bytes' => 32768,
        'max_output_tokens' => 256,
        'queue_wait_max_seconds' => 120,
        'inference_deadline_seconds' => 120,
        'result_ttl_hours' => 24,
        'metadata_retention_days' => 90,
        'key_rotation_overlap_days' => 7,
        'poll_rate_per_minute' => 60,
        'admission' => 'open',
        'monthly_hosting_cost_usd' => 21.88,
        'alert_ram_pct' => 85,
        'alert_disk_pct' => 80,
        'alert_swap_pct' => 50,
        'alert_heartbeat_seconds' => 60,
        'alert_tls_days' => 14,
        'telemetry_stale_seconds' => 120,
        'approved_providers' => [],   // external fallback disabled (PRD §08)
    ],
];
