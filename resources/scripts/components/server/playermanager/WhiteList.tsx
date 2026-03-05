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
import { PlayerEntry } from '@/components/server/playermanager/types';

interface Props {
  players: PlayerEntry[];
  search: string;
  onUpdate: () => void;
  selectedKey: string | null;
  onSelect: (player: InspectablePlayer) => void;
}

export default ({ players, search, onUpdate, selectedKey, onSelect }: Props) => {
  const filteredPlayers = useMemo(() => players.filter((item) => matchesEntrySearch(item, search)), [players, search]);

  return (
    <Section>
      <SectionHeader>
        <SectionTitle>Whitelist</SectionTitle>
        <SectionBadge>{filteredPlayers.length} Entries</SectionBadge>
      </SectionHeader>
      <SectionBody>
        {filteredPlayers.length < 1 ? (
          <EmptyState>
            {players.length < 1
              ? 'There are no players on the whitelist.'
              : 'No whitelist entries match your current filter.'}
          </EmptyState>
        ) : (
          filteredPlayers.map((item, key) => {
            const inspectable = buildInspectablePlayer(item, 'whitelist');
            const isSelected = selectedKey === inspectable.key;

            return (
              <ListItem key={`${inspectable.key}-${key}`} $selected={isSelected}>
                <div css={tw`grid grid-cols-1 lg:grid-cols-12 gap-3 items-start`}>
                  <div css={tw`flex items-center gap-3 min-w-0 lg:col-span-8`}>
                    <PlayerAvatar name={item?.name} uuid={item?.uuid} id={item?.id} alt={'Player Skin'} />
                    <div css={tw`min-w-0`}>
                      <Code>{item.name || 'Unknown Player'}</Code>
                      <Label>Username</Label>
                    </div>
                  </div>
                  <div css={tw`min-w-0 lg:col-span-4`}>
                    <Code>{item.uuid || 'Unavailable'}</Code>
                    <Label>UUID</Label>
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
                    title={'Remove from Whitelist'}
                    message={`Are you sure that you want to remove <b>${item.name}</b> from the whitelist?`}
                    buttonText={'Remove'}
                    command={`whitelist remove ${item.name}`}
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
