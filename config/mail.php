<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send any email
    | messages sent by your application. Alternative mailers may be setup
    | and used as needed; however, this mailer will be used by default.
    |
    */

    'default' => env('MAIL_MAILER', 'smtp'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers to be used while
    | sending an e-mail. You will specify which one you are using for your
    | mailers below. You are free to add additional mailers as required.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "log", "array", "failover", "roundrobin"
    |
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port' => env('MAIL_PORT', 587),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => null,
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'mailgun' => [
            'transport' => 'mailgun',
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all e-mails sent by your application to be sent from
    | the same address. Here, you may specify a name and address that is
    | used globally for all e-mails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    'workflow_notifications' => [
        'enabled' => env('WORKFLOW_EMAIL_ENABLED', false),
        'digest_times' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('WORKFLOW_DIGEST_TIMES', '06:00,18:00')),
        ))),
        'digest_window_hours' => env('WORKFLOW_DIGEST_WINDOW_HOURS', 12),
        'coalesce_window_hours' => env('WORKFLOW_NOTIFICATION_COALESCE_WINDOW_HOURS', 24),
        'channel_policies' => [
            'action_required_review' => env(
                'WORKFLOW_POLICY_ACTION_REQUIRED_REVIEW',
                'in_app_plus_immediate_plus_digest_reminder',
            ),
            'action_required_approve' => env(
                'WORKFLOW_POLICY_ACTION_REQUIRED_APPROVE',
                'in_app_plus_immediate_plus_digest_reminder',
            ),
            'final_outcome' => env('WORKFLOW_POLICY_FINAL_OUTCOME', 'in_app_plus_immediate_email'),
            'fyi_update' => env('WORKFLOW_POLICY_FYI_UPDATE', 'in_app_plus_digest'),
            'administrative_info' => env('WORKFLOW_POLICY_ADMINISTRATIVE_INFO', 'in_app_plus_digest'),
        ],
        'modules' => [
            'report' => env('WORKFLOW_EMAIL_MODULE_REPORT', false),
            'inspection' => env('WORKFLOW_EMAIL_MODULE_INSPECTION', false),
            'leave' => env('WORKFLOW_EMAIL_MODULE_LEAVE', false),
            'overtime' => env('WORKFLOW_EMAIL_MODULE_OVERTIME', false),
            'salary' => env('WORKFLOW_EMAIL_MODULE_SALARY', false),
            'expense' => env('WORKFLOW_EMAIL_MODULE_EXPENSE', false),
            'exceptional' => env('WORKFLOW_EMAIL_MODULE_EXCEPTIONAL', false),
            'salary_assignment' => env('WORKFLOW_EMAIL_MODULE_SALARY_ASSIGNMENT', false),
            'team' => env('WORKFLOW_EMAIL_MODULE_TEAM', false),
            'roster' => env('WORKFLOW_EMAIL_MODULE_ROSTER', false),
        ],
    ],

    'message_digest' => [
        'enabled' => env('MESSAGE_DIGEST_EMAIL_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Markdown Mail Settings
    |--------------------------------------------------------------------------
    |
    | If you are using Markdown based email rendering, you may configure your
    | theme and component paths here, allowing you to customize the design
    | of the emails. Or, you may simply stick with the Laravel defaults!
    |
    */

    'markdown' => [
        'theme' => 'default',

        'paths' => [
            resource_path('views/vendor/mail'),
        ],
    ],

];
