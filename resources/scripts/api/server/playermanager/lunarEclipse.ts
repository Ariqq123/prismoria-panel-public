export interface StarlightUserInfo {
  playerUUID?: string;
  skinUrl?: string;
  processedSkinUrl?: string;
  userCape?: string;
  skinType?: string;
  skinTextureWidth?: number;
  skinTextureHeight?: number;
  error?: string;
}

export type StarlightRenderType =
  | 'default'
  | 'dungeons'
  | 'reading'
  | 'cheering'
  | 'walking'
  | 'marching'
  | 'isometric'
  | 'pointing'
  | 'mojavatar';

const STARDLIGHT_BASE = 'https://starlightskins.lunareclipse.studio';

const readResponseError = async (response: Response): Promise<string> => {
  try {
    const payload = (await response.json()) as { error?: string };
    if (payload && typeof payload.error === 'string' && payload.error.trim() !== '') {
      return payload.error;
    }
  } catch {
    // Ignore body parse failure.
  }

  return `Starlight request failed with status ${response.status}.`;
};

export const buildStarlightRenderUrl = (identifier: string, renderType: StarlightRenderType): string => {
  const safeIdentifier = encodeURIComponent(identifier.trim());
  const params = new URLSearchParams({
    borderHighlight: 'true',
    borderHighlightColor: 'ef4444',
    dropShadow: 'true',
  });

  return `${STARDLIGHT_BASE}/render/${renderType}/${safeIdentifier}/full?${params.toString()}`;
};

export const fetchStarlightUserInfo = async (identifier: string): Promise<StarlightUserInfo> => {
  const safeIdentifier = encodeURIComponent(identifier.trim());
  const response = await fetch(`${STARDLIGHT_BASE}/info/user/${safeIdentifier}`, {
    method: 'GET',
    credentials: 'omit',
  });

  if (!response.ok) {
    throw new Error(await readResponseError(response));
  }

  return (await response.json()) as StarlightUserInfo;
};
