<?php

namespace Pterodactyl\Console\Commands\AutoBackups;

use Illuminate\Console\Command;
use Pterodactyl\Services\AutoBackups\AutoBackupManagerService;

class ProcessAutoBackupsCommand extends Command
{
    protected $signature = 'p:auto-backups:process {--limit=20 : Maximum profiles to process in one run}';

    protected $description = 'Process due auto backup profiles and upload completed backups to remote destinations.';

    public function __construct(private AutoBackupManagerService $manager)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stats = $this->manager->processDueProfiles($limit);

        $this->line(sprintf(
            'Processed=%d queued=%d uploaded=%d failed=%d skipped=%d',
            $stats['processed'],
            $stats['queued'],
            $stats['uploaded'],
            $stats['failed'],
            $stats['skipped']
        ));

        return Command::SUCCESS;
    }
}

