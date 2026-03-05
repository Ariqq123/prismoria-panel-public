import React, { useMemo } from 'react';
import tw from 'twin.macro';
import AskModal from '@/components/server/playermanager/AskModal';
import Button from '@/components/elements/Button';
import {
  Actions,
  Code,
  EmptyState,
  Label,
  ListItem,
  Section,
  SectionBadge,
  SectionBody,
  SectionHeader,
  SectionTitle,
} from '@/components/server/playermanager/styles';
import PlayerAvatar from '@/components/server/playermanager/PlayerAvatar';
import {
  buildInspectablePlayer,
  InspectablePlayer,
  matchesEntrySearch,
} from '@/components/server/playermanager/playerIdentity';
import { Players } from '@/components/server/playermanager/types';

interface Props {
  players: Players;
  search: string;
  onUpdate: () => void;
  selectedKey: string | null;
  onSelect: (player: InspectablePlayer) => void;
}

export default ({ players, search, onUpdate, selectedKey, onSelect }: Props) => {
  const filteredPlayers = useMemo(
    () => players.list.filter((item) => matchesEntrySearch(item, search)),
    [players.list, search]
  );

  return (
    <Section>
      <SectionHeader>
        <SectionTitle>Players</SectionTitle>
        <div css={tw`flex items-center gap-2`}>
          <SectionBadge>
            {players.players.online}/{players.players.max} Online
          </SectionBadge>
          <SectionBadge>{filteredPlayers.length} Listed</SectionBadge>
        </div>
      </SectionHeader>
      <SectionBody>
        {filteredPlayers.length < 1 ? (
          <EmptyState>
            {players.list.length < 1 ? 'There are no players on the server.' : 'No players match your current filter.'}
          </EmptyState>
        ) : (
          filteredPlayers.map((item, key) => {
            const inspectable = buildInspectablePlayer(item, 'online');
            const isSelected = selectedKey === inspectable.key;

            return (
              <ListItem key={`${inspectable.key}-${key}`} $selected={isSelected}>
                <div css={tw`grid grid-cols-1 lg:grid-cols-12 gap-3`}>
                  <div css={tw`flex items-center gap-3 min-w-0 lg:col-span-5`}>
                    <PlayerAvatar name={item?.name} uuid={item?.uuid} id={item?.id} alt={'Player Skin'} />
                    <div css={tw`min-w-0`}>
                      <Code>{item.name || 'Unknown Player'}</Code>
                      <Label>Username</Label>
                    </div>
                  </div>
                  <div css={tw`min-w-0 lg:col-span-4`}>
                    <Code>{item.id || 'Unavailable'}</Code>
                    <Label>UUID</Label>
                  </div>
                  <div css={tw`flex flex-wrap gap-2 lg:col-span-3 lg:justify-end`}>
                    {item.isOp && (
                      <span css={tw`px-2 py-1 rounded text-xs font-semibold bg-yellow-500 text-yellow-900`}>OP</span>
                    )}
                    {item.isWhitelist && (
                      <span css={tw`px-2 py-1 rounded text-xs font-semibold bg-green-500 text-green-900`}>
                        Whitelisted
                      </span>
                    )}
                  </div>
                </div>
                <Actions>
                  <Button
                    size={'xsmall'}
                    isSecondary
                    css={[
                      tw`m-0 normal-case tracking-normal whitespace-nowrap flex-1 sm:flex-none`,
                      { minWidth: '6.5rem' },
                    ]}
                    onClick={() => onSelect(inspectable)}
                  >
                    Inspect
                  </Button>
                  <AskModal
                    buttonColor={item.isOp ? 'red' : 'green'}
                    buttonText={item.isOp ? 'DEOP' : 'OP'}
                    title={`${item.isOp ? 'DEOP' : 'OP'} Player`}
                    message={`Are you sure that you want to ${item.isOp ? 'deop' : 'op'} <b>${item.name}</b>?`}
                    command={`${item.isOp ? 'deop' : 'op'} ${item.name}`}
                    onPerformed={() => onUpdate()}
                  />
                  <AskModal
                    buttonColor={item.isWhitelist ? 'grey' : 'primary'}
                    buttonText={item.isWhitelist ? 'Whitelist -' : 'Whitelist +'}
                    title={item.isWhitelist ? 'Remove Player from Whitelist' : 'Add Player to Whitelist'}
                    message={`Are you sure that you want to ${item.isWhitelist ? 'remove' : 'add'} <b>${
                      item.name
                    }</b> ${item.isWhitelist ? 'from' : 'to'} the whitelist?`}
                    command={`whitelist ${item.isWhitelist ? 'remove' : 'add'} ${item.name}`}
                    onPerformed={() => onUpdate()}
                  />
                  <AskModal
                    buttonColor={'red'}
                    buttonSecondary
                    buttonText={'Kick'}
                    title={'Kick Player'}
                    message={`Are you sure that you want to kick <b>${item.name}</b>?`}
                    command={`kick ${item.name}`}
                    onPerformed={() => onUpdate()}
                  />
                  <AskModal
                    buttonColor={'red'}
                    buttonText={'Ban'}
                    title={'Ban Player'}
                    message={`Are you sure that you want to ban <b>${item.name}</b>?`}
                    command={`ban ${item.name}`}
                    onPerformed={() => onUpdate()}
                  />
                  <AskModal
                    buttonColor={'red'}
                    buttonSecondary
                    buttonText={'Ban IP'}
                    title={'Ban IP'}
                    message={`Are you sure that you want to ip ban <b>${item.name}</b>?`}
                    command={`ban-ip ${item.name}`}
                    onPerformed={() => onUpdate()}
                  />
                </Actions>
              </ListItem>
            );
          })
        )}
      </SectionBody>
    </Section>
  );
};
