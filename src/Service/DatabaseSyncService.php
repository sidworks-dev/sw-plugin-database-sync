<?php declare(strict_types=1);

namespace Sidworks\DatabaseSync\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class DatabaseSyncService
{
    private ?array $remoteDbConfig = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDir
    ) {
    }

    public function getProjectDir(): string
    {
        return $this->projectDir;
    }

    public function getEnvironmentConfig(string $environment): array
    {
        $prefix = strtoupper($environment);
        
        return [
            'host' => $_ENV["SW_DB_SYNC_{$prefix}_HOST"] ?? $_SERVER["SW_DB_SYNC_{$prefix}_HOST"] ?? '',
            'port' => (int) ($_ENV["SW_DB_SYNC_{$prefix}_PORT"] ?? $_SERVER["SW_DB_SYNC_{$prefix}_PORT"] ?? 22),
            'user' => $_ENV["SW_DB_SYNC_{$prefix}_USER"] ?? $_SERVER["SW_DB_SYNC_{$prefix}_USER"] ?? '',
            'key' => $_ENV["SW_DB_SYNC_{$prefix}_KEY"] ?? $_SERVER["SW_DB_SYNC_{$prefix}_KEY"] ?? '',
            'project_path' => $_ENV["SW_DB_SYNC_{$prefix}_PROJECT_PATH"] ?? $_SERVER["SW_DB_SYNC_{$prefix}_PROJECT_PATH"] ?? '',
        ];
    }

    public function getLocalConfig(): array
    {
        $params = $this->connection->getParams();
        
        return [
            'host' => $params['host'] ?? 'localhost',
            'port' => $params['port'] ?? 3306,
            'name' => $params['dbname'] ?? '',
            'user' => $params['user'] ?? '',
            'pass' => $params['password'] ?? '',
        ];
    }

    public function getLocalDomain(): string
    {
        return $_ENV['SW_DB_SYNC_LOCAL_DOMAIN'] ?? $_SERVER['SW_DB_SYNC_LOCAL_DOMAIN'] ?? '';
    }

    public function getDomainMappings(): array
    {
        $mappingsString = $_ENV['SW_DB_SYNC_DOMAIN_MAPPINGS'] ?? $_SERVER['SW_DB_SYNC_DOMAIN_MAPPINGS'] ?? '';
        
        if (empty($mappingsString)) {
            return [];
        }

        $mappings = [];
        $pairs = explode(',', $mappingsString);
        
        foreach ($pairs as $pair) {
            $parts = explode(':', trim($pair));
            if (count($parts) === 2) {
                $from = trim($parts[0]);
                $to = trim($parts[1]);
                if (!empty($from) && !empty($to)) {
                    $mappings[$from] = $to;
                }
            }
        }
        
        return $mappings;
    }

    public function getIgnoredTables(): array
    {
        // Check for config file
        $configFile = $this->projectDir . '/sw-db-sync-config.json';
        if (file_exists($configFile)) {
            $configContent = file_get_contents($configFile);
            $config = json_decode($configContent, true);
            
            if (isset($config['ignore_tables']) && is_array($config['ignore_tables'])) {
                return $config['ignore_tables'];
            }
        }

        return [];
    }

    public function shouldClearCache(): bool
    {
        return ($_ENV['SW_DB_SYNC_CLEAR_CACHE'] ?? $_SERVER['SW_DB_SYNC_CLEAR_CACHE'] ?? 'true') === 'true';
    }

    public function validateEnvironmentConfig(array $config): ?string
    {
        if (empty($config['host'])) {
            return 'SSH host is not configured';
        }
        
        if (empty($config['user'])) {
            return 'SSH user is not configured';
        }
        
        if (empty($config['project_path'])) {
            return 'Project path is not configured';
        }
        
        return null;
    }

    public function getSshOptions(array $config): string
    {
        $options = [
            '-o StrictHostKeyChecking=accept-new',
            '-p ' . $config['port'],
        ];

        if (!empty($config['key'])) {
            $keyPath = $config['key'];
            
            // Expand ~ to home directory
            if (str_starts_with($keyPath, '~/')) {
                $keyPath = ($_ENV['HOME'] ?? getenv('HOME')) . substr($keyPath, 1);
            }
            
            $options[] = '-i ' . escapeshellarg($keyPath);
        }

        return implode(' ', $options);
    }

    public function fetchRemoteDbConfig(array $sshConfig, string $sshOptions, SymfonyStyle $io): bool
    {
        $io->text('Reading remote .env file...');

        $envPath = rtrim($sshConfig['project_path'], '/') . '/.env';
        
        $sshCommand = sprintf(
            'ssh %s %s@%s %s',
            $sshOptions,
            escapeshellarg($sshConfig['user']),
            escapeshellarg($sshConfig['host']),
            escapeshellarg('cat ' . escapeshellarg($envPath))
        );

        $process = Process::fromShellCommandline($sshCommand);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            
            if (str_contains($errorOutput, 'Permission denied') || 
                str_contains($errorOutput, 'Host key verification failed') || 
                str_contains($errorOutput, 'Connection refused')) {
                $io->error('SSH connection failed!');
                $io->text('');
                $io->text('If running inside DDEV, first run: <comment>ddev auth ssh</comment>');
                $io->text('This forwards your SSH agent to the container.');
                $io->text('');
                $io->text('Other options:');
                $io->text('  - Configure SSH key in plugin settings');
                $io->text('  - Ensure SSH key is loaded in your SSH agent');
            } else {
                $io->error('Failed to read remote .env file!');
                $io->text($errorOutput);
            }
            return false;
        }

        // Parse the .env content
        $envContent = $process->getOutput();
        $this->remoteDbConfig = $this->parseEnvContent($envContent);

        if (empty($this->remoteDbConfig['name'])) {
            $io->error('Could not find DATABASE_NAME or DATABASE_URL in remote .env file.');
            return false;
        }

        return true;
    }

    public function getRemoteDbConfig(): ?array
    {
        return $this->remoteDbConfig;
    }

    private function parseEnvContent(string $content): array
    {
        $config = [
            'host' => 'localhost',
            'port' => 3306,
            'name' => '',
            'user' => '',
            'pass' => '',
        ];

        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip comments and empty lines
            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            // Parse DATABASE_URL (Shopware 6 default)
            if (preg_match('/^DATABASE_URL=(.*)$/', $line, $matches)) {
                $url = trim($matches[1], '"\'');
                $parsed = $this->parseDatabaseUrl($url);
                if ($parsed) {
                    $config = array_merge($config, $parsed);
                }
                continue;
            }

            // Parse individual DATABASE_* variables (fallback)
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                $key = $matches[1];
                $value = trim($matches[2], '"\'');

                switch ($key) {
                    case 'DATABASE_HOST':
                        $config['host'] = $value;
                        break;
                    case 'DATABASE_PORT':
                        $config['port'] = (int) $value;
                        break;
                    case 'DATABASE_NAME':
                        $config['name'] = $value;
                        break;
                    case 'DATABASE_USER':
                        $config['user'] = $value;
                        break;
                    case 'DATABASE_PASSWORD':
                        $config['pass'] = $value;
                        break;
                }
            }
        }

        return $config;
    }

    private function parseDatabaseUrl(string $url): ?array
    {
        // Parse URL like: mysql://user:pass@host:port/dbname
        if (!preg_match('#^mysql://([^:]+):([^@]+)@([^:]+):?(\d+)?/(.+)$#', $url, $matches)) {
            return null;
        }

        return [
            'user' => urldecode($matches[1]),
            'pass' => urldecode($matches[2]),
            'host' => $matches[3],
            'port' => isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : 3306,
            'name' => $matches[5],
        ];
    }

    public function createRemoteDump(
        array $sshConfig,
        array $remoteDbConfig,
        string $sshOptions,
        string $remoteDumpFile,
        bool $useGzip,
        array $ignoredTables,
        SymfonyStyle $io
    ): bool {
        $io->text('Creating database dump on remote server...');
        $io->text('  Using --single-transaction (consistent dump without locking)');
        
        if (!empty($ignoredTables)) {
            $io->text(sprintf('  Ignoring %d table(s)', count($ignoredTables)));
        }

        $dbName = escapeshellarg($remoteDbConfig['name']);
        $dbHost = escapeshellarg($remoteDbConfig['host']);
        $dbPort = $remoteDbConfig['port'];
        $dbUser = escapeshellarg($remoteDbConfig['user']);
        $dbPass = escapeshellarg($remoteDbConfig['pass']);
        
        // Base mysqldump file (without .gz extension even if gzip is used)
        $baseDumpFile = $useGzip ? str_replace('.gz', '', $remoteDumpFile) : $remoteDumpFile;
        $baseDumpFileEsc = escapeshellarg($baseDumpFile);

        // Check for column-statistics support and build base flags
        $checkColumnStats = 'if mysqldump --help | grep -q -- --column-statistics; then COLUMN_STATS="--column-statistics=0"; else COLUMN_STATS=""; fi';

        $baseFlags = sprintf(
            '$COLUMN_STATS --quick -C --hex-blob --single-transaction --host=%s --port=%d --user=%s --password=%s',
            $dbHost,
            $dbPort,
            $dbUser,
            $dbPass
        );

        // Step 1: Create structure dump (no data, includes triggers and routines)
        $io->text('  Step 1/2: Dumping database structure...');
        $structureCommand = sprintf(
            '%s && mysqldump %s --no-data --routines %s | LANG=C LC_CTYPE=C LC_ALL=C sed -e \'s/DEFINER[ ]*=[ ]*[^*]*\\*/\\*/g\' -e \'s/DEFINER=[^ ]* / /g\' > %s',
            $checkColumnStats,
            $baseFlags,
            $dbName,
            $baseDumpFileEsc
        );

        // Build ignore-table arguments for data dump
        $ignoreTableArgs = '';
        if (!empty($ignoredTables)) {
            $ignoreTableParts = [];
            foreach ($ignoredTables as $table) {
                $ignoreTableParts[] = sprintf('--ignore-table=%s.%s', 
                    $remoteDbConfig['name'], 
                    $table
                );
            }
            $ignoreTableArgs = ' ' . implode(' ', $ignoreTableParts);
        }

        // Step 2: Create data dump (no create statements, skip triggers)
        $io->text('  Step 2/2: Dumping database data...');
        $dataCommand = sprintf(
            'mysqldump %s --no-create-info --skip-triggers%s %s | LANG=C LC_CTYPE=C LC_ALL=C sed -e \'s/DEFINER[ ]*=[ ]*[^*]*\\*/\\*/g\' -e \'s/DEFINER=[^ ]* / /g\' >> %s',
            $baseFlags,
            $ignoreTableArgs,
            $dbName,
            $baseDumpFileEsc
        );

        // Combine both commands
        $combinedCommand = sprintf('%s && %s', $structureCommand, $dataCommand);
        
        // Add gzip if requested
        if ($useGzip) {
            $combinedCommand .= sprintf(' && gzip %s', $baseDumpFileEsc);
        }

        // Execute via SSH
        $sshCommand = sprintf(
            'ssh %s %s@%s %s',
            $sshOptions,
            escapeshellarg($sshConfig['user']),
            escapeshellarg($sshConfig['host']),
            escapeshellarg($combinedCommand)
        );

        $process = Process::fromShellCommandline($sshCommand);
        $process->setTimeout(600); // 10 minutes
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('Failed to create dump on remote server!');
            $io->text($process->getErrorOutput());
            return false;
        }

        $io->text('<info>✓ Remote dump created successfully</info>');
        return true;
    }

    public function downloadDump(
        array $sshConfig,
        string $sshOptions,
        string $remoteDumpFile,
        string $localDumpFile,
        SymfonyStyle $io
    ): bool {
        $io->text('Downloading dump file via rsync...');

        $rsyncCommand = sprintf(
            'rsync -avz --progress -e "ssh %s" %s@%s:%s %s',
            $sshOptions,
            escapeshellarg($sshConfig['user']),
            escapeshellarg($sshConfig['host']),
            escapeshellarg($remoteDumpFile),
            escapeshellarg($localDumpFile)
        );

        $process = Process::fromShellCommandline($rsyncCommand);
        $process->setTimeout(600); // 10 minutes
        $process->run(function ($type, $buffer) use ($io) {
            if ($type === Process::OUT) {
                $io->write($buffer);
            }
        });

        if (!$process->isSuccessful()) {
            $io->error('Failed to download dump file!');
            $io->text($process->getErrorOutput());
            return false;
        }

        $io->newLine();
        
        // Check local file
        if (!file_exists($localDumpFile) || filesize($localDumpFile) === 0) {
            $io->error('Downloaded file is missing or empty.');
            return false;
        }

        $fileSize = $this->formatFileSize(filesize($localDumpFile));
        $io->text('<info>✓ Download completed (' . $fileSize . ')</info>');

        return true;
    }

    public function cleanupRemoteFile(array $sshConfig, string $sshOptions, string $remotePath): void
    {
        $sshCleanupCommand = sprintf(
            'ssh %s %s@%s %s',
            $sshOptions,
            escapeshellarg($sshConfig['user']),
            escapeshellarg($sshConfig['host']),
            escapeshellarg('rm -f ' . escapeshellarg($remotePath))
        );
        
        $process = Process::fromShellCommandline($sshCleanupCommand);
        $process->setTimeout(30);
        $process->run();
    }

    public function importDump(
        array $localDbConfig,
        string $localDumpFile,
        bool $useGzip,
        SymfonyStyle $io
    ): bool {
        $io->text('Importing database into local environment...');
        $io->text('Stripping DEFINER statements...');

        $mysqlArgs = [
            'mysql',
            '--host=' . $localDbConfig['host'],
            '--port=' . $localDbConfig['port'],
            '--user=' . $localDbConfig['user'],
            '--password=' . $localDbConfig['pass'],
            $localDbConfig['name'],
        ];

        // Prepare init commands to disable foreign key checks and disable unique checks
        // This prevents deadlocks and speeds up the import significantly
        $initCommands = 'SET FOREIGN_KEY_CHECKS=0; SET UNIQUE_CHECKS=0; SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";';

        // Strip DEFINER statements locally before import to avoid privilege errors
        // This handles views, stored procedures, triggers, and events in all MySQL comment formats
        if ($useGzip) {
            $importCommand = sprintf(
                '(echo %s; gunzip -c %s | LANG=C LC_CTYPE=C LC_ALL=C sed -e %s; echo "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;") | %s',
                escapeshellarg($initCommands),
                escapeshellarg($localDumpFile),
                escapeshellarg('s/\/\*![0-9]*\s*DEFINER=[^*]*\*\///g'),
                implode(' ', array_map('escapeshellarg', $mysqlArgs))
            );
            $process = Process::fromShellCommandline($importCommand);
        } else {
            // For non-gzipped files, use sed to strip DEFINER before piping to mysql
            $importCommand = sprintf(
                '(echo %s; LANG=C LC_CTYPE=C LC_ALL=C sed -e %s %s; echo "SET FOREIGN_KEY_CHECKS=1; SET UNIQUE_CHECKS=1;") | %s',
                escapeshellarg($initCommands),
                escapeshellarg('s/\/\*![0-9]*\s*DEFINER=[^*]*\*\///g'),
                escapeshellarg($localDumpFile),
                implode(' ', array_map('escapeshellarg', $mysqlArgs))
            );
            $process = Process::fromShellCommandline($importCommand);
        }

        $process->setTimeout(600); // 10 minutes
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error('Database import failed!');
            $io->text($process->getErrorOutput());
            return false;
        }

        $io->text('<info>✓ Import completed successfully</info>');
        return true;
    }

    public function applyLocalOverrides(string $localDomain, array $domainMappings, SymfonyStyle $io): bool
    {
        // Check for config file first
        $configFile = $this->projectDir . '/sw-db-sync-config.json';
        if (file_exists($configFile)) {
            return $this->applyConfigFileOverrides($configFile, $io);
        }

        if (empty($localDomain) && empty($domainMappings)) {
            $io->warning('No local domain or domain mappings configured, skipping URL overrides');
            return true;
        }

        $io->text('Applying local environment overrides...');

        try {
            $updatedCount = 0;

            // Apply domain mappings if configured
            if (!empty($domainMappings)) {
                foreach ($domainMappings as $from => $to) {
                    // Support both with and without protocol
                    $fromPatterns = [
                        'https://' . $from,
                        'http://' . $from,
                        $from
                    ];

                    $toUrl = 'https://' . $to;

                    foreach ($fromPatterns as $pattern) {
                        $count = $this->connection->executeStatement(
                            'UPDATE sales_channel_domain SET url = :toUrl WHERE url = :fromUrl',
                            [
                                'toUrl' => $toUrl,
                                'fromUrl' => $pattern
                            ]
                        );
                        $updatedCount += $count;

                        // Also update URLs with trailing slashes
                        $count = $this->connection->executeStatement(
                            'UPDATE sales_channel_domain SET url = :toUrl WHERE url = :fromUrl',
                            [
                                'toUrl' => $toUrl,
                                'fromUrl' => $pattern . '/'
                            ]
                        );
                        $updatedCount += $count;
                    }

                    $io->text(sprintf('  • Mapped %s → %s', $from, $to));
                }
            }

            // Apply generic local domain if configured and no specific mappings were applied
            if (!empty($localDomain) && $updatedCount === 0) {
                $this->connection->executeStatement(
                    'UPDATE sales_channel_domain SET url = :url WHERE url NOT LIKE :localDomain',
                    [
                        'url' => 'https://' . $localDomain,
                        'localDomain' => '%' . $localDomain . '%'
                    ]
                );
                $io->text(sprintf('  • Set default domain to %s', $localDomain));
            }

            // Update APP_URL in system_config to use the first mapped domain or local domain
            $appUrl = 'https://' . $localDomain;
            if (!empty($domainMappings)) {
                $firstMappedDomain = reset($domainMappings);
                $appUrl = 'https://' . $firstMappedDomain;
            }

            if (!empty($appUrl)) {
                $this->connection->executeStatement(
                    "UPDATE system_config SET configuration_value = :value WHERE configuration_key = 'core.basicInformation.appUrl'",
                    [
                        'value' => json_encode(['_value' => $appUrl])
                    ]
                );
            }

            $io->text('<info>✓ Local overrides applied</info>');
            return true;
        } catch (\Exception $e) {
            $io->error('Failed to apply local overrides: ' . $e->getMessage());
            return false;
        }
    }

    public function applyConfigFileOverrides(string $configFile, SymfonyStyle $io): bool
    {
        $io->text('Found sw-db-sync-config.json, applying configuration...');

        try {
            $configContent = file_get_contents($configFile);
            $config = json_decode($configContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->error('Invalid JSON in sw-db-sync-config.json: ' . json_last_error_msg());
                return false;
            }

            $overridesApplied = 0;

            // Apply sales channel domain updates
            if (isset($config['sales_channel_domains']) && is_array($config['sales_channel_domains'])) {
                foreach ($config['sales_channel_domains'] as $salesChannelId => $domain) {
                    // Ensure domain has protocol
                    if (!str_starts_with($domain, 'http://') && !str_starts_with($domain, 'https://')) {
                        $domain = 'https://' . $domain;
                    }

                    $count = $this->connection->executeStatement(
                        'UPDATE sales_channel_domain SET url = :url WHERE sales_channel_id = :salesChannelId',
                        [
                            'url' => $domain,
                            'salesChannelId' => hex2bin($salesChannelId)
                        ]
                    );

                    if ($count > 0) {
                        $io->text(sprintf('  • Updated domain for sales channel %s → %s', 
                            substr($salesChannelId, 0, 8) . '...', $domain));
                        $overridesApplied++;
                    }
                }
            }

            // Apply system_config updates
            if (isset($config['system_config']) && is_array($config['system_config'])) {
                foreach ($config['system_config'] as $key => $value) {
                    // Handle sales_channel_id if provided as array
                    $salesChannelId = null;
                    $configValue = $value;

                    if (is_array($value) && isset($value['_value'])) {
                        $configValue = $value['_value'];
                        $salesChannelId = $value['sales_channel_id'] ?? null;
                    }

                    // Wrap value in Shopware format
                    $wrappedValue = json_encode(['_value' => $configValue]);

                    if ($salesChannelId) {
                        $count = $this->connection->executeStatement(
                            'UPDATE system_config SET configuration_value = :value 
                             WHERE configuration_key = :key AND sales_channel_id = :salesChannelId',
                            [
                                'value' => $wrappedValue,
                                'key' => $key,
                                'salesChannelId' => hex2bin($salesChannelId)
                            ]
                        );
                    } else {
                        $count = $this->connection->executeStatement(
                            'UPDATE system_config SET configuration_value = :value 
                             WHERE configuration_key = :key AND sales_channel_id IS NULL',
                            [
                                'value' => $wrappedValue,
                                'key' => $key
                            ]
                        );

                        // Insert if not exists
                        if ($count === 0) {
                            $this->connection->executeStatement(
                                'INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) 
                                 VALUES (:id, :key, :value, NULL, NOW())',
                                [
                                    'id' => $this->generateUuid(),
                                    'key' => $key,
                                    'value' => $wrappedValue
                                ]
                            );
                            $count = 1;
                        }
                    }

                    if ($count > 0) {
                        $io->text(sprintf('  • Updated system config: %s', $key));
                        $overridesApplied++;
                    }
                }
            }

            // Apply raw SQL updates
            if (isset($config['sql_updates']) && is_array($config['sql_updates'])) {
                foreach ($config['sql_updates'] as $sql) {
                    try {
                        $this->connection->executeStatement($sql);
                        $io->text(sprintf('  • Executed SQL: %s', substr($sql, 0, 60) . '...'));
                        $overridesApplied++;
                    } catch (\Exception $e) {
                        $io->warning(sprintf('Failed to execute SQL: %s - Error: %s', 
                            substr($sql, 0, 60), $e->getMessage()));
                    }
                }
            }

            if ($overridesApplied > 0) {
                $io->text(sprintf('<info>✓ Applied %d override(s) from config file</info>', $overridesApplied));
            } else {
                $io->text('<comment>No overrides applied from config file</comment>');
            }

            return true;
        } catch (\Exception $e) {
            $io->error('Failed to apply config file overrides: ' . $e->getMessage());
            return false;
        }
    }

    private function generateUuid(): string
    {
        return random_bytes(16);
    }

    public function clearCache(SymfonyStyle $io): bool
    {
        $io->text('Clearing Shopware cache...');

        $cacheCommand = 'php ' . escapeshellarg($this->projectDir . '/bin/console') . ' cache:clear:all';
        
        $process = Process::fromShellCommandline($cacheCommand);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->warning('Failed to clear cache: ' . $process->getErrorOutput());
            return false;
        }

        $io->text('<info>✓ Cache cleared</info>');
        return true;
    }

    public function runPostSyncCommands(SymfonyStyle $io): bool
    {
        // Check for config file
        $configFile = $this->projectDir . '/sw-db-sync-config.json';
        if (!file_exists($configFile)) {
            return true;
        }

        $configContent = file_get_contents($configFile);
        $config = json_decode($configContent, true);

        if (!isset($config['post_sync_commands']) || !is_array($config['post_sync_commands'])) {
            return true;
        }

        $commands = $config['post_sync_commands'];
        if (empty($commands)) {
            return true;
        }

        $io->text('Running post-sync console commands...');

        $hasErrors = false;
        foreach ($commands as $command) {
            $io->text(sprintf('  • Running: %s', $command));

            $fullCommand = 'php ' . escapeshellarg($this->projectDir . '/bin/console') . ' ' . $command;
            
            $process = Process::fromShellCommandline($fullCommand);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                $io->warning(sprintf('    Failed: %s', $process->getErrorOutput()));
                $hasErrors = true;
            } else {
                $io->text('    <info>✓ Success</info>');
            }
        }

        if (!$hasErrors) {
            $io->text('<info>✓ All post-sync commands completed</info>');
        }

        return !$hasErrors;
    }

    public function hasPostSyncCommands(string $configFile): bool
    {
        if (!file_exists($configFile)) {
            return false;
        }

        $configContent = file_get_contents($configFile);
        $config = json_decode($configContent, true);

        return isset($config['post_sync_commands'])
            && is_array($config['post_sync_commands'])
            && !empty($config['post_sync_commands']);
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }
}
