# Sleep Tight

Personal sleep tracker. PHP 8.1+ with SQLite, no build step, no dependencies.

## Run

Point the web root at `public/`; code, migrations and the database (`data/sleep.sqlite`, created on first use)
sit one level above it. The first visit asks you to set the password.

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

`.github/workflows/deploy.yml` rsyncs `public/`, `lib/`, `migrations/` and `bin/` into `DEPLOY_PATH` over SSH on
every push to `main`, then runs `bin/migrate` there. `data/` is never touched.
Secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_PATH`, optional `DEPLOY_PORT` (default 22).
Live: https://sleep.telltec.ch
