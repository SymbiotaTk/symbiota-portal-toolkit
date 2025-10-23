#!/bin/bash
#
# Test Timing Reporter
#
# This script demonstrates the timing reporter functionality by running
# a small subset of tests and displaying the timing log.
#

set -e

echo "=== Testing Kahlan Timing Reporter ==="
echo ""

# Clean up old log
rm -f /tmp/kahlan_timing.log

# Run a small test with timing enabled
echo "Running ImagesModelGenerateSmallFixturesSpec with timing..."
KAHLAN_TIMING=1 KAHLAN_SLOW_THRESHOLD=0.01 vendor/bin/kahlan \
    --spec=spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php

echo ""
echo "=== Timing Log Output ==="
echo ""

if [ -f /tmp/kahlan_timing.log ]; then
    cat /tmp/kahlan_timing.log
    echo ""
    echo "✅ Timing log created successfully!"
else
    echo "❌ Timing log not found at /tmp/kahlan_timing.log"
    exit 1
fi

echo ""
echo "=== Test Complete ==="

