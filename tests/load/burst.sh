#!/usr/bin/env bash
# Acceptance "Queue" test (PRD §11): burst N concurrent submissions and report status codes.
# Run against STAGING only, with a staging key (uhk_test_...).
# Usage: BASE=https://ai.kemmytech.com KEY=uhk_test_... bash tests/load/burst.sh 2 5 10
set -euo pipefail
BASE="${BASE:?BASE url}"; KEY="${KEY:?project key}"
for N in "${@:-2 5 10}"; do
  echo "== burst of $N"
  for i in $(seq 1 "$N"); do
    curl -s -o /dev/null -w '%{http_code}\n' -X POST "$BASE/v1/jobs" \
      -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' -H 'Accept: application/json' \
      -d "{\"task\":\"daily_plan\",\"input\":\"Burst test $i: plan a revision day\",\"max_tokens\":120,\"idempotency_key\":\"burst-$N-$i-$(date +%s%N)\"}" &
  done | sort | uniq -c
  wait
done
echo "Expect: accepted <= project queue_limit (+ rpm headroom); the rest 429 with Retry-After; never 5xx."
echo "Then on the VPS: journalctl -u unihub-gateway-worker shows jobs processed strictly one at a time."
