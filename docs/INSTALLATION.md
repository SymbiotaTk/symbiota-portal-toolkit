# Symbiota Portal Toolkit - Installation Guide

## Overview

This guide covers the installation and initial setup of the Symbiota Portal Toolkit on a fresh Symbiota installation.

## Prerequisites

- PHP 8.1 or higher
- Composer
- MySQL/MariaDB database
- Web server (Apache/Nginx) with PHP-FPM
- PHP extensions: `zip`, `pdo`, `pdo_mysql`, `pdo_sqlite`, `mbstring`

## Installation Steps

### 1. Clone Repository

```bash
cd /var/www/html/portal
git clone https://github.com/SymbiotaTk/symbiota-portal-toolkit.git tk
cd tk
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Generate Configuration File

```bash
# For production environment
php index.php --example-config-php > config.php

# For development environment (includes testing parameters)
php index.php --example-config-php --dev > config.php
```

### 4. Edit Configuration

Edit `config.php` and customize the following settings:

```ini
[app]
name = "Symbiota Portal Toolkit"
version = "2.0.2"
repository_url = "https://github.com/SymbiotaTk/symbiota-portal-toolkit"
portal_navigation_text = "Return to portal"
portal_url = "{SYMBCLIENTURL}"

[mod.backup]
output_dir = "{SYMBTEMPDIRROOT}/downloads"
registry_file = "{SYMBTEMPDIRROOT}/data/backup.json"
site_salt = "CHANGE_THIS_TO_RANDOM_STRING"  # IMPORTANT: Generate a secure random string

[mod.images]
registry_file = "{SYMBTEMPDIRROOT}/data/images.json"
eav_cache_db = "{SYMBTEMPDIRROOT}/data/images_cache.db"
hybrid_index_db = "{SYMBTEMPDIRROOT}/data/images_hybrid_index.db"
source_db = "{SYMBTEMPDIRROOT}/data/source.db"
search_mode = "auto"

[mod.upload]
output_dir = "{SYMBTEMPDIRROOT}/uploads"
registry_file = "{SYMBTEMPDIRROOT}/data/upload.json"
```

**Important Configuration Notes:**

- `{SYMBTEMPDIRROOT}` is automatically detected from your Symbiota installation's `symbini.php` file
- `{SYMBCLIENTURL}` is automatically calculated as the relative path from web root to Symbiota
- **Security**: Change `site_salt` to a long random string (minimum 16 characters, recommended 32+)

### 5. Create Required Directories

The toolkit requires several directories with proper permissions for the web server user (typically `www-data`).

**Note:** As of version 2.0.2, the toolkit automatically creates required directories when you run status commands or perform operations. However, you may need to set proper ownership/permissions manually.

#### Option A: Automatic Creation (Recommended)

Simply run the status commands to trigger automatic directory creation:

```bash
# This will create backup directories automatically
php index.php backup status

# This will create upload directories automatically
php index.php upload status

# This will create images data directory automatically
php index.php images status
```

Then set proper ownership:

```bash
# Determine your SYMBTEMPDIRROOT (usually /var/www/temp/PORTALNAME)
TEMPDIR="/var/www/temp/portal"  # Replace 'portal' with your portal name

# Set ownership to web server user
sudo chown -R www-data:www-data $TEMPDIR
```

#### Option B: Manual Setup

If you prefer to create all directories upfront:

```bash
# Determine your SYMBTEMPDIRROOT (usually /var/www/temp/PORTALNAME)
# Replace 'portal' with your portal name
TEMPDIR="/var/www/temp/portal"

# Create directory structure
sudo mkdir -p $TEMPDIR/data
sudo mkdir -p $TEMPDIR/downloads/working
sudo mkdir -p $TEMPDIR/downloads/storage
sudo mkdir -p $TEMPDIR/uploads

# Set ownership to web server user
sudo chown -R www-data:www-data $TEMPDIR

# Set permissions
sudo chmod -R 755 $TEMPDIR
sudo chmod -R 775 $TEMPDIR/data
sudo chmod -R 775 $TEMPDIR/downloads
sudo chmod -R 775 $TEMPDIR/uploads
```

**Directory Structure:**
```
/var/www/temp/PORTALNAME/
├── data/                    # Registry files and SQLite databases
│   ├── backup.json
│   ├── upload.json
│   ├── images.json
│   ├── source.db
│   ├── images_cache.db
│   └── images_hybrid_index.db
├── downloads/               # Backup module
│   ├── working/            # Temporary working directory
│   └── storage/            # Backup storage directory
└── uploads/                 # Upload module
    └── {uid}/              # User-specific upload directories (created automatically)
        └── {session}/      # Session directories (created automatically)
            └── chunks/     # Chunk upload directories (created automatically)
```

### 6. Verify Installation

Check that all modules are properly configured:

```bash
# Check backup module
php index.php backup status

# Check upload module
php index.php upload status

# Check images module
php index.php images status
```

**Expected Output:**

**Backup Status:**
```
Backup Module Configuration:
========================================

Configuration:
  Backup Directory: /var/www/temp/portal/downloads
  Registry File: /var/www/temp/portal/data/backup.json
  Site Salt: Configured (32 chars)
  ...

System Status:
  ...
  Working Dir Available: YES  ← Should be YES
  Storage Dir Available: YES  ← Should be YES
```

**Upload Status:**
```
Upload Module Status
==================================================

Paths:
  output_dir_exists   : YES  ← Should be YES
  registry_file_exists: YES  ← Should be YES (created automatically on first use)
```

**Images Status:**
```
Images Module Status
==================================================

Cache Status:
  No cache database found  ← Expected on fresh install
```

### 7. Set Up Images Search (Optional)

The images module requires building a search index. Choose the appropriate method based on your collection size:

#### For Small to Medium Collections (< 500K records)

Build a full EAV cache:

```bash
# Step 1: Extract source data from MySQL
php index.php images cache-eav --get-source

# Step 2: Build EAV cache (may take several hours for large datasets)
php index.php images cache-eav --build
```

#### For Large Collections (≥ 500K records)

Build a lightweight hybrid index:

```bash
# Step 1: Extract source data from MySQL (same as above)
php index.php images cache --get-source

# Step 2: Build hybrid autocomplete index (much faster than EAV)
php index.php images cache --build
```

#### Test Search

```bash
# Search by scientific name (binomial/species name)
php index.php images search --query="taxon:amanita muscaria"

# Search by genus only
php index.php images search --query="genus:amanita"

# Search by collection
php index.php images search --query="collection:mich"
```

## Troubleshooting

### Issue: "Working Dir Available: NO" or "Storage Dir Available: NO"

**Cause:** Directories don't exist or web server doesn't have write permissions.

**Solution:**
```bash
# Check directory ownership
ls -la /var/www/temp/portal/downloads/

# If owned by root, change to www-data
sudo chown -R www-data:www-data /var/www/temp/portal/downloads/

# Ensure directories exist
sudo mkdir -p /var/www/temp/portal/downloads/working
sudo mkdir -p /var/www/temp/portal/downloads/storage
sudo chown -R www-data:www-data /var/www/temp/portal/downloads/
```

### Issue: "output_dir_exists: NO"

**Cause:** Upload directory doesn't exist or web server doesn't have write permissions.

**Solution:**
```bash
# Create upload directory
sudo mkdir -p /var/www/temp/portal/uploads
sudo chown -R www-data:www-data /var/www/temp/portal/uploads
sudo chmod 775 /var/www/temp/portal/uploads
```

### Issue: Permission Denied Errors

**Cause:** Web server user (www-data) doesn't have write access to directories.

**Solution:**
```bash
# Set correct ownership for all toolkit directories
sudo chown -R www-data:www-data /var/www/temp/portal/
sudo chmod -R 775 /var/www/temp/portal/
```

### Issue: "Site salt not configured"

**Cause:** Default site_salt value in config.php hasn't been changed.

**Solution:**
```bash
# Generate a secure random salt
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# Copy the output and update config.php:
# site_salt = "YOUR_GENERATED_SALT_HERE"
```

## Security Considerations

1. **Site Salt**: Always change the default `site_salt` in production. This is used for encrypting backups.

2. **Directory Permissions**:
   - Data directories should be owned by `www-data:www-data`
   - Permissions should be `755` for directories, `644` for files
   - Upload/working directories can be `775` for group write access

3. **File Locations**:
   - All data directories should be outside the web root
   - Default location `/var/www/temp/` is outside `/var/www/html/`

4. **Registry Files**:
   - Registry files contain user IDs and file metadata
   - Ensure they're not accessible via HTTP

## Next Steps

- Configure backup schedules
- Set up automated cache refresh for images module
- Configure user permissions for upload access
- Review security settings in config.php

## Support

For issues or questions:
- GitHub Issues: https://github.com/symbiotatk/symbiota-portal-toolkit/issues
- Email: anders2@illinois.edu
