#!/bin/bash
# Check EAV tokenizer progress without locking the database
# This script monitors file sizes and provides estimates

WORK_DB="/var/www/temp/myco/data/images_cache_work.db"
CACHE_DB="/var/www/temp/myco/data/images_cache.db"

echo "=== EAV Tokenizer Progress Check ==="
echo ""

# Check if databases exist
if [ ! -f "$WORK_DB" ]; then
    echo "❌ Working database not found: $WORK_DB"
    exit 1
fi

# Show file sizes
echo "📊 Database Sizes:"
ls -lh "$WORK_DB"* 2>/dev/null | awk '{print "  " $9 ": " $5}'
echo ""

if [ -f "$CACHE_DB" ]; then
    ls -lh "$CACHE_DB"* 2>/dev/null | awk '{print "  " $9 ": " $5}'
    echo ""
fi

# Show disk usage
echo "💾 Disk Usage:"
df -h /var/www/temp/myco/data/ | tail -1 | awk '{print "  Used: " $3 " / " $2 " (" $5 ")"}'
df -h /var/www/temp/myco/data/ | tail -1 | awk '{print "  Free: " $4}'
echo ""

# Try to query database in read-only mode (may fail if locked)
echo "🔍 Attempting read-only query (may fail if database is locked)..."
sqlite3 -readonly "$WORK_DB" "SELECT COUNT(*) FROM Tokens" 2>/dev/null && echo "  ✓ Tokens table accessible" || echo "  ⚠ Database locked (process is running)"

echo ""
echo "Tip: The progress is shown in the terminal output where you ran the tokenizer step."
echo "     Look for lines like: '2,400,000 / 5,906,251 rows (40.6%)'"

