<?php

declare(strict_types=1);

/*
 * This file is part of SAC Pilatus Restic Backup.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/sac-pilatus-restic-backup
 */

namespace Markocupic\SacPilatusResticBackup\Tests\DependencyInjection;

use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Markocupic\SacPilatusResticBackup\DependencyInjection\MarkocupicSacPilatusResticBackupExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class MarkocupicSacPilatusResticBackupExtensionTest extends TestCase
{
    public function testRegistersAFactoryPerConfiguration(): void
    {
        $container = new ContainerBuilder();

        (new MarkocupicSacPilatusResticBackupExtension())->load([[
            'default' => [
                'base_directory' => '/var/www/project',
                'items_to_backup' => ['files'],
                'repository' => '/var/backups/restic',
                'password' => 'secret',
            ],
            'database' => [
                'base_directory' => '/var/www/project',
                'repository' => '/var/backups/restic-db',
                'password' => 'secret-db',
                'excludes' => ['var/cache'],
            ],
        ]], $container);

        $this->assertTrue($container->hasDefinition('markocupic_sac_pilatus_restic_backup.factory.default'));
        $this->assertTrue($container->hasDefinition('markocupic_sac_pilatus_restic_backup.factory.database'));

        $definition = $container->getDefinition('markocupic_sac_pilatus_restic_backup.factory.database');

        $this->assertSame(ResticFactory::class, $definition->getClass());
        $this->assertSame('/var/backups/restic-db', $definition->getArgument('$repository'));
        $this->assertSame(['var/cache'], $definition->getArgument('$excludes'));
        $this->assertSame([], $definition->getArgument('$itemsToBackup'));
        $this->assertSame('var/restic', $definition->getArgument('$resticDirectory'));
    }

    public function testFailsWithoutDefaultConfiguration(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('markocupic_sac_pilatus_restic_backup.default must be set');

        (new MarkocupicSacPilatusResticBackupExtension())->load([[
            'database' => [
                'base_directory' => '/var/www/project',
                'repository' => '/var/backups/restic-db',
                'password' => 'secret-db',
            ],
        ]], new ContainerBuilder());
    }

    public function testFailsWithoutRequiredOptions(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new MarkocupicSacPilatusResticBackupExtension())->load([[
            'default' => [
                'base_directory' => '/var/www/project',
            ],
        ]], new ContainerBuilder());
    }
}
