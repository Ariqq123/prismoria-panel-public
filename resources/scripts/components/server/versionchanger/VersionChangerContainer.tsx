import React, { useCallback, useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import styled, { keyframes } from 'styled-components/macro';
import http from '@/api/http';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import FlashMessageRender from '@/components/FlashMessageRender';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import { usePermissions } from '@/plugins/usePermissions';
import updateStartupVariable from '@/api/server/updateStartupVariable';
import {
    McJarsBuild,
    McJarsType,
    McJarsVersion,
    fetchMcJarsBuilds,
    fetchMcJarsTypes,
    fetchMcJarsVersions,
    getBuildJarDownloadUrl,
    suggestJarFileName,
} from '@/api/server/versionchanger/mcjars';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDownload, faLink, faSyncAlt, faServer, faCodeBranch, faCubes } from '@fortawesome/free-solid-svg-icons';

const FLASH_KEY = 'server:versionchanger';

const borderFlow = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const auroraText = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const MainLayout = styled.div`
    ${tw`w-full max-w-6xl mx-auto space-y-4`};
`;

const MagicCard = styled.div<{ $interactive?: boolean }>`
    ${tw`relative overflow-hidden rounded-xl border border-neutral-700 p-4 md:p-5`};
    background: linear-gradient(140deg, rgba(17, 24, 39, 0.93) 0%, rgba(9, 13, 20, 0.96) 56%, rgba(16, 18, 24, 0.98) 100%);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.3);
    transition: transform 240ms cubic-bezier(0.22, 1, 0.36, 1), border-color 220ms ease, box-shadow 220ms ease;

    &::before {
        content: '';
        position: absolute;
        inset: -35% -12%;
        pointer-events: none;
        background: radial-gradient(circle at top right, rgba(248, 113, 113, 0.16), transparent 58%);
    }

    &::after {
        content: '';
        position: absolute;
        top: -180%;
        left: -42%;
        width: 72%;
        height: 360%;
        pointer-events: none;
        background: linear-gradient(120deg, rgba(248, 113, 113, 0), rgba(248, 113, 113, 0.18), rgba(96, 165, 250, 0));
        transform: rotate(14deg) translateX(-36%);
        transition: transform 540ms cubic-bezier(0.22, 1, 0.36, 1), opacity 320ms ease;
        opacity: 0;
    }

    ${({ $interactive }) =>
        $interactive
            ? `
        &:hover {
            transform: translateY(-2px);
            border-color: rgba(248, 113, 113, 0.46);
            box-shadow: 0 20px 44px rgba(0, 0, 0, 0.38);
        }
        &:hover::after {
            opacity: 1;
            transform: rotate(14deg) translateX(136%);
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
    background: linear-gradient(
            125deg,
            rgba(248, 113, 113, 0.44),
            rgba(251, 191, 36, 0.32),
            rgba(96, 165, 250, 0.28),
            rgba(248, 113, 113, 0.44)
        )
        border-box;
    background-size: 250% 250%;
    animation: ${borderFlow} 6s ease infinite;
    -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
`;

const HeroTitle = styled.h2`
    ${tw`text-lg md:text-xl font-semibold tracking-wide`};
    background: linear-gradient(96deg, #fde68a, #fca5a5, #93c5fd, #fca5a5);
    background-size: 220% 220%;
    animation: ${auroraText} 7.2s ease infinite;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
`;

const ActionDock = styled.div`
    ${tw`flex flex-wrap gap-2`};

    & > button {
        position: relative;
        overflow: hidden;
        isolation: isolate;
        transition: transform 220ms cubic-bezier(0.22, 1, 0.36, 1), box-shadow 220ms ease, border-color 220ms ease;
    }

    & > button::before {
        content: '';
        position: absolute;
        inset: -2px;
        background: linear-gradient(115deg, rgba(250, 204, 21, 0), rgba(250, 204, 21, 0.24), rgba(59, 130, 246, 0));
        transform: translateX(-125%);
        transition: transform 440ms cubic-bezier(0.22, 1, 0.36, 1);
        pointer-events: none;
        z-index: 0;
    }

    & > button:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.28);
        border-color: rgba(248, 113, 113, 0.46);
    }

    & > button:hover::before {
        transform: translateX(125%);
    }

    & > button > span {
        position: relative;
        z-index: 1;
    }
`;

const InfoChip = styled.div`
    ${tw`rounded-lg border border-neutral-700 bg-black/30 px-3 py-2`};
`;

const jarVariablePriority = ['SERVER_JARFILE', 'SERVER_JAR_FILE', 'JARFILE', 'JAR_FILE', 'SERVER_JAR'];

const normalizeFileName = (value: string): string => {
    const cleaned = value.trim().replace(/[^a-zA-Z0-9._-]/g, '-');
    const fallbackSafeName = cleaned.length > 0 ? cleaned : 'server.jar';

    if (fallbackSafeName.toLowerCase().endsWith('.jar')) {
        return fallbackSafeName;
    }

    return `${fallbackSafeName}.jar`;
};

const hasTokenMatch = (haystack: string, token: string): boolean => {
    const escaped = token
        .trim()
        .toLowerCase()
        .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
        .replace(/[_\s-]+/g, '[_\\s-]*');

    if (!escaped) {
        return false;
    }

    const expression = new RegExp(`(^|[^a-z0-9])${escaped}([^a-z0-9]|$)`, 'i');
    return expression.test(haystack);
};

const findJarVariable = (variables: Array<{ envVariable: string; name: string; description: string; isEditable: boolean }>) => {
    for (const env of jarVariablePriority) {
        const match = variables.find((variable) => variable.envVariable.toUpperCase() === env);
        if (match) {
            return match;
        }
    }

    return variables.find((variable) => variable.envVariable.toUpperCase().includes('JAR')) || null;
};

const detectServerType = (server: any, types: McJarsType[]): string | null => {
    if (!server || types.length === 0) {
        return null;
    }

    const typeSet = new Set(types.map((type) => type.key.toUpperCase()));
    const variables = Array.isArray(server.variables) ? server.variables : [];

    for (const variable of variables) {
        const value = typeof variable?.serverValue === 'string' && variable.serverValue.trim().length > 0
            ? variable.serverValue
            : typeof variable?.defaultValue === 'string'
                ? variable.defaultValue
                : '';
        const direct = value.trim().toUpperCase().replace(/[^A-Z0-9_]+/g, '_');
        if (typeSet.has(direct)) {
            return direct;
        }
    }

    const haystack = [
        server.name,
        server.invocation,
        server.dockerImage,
        ...variables.map((variable: any) =>
            [
                variable?.name || '',
                variable?.description || '',
                variable?.envVariable || '',
                variable?.serverValue || '',
                variable?.defaultValue || '',
            ].join(' ')
        ),
    ]
        .filter((value) => typeof value === 'string')
        .join(' ')
        .toLowerCase();

    const manualAliases: Record<string, string[]> = {
        PAPER: ['paper'],
        PURPUR: ['purpur'],
        PUFFERFISH: ['pufferfish'],
        SPIGOT: ['spigot'],
        FOLIA: ['folia'],
        VANILLA: ['vanilla', 'mojang'],
        FORGE: ['forge'],
        NEOFORGE: ['neoforge', 'neo forge'],
        FABRIC: ['fabric'],
        LEGACY_FABRIC: ['legacy fabric'],
        QUILT: ['quilt'],
        WATERFALL: ['waterfall'],
        VELOCITY: ['velocity'],
        BUNGEECORD: ['bungeecord', 'bungee'],
        MOHIST: ['mohist'],
        MAGMA: ['magma'],
        ARCLIGHT: ['arclight'],
        SPONGE: ['sponge'],
    };

    for (const type of types) {
        const aliases = new Set<string>([
            type.key,
            type.key.replace(/_/g, ' '),
            type.name,
            ...type.compatibility,
            ...(manualAliases[type.key] || []),
        ]);

        for (const alias of aliases) {
            if (hasTokenMatch(haystack, alias)) {
                return type.key;
            }
        }
    }

    return types.some((type) => type.key === 'PAPER') ? 'PAPER' : types[0]?.key || null;
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid || '');
    const server = ServerContext.useStoreState((state) => state.server.data);
    const [canReadFiles, canCreateFiles, canStartupUpdate] = usePermissions([
        'file.read',
        'file.create',
        'startup.update',
    ]);
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    const [types, setTypes] = useState<McJarsType[]>([]);
    const [selectedType, setSelectedType] = useState('');
    const [versions, setVersions] = useState<McJarsVersion[]>([]);
    const [selectedVersion, setSelectedVersion] = useState('');
    const [builds, setBuilds] = useState<McJarsBuild[]>([]);
    const [selectedBuildId, setSelectedBuildId] = useState<number | null>(null);
    const [fileName, setFileName] = useState('server.jar');
    const [applyStartupJar, setApplyStartupJar] = useState(true);

    const [loadingTypes, setLoadingTypes] = useState(false);
    const [loadingVersions, setLoadingVersions] = useState(false);
    const [loadingBuilds, setLoadingBuilds] = useState(false);
    const [installing, setInstalling] = useState(false);

    const selectedBuild = useMemo(
        () => builds.find((build) => build.id === selectedBuildId) || null,
        [builds, selectedBuildId]
    );
    const selectedTypeInfo = useMemo(
        () => types.find((type) => type.key === selectedType) || null,
        [types, selectedType]
    );
    const selectedVersionInfo = useMemo(
        () => versions.find((version) => version.id === selectedVersion) || null,
        [versions, selectedVersion]
    );

    const jarVariable = useMemo(() => {
        const variables = Array.isArray(server?.variables) ? server.variables : [];
        return findJarVariable(variables);
    }, [server?.variables]);

    const loadTypes = useCallback(async () => {
        setLoadingTypes(true);

        try {
            const fetchedTypes = await fetchMcJarsTypes();
            setTypes(fetchedTypes);

            if (fetchedTypes.length > 0) {
                const detected = detectServerType(server, fetchedTypes);
                setSelectedType(detected || fetchedTypes[0].key);
            }
        } catch (error) {
            clearAndAddHttpError({ key: FLASH_KEY, error });
        } finally {
            setLoadingTypes(false);
        }
    }, [clearAndAddHttpError, server]);

    useEffect(() => {
        clearFlashes(FLASH_KEY);
        loadTypes();
    }, [clearFlashes, loadTypes]);

    useEffect(() => {
        if (!selectedType) {
            setVersions([]);
            setSelectedVersion('');
            return;
        }

        let active = true;
        setLoadingVersions(true);
        setVersions([]);
        setSelectedVersion('');
        setBuilds([]);
        setSelectedBuildId(null);

        fetchMcJarsVersions(selectedType)
            .then((result) => {
                if (!active) {
                    return;
                }

                setVersions(result);
                if (result.length > 0) {
                    setSelectedVersion(result[0].id);
                }
            })
            .catch((error) => {
                if (!active) {
                    return;
                }

                clearAndAddHttpError({ key: FLASH_KEY, error });
            })
            .finally(() => {
                if (active) {
                    setLoadingVersions(false);
                }
            });

        return () => {
            active = false;
        };
    }, [selectedType, clearAndAddHttpError]);

    useEffect(() => {
        if (!selectedType || !selectedVersion) {
            setBuilds([]);
            setSelectedBuildId(null);
            return;
        }

        let active = true;
        setLoadingBuilds(true);
        setBuilds([]);
        setSelectedBuildId(null);

        fetchMcJarsBuilds(selectedType, selectedVersion)
            .then((result) => {
                if (!active) {
                    return;
                }

                setBuilds(result);
                if (result.length > 0) {
                    setSelectedBuildId(result[0].id);
                }
            })
            .catch((error) => {
                if (!active) {
                    return;
                }

                clearAndAddHttpError({ key: FLASH_KEY, error });
            })
            .finally(() => {
                if (active) {
                    setLoadingBuilds(false);
                }
            });

        return () => {
            active = false;
        };
    }, [selectedType, selectedVersion, clearAndAddHttpError]);

    useEffect(() => {
        if (!selectedBuild || !selectedType || !selectedVersion) {
            return;
        }

        setFileName(suggestJarFileName(selectedType, selectedVersion, selectedBuild));
    }, [selectedBuild, selectedType, selectedVersion]);

    const installSelectedBuild = async () => {
        if (!selectedBuild) {
            addFlash({
                key: FLASH_KEY,
                type: 'error',
                message: 'Please select a build before installing.',
            });
            return;
        }

        if (!canCreateFiles) {
            addFlash({
                key: FLASH_KEY,
                type: 'error',
                message: 'You do not have permission to pull files on this server.',
            });
            return;
        }

        const downloadUrl = getBuildJarDownloadUrl(selectedBuild);
        if (!downloadUrl) {
            addFlash({
                key: FLASH_KEY,
                type: 'error',
                message: 'This build does not include a downloadable JAR URL.',
            });
            return;
        }

        const outputName = normalizeFileName(fileName);
        clearFlashes(FLASH_KEY);
        setInstalling(true);

        try {
            await http.post(`/api/client/servers/${uuid}/files/pull`, {
                url: downloadUrl,
                directory: '/',
                filename: outputName,
                use_header: true,
                foreground: true,
            });

            let startupMessage = '';
            if (applyStartupJar && jarVariable && jarVariable.isEditable && canStartupUpdate) {
                await updateStartupVariable(uuid, jarVariable.envVariable, outputName);
                startupMessage = ` Startup variable ${jarVariable.envVariable} updated.`;
            }

            addFlash({
                key: FLASH_KEY,
                type: 'success',
                message: `Downloaded ${outputName} to server root.${startupMessage}`,
            });
        } catch (error) {
            clearAndAddHttpError({ key: FLASH_KEY, error });
        } finally {
            setInstalling(false);
        }
    };

    const copyDownloadUrl = async () => {
        const url = getBuildJarDownloadUrl(selectedBuild);
        if (!url) {
            return;
        }

        try {
            await navigator.clipboard.writeText(url);
            addFlash({
                key: FLASH_KEY,
                type: 'success',
                message: 'Build download URL copied to clipboard.',
            });
        } catch {
            addFlash({
                key: FLASH_KEY,
                type: 'error',
                message: 'Unable to copy to clipboard on this browser.',
            });
        }
    };

    const canApplyStartup = !!(jarVariable && jarVariable.isEditable && canStartupUpdate);
    const renderBuildDate = selectedBuild?.created
        ? new Date(selectedBuild.created).toLocaleString()
        : 'Unknown';

    return (
        <ServerContentBlock title={'Version Changer'}>
            <MainLayout>
                <FlashMessageRender byKey={FLASH_KEY} />

                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10 space-y-4`}>
                        <div css={tw`flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between`}>
                            <div css={tw`space-y-1`}>
                                <HeroTitle>Minecraft Version Changer</HeroTitle>
                                <p css={tw`text-sm text-neutral-300`}>
                                    Pilih software, versi Minecraft, dan build yang ingin dipasang. File JAR akan diunduh
                                    langsung ke root server.
                                </p>
                            </div>
                            <ActionDock>
                                <Button size={'small'} isSecondary onClick={loadTypes} isLoading={loadingTypes}>
                                    <span css={tw`inline-flex items-center gap-2`}>
                                        <FontAwesomeIcon icon={faSyncAlt} />
                                        Refresh
                                    </span>
                                </Button>
                            </ActionDock>
                        </div>

                        <div css={tw`grid grid-cols-1 sm:grid-cols-3 gap-2`}>
                            <InfoChip>
                                <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Type</p>
                                <p css={tw`text-sm text-neutral-100 truncate`}>
                                    <FontAwesomeIcon icon={faServer} css={tw`mr-2 text-red-300`} />
                                    {selectedTypeInfo?.name || 'Not selected'}
                                </p>
                            </InfoChip>
                            <InfoChip>
                                <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Version</p>
                                <p css={tw`text-sm text-neutral-100 truncate`}>
                                    <FontAwesomeIcon icon={faCodeBranch} css={tw`mr-2 text-red-300`} />
                                    {selectedVersion || 'Not selected'}
                                </p>
                            </InfoChip>
                            <InfoChip>
                                <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Build</p>
                                <p css={tw`text-sm text-neutral-100 truncate`}>
                                    <FontAwesomeIcon icon={faCubes} css={tw`mr-2 text-red-300`} />
                                    {selectedBuild ? `#${selectedBuild.buildNumber}` : 'Not selected'}
                                </p>
                            </InfoChip>
                        </div>
                    </div>
                </MagicCard>

                <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4`}>
                    <MagicCard $interactive>
                        <div css={tw`relative z-10 space-y-4`}>
                            <div>
                                <p css={tw`text-xs uppercase tracking-wider text-neutral-400 mb-1`}>Server Type</p>
                                {loadingTypes ? (
                                    <div css={tw`h-11 flex items-center`}>
                                        <Spinner size={'small'} />
                                    </div>
                                ) : (
                                    <Select value={selectedType} onChange={(event) => setSelectedType(event.currentTarget.value)}>
                                        {types.map((type) => (
                                            <option key={type.key} value={type.key}>
                                                {type.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            </div>

                            <div>
                                <p css={tw`text-xs uppercase tracking-wider text-neutral-400 mb-1`}>Minecraft Version</p>
                                {loadingVersions ? (
                                    <div css={tw`h-11 flex items-center`}>
                                        <Spinner size={'small'} />
                                    </div>
                                ) : (
                                    <Select
                                        value={selectedVersion}
                                        onChange={(event) => setSelectedVersion(event.currentTarget.value)}
                                        disabled={versions.length === 0}
                                    >
                                        {versions.length === 0 ? (
                                            <option value={''}>No versions available</option>
                                        ) : (
                                            versions.map((version) => (
                                                <option key={version.id} value={version.id}>
                                                    {version.id} {version.supported ? '(supported)' : '(legacy)'}
                                                </option>
                                            ))
                                        )}
                                    </Select>
                                )}
                            </div>

                            <div>
                                <p css={tw`text-xs uppercase tracking-wider text-neutral-400 mb-1`}>Build</p>
                                {loadingBuilds ? (
                                    <div css={tw`h-11 flex items-center`}>
                                        <Spinner size={'small'} />
                                    </div>
                                ) : (
                                    <Select
                                        value={selectedBuildId ? String(selectedBuildId) : ''}
                                        onChange={(event) => setSelectedBuildId(Number(event.currentTarget.value))}
                                        disabled={builds.length === 0}
                                    >
                                        {builds.length === 0 ? (
                                            <option value={''}>No builds available</option>
                                        ) : (
                                            builds.map((build) => (
                                                <option key={build.id} value={build.id}>
                                                    {build.name} (#{build.buildNumber})
                                                </option>
                                            ))
                                        )}
                                    </Select>
                                )}
                            </div>

                            <div>
                                <p css={tw`text-xs uppercase tracking-wider text-neutral-400 mb-1`}>Output Jar Name</p>
                                <Input
                                    value={fileName}
                                    onChange={(event) => setFileName(event.currentTarget.value)}
                                    placeholder={'server.jar'}
                                />
                            </div>

                            <div css={tw`rounded-lg border border-neutral-700 bg-black/25 p-3`}>
                                <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                    <input
                                        type={'checkbox'}
                                        checked={applyStartupJar}
                                        onChange={(event) => setApplyStartupJar(event.currentTarget.checked)}
                                        disabled={!canApplyStartup}
                                    />
                                    Update startup jar variable automatically
                                </label>
                                <p css={tw`mt-2 text-xs text-neutral-400`}>
                                    {canApplyStartup && jarVariable
                                        ? `Will update ${jarVariable.envVariable} after download.`
                                        : 'Startup variable update is unavailable for this server or your permission level.'}
                                </p>
                            </div>

                            <ActionDock>
                                <Button
                                    color={'primary'}
                                    onClick={installSelectedBuild}
                                    disabled={!canCreateFiles || !selectedBuild || installing}
                                    isLoading={installing}
                                >
                                    <span css={tw`inline-flex items-center gap-2`}>
                                        <FontAwesomeIcon icon={faDownload} />
                                        Install Selected Build
                                    </span>
                                </Button>
                                <Button
                                    type={'button'}
                                    isSecondary
                                    onClick={copyDownloadUrl}
                                    disabled={!selectedBuild || !getBuildJarDownloadUrl(selectedBuild)}
                                >
                                    <span css={tw`inline-flex items-center gap-2`}>
                                        <FontAwesomeIcon icon={faLink} />
                                        Copy URL
                                    </span>
                                </Button>
                            </ActionDock>
                        </div>
                    </MagicCard>

                    <div css={tw`space-y-4`}>
                        <MagicCard $interactive>
                            <div css={tw`relative z-10`}>
                                <h3 css={tw`text-sm uppercase tracking-wide text-neutral-300 mb-3`}>Selected Type</h3>
                                {!selectedTypeInfo ? (
                                    <p css={tw`text-sm text-neutral-400`}>No type selected.</p>
                                ) : (
                                    <div css={tw`space-y-3`}>
                                        <div css={tw`flex items-start gap-3`}>
                                            <img
                                                src={selectedTypeInfo.icon}
                                                alt={`${selectedTypeInfo.name} icon`}
                                                css={tw`h-10 w-10 rounded-md object-cover border border-neutral-700`}
                                            />
                                            <div css={tw`min-w-0`}>
                                                <p css={tw`text-base font-semibold text-neutral-100`}>{selectedTypeInfo.name}</p>
                                                <p css={tw`text-xs text-neutral-400`}>
                                                    {selectedTypeInfo.experimental ? 'Experimental' : 'Stable'} ·{' '}
                                                    {selectedTypeInfo.builds.toLocaleString()} builds
                                                </p>
                                            </div>
                                        </div>
                                        <p css={tw`text-sm text-neutral-300`}>{selectedTypeInfo.description}</p>
                                        {selectedTypeInfo.homepage && (
                                            <a
                                                href={selectedTypeInfo.homepage}
                                                target={'_blank'}
                                                rel={'noreferrer noopener'}
                                                css={tw`text-xs text-red-300 hover:text-red-200`}
                                            >
                                                View project homepage
                                            </a>
                                        )}
                                    </div>
                                )}
                            </div>
                        </MagicCard>

                        <MagicCard $interactive>
                            <div css={tw`relative z-10`}>
                                <h3 css={tw`text-sm uppercase tracking-wide text-neutral-300 mb-3`}>Build Details</h3>
                                {!selectedBuild ? (
                                    <p css={tw`text-sm text-neutral-400`}>No build selected.</p>
                                ) : (
                                    <div css={tw`space-y-2 text-sm`}>
                                        <p css={tw`text-neutral-200`}>
                                            Build: <span css={tw`text-neutral-100`}>{selectedBuild.name}</span>
                                        </p>
                                        <p css={tw`text-neutral-200`}>
                                            Build Number: <span css={tw`text-neutral-100`}>{selectedBuild.buildNumber}</span>
                                        </p>
                                        <p css={tw`text-neutral-200`}>
                                            Java Requirement: <span css={tw`text-neutral-100`}>{selectedVersionInfo?.java ?? 'Unknown'}</span>
                                        </p>
                                        <p css={tw`text-neutral-200`}>
                                            Published: <span css={tw`text-neutral-100`}>{renderBuildDate}</span>
                                        </p>
                                        <p css={tw`text-neutral-200 break-all`}>
                                            Download:{' '}
                                            <span css={tw`text-neutral-100`}>{getBuildJarDownloadUrl(selectedBuild) || 'Unavailable'}</span>
                                        </p>

                                        {Array.isArray(selectedBuild.changes) && selectedBuild.changes.length > 0 && (
                                            <div css={tw`pt-2`}>
                                                <p css={tw`text-xs uppercase tracking-wide text-neutral-400 mb-1`}>Changelog</p>
                                                <ul css={tw`space-y-1 text-neutral-300 text-sm max-h-32 overflow-auto pr-1`}>
                                                    {selectedBuild.changes.slice(0, 8).map((change, index) => (
                                                        <li key={`${selectedBuild.id}-${index}`} css={tw`leading-snug`}>
                                                            • {change}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </MagicCard>

                        {!canReadFiles && (
                            <MagicCard>
                                <div css={tw`relative z-10 text-sm text-red-200`}>
                                    You do not currently have file access for this server.
                                </div>
                            </MagicCard>
                        )}
                    </div>
                </div>
            </MainLayout>
        </ServerContentBlock>
    );
};
