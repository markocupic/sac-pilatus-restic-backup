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
    description: 'Stellt einen Snapshot in ein neues Verzeichnis wieder her.',
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
            ->addArgument('target', InputArgument::OPTIONAL, 'Zielverzeichnis als relativer Pfad ab dem Projekt-Root (z. B. var/restore/2026-07-06)')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'Snapshot-ID oder "latest". Ohne Angabe wird interaktiv gefragt.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Restore nur simulieren')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Restic-Restore');

        // 1) Zielpfad (relativ ab Projekt-Root) ermitteln
        $relativeTarget = $input->getArgument('target')
            ?? $io->ask('Zielverzeichnis (relativer Pfad ab Projekt-Root)');

        if (null === $relativeTarget || '' === trim($relativeTarget)) {
            $io->error('Es wurde kein Zielverzeichnis angegeben.');

            return Command::INVALID;
        }

        // Absoluten Pfad bilden und normalisieren
        $targetPath = Path::makeAbsolute($relativeTarget, $this->projectDir);

        // Sicherstellen, dass wir innerhalb des Projekt-Roots bleiben
        if (!Path::isBasePath($this->projectDir, $targetPath)) {
            $io->error(\sprintf(
                'Das Zielverzeichnis "%s" liegt ausserhalb des Projekt-Roots.',
                $targetPath,
            ));

            return Command::INVALID;
        }

        // 2) Abbrechen, wenn das Ziel bereits existiert (nichts überschreiben)
        if (file_exists($targetPath)) {
            $io->error(\sprintf(
                'Das Zielverzeichnis "%s" existiert bereits. Abbruch, damit nichts überschrieben wird.',
                $targetPath,
            ));

            return Command::FAILURE;
        }

        // Restic-Instanz (Projekt-Root als Arbeitsverzeichnis für Binary/Cache)
        $restic = $this->resticFactory->create();

        // 3) Vorhandene Snapshots laden und Auswahl anbieten
        $snapshotId = $input->getArgument('snapshot');

        if (null === $snapshotId) {
            $listAction = new ListSnapshots(limit: 0); // 0 = alle
            $restic->runAction($listAction);

            $listResult = $listAction->getResult();

            if (!$listResult->wasSuccessful()) {
                $io->error('Snapshots konnten nicht geladen werden: '.$listResult->getOutput());

                return Command::FAILURE;
            }

            $snapshots = $listResult->getSnapshots();

            if ($snapshots->isEmpty()) {
                $io->error('Es sind keine Snapshots vorhanden.');

                return Command::FAILURE;
            }

            // Snapshots absteigend nach Zeit sortieren (neueste zuerst)
            $sortedSnapshots = $snapshots->items();
            usort(
                $sortedSnapshots,
                static fn ($a, $b): int => $b->getTime() <=> $a->getTime(),
            );

            // Auswahlliste mit Datum/Zeit aufbauen; Label -> Snapshot-ID merken
            $latestLabel = 'Neuester Snapshot (latest)';
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
                'Welchen Snapshot möchtest du wiederherstellen?',
                $choiceList,
                $latestLabel,
            );

            $snapshotId = $labelToId[$chosenLabel];
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $io->text([
            'Snapshot: '.$snapshotId,
            'Ziel:     '.$targetPath,
            'Modus:    '.($dryRun ? 'DRY-RUN (Simulation)' : 'echtes Restore'),
        ]);

        if (!$io->confirm('Restore jetzt starten?', false)) {
            $io->warning('Abgebrochen.');

            return Command::SUCCESS;
        }

        // 4) Restore ausführen
        $action = new RestoreBackup(
            snapshotId: $snapshotId,
            targetPath: $targetPath,
            dryRun: $dryRun,
        );

        $restic->runAction($action, $io);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $io->error('Restore fehlgeschlagen: '.$result->getOutput());

            return Command::FAILURE;
        }

        // Summary robust aus dem NDJSON-Stream lesen (nicht über die Library-Getter)
        $summary = $this->extractSummary($result->getOutput());

        $io->success(\sprintf(
            'Restore abgeschlossen: %d Datei(en), %d Bytes wiederhergestellt nach "%s".',
            (int) ($summary['files_restored'] ?? 0),
            (int) ($summary['total_bytes'] ?? 0),
            $targetPath,
        ));

        return Command::SUCCESS;
    }

    /**
     * restic restore --json liefert mehrere JSON-Zeilen (NDJSON).
     * Wir suchen die letzte gültige "summary"-Zeile.
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
