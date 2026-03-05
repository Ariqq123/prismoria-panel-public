#!/usr/bin/env node
'use strict';

const crypto = require('crypto');
const fs = require('fs');
const http = require('http');
const https = require('https');
const path = require('path');

loadDotEnv();

const proxyUrlFromPanel = process.env.PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_URL || '';
const derivedPath = (() => {
    if (!proxyUrlFromPanel) {
        return '/ws/external';
    }

    try {
        const parsed = new URL(proxyUrlFromPanel.replace(/^wss?:\/\//i, 'http://'));

        return parsed.pathname || '/ws/external';
    } catch {
        return '/ws/external';
    }
})();

const host = process.env.EXTERNAL_WS_PROXY_HOST || '127.0.0.1';
const port = Number(process.env.EXTERNAL_WS_PROXY_PORT || '8090');
const expectedPath = process.env.EXTERNAL_WS_PROXY_PATH || derivedPath;
const secret = resolveProxySecret();
const fallbackOrigins = normalizeOriginList(
    process.env.EXTERNAL_WS_PROXY_ORIGIN || process.env.PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_ORIGIN || ''
);
const debugEnabled = ['1', 'true', 'yes', 'on'].includes(
    String(process.env.EXTERNAL_WS_PROXY_DEBUG || '0').toLowerCase()
);
const connectTimeoutMs = Number(process.env.EXTERNAL_WS_PROXY_CONNECT_TIMEOUT_MS || '10000');
const tlsRejectUnauthorized = !['0', 'false', 'no'].includes(
    String(process.env.EXTERNAL_WS_PROXY_TLS_REJECT_UNAUTHORIZED || '1').toLowerCase()
);

if (!Number.isFinite(port) || port <= 0) {
    console.error('[external-ws-proxy] EXTERNAL_WS_PROXY_PORT must be a valid number.');
    process.exit(1);
}

if (!secret) {
    console.error(
        '[external-ws-proxy] Missing proxy secret. Set EXTERNAL_WS_PROXY_SECRET, PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_SECRET, or APP_KEY.'
    );
    process.exit(1);
}

const server = http.createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok' }));

        return;
    }

    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not Found');
});

server.on('upgrade', (req, socket, head) => {
    const requestUrl = parseRequestUrl(req);
    if (!requestUrl) {
        return rejectUpgrade(socket, 400, 'Invalid websocket request URL.');
    }

    if (expectedPath && requestUrl.pathname !== expectedPath) {
        console.warn(
            `[external-ws-proxy] Rejected upgrade due unknown path. got="${requestUrl.pathname}" expected="${expectedPath}" from=${req.socket.remoteAddress || 'unknown'}`
        );
        return rejectUpgrade(socket, 404, 'Unknown websocket proxy path.');
    }

    const upgradeHeader = String(req.headers.upgrade || '').toLowerCase();
    if (upgradeHeader !== 'websocket') {
        console.warn(
            `[external-ws-proxy] Rejected upgrade due invalid Upgrade header "${String(req.headers.upgrade || '')}".`
        );
        return rejectUpgrade(socket, 400, 'Upgrade header must be websocket.');
    }

    const clientKey = req.headers['sec-websocket-key'];
    if (typeof clientKey !== 'string' || clientKey.length === 0) {
        console.warn('[external-ws-proxy] Rejected upgrade due missing Sec-WebSocket-Key header.');
        return rejectUpgrade(socket, 400, 'Missing Sec-WebSocket-Key header.');
    }

    const ticket = requestUrl.searchParams.get('ticket');
    if (!ticket) {
        console.warn('[external-ws-proxy] Rejected upgrade due missing ticket query parameter.');
        return rejectUpgrade(socket, 401, 'Missing websocket proxy ticket.');
    }

    let parsedTicket;
    try {
        parsedTicket = verifyTicket(ticket, secret, fallbackOrigins);
    } catch (error) {
        console.warn('[external-ws-proxy] Rejected upgrade due invalid ticket:', error instanceof Error ? error.message : error);
        return rejectUpgrade(socket, 401, error instanceof Error ? error.message : 'Invalid websocket proxy ticket.');
    }

    const upstream = parsedTicket.upstream;
    const serverReference = parsedTicket.serverReference || 'unknown';
    const upstreamHttpModule = upstream.protocol === 'wss:' ? https : http;
    const requestedProtocol = readHeaderAsString(req.headers['sec-websocket-protocol']);
    const originCandidates = parsedTicket.origins.length > 0 ? parsedTicket.origins : [''];

    let finished = false;
    let activeRequest = null;
    const fail = (status, message, error) => {
        if (finished) {
            return;
        }

        finished = true;
        if (error) {
            console.warn('[external-ws-proxy] Upstream websocket error:', error.message || error);
        }

        rejectUpgrade(socket, status, message);
        if (activeRequest) {
            activeRequest.destroy();
        }
    };

    const connectToUpstream = (originIndex) => {
        if (finished) {
            return;
        }

        const origin = originCandidates[originIndex] || '';
        debugLog(
            `Connecting upstream websocket. server=${serverReference} attempt=${originIndex + 1}/${originCandidates.length} upstream=${upstream.href} origin=${origin || '(none)'}`
        );
        const upstreamHeaders = {
            Connection: 'Upgrade',
            Upgrade: 'websocket',
            'Sec-WebSocket-Version': '13',
            'Sec-WebSocket-Key': crypto.randomBytes(16).toString('base64'),
        };

        if (origin) {
            upstreamHeaders.Origin = origin;
        }

        if (requestedProtocol) {
            upstreamHeaders['Sec-WebSocket-Protocol'] = requestedProtocol;
        }

        const upstreamRequest = upstreamHttpModule.request({
            protocol: upstream.protocol === 'wss:' ? 'https:' : 'http:',
            hostname: upstream.hostname,
            port: upstream.port || (upstream.protocol === 'wss:' ? 443 : 80),
            method: 'GET',
            path: `${upstream.pathname}${upstream.search}`,
            headers: upstreamHeaders,
            timeout: connectTimeoutMs,
            rejectUnauthorized: tlsRejectUnauthorized,
        });

        activeRequest = upstreamRequest;
        let completed = false;

        const hasNextOrigin = () => originIndex + 1 < originCandidates.length;
        const nextOrigin = () => originCandidates[originIndex + 1] || '';

        const retryNextOrigin = (status, bodySnippet) => {
            if (!hasNextOrigin()) {
                return false;
            }

            completed = true;
            upstreamRequest.destroy();
            console.warn(
                `[external-ws-proxy] Retrying upstream websocket with alternate origin due status ${status}. current=${origin || '(none)'} next=${nextOrigin() || '(none)'} upstream=${upstream.href} body="${bodySnippet.replace(/\s+/g, ' ').trim().slice(0, 200)}"`
            );
            connectToUpstream(originIndex + 1);

            return true;
        };

        upstreamRequest.on('upgrade', (upstreamResponse, upstreamSocket, upstreamHead) => {
            if (finished || completed) {
                upstreamSocket.destroy();
                return;
            }

            finished = true;
            completed = true;
            debugLog(
                `Upstream websocket connected. server=${serverReference} upstream=${upstream.href} origin=${origin || '(none)'}`
            );
            const protocolHeader = readHeaderAsString(upstreamResponse.headers['sec-websocket-protocol']);
            const headers = [
                'HTTP/1.1 101 Switching Protocols',
                'Upgrade: websocket',
                'Connection: Upgrade',
                `Sec-WebSocket-Accept: ${createWebsocketAccept(clientKey)}`,
            ];

            if (protocolHeader) {
                headers.push(`Sec-WebSocket-Protocol: ${protocolHeader}`);
            }

            socket.write(`${headers.join('\r\n')}\r\n\r\n`);

            if (head && head.length > 0) {
                upstreamSocket.write(head);
            }

            if (upstreamHead && upstreamHead.length > 0) {
                socket.write(upstreamHead);
            }

            socket.pipe(upstreamSocket);
            upstreamSocket.pipe(socket);

            socket.on('error', () => upstreamSocket.destroy());
            upstreamSocket.on('error', () => socket.destroy());
            socket.on('close', () => upstreamSocket.destroy());
            upstreamSocket.on('close', () => socket.destroy());
        });

        upstreamRequest.on('response', (response) => {
            if (finished || completed) {
                return;
            }

            const status = response.statusCode || 502;
            let bodySnippet = '';
            response.on('data', (chunk) => {
                if (bodySnippet.length >= 1024) {
                    return;
                }

                bodySnippet += chunk.toString('utf8');
                if (bodySnippet.length > 1024) {
                    bodySnippet = bodySnippet.slice(0, 1024);
                }
            });

            response.on('end', () => {
                if (finished || completed) {
                    return;
                }

                if (isLikelyOriginRejection(status, bodySnippet) && retryNextOrigin(status, bodySnippet)) {
                    return;
                }

                console.warn(
                    `[external-ws-proxy] Upstream websocket rejected upgrade with status ${status}. upstream=${upstream.href} origin=${origin || '(none)'} body="${bodySnippet.replace(/\s+/g, ' ').trim().slice(0, 500)}"`
                );
                fail(502, `Upstream websocket rejected upgrade with status ${status}.`);
            });
        });

        upstreamRequest.on('timeout', () => {
            if (finished || completed) {
                return;
            }

            fail(504, 'Timed out connecting to upstream websocket.');
        });

        upstreamRequest.on('error', (error) => {
            if (finished || completed) {
                return;
            }

            fail(502, 'Unable to connect to upstream websocket.', error);
        });

        upstreamRequest.end();
    };

    connectToUpstream(0);
});

server.listen(port, host, () => {
    console.log(
        `[external-ws-proxy] Listening on ${host}:${port}${expectedPath} (tls reject unauthorized: ${tlsRejectUnauthorized})`
    );
});

server.on('error', (error) => {
    console.error(
        `[external-ws-proxy] Failed to listen on ${host}:${port}${expectedPath}: ${error && error.message ? error.message : error}`
    );
    process.exit(1);
});

function parseRequestUrl(req) {
    try {
        const hostHeader = typeof req.headers.host === 'string' && req.headers.host.length > 0 ? req.headers.host : 'localhost';

        return new URL(req.url || '/', `http://${hostHeader}`);
    } catch {
        return null;
    }
}

function rejectUpgrade(socket, statusCode, message) {
    const body = `${message}\n`;
    socket.write(
        `HTTP/1.1 ${statusCode} ${http.STATUS_CODES[statusCode] || 'Error'}\r\n` +
            'Connection: close\r\n' +
            'Content-Type: text/plain; charset=utf-8\r\n' +
            `Content-Length: ${Buffer.byteLength(body)}\r\n\r\n` +
            body
    );
    socket.destroy();
}

function verifyTicket(ticket, hmacSecret, fallbackOrigins) {
    const [payloadPart, signaturePart] = ticket.split('.');
    if (!payloadPart || !signaturePart) {
        throw new Error('Malformed websocket proxy ticket.');
    }

    const expectedSignature = createSignature(payloadPart, hmacSecret);
    if (!safeEqual(signaturePart, expectedSignature)) {
        throw new Error('Invalid websocket proxy ticket signature.');
    }

    let payload;
    try {
        payload = JSON.parse(base64UrlDecode(payloadPart).toString('utf8'));
    } catch {
        throw new Error('Invalid websocket proxy ticket payload.');
    }

    if (!payload || typeof payload !== 'object') {
        throw new Error('Invalid websocket proxy ticket payload.');
    }

    const now = Math.floor(Date.now() / 1000);
    if (typeof payload.exp !== 'number' || payload.exp < now) {
        throw new Error('Websocket proxy ticket has expired.');
    }

    if (typeof payload.upstream !== 'string' || payload.upstream.length === 0) {
        throw new Error('Websocket proxy ticket is missing upstream socket URL.');
    }

    let upstream;
    try {
        upstream = new URL(payload.upstream);
    } catch {
        throw new Error('Websocket proxy ticket contains an invalid upstream socket URL.');
    }

    if (!['ws:', 'wss:'].includes(upstream.protocol)) {
        throw new Error('Upstream websocket protocol must be ws or wss.');
    }

    const payloadOrigins = normalizeOriginList(
        Array.isArray(payload.origins) && payload.origins.length > 0 ? payload.origins : payload.origin || ''
    );
    const resolvedOrigins = dedupeOrigins([...payloadOrigins, ...normalizeOriginList(fallbackOrigins)]);

    return {
        upstream,
        origins: resolvedOrigins.length > 0 ? resolvedOrigins : [''],
        serverReference: typeof payload.srv === 'string' ? payload.srv : '',
    };
}

function createSignature(payloadPart, hmacSecret) {
    return base64UrlEncode(crypto.createHmac('sha256', hmacSecret).update(payloadPart).digest());
}

function createWebsocketAccept(clientKey) {
    return crypto
        .createHash('sha1')
        .update(`${clientKey}258EAFA5-E914-47DA-95CA-C5AB0DC85B11`)
        .digest('base64');
}

function base64UrlEncode(buffer) {
    return Buffer.from(buffer)
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/g, '');
}

function base64UrlDecode(value) {
    const normalized = value.replace(/-/g, '+').replace(/_/g, '/');
    const missingPadding = normalized.length % 4;
    const padded = missingPadding === 0 ? normalized : normalized + '='.repeat(4 - missingPadding);

    return Buffer.from(padded, 'base64');
}

function safeEqual(left, right) {
    const leftBuffer = Buffer.from(String(left));
    const rightBuffer = Buffer.from(String(right));
    if (leftBuffer.length !== rightBuffer.length) {
        return false;
    }

    return crypto.timingSafeEqual(leftBuffer, rightBuffer);
}

function readHeaderAsString(value) {
    if (typeof value === 'string') {
        return value;
    }

    if (Array.isArray(value) && value.length > 0 && typeof value[0] === 'string') {
        return value[0];
    }

    return '';
}

function resolveProxySecret() {
    const explicitSecret = (
        process.env.EXTERNAL_WS_PROXY_SECRET ||
        process.env.PTERODACTYL_EXTERNAL_WEBSOCKET_PROXY_SECRET ||
        ''
    ).trim();

    if (explicitSecret.length > 0) {
        return explicitSecret;
    }

    const appKey = (process.env.APP_KEY || '').trim();
    if (appKey.length === 0) {
        return '';
    }

    if (!appKey.startsWith('base64:')) {
        return appKey;
    }

    const decoded = Buffer.from(appKey.slice(7), 'base64');
    if (decoded.length === 0) {
        return '';
    }

    return decoded;
}

function normalizeOriginList(value) {
    const inputs = Array.isArray(value)
        ? value
        : String(value || '')
              .split(/[\r\n,]+/)
              .map((entry) => entry.trim())
              .filter(Boolean);

    return dedupeOrigins(
        inputs
            .map((entry) => normalizeOrigin(entry))
            .filter((entry) => entry.length > 0)
    );
}

function normalizeOrigin(value) {
    const raw = String(value || '').trim();
    if (!raw) {
        return '';
    }

    try {
        const parsed = new URL(raw);
        const normalizedPort =
            (parsed.protocol === 'https:' && parsed.port === '443') || (parsed.protocol === 'http:' && parsed.port === '80')
                ? ''
                : parsed.port;

        return `${parsed.protocol}//${parsed.hostname}${normalizedPort ? `:${normalizedPort}` : ''}`;
    } catch {
        return '';
    }
}

function dedupeOrigins(origins) {
    const seen = new Set();

    return origins.filter((origin) => {
        const normalized = String(origin || '').trim();
        if (!normalized || seen.has(normalized)) {
            return false;
        }

        seen.add(normalized);

        return true;
    });
}

function isLikelyOriginRejection(status, bodySnippet) {
    if (![400, 401, 403].includes(Number(status))) {
        return false;
    }

    if (Number(status) === 403) {
        return true;
    }

    return /origin|cors|forbidden|not allowed|policy/i.test(String(bodySnippet || ''));
}

function loadDotEnv() {
    const candidateFiles = [path.resolve(process.cwd(), '.env'), path.resolve(__dirname, '..', '.env')];

    for (const envPath of candidateFiles) {
        if (!fs.existsSync(envPath)) {
            continue;
        }

        const contents = fs.readFileSync(envPath, 'utf8');
        for (const line of contents.split(/\r?\n/)) {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) {
                continue;
            }

            const separator = trimmed.indexOf('=');
            if (separator <= 0) {
                continue;
            }

            const key = trimmed.slice(0, separator).trim();
            if (!key || process.env[key] !== undefined) {
                continue;
            }

            const rawValue = trimmed.slice(separator + 1);
            const parsedValue = interpolateEnvReferences(unquoteEnvValue(rawValue).trim());
            process.env[key] = parsedValue;
        }
    }
}

function debugLog(message) {
    if (!debugEnabled) {
        return;
    }

    console.log(`[external-ws-proxy:debug] ${message}`);
}

function unquoteEnvValue(value) {
    const raw = String(value || '').trim();
    if (raw.startsWith('"') && raw.endsWith('"')) {
        return raw.slice(1, -1);
    }

    if (raw.startsWith("'") && raw.endsWith("'")) {
        return raw.slice(1, -1);
    }

    const commentIndex = raw.indexOf(' #');
    if (commentIndex >= 0) {
        return raw.slice(0, commentIndex).trim();
    }

    return raw;
}

function interpolateEnvReferences(value) {
    return String(value || '').replace(/\$\{([A-Z0-9_]+)\}/gi, (_match, variable) => process.env[variable] || '');
}
