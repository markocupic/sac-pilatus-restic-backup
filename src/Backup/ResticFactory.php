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

namespace Markocupic\SacPilatusResticBackup\Backup;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Terminal42\Restic\Restic;

readonly class ResticFactory
{
    public function __construct(
        private string $baseDirectory,
        private array $itemsToBackup,
        #[\SensitiveParameter] private string $repository,
        #[\SensitiveParameter] private string $password,
        private array $excludes = [],
        #[\SensitiveParameter] private array $env = [],
        private string $resticDirectory = 'var/restic',
        private LoggerInterface|null $logger = null,
        private bool $enabled = true,
    ) {
    }

    /**
     * False if the backups of this configuration have been disabled (option "enable: false").
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function create(): Restic
    {
        return Restic::create(
            $this->baseDirectory,
            $this->repository,
            $this->password,
            $this->excludes,
            $this->env,
            $this->resticDirectory,
            $this->logger ?? new NullLogger(),
        );
    }

    public function getItemsToBackup(): array
    {
        return $this->itemsToBackup;
    }
}
