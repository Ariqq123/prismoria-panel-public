import http from '@/api/http';

export interface ExternalPanelConnection {
    id: number;
    name: string | null;
    panelUrl: string;
    websocketOrigin: string | null;
    defaultConnection: boolean;
    status: 'connected' | 'disconnected';
    lastVerifiedAt: Date | null;
}

export interface ExternalPanelConnectionImportResult {
    total: number;
    imported: number;
    updated: number;
    skipped: number;
    errors: string[];
}

export interface ExternalPanelConnectionExportPayload {
    object: string;
    version: number;
    exported_at: string;
    source_panel: string;
    connections: Array<{
        name: string | null;
        panel_url: string;
        websocket_origin: string | null;
        default_connection: boolean;
        api_key: string;
    }>;
}

const rawDataToConnection = (data: any): ExternalPanelConnection => ({
    id: data.id,
    name: data.name || null,
    panelUrl: data.panel_url,
    websocketOrigin: data.websocket_origin || data.allowed_origin || null,
    defaultConnection: !!data.default_connection,
    status: data.status === 'connected' ? 'connected' : 'disconnected',
    lastVerifiedAt: data.last_verified_at ? new Date(data.last_verified_at) : null,
});

export const getExternalPanelConnections = async (): Promise<ExternalPanelConnection[]> => {
    const { data } = await http.get('/api/client/account/external-panels');

    return (data.data || []).map((item: any) => rawDataToConnection(item.attributes));
};

export const createExternalPanelConnection = async (payload: {
    name?: string;
    panelUrl: string;
    websocketOrigin?: string;
    apiKey: string;
    defaultConnection?: boolean;
}): Promise<ExternalPanelConnection> => {
    const { data } = await http.post('/api/client/account/external-panels', {
        name: payload.name,
        panel_url: payload.panelUrl,
        websocket_origin: payload.websocketOrigin,
        allowed_origin: payload.websocketOrigin,
        api_key: payload.apiKey,
        default_connection: !!payload.defaultConnection,
    });

    return rawDataToConnection(data.attributes);
};

export const updateExternalPanelConnection = async (
    id: number,
    payload: {
        name?: string;
        panelUrl: string;
        websocketOrigin?: string;
        apiKey?: string;
        defaultConnection?: boolean;
    }
): Promise<ExternalPanelConnection> => {
    const { data } = await http.patch(`/api/client/account/external-panels/${id}`, {
        name: payload.name,
        panel_url: payload.panelUrl,
        websocket_origin: payload.websocketOrigin,
        allowed_origin: payload.websocketOrigin,
        api_key: payload.apiKey,
        default_connection: !!payload.defaultConnection,
    });

    return rawDataToConnection(data.attributes);
};

export const verifyExternalPanelConnection = async (id: number): Promise<'connected' | 'disconnected'> => {
    const { data } = await http.post(`/api/client/account/external-panels/${id}/verify`);

    return data.attributes?.status === 'connected' ? 'connected' : 'disconnected';
};

export const deleteExternalPanelConnection = async (id: number): Promise<void> => {
    await http.delete(`/api/client/account/external-panels/${id}`);
};

const parseExportFilename = (headerValue?: string): string => {
    const fallback = 'external-panel-connections.json';
    if (!headerValue) {
        return fallback;
    }

    const encoded = headerValue.match(/filename\*=UTF-8''([^;]+)/i);
    if (encoded && encoded[1]) {
        try {
            return decodeURIComponent(encoded[1].trim());
        } catch (error) {
            return fallback;
        }
    }

    const basic = headerValue.match(/filename=\"?([^\";]+)\"?/i);
    if (basic && basic[1]) {
        return basic[1].trim();
    }

    return fallback;
};

export const exportExternalPanelConnections = async (): Promise<{ blob: Blob; filename: string }> => {
    const response = await http.get('/api/client/account/external-panels/export', {
        responseType: 'blob',
    });

    return {
        blob: response.data as Blob,
        filename: parseExportFilename(response.headers?.['content-disposition']),
    };
};

export const exportExternalPanelConnectionsPayload = async (): Promise<ExternalPanelConnectionExportPayload> => {
    const { data } = await http.get('/api/client/account/external-panels/export');

    return data as ExternalPanelConnectionExportPayload;
};

export const importExternalPanelConnections = async (file: File): Promise<ExternalPanelConnectionImportResult> => {
    const payload = new FormData();
    payload.append('import_file', file);

    const { data } = await http.post('/api/client/account/external-panels/import', payload, {
        headers: {
            'Content-Type': 'multipart/form-data',
        },
    });

    return {
        total: Number(data?.attributes?.total ?? 0),
        imported: Number(data?.attributes?.imported ?? 0),
        updated: Number(data?.attributes?.updated ?? 0),
        skipped: Number(data?.attributes?.skipped ?? 0),
        errors: Array.isArray(data?.attributes?.errors) ? data.attributes.errors : [],
    };
};
