<?php declare(strict_types=1);

namespace Sidworks\DatabaseSync\Command;

use Sidworks\DatabaseSync\Service\DatabaseSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sidworks:db:sync',
    description: 'Sync database from staging/production to development via SSH'
)]
class DatabaseSyncCommand extends Command
{
    public function __construct(
        private readonly DatabaseSyncService $syncService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'Environment to sync from: staging or production (not needed with --apply-config-only)')
            ->addOption('keep-dump', 'k', InputOption::VALUE_NONE, 'Keep the downloaded dump file after import')
            ->addOption('skip-import', null, InputOption::VALUE_NONE, 'Only download the dump, skip local import')
            ->addOption('no-gzip', null, InputOption::VALUE_NONE, 'Do not compress the dump file')
            ->addOption('skip-overrides', null, InputOption::VALUE_NONE, 'Skip applying local environment overrides')
            ->addOption('no-ignore', null, InputOption::VALUE_NONE, 'Don\'t ignore any tables (dump everything)')
            ->addOption('apply-config-only', null, InputOption::VALUE_OPTIONAL, 'Only apply config file without syncing database (specify config file path)', false)
            ->addOption('skip-cache-clear', null, InputOption::VALUE_NONE, 'Skip clearing cache after applying configuration')
            ->addOption('skip-post-commands', null, InputOption::VALUE_NONE, 'Skip running post-sync commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if we should only apply config
        $applyConfigOnly = $input->getOption('apply-config-only');
        if ($applyConfigOnly !== false) {
            return $this->applyConfigOnly($input, $output, $io, $applyConfigOnly);
        }

        $io->title('Sidworks Database Sync');

        // Get environment
        $environment = $input->getArgument('environment');
        if (empty($environment)) {
            $io->error('Environment argument is required. Choose either "staging" or "production".');
            $io->text('Or use --apply-config-only to only apply configuration without syncing.');
            return Command::FAILURE;
        }

        $environment = strtolower($environment);
        if (!in_array($environment, ['staging', 'production'])) {
            $io->error('Invalid environment. Choose either "staging" or "production".');
            return Command::FAILURE;
        }

        // Get options
        $keepDump = $input->getOption('keep-dump');
        $skipImport = $input->getOption('skip-import');
        $useGzip = !$input->getOption('no-gzip');
        $skipOverrides = $input->getOption('skip-overrides');
        $noIgnore = $input->getOption('no-ignore');

        // Get configuration
        $io->section('Reading configuration');
        
        $sshConfig = $this->syncService->getEnvironmentConfig($environment);
        $localDbConfig = $this->syncService->getLocalConfig();
        $localDomain = $this->syncService->getLocalDomain();
        $domainMappings = $this->syncService->getDomainMappings();

        // Validate configuration
        $error = $this->syncService->validateEnvironmentConfig($sshConfig);
        if ($error) {
            $io->error($error);
            $io->text('Please add the configuration to your .env.local file.');
            $io->text('See the plugin README.md for configuration examples.');
            return Command::FAILURE;
        }

        $sshOptions = $this->syncService->getSshOptions($sshConfig);

        // Fetch remote database configuration
        if (!$this->syncService->fetchRemoteDbConfig($sshConfig, $sshOptions, $io)) {
            return Command::FAILURE;
        }

        $remoteDbConfig = $this->syncService->getRemoteDbConfig();

        // Determine which tables to ignore
        $ignoredTables = [];
        if (!$noIgnore) {
            $ignoredTables = $this->syncService->getIgnoredTables();
        }

        // Display configuration
        $io->section('Configuration');
        
        $configTable = [
            ['Environment', ucfirst($environment)],
            ['Remote Host', $sshConfig['user'] . '@' . $sshConfig['host'] . ':' . $sshConfig['port']],
            ['Remote Project', $sshConfig['project_path']],
            ['Remote Database', $remoteDbConfig['name']],
            ['Local Database', $localDbConfig['name']],
            ['Local Domain', $localDomain ?: '(not configured)'],
            ['Compress', $useGzip ? 'Yes' : 'No'],
            ['Ignored Tables', count($ignoredTables) . ' table(s)'],
        ];
        
        if (!empty($domainMappings)) {
            $mappingStrings = [];
            foreach ($domainMappings as $from => $to) {
                $mappingStrings[] = $from . ' → ' . $to;
            }
            $configTable[] = ['Domain Mappings', implode("\n", $mappingStrings)];
        }
        
        $io->table(['Setting', 'Value'], $configTable);
        
        // Show ignored tables if requested
        if (!empty($ignoredTables) && $io->isVerbose()) {
            $io->text('Ignored tables:');
            foreach ($ignoredTables as $table) {
                $io->text('  • ' . $table);
            }
            $io->newLine();
        }

        // Confirm before proceeding
        if (!$skipImport && !$io->confirm('⚠️  This will OVERWRITE your local database. Continue?', false)) {
            $io->warning('Sync cancelled.');
            return Command::SUCCESS;
        }

        $timestamp = date('Y-m-d_His');
        $dumpFileName = 'sync_' . $environment . '_' . $timestamp . '.sql' . ($useGzip ? '.gz' : '');
        $remoteDumpFile = rtrim($sshConfig['project_path'], '/') . '/' . $dumpFileName;
        $localDumpFile = $this->syncService->getProjectDir() . '/' . $dumpFileName;

        // Step 1: Create dump on remote server
        $io->section('Step 1/4: Creating remote dump');
        if (!$this->syncService->createRemoteDump(
            $sshConfig,
            $remoteDbConfig,
            $sshOptions,
            $remoteDumpFile,
            $useGzip,
            $ignoredTables,
            $io
        )) {
            return Command::FAILURE;
        }

        // Step 2: Download dump file
        $io->section('Step 2/4: Downloading dump');
        if (!$this->syncService->downloadDump(
            $sshConfig,
            $sshOptions,
            $remoteDumpFile,
            $localDumpFile,
            $io
        )) {
            $this->syncService->cleanupRemoteFile($sshConfig, $sshOptions, $remoteDumpFile);
            return Command::FAILURE;
        }

        // Clean up remote dump file
        $io->text('Cleaning up remote dump file...');
        $this->syncService->cleanupRemoteFile($sshConfig, $sshOptions, $remoteDumpFile);

        // Step 3: Import into local database
        if (!$skipImport) {
            $io->section('Step 3/4: Importing database');
            if (!$this->syncService->importDump(
                $localDbConfig,
                $localDumpFile,
                $useGzip,
                $io
            )) {
                return Command::FAILURE;
            }

            // Step 4: Apply local overrides
            if (!$skipOverrides) {
                $io->section('Step 4/4: Applying local overrides');
                $this->syncService->applyLocalOverrides($localDomain, $domainMappings, $io);
            }

            // Clear cache if configured
            if ($this->syncService->shouldClearCache()) {
                $this->syncService->clearCache($io);
            }

            // Run post-sync commands
            $this->syncService->runPostSyncCommands($io);
        }

        // Clean up local dump file unless --keep-dump is specified
        if (!$keepDump && !$skipImport) {
            unlink($localDumpFile);
            $io->text('Local dump file removed.');
        } else {
            $io->text('Dump file saved at: ' . $localDumpFile);
        }

        $io->newLine();
        $io->success('🎉 Database sync completed successfully!');

        if (!$skipImport) {
            $io->text('Your local database now contains data from ' . $environment . '.');
            if (!$skipOverrides) {
                if (!empty($domainMappings)) {
                    $io->text('Domain mappings applied:');
                    foreach ($domainMappings as $from => $to) {
                        $io->text('  • ' . $from . ' → https://' . $to);
                    }
                } elseif ($localDomain) {
                    $io->text('URLs have been updated to: https://' . $localDomain);
                }
            }
        }

        return Command::SUCCESS;
    }

    private function applyConfigOnly(InputInterface $input, OutputInterface $output, SymfonyStyle $io, $configFileName): int
    {
        $io->title('Sidworks Database Config Application');

        $skipCacheClear = $input->getOption('skip-cache-clear');
        $skipPostCommands = $input->getOption('skip-post-commands');

        // Default to sw-db-sync-config.json if no path specified
        if ($configFileName === null || $configFileName === '') {
            $configFileName = 'sw-db-sync-config.json';
        }

        // Resolve config file path
        $projectRoot = $this->syncService->getProjectDir();
        if (str_starts_with($configFileName, '/')) {
            // Absolute path
            $configFile = $configFileName;
        } else {
            // Relative path from project root
            $configFile = $projectRoot . '/' . $configFileName;
        }

        // Check if config file exists
        if (!file_exists($configFile)) {
            $io->error('Configuration file not found: ' . $configFileName);
            $io->text('Searched in: ' . $configFile);
            $io->text('');
            $io->text('Usage examples:');
            $io->text('  bin/console sidworks:db:sync --apply-config-only');
            $io->text('  bin/console sidworks:db:sync --apply-config-only=custom-config.json');
            $io->text('  bin/console sidworks:db:sync --apply-config-only=/absolute/path/to/config.json');
            return Command::FAILURE;
        }

        $io->section('Configuration file found');
        $io->text('File: ' . $configFile);

        // Apply configuration overrides
        $io->section('Applying configuration');
        if (!$this->syncService->applyConfigFileOverrides($configFile, $io)) {
            return Command::FAILURE;
        }

        // Run post-sync commands if not skipped
        if (!$skipPostCommands) {
            $postCommandsExist = $this->syncService->hasPostSyncCommands($configFile);
            if ($postCommandsExist) {
                $io->section('Running post-sync commands');
                $this->syncService->runPostSyncCommands($io);
            }
        }

        // Clear cache if configured and not skipped
        if (!$skipCacheClear && $this->syncService->shouldClearCache()) {
            $io->section('Clearing cache');
            $this->syncService->clearCache($io);
        }

        $io->newLine();
        $io->success('🎉 Configuration applied successfully!');

        return Command::SUCCESS;
    }
}
