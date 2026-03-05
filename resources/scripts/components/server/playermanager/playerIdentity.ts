import { BanIpEntry, PlayerEntry } from '@/components/server/playermanager/types';

export type PlayerOrigin = 'online' | 'op' | 'whitelist' | 'ban' | 'ban-ip';

export interface InspectablePlayer {
  key: string;
  name: string | null;
  uuid: string | null;
  ip: string | null;
  source: string | null;
  starlightIdentifier: string | null;
  isOp: boolean;
  isWhitelist: boolean;
  isBanned: boolean;
  isIpBanned: boolean;
  origin: PlayerOrigin;
}

const asText = (value: unknown): string => String(value || '').trim();

export const sanitizePlayerName = (value: unknown): string | null => {
  const raw = asText(value);
  if (!raw) {
    return null;
  }

  const hasGeyserPrefix = raw.startsWith('.');
  const candidate = hasGeyserPrefix ? raw.slice(1) : raw;
  const normalized = candidate.replace(/[^a-zA-Z0-9_]/g, '');

  if (!normalized) {
    return null;
  }

  const capped = normalized.slice(0, 32);
  return hasGeyserPrefix ? `.${capped}` : capped;
};

export const normalizeUuid = (value: unknown): string | null => {
  const raw = asText(value).toLowerCase();
  if (!raw) {
    return null;
  }

  const compact = raw.replace(/-/g, '');
  if (!/^[0-9a-f]{32}$/.test(compact)) {
    return null;
  }

  return compact;
};

export const formatUuid = (value: unknown): string | null => {
  const compact = normalizeUuid(value);
  if (!compact) {
    return null;
  }

  return `${compact.slice(0, 8)}-${compact.slice(8, 12)}-${compact.slice(12, 16)}-${compact.slice(
    16,
    20
  )}-${compact.slice(20, 32)}`;
};

const resolveIdentity = (entry: Partial<PlayerEntry | BanIpEntry>) => {
  const name = sanitizePlayerName((entry as PlayerEntry).name);
  const uuid = normalizeUuid((entry as PlayerEntry).uuid) || normalizeUuid((entry as PlayerEntry).id);
  const ip = asText((entry as PlayerEntry).ip || (entry as BanIpEntry).ip) || null;
  const source = asText((entry as PlayerEntry).source || (entry as BanIpEntry).source) || null;

  return {
    name,
    uuid,
    ip,
    source,
    starlightIdentifier: name || uuid,
  };
};

export const buildPlayerKey = (entry: Partial<PlayerEntry | BanIpEntry>): string => {
  const identity = resolveIdentity(entry);
  if (identity.uuid) {
    return `uuid:${identity.uuid}`;
  }

  if (identity.name) {
    return `name:${identity.name.toLowerCase()}`;
  }

  if (identity.ip) {
    return `ip:${identity.ip}`;
  }

  if (identity.source) {
    return `source:${identity.source}`;
  }

  return 'unknown:entry';
};

export const buildInspectablePlayer = (
  entry: Partial<PlayerEntry | BanIpEntry>,
  origin: PlayerOrigin
): InspectablePlayer => {
  const identity = resolveIdentity(entry);

  return {
    key: buildPlayerKey(entry),
    name: identity.name,
    uuid: identity.uuid,
    ip: identity.ip,
    source: identity.source,
    starlightIdentifier: identity.starlightIdentifier,
    isOp: Boolean((entry as PlayerEntry).isOp) || origin === 'op',
    isWhitelist: Boolean((entry as PlayerEntry).isWhitelist) || origin === 'whitelist',
    isBanned: origin === 'ban',
    isIpBanned: origin === 'ban-ip',
    origin,
  };
};

export const matchesEntrySearch = (entry: Partial<PlayerEntry | BanIpEntry>, normalizedSearch: string): boolean => {
  if (!normalizedSearch) {
    return true;
  }

  const identity = resolveIdentity(entry);
  const searchText = [
    identity.name || '',
    identity.uuid || '',
    identity.ip || '',
    identity.source || '',
    asText((entry as PlayerEntry).id),
    asText((entry as PlayerEntry).name),
  ]
    .join(' ')
    .toLowerCase();

  return searchText.includes(normalizedSearch);
};
