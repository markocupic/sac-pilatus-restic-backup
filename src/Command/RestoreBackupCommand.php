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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Terminal42\Restic\Action\ListSnapshots;
use Terminal42\Restic\Action\RestoreBackup;

#[AsCommand(
    name: 'sac-pilatus-restic:restore',
    description: 'Restores a snapshot into a new directory.',
)]
class RestoreBackupCommand extends Command
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
            ->addArgument('target', InputArgument::OPTIONAL, 'Target directory as a relative path from the project root (e.g. var/restore/2026-07-06)')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot ID or "latest". If omitted, you are asked interactively.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only simulate the restore')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Restic restore');

        // 1) Determine the target path (relative to the project root)
        $relativeTarget = $input->getArgument('target')
            ?? $io->ask('Target directory (relative path from the project root)');

        if (null === $relativeTarget || '' === trim($relativeTarget)) {
            $io->error('No target directory was given.');

            return Command::INVALID;
        }

        // Build and normalize the absolute path
        $targetPath = Path::makeAbsolute($relativeTarget, $this->projectDir);

        // Make sure we stay inside the project root
        if (!Path::isBasePath($this->projectDir, $targetPath)) {
            $io->error(\sprintf(
                'The target directory "%s" is outside the project root.',
                $targetPath,
            ));

            return Command::INVALID;
        }

        // 2) Abort if the target already exists (do not overwrite anything)
        if (file_exists($targetPath)) {
            $io->error(\sprintf(
                'The target directory "%s" already exists. Aborting so nothing gets overwritten.',
                $targetPath,
            ));

            return Command::FAILURE;
        }

        // Restic instance (project root as working directory for binary/cache)
        $restic = $this->resticFactory->create();

        // 3) Load the existing snapshots and offer a selection
        $snapshotId = $input->getArgument('snapshot');

        if (null === $snapshotId) {
            $listAction = new ListSnapshots(limit: 0); // 0 = all
            $restic->runAction($listAction);

            $listResult = $listAction->getResult();

            if (!$listResult->wasSuccessful()) {
                $io->error('Snapshots could not be loaded: '.$listResult->getOutput());

                return Command::FAILURE;
            }

            $snapshots = $listResult->getSnapshots();

            if ($snapshots->isEmpty()) {
                $io->error('There are no snapshots.');

                return Command::FAILURE;
            }

            // Sort snapshots descending by time (newest first)
            $sortedSnapshots = $snapshots->items();
            usort(
                $sortedSnapshots,
                static fn ($a, $b): int => $b->getTime() <=> $a->getTime(),
            );

            // Build the choice list with date/time; remember label -> snapshot ID
            $latestLabel = 'Latest snapshot (latest)';
            $choiceList = [$latestLabel];
            $labelToId = [$latestLabel => 'latest'];

            foreach ($sortedSnapshots as $snapshot) {
                $label = \sprintf(
                    '%s  —  %s',
                    $snapshot->getId(),
                    $snapshot->getTime()->format('d.m.Y H:i:s'),
                );

                $choiceList[] = $label;
                $labelToId[$label] = $snapshot->getId();
            }

            $chosenLabel = $io->choice(
                'Which snapshot would you like to restore?',
                $choiceList,
                $latestLabel,
            );

            $snapshotId = $labelToId[$chosenLabel];
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $io->text([
            'Snapshot: '.$snapshotId,
            'Target:   '.$targetPath,
            'Mode:     '.($dryRun ? 'DRY-RUN (simulation)' : 'real restore'),
        ]);

        if (!$io->confirm('Start the restore now?', false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        // 4) Run the restore
        $action = new RestoreBackup(
            snapshotId: $snapshotId,
            targetPath: $targetPath,
            dryRun: $dryRun,
        );

        $restic->runAction($action, $io);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Restore failed: '.$result->getOutput());

            return Command::FAILURE;
        }

        // Read the summary robustly from the NDJSON stream (not via the library getters)
        $summary = $this->extractSummary($result->getOutput());

        $io->success(\sprintf(
            'Restore finished: %d file(s), %d bytes restored to "%s".',
            (int) ($summary['files_restored'] ?? 0),
            (int) ($summary['total_bytes'] ?? 0),
            $targetPath,
        ));

        return Command::SUCCESS;
    }

    /**
     * Restic restore --json returns multiple JSON lines (NDJSON).
     * We look for the last valid "summary" line.
     */
    private function extractSummary(string $output): array
    {
        $lines = preg_split('/\r?\n/', trim($output)) ?: [];

        foreach (array_reverse($lines) as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $data = json_decode($line, true);

            if (\is_array($data) && ('summary' === ($data['message_type'] ?? null) || isset($data['total_bytes']))) {
                return $data;
            }
        }

        return [];
    }
}
