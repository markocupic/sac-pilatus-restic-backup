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
readonly class PruneOldSnapshotsCron
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private ResticFactory $resticFactory,
        private LoggerInterface|null $logger = null,
    ) {
    }

    public function __invoke(string $scope): void
    {
        // Pruning is resource-intensive -> only in the CLI scope (server cron), never in a web request.
        if (Cron::SCOPE_CLI !== $scope) {
            return;
        }

        $restic = $this->resticFactory->create();

        // Keep everything younger than 3 months old; older snapshots are removed.
        $action = new ForgetSnapshotsWithin('3m');

        $restic->runAction($action);

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $this->logger?->error(
                '[Restic] Pruning old snapshots failed: '.$result->getOutput(),
            );

            return;
        }

        $this->logger?->info('[Restic] Removed snapshots older than 3 months and cleaned up the repository.');
    }
}
