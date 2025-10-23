#!/bin/bash
#
# Measure Test Performance
#
# This script measures the execution time of individual test files
# to identify performance bottlenecks.
#

set -e

echo "=== Measuring Test Performance ==="
echo ""

# Array of test files to measure
TEST_FILES=(
    "spec/unit/Models/ImagesModelSpec.php"
    "spec/unit/Models/ImagesModelEavIntegrationSpec.php"
    "spec/unit/Models/ImagesModelEavBatchSpec.php"
    "spec/unit/Models/ImagesModelEavPureSqlSpec.php"
    "spec/integration/Models/ImagesModelEavSearchSpec.php"
)

# Measure each test file
for test_file in "${TEST_FILES[@]}"; do
    if [ -f "$test_file" ]; then
        echo "Testing: $test_file"
        START=$(date +%s)
        vendor/bin/kahlan --spec="$test_file" > /dev/null 2>&1
        END=$(date +%s)
        DURATION=$((END - START))
        echo "  Duration: ${DURATION}s"
        echo ""
    else
        echo "Skipping: $test_file (not found)"
        echo ""
    fi
done

echo "=== Measurement Complete ==="

