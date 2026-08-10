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

- No password is committed or stored in configuration.
- Each run generates a different random 24-character password for every persona.
- The six credentials are shown once in the seeder's terminal table and must be copied into a secure temporary record.
- Rerunning rotates every password and revokes existing tokens/sessions.
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

Copy the resulting credential table immediately. The passwords cannot be recovered from the database. If they are lost, rerun the seeder to rotate all six.

Use the same credential pairs as temporary environment variables in the local frontend Playwright process. Do not commit them or add them to either repository.

## Cleanup before handover

```bash
php artisan db:seed --class=LiveUatUsersCleanupSeeder --force
```

The cleanup seeder uses the fixed marked email/name pairs in `config/live_uat.php`; no password or environment configuration is required.
