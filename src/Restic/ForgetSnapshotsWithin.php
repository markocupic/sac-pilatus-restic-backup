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
 * Entfernt alle Snapshots, die älter als die angegebene Dauer sind (restic --keep-within),
 * und bereinigt anschliessend das Repository (--prune).
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
            '--keep-within', $this->keepWithin, // z. B. "3m" = 3 Monate
            '--prune', // Daten wirklich entfernen
            '--cleanup-cache',
        ];
    }

    protected function updateResult(string $output, ProcessFailedException|null $exception = null): void
    {
        $this->result = new AbstractActionResult($output, $exception);
    }
}
