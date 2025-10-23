#!/usr/bin/env php
<?php
/**
 * Parse Kahlan verbose output and create comprehensive test inventory
 * 
 * Usage: cat /tmp/kahlan_full_output.txt | php scripts/parse-test-results.php
 */

$input = file_get_contents('php://stdin');
$lines = explode("\n", $input);

$tests = [];
$currentFile = '';
$currentDescribe = '';
$currentTest = '';
$inFailure = false;
$failureDetails = [];

foreach ($lines as $line) {
    // Match file path
    if (preg_match('/^\.spec\/(.+\.php)$/', $line, $matches)) {
        $currentFile = $matches[1];
        continue;
    }
    
    // Match describe blocks
    if (preg_match('/^\s{2}describe\s+"(.+)"/', $line, $matches)) {
        $currentDescribe = $matches[1];
        continue;
    }
    
    // Match nested describe blocks
    if (preg_match('/^\s{4}describe\s+"(.+)"/', $line, $matches)) {
        $currentDescribe .= ' > ' . $matches[1];
        continue;
    }
    
    // Match passing tests with timing
    if (preg_match('/^\s+✓\s+it\s+(.+?)\s+\((\d+\.\d+)s\)/', $line, $matches)) {
        $tests[] = [
            'file' => $currentFile,
            'suite' => $currentDescribe,
            'test' => $matches[1],
            'status' => 'PASS',
            'time' => (float)$matches[2],
            'details' => ''
        ];
        continue;
    }
    
    // Match failing tests
    if (preg_match('/^\s+✖\s+it\s+(.+)/', $line, $matches)) {
        $currentTest = $matches[1];
        $inFailure = true;
        $failureDetails = [];
        continue;
    }
    
    // Collect failure details
    if ($inFailure) {
        if (preg_match('/^\s{6}(.+)/', $line, $matches)) {
            $failureDetails[] = trim($matches[1]);
        } elseif (preg_match('/^\s+✓|^\s+✖|^describe/', $line)) {
            // End of failure details
            $tests[] = [
                'file' => $currentFile,
                'suite' => $currentDescribe,
                'test' => $currentTest,
                'status' => 'FAIL',
                'time' => 0.0,
                'details' => implode(' ', array_slice($failureDetails, 0, 3))
            ];
            $inFailure = false;
            $failureDetails = [];
        }
    }
}

// Sort by time (slowest first)
usort($tests, function($a, $b) {
    return $b['time'] <=> $a['time'];
});

// Output as CSV
echo "File,Suite,Test,Status,Time(s),Details\n";
foreach ($tests as $test) {
    echo sprintf(
        '"%s","%s","%s","%s",%0.3f,"%s"' . "\n",
        $test['file'],
        $test['suite'],
        $test['test'],
        $test['status'],
        $test['time'],
        str_replace('"', '""', $test['details'])
    );
}

// Summary statistics
$totalTests = count($tests);
$passing = count(array_filter($tests, fn($t) => $t['status'] === 'PASS'));
$failing = count(array_filter($tests, fn($t) => $t['status'] === 'FAIL'));
$totalTime = array_sum(array_column($tests, 'time'));

fprintf(STDERR, "\n=== SUMMARY ===\n");
fprintf(STDERR, "Total Tests: %d\n", $totalTests);
fprintf(STDERR, "Passing: %d (%.1f%%)\n", $passing, ($passing/$totalTests)*100);
fprintf(STDERR, "Failing: %d (%.1f%%)\n", $failing, ($failing/$totalTests)*100);
fprintf(STDERR, "Total Time: %.2f seconds\n", $totalTime);
fprintf(STDERR, "Avg Time/Test: %.3f seconds\n", $totalTime/$totalTests);
fprintf(STDERR, "\n=== TOP 20 SLOWEST TESTS ===\n");
foreach (array_slice($tests, 0, 20) as $i => $test) {
    fprintf(STDERR, "%2d. [%.2fs] %s > %s\n", 
        $i+1, 
        $test['time'], 
        basename($test['file'], '.php'),
        substr($test['test'], 0, 60)
    );
}

