// k6 soak test: steady mixed traffic for a long period (default 12 minutes),
// used together with tests/soak/run.sh, which samples memory / CPU and
// injects failures while this runs. Point the workers at the mock provider
// first (see the load test header).
//
//   docker-compose --profile loadtest run --rm -e DURATION=12m k6 run /scripts/soak-test.js

import http from 'k6/http';
import { check } from 'k6';

const API = __ENV.API_URL || 'http://nginx/api/v1';
const DURATION = __ENV.DURATION || '12m';

export const options = {
    scenarios: {
        singles: { executor: 'constant-arrival-rate', exec: 'single', rate: 40, timeUnit: '1s', duration: DURATION, preAllocatedVUs: 20, maxVUs: 60 },
        batches: { executor: 'constant-arrival-rate', exec: 'batch', rate: 1, timeUnit: '5s', duration: DURATION, preAllocatedVUs: 2, maxVUs: 10 },
    },
    thresholds: { checks: ['rate>0.99'] },
};

const CHANNELS = ['sms', 'email', 'push'];
const PRIORITIES = ['high', 'normal', 'normal', 'low'];
const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };

function item(i) {
    const channel = CHANNELS[i % 3];
    const recipient = { sms: `+90555${String(i % 1e7).padStart(7, '0')}`, email: `soak${i}@example.com`, push: `device-token-${String(i).padStart(32, '0')}` }[channel];
    return { channel, recipient, priority: PRIORITIES[i % 4], content: `Soak ${i}` };
}

export function single() {
    const res = http.post(`${API}/notifications`, JSON.stringify(item(Math.floor(Math.random() * 1e9))), { headers });
    check(res, { 'single accepted': (r) => r.status === 202 });
}

export function batch() {
    const base = Math.floor(Math.random() * 1e9);
    const res = http.post(`${API}/notifications/batch`, JSON.stringify({ notifications: Array.from({ length: 200 }, (_, i) => item(base + i)) }), { headers, timeout: '60s' });
    check(res, { 'batch accepted': (r) => r.status === 202 });
}
