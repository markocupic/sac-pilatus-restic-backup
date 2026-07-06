![Alt text](docs/logo.png?raw=true "logo")

# SAC Pilatus Restic Backup

Ein Contao-Bundle, das [`terminal42/php-restic`](https://github.com/terminal42/php-restic) kapselt und
Backups mit [restic](https://restic.net/) als Console-Commands und Contao-Cronjobs bereitstellt.

Die restic-Binary wird beim ersten Lauf automatisch heruntergeladen (SHA256-verifiziert) – es muss kein
systemweites restic installiert werden.

> Basiert auf `terminal42/php-restic` **v0.2.x** (restic 0.19.1). Das Paket gibt keine BC-Garantien.

## Voraussetzungen

- PHP ≥ 8.2 mit den Extensions `ext-zip` und `ext-bz2`
- Contao ≥ 5.3
- Schreibrechte im `base_directory` und Internetzugriff auf `github.com/restic/restic/releases` beim ersten Lauf

## Installation

```bash
composer require markocupic/sac-pilatus-restic-backup
```

Das Bundle registriert sich über den Contao Manager Plugin automatisch.

## Konfiguration

Konfiguriert wird unter dem Schlüssel `markocupic_sac_pilatus_restic_backup`. Jeder Schlüssel darunter ist
eine **benannte Backup-Konfiguration** (z. B. `default`, `database`, `media`). Eine Konfiguration mit dem
Namen `default` ist **Pflicht**.

```yaml
# config/config.yaml
markocupic_sac_pilatus_restic_backup:

  default:
    base_directory: '%kernel.project_dir%'          # Pflicht: Projekt-Wurzelverzeichnis (absolut)
    items_to_backup: # zu sichernde Pfade, relativ zum base_directory
      - 'files/fileadmin'
      - 'files/sektion'
    repository: '%kernel.project_dir%/var/backups/restic'   # Pflicht: absoluter Pfad oder s3-DSN
    password: '%env(RESTIC_PASSWORD)%'               # Pflicht
    restic_directory: 'var/restic'                   # optional: Ort für Binary/Cache (relativ)
    excludes: # optional: relativ zum base_directory
      - 'var/cache'
      - 'var/logs'
    env: [ ]                                          # optional: z. B. AWS-Keys für S3

  # Weitere Typen sind möglich – einfach als zusätzlichen Schlüssel anlegen:
  # database:
  #     base_directory: '%kernel.project_dir%'
  #     items_to_backup: ['var/db_dump']
  #     repository: '%kernel.project_dir%/var/backups/restic-db'
  #     password: '%env(RESTIC_PASSWORD_DB)%'
```

```dotenv
# .env.local
RESTIC_PASSWORD=ein-sehr-geheimes-passwort
```

Felder je Konfiguration:

| Option             | Pflicht | Default      | Beschreibung                                                                    |
|--------------------|:-------:|--------------|---------------------------------------------------------------------------------|
| `base_directory`   | **ja**  | –            | Projekt-Wurzelverzeichnis. Basis für `restic_directory`, CWD und Auto-Excludes. |
| `items_to_backup`  |  nein   | `[]`         | Zu sichernde Pfade, relativ zum `base_directory`.                               |
| `repository`       | **ja**  | –            | Restic-DSN: absoluter lokaler Pfad **oder** `s3:…`.                             |
| `password`         | **ja**  | –            | Passwort des restic-Repositories.                                               |
| `restic_directory` |  nein   | `var/restic` | Unterordner für Binary/Cache, relativ zum `base_directory`.                     |
| `excludes`         |  nein   | `[]`         | Ausschlüsse, relativ zum `base_directory`.                                      |
| `env`              |  nein   | `[]`         | Zusätzliche restic-Env-Variablen (z. B. `AWS_ACCESS_KEY_ID` für S3).            |

Hinweise: Lokale Repositories brauchen einen **absoluten** Pfad. Für S3 müssen `AWS_ACCESS_KEY_ID` und
`AWS_SECRET_ACCESS_KEY` im `env`-Array stehen. Das `restic_directory` wird automatisch aus Backups
ausgeschlossen. Wähle als Repository idealerweise einen anderen Datenträger als das Projekt.

Aus jeder Konfiguration registriert das Bundle einen eigenen Factory-Service unter der ID
`markocupic_sac_pilatus_restic_backup.factory.<name>` (z. B. `markocupic_sac_pilatus_restic_backup.factory.default`).

## Commands

Alle Commands nutzen standardmässig die `default`-Konfiguration.

### `sac-pilatus-restic:backup`

Sichert die in der Konfiguration definierten `items_to_backup`. Prüft vorab, ob die Pfade existieren.

```bash
php bin/console sac-pilatus-restic:backup            # Backup erstellen
php bin/console sac-pilatus-restic:backup --dry-run  # nur simulieren
php bin/console sac-pilatus-restic:backup --no-scan  # Scan-Phase überspringen
php bin/console sac-pilatus-restic:backup -v         # verbose: zeigt jede verarbeitete Datei
```

### `sac-pilatus-restic:list`

Listet alle Snapshots auf – **neueste zuerst** – als Tabelle mit ID und Zeitpunkt.

```bash
php bin/console sac-pilatus-restic:list
```

### `sac-pilatus-restic:restore`

Stellt einen Snapshot **in ein neues Verzeichnis** wieder her. Interaktiv: die vorhandenen Snapshots
werden – neueste zuerst, mit Datum/Uhrzeit – zur Auswahl angeboten (inkl. `latest`).

```bash
php bin/console sac-pilatus-restic:restore                                   # vollständig interaktiv
php bin/console sac-pilatus-restic:restore var/restore/2026-07-06            # Ziel vorgeben
php bin/console sac-pilatus-restic:restore var/restore/2026-07-06 latest     # alles vorgeben
```

Argumente: `target` (Zielverzeichnis als relativer Pfad ab Projekt-Root), `snapshot` (ID oder `latest`).
Option `--dry-run`. Der Zielpfad muss innerhalb des Projekt-Roots liegen, und **existiert das Ziel
bereits, bricht der Command ab** – es wird nie etwas überschrieben.

## Cronjobs

Beide Cronjobs laufen ausschliesslich im **CLI-Scope** (rechenintensive Vorgänge blockieren so keinen
Besucher-Request) und nutzen die `default`-Konfiguration. Ergebnisse werden in den Contao-Logger
geschrieben.

- **`CreateBackupCron`** (`#[AsCronJob('hourly')]`) – erstellt stündlich ein Backup der konfigurierten
  `items_to_backup`.
- **`PruneOldSnapshotsCron`** (`#[AsCronJob('daily')]`) – entfernt täglich alle Snapshots, die älter als
  **3 Monate** sind, und bereinigt das Repository (`restic forget --keep-within 3m --prune`). Nutzt dazu
  die eigene Action `ForgetSnapshotsWithin`.

Damit CLI-Cronjobs laufen, muss ein Server-Cron den Contao-Cron anstossen:

```cron
* * * * * cd /pfad/zum/projekt && php vendor/bin/contao-console contao:cron
```

Einen einzelnen Job zum Testen erzwingen (ignoriert das Intervall):

```bash
vendor/bin/contao-console contao:cron --force "Markocupic\SacPilatusResticBackup\Cron\CreateBackupCron"
vendor/bin/contao-console contao:cron --force "Markocupic\SacPilatusResticBackup\Cron\PruneOldSnapshotsCron"
```

## Erweitern

Eine konfigurierte `Restic`-Instanz bekommst du über die passende `ResticFactory`. Da pro Konfiguration ein
eigener Service existiert, wird er über die Service-ID injiziert:

```php
use Markocupic\SacPilatusResticBackup\Backup\ResticFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

public function __construct(
    #[Autowire(service: 'markocupic_sac_pilatus_restic_backup.factory.default')]
    private readonly ResticFactory $resticFactory,
) {}

// …
$restic = $this->resticFactory->create();
$items  = $this->resticFactory->getItemsToBackup(); // zu sichernde Pfade aus der Config
```

Für einen anderen Backup-Typ tauschst du die Service-ID (`markocupic_sac_pilatus_restic_backup.factory.database` usw.).

## Bekannte Einschränkung

`restic restore --json` liefert einen NDJSON-Stream; die Getter `getNumberOfFilesRestored()` /
`getTotalBytes()` von `RestoreBackupResult` erwarten aber ein einzelnes JSON-Objekt und würden sonst
einen `TypeError` auslösen. Der Restore-Command umgeht das, indem er die letzte `summary`-Zeile selbst
parst.

## Lizenz

GPL-3.0-or-later — (c) Marko Cupic · https://github.com/markocupic/sac-pilatus-restic-backup
