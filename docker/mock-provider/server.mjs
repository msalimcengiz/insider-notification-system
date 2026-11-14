// Mock notification provider.
//
// Mirrors the webhook.site contract from the assessment:
//   POST /            {"to", "channel", "content"}
//   202 Accepted      {"messageId", "status": "accepted", "timestamp"}
//
// Extras for local testing and load tests:
//   - random latency and failure rates (env vars below)
//   - deterministic failures: put [mock:500], [mock:503], [mock:429],
//     [mock:400] or [mock:timeout] in the content
//   - Idempotency-Key header: a repeated key returns the same messageId
//   - GET /stats: request counts and the highest number of requests seen in
//     any one-second window, per channel (to verify rate limiting)
//   - POST /reset: clears the stats
//
// No dependencies: plain Node.js.

import { createServer } from 'node:http';
import { randomUUID } from 'node:crypto';

const PORT = Number(process.env.PORT ?? 8080);
const MIN_LATENCY_MS = Number(process.env.MOCK_MIN_LATENCY_MS ?? 20);
const MAX_LATENCY_MS = Number(process.env.MOCK_MAX_LATENCY_MS ?? 80);
const FAILURE_RATE = Number(process.env.MOCK_FAILURE_RATE ?? 0.05);
const PERMANENT_FAILURE_RATE = Number(process.env.MOCK_PERMANENT_FAILURE_RATE ?? 0.01);
const TIMEOUT_MS = 30_000;

const seenKeys = new Map();
let stats = freshStats();

function freshStats() {
    return { startedAt: new Date().toISOString(), channels: {} };
}

function channelStats(channel) {
    return (stats.channels[channel] ??= {
        requests: 0,
        accepted: 0,
        failed: 0,
        maxPerSecond: 0,
        window: [],
    });
}

function track(channel, outcome) {
    const s = channelStats(channel);
    const now = Date.now();

    s.requests++;
    s[outcome]++;
    s.window.push(now);
    while (s.window.length && s.window[0] <= now - 1000) s.window.shift();
    s.maxPerSecond = Math.max(s.maxPerSecond, s.window.length);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function send(res, status, body, headers = {}) {
    res.writeHead(status, { 'Content-Type': 'application/json', ...headers });
    res.end(JSON.stringify(body));
}

async function readJson(req) {
    let raw = '';
    for await (const chunk of req) raw += chunk;
    try {
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

function forcedOutcome(content) {
    const match = /\[mock:(500|503|429|400|timeout)\]/.exec(content ?? '');
    if (match) return match[1];

    const roll = Math.random();
    if (roll < PERMANENT_FAILURE_RATE) return '400';
    if (roll < PERMANENT_FAILURE_RATE + FAILURE_RATE) return '503';

    return null;
}

const server = createServer(async (req, res) => {
    if (req.method === 'GET' && req.url === '/stats') {
        const channels = Object.fromEntries(
            Object.entries(stats.channels).map(([name, { window, ...rest }]) => [name, rest]),
        );
        return send(res, 200, { ...stats, channels });
    }

    if (req.method === 'POST' && req.url === '/reset') {
        stats = freshStats();
        seenKeys.clear();
        return send(res, 200, { reset: true });
    }

    if (req.method === 'GET' && req.url === '/health') {
        return send(res, 200, { status: 'ok' });
    }

    if (req.method !== 'POST') {
        return send(res, 405, { message: 'Method not allowed' });
    }

    const body = await readJson(req);
    const channel = typeof body?.channel === 'string' ? body.channel : 'unknown';

    if (!body || !body.to || !body.channel || !body.content) {
        track(channel, 'failed');
        return send(res, 400, { message: 'to, channel and content are required' });
    }

    const outcome = forcedOutcome(body.content);

    if (outcome === 'timeout') {
        await sleep(TIMEOUT_MS);
    } else {
        await sleep(MIN_LATENCY_MS + Math.random() * (MAX_LATENCY_MS - MIN_LATENCY_MS));
    }

    if (outcome === '429') {
        track(channel, 'failed');
        return send(res, 429, { message: 'Too many requests' }, { 'Retry-After': '5' });
    }

    if (outcome === '400') {
        track(channel, 'failed');
        return send(res, 400, { message: 'Recipient rejected by the provider' });
    }

    if (outcome === '500' || outcome === '503') {
        track(channel, 'failed');
        return send(res, Number(outcome), { message: 'Provider temporarily unavailable' });
    }

    const key = req.headers['idempotency-key'];
    let messageId = key ? seenKeys.get(key) : undefined;
    if (!messageId) {
        messageId = randomUUID();
        if (key) seenKeys.set(key, messageId);
    }

    track(channel, 'accepted');
    return send(res, 202, { messageId, status: 'accepted', timestamp: new Date().toISOString() });
});

server.listen(PORT, () => console.log(`mock provider listening on :${PORT}`));
