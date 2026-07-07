<?php

declare(strict_types=1);

/*
 * This file is part of SAC Pilatus Restic Backup.
 *
 * (c) Marko Cupic <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * @link https://github.com/markocupic/sac-pilatus-restic-backup
 */

namespace Markocupic\SacPilatusResticBackup\Restic;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Terminal42\Restic\Action\AbstractAction;
use Terminal42\Restic\Action\Result\AbstractActionResult;

/**
 * Removes all snapshots older than the given duration (restic --keep-within)
 * and then cleans up the repository (--prune).
 */
class ForgetSnapshotsWithin extends AbstractAction
{
    private AbstractActionResult $result;

    public function __construct(private readonly string $keepWithin = '3m')
    {
    }

    public function getResult(): AbstractActionResult
    {
        return $this->result;
    }

    protected function doGetArguments(): array
    {
        return [
            'forget',
            '--keep-within', $this->keepWithin, // e.g. "3m" = 3 months
            '--prune', // actually remove the data
            '--cleanup-cache',
        ];
    }

    protected function updateResult(string $output, ProcessFailedException|null $exception = null): void
    {
        $this->result = new AbstractActionResult($output, $exception);
    }
}
