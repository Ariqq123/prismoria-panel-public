import React, { useMemo, useState, useEffect } from 'react';
import { Avatar } from '@/components/server/playermanager/styles';
import { normalizeUuid, sanitizePlayerName } from '@/components/server/playermanager/playerIdentity';

interface Props {
  name?: unknown;
  uuid?: unknown;
  id?: unknown;
  alt?: string;
}

const pushUnique = (list: string[], value: string | null) => {
  if (!value || list.includes(value)) {
    return;
  }

  list.push(value);
};

const buildAvatarCandidates = ({ name, uuid, id }: Props): string[] => {
  const candidates: string[] = [];
  const username = sanitizePlayerName(name);
  const avatarUsername = username?.startsWith('.') ? username.slice(1) : username;
  const parsedUuid = normalizeUuid(uuid) || normalizeUuid(id);

  // Prefer username to support cracked/offline-mode players.
  if (avatarUsername) {
    pushUnique(candidates, `https://mc-heads.net/avatar/${encodeURIComponent(avatarUsername)}/64`);
  }

  if (parsedUuid) {
    pushUnique(candidates, `https://mc-heads.net/avatar/${parsedUuid}/64`);
  }

  pushUnique(candidates, 'https://mc-heads.net/avatar/Steve/64');

  return candidates;
};

const PlayerAvatar = ({ name, uuid, id, alt = 'Player Avatar' }: Props) => {
  const candidates = useMemo(() => buildAvatarCandidates({ name, uuid, id }), [name, uuid, id]);
  const [candidateIndex, setCandidateIndex] = useState(0);

  useEffect(() => {
    setCandidateIndex(0);
  }, [candidates]);

  const currentSrc = candidates[Math.min(candidateIndex, candidates.length - 1)];

  return (
    <Avatar
      src={currentSrc}
      alt={alt}
      loading={'lazy'}
      referrerPolicy={'no-referrer'}
      onError={() => {
        setCandidateIndex((current) => {
          const next = current + 1;
          return next < candidates.length ? next : current;
        });
      }}
    />
  );
};

export default PlayerAvatar;
