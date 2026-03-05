import React, { useCallback, useEffect, useMemo, useState } from 'react';
import tw from 'twin.macro';
import http from '@/api/http';
import Input from '@/components/elements/Input';
import Select from '@/components/elements/Select';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import ContentBox from '@/components/elements/ContentBox';
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
import { faDownload, faLink, faSyncAlt } from '@fortawesome/free-solid-svg-icons';

const FLASH_KEY = 'server:versionchanger';

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
            <div css={tw`w-full max-w-6xl mx-auto space-y-4`}>
                <FlashMessageRender byKey={FLASH_KEY} />

                <ContentBox
                    title={'Minecraft Version Changer'}
                    headerAction={
                        <Button size={'small'} isSecondary onClick={loadTypes} isLoading={loadingTypes}>
                            <span css={tw`inline-flex items-center gap-2`}>
                                <FontAwesomeIcon icon={faSyncAlt} />
                                Refresh
                            </span>
                        </Button>
                    }
                >
                    <div css={tw`space-y-4`}>
                        <div css={tw`rounded-lg border border-neutral-600 bg-neutral-900/80 p-3 text-sm text-neutral-300`}>
                            Pick your server software, Minecraft version, and build, then click Install Selected Build
                            to download it automatically. You can also auto-update the startup jar value so the new
                            version runs on next start.
                        </div>

                        <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4`}>
                            <div css={tw`space-y-3`}>
                                <div>
                                    <p css={tw`text-xs uppercase tracking-wider text-neutral-400 mb-1`}>Server Type</p>
                                    {loadingTypes ? (
                                        <div css={tw`h-11 flex items-center`}>
                                            <Spinner size={'small'} />
                                        </div>
                                    ) : (
                                        <Select
                                            value={selectedType}
                                            onChange={(event) => setSelectedType(event.currentTarget.value)}
                                        >
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

                                <div css={tw`rounded-md border border-neutral-600 bg-neutral-800/70 p-3`}>
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

                                <div css={tw`flex flex-wrap items-center gap-2`}>
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
                                </div>
                            </div>

                            <div css={tw`space-y-3`}>
                                <div css={tw`rounded-lg border border-neutral-600 bg-neutral-900/80 p-4`}>
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

                                <div css={tw`rounded-lg border border-neutral-600 bg-neutral-900/80 p-4`}>
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
                                                Java Requirement:{' '}
                                                <span css={tw`text-neutral-100`}>{selectedVersionInfo?.java ?? 'Unknown'}</span>
                                            </p>
                                            <p css={tw`text-neutral-200`}>
                                                Published: <span css={tw`text-neutral-100`}>{renderBuildDate}</span>
                                            </p>
                                            <p css={tw`text-neutral-200 break-all`}>
                                                Download:{' '}
                                                <span css={tw`text-neutral-100`}>
                                                    {getBuildJarDownloadUrl(selectedBuild) || 'Unavailable'}
                                                </span>
                                            </p>

                                            {Array.isArray(selectedBuild.changes) && selectedBuild.changes.length > 0 && (
                                                <div css={tw`pt-2`}>
                                                    <p css={tw`text-xs uppercase tracking-wide text-neutral-400 mb-1`}>
                                                        Changelog
                                                    </p>
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

                                {!canReadFiles && (
                                    <div css={tw`rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200`}>
                                        You do not currently have file access for this server.
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </ContentBox>
            </div>
        </ServerContentBlock>
    );
};
