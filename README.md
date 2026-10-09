![Alt text](docs/logo.png?raw=true "logo")

# SAC Pilatus Restic Backup

A Contao bundle that wraps [`terminal42/php-restic`](https://github.com/terminal42/php-restic) and
provides backups with [restic](https://restic.net/) as console commands and Contao cron jobs.

The restic binary is downloaded automatically on the first run (SHA256-verified) – no system-wide restic
installation is required.

> Based on `terminal42/php-restic` **v0.2.x** (restic 0.19.1). The package makes no BC promises.

## Requirements

- PHP ≥ 8.2 with the `ext-zip` and `ext-bz2` extensions
- Contao 5.3 or Contao 6
- Write permissions in the `base_directory` and internet access to `github.com/restic/restic/releases` on the first run

## Installation

```bash
composer require markocupic/sac-pilatus-restic-backup
```

The bundle registers itself automatically via the Contao Manager plugin.

## Configuration

Configuration is done under the key `markocupic_sac_pilatus_restic_backup`. Each key below it is a
**named backup configuration** (e.g. `default`, `database`, `media`). A configuration named `default` is
**required**.

```yaml
# config/config.yaml
markocupic_sac_pilatus_restic_backup:

    default:
        base_directory: '%kernel.project_dir%'          # required: project root directory (absolute)
        items_to_backup:                                 # paths to back up, relative to base_directory
            - 'files/fileadmin'
            - 'files/sektion'
        repository: '%kernel.project_dir%/var/backups/restic'   # required: absolute path or s3 DSN
        password: '%env(RESTIC_PASSWORD)%'               # required
        restic_directory: 'var/restic'                   # optional: location for binary/cache (relative)
        excludes:                                        # optional: relative to base_directory
            - 'var/cache'
            - 'var/logs'
        env: []                                          # optional: e.g., AWS keys for S3

    # Additional types are possible – just add another key:
    # database:
    #     base_directory: '%kernel.project_dir%'
    #     items_to_backup: ['var/db_dump']
    #     repository: '%kernel.project_dir%/var/backups/restic-db'
    #     password: '%env(RESTIC_PASSWORD_DB)%'
```

```dotenv
# .env.local
RESTIC_PASSWORD=a-very-secret-password
```

Fields per configuration:

| Option             | Required | Default      | Description                                                                 |
|--------------------|:--------:|--------------|-----------------------------------------------------------------------------|
| `base_directory`   | **yes**  | –            | Project root directory. Base for `restic_directory`, CWD and auto excludes. |
| `items_to_backup`  |    no    | `[]`         | Paths to back up, relative to `base_directory`.                             |
| `repository`       | **yes**  | –            | Restic DSN: absolute local path **or** `s3:…`.                              |
| `password`         | **yes**  | –            | Password of the restic repository.                                          |
| `restic_directory` |    no    | `var/restic` | Subdirectory for binary/cache, relative to `base_directory`.                |
| `excludes`         |    no    | `[]`         | Excludes, relative to `base_directory`.                                     |
| `env`              |    no    | `[]`         | Additional restic env variables (e.g. `AWS_ACCESS_KEY_ID` for S3).          |

Notes: Local repositories require an **absolute** path. For S3, `AWS_ACCESS_KEY_ID` and
`AWS_SECRET_ACCESS_KEY` must be set in the `env` array. The `restic_directory` is excluded from backups
automatically. Ideally choose a different storage device for the repository than the project itself.

For each configuration the bundle registers a dedicated factory service with the ID
`markocupic_sac_pilatus_restic_backup.factory.<name>` (e.g. `markocupic_sac_pilatus_restic_backup.factory.default`).

## Commands

All commands use the `default` configuration by default.

### `sac-pilatus-restic:backup`

Backs up the `items_to_backup` defined in the configuration. Checks in advance whether the paths exist.

```bash
php bin/console sac-pilatus-restic:backup            # create a backup
php bin/console sac-pilatus-restic:backup --dry-run  # only simulate
php bin/console sac-pilatus-restic:backup --no-scan  # skip the scan phase
php bin/console sac-pilatus-restic:backup -v         # verbose: shows every processed file
```

### `sac-pilatus-restic:list`

Lists all snapshots – **newest first** – as a table with ID and time.

```bash
php bin/console sac-pilatus-restic:list
```

### `sac-pilatus-restic:restore`

Restores a snapshot **into a new directory**. Interactive: the existing snapshots are offered for
selection – newest first, with date/time (including `latest`).

```bash
php bin/console sac-pilatus-restic:restore                                   # fully interactive
php bin/console sac-pilatus-restic:restore var/restore/2026-07-06            # provide the target
php bin/console sac-pilatus-restic:restore var/restore/2026-07-06 latest     # provide everything
```

Arguments: `target` (target directory as a relative path from the project root), `snapshot` (ID or
`latest`). Option `--dry-run`. The target path must be inside the project root, and **if the target
already exists, the command aborts** – nothing is ever overwritten.

## Cron jobs

Both cron jobs run exclusively in the **CLI scope** (so resource-intensive operations never block a
visitor request) and use the `default` configuration. Results are written to the Contao logger.

- **`CreateBackupCron`** (`#[AsCronJob('5 * * * *')]`) – creates an hourly backup (at minute 5) of the
  configured `items_to_backup`.
- **`PruneOldSnapshotsCron`** (`#[AsCronJob('daily')]`) – removes all snapshots older than **3 months**
  every day and cleans up the repository (`restic forget --keep-within 3m --prune`). It uses the custom
  action `ForgetSnapshotsWithin`.

For CLI cron jobs to run, a server cron must trigger the Contao cron:

```cron
* * * * * cd /path/to/project && php vendor/bin/contao-console contao:cron
```

Force a single job for testing (ignores the interval):

```bash
vendor/bin/contao-console contao:cron --force "Markocupic\SacPilatusResticBackup\Cron\CreateBackupCron"
vendor/bin/contao-console contao:cron --force "Markocupic\SacPilatusResticBackup\Cron\PruneOldSnapshotsCron"
```

## Extending

You get a configured `Restic` instance from the matching `ResticFactory`. Since there is one service per
configuration, it is injected via the service ID:

```php
use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

public function __construct(
    #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
    private readonly ResticFactory $resticFactory,
) {}

// …
$restic = $this->resticFactory->create();
$items  = $this->resticFactory->getItemsToBackup(); // paths to back up from the config
```

For a different backup type, swap the service ID (`markocupic_sac_pilatus_restic_backup.factory.database`, etc.).

## Known limitation

`restic restore --json` returns an NDJSON stream; however, the getters `getNumberOfFilesRestored()` /
`getTotalBytes()` of `RestoreBackupResult` expect a single JSON object and would otherwise throw a
`TypeError`. The restore command works around this by parsing the last `summary` line itself.

## License

GPL-3.0-or-later — (c) Marko Cupic · https://github.com/markocupic/sac-pilatus-restic-backup
