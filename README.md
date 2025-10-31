# Symbiota Portal Toolkit

**Version**: 2.0.2
**License**: NCSA Open Source License

A companion toolkit for Symbiota portals providing enhanced functionality for GenBank exports, taxonomy reports, file uploads, encrypted backups, and high-performance image search.

## Key Features

- **Read-Only Database Access**: All interactions with the Symbiota database are read-only for security
- **Dual Interface**: Works via both HTTP (web) and CLI (command-line)
- **Modular Architecture**: Enable/disable components as needed
- **High Performance**: Optimized for large datasets (millions of records)
- **Secure by Default**: Authentication required for sensitive operations

---

## Quick Start

### Installation

```bash
# Install dependencies (production - excludes development tools)
composer install --no-dev

# OR for development (includes Kahlan testing framework)
composer install

# Generate configuration file
php index.php --example-config-php > config.php

# Edit config.php to customize settings
# Test configuration
php index.php --test-config
```

> **Note**: The application requires Composer's autoloader (`vendor/autoload.php`) for PSR-4 class loading, but has no external runtime dependencies. Use `--no-dev` for production to exclude development tools like Kahlan.

### Requirements

- PHP 8.1 or higher
- MySQL 5.7+ or MariaDB 10.3+
- SQLite 3.x (for caching)
- Composer
- **PHP Extensions**:
  - `zip` - Required for backup operations (encrypted archive creation)

#### Checking PHP Extensions

To verify the `zip` extension is installed:

```bash
# Check if zip extension is loaded
php -m | grep zip

# Or check backup module status (shows all requirements)
php index.php backup status
```

#### Installing php-zip Extension

If the `zip` extension is not installed:

**Ubuntu/Debian:**
```bash
sudo apt-get update
sudo apt-get install php-zip
sudo systemctl restart apache2  # or php-fpm
```

**CentOS/RHEL:**
```bash
sudo yum install php-zip
sudo systemctl restart httpd  # or php-fpm
```

**macOS (Homebrew):**
```bash
# Usually included with PHP installation
# If missing, reinstall PHP:
brew reinstall php
```

**Docker:**
```dockerfile
# Add to your Dockerfile
RUN docker-php-ext-install zip
```

---

## Components Overview

### Main Dashboard

**CLI Commands**:
```bash
php index.php --help              # Show all available commands
php index.php status              # Show system status
php index.php --example-config-php # Generate config template
php index.php --test-config       # Test configuration
```

---

### GenBank Export

Export occurrence records in GenBank submission format.

**CLI Commands**:
```bash
php index.php genbank --help      # Show GenBank help
php index.php genbank status      # Check GenBank status
```

**Web Access**: `http://yoursite.com/portal/tk/?/genbank`

---

### Taxonomy Report

Generate taxonomy reports for collections.

**CLI Commands**:
```bash
php index.php taxonomy-report --help   # Show help
php index.php taxonomy-report status   # Check status
```

**Web Access**: `http://yoursite.com/portal/tk/?/taxonomy-report`

---

### Upload Module

Chunked file upload with parallel processing support.

**Requires**: Authentication & configuration setup

**Web Features**:
- Drag-and-drop file upload
- Parallel chunk uploads
- Progress tracking
- Large file support (up to 1GB+)

**CLI Commands**:
```bash
php index.php upload --help           # Show help
php index.php upload status           # Check status
php index.php upload add-user         # Add authorized user
php index.php upload remove-user      # Remove user
php index.php upload list-user-files  # List user's files
php index.php upload users            # List all users
```

**Configuration** (config.php):
```ini
[mod.upload]
output_dir = "{SYMBTEMPDIRROOT}/uploads"
chunk_size_kb = 1024
max_file_size_mb = 1024
parallel_uploads = 5
parallel_chunk_uploads = true
```

---

### Backup Module

Encrypted collection backups with user passphrase protection.

**Requires**: Authentication & configuration setup

**Web Features**:
- User registration with passphrase
- Encrypted backup creation
- Backup download
- Retention management

**Public API**:
```bash
# Collection status (public)
GET /portal/tk/?/backup/<collid>

# List backups (public)
GET /portal/tk/?/backup/<collid>/list

# Download backup (public)
GET /portal/tk/?/backup/<collid>/download

# Create encrypted backup (authentication required to set passphrase & public access to trigger create)
POST /portal/tk/?/backup/<collid>
```

**CLI Commands**:
```bash
php index.php backup --help                                         # Show help
php index.php backup status                                         # Check status
php index.php backup collections                                    # List collections
php index.php backup users <collid>                                 # List users for collection
php index.php backup user-collections <uid>                         # List user's collections
php index.php backup register <collid> <uid> --passphrase=<secret>  # Register user
php index.php backup create-encrypted <collid>                      # Create backup
php index.php backup list <collid>                                  # List backups
php index.php backup cleanup <collid>                               # Remove old backups
php index.php backup remove-user <collid>                           # Remove user
php index.php backup reset-passphrase <collid>                      # Reset passphrase
php index.php backup verify-pw <collid>                             # Verify passphrase
php index.php backup symbdwc <collid>                               # Export SymbDwC format
php index.php backup --dry-run                                      # Test without changes
```

**Crontab Example** (daily backup via API):
```cron
# Daily backup at 2 AM via API
0 2 * * * curl -X POST http://yoursite.com/portal/tk/?/backup/53
```

**Configuration** (config.php):
```ini
[mod.backup]
output_dir = "{SYMBTEMPDIRROOT}/downloads"
retention_threshold = 7
backup_threshold = 1410
site_salt = "CHANGE_THIS_TO_RANDOM_STRING"
http_post = true
```

**Security Note**: Change `site_salt` to a unique random string for production! This is used to store the hashed passphrase.

---

### Images Module

High-performance image search with hybrid caching architecture.

**Requires**: Configuration setup (no authentication needed for search)

**Web Features**:
- Fast autocomplete search
- Multi-field queries (e.g., `taxon:lactarius AND institutionCode:eiu`)
- Field aliases (e.g., `taxon:` searches family, genus, sciname)
- Random image browsing
- Responsive image gallery

**CLI Commands**:
```bash
php index.php images --help                    # Show help
php index.php images status                    # Check status

# Cache management (Flat model - RECOMMENDED)
php index.php images cache --get-source        # Extract source data from MySQL
php index.php images cache --build             # Build flat index (fast, lightweight)
php index.php images cache --info              # Show cache statistics

# Alternative: EAV or Hybrid model (for small datasets only)
php index.php images cache --build-eav         # Build full EAV cache (slower, larger)

# Maintenance
php index.php images cache-refresh             # Refresh cache from MySQL
php index.php images cache-cleanup --delete    # Remove all cache files

# Search
php index.php images search --query="taxon:lactarius"
php index.php images search --query="institutionCode:eiu" --queryAnd="taxon:lactarius"
php index.php images load --limit=10           # Load random images
```

**Crontab Example** (daily cache refresh):
```cron
# Daily cache refresh at 3 AM (Hybrid model - RECOMMENDED)
0 3 * * * cd /var/www/html/portal/tk && \
  php index.php images cache --get-source --limit=0 && \
  php index.php images cache --build
```

**Configuration** (config.php):
```ini
[mod.images]
# Flat model (RECOMMENDED for all dataset sizes)
flat_index_db = "{SYMBTEMPDIRROOT}/data/images_flat_index.db"
source_db = "{SYMBTEMPDIRROOT}/data/source.db"

# EAV or Hybrid model (legacy, only for very small datasets)
eav_cache_db = "{SYMBTEMPDIRROOT}/data/images_cache.db"
hybrid_index_db = "{SYMBTEMPDIRROOT}/data/images_hybrid_index.db"

# General settings
images_per_request = 30
images_cache_hours = 168
search_mode = "auto"  # flat (recommended), hybrid, eav (legacy), or false
```

**Search Modes**:
- `flat` - **RECOMMENDED**: Hybrid flat index + source.db (optimal for all dataset sizes)
- `hybrid` - Hybrid index + source.db (optimal for all dataset sizes)
- `eav` - Legacy EAV cache (only for very small datasets <100K images)
- `false` - Disable search

---

## Maintenance Mode

Display a maintenance banner on both portal and toolkit pages when system maintenance is in progress.

### Configuration

Edit `config.php`:

```ini
[app.maintenance]
enabled = true
message = "System maintenance in progress. [Contact support](mailto:admin@example.com)"
style = "warning"  ; Options: info, warning, danger, success
```

### Portal Integration

Add this line to `/portal/includes/header.php` immediately after the opening `<body>` tag:

```php
 <?php
     $TK_MAINTENANCE = $SERVER_ROOT . '/tk/static/maintenance_banner_include.php';
     if (is_file($TK_MAINTENANCE)) {
         include_once($TK_MAINTENANCE);
         if (function_exists('tk_render_maintenance_banner')) {
             tk_render_maintenance_banner();
         }
     }
 ?>
```

### Toolkit Integration

The banner is automatically displayed on all toolkit pages when enabled.

### Endpoint

**URL**: `/portal/tk/?/maintenance`
**Method**: GET
**Returns**: HTML banner (or empty string if disabled)

---

## Configuration Management

### Generate Configuration Template

```bash
# Production config (clean, no dev/test parameters)
php index.php --example-config-php > config.php

# Development config (includes test examples)
php index.php --example-config-php --dev > config-dev.php
```

### Environment Variables

The toolkit uses placeholder variables in `config.php` that are automatically replaced with actual paths at runtime. These variables integrate with your Symbiota portal installation.

#### Available Variables

- **`{SYMBDIR}`** - Full path to Symbiota installation directory (auto-detected)
- **`{WEBROOT}`** - Full path to web root directory (auto-detected)
- **`{SYMBCLIENTURL}`** - Calculated relative path from web root to Symbiota client (SYMBDIR - WEBROOT)
- **`{SYMBTEMPDIRROOT}`** - Symbiota TEMP_DIR_ROOT from symbini.php (auto-extracted)

#### Example Values

For a typical installation:
```ini
; Auto-detected values (no manual configuration needed):
SYMBDIR = "/var/www/html/portal"
WEBROOT = "/var/www/html"
SYMBCLIENTURL = "/portal"
SYMBTEMPDIRROOT = "/var/www/temp/myco"
```

#### Usage in config.php

These variables can be used anywhere in your configuration:

```ini
[app]
portal_url = "{SYMBCLIENTURL}"

[mod.backup]
output_dir = "{SYMBTEMPDIRROOT}/downloads"
registry_file = "{SYMBTEMPDIRROOT}/data/backup.json"

[mod.images]
eav_cache_db = "{SYMBTEMPDIRROOT}/data/images_cache.db"
source_db = "{SYMBTEMPDIRROOT}/data/source.db"

[mod.upload]
output_dir = "{SYMBTEMPDIRROOT}/uploads"
registry_file = "{SYMBTEMPDIRROOT}/data/upload.json"
```

#### How Auto-Detection Works

1. **SYMBDIR**: Detected from the parent directory of the `tk` folder
2. **WEBROOT**: Detected from `$_SERVER['DOCUMENT_ROOT']`
3. **SYMBCLIENTURL**: Calculated as the relative path from WEBROOT to SYMBDIR
4. **SYMBTEMPDIRROOT**: Extracted from `../symbini.php` configuration file

> **Note**: All path detection happens automatically. You don't need to manually set these values unless you have a non-standard installation.

### Security Settings

```ini
[app.debug]
enabled = false  # Enable debug logging

[performance]
memory_limit = "512M"      # PHP memory limit
max_execution_time = 0     # Unlimited for CLI

[mod.images]
allowed_refresh_ips = []   # IP whitelist for cache refresh (empty = allow all)
```

---

## Installation Steps

### 1. Install Dependencies

```bash
cd /path/to/symbiota/portal/tk
composer install
```

### 2. Generate Configuration

```bash
php index.php --example-config-php > config.php
```

### 3. Edit Configuration

Edit `config.php` and customize:
- `site_salt` for backup module (required for production)
- Module-specific paths and settings
- Performance settings (memory_limit, etc.)

### 4. Test Configuration

```bash
php index.php --test-config
```

### 5. Set Up Modules

**For Images Module**:
```bash
# Extract source data
php index.php images cache --get-source

# Build cache
php index.php images cache --build

# Test search
php index.php images search --query="taxon:lactarius"
```

**For Backup Module**:
```bash
# Register user for collection
php index.php backup register 53 433

# Create test backup
php index.php backup create-encrypted 53 --dry-run
```

### 6. Configure Web Access (Optional)

Add to Apache/Nginx configuration to enable web access:

```apache
# Apache example
Alias /portal/tk /var/www/html/portal/tk
<Directory /var/www/html/portal/tk>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

---

## Documentation

- **User Manual**: `docs/dist/USER_MANUAL.md`
- **Changelog**: `docs/dist/CHANGELOG.md`
- **Development**: `scripts/README.md`
- **Testing**: `spec/README.md`

---

## License

This project is licensed under the **University of Illinois/NCSA Open Source License**.

See the [LICENSE](LICENSE) file for the full license text.

### What This Means

The NCSA license is a permissive open source license that allows you to:

- ✅ Use the software for any purpose (commercial or non-commercial)
- ✅ Modify the source code
- ✅ Distribute copies of the software
- ✅ Distribute modified versions

**Requirements:**
- Include the copyright notice and license text in redistributions
- Don't use the authors' names to endorse derived products without permission

---

## Authors & Contact

**Primary Author**: Philip J Anders
**Email**: <anders2@illinois.edu>
**Project**: Symbiota Portal Toolkit
**Repository**: https://github.com/SymbiotaTk/symbiota-portal-toolkit
**Copyright**: 2018-2025

### Development History

This toolkit evolved from earlier projects:
- [genbankgen](https://github.com/SymbiotaTk/genbankgen) (2018-2023) - GenBank submission tool plugin
- [symbtk](https://github.com/SymbiotaTk/symbtk) (2022-2023) - Symbiota toolkit framework

Recent development (2024-2025) includes significant contributions from **Augment AI**, which assisted in:
- Architectural redesign and modularization
- Implementation of EAV and hybrid search systems
- Enhanced backup and upload modules
- Comprehensive testing framework
- Documentation and code quality improvements

### Contributing

Contributions are welcome! For issues, questions, or contributions:

1. **Bug Reports**: Submit detailed bug reports with steps to reproduce
2. **Feature Requests**: Describe the feature and use case
3. **Pull Requests**: Follow the existing code style and include tests
4. **Questions**: Contact the development team via email

### Acknowledgments

This toolkit is designed to work with the [Symbiota](https://symbiota.org) biodiversity data management platform.

---

## Citation

If you use this toolkit in your research or project, please cite:

```
Philip J Anders (2018-2025). Symbiota Portal Toolkit v2.0.2.
https://github.com/SymbiotaTk/symbiota-portal-toolkit
```

### Related Projects

- [genbankgen](https://github.com/SymbiotaTk/genbankgen) - GenBank submission tool (archived)
- [symbtk](https://github.com/SymbiotaTk/symbtk) - Original toolkit framework
