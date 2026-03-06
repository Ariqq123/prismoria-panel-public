import axios, { AxiosProgressEvent } from 'axios';
import createDirectory from '@/api/server/files/createDirectory';
import getFileUploadUrl from '@/api/server/files/getFileUploadUrl';
import tw from 'twin.macro';
import { Button } from '@/components/elements/button/index';
import React, { useEffect, useRef, useState } from 'react';
import { ModalMask } from '@/components/elements/Modal';
import Fade from '@/components/elements/Fade';
import useEventListener from '@/plugins/useEventListener';
import { useFlashKey } from '@/plugins/useFlash';
import useFileManagerSwr from '@/plugins/useFileManagerSwr';
import { ServerContext } from '@/state/server';
import { WithClassname } from '@/components/types';
import Portal from '@/components/elements/Portal';
import { CloudUploadIcon } from '@heroicons/react/outline';
import { useSignal } from '@preact/signals-react';
import { Dialog } from '@/components/elements/dialog';

function isFileOrDirectory(event: DragEvent): boolean {
    if (!event.dataTransfer?.types) {
        return false;
    }

    return event.dataTransfer.types.some((value) => value.toLowerCase() === 'files');
}

const LOCAL_UPLOAD_CONCURRENCY = 6;
const EXTERNAL_UPLOAD_CONCURRENCY = 4;
const DIRECTORY_CREATE_CONCURRENCY = 4;
const MAX_REQUEST_ATTEMPTS = 5;
const MAX_UPLOAD_URL_ATTEMPTS = 3;

type UploadQueueItem = {
    file: File;
    relativePath: string;
};

type BrowserFileEntry = {
    isFile?: boolean;
    isDirectory?: boolean;
    name?: string;
    file?: (success: (file: File) => void, error?: (error: any) => void) => void;
    createReader?: () => {
        readEntries: (success: (entries: BrowserFileEntry[]) => void, error?: (error: any) => void) => void;
    };
};

const normalizePanelPath = (value: string): string => {
    const normalized = value.replace(/\\/g, '/').replace(/\/{2,}/g, '/').trim();
    if (normalized === '' || normalized === '/') {
        return '/';
    }

    const prefixed = normalized.startsWith('/') ? normalized : `/${normalized}`;

    return prefixed.endsWith('/') ? prefixed.slice(0, -1) : prefixed;
};

const sanitizeRelativePath = (value: string): string => {
    return value
        .replace(/\\/g, '/')
        .split('/')
        .map((segment) => segment.trim())
        .filter((segment) => segment.length > 0 && segment !== '.' && segment !== '..')
        .join('/');
};

const relativeDirectoryFromPath = (relativePath: string): string => {
    const index = relativePath.lastIndexOf('/');

    return index <= 0 ? '' : relativePath.slice(0, index);
};

const joinPanelPath = (base: string, relative: string): string => {
    const safeRelative = sanitizeRelativePath(relative);
    if (safeRelative === '') {
        return normalizePanelPath(base);
    }

    const normalizedBase = normalizePanelPath(base);
    if (normalizedBase === '/') {
        return `/${safeRelative}`;
    }

    return `${normalizedBase}/${safeRelative}`;
};

const splitPathForCreateDirectory = (fullPath: string): { root: string; name: string } | null => {
    const normalized = normalizePanelPath(fullPath);
    if (normalized === '/') {
        return null;
    }

    const segments = normalized.split('/').filter((segment) => segment.length > 0);
    const name = segments.pop();
    if (!name) {
        return null;
    }

    return {
        root: segments.length > 0 ? `/${segments.join('/')}` : '/',
        name,
    };
};

const shouldIgnoreCreateDirectoryError = (error: any): boolean => {
    const status = Number(error?.response?.status ?? 0);
    const detail = String(error?.response?.data?.errors?.[0]?.detail || error?.message || '').toLowerCase();

    return status === 409 || detail.includes('already') || detail.includes('exists');
};

const runUploadsWithConcurrency = async (tasks: Array<() => Promise<void>>, limit: number): Promise<void> => {
    if (tasks.length < 1) {
        return;
    }

    let index = 0;
    const worker = async () => {
        while (index < tasks.length) {
            const current = tasks[index];
            index += 1;
            if (!current) {
                continue;
            }

            await current();
        }
    };

    const workers = Array.from({ length: Math.min(limit, tasks.length) }, () => worker());
    await Promise.all(workers);
};

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

const responseStatus = (error: any): number => Number(error?.response?.status ?? 0);

const responseHeader = (error: any, name: string): string => {
    const headers = error?.response?.headers;
    if (!headers || typeof headers !== 'object') {
        return '';
    }

    const value = headers[name] ?? headers[name.toLowerCase()] ?? headers[name.toUpperCase()];

    return typeof value === 'string' ? value : '';
};

const retryAfterMs = (error: any): number | null => {
    const raw = responseHeader(error, 'retry-after').trim();
    if (!raw) {
        return null;
    }

    const seconds = Number(raw);
    if (Number.isFinite(seconds) && seconds >= 0) {
        return Math.min(10_000, Math.max(250, Math.round(seconds * 1000)));
    }

    const unixMs = Date.parse(raw);
    if (!Number.isNaN(unixMs)) {
        return Math.min(10_000, Math.max(250, unixMs - Date.now()));
    }

    return null;
};

const retryDelayMs = (error: any, attempt: number): number => {
    const retryAfter = retryAfterMs(error);
    if (retryAfter !== null) {
        return retryAfter;
    }

    const exponential = Math.min(6_000, 350 * 2 ** Math.max(0, attempt - 1));
    const jitter = Math.floor(Math.random() * 220);

    return exponential + jitter;
};

const shouldRetryRequest = (error: any): boolean => {
    const status = responseStatus(error);
    if (status === 429 || status === 408 || status === 425) {
        return true;
    }

    if (status >= 500 && status <= 504) {
        return true;
    }

    const code = String(error?.code ?? '').toUpperCase();

    return code === 'ECONNABORTED' || code === 'ERR_NETWORK' || code === 'ETIMEDOUT' || code === 'ECONNRESET';
};

const runWithRetry = async <T,>(
    fn: (attempt: number) => Promise<T>,
    {
        maxAttempts = MAX_REQUEST_ATTEMPTS,
        shouldRetry = shouldRetryRequest,
    }: {
        maxAttempts?: number;
        shouldRetry?: (error: any) => boolean;
    } = {}
): Promise<T> => {
    let attempt = 1;
    while (true) {
        try {
            return await fn(attempt);
        } catch (error: any) {
            if (attempt >= maxAttempts || !shouldRetry(error)) {
                throw error;
            }

            await sleep(retryDelayMs(error, attempt));
            attempt += 1;
        }
    }
};

const ensureFolderPickerAttributes = (input: HTMLInputElement | null): void => {
    if (!input) {
        return;
    }

    input.setAttribute('webkitdirectory', '');
    input.setAttribute('directory', '');

    const folderInput = input as HTMLInputElement & { webkitdirectory?: boolean; directory?: boolean };
    folderInput.webkitdirectory = true;
    folderInput.directory = true;
};

const supportsFolderPicker = (): boolean => {
    const input = document.createElement('input') as HTMLInputElement & { webkitdirectory?: boolean; directory?: boolean };

    return 'webkitdirectory' in input || 'directory' in input;
};

const joinRelativePath = (base: string, segment: string): string => {
    const safeBase = sanitizeRelativePath(base);
    const safeSegment = sanitizeRelativePath(segment);

    if (safeSegment === '') {
        return safeBase;
    }

    return safeBase === '' ? safeSegment : `${safeBase}/${safeSegment}`;
};

const flattenUploadItems = (groups: UploadQueueItem[][]): UploadQueueItem[] => {
    return groups.reduce((carry, items) => carry.concat(items), [] as UploadQueueItem[]);
};

const readDirectoryEntriesChunk = (
    reader: ReturnType<NonNullable<BrowserFileEntry['createReader']>>
): Promise<BrowserFileEntry[]> => {
    return new Promise((resolve, reject) => {
        reader.readEntries(
            (entries) => resolve(Array.isArray(entries) ? entries : []),
            (error) => reject(error)
        );
    });
};

const readAllDirectoryEntries = async (directoryEntry: BrowserFileEntry): Promise<BrowserFileEntry[]> => {
    if (typeof directoryEntry.createReader !== 'function') {
        return [];
    }

    const reader = directoryEntry.createReader();
    const entries: BrowserFileEntry[] = [];

    while (true) {
        const chunk = await readDirectoryEntriesChunk(reader);
        if (chunk.length < 1) {
            break;
        }

        entries.push(...chunk);
    }

    return entries;
};

const readFileFromEntry = (entry: BrowserFileEntry): Promise<File> => {
    return new Promise((resolve, reject) => {
        if (typeof entry.file !== 'function') {
            reject(new Error('File entry reader is not available.'));
            return;
        }

        entry.file(
            (file) => resolve(file),
            (error) => reject(error)
        );
    });
};

const collectUploadItemsFromEntry = async (entry: BrowserFileEntry, parentPath = ''): Promise<UploadQueueItem[]> => {
    const currentPath = joinRelativePath(parentPath, String(entry.name ?? ''));

    if (entry.isFile) {
        const file = await readFileFromEntry(entry);
        const fallbackPath = sanitizeRelativePath(file.webkitRelativePath || file.name);
        const relativePath = currentPath || fallbackPath;

        return relativePath === '' ? [] : [{ file, relativePath }];
    }

    if (!entry.isDirectory) {
        return [];
    }

    const children = await readAllDirectoryEntries(entry);
    const childGroups = await Promise.all(children.map((child) => collectUploadItemsFromEntry(child, currentPath)));

    return flattenUploadItems(childGroups);
};

const filesToUploadItems = (files: File[], useRelativePaths = false): UploadQueueItem[] => {
    return files
        .map((file) => {
            const candidate = useRelativePaths ? file.webkitRelativePath || file.name : file.name;
            const relativePath = sanitizeRelativePath(candidate);

            return relativePath === '' ? null : { file, relativePath };
        })
        .filter((item): item is UploadQueueItem => item !== null);
};

const collectDroppedUploadItems = async (dataTransfer: DataTransfer): Promise<UploadQueueItem[]> => {
    const rootEntries = Array.from(dataTransfer.items || [])
        .map((item) => {
            const getAsEntry = (item as DataTransferItem & { webkitGetAsEntry?: () => BrowserFileEntry | null })
                .webkitGetAsEntry;

            return typeof getAsEntry === 'function' ? getAsEntry.call(item) : null;
        })
        .filter((entry): entry is BrowserFileEntry => entry !== null);

    if (rootEntries.length > 0) {
        const grouped = await Promise.all(rootEntries.map((entry) => collectUploadItemsFromEntry(entry)));
        const flattened = flattenUploadItems(grouped);
        if (flattened.length > 0) {
            return flattened;
        }
    }

    return filesToUploadItems(Array.from(dataTransfer.files || []), true);
};

export default ({ className }: WithClassname) => {
    const fileUploadInput = useRef<HTMLInputElement>(null);
    const folderUploadInput = useRef<HTMLInputElement>(null);
    const uploadMenuRef = useRef<HTMLDivElement>(null);
    const [isUploadMenuOpen, setUploadMenuOpen] = useState(false);
    const [folderWarning, setFolderWarning] = useState<string | null>(null);

    const visible = useSignal(false);
    const timeouts = useSignal<NodeJS.Timeout[]>([]);

    const { mutate } = useFileManagerSwr();
    const { clearAndAddHttpError } = useFlashKey('files');

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const directory = ServerContext.useStoreState((state) => state.files.directory);
    const { clearFileUploads, completeFileUpload, pushFileUpload, setUploadProgress, setPreparingUpload, startFileUploadBatch } =
        ServerContext.useStoreActions((actions) => actions.files);

    useEventListener(
        'dragenter',
        (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (isFileOrDirectory(e)) {
                visible.value = true;
            }
        },
        { capture: true }
    );

    useEventListener('dragexit', () => (visible.value = false), { capture: true });

    useEventListener('keydown', () => (visible.value = false));

    useEffect(() => {
        return () => timeouts.value.forEach(clearTimeout);
    }, []);

    useEffect(() => {
        ensureFolderPickerAttributes(folderUploadInput.current);
    }, []);

    useEffect(() => {
        if (!isUploadMenuOpen) {
            return;
        }

        const onClickOutside = (event: MouseEvent) => {
            if (!uploadMenuRef.current?.contains(event.target as Node)) {
                setUploadMenuOpen(false);
            }
        };
        const onEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setUploadMenuOpen(false);
            }
        };

        document.addEventListener('mousedown', onClickOutside);
        document.addEventListener('keydown', onEscape);

        return () => {
            document.removeEventListener('mousedown', onClickOutside);
            document.removeEventListener('keydown', onEscape);
        };
    }, [isUploadMenuOpen]);

    const onUploadProgress = (data: AxiosProgressEvent, name: string) => {
        setUploadProgress({ name, loaded: Number(data.loaded ?? 0) });
    };

    const ensureUploadDirectories = async (queueItems: UploadQueueItem[], useRelativePaths: boolean) => {
        if (!useRelativePaths) {
            return;
        }

        const directories = new Set<string>();
        queueItems.forEach((item) => {
            const relativePath = sanitizeRelativePath(item.relativePath);
            const relativeDirectory = relativeDirectoryFromPath(relativePath);
            if (relativeDirectory.length > 0) {
                directories.add(joinPanelPath(directory, relativeDirectory));
            }
        });

        const directoriesByDepth = new Map<number, string[]>();
        directories.forEach((absoluteDirectory) => {
            const depth = absoluteDirectory.split('/').filter((segment) => segment.length > 0).length;
            const list = directoriesByDepth.get(depth) ?? [];
            list.push(absoluteDirectory);
            directoriesByDepth.set(depth, list);
        });

        const orderedDepths = Array.from(directoriesByDepth.keys()).sort((a, b) => a - b);
        for (const depth of orderedDepths) {
            const depthDirectories = directoriesByDepth.get(depth) ?? [];
            const tasks = depthDirectories.map((absoluteDirectory) => async () => {
                const parts = splitPathForCreateDirectory(absoluteDirectory);
                if (!parts) {
                    return;
                }

                try {
                    await runWithRetry(() => createDirectory(uuid, parts.root, parts.name));
                } catch (error: any) {
                    if (!shouldIgnoreCreateDirectoryError(error)) {
                        throw error;
                    }
                }
            });

            await runUploadsWithConcurrency(tasks, DIRECTORY_CREATE_CONCURRENCY);
        }
    };

    const onFileSubmission = (queueItems: UploadQueueItem[], useRelativePaths = false) => {
        clearAndAddHttpError();
        const list = queueItems.filter((item) => item.relativePath.length > 0);
        if (list.length < 1) {
            return;
        }
        startFileUploadBatch({
            expectedCount: list.length,
            expectedBytes: list.reduce((sum, item) => sum + Math.max(0, item.file.size), 0),
        });

        const isExternalServer = uuid.startsWith('external:');
        const uploadConcurrency = isExternalServer ? EXTERNAL_UPLOAD_CONCURRENCY : LOCAL_UPLOAD_CONCURRENCY;
        let cachedUploadUrl: string | null = null;
        let inflightUploadUrlPromise: Promise<string> | null = null;

        const getUploadUrl = (forceRefresh = false): Promise<string> => {
            if (!forceRefresh && cachedUploadUrl) {
                return Promise.resolve(cachedUploadUrl);
            }

            if (!forceRefresh && inflightUploadUrlPromise) {
                return inflightUploadUrlPromise;
            }

            const request = runWithRetry(() => getFileUploadUrl(uuid), { maxAttempts: MAX_UPLOAD_URL_ATTEMPTS })
                .then((url) => {
                    cachedUploadUrl = url;

                    return url;
                })
                .finally(() => {
                    if (inflightUploadUrlPromise === request) {
                        inflightUploadUrlPromise = null;
                    }
                });

            inflightUploadUrlPromise = request;

            return request;
        };

        ensureUploadDirectories(list, useRelativePaths)
            .then(() => {
                setPreparingUpload(false);
                const uploads = list.map((item) => {
                    const file = item.file;
                    const controller = new AbortController();
                    const relativePath = sanitizeRelativePath(item.relativePath);
                    const uploadName = useRelativePaths && relativePath.length > 0 ? relativePath : file.name;
                    const uploadDirectory =
                        useRelativePaths && relativePath.length > 0
                            ? joinPanelPath(directory, relativeDirectoryFromPath(relativePath))
                            : directory;

                    pushFileUpload({
                        name: uploadName,
                        data: { abort: controller, loaded: 0, total: file.size },
                    });

                    return () =>
                        runWithRetry(
                            async (attempt) => {
                                const shouldRefreshUploadUrl = attempt > 1;
                                const url = await getUploadUrl(shouldRefreshUploadUrl);

                                await axios.post(
                                    url,
                                    { files: file },
                                    {
                                        signal: controller.signal,
                                        headers: { 'Content-Type': 'multipart/form-data' },
                                        params: { directory: uploadDirectory },
                                        onUploadProgress: (data) => onUploadProgress(data, uploadName),
                                        timeout: 120000,
                                    }
                                );
                            },
                            {
                                maxAttempts: MAX_REQUEST_ATTEMPTS,
                                shouldRetry: (error) => {
                                    if (controller.signal.aborted) {
                                        return false;
                                    }

                                    return shouldRetryRequest(error) || [401, 403].includes(responseStatus(error));
                                },
                            }
                        ).then(() => {
                            timeouts.value.push(setTimeout(() => completeFileUpload(uploadName), 500));
                        });
                });

                return runUploadsWithConcurrency(uploads, uploadConcurrency);
            })
            .then(() => mutate())
            .catch((error) => {
                setPreparingUpload(false);
                clearFileUploads();
                clearAndAddHttpError(error);
            });
    };

    return (
        <>
            <Portal>
                <Fade appear in={visible.value} timeout={75} key={'upload_modal_mask'} unmountOnExit>
                    <ModalMask
                        onClick={() => (visible.value = false)}
                        onDragOver={(e) => e.preventDefault()}
                        onDrop={(e) => {
                            e.preventDefault();
                            e.stopPropagation();

                            visible.value = false;
                            if (!e.dataTransfer?.files.length) return;
                            void collectDroppedUploadItems(e.dataTransfer)
                                .then((items) => {
                                    if (items.length < 1) {
                                        setFolderWarning(
                                            'Unable to read dropped folders/files in this browser. Use Upload Folder instead.'
                                        );
                                        return;
                                    }

                                    onFileSubmission(items, true);
                                })
                                .catch(() => {
                                    setFolderWarning(
                                        'Unable to read dropped folders/files in this browser. Use Upload Folder instead.'
                                    );
                                });
                        }}
                    >
                        <div className={'w-full flex items-center justify-center pointer-events-none'}>
                            <div
                                className={
                                    'flex items-center space-x-4 w-full ring-4 ring-blue-200 ring-opacity-60 rounded p-6 mx-10 max-w-sm'
                                }
                                style={{ background: 'var(--panel-surface-1)', border: '1px solid var(--panel-border)' }}
                            >
                                <CloudUploadIcon className={'w-10 h-10 flex-shrink-0'} />
                                <p className={'font-header flex-1 text-lg text-center'} style={{ color: 'var(--panel-text)' }}>
                                    Drag and drop files to upload.
                                </p>
                            </div>
                        </div>
                    </ModalMask>
                </Fade>
            </Portal>
            <Dialog open={folderWarning !== null} onClose={() => setFolderWarning(null)} title={'Folder Upload Warning'}>
                <Dialog.Icon type={'warning'} />
                <p className={'mt-3 mb-4 text-sm'} style={{ color: 'var(--panel-text)' }}>
                    {folderWarning}
                </p>
                <Dialog.Footer>
                    <Button onClick={() => setFolderWarning(null)}>Okay</Button>
                </Dialog.Footer>
            </Dialog>
            <input
                type={'file'}
                ref={fileUploadInput}
                css={tw`hidden`}
                onChange={(e) => {
                    if (!e.currentTarget.files) return;

                    onFileSubmission(filesToUploadItems(Array.from(e.currentTarget.files)));
                    if (fileUploadInput.current) {
                        fileUploadInput.current.files = null;
                    }
                }}
                multiple
            />
            <input
                type={'file'}
                ref={folderUploadInput}
                css={tw`hidden`}
                onChange={(e) => {
                    if (!e.currentTarget.files) return;

                    onFileSubmission(filesToUploadItems(Array.from(e.currentTarget.files), true), true);
                    if (folderUploadInput.current) {
                        folderUploadInput.current.files = null;
                    }
                }}
                multiple
            />
            <div className={'relative'} ref={uploadMenuRef}>
                <Button className={className} onClick={() => setUploadMenuOpen((open) => !open)}>
                    Upload
                </Button>
                {isUploadMenuOpen && (
                    <div className={'absolute right-0 mt-2 w-44 rounded-md border border-neutral-700 bg-neutral-900 shadow-lg z-50'}>
                        <button
                            type={'button'}
                            className={'block w-full px-3 py-2 text-left text-sm text-neutral-100 hover:bg-neutral-800'}
                            onClick={() => {
                                setUploadMenuOpen(false);
                                fileUploadInput.current?.click();
                            }}
                        >
                            Upload Files
                        </button>
                        <button
                            type={'button'}
                            className={'block w-full px-3 py-2 text-left text-sm text-neutral-100 hover:bg-neutral-800'}
                            onClick={() => {
                                setUploadMenuOpen(false);
                                if (!supportsFolderPicker()) {
                                    setFolderWarning(
                                        'This browser does not support selecting directories. Please switch to a Chromium-based browser.'
                                    );
                                    return;
                                }
                                ensureFolderPickerAttributes(folderUploadInput.current);
                                folderUploadInput.current?.click();
                            }}
                        >
                            Upload Folder(s)
                        </button>
                    </div>
                )}
            </div>
        </>
    );
};
