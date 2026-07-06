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
    description: 'Listet alle Restic-Snapshots auf (neueste zuerst).',
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

        $action = new ListSnapshots(limit: 0); // 0 = alle Snapshots
        $restic->runAction($action);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Snapshots konnten nicht geladen werden: '.$result->getOutput());

            return Command::FAILURE;
        }

        $snapshots = $result->getSnapshots();

        if ($snapshots->isEmpty()) {
            $io->warning('Es sind keine Snapshots vorhanden.');

            return Command::SUCCESS;
        }

        // Absteigend nach Zeit sortieren (neueste zuerst)
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

        $io->title('Restic-Snapshots (neueste zuerst)');
        $io->table(['Snapshot-ID', 'Zeitpunkt'], $rows);
        $io->text(\sprintf('%d Snapshot(s) gefunden.', \count($sortedSnapshots)));

        return Command::SUCCESS;
    }
}
