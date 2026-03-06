import React, { CSSProperties, useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import { Actions, useStoreActions } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import FlashMessageRender from '@/components/FlashMessageRender';
import Spinner from '@/components/elements/Spinner';
import CopyOnClick from '@/components/elements/CopyOnClick';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import tw from 'twin.macro';
import axios from 'axios';

const LogSectionNames = ['ERROR', 'WARN', 'INFO', 'OTHER'] as const;
type LogSection = (typeof LogSectionNames)[number];

interface McLogEntry {
    id: string;
    url: string;
    /** ISO timestamp for when the upload was created (client-side). */
    uploadedAt: string;
}

interface InsightsData {
    version: string;
    name: string;
    analysis: {
        problems: Array<{
            message: string;
            solutions?: Array<{ message: string }>;
        }>;
    };
}

const CARD_STYLE: CSSProperties = {
    borderColor: 'var(--panel-border)',
    background: 'var(--panel-surface-2)',
};

const SUBCARD_STYLE: CSSProperties = {
    borderColor: 'var(--panel-border)',
    background: 'var(--panel-surface-3)',
};

const NEUTRAL_BUTTON_STYLE: CSSProperties = {
    borderColor: 'var(--panel-border)',
    background: 'var(--panel-surface-3)',
    color: 'var(--panel-text)',
};

const HEADING_STYLE: CSSProperties = {
    color: 'var(--panel-heading)',
};

const TEXT_STYLE: CSSProperties = {
    color: 'var(--panel-text)',
};

const MUTED_TEXT_STYLE: CSSProperties = {
    color: 'var(--panel-text-muted)',
};

/**
 * Blueprint server route page for the MC Logs addon.
 *
 * - Lists `/logs` using the Pterodactyl client API.
 * - Uploads selected logs to mclo.gs and stores returned ids/urls in browser localStorage.
 * - Fetches and renders mclo.gs `raw` and `insights` data for a selected upload.
 *
 * Privacy: uploading sends the full log contents to a third-party service.
 */
const LogsPage: React.FC = () => {
    const [collapsed, setCollapsed] = useState<Record<LogSection, boolean>>({
        ERROR: false,
        WARN: false,
        INFO: true,
        OTHER: true,
    });
    const [showOriginal, setShowOriginal] = useState(false);


    const [logs, setLogs] = useState<string[]>([]);
    const [mclogsUrls, setMclogsUrls] = useState<McLogEntry[]>([]);
    const [lastUploadedLog, setLastUploadedLog] = useState<McLogEntry | null>(null);
    const [loading, setLoading] = useState(false);
    const [selectedLogData, setSelectedLogData] = useState<string | null>(null);
    const [insightsData, setInsightsData] = useState<InsightsData | null>(null);
    const [historyVisible, setHistoryVisible] = useState(false);
    const [showModal, setShowModal] = useState(false);

    const [currentLogsPage, setCurrentLogsPage] = useState<number>(1);
    const [currentHistoryPage, setCurrentHistoryPage] = useState<number>(1);
    const [logsPerPage, setLogsPerPage] = useState<number>(5);
    const maxPageButtons = 5;

    const [sortOrder, setSortOrder] = useState<'newest' | 'oldest'>('newest');
    const [logSortOrder, setLogSortOrder] = useState<'newest' | 'oldest'>('newest');

    const { uuid } = ServerContext.useStoreState((state) => state.server.data!);
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    const clearFlashes = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes.clearFlashes);
    const addError = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes.addError);

    // Note: this sorts by filename (lexicographic), not file timestamps.
    const sortedLogs = React.useMemo(() => {
        const sorted = [...logs].sort();
        return logSortOrder === 'newest' ? sorted.reverse() : sorted;
    }, [logs, logSortOrder]);

    const paginatedLogs = sortedLogs.slice((currentLogsPage - 1) * logsPerPage, currentLogsPage * logsPerPage);
    const paginatedHistory = mclogsUrls.slice((currentHistoryPage - 1) * logsPerPage, currentHistoryPage * logsPerPage);

    const totalLogsPages = Math.ceil(sortedLogs.length / logsPerPage);
    const totalHistoryPages = Math.ceil(mclogsUrls.length / logsPerPage);

    const handleLogsPageChange = (pageNumber: number) => {
        setCurrentLogsPage(pageNumber);
    };

    const handleHistoryPageChange = (pageNumber: number) => {
        setCurrentHistoryPage(pageNumber);
    };

    const handleLogsPerPageChange = (newPerPage: number) => {
        setLogsPerPage(newPerPage);
        setCurrentLogsPage(1);
        setCurrentHistoryPage(1);
    };

    const saveToLocalStorage = (data: McLogEntry) => {
        const storedData = JSON.parse(localStorage.getItem(`${uuid}_mclogs`) || '[]');
        storedData.push(data);
        localStorage.setItem(`${uuid}_mclogs`, JSON.stringify(storedData));
    };

    const loadFromLocalStorage = () => {
        const storedData: McLogEntry[] = JSON.parse(localStorage.getItem(`${uuid}_mclogs`) || '[]');
        setMclogsUrls(storedData);
    };

    const removeFromLocalStorage = (id: string) => {
        const storedData: McLogEntry[] = JSON.parse(localStorage.getItem(`${uuid}_mclogs`) || '[]');
        const updatedData = storedData.filter((entry) => entry.id !== id);
        localStorage.setItem(`${uuid}_mclogs`, JSON.stringify(updatedData));
        setMclogsUrls(updatedData);
    };

    const clearAllLogs = () => {
        localStorage.removeItem(`${uuid}_mclogs`);
        setMclogsUrls([]);
        setShowModal(false);
    };

    const fetchLogs = async () => {
        clearFlashes('logs');
        setLoading(true);
        try {
            const response = await axios.get(`/api/client/servers/${uuid}/files/list?directory=/logs`, {
                headers: { 'X-CSRF-TOKEN': csrfToken ?? '' },
            });

            const files = response.data.data.map((file: { attributes: { name: string } }) => file.attributes.name);
            setLogs(files);
        } catch (error) {
            console.error('Error fetching logs:', error);
            addError({ key: 'logs', message: 'Failed to fetch logs. Please try again later.' });
        } finally {
            setLoading(false);
        }
    };

    const handleUploadToMclogs = async (fileName: string) => {
        clearFlashes('logs');
        setLoading(true);

        try {
            let logData: string;

            // For `.gz` logs we decompress server-side first, then read the temporary plain-text file.
            if (fileName.endsWith('.gz')) {
                const decompressResponse = await axios.post(
                    `/api/client/servers/${uuid}/files/decompress`,
                    { root: '/logs', file: fileName },
                    { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } }
                );

                if (decompressResponse.status === 204) {
                    const decompressedFileName = fileName.replace('.gz', '');

                    const fileContentResponse = await axios.get(
                        `/api/client/servers/${uuid}/files/contents?file=/logs/${decompressedFileName}`,
                        { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } }
                    );

                    logData = fileContentResponse.data;

                    // Avoid leaving a decompressed copy behind on disk.
                    await axios.post(
                        `/api/client/servers/${uuid}/files/delete`,
                        { root: '/logs', files: [decompressedFileName] },
                        { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } }
                    );
                } else {
                    throw new Error('Failed to decompress the file.');
                }
            } else {
                const fileContentResponse = await axios.get(
                    `/api/client/servers/${uuid}/files/contents?file=/logs/${fileName}`,
                    { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } }
                );

                logData = fileContentResponse.data;
            }

            const formData = new URLSearchParams();
            // Prefix the paste with the filename so the mclo.gs page has context.
            formData.append('content', `// Log file: ${fileName}\n\n${logData}`);

            const uploadResponse = await axios.post('https://api.mclo.gs/1/log', formData, {
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            });

            if (uploadResponse.data.success && uploadResponse.data.url) {
                const newLog = {
                    id: uploadResponse.data.id,
                    url: uploadResponse.data.url,
                    uploadedAt: new Date().toISOString(),
                };
                setMclogsUrls((prev) => [newLog, ...prev]);
                setLastUploadedLog(newLog);
                saveToLocalStorage(newLog);

                await fetchMclogsData(uploadResponse.data.id);
            } else {
                addError({ key: 'logs', message: 'Failed to upload logs to MCLogs.' });
            }
        } catch (error) {
            console.error('Error uploading logs:', error);
            addError({ key: 'logs', message: 'An error occurred while uploading logs. Please try again later.' });
        } finally {
            setLoading(false);
        }
    };

    const getServerImageUrl = (serverName: string) => {
        const normalized = (serverName || '').toLowerCase();
        const keywordMap: Array<[string, string]> = [
            ['arclight', '/extensions/mclogs/versions/arclight.png'],
            ['bungeecord', '/extensions/mclogs/versions/bungeecord.png'],
            ['canvas', '/extensions/mclogs/versions/canvas.png'],
            ['fabric', '/extensions/mclogs/versions/fabric.png'],
            ['folia', '/extensions/mclogs/versions/folia.png'],
            ['forge', '/extensions/mclogs/versions/forge.png'],
            ['leaves', '/extensions/mclogs/versions/leaves.png'],
            ['mohist', '/extensions/mclogs/versions/mohist.png'],
            ['neoforge', '/extensions/mclogs/versions/neoforge.png'],
            ['paper', '/extensions/mclogs/versions/paper.png'],
            ['papermc', '/extensions/mclogs/versions/paper.png'],
            ['pufferfish', '/extensions/mclogs/versions/pufferfish.png'],
            ['purpur', '/extensions/mclogs/versions/purpur.png'],
            ['quilt', '/extensions/mclogs/versions/quilt.png'],
            ['sponge', '/extensions/mclogs/versions/sponge.png'],
            ['velocity', '/extensions/mclogs/versions/velocity.png'],
            ['waterfall', '/extensions/mclogs/versions/waterfall.png'],
            ['vanilla', '/extensions/mclogs/versions/vanilla.png'],
        ];

        const matched = keywordMap.find(([keyword]) => normalized.includes(keyword));
        return matched ? matched[1] : '/extensions/mclogs/versions/vanilla.png';
    };

    const fetchMclogsData = async (id: string) => {
        clearFlashes('logs');
        setLoading(true);
        try {
            const rawResponse = await axios.get(`https://api.mclo.gs/1/raw/${id}`);
            const insightsResponse = await axios.get(`https://api.mclo.gs/1/insights/${id}`);

            setSelectedLogData(rawResponse.data);
            setInsightsData(insightsResponse.data);
        } catch (error) {
            console.error('Error fetching MCLogs data:', error);
            addError({ key: 'logs', message: 'Failed to fetch MCLogs data. Please try again later.' });
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs();
        loadFromLocalStorage();
    }, []);

    useEffect(() => {
        setMclogsUrls((prev) => {
            const sorted = [...prev].sort((a, b) => {
                const aTime = new Date(a.uploadedAt).getTime();
                const bTime = new Date(b.uploadedAt).getTime();
                return sortOrder === 'newest' ? bTime - aTime : aTime - bTime;
            });
            return sorted;
        });
    }, [sortOrder]);

    const renderPaginationControls = (
        currentPage: number,
        totalPages: number,
        onPageChange: (page: number) => void
    ) => {
        if (totalPages <= 1) return null;

        let startPage = Math.max(1, currentPage - Math.floor(maxPageButtons / 2));
        let endPage = startPage + maxPageButtons - 1;

        if (endPage > totalPages) {
            endPage = totalPages;
            startPage = Math.max(1, endPage - maxPageButtons + 1);
        }

        const pageNumbers = [];
        for (let i = startPage; i <= endPage; i++) {
            pageNumbers.push(i);
        }

        return (
            <div css={tw`flex justify-center items-center mt-4 space-x-1`}>
                {currentPage > 1 && (
                    <>
                        <button
                            css={tw`px-3 py-1 rounded border transition-colors duration-150`}
                            style={NEUTRAL_BUTTON_STYLE}
                            onClick={() => onPageChange(1)}
                        >
                            First
                        </button>
                        <button
                            css={tw`px-3 py-1 rounded border transition-colors duration-150`}
                            style={NEUTRAL_BUTTON_STYLE}
                            onClick={() => onPageChange(currentPage - 1)}
                        >
                            Prev
                        </button>
                    </>
                )}
                {pageNumbers.map((page) => (
                    <button
                        key={page}
                        css={[
                            tw`px-3 py-1 rounded border transition-colors duration-150`,
                            currentPage === page && tw`bg-primary-500 border-primary-600 text-primary-50`,
                        ]}
                        style={currentPage === page ? undefined : NEUTRAL_BUTTON_STYLE}
                        onClick={() => onPageChange(page)}
                    >
                        {page}
                    </button>
                ))}
                {currentPage < totalPages && (
                    <>
                        <button
                            css={tw`px-3 py-1 rounded border transition-colors duration-150`}
                            style={NEUTRAL_BUTTON_STYLE}
                            onClick={() => onPageChange(currentPage + 1)}
                        >
                            Next
                        </button>
                        <button
                            css={tw`px-3 py-1 rounded border transition-colors duration-150`}
                            style={NEUTRAL_BUTTON_STYLE}
                            onClick={() => onPageChange(totalPages)}
                        >
                            Last
                        </button>
                    </>
                )}
            </div>
        );
    };

    if (loading) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <ServerContentBlock title={'MC Logs'} css={tw`space-y-4`}>
            <div css={tw`w-full max-w-6xl mx-auto space-y-4`}>
                <FlashMessageRender byKey={'logs'} css={tw`mb-4`} />
                {lastUploadedLog && (
                    <div
                        css={tw`rounded-lg border shadow-md px-4 py-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between`}
                        style={CARD_STYLE}
                    >
                        <p css={tw`text-sm break-all`} style={TEXT_STYLE}>
                            Uploaded to mclo.gs:{' '}
                            <a
                                href={lastUploadedLog.url}
                                target={'_blank'}
                                rel={'noopener noreferrer'}
                                css={tw`text-primary-400 hover:underline`}
                            >
                                {lastUploadedLog.url}
                            </a>
                        </p>
                        <CopyOnClick text={lastUploadedLog.url}>
                            <button
                                css={tw`text-sm px-4 py-2 rounded border transition-colors duration-150`}
                                style={NEUTRAL_BUTTON_STYLE}
                            >
                                Copy Link
                            </button>
                        </CopyOnClick>
                    </div>
                )}
                <div css={tw`rounded-lg border shadow-md px-4 py-4 md:px-5`} style={CARD_STYLE}>
                    <div css={tw`flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-2`}>
                        <h3 css={tw`text-lg`} style={HEADING_STYLE}>
                            Available Logs
                        </h3>
                        <div css={tw`flex flex-wrap items-center gap-2`}>
                            <select
                                css={tw`text-sm px-4 py-2 rounded border`}
                                style={NEUTRAL_BUTTON_STYLE}
                                value={logsPerPage}
                                onChange={(e) => handleLogsPerPageChange(Number(e.target.value))}
                            >
                                <option value={5}>5 per page</option>
                                <option value={10}>10 per page</option>
                                <option value={15}>15 per page</option>
                                <option value={20}>20 per page</option>
                            </select>
                            <select
                                css={tw`text-sm px-4 py-2 rounded border`}
                                style={NEUTRAL_BUTTON_STYLE}
                                value={logSortOrder}
                                onChange={(e) => setLogSortOrder(e.target.value as 'newest' | 'oldest')}
                            >
                                <option value="newest">Newest to Oldest</option>
                                <option value="oldest">Oldest to Newest</option>
                            </select>
                        </div>
                    </div>
                    {!sortedLogs.length ? (
                        <p css={tw`text-sm`} style={MUTED_TEXT_STYLE}>
                            No logs found in the server directory. Are you running a Minecraft Server?
                        </p>
                    ) : (
                        <>
                            <div css={tw`divide-y`} style={{ borderColor: 'var(--panel-border)' }}>
                                {paginatedLogs.map((logFile) => (
                                    <div key={logFile} css={tw`flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between`}>
                                        <span css={tw`text-sm break-all`} style={TEXT_STYLE}>
                                            {logFile}
                                        </span>
                                        <button
                                            css={tw`text-sm px-4 py-2 rounded border border-primary-600 bg-primary-500 text-primary-50 hover:bg-primary-600`}
                                            onClick={() => handleUploadToMclogs(logFile)}
                                        >
                                            Upload to MCLogs
                                        </button>
                                    </div>
                                ))}
                            </div>
                            {renderPaginationControls(currentLogsPage, totalLogsPages, handleLogsPageChange)}
                        </>
                    )}
                </div>

                <div>
                <button
                    css={tw`text-sm px-4 py-2 rounded border transition-colors duration-150`}
                    style={NEUTRAL_BUTTON_STYLE}
                    onClick={() => setHistoryVisible(!historyVisible)}
                >
                    {historyVisible ? 'Hide MCLogs History' : 'Show MCLogs History'}
                </button>
                {historyVisible && (
                    <div css={tw`rounded-lg border shadow-md px-4 py-4 md:px-5 mt-4`} style={CARD_STYLE}>
                        <div css={tw`flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-4`}>
                            <h3 css={tw`text-lg`} style={HEADING_STYLE}>
                                MCLogs History
                            </h3>
                            <div css={tw`flex flex-wrap items-center gap-2`}>
                                <button
                                    css={tw`text-sm bg-red-500 border border-red-600 text-red-100 px-4 py-2 rounded hover:bg-red-600`}
                                    onClick={() => setShowModal(true)}
                                >
                                    Delete All History
                                </button>
                                <select
                                    css={tw`text-sm px-4 py-2 rounded border`}
                                    style={NEUTRAL_BUTTON_STYLE}
                                    value={logsPerPage}
                                    onChange={(e) => handleLogsPerPageChange(Number(e.target.value))}
                                >
                                    <option value={5}>5 per page</option>
                                    <option value={10}>10 per page</option>
                                    <option value={15}>15 per page</option>
                                    <option value={20}>20 per page</option>
                                </select>
                                <select
                                    css={tw`text-sm px-4 py-2 rounded border`}
                                    style={NEUTRAL_BUTTON_STYLE}
                                    value={sortOrder}
                                    onChange={(e) => setSortOrder(e.target.value as 'newest' | 'oldest')}
                                >
                                    <option value="newest">Newest to Oldest</option>
                                    <option value="oldest">Oldest to Newest</option>
                                </select>
                            </div>
                        </div>
                        {!mclogsUrls.length ? (
                            <p css={tw`text-sm`} style={MUTED_TEXT_STYLE}>
                                No MCLogs uploads found.
                            </p>
                        ) : (
                            <>
                                <ul css={tw`list-none p-0`}>
                                    {paginatedHistory.map(({ id, url, uploadedAt }) => (
                                        <li
                                            key={id}
                                            css={tw`py-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b last:border-b-0`}
                                            style={{ borderColor: 'var(--panel-border)' }}
                                        >
                                            <div css={tw`min-w-0`}>
                                                <a
                                                    href={url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    css={tw`text-sm text-primary-400 hover:underline break-all`}
                                                >
                                                    {url}
                                                </a>
                                                <span css={tw`block text-sm`} style={MUTED_TEXT_STYLE}>
                                                    Uploaded on: {new Date(uploadedAt).toLocaleString()}
                                                </span>
                                            </div>
                                            <div css={tw`flex flex-wrap gap-2`}>
                                                <CopyOnClick text={url}>
                                                    <button
                                                        css={tw`text-sm px-4 py-2 rounded border transition-colors duration-150`}
                                                        style={NEUTRAL_BUTTON_STYLE}
                                                    >
                                                        <i className="fas fa-link"></i>
                                                    </button>
                                                </CopyOnClick>
                                                <button
                                                    css={tw`text-sm bg-primary-500 border border-primary-600 text-primary-50 px-4 py-2 rounded hover:bg-primary-600`}
                                                    onClick={() => fetchMclogsData(id)}
                                                >
                                                    View Data
                                                </button>
                                                <button
                                                    css={tw`text-sm bg-red-500 border border-red-600 text-red-100 px-4 py-2 rounded hover:bg-red-600`}
                                                    onClick={() => removeFromLocalStorage(id)}
                                                >
                                                    <i className="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                                {renderPaginationControls(currentHistoryPage, totalHistoryPages, handleHistoryPageChange)}
                            </>
                        )}
                    </div>
                )}
            </div>

            {showModal && (
                <div css={tw`fixed inset-0 flex items-center justify-center z-50`} style={{ background: 'var(--panel-overlay-bg)' }}>
                    <div css={tw`rounded-lg border p-6 text-center shadow-xl`} style={CARD_STYLE}>
                        <h3 css={tw`text-lg mb-4`} style={HEADING_STYLE}>
                            Are you sure you want to clear all history?
                        </h3>
                        <button
                            css={tw`text-sm bg-red-500 border border-red-600 text-red-100 px-4 py-2 rounded hover:bg-red-600 mr-4`}
                            onClick={clearAllLogs}
                        >
                            Yes, Delete All
                        </button>
                        <button
                            css={tw`text-sm px-4 py-2 rounded border transition-colors duration-150`}
                            style={NEUTRAL_BUTTON_STYLE}
                            onClick={() => setShowModal(false)}
                        >
                            Cancel
                        </button>
                    </div>
                </div>
            )}

            {insightsData && (
                <div css={tw`rounded-lg border shadow-md px-4 py-4 md:px-5`} style={CARD_STYLE}>
                    <div css={tw`flex items-center mb-4`}>
                        <img
                            src={getServerImageUrl(insightsData.name || 'Vanilla')}
                            alt={insightsData.name || 'Unknown'}
                            css={tw`w-16 h-16 mr-4 rounded-full`}
                        />
                        <div>
                            <h3 css={tw`text-lg`} style={HEADING_STYLE}>
                                {insightsData.name || 'Unknown Server'}
                            </h3>
                            <p css={tw`block text-sm`} style={MUTED_TEXT_STYLE}>
                                Version: {insightsData.version || 'Not Available'}
                            </p>
                        </div>
                    </div>

                    {insightsData.analysis?.problems?.length ? (
                        <div css={tw`border p-4 rounded`} style={SUBCARD_STYLE}>
                            <h4 css={tw`text-base mb-2`} style={HEADING_STYLE}>
                                Analysis
                            </h4>
                            {insightsData.analysis.problems.map((problem, index) => (
                                <div key={index} css={tw`mb-4`}>
                                    <p css={tw`text-sm mb-1`} style={TEXT_STYLE}>
                                        {problem.message || 'No problem message available.'}
                                    </p>
                                    {problem.solutions?.length ? (
                                        <ul css={tw`list-disc list-inside text-sm`} style={MUTED_TEXT_STYLE}>
                                            {problem.solutions.map((solution, idx) => (
                                                <li key={idx}>{solution.message || 'No solution message available.'}</li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p css={tw`text-sm`} style={MUTED_TEXT_STYLE}>
                                            No solutions available.
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p css={tw`text-sm`} style={MUTED_TEXT_STYLE}>
                            No problems found in analysis.
                        </p>
                    )}
                </div>
            )}

            {selectedLogData && (
                <div css={tw`rounded-lg border shadow-md px-4 py-4 md:px-5`} style={CARD_STYLE}>
                    <div css={tw`flex justify-between items-center mb-4`}>
                        <h3 css={tw`text-lg`} style={HEADING_STYLE}>
                            Selected Log Data
                        </h3>
                        <button
                            css={tw`text-sm bg-red-500 border border-red-600 text-red-100 px-4 py-2 rounded hover:bg-red-600`}
                            onClick={() => {
                                setSelectedLogData(null);
                                setInsightsData(null);
                            }}
                        >
                            Close
                        </button>
                    </div>
                    <h4 css={tw`text-base mb-2`} style={HEADING_STYLE}>
                        Raw Log
                    </h4>
                    <button
                        css={tw`mb-4 px-2 py-1 rounded border text-xs transition-colors duration-150`}
                        style={NEUTRAL_BUTTON_STYLE}
                        onClick={() => setShowOriginal((v) => !v)}
                        type="button"
                    >
                        {showOriginal ? 'Show Grouped/Collapsible View' : 'Show Original Log Order'}
                    </button>
                    <div css={tw`text-sm whitespace-pre-wrap mb-4 rounded border p-3`} style={SUBCARD_STYLE}>
                        {showOriginal ? (
                            selectedLogData.split('\n').map((line, index) => {
                                let lineStyle = { color: 'var(--panel-heading)' };
                                if (line.includes('WARN')) lineStyle = tw`text-[#FF8C00]`;
                                else if (line.includes('INFO')) lineStyle = tw`text-[#FFFF99]`;
                                else if (line.includes('ERROR')) lineStyle = tw`text-[#F62451]`;
                                return (
                                    <p css={lineStyle} key={index}>{line}</p>
                                );
                            })
                        ) : (
                            (() => {
                                const grouped: Record<LogSection, string[]> = {
                                    ERROR: [],
                                    WARN: [],
                                    INFO: [],
                                    OTHER: [],
                                };

                                // Best-effort grouping based on substring matches (not a structured parser).
                                selectedLogData.split('\n').forEach(line => {
                                    if (line.includes('ERROR')) grouped.ERROR.push(line);
                                    else if (line.includes('WARN')) grouped.WARN.push(line);
                                    else if (line.includes('INFO')) grouped.INFO.push(line);
                                    else grouped.OTHER.push(line);
                                });
                                const lineStyle: Record<LogSection, any> = {
                                    ERROR: tw`text-[#F62451]`,
                                    WARN: tw`text-[#FF8C00]`,
                                    INFO: tw`text-[#FFFF99]`,
                                    OTHER: { color: 'var(--panel-heading)' },
                                };
                                return LogSectionNames.map(type => (
                                    grouped[type].length > 0 && (
                                        <div key={type} css={tw`mb-2`}>
                                            <button
                                                css={tw`mb-1 px-2 py-1 rounded border text-xs transition-colors duration-150`}
                                                style={NEUTRAL_BUTTON_STYLE}
                                                onClick={() => setCollapsed(c => ({ ...c, [type]: !c[type] }))}
                                                type="button"
                                            >
                                                {collapsed[type] ? `Show` : `Hide`} {grouped[type].length} {type} line{grouped[type].length !== 1 ? 's' : ''}
                                            </button>
                                            {!collapsed[type] && (
                                                <div>
                                                    {grouped[type].map((line, idx) => (
                                                        <p css={lineStyle[type]} key={idx}>
                                                            {line}
                                                        </p>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    )
                                ));
                            })()
                        )}
                    </div>
                </div>
            )}
            </div>
        </ServerContentBlock>
    );
};

export default LogsPage;
