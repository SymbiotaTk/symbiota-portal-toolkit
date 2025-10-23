<?php
/**
 * Custom Kahlan Reporter for Test Timing
 * 
 * Tracks execution time for each test spec and suite, logging slow tests
 * to help identify performance bottlenecks.
 */

namespace Symbiota\Helpers\Spec;

use Kahlan\Reporter\Terminal;

class TimingReporter extends Terminal
{
    /**
     * Timing data for each spec
     * @var array
     */
    protected $_timings = [];

    /**
     * Current spec start time
     * @var float
     */
    protected $_specStartTime = null;

    /**
     * Suite start times
     * @var array
     */
    protected $_suiteStartTimes = [];

    /**
     * Suite timings
     * @var array
     */
    protected $_suiteTimes = [];

    /**
     * Threshold in seconds for logging slow tests
     * @var float
     */
    protected $_slowThreshold = 0.1;

    /**
     * Log file path
     * @var string
     */
    protected $_logFile = null;

    /**
     * Constructor
     * 
     * @param array $config Configuration options
     */
    public function __construct($config = [])
    {
        parent::__construct($config);
        
        $defaults = [
            'slow_threshold' => 0.1,
            'log_file' => sys_get_temp_dir() . '/kahlan_timing.log'
        ];
        $config += $defaults;
        
        $this->_slowThreshold = $config['slow_threshold'];
        $this->_logFile = $config['log_file'];
        
        // Initialize log file
        file_put_contents($this->_logFile, "# Kahlan Test Timing Log - " . date('Y-m-d H:i:s') . "\n\n");
    }

    /**
     * Callback called before any specs processing
     * 
     * @param array $args The suite arguments
     */
    public function start($args)
    {
        parent::start($args);
        $this->_log("=== Test Suite Started ===\n");
        $this->_log("Total specs: {$args['total']}\n");
        $this->_log("Slow threshold: {$this->_slowThreshold}s\n\n");
    }

    /**
     * Callback called before a suite execution
     * 
     * @param object $log The log object of the suite
     */
    public function suiteStart($log = null)
    {
        parent::suiteStart($log);
        
        $suite = $log->block();
        $suiteName = $suite->message();
        $this->_suiteStartTimes[$suiteName] = microtime(true);
    }

    /**
     * Callback called after a suite execution
     * 
     * @param object $log The log object of the suite
     */
    public function suiteEnd($log = null)
    {
        parent::suiteEnd($log);
        
        $suite = $log->block();
        $suiteName = $suite->message();
        
        if (isset($this->_suiteStartTimes[$suiteName])) {
            $duration = microtime(true) - $this->_suiteStartTimes[$suiteName];
            $this->_suiteTimes[$suiteName] = $duration;
            
            if ($duration > $this->_slowThreshold) {
                $this->_log(sprintf(
                    "SLOW SUITE: %s (%.3fs)\n",
                    $suiteName,
                    $duration
                ));
            }
            
            unset($this->_suiteStartTimes[$suiteName]);
        }
    }

    /**
     * Callback called before a spec execution
     * 
     * @param object $log The log object of the spec
     */
    public function specStart($log = null)
    {
        parent::specStart($log);
        $this->_specStartTime = microtime(true);
    }

    /**
     * Callback called after a spec execution
     * 
     * @param object $log The log object of the spec
     */
    public function specEnd($log = null)
    {
        parent::specEnd($log);
        
        if ($this->_specStartTime !== null) {
            $duration = microtime(true) - $this->_specStartTime;
            
            $spec = $log->block();
            $specName = $spec->message();
            $suite = $spec->suite();
            $suiteName = $suite ? $suite->message() : 'Unknown';
            
            $fullName = $suiteName . ' > ' . $specName;
            
            $this->_timings[] = [
                'suite' => $suiteName,
                'spec' => $specName,
                'full_name' => $fullName,
                'duration' => $duration,
                'status' => $log->type()
            ];
            
            // Log slow tests immediately
            if ($duration > $this->_slowThreshold) {
                $this->_log(sprintf(
                    "SLOW TEST: %s (%.3fs) [%s]\n",
                    $fullName,
                    $duration,
                    $log->type()
                ));
            }
            
            $this->_specStartTime = null;
        }
    }

    /**
     * Callback called at the end of specs processing
     * 
     * @param object $summary The execution summary
     */
    public function end($summary)
    {
        parent::end($summary);
        
        $this->_log("\n=== Test Suite Completed ===\n\n");
        
        // Sort timings by duration (slowest first)
        usort($this->_timings, function($a, $b) {
            return $b['duration'] <=> $a['duration'];
        });
        
        // Log top 20 slowest tests
        $this->_log("=== Top 20 Slowest Tests ===\n");
        $count = min(20, count($this->_timings));
        for ($i = 0; $i < $count; $i++) {
            $timing = $this->_timings[$i];
            $this->_log(sprintf(
                "%2d. [%.3fs] %s [%s]\n",
                $i + 1,
                $timing['duration'],
                $timing['full_name'],
                $timing['status']
            ));
        }
        
        // Sort suite times by duration
        arsort($this->_suiteTimes);
        
        // Log top 10 slowest suites
        $this->_log("\n=== Top 10 Slowest Suites ===\n");
        $count = 0;
        foreach ($this->_suiteTimes as $suite => $duration) {
            if ($count >= 10) break;
            $this->_log(sprintf(
                "%2d. [%.3fs] %s\n",
                $count + 1,
                $duration,
                $suite
            ));
            $count++;
        }
        
        // Summary statistics
        $totalTime = array_sum(array_column($this->_timings, 'duration'));
        $avgTime = count($this->_timings) > 0 ? $totalTime / count($this->_timings) : 0;
        $slowCount = count(array_filter($this->_timings, function($t) {
            return $t['duration'] > $this->_slowThreshold;
        }));
        
        $this->_log("\n=== Timing Summary ===\n");
        $this->_log(sprintf("Total tests: %d\n", count($this->_timings)));
        $this->_log(sprintf("Total time: %.3fs\n", $totalTime));
        $this->_log(sprintf("Average time: %.3fs\n", $avgTime));
        $this->_log(sprintf("Slow tests (>%.3fs): %d (%.1f%%)\n", 
            $this->_slowThreshold,
            $slowCount,
            count($this->_timings) > 0 ? ($slowCount / count($this->_timings) * 100) : 0
        ));
        
        $this->write("\n");
        $this->write("Timing log written to: {$this->_logFile}\n", 'cyan');
    }

    /**
     * Write to log file
     * 
     * @param string $message Message to log
     */
    protected function _log($message)
    {
        file_put_contents($this->_logFile, $message, FILE_APPEND);
    }

    /**
     * Get all timing data
     * 
     * @return array
     */
    public function getTimings()
    {
        return $this->_timings;
    }

    /**
     * Get suite timings
     * 
     * @return array
     */
    public function getSuiteTimes()
    {
        return $this->_suiteTimes;
    }
}

