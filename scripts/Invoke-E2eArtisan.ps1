[CmdletBinding(PositionalBinding = $false)]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^VMECC-QA-\d{8}-\d{6}-[a-z0-9]{6}$')]
    [string] $RunId,

    [ValidateRange(1024, 65535)]
    [int] $BackendPort = 8000,

    [ValidateRange(1024, 65535)]
    [int] $FrontendPort = 3000,

    [Parameter(Mandatory = $true)]
    [string[]] $ArtisanArguments
)

$ErrorActionPreference = 'Stop'

$backendRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$workspaceRoot = [System.IO.Path]::GetFullPath((Join-Path $backendRoot '..'))
$qaRoot = [System.IO.Path]::GetFullPath((Join-Path $workspaceRoot '.qa'))
$runRoot = [System.IO.Path]::GetFullPath((Join-Path $qaRoot $RunId))
$lockRoot = [System.IO.Path]::GetFullPath((Join-Path $qaRoot 'locks'))

if (-not $runRoot.StartsWith($qaRoot + [System.IO.Path]::DirectorySeparatorChar, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'The E2E run root escapes the QA root.'
}

foreach ($path in @($qaRoot, $runRoot, $lockRoot)) {
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Required E2E directory does not exist: $path"
    }

    $item = Get-Item -LiteralPath $path -Force
    if ($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "E2E directory cannot be a reparse point: $path"
    }
}

$requiredRunDirectories = @(
    'cache',
    'downloads',
    'logs',
    'sessions',
    'storage\local',
    'storage\public'
)
foreach ($relativePath in $requiredRunDirectories) {
    $path = Join-Path $runRoot $relativePath
    if (-not (Test-Path -LiteralPath $path -PathType Container)) {
        throw "Required E2E run directory does not exist: $path"
    }
    if ((Get-Item -LiteralPath $path -Force).Attributes -band [System.IO.FileAttributes]::ReparsePoint) {
        throw "E2E run directory cannot be a reparse point: $path"
    }
}

$sanitizedVariables = @(
    'DATABASE_URL',
    'DB_URL',
    'MAIL_URL',
    'REDIS_URL',
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_SESSION_TOKEN',
    'OPENAI_API_KEY',
    'LOG_SLACK_WEBHOOK_URL',
    'PUSHER_APP_ID',
    'PUSHER_APP_KEY',
    'PUSHER_APP_SECRET',
    'ABLY_KEY'
)
foreach ($name in $sanitizedVariables) {
    [Environment]::SetEnvironmentVariable($name, '', 'Process')
}

$env:APP_ENV = 'testing'
$env:APP_DEBUG = 'false'
$env:APP_URL = "http://127.0.0.1:$BackendPort"
$env:APP_FRONTEND_URL = "http://127.0.0.1:$FrontendPort"
$env:CORS_ALLOWED_ORIGINS = "http://127.0.0.1:$FrontendPort"
$env:SANCTUM_STATEFUL_DOMAINS = "127.0.0.1:$FrontendPort"

$env:DB_CONNECTION = 'pgsql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_DATABASE = 'vmecc_test'
$env:DB_USERNAME = 'vmecc_e2e'
$env:DB_PASSWORD = ''
$env:E2E_ALLOWED_DATABASE = 'vmecc_test'
$env:E2E_ALLOWED_DB_HOST = '127.0.0.1'
$env:E2E_ALLOWED_DB_USERNAME = 'vmecc_e2e'

$env:E2E_RUN_ID = $RunId
$env:E2E_RUN_ROOT = $runRoot.Replace('\', '/')
$env:E2E_LOCK_ROOT = $lockRoot.Replace('\', '/')
$env:E2E_DOWNLOAD_ROOT = (Join-Path $runRoot 'downloads').Replace('\', '/')
$env:E2E_MINIMUM_FREE_BYTES = '1073741824'
$env:E2E_APACHE_ROOT = 'C:/laragon/bin/apache/httpd-2.4.65-250724-Win64-VS17'
$env:E2E_APACHE_PHP_MODULE = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php8apache2_4.dll'
$env:E2E_PHP_INI_DIRECTORY = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64'

$env:LOG_CHANNEL = 'single'
$env:LOG_PATH = (Join-Path $runRoot 'logs\laravel.log').Replace('\', '/')
$env:LOG_DEPRECATIONS_CHANNEL = 'null'
$env:BROADCAST_DRIVER = 'log'
$env:CACHE_DRIVER = 'array'
$env:CACHE_PATH = (Join-Path $runRoot 'cache').Replace('\', '/')
$env:CACHE_PREFIX = ($RunId.ToLowerInvariant() -replace '[^a-z0-9]+', '_') + '_'
$env:QUEUE_CONNECTION = 'sync'
$env:SESSION_DRIVER = 'file'
$env:SESSION_FILES_PATH = (Join-Path $runRoot 'sessions').Replace('\', '/')
$env:SESSION_COOKIE = 'vmecc_e2e_laravel_session'

$env:FILESYSTEM_DISK = 'local'
$env:PUBLIC_UPLOADS_DISK = 'public'
$env:FILESYSTEM_LOCAL_ROOT = (Join-Path $runRoot 'storage\local').Replace('\', '/')
$env:FILESYSTEM_PUBLIC_ROOT = (Join-Path $runRoot 'storage\public').Replace('\', '/')

$env:MAIL_MAILER = 'array'
$env:MAIL_FROM_ADDRESS = 'qa@vmecc.example.test'
$env:WORKFLOW_EMAIL_ENABLED = 'false'
$env:AI_HELPER_ENABLED = 'false'

Push-Location $backendRoot
try {
    & php artisan @ArtisanArguments --env=testing
    exit $LASTEXITCODE
}
finally {
    Pop-Location
}
