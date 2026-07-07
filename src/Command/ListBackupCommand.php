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

namespace Markocupic\SacPilatusResticBackup\Command;

use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Terminal42\Restic\Action\ListSnapshots;

/**
 * php bin/console sac-pilatus-restic:list.
 */
#[AsCommand(
    name: 'sac-pilatus-restic:list',
    description: 'Lists all restic snapshots (newest first).',
)]
class ListBackupCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private readonly ResticFactory $resticFactory,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $restic = $this->resticFactory->create();

        $action = new ListSnapshots(limit: 0); // 0 = all snapshots
        $restic->runAction($action);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Snapshots could not be loaded: '.$result->getOutput());

            return Command::FAILURE;
        }

        $snapshots = $result->getSnapshots();

        if ($snapshots->isEmpty()) {
            $io->warning('There are no snapshots.');

            return Command::SUCCESS;
        }

        // Sort descending by time (newest first)
        $sortedSnapshots = $snapshots->items();
        usort(
            $sortedSnapshots,
            static fn ($a, $b): int => $b->getTime() <=> $a->getTime(),
        );

        $rows = [];

        foreach ($sortedSnapshots as $snapshot) {
            $rows[] = [
                $snapshot->getId(),
                $snapshot->getTime()->format('d.m.Y H:i:s'),
            ];
        }

        $io->title('Restic snapshots (newest first)');
        $io->table(['Snapshot ID', 'Time'], $rows);
        $io->text(\sprintf('%d snapshot(s) found.', \count($sortedSnapshots)));

        return Command::SUCCESS;
    }
}
