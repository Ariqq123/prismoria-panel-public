import http from '@/api/http';
import type { ServerBackgroundPreference } from '@/api/server/getServerBackgroundPreference';

const normalizeOpacity = (value: unknown): number => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
        return 1;
    }

    return Math.max(0, Math.min(1, numeric));
};

export default async (serverId: string, file: File): Promise<ServerBackgroundPreference> => {
    const formData = new FormData();
    formData.append('server_id', serverId);
    formData.append('background_file', file);

    const { data } = await http.post('/extensions/serverbackgrounds/api/user-server-background/upload', formData);
    const attributes = data?.attributes || {};

    return {
        serverId: typeof attributes.server_id === 'string' ? attributes.server_id : serverId,
        imageUrl: typeof attributes.image_url === 'string' ? attributes.image_url : '',
        opacity: normalizeOpacity(attributes.opacity),
        isCustom: Boolean(attributes.is_custom),
    };
};
