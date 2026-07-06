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

namespace Markocupic\SacPilatusResticBackup\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Terminal42\Restic\Action\CreateBackup;

#[AsCronJob('hourly')]
class CreateBackupCron
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private readonly ResticFactory $resticFactory,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly LoggerInterface|null $contaoGeneralLogger = null,
        private readonly LoggerInterface|null $contaoErrorLogger = null,
    ) {
    }

    public function __invoke(string $scope): void
    {
        // Backup ist rechenintensiv -> nur im CLI-Scope (Server-Cron), nie im Web-Request.
        if (Cron::SCOPE_CLI !== $scope) {
            return;
        }

        if (!is_dir($this->projectDir)) {
            $this->contaoErrorLogger?->error(\sprintf('[Restic] Verzeichnis "%s" existiert nicht. CreateBackupCron konnte nicht ausgeführt werden.', $this->projectDir));

            return;
        }

        $itemsToBackup = $this->resticFactory->getItemsToBackup();

        if (empty($itemsToBackup)) {
            $this->contaoErrorLogger?->error('Es wurden keine Verzeichnisse, die gesichert werden müssen ausgewählt.');

            return;
        }

        $hasError = false;

        foreach ($itemsToBackup as $item) {
            if (!file_exists(Path::join($this->projectDir, $item))) {
                $this->contaoErrorLogger?->error(\sprintf('Die Datei oder das Verzeichnis "%s", das gesichert werden soll existiert nicht.', $item));
                $hasError = true;
            }
        }

        if ($hasError) {
            $this->contaoErrorLogger?->error('CreateBackupCron konnte nicht ausgeführt werden');

            return;
        }

        $restic = $this->resticFactory->create();

        $action = new CreateBackup(
            $itemsToBackup,
            $restic->getExcludesFromBackup(),
            noScan: false,
            dryRun: false,
        );

        $restic->runAction($action); // ohne $io -> kein Progress-Output

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $this->contaoErrorLogger?->error('[Restic] File-Backup fehlgeschlagen: '.$result->getOutput());

            return;
        }

        $this->contaoGeneralLogger?->info('[Restic] File-Backup erstellt. Snapshot: '.$result->getSnapshotId());
    }
}
