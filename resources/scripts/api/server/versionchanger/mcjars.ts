export interface McJarsTypeDetails {
    name: string;
    icon: string;
    color: string;
    homepage: string;
    deprecated: boolean;
    experimental: boolean;
    description: string;
    categories: string[];
    compatibility: string[];
    builds: number;
    versions: {
        minecraft: number;
        project: number;
    };
}

export interface McJarsType extends McJarsTypeDetails {
    key: string;
    bucket: string;
}

export interface McJarsBuildDownloadStep {
    type: 'download';
    url: string;
    file: string;
    size: number;
}

export interface McJarsBuildExtractStep {
    type: 'unzip';
    file: string;
    location: string;
}

export interface McJarsBuildRemoveStep {
    type: 'remove';
    location: string;
}

export type McJarsInstallationStep = McJarsBuildDownloadStep | McJarsBuildExtractStep | McJarsBuildRemoveStep;

export interface McJarsBuild {
    id: number;
    versionId: string | null;
    projectVersionId: string | null;
    type: string;
    experimental: boolean;
    name: string;
    buildNumber: number;
    jarUrl: string | null;
    jarSize: number | null;
    zipUrl: string | null;
    zipSize: number | null;
    installation: McJarsInstallationStep[][];
    changes: string[];
    created: string | null;
}

export interface McJarsVersion {
    id: string;
    type: string;
    supported: boolean;
    java: number;
    builds: number;
    created: string;
    latest: McJarsBuild;
}

const API_BASE = 'https://mcjars.app/api';
const REQUEST_TIMEOUT = 14000;
const TYPE_BUCKET_ORDER = ['recommended', 'established', 'experimental', 'miscellaneous', 'limbos'];

const withTimeout = async <T>(handler: (signal: AbortSignal) => Promise<T>): Promise<T> => {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);

    try {
        return await handler(controller.signal);
    } catch (error) {
        if ((error as Error).name === 'AbortError') {
            throw new Error('MCJars request timed out.');
        }

        throw error;
    } finally {
        clearTimeout(timeout);
    }
};

const fetchMcJarsJson = async <T>(endpoint: string): Promise<T> => {
    return withTimeout(async (signal) => {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
            credentials: 'omit',
            signal,
        });

        if (!response.ok) {
            throw new Error(`MCJars responded with status ${response.status}.`);
        }

        return (await response.json()) as T;
    });
};

const asTypeDetails = (value: unknown): McJarsTypeDetails | null => {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const raw = value as Partial<McJarsTypeDetails>;
    if (typeof raw.name !== 'string' || typeof raw.icon !== 'string') {
        return null;
    }

    return {
        name: raw.name,
        icon: typeof raw.icon === 'string' ? raw.icon : '',
        color: typeof raw.color === 'string' ? raw.color : '#ef4444',
        homepage: typeof raw.homepage === 'string' ? raw.homepage : '',
        deprecated: !!raw.deprecated,
        experimental: !!raw.experimental,
        description: typeof raw.description === 'string' ? raw.description : '',
        categories: Array.isArray(raw.categories) ? raw.categories.filter((item): item is string => typeof item === 'string') : [],
        compatibility: Array.isArray(raw.compatibility)
            ? raw.compatibility.filter((item): item is string => typeof item === 'string')
            : [],
        builds: typeof raw.builds === 'number' ? raw.builds : 0,
        versions: {
            minecraft: typeof raw.versions?.minecraft === 'number' ? raw.versions.minecraft : 0,
            project: typeof raw.versions?.project === 'number' ? raw.versions.project : 0,
        },
    };
};

export const fetchMcJarsTypes = async (): Promise<McJarsType[]> => {
    const payload = await fetchMcJarsJson<{ success: boolean; types: Record<string, Record<string, unknown>> }>('/v2/types');
    const types = payload?.types && typeof payload.types === 'object' ? payload.types : {};
    const merged = new Map<string, McJarsType>();

    TYPE_BUCKET_ORDER.forEach((bucket) => {
        const bucketTypes = types[bucket];
        if (!bucketTypes || typeof bucketTypes !== 'object') {
            return;
        }

        Object.entries(bucketTypes).forEach(([key, value]) => {
            if (merged.has(key)) {
                return;
            }

            const details = asTypeDetails(value);
            if (!details) {
                return;
            }

            merged.set(key, {
                key,
                bucket,
                ...details,
            });
        });
    });

    Object.entries(types).forEach(([bucket, bucketTypes]) => {
        if (!bucketTypes || typeof bucketTypes !== 'object') {
            return;
        }

        Object.entries(bucketTypes).forEach(([key, value]) => {
            if (merged.has(key)) {
                return;
            }

            const details = asTypeDetails(value);
            if (!details) {
                return;
            }

            merged.set(key, {
                key,
                bucket,
                ...details,
            });
        });
    });

    return Array.from(merged.values()).sort((a, b) => {
        const aBucketOrder = TYPE_BUCKET_ORDER.indexOf(a.bucket);
        const bBucketOrder = TYPE_BUCKET_ORDER.indexOf(b.bucket);

        if (aBucketOrder !== bBucketOrder) {
            return (aBucketOrder === -1 ? Number.MAX_SAFE_INTEGER : aBucketOrder) -
                (bBucketOrder === -1 ? Number.MAX_SAFE_INTEGER : bBucketOrder);
        }

        return a.name.localeCompare(b.name);
    });
};

export const fetchMcJarsVersions = async (type: string): Promise<McJarsVersion[]> => {
    const payload = await fetchMcJarsJson<{ success: boolean; versions: Record<string, Omit<McJarsVersion, 'id'>> }>(
        `/v1/builds/${encodeURIComponent(type)}`
    );

    const rawVersions = payload?.versions && typeof payload.versions === 'object' ? payload.versions : {};

    return Object.entries(rawVersions)
        .map(([id, value]) => ({
            id,
            type: typeof value.type === 'string' ? value.type : 'RELEASE',
            supported: !!value.supported,
            java: typeof value.java === 'number' ? value.java : 8,
            builds: typeof value.builds === 'number' ? value.builds : 0,
            created: typeof value.created === 'string' ? value.created : new Date(0).toISOString(),
            latest: value.latest as McJarsBuild,
        }))
        .sort((a, b) => {
            if (a.supported !== b.supported) {
                return Number(b.supported) - Number(a.supported);
            }

            return b.id.localeCompare(a.id, undefined, { numeric: true, sensitivity: 'base' });
        });
};

export const fetchMcJarsBuilds = async (type: string, version: string): Promise<McJarsBuild[]> => {
    const payload = await fetchMcJarsJson<{ success: boolean; builds: McJarsBuild[] }>(
        `/v1/builds/${encodeURIComponent(type)}/${encodeURIComponent(version)}`
    );
    const builds = Array.isArray(payload?.builds) ? payload.builds : [];

    return builds.slice().sort((a, b) => {
        if (a.buildNumber !== b.buildNumber) {
            return b.buildNumber - a.buildNumber;
        }

        const left = a.created ? new Date(a.created).getTime() : 0;
        const right = b.created ? new Date(b.created).getTime() : 0;
        return right - left;
    });
};

export const getBuildJarDownloadUrl = (build: McJarsBuild | null | undefined): string | null => {
    if (!build) {
        return null;
    }

    if (typeof build.jarUrl === 'string' && build.jarUrl.trim().length > 0) {
        return build.jarUrl;
    }

    const steps = Array.isArray(build.installation) ? build.installation : [];
    for (const group of steps) {
        if (!Array.isArray(group)) {
            continue;
        }

        for (const step of group) {
            if (!step || typeof step !== 'object' || (step as McJarsInstallationStep).type !== 'download') {
                continue;
            }

            const download = step as McJarsBuildDownloadStep;
            if (typeof download.url !== 'string' || download.url.trim().length === 0) {
                continue;
            }

            const file = typeof download.file === 'string' ? download.file.toLowerCase() : '';
            if (file.endsWith('.jar') || file === 'server.jar') {
                return download.url;
            }

            return download.url;
        }
    }

    return null;
};

export const suggestJarFileName = (type: string, version: string, build: McJarsBuild): string => {
    const downloadUrl = getBuildJarDownloadUrl(build);
    if (downloadUrl) {
        try {
            const parsed = new URL(downloadUrl);
            const pathname = decodeURIComponent(parsed.pathname);
            const maybeFile = pathname.split('/').filter(Boolean).pop();

            if (maybeFile && /\.jar$/i.test(maybeFile)) {
                return maybeFile;
            }
        } catch {
            // Ignore URL parsing issues and fallback to generated file name.
        }
    }

    const fallback = `${type.toLowerCase()}-${version}-${build.buildNumber}.jar`;
    return fallback.replace(/[^a-zA-Z0-9._-]/g, '-');
};
