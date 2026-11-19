# Sleep Tight

Personal sleep tracker. PHP 8.1+ with SQLite, no build step, no dependencies.

## Run

Drop the folder on any PHP host (Apache/LiteSpeed; `.htaccess` blocks `data/`, `lib/`, `bin/`, `migrations/`).
The first visit asks you to set the password. The database is created at `data/sleep.sqlite`.

Local: https://sleep-tight.test (Herd). `bin/seed` fills an empty database with demo nights.

Quick-select options live in the `TAGS` constant in `lib/app.php`.

## Migrations

Numbered SQL files in `migrations/`. A fresh install applies them all; afterwards they are run explicitly:

```bash
bin/migrate                    # apply pending, this install
bin/migrate --status           # list only
bin/migrate --remote           # apply on the live site (uses .sync.json)
bin/migrate --remote --status
```

The deploy workflow runs `bin/migrate` on the server after each upload.

## Data sync

```bash
bin/sync
```

Interactive pull / push / both ways between the local database and the live site. Nights are merged
one by one, the most recently edited version wins, deletes are carried over. A local backup is written to
`data/backups/` before every pull. The first run asks for the live URL and the sync token
(Stats → Sync token on the live site) and stores them in the git-ignored `.sync.json`.

## Deploy

`.github/workflows/deploy.yml` rsyncs to the host over SSH on every push to `main` and never touches `data/`.
It skips itself while the secrets are missing.
