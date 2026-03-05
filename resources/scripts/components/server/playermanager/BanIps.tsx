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
import {
  buildInspectablePlayer,
  InspectablePlayer,
  matchesEntrySearch,
} from '@/components/server/playermanager/playerIdentity';
import { BanIpEntry } from '@/components/server/playermanager/types';

interface Props {
  players: BanIpEntry[];
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
        <SectionTitle>Banned IPs</SectionTitle>
        <SectionBadge>{filteredPlayers.length} Entries</SectionBadge>
      </SectionHeader>
      <SectionBody>
        {filteredPlayers.length < 1 ? (
          <EmptyState>
            {players.length < 1
              ? 'There are no banned IP addresses on the list.'
              : 'No banned IP addresses match your current filter.'}
          </EmptyState>
        ) : (
          filteredPlayers.map((item, key) => {
            const inspectable = buildInspectablePlayer(item, 'ban-ip');
            const isSelected = selectedKey === inspectable.key;

            return (
              <ListItem key={`${inspectable.key}-${key}`} $selected={isSelected}>
                <div css={tw`grid grid-cols-1 lg:grid-cols-12 gap-3`}>
                  <div css={tw`min-w-0 lg:col-span-8`}>
                    <Code>{item.ip || 'Unavailable'}</Code>
                    <Label>IP Address</Label>
                  </div>
                  <div css={tw`min-w-0 lg:col-span-4`}>
                    <Code>{item.source || 'Unknown'}</Code>
                    <Label>Source</Label>
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
                    title={'Pardon IP Ban'}
                    message={`Are you sure that you want to pardon ip <b>${item.ip}</b>?`}
                    buttonText={'Pardon IP'}
                    command={`pardon-ip ${item.ip}`}
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
