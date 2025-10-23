# EAV Test Fixtures

This directory contains a **static SQLite database** (`fixtures.db`) for testing the EAV (Entity-Attribute-Value) indexing system without requiring a MySQL connection.

## Contents

- `fixtures.db` - SQLite database with 100 media records + related data
- `.gitkeep` - Placeholder (removed after fixtures generated)

## Data

- **100 media records** (images)
- **Related omoccurrences** (only those with images)
- **Related omcollections** (only those with images)
- **Related taxa** (only those with images)
- **Related taxstatus** (only for taxa with images)

All foreign key relationships are maintained.

## Generation

Generated using:
```bash
docker exec -it symbiota-web bash -c "cd /var/www/html/portal/tk && vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelGenerateFixturesSpec.php"
```

This test:
1. Connects to MySQL
2. Extracts 100 media records
3. Extracts related records (filtered by foreign keys from `index_config.ini`)
4. Creates `fixtures.db` SQLite database

## Usage in Tests

The test suite `spec/unit/Models/ImagesModelEavFixtureSpec.php` uses these fixtures:

```bash
vendor/bin/kahlan --spec=spec/unit/Models/ImagesModelEavFixtureSpec.php
```

The test workflow:
1. **Load fixtures** → Creates `original.db` from SQL dumps
2. **Export** → Runs `cache-get-source` logic (with filtering)
3. **Build EAV** → Creates EAV index in `images_cache.db`
4. **Query** → Tests search functionality

## Benefits

- ✅ **No MySQL dependency** - Tests run anywhere
- ✅ **Fast** - SQLite is much faster than MySQL for small datasets
- ✅ **Reproducible** - Same fixtures every time
- ✅ **Portable** - Can be committed to version control
- ✅ **Isolated** - Tests don't affect production data

## Regenerating Fixtures (Rare)

⚠️ **Only regenerate if schema changes or test data needs updating**

Fixtures should be regenerated when:
- Schema changes (new columns, tables)
- Need different test data
- Want more/fewer records

**Process:**
1. Create a new temporary script (copy from Git history or recreate)
2. Run the script to generate new fixtures
3. Verify fixtures work with tests
4. Commit new fixtures to Git
5. Delete the temporary script

**Why not keep the generator script?**
- It's not part of the application logic
- It's hardcoded for a specific schema snapshot
- It requires MySQL connection (defeats purpose of fixtures)
- Fixtures are meant to be static, versioned test data

## File Format

Each SQL file contains:
- CREATE TABLE statement
- INSERT statements for all rows

Example:
```sql
-- Table: media
-- Generated: 2024-01-15 10:30:00

CREATE TABLE media (`mediaID` INTEGER, `occid` INTEGER, `url` TEXT, ...);

INSERT INTO media VALUES (1, 101, 'http://...', ...);
INSERT INTO media VALUES (2, 102, 'http://...', ...);
...
```

## Data Relationships

The fixtures maintain referential integrity:

```
media (100 records)
  ↓ (occid)
omoccurrences (~100 records)
  ↓ (collid)        ↓ (tidInterpreted)
omcollections       taxa
  (~10 records)     (~50 records)
                      ↓ (tid)
                    taxstatus
                      (~100 records)
```

All foreign key relationships are valid (no orphaned records).

## Size

Total fixture size: ~500KB - 2MB (depending on text field content)

This is small enough to:
- Commit to Git
- Load quickly in tests
- Run in CI/CD pipelines

## Troubleshooting

### Fixtures not found
```
Error: spec/fixtures/images/eav/media.sql not found
```

**Solution**: Generate fixtures first:
```bash
php bin/generate-eav-fixtures.php
```

### MySQL connection error
```
ERROR: Could not connect to MySQL database
```

**Solution**: Check database configuration in `config/database.php`

### Empty fixtures
```
⚠ No related records found, skipping
```

**Solution**: Ensure the media table has records with valid foreign keys (occid, etc.)

## CI/CD Integration

**Fixtures are committed to Git** - no generation needed in CI/CD.

The test suite simply:
1. Loads fixtures from `spec/fixtures/images/eav/*.sql`
2. Runs tests
3. Cleans up temp files

No MySQL connection required! ✅

## Future Enhancements

Potential improvements:
- [ ] Parameterize number of records (default: 100)
- [ ] Support multiple fixture sets (small, medium, large)
- [ ] Add data validation checks
- [ ] Generate fixtures from synthetic data (no MySQL needed)

