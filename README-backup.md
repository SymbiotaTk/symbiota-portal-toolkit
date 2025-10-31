# Symbiota Portal Toolkit

**Version**: 2.0.2
**License**: NCSA Open Source License

A companion toolkit for Symbiota portals providing enhanced functionality for GenBank exports, taxonomy reports, file uploads, encrypted backups, and high-performance image search.


## Backup Module (tutorial)

This tutorial walks through the complete workflow for setting up and using encrypted collection backups.

### TL;DR - Most Common Operations

**Command Line:**
```bash
# List collections to find collid
php index.php backup collections

# List collections for a specific user
php index.php backup user-collections 42

# Register user with passphrase (first time setup)
php index.php backup register 53 42 --passphrase="my_secure_password"

# Test command without making changes (dry run)
php index.php backup register 53 42 --passphrase="test" --dry-run

# Verify passphrase
php index.php backup verify-pw 53

# Create encrypted backup
php index.php backup create-encrypted 53

# List backups
php index.php backup list 53

# Cleanup old backups (beyond retention threshold)
php index.php backup cleanup 53

# Remove user registration
php index.php backup remove-user 53
```

**Important: Using sudo for CLI commands**
```bash
# If you need to run as root (may cause permission issues)
sudo php index.php backup collections

# Better: Run as web server user to avoid permission conflicts
sudo -u www-data php index.php backup collections

# If files were created by root, fix permissions for web server
sudo chown -R www-data:www-data /path/to/downloads/
sudo chown www-data:www-data /path/to/backup.json
```

**Recommendation:** Use web dashboard for initial setup to avoid permission issues, then use CLI for automation.

**HTTP Access:**
```bash
# Download latest backup
curl -O http://yoursite.com/portal/tk/?/backup/53/download

# Create backup (requires http_post = true in config.php)
curl -X POST http://yoursite.com/portal/tk/?/backup/53

# List backups
curl http://yoursite.com/portal/tk/?/backup/53/list
```

**Automated Backups (Crontab or Windows PowerShell):**
```bash
# Daily backup at 2 AM (can run from ANY computer if http_post = true)
0 2 * * * curl -X POST http://yoursite.com/portal/tk/?/backup/53

# Daily backup + download at 3 AM
0 3 * * * curl -X POST http://yoursite.com/portal/tk/?/backup/53 && sleep 60 && curl -O http://yoursite.com/portal/tk/?/backup/53/download
```

**Common Responses:**
- Backup in progress: "Backup already in progress, please wait"
- Too soon for new backup: "Backup threshold not met, next backup allowed in X hours"
- Success: "Backup created successfully"

### Quick Start Recommendations

**For initial setup (recommended workflow):**
1. ✅ Use the **web dashboard** to register users
   - This ensures correct file permissions (`www-data:www-data`)
   - Avoids permission conflicts between CLI and web access
   - User-friendly interface for non-technical users
   - Provides verify passphrase and remove user functions

2. ✅ Use **CLI commands** for automation and server management
   - List collections: `php index.php backup collections`
   - View users: `php index.php backup users <collid>`
   - View user's collections: `php index.php backup user-collections <uid>`
   - Cleanup old backups: `php index.php backup cleanup <collid>`  # (optional) This is automated with each backup create operation
   - Test commands safely: Add `--dry-run` to any command

3. ✅ Use **HTTP API** for automated backups (cron jobs)
   - Requires `http_post = true` in config.php
   - Example: `curl -X POST http://yoursite.com/portal/tk/?/backup/53`
   - Can be disabled (`http_post = false`) for server-only management
   - **Important:** If HTTP POST is enabled, cron jobs can run from ANY computer (not just the hosting server)
   - Can be scheduled via crontab (Linux/Mac) or PowerShell scheduled tasks (Windows)

**Permission best practices:**
- Initialize via web dashboard to avoid permission issues
- If using CLI as root, fix permissions: `sudo chown -R www-data:www-data /path/to/files`
- Or run CLI as web server user: `sudo -u www-data php index.php backup ...`

**Testing best practices:**
- Always use `--dry-run` first when testing new commands or configurations
- Example: `php index.php backup register 53 42 --passphrase="test" --dry-run`

### Prerequisites

Before starting, ensure:
1. The backup module is configured in `config.php` (see Configuration section below)
2. You have changed the `site_salt` to a unique random string
3. You know the collection ID (collid) you want to backup
4. You know the user ID (uid) who will manage the backups

**Important: Permissions and Web Server Credentials**

Web servers typically run under specific credentials (e.g., `www-data:www-data`). This affects how you run backup commands:

- **Running as root/sudo**: If you run CLI commands as root (using `sudo`), files and directories will be created with root ownership
- **Permission conflicts**: If `backup.json` is created by root, the web server may not be able to write to it
- **Best practice**: Initialize backups via the web dashboard first, which creates files with correct permissions (`www-data:www-data`)
- **After web initialization**: Command line actions (as root) should not affect web functions, but new files may need permission adjustments

**Permission troubleshooting:**
```bash
# If status reports directories are not writable, check ownership
ls -la /path/to/downloads/

# Fix permissions if needed (adjust www-data to your web server user)
sudo chown -R www-data:www-data /path/to/downloads/
sudo chmod -R 755 /path/to/downloads/

# Fix backup.json permissions if created by root
sudo chown www-data:www-data /path/to/backup.json
sudo chmod 644 /path/to/backup.json
```

### View the module status

Check if the backup module is properly configured and operational:

```bash
php index.php backup status
```

**Expected output:**
```
Backup Module Status
====================
Configuration: OK
Output directory: /path/to/downloads
Working directory: /path/to/working
Retention threshold: 7 days
Backup threshold: 1410 minutes
HTTP POST enabled: Yes
```

**If directories are not available or not writable:**
- This is usually a permissions issue (see Prerequisites section above)
- The web server user (e.g., `www-data`) needs write access to these directories
- If running as root/sudo, you may need to adjust ownership after directory creation

**About HTTP POST enabled:**
- `Yes` - Backups can be triggered via HTTP POST (useful for cron jobs via curl)
- `No` - Backups can only be created via CLI or web dashboard (more secure, server-only management)
- Set `http_post = false` in config.php if you want to disable HTTP POST access

If you see any errors, check your `config.php` configuration.

### Find collection IDs

Before registering a collection, you need to know its collection ID (collid):

```bash
# List all collections
php index.php backup collections
```

**Expected output:**
```
Collections
===========
ID   Code    Name
53   COLO    Colorado Herbarium
54   DBG     Denver Botanic Gardens
55   RMBL    Rocky Mountain Biological Laboratory
```

You can also view collection users to see which users have collection manager roles:

```bash
# List users for a specific collection
php index.php backup users <collid>
```

**Example:**
```bash
php index.php backup users 53
```

**Expected output:**
```
Users for collection 53 (COLO)
==============================
UID  Username        Role
42   jsmith          CollectionManager
67   mjones          CollectionEditor
```

**Note:** This only shows users with collection manager roles. SuperAdmin accounts have access to everything and can be assigned to any collection backup, but they are not listed here.

**List collections for a specific user:**

```bash
# List all collections a user has access to
php index.php backup user-collections <uid>
```

**Example:**
```bash
php index.php backup user-collections 42
```

**Expected output:**
```
Collections for user 42 (jsmith)
================================
ID   Code    Name                              Role
53   COLO    Colorado Herbarium                CollectionManager
54   DBG     Denver Botanic Gardens            CollectionEditor
```

### Register a collection with a user

Register a user to manage backups for a specific collection. This creates an encrypted passphrase that will be used to encrypt all backups.

**Via CLI:**
```bash
php index.php backup register <collid> <uid> --passphrase="YourSecurePassphrase123"
```

**Example:**
```bash
# Register user ID 42 for collection ID 53
php index.php backup register 53 42 --passphrase="MySecurePassword123!"
```

**Expected output:**
```
✓ User 42 registered for collection 53
✓ Passphrase encrypted and stored
```

**Via Web Dashboard:**

The web dashboard provides the same functionality:
1. Navigate to the backup module in your browser
2. Select the collection from the dropdown
3. Enter your user credentials
4. Set your passphrase
5. Click "Register"

**Important notes:**
- The passphrase is hashed using the `site_salt` and stored securely
- Users must remember their passphrase - it cannot be recovered if lost
- Only one user can be registered per collection at a time
- The passphrase will be required to decrypt backups
- **Recommended:** Use the web dashboard for initial registration to ensure correct file permissions

### Verify the passphrase

After registration, verify that the passphrase is correct:

**Via CLI:**
```bash
php index.php backup verify-pw <collid>
```

**Example:**
```bash
php index.php backup verify-pw 53
```

You will be prompted to enter the passphrase:
```
Enter passphrase for collection 53: [type your passphrase]
✓ Passphrase verified successfully
```

If the passphrase is incorrect:
```
✗ Passphrase verification failed
```

**Via Web Dashboard:**
1. Navigate to the backup module
2. Select your collection
3. Enter your passphrase
4. Click "Verify Passphrase"
5. Immediate visual feedback (success or failure)

### Show collection backup status

View the backup status for a specific collection:

```bash
php index.php backup <collid>
```

**Example:**
```bash
php index.php backup 53
```

**Expected output:**
```
Collection 53 Backup Status
===========================
Registered user: 42
Last backup: 2025-10-30 14:23:15
Backup count: 3
Next backup due: 2025-10-31 14:23:15
```

You can also view this via HTTP:
```bash
curl http://yoursite.com/portal/tk/?/backup/53
```

### Create an encrypted backup

Create a new encrypted backup for a collection:

**Via CLI:**
```bash
php index.php backup create-encrypted <collid>
```

**Example:**
```bash
php index.php backup create-encrypted 53
```

**Expected output:**
```
Creating encrypted backup for collection 53...
✓ Exporting data (1,234 records)
✓ Encrypting with user passphrase
✓ Backup created: backup_53_20251030_142315.enc
✓ File size: 2.4 MB
```

**Via HTTP API:**
```bash
curl -X POST http://yoursite.com/portal/tk/?/backup/53
```

**Via crontab (automated daily backups):**

If `http_post = true` in config.php, you can schedule backups from ANY computer (not just the hosting server):

```cron
# Linux/Mac crontab - Daily backup at 2 AM
0 2 * * * curl -X POST http://yoursite.com/portal/tk/?/backup/53

# Daily backup at 2 AM + download at 2:05 AM
0 2 * * * curl -X POST http://yoursite.com/portal/tk/?/backup/53
5 2 * * * curl -O http://yoursite.com/portal/tk/?/backup/53/download
```

**Windows PowerShell scheduled task:**
```powershell
# Create scheduled task to run daily at 2 AM
$action = New-ScheduledTaskAction -Execute "powershell.exe" -Argument "-Command `"Invoke-WebRequest -Uri 'http://yoursite.com/portal/tk/?/backup/53' -Method POST`""
$trigger = New-ScheduledTaskTrigger -Daily -At 2am
Register-ScheduledTask -Action $action -Trigger $trigger -TaskName "SymbiotaBackup" -Description "Daily Symbiota collection backup"
```

**Backup request responses:**
- **Success:** "Backup created successfully"
- **Backup in progress:** "Backup already in progress, please wait" (another backup is currently running)
- **Too soon:** "Backup threshold not met, next backup allowed in X hours" (backup_threshold not elapsed since last backup)

### List backup files

View all backup files for a collection:

**Via CLI:**
```bash
php index.php backup list <collid>
```

**Example:**
```bash
php index.php backup list 53
```

**Expected output:**
```
Backups for collection 53
=========================
backup_53_20251030_142315.enc  2.4 MB  2025-10-30 14:23:15
backup_53_20251029_020000.enc  2.3 MB  2025-10-29 02:00:00
backup_53_20251028_020000.enc  2.3 MB  2025-10-28 02:00:00

Total: 3 backups, 7.0 MB
```

**Via HTTP API:**
```bash
curl http://yoursite.com/portal/tk/?/backup/53/list
```

### Download a backup file

Download the most recent backup:

**Via HTTP:**
```bash
curl -O http://yoursite.com/portal/tk/?/backup/53/download
```

This downloads the most recent encrypted backup file.

**Via web browser:**
Navigate to: `http://yoursite.com/portal/tk/?/backup/53/download`

The file will be downloaded with a name like `backup_53_20251030_142315.enc`

### Decrypt a backup file

To decrypt a backup file, you'll need the passphrase that was used during registration:

```bash
# Decrypt using OpenSSL (the encryption method used by the module)
openssl enc -d -aes-256-cbc -pbkdf2 -in backup_53_20251030_142315.enc -out backup_53_20251030_142315.tar.gz
```

You will be prompted for the passphrase:
```
enter aes-256-cbc decryption password: [type your passphrase]
```

After decryption, extract the tar.gz file:
```bash
tar -xzf backup_53_20251030_142315.tar.gz
```

### Manage old backups

**Remove old backups (beyond retention threshold):**

Remove backups older than the retention threshold (default: 7 days):

```bash
php index.php backup cleanup <collid>
```

**Example:**
```bash
php index.php backup cleanup 53
```

**Expected output:**
```
Cleaning up old backups for collection 53...
✓ Removed: backup_53_20251020_020000.enc (10 days old)
✓ Removed: backup_53_20251019_020000.enc (11 days old)
✓ Kept: 3 recent backups
```

**Remove ALL backups:**

To remove all backup files for a collection:

```bash
php index.php backup cleanup <collid> --remove-all
```

**Example:**
```bash
php index.php backup cleanup 53 --remove-all
```

**Expected output:**
```
Removing ALL backups for collection 53...
✓ Removed: backup_53_20251030_142315.enc
✓ Removed: backup_53_20251029_020000.enc
✓ Removed: backup_53_20251028_020000.enc
✓ All backups removed (3 files)
```

**Important:** The `--remove-all` flag removes all backup files but does NOT reset the backup delay timer. If you want to allow immediate backup creation after removing all files, you must manually edit the `backup.json` file and set the last backup timestamp to `null`:

```bash
# Edit backup.json manually
nano /path/to/backup.json

# Find the entry for your collection and set lastBackup to null:
{
  "53": {
    "lastBackup": null,
    "userId": 42
  }
}
```

### HTTP access and Web Dashboard

The backup module provides both a web dashboard and a public HTTP API for automation.

#### Web Dashboard

The web dashboard provides a user-friendly interface with the equivalent of these CLI operations:
- `php index.php backup collections` - View all collections
- `php index.php backup users <collid>` - View users for a collection
- `php index.php backup user-collections <uid>` - View collections for a specific user
- `php index.php backup list <collid>` - List backups for a collection
- `php index.php backup status` - View module status
- `php index.php backup register <collid> <uid> --passphrase="..."` - Register user with passphrase
- `php index.php backup verify-pw <collid>` - Verify passphrase
- `php index.php backup remove-user <collid>` - Remove user registration

**CLI-only operations:**
- `php index.php backup reset-passphrase <collid>` - Reset passphrase (system administrator only)

**Accessing the web dashboard:**
Navigate to: `http://yoursite.com/portal/tk/?/backup`

**Benefits of using the web dashboard:**
- Files and directories are created with correct web server permissions (`www-data:www-data`)
- No need to manually fix permissions after initialization
- User-friendly interface for non-technical users
- Immediate visual feedback
- Users can verify their own passphrase and remove their registration if needed

#### HTTP API for Automation

The backup module provides a public HTTP API for automation:

**Check collection status:**
```bash
curl http://yoursite.com/portal/tk/?/backup/53
```

**List backups:**
```bash
curl http://yoursite.com/portal/tk/?/backup/53/list
```

**Download latest backup:**
```bash
curl -O http://yoursite.com/portal/tk/?/backup/53/download
```

**Create new backup (requires HTTP POST enabled):**
```bash
curl -X POST http://yoursite.com/portal/tk/?/backup/53
```

**Important notes:**
- Creating backups requires the user to be registered first (via CLI or web dashboard)
- The HTTP POST endpoint requires `http_post = true` in config.php
- If `http_post = false`, backups can only be created via CLI or web dashboard (more secure for server-only management)
- HTTP POST is optional and can be disabled if you want to manage backup execution exclusively on the server
- **If HTTP POST is enabled:** Cron jobs can run from ANY computer (not just the hosting server)
  - Schedule via crontab on Linux/Mac
  - Schedule via PowerShell scheduled tasks on Windows
  - Useful for off-site backup automation

**Backup request responses:**
- **Success:** "Backup created successfully"
- **Backup in progress:** "Backup already in progress, please wait" (another backup is currently running)
- **Too soon:** "Backup threshold not met, next backup allowed in X hours" (backup_threshold not elapsed since last backup)

### Troubleshooting

**Problem: "Configuration error: output_dir not set"**
- **Solution:** Add `output_dir` to `[mod.backup]` section in config.php

**Problem: "Working directory not available" or "Storage directory not writable"**
- **Cause:** Permission issue - web server user (e.g., `www-data`) cannot write to directories
- **Solution:**
  ```bash
  # Check directory ownership
  ls -la /path/to/downloads/

  # Fix permissions (adjust www-data to your web server user)
  sudo chown -R www-data:www-data /path/to/downloads/
  sudo chmod -R 755 /path/to/downloads/
  ```
- **Prevention:** Initialize backups via web dashboard first to create files with correct permissions

**Problem: "backup.json permission denied"**
- **Cause:** File was created by root/sudo and web server cannot write to it
- **Solution:**
  ```bash
  sudo chown www-data:www-data /path/to/backup.json
  sudo chmod 644 /path/to/backup.json
  ```
- **Prevention:** Use web dashboard for initial setup, or run CLI commands as web server user

**Problem: "User not registered for collection"**
- **Solution:** Register the user first using `php index.php backup register <collid> <uid> --passphrase="..."`

**Problem: "Passphrase verification failed" or "Forgot passphrase"**
- **Solution (via web dashboard):**
  1. Navigate to backup module
  2. Select collection
  3. Click "Remove User Registration"
  4. Re-register with new passphrase
- **Solution (via CLI - system administrator only):**
  ```bash
  # Option 1: Reset passphrase (keeps user registration)
  php index.php backup reset-passphrase <collid>
  php index.php backup register <collid> <uid> --passphrase="new_password"

  # Option 2: Remove user and re-register
  php index.php backup remove-user <collid>
  php index.php backup register <collid> <uid> --passphrase="new_password"
  ```
- **Important:** Resetting the passphrase will make all existing encrypted backups unrecoverable unless you remember the old passphrase

**Problem: "Permission denied writing to output directory"**
- **Solution:** Ensure the web server has write permissions to the output_dir (see permission fixes above)

**Problem: "Backup file not found"**
- **Solution:** Create a backup first using `php index.php backup create-encrypted <collid>`

**Problem: "Cannot decrypt backup file"**
- **Solution:** Ensure you're using the correct passphrase that was set during registration

**Problem: "HTTP POST not working"**
- **Solution:** Set `http_post = true` in the `[mod.backup]` section of config.php
- **Note:** HTTP POST is optional and can be disabled for server-only backup management

**Problem: "Backup delay - cannot create backup yet"**
- **Cause:** Backup threshold (default: 1410 minutes = ~23.5 hours) has not elapsed since last backup
- **Solution:** Wait for threshold to elapse, or manually edit `backup.json` to set `lastBackup` to `null`

**Problem: "Removed all backups but still cannot create new backup immediately"**
- **Cause:** `backup cleanup --remove-all` removes files but does not reset the backup delay timer
- **Solution:** Manually edit `backup.json` and set `lastBackup` to `null`:
  ```bash
  nano /path/to/backup.json
  # Set: "lastBackup": null
  ```

**Problem: "Web dashboard works but CLI commands fail with permission errors"**
- **Cause:** CLI commands run as different user (root) than web server (www-data)
- **Solution:** Either:
  - Run CLI commands as web server user: `sudo -u www-data php index.php backup ...`
  - Or fix permissions after running as root (see permission fixes above)
- **Best practice:** Use web dashboard for initialization, CLI for automation

### Testing with --dry-run

The `--dry-run` switch allows you to test commands without making any changes. This is useful for:
- Verifying correct arguments before executing
- Testing permissions without modifying files
- Validating configuration before production use

**Usage:**
```bash
# Test any command by adding --dry-run
php index.php backup <command> <args> --dry-run
```

**Examples:**
```bash
# Test registration without creating files
php index.php backup register 53 42 --passphrase="test" --dry-run

# Test backup creation without actually creating backup
php index.php backup create-encrypted 53 --dry-run

# Test cleanup without deleting files
php index.php backup cleanup 53 --dry-run

# Test cleanup --remove-all without deleting files
php index.php backup cleanup 53 --remove-all --dry-run
```

**Expected output:**
```
[DRY RUN] Would register user 42 for collection 53
[DRY RUN] Would create passphrase hash
[DRY RUN] Would write to backup.json
No changes made (dry run mode)
```

**Best practice:** Always use `--dry-run` first when testing new configurations or unfamiliar commands.

### Remove user registration

If you need to remove a user's registration (e.g., forgot passphrase, user no longer needs access):

**Via Web Dashboard:**
1. Navigate to backup module
2. Select collection
3. Click "Remove User Registration"
4. Confirm removal

**Via CLI (system administrator):**
```bash
php index.php backup remove-user <collid>
```

**Example:**
```bash
php index.php backup remove-user 53
```

**Expected output:**
```
✓ User registration removed for collection 53
✓ Passphrase cleared
```

**Important notes:**
- This does NOT delete existing backup files
- This does NOT reset the backup delay timer
- User can re-register immediately with a new passphrase
- Existing backups can still be decrypted with the old passphrase (if remembered)

### Reset passphrase (CLI only - system administrator)

System administrators can reset a passphrase from the command line:

```bash
php index.php backup reset-passphrase <collid>
```

**Example:**
```bash
php index.php backup reset-passphrase 53
```

**Expected output:**
```
✓ Passphrase reset for collection 53
✓ User must re-register with new passphrase
```

**Important notes:**
- This is a CLI-only operation (not available in web dashboard)
- Requires system administrator access to the server
- After reset, user must re-register with `backup register` command
- Existing encrypted backups will be unrecoverable unless old passphrase is remembered

**If passphrase is forgotten:**
1. **Recommended:** Use web dashboard to remove user registration and re-register
2. **Alternative:** System administrator can use CLI to reset passphrase and re-register user
3. **Important:** Old encrypted backups cannot be decrypted without the original passphrase

### Advanced: Export SymbDwC format

Export collection data in SymbDwC (Symbiota Darwin Core) format without encryption:

```bash
php index.php backup symbdwc <collid>
```

**Example:**
```bash
php index.php backup symbdwc 53
```

This creates an unencrypted tar.gz file with the collection data in SymbDwC format, useful for data migration or analysis.


## Backup Module (help)
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
