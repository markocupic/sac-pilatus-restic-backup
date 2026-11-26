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
            ->info('Named backup configurations. The key is the name (e.g. "default").')
            ->useAttributeAsKey('name')
            ->requiresAtLeastOneElement()
            ->arrayPrototype()
                ->children()
                    ->booleanNode('enable')
                        ->info('Set to false to disable the backups of this configuration completely: the cron jobs and the backup command then do nothing.')
                        ->defaultTrue()
                    ->end()
                    ->scalarNode('base_directory')
                        ->info('Absolute path to the project root directory. Base for restic_directory (binary/cache), the restic CWD and the auto excludes. In auto mode this is also the directory to back up; with an explicit item list in CreateBackup the items determine what is backed up.')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->arrayNode('items_to_backup')
                        ->info('Which paths relative to base_directory should be backed up?')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->scalarNode('repository')
                        ->info('Restic DSN: absolute local path or s3:...')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->scalarNode('password')
                        ->isRequired()->cannotBeEmpty()
                    ->end()
                    ->scalarNode('restic_directory')
                        ->info('Subdirectory for the restic binary and cache, relative to base_directory.')
                        ->defaultValue('var/restic')
                    ->end()
                    ->arrayNode('excludes')
                        ->info('Excludes, relative to base_directory')
                        ->scalarPrototype()->end()
                        ->defaultValue([])
                    ->end()
                    ->arrayNode('env')
                        ->info('Additional restic env vars, e.g. AWS_ACCESS_KEY_ID')
                        ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
