# Sidworks Database Sync Plugin

A Shopware 6 plugin for syncing databases from staging/production environments to your local development environment via SSH.

## Features

- Sync from staging or production environments
- SSH connection support with key authentication
- Automatic database dump, download, and import
- Table filtering for performance and GDPR compliance
- Local environment overrides (URLs, domains, system config)
- Post-sync console commands (e.g. deactivate plugins, create users)
- Apply config overrides without a full sync (`--apply-config-only`)
- DDEV compatible

## Installation

#### Via Composer (Recommended)

```bash
composer require sidworks/sw-plugin-database-sync --dev
bin/console plugin:refresh
bin/console plugin:install --activate SidworksDatabaseSync
bin/console cache:clear
```

#### Manual Installation

1. Clone or download this repository to `custom/plugins/SidworksDatabaseSync`
2. Run the following commands:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SidworksDatabaseSync
bin/console cache:clear
```

## Configuration

### Environment Variables

Add the following to your `.env.local` file:

```bash
# Staging Environment
SW_DB_SYNC_STAGING_HOST=your-staging-server.com
SW_DB_SYNC_STAGING_USER=your-username
SW_DB_SYNC_STAGING_PORT=22
SW_DB_SYNC_STAGING_PROJECT_PATH=/var/www/html
SW_DB_SYNC_STAGING_KEY=~/.ssh/id_ed25519   # Optional: SSH key path

# Production Environment
SW_DB_SYNC_PRODUCTION_HOST=your-production-server.com
SW_DB_SYNC_PRODUCTION_USER=your-username
SW_DB_SYNC_PRODUCTION_PORT=22
SW_DB_SYNC_PRODUCTION_PROJECT_PATH=/var/www/html
SW_DB_SYNC_PRODUCTION_KEY=~/.ssh/id_ed25519 # Optional: SSH key path

# Local overrides
SW_DB_SYNC_LOCAL_DOMAIN=your-project.ddev.site
SW_DB_SYNC_DOMAIN_MAPPINGS=production.com:your-project.ddev.site,staging.com:your-project.ddev.site
SW_DB_SYNC_CLEAR_CACHE=true   # Set to "false" to skip cache clearing
```

#### Required Variables

| Variable | Description |
|----------|-------------|
| `SW_DB_SYNC_[ENV]_HOST` | Hostname or IP address of the server |
| `SW_DB_SYNC_[ENV]_USER` | SSH username |
| `SW_DB_SYNC_[ENV]_PORT` | SSH port (default: 22) |
| `SW_DB_SYNC_[ENV]_PROJECT_PATH` | Remote Shopware project directory path |

#### Optional Variables

| Variable | Description |
|----------|-------------|
| `SW_DB_SYNC_[ENV]_KEY` | Path to SSH private key (supports `~/` expansion) |
| `SW_DB_SYNC_LOCAL_DOMAIN` | Default local domain for URL overrides |
| `SW_DB_SYNC_DOMAIN_MAPPINGS` | Comma-separated `from:to` domain mappings |
| `SW_DB_SYNC_CLEAR_CACHE` | Clear cache after sync — `true` (default) or `false` |

Replace `[ENV]` with either `STAGING` or `PRODUCTION`.

## Advanced Configuration (Config File)

Create a `sw-db-sync-config.json` file in your Shopware root directory:

```bash
cp vendor/sidworks/sw-plugin-database-sync/sw-db-sync-config.json.example sw-db-sync-config.json
nano sw-db-sync-config.json
```

When this file exists, it takes priority over environment variable domain mappings.

### Configuration Sections

#### `ignore_tables`

Tables to skip during the data dump. The example file includes 62 commonly ignored tables covering:

**Performance tables** (always recommended):
- `enqueue` — Message queue
- `product_keyword_dictionary` — Search keywords
- `product_search_keyword` — Search index
- `log_entry` — Application logs
- `message_queue_stats` — Queue statistics
- `elasticsearch_index_task` — Search indexing
- `state_machine_history` — Order/payment state history

**GDPR/Privacy tables** (customer and order data):
- `customer`, `customer_address`, `customer_tag`, `customer_wishlist`
- `order`, `order_address`, `order_delivery`, `order_line_item`, `order_transaction`
- `cart` — Shopping carts
- `user`, `user_config`, `user_recovery`, `user_access_key` — Admin users
- `newsletter_recipient`, `newsletter_recipient_tag`
- Payment plugin tables (Klarna, Payone, Pay.nl, Unzer)

**Tip**: Remove tables from the list if you need them in development. For example, keep `user` if you need existing admin accounts.

#### `sales_channel_domains`

Map sales channel IDs to local domains. Find your sales channel IDs with:

```sql
SELECT LOWER(HEX(id)) as id, name FROM sales_channel;
```

```json
{
    "sales_channel_domains": {
        "018d5f1e5e7e7f1e8b8d5f1e5e7e7f1e": "https://your-project.ddev.site"
    }
}
```

#### `system_config`

Update Shopware system configuration values after import:

```json
{
    "system_config": {
        "core.basicInformation.email": "local@example.com",
        "core.mailerSettings.host": "localhost",
        "core.mailerSettings.port": "1025"
    }
}
```

Values that don't exist yet are automatically inserted.

#### `sql_updates`

Execute raw SQL statements after import. Use with caution:

```json
{
    "sql_updates": [
        "UPDATE sales_channel_domain SET url = REPLACE(url, 'production.com', 'ddev.site')"
    ]
}
```

#### `post_sync_commands`

Console commands to run after the sync completes. Failed commands produce warnings but don't abort the process:

```json
{
    "post_sync_commands": [
        "user:create admin -a --email info@example.com -p thisIsMyPassword",
        "plugin:refresh",
        "plugin:install SidworksDatabaseSync -a",
        "theme:compile"
    ]
}
```

Common use cases:
- Create a local admin user after importing (since `user` table is typically ignored)
- Deactivate production-only plugins or apps
- Refresh and reinstall the sync plugin itself after import
- Recompile themes

### Environment Variables vs Config File

| Method | Use case |
|--------|----------|
| Environment variables (`.env.local`) | Simple domain mappings, basic setups |
| Config file (`sw-db-sync-config.json`) | Advanced overrides, system config, SQL updates, post-sync commands |

If `sw-db-sync-config.json` exists, it takes priority over environment variable domain mappings.

## Usage

### Basic sync from staging

```bash
bin/console sidworks:db:sync staging
```

### Sync from production

```bash
bin/console sidworks:db:sync production
```

### Options

| Option | Description |
|--------|-------------|
| `--keep-dump, -k` | Keep the dump file in `var/dumps/` after import |
| `--skip-import` | Only download the dump, don't import |
| `--no-gzip` | Don't compress the dump (faster for small databases) |
| `--skip-overrides` | Skip applying local environment overrides |
| `--no-ignore` | Dump all tables (don't ignore any) |
| `--apply-config-only[=path]` | Only apply config file overrides without syncing |
| `--skip-cache-clear` | Skip clearing cache after applying configuration |
| `--skip-post-commands` | Skip running post-sync commands |

### Apply config only (no database sync)

Re-apply your `sw-db-sync-config.json` overrides without downloading a new database dump. Useful after manual database changes or when you just need to update system config:

```bash
# Use default sw-db-sync-config.json
bin/console sidworks:db:sync --apply-config-only

# Use a custom config file
bin/console sidworks:db:sync --apply-config-only=custom-config.json

# Use an absolute path
bin/console sidworks:db:sync --apply-config-only=/path/to/config.json

# Apply config only, skip cache clear and post-sync commands
bin/console sidworks:db:sync --apply-config-only --skip-cache-clear --skip-post-commands
```

### Verbose output

Show which tables are being ignored:

```bash
bin/console sidworks:db:sync staging -v
```

## DDEV Usage

If you're using DDEV, forward your SSH agent first:

```bash
ddev auth ssh
```

Then run the sync command inside the container:

```bash
ddev exec bin/console sidworks:db:sync staging
```

## How It Works

### Execution Flow

1. **Validate configuration** — Check required SSH and environment settings
2. **Fetch remote `.env`** — Read database credentials from the remote server via SSH
3. **Create remote dump** — Two-step mysqldump (structure + data) on the remote server
4. **Download dump** — Transfer the compressed dump via rsync
5. **Cleanup remote** — Delete the dump file from the remote server
6. **Import database** — Import dump into local database with optimizations
7. **Apply overrides** — Update domains, system config, and run SQL updates
8. **Clear cache** — Run `cache:clear:all`
9. **Run post-sync commands** — Execute configured console commands
10. **Cleanup local** — Delete the local dump file (unless `--keep-dump`)

### mysqldump Strategy

The plugin uses a two-step dump process:

1. **Structure dump**: `--no-data --routines` exports table structures, triggers, stored procedures, and functions
2. **Data dump**: `--no-create-info --skip-triggers` exports data only, skipping ignored tables

**Common flags:**
- `--single-transaction` — InnoDB consistent read without table locks (safe for production)
- `--quick` — Stream results without buffering entire tables
- `-C` — Compress data between client and server
- `--hex-blob` — Binary data as hex for portability
- `--column-statistics=0` — Disable statistics collection (auto-detected if supported)

**Post-processing (both steps):**
- `LANG=C LC_CTYPE=C LC_ALL=C` — Consistent character encoding
- `sed` strips `DEFINER` clauses for cross-server compatibility

### Import Optimization

The import pipeline applies several optimizations:

- `SET FOREIGN_KEY_CHECKS=0` — Disables FK constraint checks during import to prevent deadlocks and speed up loading
- `SET UNIQUE_CHECKS=0` — Skips unique index verification during bulk insert
- `SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO"` — Preserves explicit zero values in auto-increment columns
- DEFINER clauses are stripped locally via `sed` before piping to `mysql`, avoiding privilege errors when views or stored procedures reference specific users
- Foreign key and unique checks are re-enabled at the end of the import

### Override Priority

1. If `sw-db-sync-config.json` exists → use config file (sales channel domains, system config, SQL updates)
2. Otherwise → use environment variable domain mappings (`SW_DB_SYNC_DOMAIN_MAPPINGS`)
3. Fallback → use `SW_DB_SYNC_LOCAL_DOMAIN` to set all sales channel domains

## Troubleshooting

### SSH Connection Failed

1. **In DDEV**: Run `ddev auth ssh` to forward your SSH agent into the container
2. **SSH Key**: Set `SW_DB_SYNC_[ENV]_KEY` to your private key path
3. **SSH Agent**: Ensure your key is loaded: `ssh-add ~/.ssh/your_key`

### Permission Denied

Your SSH user needs:
- Read access to the remote `.env` file
- Execute permissions for `mysqldump`
- Write permissions to `/tmp` on the remote server

### Remote .env Not Found

Verify that `SW_DB_SYNC_[ENV]_PROJECT_PATH` points to the Shopware root directory containing the `.env` file.

### Import Fails with DEFINER Errors

This is handled automatically. Both the remote dump and local import pipeline strip DEFINER clauses. If you still encounter errors, check that your MySQL user has `SUPER` or `PROXY` privileges, or verify the dump file isn't corrupted.

## Requirements

- Shopware 6.6+ & 6.7+
- PHP 8.1+
- SSH access to remote servers
- `mysqldump` on the remote server
- `rsync` for file transfer
- `gzip` / `gunzip` (unless using `--no-gzip`)

## Author

Sidworks — https://www.sidworks.nl/
