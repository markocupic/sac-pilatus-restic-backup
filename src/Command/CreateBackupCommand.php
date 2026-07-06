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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Terminal42\Restic\Action\CreateBackup;

/**
 * php bin/console sac-pilatus-restic:backup
 * php bin/console sac-pilatus-restic:backup --dry-run # nur simulieren
 * php bin/console sac-pilatus-restic:backup -v # verbose: schaltet eine ausführlichere Ausgabe frei, bei der du jede einzelne Datei siehst, die restic gerade verarbeitet — statt nur des Fortschrittsbalkens.
 */
#[AsCommand(
    name: 'sac-pilatus-restic:backup',
    description: 'Erstellt ein Restic-Backup des angegebenen Verzeichnisses.',
)]
class CreateBackupCommand extends Command
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private readonly ResticFactory $resticFactory,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Backup nur simulieren')
            ->addOption('no-scan', null, InputOption::VALUE_NONE, 'restic-Scan-Phase überspringen')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $this->projectDir;

        if (!is_dir($directory)) {
            $io->error(\sprintf('Verzeichnis "%s" existiert nicht.', $directory));

            return Command::FAILURE;
        }

        $itemsToBackup = $this->resticFactory->getItemsToBackup();

        if (empty($itemsToBackup)) {
            $io->error('Es wurden keine Verzeichnisse, die gesichert werden müssen ausgewählt.');

            return Command::FAILURE;
        }

        $restic = $this->resticFactory->create();

        $io->title('Restic-Backup');

        foreach ($itemsToBackup as $item) {
            if (!file_exists(Path::join($this->projectDir, $item))) {
                $io->error(\sprintf('Die Datei oder das Verzeichnis "%s", das gesichert werden soll existiert nicht.', $item));

                return Command::FAILURE;
            }
            $io->writeln('Sichere: '.Path::makeRelative($item, $this->projectDir));
        }

        $action = new CreateBackup(
            $itemsToBackup,
            $restic->getExcludesFromBackup(),
            noScan: (bool) $input->getOption('no-scan'),
            dryRun: (bool) $input->getOption('dry-run'),
        );

        $restic->runAction($action, $io); // mit $io -> Fortschrittsbalken

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Backup fehlgeschlagen: '.$result->getOutput());

            return Command::FAILURE;
        }

        $result->addSummaryToOutput($io); // Zusammenfassungstabelle
        $io->success('Backup erstellt. Snapshot: '.$result->getSnapshotId());

        return Command::SUCCESS;
    }
}
