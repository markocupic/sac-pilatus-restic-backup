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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const ROOT_KEY = 'markocupic_sac_pilatus_restic_backup';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_KEY);

        $root = $treeBuilder->getRootNode();
        $root
            ->info('Benannte Backup-Konfigurationen. Der Schlüssel ist der Name (z. B. "default").')
            ->useAttributeAsKey('name')
            ->requiresAtLeastOneElement()
            ->arrayPrototype()
                ->children()
                    ->scalarNode('base_directory')
                        ->info('Absoluter Pfad zum Projekt-Wurzelverzeichnis. Basis für restic_directory (Binary/Cache), die restic-CWD und die Auto-Excludes. Im Auto-Modus zugleich das zu sichernde Verzeichnis; bei expliziter Item-Liste in CreateBackup bestimmen die Items, was gesichert wird.')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->arrayNode('items_to_backup')
                        ->info('Welche Pfade relativ zum base_directory sollen gesichert werden?')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->scalarNode('repository')
                        ->info('Restic-DSN: absoluter lokaler Pfad oder s3:...')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->scalarNode('password')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->scalarNode('restic_directory')
                        ->info('Unterordner für restic-Binary und -Cache, relativ zum backup_directory.')
                        ->defaultValue('var/restic')
                    ->end()
                    ->arrayNode('excludes')
                        ->info('Ausschlüsse, relativ zum backup_directory')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->arrayNode('env')
                        ->info('Zusätzliche Restic-Env-Vars, z.B. AWS_ACCESS_KEY_ID')
                        ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
