# Production Email Delivery

VMECC sends direct account email and centralized workflow email. Production requires a real SMTP relay, an asynchronous queue worker, and Laravel's scheduler.

## Required environment

Start from `.env.production.example` and replace every `REPLACE_WITH_*` value. Keep these email switches enabled:

```dotenv
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=your-production-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-production-smtp-username
MAIL_PASSWORD=your-production-smtp-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@amiosh.com
MAIL_FROM_NAME="${APP_NAME}"

WORKFLOW_EMAIL_ENABLED=true
WORKFLOW_EMAIL_MODULE_REPORT=true
WORKFLOW_EMAIL_MODULE_INSPECTION=true
WORKFLOW_EMAIL_MODULE_LEAVE=true
WORKFLOW_EMAIL_MODULE_OVERTIME=true
WORKFLOW_EMAIL_MODULE_SALARY=true
WORKFLOW_EMAIL_MODULE_EXPENSE=true
WORKFLOW_EMAIL_MODULE_EXCEPTIONAL=true
WORKFLOW_EMAIL_MODULE_SALARY_ASSIGNMENT=true
WORKFLOW_EMAIL_MODULE_TEAM=true
WORKFLOW_EMAIL_MODULE_ROSTER=true
MESSAGE_DIGEST_EMAIL_ENABLED=true
```

After changing the environment, rebuild Laravel's cached configuration:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
```

## Queue worker

All queued application jobs now use the configured default queue. Run a persistent worker under Supervisor, systemd, or the hosting platform's process manager:

```bash
php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=900
```

Restart workers after every deployment:

```bash
php artisan queue:restart
```

## Scheduler

Run Laravel's scheduler every minute. It initiates workflow digests and unread-message digests:

```cron
* * * * * cd /absolute/path/to/vmecc-backend && php artisan schedule:run >> /dev/null 2>&1
```

## Deployment verification

Run the static readiness checks first:

```bash
php artisan email:production-readiness
```

Then send one live SMTP probe to an inbox controlled by the deployment operator:

```bash
php artisan email:production-readiness --send-to=operator@example.org
```

Verify the queue and delivery records:

```bash
php artisan queue:monitor database:default --max=100
php artisan workflow:email-deliveries --since="24 hours ago"
php artisan workflow:notification-outbox-status --max-age=10
php artisan workflow:dispatch-notification-outbox
php artisan schedule:list
```

Finally exercise one action-required workflow and one FYI workflow. Confirm that immediate mail arrives, the delivery row is marked `sent`, and the next scheduled digest does not include disabled modules.

## Failure checks

```bash
php artisan queue:failed
php artisan workflow:email-deliveries --status=failed --since="24 hours ago"
php artisan workflow:notification-outbox-status --max-age=10
php artisan workflow:dispatch-notification-outbox --retry-failed
```

Application warnings are written to `storage/logs/laravel.log`. SMTP acceptance confirms that the relay accepted the message; inbox delivery and bounces still need to be checked with the SMTP provider.

## Workflow routing checks

Run the routing audit after deployment and after material team, role, or temporary-duty changes:

```bash
php artisan workflow:audit-routing
```

The audit is read-only. A stranded legacy report must be repaired one record at a
time. Preview the exact recipient and team first, then repeat with `--apply`:

```bash
php artisan workflow:repair-report-routing REPORT_ID \
  --team=TEAM_ID \
  --role="Incident Commander" \
  --user=RECIPIENT_USER_ID \
  --actor-user=ADMIN_USER_ID \
  --reason="Verified workflow team and active duty holder"

php artisan workflow:repair-report-routing REPORT_ID \
  --team=TEAM_ID \
  --role="Incident Commander" \
  --user=RECIPIENT_USER_ID \
  --actor-user=ADMIN_USER_ID \
  --reason="Verified workflow team and active duty holder" \
  --apply
```

Do not repair a record by widening it to another team. The command rejects
inactive users, users who do not currently hold the required role in the
persisted workflow team, and actors without administrative assignment authority.
Every applied repair writes report approval history and a routing audit event.

The scheduler also runs `workflow:reconcile-report-routing` and
`workflow:dispatch-notification-outbox` every minute. The first safely reroutes
open reports when a temporary duty assignment expires; the second recovers
notification deliveries if immediate queue dispatch was unavailable.
