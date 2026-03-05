import React, { useEffect, useMemo, useState } from 'react';
import useSWR from 'swr';
import tw from 'twin.macro';
import Select from '@/components/elements/Select';
import Spinner from '@/components/elements/Spinner';
import PlayerAvatar from '@/components/server/playermanager/PlayerAvatar';
import {
  Code,
  EmptyState,
  Label,
  Section,
  SectionBadge,
  SectionBody,
  SectionHeader,
  SectionTitle,
} from '@/components/server/playermanager/styles';
import { formatUuid, InspectablePlayer } from '@/components/server/playermanager/playerIdentity';
import {
  buildStarlightRenderUrl,
  fetchStarlightUserInfo,
  StarlightRenderType,
} from '@/api/server/playermanager/lunarEclipse';

interface Props {
  selected: InspectablePlayer | null;
}

const renderOptions: Array<{ value: StarlightRenderType; label: string }> = [
  { value: 'default', label: 'Default Pose' },
  { value: 'dungeons', label: 'Dungeons Pose' },
  { value: 'mojavatar', label: 'Mojavatar Pose' },
  { value: 'reading', label: 'Reading Pose' },
  { value: 'cheering', label: 'Cheering Pose' },
  { value: 'walking', label: 'Walking Pose' },
  { value: 'isometric', label: 'Isometric Pose' },
  { value: 'pointing', label: 'Pointing Pose' },
];

const originLabel: Record<InspectablePlayer['origin'], string> = {
  online: 'Online List',
  op: 'OP List',
  whitelist: 'Whitelist',
  ban: 'Banned Players',
  'ban-ip': 'Banned IPs',
};

const asCleanText = (value: unknown): string | null => {
  const parsed = String(value || '').trim();
  return parsed ? parsed : null;
};

export default ({ selected }: Props) => {
  const [renderType, setRenderType] = useState<StarlightRenderType>('default');
  const [renderFailed, setRenderFailed] = useState(false);

  useEffect(() => {
    setRenderType('default');
    setRenderFailed(false);
  }, [selected?.key]);

  const starlightIdentifier = selected?.starlightIdentifier || null;

  const { data, error, isValidating } = useSWR(
    starlightIdentifier ? ['playermanager:starlight-info', starlightIdentifier] : null,
    (_, identifier) => fetchStarlightUserInfo(identifier),
    {
      revalidateOnFocus: false,
      dedupingInterval: 5 * 60 * 1000,
      shouldRetryOnError: false,
    }
  );

  const renderUrl = useMemo(() => {
    if (!starlightIdentifier) {
      return null;
    }

    return buildStarlightRenderUrl(starlightIdentifier, renderType);
  }, [starlightIdentifier, renderType]);

  const fallbackRender = useMemo(() => {
    const avatarKey = asCleanText(selected?.name) || asCleanText(selected?.uuid) || 'Steve';
    return `https://mc-heads.net/body/${encodeURIComponent(avatarKey)}/right`;
  }, [selected?.name, selected?.uuid]);

  const uuidLabel = formatUuid(data?.playerUUID || selected?.uuid);
  const skinTypeLabel = asCleanText(data?.skinType)?.toUpperCase() || 'UNKNOWN';
  const textureDimensions =
    Number.isFinite(data?.skinTextureWidth) && Number.isFinite(data?.skinTextureHeight)
      ? `${data?.skinTextureWidth} x ${data?.skinTextureHeight}`
      : 'Unknown';
  const hasCape = asCleanText(data?.userCape) ? 'Yes' : 'No';

  return (
    <Section css={tw`2xl:sticky 2xl:top-24`}>
      <SectionHeader>
        <SectionTitle>Player Inspector</SectionTitle>
        {selected && <SectionBadge>{originLabel[selected.origin]}</SectionBadge>}
      </SectionHeader>
      <SectionBody>
        {!selected ? (
          <EmptyState>Select a player from any list to load advanced details and 3D skin preview.</EmptyState>
        ) : (
          <div css={tw`space-y-4`}>
            <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/70 p-3`}>
              <div css={tw`flex items-center gap-3 min-w-0`}>
                <PlayerAvatar
                  name={selected.name}
                  uuid={selected.uuid}
                  id={selected.uuid}
                  alt={'Selected player avatar'}
                />
                <div css={tw`min-w-0`}>
                  <Code>{selected.name || 'Unknown Player'}</Code>
                  <Label>Username</Label>
                </div>
              </div>
              {uuidLabel && (
                <div css={tw`mt-3`}>
                  <Code>{uuidLabel}</Code>
                  <Label>UUID</Label>
                </div>
              )}
              {selected.ip && (
                <div css={tw`mt-3`}>
                  <Code>{selected.ip}</Code>
                  <Label>IP Address</Label>
                </div>
              )}
            </div>

            <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/70 p-3`}>
              <div css={tw`flex items-center justify-between gap-3 mb-3`}>
                <p css={tw`text-sm font-semibold text-neutral-100`}>3D Skin Preview</p>
                <Select
                  value={renderType}
                  onChange={(event) => setRenderType(event.currentTarget.value as StarlightRenderType)}
                  css={tw`w-40 text-xs`}
                  disabled={!starlightIdentifier}
                >
                  {renderOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </Select>
              </div>

                            <div css={tw`rounded-md border border-neutral-700 bg-neutral-900 overflow-hidden mx-auto w-full max-w-sm`}>
                                <div css={tw`relative w-full`} style={{ paddingBottom: '100%' }}>
                                    <div css={tw`absolute inset-0 flex items-center justify-center`}>
                    {!starlightIdentifier ? (
                      <p css={tw`text-xs text-neutral-500 px-4 text-center`}>
                        No valid username or UUID available for this entry.
                      </p>
                    ) : renderUrl && !renderFailed ? (
                      <img
                        src={renderUrl}
                        alt={`${selected.name || 'Player'} 3D preview`}
                        css={tw`w-full h-full object-contain`}
                        loading={'lazy'}
                        referrerPolicy={'no-referrer'}
                        onError={() => setRenderFailed(true)}
                      />
                    ) : (
                      <img
                        src={fallbackRender}
                        alt={`${selected.name || 'Player'} fallback preview`}
                        css={tw`w-full h-full object-contain`}
                        loading={'lazy'}
                        referrerPolicy={'no-referrer'}
                      />
                    )}

                    {isValidating && (
                      <div
                        css={tw`absolute inset-0 bg-neutral-900 flex items-center justify-center`}
                        style={{ opacity: 0.75 }}
                      >
                        <Spinner size={'small'} />
                      </div>
                    )}
                  </div>
                </div>
              </div>
              <p css={tw`text-[11px] text-neutral-500 mt-2`}>
                Powered by LunarEclipse Starlight API. Render may fall back for unknown players.
              </p>
            </div>

            <div css={tw`grid grid-cols-2 gap-2`}>
              <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/60 px-3 py-2`}>
                <p css={tw`text-[10px] uppercase tracking-wide text-neutral-400`}>OP</p>
                <p css={tw`text-sm font-semibold text-neutral-100`}>{selected.isOp ? 'Yes' : 'No'}</p>
              </div>
              <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/60 px-3 py-2`}>
                <p css={tw`text-[10px] uppercase tracking-wide text-neutral-400`}>Whitelisted</p>
                <p css={tw`text-sm font-semibold text-neutral-100`}>{selected.isWhitelist ? 'Yes' : 'No'}</p>
              </div>
              <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/60 px-3 py-2`}>
                <p css={tw`text-[10px] uppercase tracking-wide text-neutral-400`}>Banned</p>
                <p css={tw`text-sm font-semibold text-neutral-100`}>{selected.isBanned ? 'Yes' : 'No'}</p>
              </div>
              <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/60 px-3 py-2`}>
                <p css={tw`text-[10px] uppercase tracking-wide text-neutral-400`}>IP Banned</p>
                <p css={tw`text-sm font-semibold text-neutral-100`}>{selected.isIpBanned ? 'Yes' : 'No'}</p>
              </div>
            </div>

            <div css={tw`rounded-md border border-neutral-700 bg-neutral-800/70 p-3 space-y-3`}>
              <div>
                <Code>{skinTypeLabel}</Code>
                <Label>Skin Type</Label>
              </div>
              <div>
                <Code>{textureDimensions}</Code>
                <Label>Texture Size</Label>
              </div>
              <div>
                <Code>{hasCape}</Code>
                <Label>Cape Available</Label>
              </div>
              {data?.skinUrl && (
                <a
                  href={data.skinUrl}
                  target={'_blank'}
                  rel={'noopener noreferrer'}
                  css={tw`text-xs text-red-300 hover:text-red-200 transition-colors break-all`}
                >
                  Open Skin Texture
                </a>
              )}
              {error && (
                <p css={tw`text-xs text-yellow-300`}>
                  Unable to fetch full skin metadata right now. Showing available local data.
                </p>
              )}
            </div>
          </div>
        )}
      </SectionBody>
    </Section>
  );
};
