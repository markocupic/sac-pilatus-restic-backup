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

#[AsCronJob('5 * * * *')]
readonly class CreateBackupCron
{
    public function __construct(
        #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
        private ResticFactory $resticFactory,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        private LoggerInterface|null $contaoGeneralLogger = null,
        private LoggerInterface|null $contaoErrorLogger = null,
    ) {
    }

    public function __invoke(string $scope): void
    {
        // Backups are resource-intensive -> only in the CLI scope (server cron), never in a web request.
        if (Cron::SCOPE_CLI !== $scope) {
            return;
        }

        if (!is_dir($this->projectDir)) {
            $this->contaoErrorLogger?->error(\sprintf('[Restic] Directory "%s" does not exist. CreateBackupCron could not be executed.', $this->projectDir));

            return;
        }

        $itemsToBackup = $this->resticFactory->getItemsToBackup();

        if (empty($itemsToBackup)) {
            $this->contaoErrorLogger?->error('No directories to back up have been selected.');

            return;
        }

        $hasError = false;

        foreach ($itemsToBackup as $item) {
            if (!file_exists(Path::join($this->projectDir, $item))) {
                $this->contaoErrorLogger?->error(\sprintf('The file or directory "%s" that should be backed up does not exist.', $item));
                $hasError = true;
            }
        }

        if ($hasError) {
            $this->contaoErrorLogger?->error('CreateBackupCron could not be executed');

            return;
        }

        $restic = $this->resticFactory->create();

        $action = new CreateBackup(
            $itemsToBackup,
            $restic->getExcludesFromBackup(),
            noScan: false,
            dryRun: false,
        );

        $restic->runAction($action); // without $io -> no progress output

        $result = $action->getResult();

        if (!$result->wasSuccessful()) {
            $this->contaoErrorLogger?->error('[Restic] File backup failed: '.$result->getOutput());

            return;
        }

        $this->contaoGeneralLogger?->info('[Restic] File backup created. Snapshot: '.$result->getSnapshotId());
    }
}
