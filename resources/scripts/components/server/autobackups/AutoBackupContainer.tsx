/* eslint-disable react/no-unknown-property */
import React, { useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import FlashMessageRender from '@/components/FlashMessageRender';
import Spinner from '@/components/elements/Spinner';
import Label from '@/components/elements/Label';
import Input, { Textarea } from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import { Button } from '@/components/elements/button';
import useFlash from '@/plugins/useFlash';
import { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import styled, { keyframes } from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faArchive,
    faCheckCircle,
    faCloudUploadAlt,
    faClock,
    faDatabase,
    faEdit,
    faExclamationTriangle,
    faLock,
    faPlay,
    faTrashAlt,
} from '@fortawesome/free-solid-svg-icons';
import {
    AutoBackupClientDefaults,
    AutoBackupDestinationType,
    AutoBackupPayload,
    AutoBackupProfile,
    createAutoBackupProfile,
    deleteAutoBackupProfile,
    getAutoBackupProfiles,
    runAutoBackupProfile,
    updateAutoBackupProfile,
} from '@/api/server/autobackups';

const FLASH_KEY = 'server:auto-backups';

const shineTravel = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const auroraFlow = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const metricFloat = keyframes`
    0%, 100% { transform: translateY(0px); }
    50% { transform: translateY(-3px); }
`;

const MagicCard = styled.div<{ $interactive?: boolean }>`
    ${tw`relative overflow-hidden rounded-xl border p-4 md:p-5`};
    border-color: var(--panel-border);
    background: var(--panel-magic-card-bg);
    box-shadow: var(--panel-magic-card-shadow);
    transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1), border-color 220ms ease, box-shadow 220ms ease;

    &::before {
        content: '';
        position: absolute;
        inset: -35% -15%;
        background: var(--panel-magic-card-glow);
        pointer-events: none;
    }

    &::after {
        content: '';
        position: absolute;
        top: -180%;
        left: -40%;
        width: 70%;
        height: 360%;
        pointer-events: none;
        background: var(--panel-magic-card-sweep);
        transform: rotate(14deg) translateX(-35%);
        transition: transform 560ms cubic-bezier(0.22, 1, 0.36, 1), opacity 320ms ease;
        opacity: 0;
    }

    ${({ $interactive }) =>
        $interactive
            ? `
        &:hover {
            transform: translateY(-2px);
            border-color: var(--panel-magic-accent-border);
            box-shadow: var(--panel-magic-card-shadow-hover);
        }

        &:hover::after {
            opacity: 1;
            transform: rotate(14deg) translateX(135%);
        }
    `
            : ''}
`;

const ShineBorder = styled.div`
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    border: 1px solid transparent;
    background: var(--panel-magic-border-gradient) border-box;
    background-size: 250% 250%;
    animation: ${shineTravel} 6s ease infinite;
    -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
`;

const AuroraHeading = styled.h2`
    ${tw`text-lg md:text-xl font-semibold tracking-wide`};
    background: var(--panel-magic-title-gradient);
    background-size: 220% 220%;
    animation: ${auroraFlow} 7s ease infinite;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
`;

const MetricCard = styled.div<{ $delay?: number }>`
    ${tw`rounded-lg border px-3 py-2`};
    border-color: var(--panel-chip-border);
    background: var(--panel-chip-bg);
    animation: ${metricFloat} 6.2s ease-in-out infinite;
    animation-delay: ${({ $delay = 0 }) => `${$delay}s`};
`;

const ActionDock = styled.div`
    ${tw`flex flex-wrap gap-2 lg:justify-end`};

    & > button {
        position: relative;
        overflow: hidden;
        isolation: isolate;
        transform: translateZ(0);
        transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 220ms ease, border-color 220ms ease;
    }

    & > button::before {
        content: '';
        position: absolute;
        inset: -2px;
        background: var(--panel-magic-action-sweep);
        transform: translateX(-125%);
        transition: transform 440ms cubic-bezier(0.22, 1, 0.36, 1);
        pointer-events: none;
        z-index: 0;
    }

    & > button:hover {
        transform: translateY(-1px);
        box-shadow: var(--panel-magic-card-shadow);
        border-color: var(--panel-magic-accent-border);
    }

    & > button:hover::before {
        transform: translateX(125%);
    }

    & > button span {
        position: relative;
        z-index: 1;
    }

    & > button svg {
        transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1);
    }

    & > button:hover svg {
        transform: translateX(1px);
    }
`;

const defaultConfig = (type: AutoBackupDestinationType): Record<string, unknown> => {
    if (type === 's3') {
        return {
            bucket: '',
            region: '',
            endpoint: '',
            path_prefix: '',
            use_path_style: false,
            access_key_id: '',
            secret_access_key: '',
        };
    }

    if (type === 'dropbox') {
        return {
            folder_path: '',
            access_token: '',
        };
    }

    return {
        auth_mode: 'service_account',
        folder_id: '',
        service_account_json: '',
        client_id: '',
        client_secret: '',
        refresh_token: '',
    };
};

const defaultClientDefaults: AutoBackupClientDefaults = {
    enabled: true,
    allowUserDestinationOverride: true,
    defaultDestinationType: 'google_drive',
    defaultIntervalMinutes: 360,
    defaultKeepRemote: 10,
};

const blankPayload = (defaults: AutoBackupClientDefaults = defaultClientDefaults): AutoBackupPayload => ({
    name: '',
    destination_type: defaults.defaultDestinationType,
    destination_config: defaultConfig(defaults.defaultDestinationType),
    is_enabled: true,
    interval_minutes: defaults.defaultIntervalMinutes,
    keep_remote: defaults.defaultKeepRemote,
    is_locked: false,
    ignored_files: '',
});

const boolFromConfig = (value: unknown): boolean => {
    if (typeof value === 'boolean') {
        return value;
    }

    if (typeof value === 'string') {
        return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase());
    }

    return Boolean(value);
};

const googleAuthModeFromConfig = (value: unknown): 'service_account' | 'oauth' => {
    if (typeof value === 'string') {
        const normalized = value.toLowerCase().trim();
        if (normalized === 'oauth') {
            return 'oauth';
        }
    }

    return 'service_account';
};

const statusTone = (profile: AutoBackupProfile): { label: string; style: React.CSSProperties } => {
    const source = `${profile.lastStatus || ''} ${profile.lastError || ''}`.toLowerCase();

    if (source.includes('error') || source.includes('fail')) {
        return {
            label: profile.lastStatus || 'error',
            style: {
                color: '#fecaca',
                borderColor: 'rgba(248, 113, 113, 0.4)',
                backgroundColor: 'rgba(127, 29, 29, 0.35)',
            },
        };
    }

    if (source.includes('success') || source.includes('complete') || source.includes('finished')) {
        return {
            label: profile.lastStatus || 'success',
            style: {
                color: '#bbf7d0',
                borderColor: 'rgba(74, 222, 128, 0.4)',
                backgroundColor: 'rgba(20, 83, 45, 0.3)',
            },
        };
    }

    if (source.includes('queue') || source.includes('running') || source.includes('process')) {
        return {
            label: profile.lastStatus || 'running',
            style: {
                color: '#bfdbfe',
                borderColor: 'rgba(96, 165, 250, 0.4)',
                backgroundColor: 'rgba(30, 58, 138, 0.25)',
            },
        };
    }

    return {
        label: profile.lastStatus || 'idle',
        style: {
            color: '#e5e7eb',
            borderColor: 'rgba(148, 163, 184, 0.35)',
            backgroundColor: 'rgba(30, 41, 59, 0.35)',
        },
    };
};

const destinationMeta = (type: AutoBackupDestinationType): { label: string; color: React.CSSProperties } => {
    if (type === 'google_drive') {
        return {
            label: 'Google Drive',
            color: {
                color: '#bfdbfe',
                borderColor: 'rgba(96, 165, 250, 0.35)',
                backgroundColor: 'rgba(30, 64, 175, 0.2)',
            },
        };
    }

    if (type === 's3') {
        return {
            label: 'S3 Bucket',
            color: {
                color: '#fde68a',
                borderColor: 'rgba(251, 191, 36, 0.35)',
                backgroundColor: 'rgba(120, 53, 15, 0.2)',
            },
        };
    }

    return {
        label: 'Dropbox',
        color: {
            color: '#93c5fd',
            borderColor: 'rgba(59, 130, 246, 0.35)',
            backgroundColor: 'rgba(29, 78, 216, 0.2)',
        },
    };
};

const shortDate = (value: string | null): string => {
    if (!value) {
        return 'Not scheduled';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return parsed.toLocaleString();
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid || '');
    const { clearFlashes, clearAndAddHttpError, addFlash, addError } = useFlash();

    const [profiles, setProfiles] = useState<AutoBackupProfile[]>([]);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [runningIds, setRunningIds] = useState<number[]>([]);
    const [deletingIds, setDeletingIds] = useState<number[]>([]);
    const [editingProfileId, setEditingProfileId] = useState<number | null>(null);
    const [clientDefaults, setClientDefaults] = useState<AutoBackupClientDefaults>(defaultClientDefaults);
    const [payload, setPayload] = useState<AutoBackupPayload>(blankPayload(defaultClientDefaults));

    const loadProfiles = () => {
        setLoading(true);
        clearFlashes(FLASH_KEY);

        getAutoBackupProfiles(uuid)
            .then((response) => {
                setProfiles(response.profiles);
                setClientDefaults(response.defaults);

                if (editingProfileId === null) {
                    setPayload(blankPayload(response.defaults));
                }
            })
            .catch((error) => clearAndAddHttpError({ key: FLASH_KEY, error }))
            .then(() => setLoading(false));
    };

    useEffect(() => {
        if (!uuid) {
            return;
        }

        loadProfiles();
    }, [uuid]);

    const editProfile = (profile: AutoBackupProfile) => {
        setEditingProfileId(profile.id);
        setPayload({
            name: profile.name || '',
            destination_type: profile.destinationType,
            destination_config: {
                ...defaultConfig(profile.destinationType),
                ...profile.destinationConfig,
            },
            is_enabled: profile.isEnabled,
            interval_minutes: profile.intervalMinutes,
            keep_remote: profile.keepRemote,
            is_locked: profile.isLocked,
            ignored_files: profile.ignoredFiles || '',
        });
    };

    const resetForm = () => {
        setEditingProfileId(null);
        setPayload(blankPayload(clientDefaults));
    };

    const destinationConfig = useMemo(() => {
        return {
            ...defaultConfig(payload.destination_type),
            ...(payload.destination_config || {}),
        };
    }, [payload.destination_type, payload.destination_config]);

    const submit = () => {
        if (!clientDefaults.enabled) {
            addError({ key: FLASH_KEY, message: 'Auto backups are currently disabled by panel administrator.' });
            return;
        }

        setSubmitting(true);
        clearFlashes(FLASH_KEY);

        const action = editingProfileId
            ? updateAutoBackupProfile(uuid, editingProfileId, payload)
            : createAutoBackupProfile(uuid, payload);

        action.then((profile) => {
            addFlash({
                key: FLASH_KEY,
                type: 'success',
                message: editingProfileId
                    ? `Auto backup profile #${profile.id} updated.`
                    : `Auto backup profile #${profile.id} created.`,
            });
            resetForm();
            loadProfiles();
        })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .then(() => setSubmitting(false));
    };

    const triggerRun = (profileId: number) => {
        if (!clientDefaults.enabled) {
            addError({ key: FLASH_KEY, message: 'Auto backups are currently disabled by panel administrator.' });
            return;
        }

        setRunningIds((current) => [...current, profileId]);
        clearFlashes(FLASH_KEY);

        runAutoBackupProfile(uuid, profileId)
            .then(() => {
                addFlash({
                    key: FLASH_KEY,
                    type: 'success',
                    message: `Auto backup profile #${profileId} has been queued.`,
                });
                loadProfiles();
            })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .then(() => setRunningIds((current) => current.filter((id) => id !== profileId)));
    };

    const removeProfile = (profileId: number) => {
        setDeletingIds((current) => [...current, profileId]);
        clearFlashes(FLASH_KEY);

        deleteAutoBackupProfile(uuid, profileId)
            .then(() => {
                addFlash({
                    key: FLASH_KEY,
                    type: 'success',
                    message: `Auto backup profile #${profileId} deleted.`,
                });
                if (editingProfileId === profileId) {
                    resetForm();
                }
                loadProfiles();
            })
            .catch((error) => addError({ key: FLASH_KEY, message: httpErrorToHuman(error) }))
            .then(() => setDeletingIds((current) => current.filter((id) => id !== profileId)));
    };

    const isProcessing = submitting || loading;
    const autoBackupsDisabled = !clientDefaults.enabled;

    return (
        <ServerContentBlock title={'Auto Backups'}>
            <FlashMessageRender byKey={FLASH_KEY} css={tw`mb-4`} />

            {autoBackupsDisabled && (
                <MagicCard css={tw`mb-4`}>
                    <ShineBorder />
                    <p css={tw`relative z-10 text-sm text-yellow-200`}>
                        Auto backups are currently disabled by the panel administrator. Existing profiles are visible but new runs are blocked.
                    </p>
                </MagicCard>
            )}

            <MagicCard css={tw`mb-5`}>
                <ShineBorder />
                <div css={tw`relative z-10 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between`}>
                    <div>
                        <AuroraHeading>Smart Auto Backup Profiles</AuroraHeading>
                        <p css={tw`mt-2 text-sm text-neutral-300 max-w-3xl leading-relaxed`}>
                            Build automated backup jobs to Google Drive, S3, or Dropbox. Profiles run on schedule, support retention limits,
                            and can be executed instantly with <strong>Run Now</strong> to validate credentials and paths.
                        </p>
                        <p css={tw`mt-3 text-xs text-neutral-500`}>
                            Setup guide: <code>docs/AUTO_BACKUPS.md</code>
                        </p>
                    </div>
                    <div css={tw`grid grid-cols-2 gap-2 flex-shrink-0`}>
                        <MetricCard $delay={0}>
                            <p css={tw`text-[11px] uppercase tracking-wide text-neutral-400`}>Profiles</p>
                            <p css={tw`mt-1 text-base font-semibold text-neutral-100`}>{profiles.length}</p>
                        </MetricCard>
                        <MetricCard $delay={0.8}>
                            <p css={tw`text-[11px] uppercase tracking-wide text-neutral-400`}>Active</p>
                            <p css={tw`mt-1 text-base font-semibold text-green-300`}>
                                {profiles.filter((profile) => profile.isEnabled).length}
                            </p>
                        </MetricCard>
                    </div>
                </div>
            </MagicCard>

            <div css={tw`mb-5 grid grid-cols-1 gap-3 lg:grid-cols-3`}>
                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10`}>
                        <div css={tw`flex items-center gap-2 text-sm font-semibold text-blue-300`}>
                            <FontAwesomeIcon icon={faDatabase} />
                            Google Drive
                        </div>
                        <p css={tw`mt-2 text-xs leading-relaxed text-neutral-300`}>
                            Simple mode (recommended): <code>service_account_json</code>. Advanced mode: <code>client_id</code>,
                            <code> client_secret</code>, <code>refresh_token</code>. Optional: <code>folder_id</code>.
                        </p>
                    </div>
                </MagicCard>
                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10`}>
                        <div css={tw`flex items-center gap-2 text-sm font-semibold text-yellow-300`}>
                            <FontAwesomeIcon icon={faArchive} />
                            S3 Bucket
                        </div>
                        <p css={tw`mt-2 text-xs leading-relaxed text-neutral-300`}>
                            Required: <code>bucket</code>, <code>region</code>, <code>access_key_id</code>, <code>secret_access_key</code>.
                            Optional: <code>endpoint</code>, <code>path_prefix</code>.
                        </p>
                    </div>
                </MagicCard>
                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10`}>
                        <div css={tw`flex items-center gap-2 text-sm font-semibold text-cyan-300`}>
                            <FontAwesomeIcon icon={faCloudUploadAlt} />
                            Dropbox
                        </div>
                        <p css={tw`mt-2 text-xs leading-relaxed text-neutral-300`}>
                            Required: <code>access_token</code>. Optional: <code>folder_path</code> for nested storage.
                        </p>
                    </div>
                </MagicCard>
            </div>

            <MagicCard css={tw`mb-6`}>
                <ShineBorder />
                <form
                    id={'autobackup-profile-form'}
                    css={tw`relative z-10`}
                    onSubmit={(event) => {
                        event.preventDefault();
                    }}
                >
                    <div css={tw`mb-4 flex flex-wrap items-center gap-2 text-xs text-neutral-300`}>
                        <span css={tw`inline-flex items-center gap-2 rounded-full border border-red-300/40 bg-red-900/25 px-3 py-1`}>
                            <FontAwesomeIcon icon={faClock} />
                            Scheduler + Retention
                        </span>
                        <span css={tw`inline-flex items-center gap-2 rounded-full border border-blue-300/40 bg-blue-900/20 px-3 py-1`}>
                            <FontAwesomeIcon icon={faCloudUploadAlt} />
                            Multi Destination
                        </span>
                        <span css={tw`inline-flex items-center gap-2 rounded-full border border-green-300/40 bg-green-900/20 px-3 py-1`}>
                            <FontAwesomeIcon icon={faCheckCircle} />
                            Run-On-Demand
                        </span>
                        {!clientDefaults.allowUserDestinationOverride && (
                            <span css={tw`inline-flex items-center gap-2 rounded-full border border-blue-300/40 bg-blue-900/20 px-3 py-1`}>
                                Admin-Enforced Credentials
                            </span>
                        )}
                    </div>

                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                    <div>
                        <Label>Name (Optional)</Label>
                        <Input
                            value={payload.name || ''}
                            onChange={(event) => {
                                const value = event.currentTarget.value;
                                setPayload((current) => ({ ...current, name: value }));
                            }}
                            placeholder={'Nightly Backup'}
                        />
                    </div>
                    <div>
                        <Label>Destination</Label>
                        <Select
                            value={payload.destination_type}
                            onChange={(event) => {
                                const type = event.currentTarget.value as AutoBackupDestinationType;
                                setPayload((current) => ({
                                    ...current,
                                    destination_type: type,
                                    destination_config: defaultConfig(type),
                                }));
                            }}
                        >
                            <option value={'google_drive'}>Google Drive</option>
                            <option value={'s3'}>S3 Bucket</option>
                            <option value={'dropbox'}>Dropbox</option>
                        </Select>
                    </div>
                    <div>
                        <Label>Interval (Minutes)</Label>
                        <Input
                            type={'number'}
                            min={5}
                            max={10080}
                            value={String(payload.interval_minutes || clientDefaults.defaultIntervalMinutes)}
                            onChange={(event) => {
                                const value = event.currentTarget.value;
                                setPayload((current) => ({
                                    ...current,
                                    interval_minutes: Number(value || clientDefaults.defaultIntervalMinutes),
                                }));
                            }}
                        />
                    </div>
                    <div>
                        <Label>Keep Remote Copies</Label>
                        <Input
                            type={'number'}
                            min={1}
                            max={1000}
                            value={String(payload.keep_remote || clientDefaults.defaultKeepRemote)}
                            onChange={(event) => {
                                const value = event.currentTarget.value;
                                setPayload((current) => ({
                                    ...current,
                                    keep_remote: Number(value || clientDefaults.defaultKeepRemote),
                                }));
                            }}
                        />
                    </div>
                    </div>

                    {payload.destination_type === 'google_drive' &&
                        (() => {
                            const googleAuthMode = googleAuthModeFromConfig(destinationConfig.auth_mode);
                            const hasServiceAccountJson = boolFromConfig(destinationConfig.has_service_account_json);
                            const hasClientSecret = boolFromConfig(destinationConfig.has_client_secret);
                            const hasRefreshToken = boolFromConfig(destinationConfig.has_refresh_token);

                            return (
                                <div css={tw`mt-5 grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                    <div css={tw`md:col-span-2`}>
                                        <Label>Google Auth Mode</Label>
                                        <Select
                                            value={googleAuthMode}
                                            onChange={(event) => {
                                                const value = event.currentTarget.value === 'oauth' ? 'oauth' : 'service_account';
                                                setPayload((current) => ({
                                                    ...current,
                                                    destination_config: {
                                                        ...destinationConfig,
                                                        auth_mode: value,
                                                    },
                                                }));
                                            }}
                                        >
                                            <option value={'service_account'}>Service Account (Simple, Recommended)</option>
                                            <option value={'oauth'}>OAuth Client + Refresh Token (Advanced)</option>
                                        </Select>
                                        <p css={tw`mt-2 text-xs text-neutral-400`}>
                                            Service Account mode avoids refresh token management. Share the Drive folder with the service account email.
                                        </p>
                                    </div>
                                    <div css={tw`md:col-span-2`}>
                                        <Label>Google Drive Folder ID (Optional)</Label>
                                        <Input
                                            value={String(destinationConfig.folder_id || '')}
                                            onChange={(event) => {
                                                const value = event.currentTarget.value;
                                                setPayload((current) => ({
                                                    ...current,
                                                    destination_config: {
                                                        ...destinationConfig,
                                                        folder_id: value,
                                                    },
                                                }));
                                            }}
                                        />
                                    </div>

                                    {googleAuthMode === 'service_account' && (
                                        <div css={tw`md:col-span-2`}>
                                            <Label>Service Account JSON</Label>
                                            <Textarea
                                                rows={7}
                                                value={String(destinationConfig.service_account_json || '')}
                                                onChange={(event) => {
                                                    const value = event.currentTarget.value;
                                                    setPayload((current) => ({
                                                        ...current,
                                                        destination_config: {
                                                            ...destinationConfig,
                                                            service_account_json: value,
                                                        },
                                                    }));
                                                }}
                                            />
                                            <p css={tw`mt-2 text-xs text-neutral-400`}>
                                                {hasServiceAccountJson
                                                    ? 'A service account JSON is already saved. Leave blank to keep it, or paste a new one to replace it.'
                                                    : 'Paste the full JSON key for your Google service account.'}
                                            </p>
                                        </div>
                                    )}

                                    {googleAuthMode === 'oauth' && (
                                        <>
                                            <div>
                                                <Label>Google OAuth Client ID</Label>
                                                <Input
                                                    value={String(destinationConfig.client_id || '')}
                                                    onChange={(event) => {
                                                        const value = event.currentTarget.value;
                                                        setPayload((current) => ({
                                                            ...current,
                                                            destination_config: {
                                                                ...destinationConfig,
                                                                client_id: value,
                                                            },
                                                        }));
                                                    }}
                                                />
                                            </div>
                                            <div>
                                                <Label>Google OAuth Client Secret</Label>
                                                <Input
                                                    type={'password'}
                                                    name={'google_client_secret'}
                                                    form={'autobackup-profile-form'}
                                                    value={String(destinationConfig.client_secret || '')}
                                                    onChange={(event) => {
                                                        const value = event.currentTarget.value;
                                                        setPayload((current) => ({
                                                            ...current,
                                                            destination_config: {
                                                                ...destinationConfig,
                                                                client_secret: value,
                                                            },
                                                        }));
                                                    }}
                                                />
                                                {hasClientSecret && (
                                                    <p css={tw`mt-2 text-xs text-neutral-400`}>
                                                        Secret already saved. Leave blank to keep existing value.
                                                    </p>
                                                )}
                                            </div>
                                            <div css={tw`md:col-span-2`}>
                                                <Label>Google Refresh Token</Label>
                                                <Input
                                                    type={'password'}
                                                    name={'google_refresh_token'}
                                                    form={'autobackup-profile-form'}
                                                    value={String(destinationConfig.refresh_token || '')}
                                                    onChange={(event) => {
                                                        const value = event.currentTarget.value;
                                                        setPayload((current) => ({
                                                            ...current,
                                                            destination_config: {
                                                                ...destinationConfig,
                                                                refresh_token: value,
                                                            },
                                                        }));
                                                    }}
                                                />
                                                {hasRefreshToken && (
                                                    <p css={tw`mt-2 text-xs text-neutral-400`}>
                                                        Refresh token already saved. Leave blank to keep existing value.
                                                    </p>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </div>
                            );
                        })()}

                    {payload.destination_type === 's3' && (
                        <div css={tw`mt-5 grid grid-cols-1 md:grid-cols-2 gap-4`}>
                            <div>
                                <Label>Bucket</Label>
                                <Input
                                    value={String(destinationConfig.bucket || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                bucket: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div>
                                <Label>Region</Label>
                                <Input
                                    value={String(destinationConfig.region || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                region: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div>
                                <Label>Access Key ID</Label>
                                <Input
                                    value={String(destinationConfig.access_key_id || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                access_key_id: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div>
                                <Label>Secret Access Key</Label>
                                <Input
                                    type={'password'}
                                    name={'s3_secret_access_key'}
                                    form={'autobackup-profile-form'}
                                    value={String(destinationConfig.secret_access_key || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                secret_access_key: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div>
                                <Label>Endpoint (Optional)</Label>
                                <Input
                                    value={String(destinationConfig.endpoint || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                endpoint: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div>
                                <Label>Path Prefix (Optional)</Label>
                                <Input
                                    value={String(destinationConfig.path_prefix || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                path_prefix: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div css={tw`md:col-span-2`}>
                                <label css={tw`inline-flex items-center gap-2 text-sm text-neutral-200`}>
                                    <input
                                        type={'checkbox'}
                                        checked={boolFromConfig(destinationConfig.use_path_style)}
                                        onChange={(event) => {
                                            const checked = event.currentTarget.checked;
                                            setPayload((current) => ({
                                                ...current,
                                                destination_config: {
                                                    ...destinationConfig,
                                                    use_path_style: checked,
                                                },
                                            }));
                                        }}
                                    />
                                    Use path-style endpoint addressing (for S3-compatible providers).
                                </label>
                            </div>
                        </div>
                    )}

                    {payload.destination_type === 'dropbox' && (
                        <div css={tw`mt-5 grid grid-cols-1 md:grid-cols-2 gap-4`}>
                            <div css={tw`md:col-span-2`}>
                                <Label>Dropbox Access Token</Label>
                                <Input
                                    type={'password'}
                                    name={'dropbox_access_token'}
                                    form={'autobackup-profile-form'}
                                    value={String(destinationConfig.access_token || '')}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                access_token: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                            <div css={tw`md:col-span-2`}>
                                <Label>Dropbox Folder Path (Optional)</Label>
                                <Input
                                    value={String(destinationConfig.folder_path || '')}
                                    placeholder={'minecraft-backups'}
                                    onChange={(event) => {
                                        const value = event.currentTarget.value;
                                        setPayload((current) => ({
                                            ...current,
                                            destination_config: {
                                                ...destinationConfig,
                                                folder_path: value,
                                            },
                                        }));
                                    }}
                                />
                            </div>
                        </div>
                    )}

                    <div css={tw`mt-5`}>
                        <Label>Ignored Files (Optional)</Label>
                        <textarea
                            css={tw`w-full mt-1 p-3 rounded border text-sm`}
                            style={{
                                background: 'var(--panel-chip-bg)',
                                borderColor: 'var(--panel-chip-border)',
                                color: 'var(--panel-text)',
                            }}
                            rows={4}
                            value={payload.ignored_files || ''}
                            onChange={(event) => {
                                const value = event.currentTarget.value;
                                setPayload((current) => ({ ...current, ignored_files: value }));
                            }}
                            placeholder={'logs\ncache'}
                        />
                    </div>

                    <div css={tw`mt-4 flex flex-wrap gap-6`}>
                        <label css={tw`inline-flex items-center gap-2 text-sm text-neutral-200`}>
                            <input
                                type={'checkbox'}
                                checked={Boolean(payload.is_enabled)}
                                onChange={(event) => {
                                    const checked = event.currentTarget.checked;
                                    setPayload((current) => ({ ...current, is_enabled: checked }));
                                }}
                            />
                            Enabled
                        </label>
                        <label css={tw`inline-flex items-center gap-2 text-sm text-neutral-200`}>
                            <input
                                type={'checkbox'}
                                checked={Boolean(payload.is_locked)}
                                onChange={(event) => {
                                    const checked = event.currentTarget.checked;
                                    setPayload((current) => ({ ...current, is_locked: checked }));
                                }}
                            />
                            Lock generated backups
                        </label>
                    </div>

                    <ActionDock css={tw`mt-6 justify-end`}>
                        {editingProfileId && (
                            <Button
                                type={'button'}
                                variant={Button.Variants.Secondary}
                                disabled={isProcessing || autoBackupsDisabled}
                                onClick={resetForm}
                            >
                                Cancel Edit
                            </Button>
                        )}
                        <Button type={'button'} disabled={isProcessing || autoBackupsDisabled} onClick={submit}>
                            {editingProfileId ? 'Update Auto Backup' : 'Create Auto Backup'}
                        </Button>
                    </ActionDock>
                </form>
            </MagicCard>

            {loading ? (
                <Spinner size={'large'} centered />
            ) : profiles.length < 1 ? (
                <MagicCard css={tw`text-center py-6`}>
                    <ShineBorder />
                    <p css={tw`relative z-10 text-sm text-neutral-300`}>No auto backup profiles configured for this server.</p>
                </MagicCard>
            ) : (
                <div css={tw`space-y-3`}>
                    {profiles.map((profile) => {
                        const status = statusTone(profile);
                        const destination = destinationMeta(profile.destinationType);

                        return (
                            <MagicCard key={profile.id} $interactive>
                                <ShineBorder />
                                <div css={tw`relative z-10 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between`}>
                                    <div css={tw`min-w-0`}>
                                        <div css={tw`flex flex-wrap items-center gap-2`}>
                                            <p css={tw`text-base font-medium text-neutral-100`}>
                                                #{profile.id} {profile.name || 'Auto Backup'}
                                            </p>
                                            <span
                                                css={tw`inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] uppercase tracking-wide`}
                                                style={destination.color}
                                            >
                                                <FontAwesomeIcon icon={faCloudUploadAlt} />
                                                {destination.label}
                                            </span>
                                            <span
                                                css={tw`inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] uppercase tracking-wide`}
                                                style={status.style}
                                            >
                                                <FontAwesomeIcon
                                                    icon={
                                                        (profile.lastStatus || '').toLowerCase().includes('error') || profile.lastError
                                                            ? faExclamationTriangle
                                                            : faCheckCircle
                                                    }
                                                />
                                                {status.label}
                                            </span>
                                            {profile.isLocked && (
                                                <span
                                                    css={tw`inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px]`}
                                                    style={{
                                                        borderColor: 'var(--panel-chip-border)',
                                                        background: 'var(--panel-chip-bg)',
                                                        color: 'var(--panel-text-muted)',
                                                    }}
                                                >
                                                    <FontAwesomeIcon icon={faLock} />
                                                    Locked
                                                </span>
                                            )}
                                        </div>
                                        <div css={tw`mt-3 grid grid-cols-1 gap-2 text-xs text-neutral-300 sm:grid-cols-3`}>
                                            <p css={tw`truncate`}>
                                                <span css={tw`text-neutral-400`}>Next:</span> {shortDate(profile.nextRunAt)}
                                            </p>
                                            <p>
                                                <span css={tw`text-neutral-400`}>Interval:</span> {profile.intervalMinutes} min
                                            </p>
                                            <p>
                                                <span css={tw`text-neutral-400`}>Keep:</span> {profile.keepRemote} copies
                                            </p>
                                        </div>
                                        {profile.lastError && (
                                            <p css={tw`mt-2 text-xs text-red-300 break-words`}>Last error: {profile.lastError}</p>
                                        )}
                                    </div>

                                    <ActionDock>
                                        <Button
                                            type={'button'}
                                            size={Button.Sizes.Small}
                                            disabled={runningIds.includes(profile.id) || autoBackupsDisabled}
                                            onClick={() => triggerRun(profile.id)}
                                        >
                                            <span css={tw`inline-flex items-center gap-1`}>
                                                <FontAwesomeIcon icon={faPlay} />
                                                Run Now
                                            </span>
                                        </Button>
                                        <Button
                                            type={'button'}
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            onClick={() => editProfile(profile)}
                                        >
                                            <span css={tw`inline-flex items-center gap-1`}>
                                                <FontAwesomeIcon icon={faEdit} />
                                                Edit
                                            </span>
                                        </Button>
                                        <Button.Danger
                                            type={'button'}
                                            size={Button.Sizes.Small}
                                            variant={Button.Variants.Secondary}
                                            disabled={deletingIds.includes(profile.id)}
                                            onClick={() => removeProfile(profile.id)}
                                        >
                                            <span css={tw`inline-flex items-center gap-1`}>
                                                <FontAwesomeIcon icon={faTrashAlt} />
                                                Delete
                                            </span>
                                        </Button.Danger>
                                    </ActionDock>
                                </div>
                            </MagicCard>
                        );
                    })}
                </div>
            )}
        </ServerContentBlock>
    );
};
