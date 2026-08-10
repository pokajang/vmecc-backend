# Live UAT Users

The live-UAT users are temporary database accounts for the read-only frontend Playwright audit. Their seeders are deliberately excluded from `DatabaseSeeder` and must be run explicitly.

No production `.env` changes are required.

## Accounts

The six dedicated emails and role contracts are defined in `config/live_uat.php`:

- Tactical Response Team;
- Incident Commander;
- Contract Manager;
- Human Resource;
- Finance;
- System Administrator.

TRT and Incident Commander are assigned to the existing Alpha team. Alpha must remain `On Duty` and may use either the legacy null group or the newer `site` group. The seeder does not create or modify the team.

## Safety contract

- No plaintext password is committed or stored in application configuration.
- Strong random plaintext credentials are retained only in the workspace-level `UAT/creds.md`, outside both repositories.
- Only one-way bcrypt hashes are committed in the backend persona configuration.
- Rerunning restores the recorded credentials and revokes existing tokens/sessions.
- An existing user is updated only when its name exactly matches the protected `[Live UAT]` marker.
- The exact role, scope, and team membership are reconciled transactionally.
- Cleanup revokes access, removes role/team assignments, and soft-deletes only exact marked accounts.

These are real authenticated production accounts. Keep the credentials private and execute the cleanup before system handover.

## Seed

From the deployed backend directory:

```bash
composer dump-autoload --optimize --no-dev
php artisan optimize:clear
php artisan db:seed --class=LiveUatUsersSeeder --force
php artisan config:cache
```

Use the credential pairs from the protected workspace-level `UAT/creds.md` as temporary environment variables in the local frontend Playwright process. Do not commit, upload, or copy that file into either repository.

## Cleanup before handover

```bash
php artisan db:seed --class=LiveUatUsersCleanupSeeder --force
```

The cleanup seeder uses the fixed marked email/name pairs in `config/live_uat.php`; no password or environment configuration is required.
