#!/usr/bin/env bash
#
# Soak test: steady traffic for DURATION minutes while resources are sampled
# every 30 s and failures are injected on a fixed timeline. Afterwards it
# waits for the queues to drain, samples an idle period and writes a report.
#
#   NOTIFICATION_PROVIDER_URL=http://mock-provider:8080/   (in .env, then restart horizon)
#   docker-compose --profile mock up -d mock-provider
#   tests/soak/run.sh [minutes] [output dir]
#
# Failure timeline (seconds after start):
#   120  kill -9 one Horizon worker process (crash mid-job)
#   210  restart Redis
#   300  provider outage for 60 s (every request fails -> circuit breaker)
#   420  stop Jaeger for 60 s (trace collector down)
#   540  restart Horizon (graceful deploy)
#   600  kill -9 the scheduler container, start it again
set -uo pipefail

MINUTES="${1:-12}"
OUT="${2:-storage/framework/cache/soak-$(date +%Y%m%d-%H%M%S)}"
API="http://localhost:8000/api/v1"
mkdir -p "$OUT"
DC="docker-compose"

log() { echo "[$(date +%H:%M:%S)] $*" | tee -a "$OUT/events.log"; }

in_flight() {
  curl -s "$API/metrics?window=1" | python3 -c 'import sys,json
try:
  f=json.load(sys.stdin)["notifications_in_flight"]; print(f["pending"],f["queued"],f["processing"],f["retrying"],f["scheduled"])
except Exception: print("? ? ? ? ?")'
}

# "<count>,<sum MB>,<max MB>" of the processes whose command line contains $2.
proc_rss() {
  $DC exec -T "$1" sh -c "for p in /proc/[0-9]*; do tr '\\0' ' ' < \$p/cmdline 2>/dev/null | grep -q '$2' && awk '/VmRSS/{print \$2}' \$p/status; done" 2>/dev/null \
    | awk '{s+=$1; if($1>m)m=$1; n++} END {printf "%d,%d,%d", n, s/1024, m/1024}'
}

sample() {
  local elapsed=$1 phase=$2
  local stats workers fpm redis_mem redis_keys pg_conn flight
  stats=$(docker stats --no-stream --format '{{.Name}}={{.MemUsage}}={{.CPUPerc}}' 2>/dev/null \
    | sed -E 's/insider-notification-system-//; s/-1=/=/' | tr '\n' ' ')
  # busybox ps (alpine) has no rss column: read VmRSS from /proc.
  workers=$(proc_rss horizon 'horizon:work')
  fpm=$(proc_rss app 'php-fpm: pool')
  redis_mem=$($DC exec -T redis redis-cli info memory 2>/dev/null | awk -F: '/^used_memory:/{printf "%d", $2/1048576}')
  redis_keys=$($DC exec -T redis redis-cli dbsize 2>/dev/null | tr -d '\r')
  pg_conn=$($DC exec -T postgres psql -U postgres -tAc "select count(*) from pg_stat_activity where datname='notifications'" 2>/dev/null | tr -d '\r')
  flight=$(in_flight)
  echo "$(date +%s),$elapsed,$phase,$workers,$fpm,$redis_mem,$redis_keys,$pg_conn,${flight// /,},$stats" >> "$OUT/samples.csv"
}

# Changes the mock provider's failure rates at runtime (its stats survive).
mock_rates() {
  curl -s -X POST localhost:8081/config -H 'Content-Type: application/json' \
    -d "{\"failureRate\": $1, \"permanentFailureRate\": $2}" >/dev/null
}

faults() {
  sleep 120; local pid
  pid=$($DC exec -T horizon sh -c "ps -o pid=,args= | grep 'horizon:work' | grep sms | grep -v grep | head -1 | awk '{print \$1}'" | tr -d '\r')
  log "FAULT kill -9 worker pid $pid"; $DC exec -T horizon kill -9 "$pid"
  sleep 90;  log "FAULT restart redis"; $DC restart redis >/dev/null 2>&1; log "redis back"
  sleep 90;  log "FAULT provider outage 60s"; mock_rates 1 0
  sleep 60;  log "provider restored"; mock_rates 0.05 0.01
  sleep 60;  log "FAULT stop jaeger 60s"; $DC stop jaeger >/dev/null 2>&1
  sleep 60;  log "jaeger started"; $DC start jaeger >/dev/null 2>&1
  sleep 60;  log "FAULT restart horizon (graceful)"; $DC restart horizon >/dev/null 2>&1; log "horizon back"
  sleep 50;  log "FAULT kill -9 scheduler"; docker kill insider-notification-system-scheduler-1 >/dev/null 2>&1; sleep 5; $DC start scheduler >/dev/null 2>&1; log "scheduler back"
}

START=$(date +%s)
echo "epoch,elapsed,phase,workers,workers_rss_mb,worker_max_rss_mb,fpm,fpm_sum_rss_mb,fpm_max_rss_mb,redis_mb,redis_keys,pg_conn,pending,queued,processing,retrying,scheduled,containers" > "$OUT/samples.csv"
date -u +%Y-%m-%dT%H:%M:%SZ > "$OUT/started_at"
curl -s -X POST localhost:8081/reset >/dev/null
log "soak start: ${MINUTES} min of load"

( while :; do sample $(( $(date +%s) - START )) load; sleep 30; done ) & SAMPLER=$!
faults & FAULTS=$!

$DC --profile loadtest run --rm -e DURATION="${MINUTES}m" k6 run /scripts/soak-test.js > "$OUT/k6.log" 2>&1
log "load finished: $(grep -E 'checks\.|http_reqs\.' "$OUT/k6.log" | tr -s ' ' | tr '\n' ' ')"
wait $FAULTS 2>/dev/null
kill $SAMPLER 2>/dev/null

# Drain: everything in flight must reach a final state (the recovery sweeper
# re-queues jobs lost to the crashes after 5-15 minutes).
DRAIN_START=$(date +%s)
while :; do
  read -r p q pr r s <<< "$(in_flight)"
  sample $(( $(date +%s) - START )) drain
  if [ "$p" = "0" ] && [ "$q" = "0" ] && [ "$pr" = "0" ] && [ "$r" = "0" ]; then break; fi
  if [ $(( $(date +%s) - DRAIN_START )) -gt 1260 ]; then log "DRAIN TIMEOUT pending=$p queued=$q processing=$pr retrying=$r"; break; fi
  sleep 30
done
log "drained after $(( $(date +%s) - DRAIN_START ))s"

# Idle: nothing should keep burning CPU once the work is done.
for i in 1 2 3 4; do sample $(( $(date +%s) - START )) idle; sleep 30; done
$DC exec -T horizon sh -c "ps -o pid,rss,time,args | grep -E 'horizon|artisan' | grep -v grep" > "$OUT/idle-processes.txt" 2>&1
curl -s localhost:8081/stats > "$OUT/provider-stats.json"
log "soak done, results in $OUT"
