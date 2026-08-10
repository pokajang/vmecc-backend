# Live UAT Users

The live-UAT users are temporary production accounts for the read-only frontend Playwright audit. Their seeders are deliberately excluded from `DatabaseSeeder`.

## Safety contract

- No email or password is committed.
- Seeding is disabled by default.
- Production requires a second explicit opt-in.
- All six passwords must contain at least 16 characters.
- Configured emails must be unique.
- An existing user is updated only when its name has the exact protected `[Live UAT]` marker expected for that persona.
- TRT and Incident Commander must use an existing active site team; the seeder never creates a production team.
- Rerunning rotates the configured passwords, revokes existing tokens/sessions, resets login locks, and reconciles the exact role assignment.
- Cleanup revokes access, removes role/team assignments, and soft-deletes only exact marked accounts.

## Required variables

```dotenv
LIVE_UAT_USERS_ENABLED=true
LIVE_UAT_USERS_ALLOW_PRODUCTION=true
LIVE_UAT_SITE_TEAM_ID=<active-site-team-id>
VMECC_LIVE_UAT_TRT_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_TRT_PASSWORD=<temporary-secret>
VMECC_LIVE_UAT_INCIDENT_COMMANDER_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_INCIDENT_COMMANDER_PASSWORD=<temporary-secret>
VMECC_LIVE_UAT_CONTRACT_MANAGER_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_CONTRACT_MANAGER_PASSWORD=<temporary-secret>
VMECC_LIVE_UAT_HUMAN_RESOURCE_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_HUMAN_RESOURCE_PASSWORD=<temporary-secret>
VMECC_LIVE_UAT_FINANCE_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_FINANCE_PASSWORD=<temporary-secret>
VMECC_LIVE_UAT_SYSADMIN_EMAIL=<dedicated-email>
VMECC_LIVE_UAT_SYSADMIN_PASSWORD=<temporary-secret>
```

Prefer temporary shell environment variables or a protected deployment secret store. Do not commit values. If the values are placed in `.env`, remove them after cleanup.

## Production commands

From the deployed backend directory, refresh Composer's optimized class map after pulling the new files:

```bash
composer dump-autoload --optimize --no-dev
```

List eligible site teams and select the intended team's ID:

```bash
php artisan tinker --execute="dump(App\\Models\\Team::query()->where('group', 'site')->where('status', 'Active')->get(['id', 'name'])->toArray());"
```

After exporting the required variables:

```bash
php artisan config:clear
php artisan db:seed --class=LiveUatUsersSeeder --force
```

After seeding, copy the same six credential pairs into the local frontend UAT process environment. Do not place them in a committed frontend file.

## Cleanup

Keep or re-export the same emails and explicit opt-in variables, then run:

```bash
php artisan config:clear
php artisan db:seed --class=LiveUatUsersCleanupSeeder --force
```

Unset the temporary shell variables. If the backend `.env` was not changed, rebuild the ordinary cached configuration after unsetting them:

```bash
php artisan config:cache
```

Do not run the cleanup seeder with different email values: it can only locate the accounts listed in the current environment.
