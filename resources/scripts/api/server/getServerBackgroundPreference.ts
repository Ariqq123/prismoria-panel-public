import http from '@/api/http';

export interface ServerBackgroundPreference {
    serverId: string;
    imageUrl: string;
    opacity: number;
    isCustom: boolean;
}

const normalizeOpacity = (value: unknown): number => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
        return 1;
    }

    return Math.max(0, Math.min(1, numeric));
};

export default async (serverId: string): Promise<ServerBackgroundPreference> => {
    const { data } = await http.get('/extensions/serverbackgrounds/api/user-server-background', {
        params: { server_id: serverId },
    });

    const attributes = data?.attributes || {};

    return {
        serverId: typeof attributes.server_id === 'string' ? attributes.server_id : serverId,
        imageUrl: typeof attributes.image_url === 'string' ? attributes.image_url : '',
        opacity: normalizeOpacity(attributes.opacity),
        isCustom: Boolean(attributes.is_custom),
    };
};
