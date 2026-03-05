import http from '@/api/http';

export type AutoBackupDestinationType = 'google_drive' | 's3' | 'dropbox';

export interface AutoBackupProfile {
    id: number;
    serverIdentifier: string;
    name: string | null;
    destinationType: AutoBackupDestinationType;
    destinationConfig: Record<string, unknown>;
    isEnabled: boolean;
    intervalMinutes: number;
    keepRemote: number;
    isLocked: boolean;
    ignoredFiles: string;
    pendingBackupUuid: string | null;
    lastBackupUuid: string | null;
    lastStatus: string | null;
    lastError: string | null;
    lastRunAt: string | null;
    nextRunAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
}

export interface AutoBackupClientDefaults {
    enabled: boolean;
    allowUserDestinationOverride: boolean;
    defaultDestinationType: AutoBackupDestinationType;
    defaultIntervalMinutes: number;
    defaultKeepRemote: number;
}

export interface AutoBackupProfilesResponse {
    profiles: AutoBackupProfile[];
    defaults: AutoBackupClientDefaults;
}

export interface AutoBackupPayload {
    name?: string;
    destination_type: AutoBackupDestinationType;
    destination_config: Record<string, unknown>;
    is_enabled?: boolean;
    interval_minutes?: number;
    keep_remote?: number;
    is_locked?: boolean;
    ignored_files?: string;
}

const transform = (item: any): AutoBackupProfile => {
    const attributes = item?.attributes || {};

    return {
        id: Number(attributes.id),
        serverIdentifier: String(attributes.server_identifier || ''),
        name: attributes.name || null,
        destinationType: attributes.destination_type as AutoBackupDestinationType,
        destinationConfig: attributes.destination_config || {},
        isEnabled: Boolean(attributes.is_enabled),
        intervalMinutes: Number(attributes.interval_minutes || 360),
        keepRemote: Number(attributes.keep_remote || 10),
        isLocked: Boolean(attributes.is_locked),
        ignoredFiles: String(attributes.ignored_files || ''),
        pendingBackupUuid: attributes.pending_backup_uuid || null,
        lastBackupUuid: attributes.last_backup_uuid || null,
        lastStatus: attributes.last_status || null,
        lastError: attributes.last_error || null,
        lastRunAt: attributes.last_run_at || null,
        nextRunAt: attributes.next_run_at || null,
        createdAt: attributes.created_at || null,
        updatedAt: attributes.updated_at || null,
    };
};

const transformDefaults = (data: any): AutoBackupClientDefaults => {
    const defaults = data?.meta?.defaults || {};
    const destination = String(defaults.default_destination_type || 'google_drive') as AutoBackupDestinationType;
    const safeDestination: AutoBackupDestinationType = ['google_drive', 's3', 'dropbox'].includes(destination)
        ? destination
        : 'google_drive';

    return {
        enabled: defaults.enabled !== false,
        allowUserDestinationOverride: defaults.allow_user_destination_override !== false,
        defaultDestinationType: safeDestination,
        defaultIntervalMinutes: Math.min(10080, Math.max(5, Number(defaults.default_interval_minutes || 360))),
        defaultKeepRemote: Math.min(1000, Math.max(1, Number(defaults.default_keep_remote || 10))),
    };
};

export const getAutoBackupProfiles = async (uuid: string): Promise<AutoBackupProfilesResponse> => {
    const { data } = await http.get(`/api/client/servers/${uuid}/auto-backups`);
    const items = Array.isArray(data?.data) ? data.data : [];

    return {
        profiles: items.map((item: any) => transform(item)),
        defaults: transformDefaults(data),
    };
};

export const createAutoBackupProfile = async (uuid: string, payload: AutoBackupPayload): Promise<AutoBackupProfile> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/auto-backups`, payload);

    return transform(data);
};

export const updateAutoBackupProfile = async (
    uuid: string,
    profileId: number,
    payload: AutoBackupPayload
): Promise<AutoBackupProfile> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/auto-backups/${profileId}`, payload);

    return transform(data);
};

export const runAutoBackupProfile = async (uuid: string, profileId: number): Promise<AutoBackupProfile> => {
    const { data } = await http.post(`/api/client/servers/${uuid}/auto-backups/${profileId}/run`);

    return transform(data);
};

export const deleteAutoBackupProfile = async (uuid: string, profileId: number): Promise<void> => {
    await http.delete(`/api/client/servers/${uuid}/auto-backups/${profileId}`);
};
