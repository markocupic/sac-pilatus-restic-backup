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
 * php bin/console sac-pilatus-restic:backup --dry-run # only simulate
 * php bin/console sac-pilatus-restic:backup -v # verbose: enables more detailed output showing every single file restic is processing — instead of just the progress bar.
 */
#[AsCommand(
    name: 'sac-pilatus-restic:backup',
    description: 'Creates a restic backup of the configured directories.',
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only simulate the backup')
            ->addOption('no-scan', null, InputOption::VALUE_NONE, 'Skip the restic scan phase')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $directory = $this->projectDir;

        if (!is_dir($directory)) {
            $io->error(\sprintf('Directory "%s" does not exist.', $directory));

            return Command::FAILURE;
        }

        $itemsToBackup = $this->resticFactory->getItemsToBackup();

        if (empty($itemsToBackup)) {
            $io->error('No directories to back up have been selected.');

            return Command::FAILURE;
        }

        $restic = $this->resticFactory->create();

        $io->title('Restic backup');

        foreach ($itemsToBackup as $item) {
            if (!file_exists(Path::join($this->projectDir, $item))) {
                $io->error(\sprintf('The file or directory "%s" that should be backed up does not exist.', $item));

                return Command::FAILURE;
            }
            $io->writeln('Backing up: '.Path::makeRelative($item, $this->projectDir));
        }

        $action = new CreateBackup(
            itemsToBackup: $itemsToBackup,
            excludes: $restic->getExcludesFromBackup(),
            noScan: (bool) $input->getOption('no-scan'),
            dryRun: (bool) $input->getOption('dry-run'),
        );

        $restic->runAction($action, $io); // with $io -> progress bar

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Backup failed: '.$result->getOutput());

            return Command::FAILURE;
        }

        $result->addSummaryToOutput($io); // summary table
        $io->success('Backup created. Snapshot: '.$result->getSnapshotId());

        return Command::SUCCESS;
    }
}
