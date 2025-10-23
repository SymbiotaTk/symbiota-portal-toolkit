<?php

namespace Symbiota\Helpers\Core;

/**
 * CLI Progress Spinner for long-running operations
 * 
 * Provides visual feedback during database queries, file operations,
 * and other time-consuming tasks in the command line interface.
 */
class ProgressSpinner
{
    private array $frames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private int $currentFrame = 0;
    private bool $isRunning = false;
    private string $message = '';
    private float $startTime;
    private bool $isCli;
    
    public function __construct(?bool $isCli = null)
    {
        $this->isCli = $isCli ?? (php_sapi_name() === 'cli' && $this->isInteractiveTerminal() && !$this->isJsonOutputMode());
    }

    /**
     * Check if we're in an interactive terminal (not piped)
     */
    private function isInteractiveTerminal(): bool
    {
        // Check if output is being piped or redirected
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }

        // Fallback: check if STDOUT is a terminal
        if (function_exists('fstat')) {
            $stat = @fstat(STDOUT);
            return $stat && 0020000 === ($stat['mode'] & 0170000);
        }

        // Final fallback: assume interactive if no detection available
        return true;
    }

    /**
     * Check if we're in JSON output mode (format=json specified)
     */
    private function isJsonOutputMode(): bool
    {
        // Check query parameters for format=json
        if (isset($_GET['format']) && $_GET['format'] === 'json') {
            return true;
        }

        // Check command line arguments for format=json
        global $argv;
        if (isset($argv)) {
            foreach ($argv as $arg) {
                if (strpos($arg, ':json') !== false || strpos($arg, 'format=json') !== false) {
                    return true;
                }
            }
        }

        return false;
    }
    
    /**
     * Start the spinner with a message
     */
    public function start(string $message = 'Processing...'): void
    {
        if (!$this->isCli) {
            return; // No spinner for web interface
        }
        
        $this->message = $message;
        $this->isRunning = true;
        $this->startTime = microtime(true);
        $this->currentFrame = 0;
        
        // Hide cursor
        echo "\033[?25l";
        
        $this->render();
    }
    
    /**
     * Update the spinner message
     */
    public function update(string $message): void
    {
        if (!$this->isCli || !$this->isRunning) {
            return;
        }
        
        $this->message = $message;
        $this->render();
    }
    
    /**
     * Advance the spinner to next frame
     */
    public function tick(): void
    {
        if (!$this->isCli || !$this->isRunning) {
            return;
        }
        
        $this->currentFrame = ($this->currentFrame + 1) % count($this->frames);
        $this->render();
    }
    
    /**
     * Stop the spinner with success message
     */
    public function succeed(string $message = 'Done!'): void
    {
        $this->finish('✓', $message, "\033[32m"); // Green
    }
    
    /**
     * Stop the spinner with error message
     */
    public function fail(string $message = 'Failed!'): void
    {
        $this->finish('✗', $message, "\033[31m"); // Red
    }
    
    /**
     * Stop the spinner with warning message
     */
    public function warn(string $message = 'Warning!'): void
    {
        $this->finish('⚠', $message, "\033[33m"); // Yellow
    }
    
    /**
     * Stop the spinner with info message
     */
    public function info(string $message = 'Info'): void
    {
        $this->finish('ℹ', $message, "\033[36m"); // Cyan
    }
    
    /**
     * Stop the spinner and clear the line
     */
    public function stop(): void
    {
        if (!$this->isCli || !$this->isRunning) {
            return;
        }
        
        $this->isRunning = false;
        
        // Clear line and show cursor
        echo "\r\033[K\033[?25h";
    }
    
    /**
     * Render the current spinner frame
     */
    private function render(): void
    {
        if (!$this->isCli || !$this->isRunning) {
            return;
        }
        
        $elapsed = microtime(true) - $this->startTime;
        $elapsedStr = sprintf('%.1fs', $elapsed);
        
        $frame = $this->frames[$this->currentFrame];
        $output = sprintf("\r%s %s (%s)", $frame, $this->message, $elapsedStr);
        
        echo $output;
    }
    
    /**
     * Finish the spinner with a final message
     */
    private function finish(string $symbol, string $message, string $color): void
    {
        if (!$this->isCli || !$this->isRunning) {
            return;
        }
        
        $this->isRunning = false;
        $elapsed = microtime(true) - $this->startTime;
        $elapsedStr = sprintf('%.1fs', $elapsed);
        
        // Clear line, show final message with color, reset color, show cursor
        echo sprintf("\r\033[K%s%s %s (%s)\033[0m\033[?25h\n", $color, $symbol, $message, $elapsedStr);
    }
    
    /**
     * Create a spinner that automatically ticks during a callback
     */
    public static function during(callable $callback, string $message = 'Processing...', ?bool $isCli = null)
    {
        $spinner = new self($isCli);
        $spinner->start($message);
        
        try {
            // Start background ticker
            $tickerPid = null;
            if ($spinner->isCli && function_exists('pcntl_fork')) {
                $tickerPid = pcntl_fork();
                if ($tickerPid === 0) {
                    // Child process - ticker
                    while ($spinner->isRunning) {
                        usleep(100000); // 100ms
                        $spinner->tick();
                    }
                    exit(0);
                }
            }
            
            // Execute the callback
            $result = $callback($spinner);
            
            // Stop ticker
            if ($tickerPid) {
                posix_kill($tickerPid, SIGTERM);
                pcntl_wait($status);
            }
            
            $spinner->succeed();
            return $result;
            
        } catch (\Exception $e) {
            // Stop ticker
            if ($tickerPid) {
                posix_kill($tickerPid, SIGTERM);
                pcntl_wait($status);
            }
            
            $spinner->fail($e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Simple progress bar for operations with known total
     */
    public function progressBar(int $current, int $total, string $message = ''): void
    {
        if (!$this->isCli) {
            return;
        }
        
        $percentage = min(100.0, ((float)$current / (float)$total) * 100.0);
        $barLength = 30;
        $filledLength = (int)((float)$barLength * $percentage / 100.0);
        
        $bar = str_repeat('█', $filledLength) . str_repeat('░', $barLength - $filledLength);
        $output = sprintf("\r[%s] %3.0f%% (%d/%d) %s", $bar, $percentage, $current, $total, $message);
        
        echo $output;
        
        if ($current >= $total) {
            echo "\n";
        }
    }
}
