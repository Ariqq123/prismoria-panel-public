export interface MinecraftServerStatusPlayers {
    online?: number;
    max?: number;
    list?: string[];
}

export interface MinecraftServerStatusMotd {
    raw?: string[];
    clean?: string[];
    html?: string[];
}

export interface MinecraftServerStatusDebug {
    ping?: boolean;
    query?: boolean;
    bedrock?: boolean;
    srv?: boolean;
    querymismatch?: boolean;
    ipinsrv?: boolean;
    cnameinsrv?: boolean;
    animatedmotd?: boolean;
    cachehit?: boolean;
    cachetime?: number;
    cacheexpire?: number;
    apiversion?: number;
    error?: Record<string, string>;
}

export interface MinecraftServerStatusResponse {
    online?: boolean;
    ip?: string;
    port?: number;
    hostname?: string;
    version?: string;
    protocol?: number;
    protocol_name?: string;
    players?: MinecraftServerStatusPlayers;
    motd?: MinecraftServerStatusMotd;
    icon?: string;
    software?: string;
    map?: string;
    eula_blocked?: boolean;
    plugins?: {
        names?: string[];
    };
    debug?: MinecraftServerStatusDebug;
}

export interface MinecraftServerStatusResult {
    mode: 'java' | 'bedrock';
    fetchedAt: number;
    data: MinecraftServerStatusResponse;
}

const API_BASE = 'https://api.mcsrvstat.us';
const API_VERSION = 2;
const REQUEST_TIMEOUT = 12000;

const sanitizeHost = (host: string): string => {
    const trimmed = host.trim();
    if (trimmed === '') {
        return '';
    }

    const withoutProtocol = trimmed.replace(/^https?:\/\//i, '');
    return withoutProtocol.split('/')[0].trim();
};

const fetchStatus = async (
    mode: 'java' | 'bedrock',
    host: string,
    port?: number
): Promise<MinecraftServerStatusResponse> => {
    const cleanHost = sanitizeHost(host);
    if (!cleanHost) {
        throw new Error('Server address is empty.');
    }

    const target = port ? `${cleanHost}:${port}` : cleanHost;
    const encodedTarget = encodeURIComponent(target);
    const endpoint = mode === 'bedrock' ? `/bedrock/${API_VERSION}/${encodedTarget}` : `/${API_VERSION}/${encodedTarget}`;
    const url = `${API_BASE}${endpoint}`;

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(`Minecraft status lookup failed (${response.status}).`);
        }

        return (await response.json()) as MinecraftServerStatusResponse;
    } catch (error) {
        if ((error as Error).name === 'AbortError') {
            throw new Error('Minecraft status lookup timed out.');
        }

        throw error;
    } finally {
        clearTimeout(timeout);
    }
};

const hasUsefulJavaPayload = (data: MinecraftServerStatusResponse): boolean =>
    !!(
        data.online ||
        data.icon ||
        data.version ||
        data.protocol_name ||
        (Array.isArray(data.motd?.clean) && data.motd!.clean!.length > 0) ||
        typeof data.players?.max === 'number' ||
        typeof data.players?.online === 'number'
    );

export default async (host: string, port?: number): Promise<MinecraftServerStatusResult> => {
    const javaResult = await fetchStatus('java', host, port);

    if (javaResult.online || hasUsefulJavaPayload(javaResult)) {
        return {
            mode: 'java',
            fetchedAt: Date.now(),
            data: javaResult,
        };
    }

    const bedrockResult = await fetchStatus('bedrock', host, port);

    return {
        mode: bedrockResult.online ? 'bedrock' : 'java',
        fetchedAt: Date.now(),
        data: bedrockResult.online ? bedrockResult : javaResult,
    };
};
