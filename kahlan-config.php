<?php
/**
 * Kahlan Configuration for Symbiota Portal Helpers v2.0
 *
 * This configuration sets up testing for the Helpers application.
 */

// Set up autoloader for development source
require_once __DIR__ . '/vendor/autoload.php';

// Load test helpers
require_once __DIR__ . '/spec/helpers/TestUtilities.php';
require_once __DIR__ . '/spec/helpers/TimingReporter.php';

// Configure spec directories (exclude deprecated)
$commandLine = $this->commandLine();
$commandLine->option('spec', 'default', [
    'spec/unit',
    'spec/integration'
]);

// Configure pattern for spec files
$commandLine->option('grep', 'default', '*Spec.php');

// Add timing reporter when --timing flag is used
use Kahlan\Filter\Filters;
use Symbiota\Helpers\Spec\TimingReporter;

Filters::apply($this, 'reporters', function($next) {
    $reporters = $next();

    // Check if --timing flag is set via environment or command line
    $enableTiming = getenv('KAHLAN_TIMING') === '1' ||
                    in_array('--timing', $_SERVER['argv'] ?? []);

    if ($enableTiming) {
        $timingReporter = new TimingReporter([
            'slow_threshold' => (float)(getenv('KAHLAN_SLOW_THRESHOLD') ?: 0.1),
            'log_file' => getTimingLogPath()
        ]);
        $reporters->add('timing', $timingReporter);
    }

    return $reporters;
});

/*
 *     │   ├── ComprehensiveHttpMockingSpec.php
    │   ├── ConfigurationSpec.php
    │   ├── DatabaseManagerSpec.php
    │   ├── DiscoverableModelSpec.php
    │   ├── DiscoveringRouterSpec.php
    │   ├── EnvironmentSpec.php
    │   ├── HttpRequestMockingSpec.php
    │   ├── HttpValidatorSpec.php
    │   ├── ModelDiscoverySpec.php
    │   ├── ModuleTypeSpec.php
    │   ├── OutputHandlerSpec.php
    │   ├── ProgressSpinnerSpec.php
    │   ├── RequestSpec.php
    │   ├── ResponseSpec.php
    │   ├── RoutingDebugSpec.php
    │   ├── SecureFileHandlerSpec.php
    │   ├── TemplateEngineSpec.php
    │   ├── TemplateFormatSpec.php
    │   ├── UriParserOffsetSpec.php
    │   └── UriParserSpec.php
    └── Models
        ├── BackupModelSpec.php
        ├── GenBankModelSpec.php
        ├── ImagesModelSpec.php
        ├── StaticContentModelSpec.php
        ├── TaxonomyReportModelSpec.php
        └── UploadModelSpec.php
 */


// Add custom helper functions for testing
if (!function_exists('mockSymbiotaConnection')) {
    function mockSymbiotaConnection() {
        return new class {
            public function prepare($sql) {
                return new class {
                    public function execute($params = []) {
                        return true;
                    }
                    public function fetchAll() {
                        return [];
                    }
                    public function fetch() {
                        return [];
                    }
                };
            }
            public function query($sql) {
                return new class {
                    public function fetch() {
                        return ['test' => 1];
                    }
                    public function fetchAll() {
                        return [['test' => 1]];
                    }
                };
            }
        };
    }
}
