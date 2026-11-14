// k6 load test: burst traffic through the public API.
//
// The stack delivers to webhook.site by default, whose free tier cannot take
// 20 000 requests. Point the workers at the local mock provider first:
//
//   NOTIFICATION_PROVIDER_URL=http://mock-provider:8080/   (in .env)
//   docker-compose --profile loadtest up -d mock-provider
//   docker-compose restart horizon
//   docker-compose --profile loadtest run --rm k6
//
// setup() refuses to run if the workers are not using the mock provider.
//
// Scenarios (run in parallel):
//   batches  - 20 batch requests x 1000 notifications (mixed channels and
//              priorities) as fast as 5 clients can send them: a flash sale
//   singles  - 50 single notifications per second for 30 seconds
//
// Teardown waits until every notification has left the queues, then checks
// the mock provider's own counters: the highest number of requests that
// arrived for a channel in any one-second window.

import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const API = __ENV.API_URL || 'http://nginx/api/v1';
const PROVIDER = __ENV.PROVIDER_URL || 'http://mock-provider:8080';
const RATE_LIMIT = Number(__ENV.RATE_LIMIT || 100);
// The limiter is exact where calls leave the workers (verified on Redis'
// clock). Measured on arrival at the provider, transport jitter adds a few
// percent; see README, "Load test".
const ARRIVAL_TOLERANCE = Number(__ENV.ARRIVAL_TOLERANCE || 0.1);
const BATCHES = Number(__ENV.BATCHES || 20);
const BATCH_SIZE = Number(__ENV.BATCH_SIZE || 1000);
const DRAIN_TIMEOUT_S = Number(__ENV.DRAIN_TIMEOUT_S || 600);

const accepted = new Counter('notifications_accepted');
const batchDuration = new Trend('batch_request_duration', true);

export const options = {
    scenarios: {
        batches: {
            executor: 'shared-iterations',
            exec: 'sendBatch',
            vus: 5,
            iterations: BATCHES,
            maxDuration: '2m',
        },
        singles: {
            executor: 'constant-arrival-rate',
            exec: 'sendSingle',
            rate: 50,
            timeUnit: '1s',
            duration: '30s',
            preAllocatedVUs: 20,
        },
    },
    // Calibrated for a single machine where the API, 36 workers, PostgreSQL
    // and Redis share the same CPUs (see README, "Load test").
    thresholds: {
        checks: ['rate>0.99'],
        'http_req_duration{scenario:singles}': ['p(95)<1000'],
        batch_request_duration: ['p(95)<8000'],
    },
    teardownTimeout: `${DRAIN_TIMEOUT_S + 60}s`,
};

const CHANNELS = ['sms', 'email', 'push'];
const PRIORITIES = ['high', 'normal', 'normal', 'low'];
const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };

function notification(i) {
    const channel = CHANNELS[i % CHANNELS.length];
    const recipient = {
        sms: `+90555${String(i % 10_000_000).padStart(7, '0')}`,
        email: `user${i}@example.com`,
        push: `device-token-${String(i).padStart(32, '0')}`,
    }[channel];

    return { channel, recipient, priority: PRIORITIES[i % PRIORITIES.length], content: `Flash sale #${i}: 40% off for the next hour!` };
}

export function setup() {
    assertWorkersUseMockProvider();
    http.post(`${PROVIDER}/reset`);
    return { startedAt: Date.now() };
}

// Sends one probe notification and waits for it to reach the mock provider.
function assertWorkersUseMockProvider() {
    const before = totalRequests();
    const probe = http.post(`${API}/notifications`, JSON.stringify(notification(0)), { headers });

    for (let i = 0; i < 20; i++) {
        sleep(0.5);
        if (totalRequests() > before) return;
    }

    exec.test.abort(
        `The probe notification (${probe.json('data.id')}) never reached the mock provider: the workers are ` +
        'probably delivering to webhook.site. Set NOTIFICATION_PROVIDER_URL=http://mock-provider:8080/ in .env ' +
        'and run `docker-compose restart horizon` before load testing.',
    );
}

function totalRequests() {
    const res = http.get(`${PROVIDER}/stats`);
    if (res.status !== 200) return -1;
    return Object.values(res.json('channels')).reduce((sum, c) => sum + c.requests, 0);
}

export function sendBatch() {
    const offset = (__ITER + __VU * 1000) * BATCH_SIZE;
    const items = Array.from({ length: BATCH_SIZE }, (_, i) => notification(offset + i));

    const res = http.post(`${API}/notifications/batch`, JSON.stringify({ notifications: items }), { headers, timeout: '30s' });
    batchDuration.add(res.timings.duration);

    if (check(res, { 'batch accepted (202)': (r) => r.status === 202 })) {
        accepted.add(res.json('data.accepted'));
    }
}

export function sendSingle() {
    const res = http.post(`${API}/notifications`, JSON.stringify(notification(Math.floor(Math.random() * 1e9))), { headers });

    if (check(res, { 'notification accepted (202)': (r) => r.status === 202 })) {
        accepted.add(1);
    }
}

export function teardown(data) {
    let inFlight = -1;
    const deadline = Date.now() + DRAIN_TIMEOUT_S * 1000;

    while (Date.now() < deadline) {
        const metrics = http.get(`${API}/metrics?window=1`).json();
        const f = metrics.notifications_in_flight;
        inFlight = f.pending + f.queued + f.processing;
        if (inFlight === 0) break;
        sleep(2);
    }

    const drainedAfter = ((Date.now() - data.startedAt) / 1000).toFixed(1);
    const stats = http.get(`${PROVIDER}/stats`).json();
    const metrics = http.get(`${API}/metrics?window=${Math.ceil(Number(drainedAfter) / 60) + 1}`).json();

    console.log(`\n=== drained: ${inFlight === 0} after ${drainedAfter}s ===`);
    for (const [channel, s] of Object.entries(stats.channels)) {
        const m = metrics.channels[channel];
        console.log(
            `${channel.padEnd(5)} provider calls=${s.requests} peak/s=${s.maxPerSecond} | ` +
            `sent=${m.sent} failed=${m.failed} success=${m.success_rate} ` +
            `provider p95=${m.latency_ms.provider.p95}ms e2e p50=${m.latency_ms.end_to_end.p50}ms p95=${m.latency_ms.end_to_end.p95}ms`,
        );
    }

    check(stats, {
        'every queued notification was processed': () => inFlight === 0,
        [`provider-side peak within ${ARRIVAL_TOLERANCE * 100}% of ${RATE_LIMIT} requests/second per channel`]: (s) =>
            Object.values(s.channels).every((c) => c.maxPerSecond <= RATE_LIMIT * (1 + ARRIVAL_TOLERANCE)),
    });
}
