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

namespace Markocupic\SacPilatusResticBackup\DependencyInjection;

use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

class MarkocupicSacPilatusResticBackupExtension extends Extension
{
    public function getAlias(): string
    {
        return Configuration::ROOT_KEY;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../../config'),
        );

        $loader->load('services.yaml');

        if (empty($config['default'])) {
            throw new \Exception(Configuration::ROOT_KEY.'.default must be set in your configuration.');
        }

        // $config ist jetzt ['default' => [...], 'files' => [...], …]
        foreach ($config as $name => $backup) {
            // Factory-Service
            $factory = new Definition(ResticFactory::class);
            $factory->setArguments([
                '$baseDirectory' => $backup['base_directory'],
                '$itemsToBackup' => $backup['items_to_backup'],
                '$repository' => $backup['repository'],
                '$password' => $backup['password'],
                '$excludes' => $backup['excludes'],
                '$env' => $backup['env'],
                '$resticDirectory' => $backup['restic_directory'],
                '$logger' => new Reference(
                    'logger',
                    ContainerInterface::NULL_ON_INVALID_REFERENCE, // = ->nullOnInvalid()
                ),
            ]);
            $container->setDefinition(self::factoryId($name), $factory);
        }
    }

    private static function factoryId(string $name): string
    {
        return Configuration::ROOT_KEY.'.factory.'.$name;
    }
}
