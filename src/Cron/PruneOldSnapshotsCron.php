<?php

declare(strict_types=1);

/*
 * This file is part of SAC Pilatus Restic Backup.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * @link https://github.com/markocupic/sac-pilatus-restic-backup
 */

namespace Markocupic\SacPilatusResticBackup\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Markocupic\SacPilatusResticBackup\Restic\ForgetSnapshotsWithin;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCronJob('daily')]
class PruneOldSnapshotsCron
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private readonly ResticFactory $resticFactory,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(string $scope): void
    {
        // Prune ist rechenintensiv -> nur im CLI-Scope (Server-Cron), nie im Web-Request.
        if (Cron::SCOPE_CLI !== $scope) {
            return;
        }

        $restic = $this->resticFactory->create();

        // Alles behalten, was jünger als 3 Monate ist; Älteres wird entfernt.
        $action = new ForgetSnapshotsWithin('3m');

        $restic->runAction($action);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $this->logger?->error(
                '[Restic] Aufräumen alter Snapshots fehlgeschlagen: '.$result->getOutput(),
            );

            return;
        }

        $this->logger?->info('[Restic] Snapshots älter als 3 Monate entfernt und Repository bereinigt.');
    }
}
