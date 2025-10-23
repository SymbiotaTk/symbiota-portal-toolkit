#!/bin/bash
# ============================================================================
# Complete EAV Index Build Script
# ============================================================================
# This script performs the complete EAV index build process:
# 1. Export MySQL tables to TSV
# 2. Escape quotes in TSV files
# 3. Build EAV index in SQLite
# ============================================================================

set -e  # Exit on error

# Configuration
DATA_DIR="/var/www/temp/bioc/data"
DB_PATH="$DATA_DIR/images_cache.db"
TEMPLATE_DIR="/var/www/html/portal/tk/templates/sql/images/eav"

echo "============================================================================"
echo "EAV Index Build - Complete Workflow"
echo "============================================================================"
echo ""

# ============================================================================
# Step 1: Export MySQL Tables to TSV
# ============================================================================
echo "Step 1: Exporting MySQL tables to TSV..."
echo "----------------------------------------"

# Create data directory if it doesn't exist
mkdir -p "$DATA_DIR"

# Export using MySQL client
mysql -h symbiota-db -u symbiota -psymbiota symbiota <<EOF
SELECT 
    mediaID,
    occid,
    url,
    originalUrl,
    thumbnailUrl
FROM media
WHERE mediaID IS NOT NULL
ORDER BY mediaID
INTO OUTFILE '/var/lib/mysql-files/media.tsv'
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';

SELECT 
    collid,
    institutionCode,
    collectionCode,
    collectionName
FROM omcollections
WHERE collid IS NOT NULL
ORDER BY collid
INTO OUTFILE '/var/lib/mysql-files/collections.tsv'
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';

SELECT 
    tid,
    sciName
FROM taxa
WHERE tid IS NOT NULL
ORDER BY tid
INTO OUTFILE '/var/lib/mysql-files/taxa.tsv'
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';

SELECT 
    tid,
    parenttid,
    family
FROM taxstatus
WHERE tid IS NOT NULL
ORDER BY tid
INTO OUTFILE '/var/lib/mysql-files/taxstatus.tsv'
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';

SELECT 
    occid,
    collid,
    catalogNumber,
    otherCatalogNumbers,
    family,
    genus,
    scientificName,
    sciname,
    tidInterpreted,
    eventDate,
    continent,
    country,
    stateProvince,
    county,
    municipality,
    locality,
    decimalLatitude,
    decimalLongitude,
    dateLastModified
FROM omoccurrences
WHERE occid IS NOT NULL
ORDER BY occid
INTO OUTFILE '/var/lib/mysql-files/occurrences.tsv'
FIELDS TERMINATED BY '\t'
LINES TERMINATED BY '\n';
EOF

# Move files to data directory
mv /var/lib/mysql-files/*.tsv "$DATA_DIR/"

echo "✓ MySQL export complete"
echo ""

# ============================================================================
# Step 2: Escape Quotes in TSV Files
# ============================================================================
echo "Step 2: Escaping quotes in TSV files..."
echo "----------------------------------------"

cd "$DATA_DIR"

for file in *.tsv; do
    if [ -f "$file" ]; then
        echo "  Processing $file..."
        sed 's/"/\\"/g' "$file" > "${file}.tmp"
        mv "${file}.tmp" "$file"
    fi
done

echo "✓ Quote escaping complete"
echo ""

# ============================================================================
# Step 3: Build EAV Index
# ============================================================================
echo "Step 3: Building EAV index in SQLite..."
echo "----------------------------------------"

# Remove old database if exists
if [ -f "$DB_PATH" ]; then
    echo "  Removing old database..."
    rm -f "$DB_PATH"
fi

# Create new database and run setup
echo "  Phase 1: Setting up metadata and importing tables..."
sqlite3 "$DB_PATH" < "$TEMPLATE_DIR/setup_metadata.sql"

# Generate UNPIVOT SQL
echo "  Phase 2: Generating UNPIVOT SQL..."
cd /var/www/html/portal/tk
php index.php images generate-eav-sql

# Execute UNPIVOT SQL
echo "  Phase 3: Executing UNPIVOT SQL (this may take a while)..."
sqlite3 "$DB_PATH" < "$DATA_DIR/eav_inserts.sql"

# Finalize EAV
echo "  Phase 4: Finalizing EAV tables and indexes..."
sqlite3 "$DB_PATH" < "$TEMPLATE_DIR/finalize_eav.sql"

echo "✓ EAV index build complete"
echo ""

# ============================================================================
# Step 4: Show Statistics
# ============================================================================
echo "Step 4: Database statistics"
echo "----------------------------------------"

sqlite3 "$DB_PATH" <<EOF
SELECT 'Entities' as TableName, COUNT(*) as RowCount FROM Entities
UNION ALL
SELECT 'Attributes', COUNT(*) FROM Attributes
UNION ALL
SELECT 'ValuesText', COUNT(*) FROM ValuesText
UNION ALL
SELECT 'EAV', COUNT(*) FROM EAV;
EOF

echo ""

# Show database size
DB_SIZE=$(du -h "$DB_PATH" | cut -f1)
echo "Database size: $DB_SIZE"
echo ""

# ============================================================================
# Complete
# ============================================================================
echo "============================================================================"
echo "EAV Index Build Complete!"
echo "============================================================================"
echo "Database: $DB_PATH"
echo "Ready for queries!"
echo ""

