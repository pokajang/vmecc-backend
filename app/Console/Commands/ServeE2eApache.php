<?php

namespace App\Console\Commands;

use App\Support\E2ePreflight;
use Illuminate\Console\Command;
use RuntimeException;

class ServeE2eApache extends Command
{
    protected $signature = 'e2e:serve-apache
        {--host=127.0.0.1 : Loopback address}
        {--port=8000 : Listen port}
        {--test-config : Generate and validate configuration without starting Apache}';

    protected $description = 'Serve the guarded E2E backend with concurrency-capable Apache WinNT';

    public function handle(E2ePreflight $preflight): int
    {
        $preflight->assertReady();

        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        if ($host !== '127.0.0.1' || $port < 1024 || $port > 65535) {
            throw new RuntimeException('E2E Apache must bind to an explicit 127.0.0.1 high port.');
        }
        if ((int) parse_url((string) config('app.url'), PHP_URL_PORT) !== $port) {
            throw new RuntimeException('E2E Apache port must match APP_URL.');
        }

        $apacheRoot = $this->canonicalDirectory((string) config('e2e.apache.root'), 'Apache root');
        $phpIniDirectory = $this->canonicalDirectory(
            (string) config('e2e.apache.php_ini_directory'),
            'PHP INI directory',
        );
        $apacheBinary = $this->canonicalFile($apacheRoot.'/bin/httpd.exe', 'Apache binary');
        $phpModule = $this->canonicalFile(
            (string) config('e2e.apache.php_module'),
            'Apache PHP module',
        );
        $documentRoot = $this->canonicalDirectory(public_path(), 'backend public directory');
        $runRoot = $this->canonicalDirectory((string) config('e2e.run_root'), 'run root');
        $logRoot = $this->canonicalDirectory($runRoot.'/logs', 'run log directory');
        $configPath = $runRoot.'/apache-e2e.conf';

        $configuration = $this->configuration(
            $apacheRoot,
            $phpIniDirectory,
            $phpModule,
            $documentRoot,
            $logRoot,
            $host,
            $port,
        );
        if (file_put_contents($configPath, $configuration, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the run-owned Apache configuration.');
        }

        $validationCommand = escapeshellarg($apacheBinary).' -t -f '.escapeshellarg($configPath);
        exec($validationCommand.' 2>&1', $validationOutput, $validationExitCode);
        if ($validationExitCode !== 0) {
            throw new RuntimeException('Apache configuration failed: '.implode("\n", $validationOutput));
        }
        $this->info('Apache configuration is valid.');

        if ($this->option('test-config')) {
            return self::SUCCESS;
        }

        $this->info("Starting guarded Apache on http://{$host}:{$port}.");
        $serveCommand = escapeshellarg($apacheBinary)
            .' -f '.escapeshellarg($configPath)
            .' -DFOREGROUND';
        passthru($serveCommand, $exitCode);

        return $exitCode;
    }

    private function configuration(
        string $apacheRoot,
        string $phpIniDirectory,
        string $phpModule,
        string $documentRoot,
        string $logRoot,
        string $host,
        int $port,
    ): string {
        return <<<CONF
            ServerRoot "{$apacheRoot}"
            Listen {$host}:{$port}
            ServerName {$host}:{$port}
            PidFile "{$logRoot}/apache.pid"

            LoadModule authz_core_module modules/mod_authz_core.so
            LoadModule dir_module modules/mod_dir.so
            LoadModule env_module modules/mod_env.so
            LoadModule log_config_module modules/mod_log_config.so
            LoadModule mime_module modules/mod_mime.so
            LoadModule negotiation_module modules/mod_negotiation.so
            LoadModule rewrite_module modules/mod_rewrite.so
            LoadModule setenvif_module modules/mod_setenvif.so
            LoadModule php_module "{$phpModule}"

            PHPIniDir "{$phpIniDirectory}"
            AddType application/x-httpd-php .php
            TypesConfig conf/mime.types
            DirectoryIndex index.php index.html
            ErrorLog "{$logRoot}/apache-error.log"
            LogFormat "%h %l %u %t \"%r\" %>s %b" common
            CustomLog "{$logRoot}/apache-access.log" common
            LogLevel warn

            DocumentRoot "{$documentRoot}"
            <Directory "{$documentRoot}">
                AllowOverride All
                Options FollowSymLinks
                Require all granted
            </Directory>
            CONF;
    }

    private function canonicalDirectory(string $path, string $label): string
    {
        $resolved = $path === '' ? false : realpath($path);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException("The {$label} is unavailable.");
        }

        return str_replace('\\', '/', $resolved);
    }

    private function canonicalFile(string $path, string $label): string
    {
        $resolved = $path === '' ? false : realpath($path);
        if ($resolved === false || ! is_file($resolved)) {
            throw new RuntimeException("The {$label} is unavailable.");
        }

        return str_replace('\\', '/', $resolved);
    }
}
