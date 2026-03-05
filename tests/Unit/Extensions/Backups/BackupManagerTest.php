<?php

namespace Pterodactyl\Tests\Unit\Extensions\Backups;

use Mockery as m;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Extensions\Backups\BackupManager;
use League\Flysystem\FilesystemAdapter;

class BackupManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function testGetReturnsCachedAdapterWithoutResolvingAgain(): void
    {
        $manager = new BackupManager($this->app);
        $cached = m::mock(FilesystemAdapter::class);

        $manager->set('cached-disk', $cached);

        $this->assertSame($cached, $manager->adapter('cached-disk'));
        $this->assertSame($cached, $manager->adapter('cached-disk'));
    }

    public function testCustomCreatorIsResolvedByAdapterType(): void
    {
        config()->set('backups.disks.custom-disk', [
            'adapter' => 'custom-adapter',
        ]);

        $manager = new BackupManager($this->app);
        $custom = m::mock(FilesystemAdapter::class);

        $manager->extend('custom-adapter', fn ($app, array $config) => $custom);

        $this->assertSame($custom, $manager->adapter('custom-disk'));
        $this->assertSame($custom, $manager->adapter('custom-disk'));
    }
}
