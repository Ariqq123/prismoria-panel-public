export interface PlayerStats {
  max: number;
  online: number;
}

export interface PlayerEntry {
  name?: string | null;
  id?: string | null;
  uuid?: string | null;
  ip?: string | null;
  source?: string | null;
  isOp?: boolean;
  isWhitelist?: boolean;
}

export interface BanIpEntry {
  ip?: string | null;
  source?: string | null;
}

export interface Players {
  list: PlayerEntry[];
  players: PlayerStats;
}

export interface PlayerManagerResponse {
  players: Players;
  ops: PlayerEntry[];
  whitelist: PlayerEntry[];
  bans: PlayerEntry[];
  banIps: BanIpEntry[];
}
