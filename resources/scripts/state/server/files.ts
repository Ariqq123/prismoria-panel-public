import { action, Action } from 'easy-peasy';
import { cleanDirectoryPath } from '@/helpers';

export interface FileUploadData {
    loaded: number;
    readonly abort: AbortController;
    readonly total: number;
}

export interface ServerFileStore {
    directory: string;
    selectedFiles: string[];
    uploads: Record<string, FileUploadData>;
    isPreparingUpload: boolean;
    expectedUploadCount: number;
    expectedUploadBytes: number;
    completedUploadCount: number;
    completedUploadBytes: number;

    setDirectory: Action<ServerFileStore, string>;
    setSelectedFiles: Action<ServerFileStore, string[]>;
    appendSelectedFile: Action<ServerFileStore, string>;
    removeSelectedFile: Action<ServerFileStore, string>;

    startFileUploadBatch: Action<ServerFileStore, { expectedCount: number; expectedBytes: number }>;
    pushFileUpload: Action<ServerFileStore, { name: string; data: FileUploadData }>;
    setUploadProgress: Action<ServerFileStore, { name: string; loaded: number }>;
    clearFileUploads: Action<ServerFileStore>;
    completeFileUpload: Action<ServerFileStore, string>;
    removeFileUpload: Action<ServerFileStore, string>;
    cancelFileUpload: Action<ServerFileStore, string>;
    setPreparingUpload: Action<ServerFileStore, boolean>;
}

const files: ServerFileStore = {
    directory: '/',
    selectedFiles: [],
    uploads: {},
    isPreparingUpload: false,
    expectedUploadCount: 0,
    expectedUploadBytes: 0,
    completedUploadCount: 0,
    completedUploadBytes: 0,

    setDirectory: action((state, payload) => {
        state.directory = cleanDirectoryPath(payload);
    }),

    setSelectedFiles: action((state, payload) => {
        state.selectedFiles = payload;
    }),

    appendSelectedFile: action((state, payload) => {
        state.selectedFiles = state.selectedFiles.filter((f) => f !== payload).concat(payload);
    }),

    removeSelectedFile: action((state, payload) => {
        state.selectedFiles = state.selectedFiles.filter((f) => f !== payload);
    }),

    startFileUploadBatch: action((state, payload) => {
        const noActiveBatch = Object.keys(state.uploads).length < 1 && !state.isPreparingUpload;

        if (noActiveBatch) {
            state.expectedUploadCount = 0;
            state.expectedUploadBytes = 0;
            state.completedUploadCount = 0;
            state.completedUploadBytes = 0;
        }

        state.isPreparingUpload = true;
        state.expectedUploadCount += Math.max(0, payload.expectedCount);
        state.expectedUploadBytes += Math.max(0, payload.expectedBytes);
    }),

    clearFileUploads: action((state) => {
        Object.values(state.uploads).forEach((upload) => upload.abort.abort());

        state.uploads = {};
        state.isPreparingUpload = false;
        state.expectedUploadCount = 0;
        state.expectedUploadBytes = 0;
        state.completedUploadCount = 0;
        state.completedUploadBytes = 0;
    }),

    pushFileUpload: action((state, payload) => {
        state.uploads[payload.name] = payload.data;
    }),

    setUploadProgress: action((state, { name, loaded }) => {
        if (state.uploads[name]) {
            state.uploads[name].loaded = loaded;
        }
    }),

    completeFileUpload: action((state, payload) => {
        if (state.uploads[payload]) {
            const file = state.uploads[payload];
            delete state.uploads[payload];

            state.completedUploadCount += 1;
            state.completedUploadBytes += Math.max(0, file.total);
        }
    }),

    removeFileUpload: action((state, payload) => {
        if (state.uploads[payload]) {
            const file = state.uploads[payload];
            delete state.uploads[payload];

            state.expectedUploadCount = Math.max(0, state.expectedUploadCount - 1);
            state.expectedUploadBytes = Math.max(0, state.expectedUploadBytes - Math.max(0, file.total));
        }

        if (Object.keys(state.uploads).length < 1 && !state.isPreparingUpload) {
            state.completedUploadCount = Math.min(state.completedUploadCount, state.expectedUploadCount);
            state.completedUploadBytes = Math.min(state.completedUploadBytes, state.expectedUploadBytes);
        }
    }),

    cancelFileUpload: action((state, payload) => {
        if (state.uploads[payload]) {
            // Abort the request if it is still in flight. If it already completed this is
            // a no-op.
            const file = state.uploads[payload];
            file.abort.abort();

            delete state.uploads[payload];
            state.expectedUploadCount = Math.max(0, state.expectedUploadCount - 1);
            state.expectedUploadBytes = Math.max(0, state.expectedUploadBytes - Math.max(0, file.total));
            state.completedUploadCount = Math.min(state.completedUploadCount, state.expectedUploadCount);
            state.completedUploadBytes = Math.min(state.completedUploadBytes, state.expectedUploadBytes);
        }
    }),

    setPreparingUpload: action((state, payload) => {
        state.isPreparingUpload = payload;
    }),
};

export default files;
