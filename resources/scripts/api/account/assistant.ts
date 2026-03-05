import http from '@/api/http';

export type AssistantHistoryRole = 'user' | 'assistant';
export type AssistantProvider = 'openai' | 'groq' | 'gemini';
export type AssistantServerSource = 'local' | 'external';

export interface AssistantHistoryEntry {
    role: AssistantHistoryRole;
    content: string;
}

export interface AssistantServerContext {
    identifier: string;
    name?: string;
    uuid?: string;
    source?: AssistantServerSource;
    externalPanelName?: string;
    externalPanelUrl?: string;
    externalServerIdentifier?: string;
}

export interface AssistantRequestContext {
    routePath?: string;
    server?: AssistantServerContext;
}

interface AssistantResponse {
    object?: string;
    attributes?: {
        provider?: AssistantProvider;
        message?: string;
        model?: string;
    };
}

export const sendAssistantChat = async (
    message: string,
    history: AssistantHistoryEntry[] = [],
    provider?: AssistantProvider,
    context?: AssistantRequestContext
): Promise<{ message: string; model?: string; provider?: AssistantProvider }> => {
    const payload: Record<string, unknown> = {
        message,
        history,
    };

    if (provider) {
        payload.provider = provider;
    }

    if (context) {
        payload.context = context;
    }

    const { data } = await http.post<AssistantResponse>('/api/client/account/assistant/chat', payload);

    return {
        message: data?.attributes?.message || '',
        model: data?.attributes?.model,
        provider: data?.attributes?.provider,
    };
};
