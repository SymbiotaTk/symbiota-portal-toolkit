<?php
error_reporting(E_ALL & ~E_DEPRECATED);

const SEARCH_OFFSET = 2;
const SEARCH_DEPTH = 2;

function buildPosArr($pos) {
    $arr = [];
    foreach ($pos as $path) {
        for ($i=SEARCH_OFFSET;$i<(SEARCH_OFFSET+SEARCH_DEPTH);$i++) {
            array_push($arr, sprintf('%s/%s', dirname(__DIR__, $i), $path));
        }
    }

    return $arr;
}

function detectSymbiotaClassPath() {
    // Use the new configuration state system
    $config = \Symbiota\Helpers\Core\Configuration::getInstance();
    if ($config) {
        $classPath = $config->getSymbiotaClassPath();
        if ($classPath) {
            return $classPath;
        }
    }

    // Fall back to auto-detection for legacy compatibility
    $testingEnv = buildPosArr(['dev/git-code/Symbiota/classes']);
    $productionEnv = buildPosArr(['classes']);

    $possiblePaths = array_merge($testingEnv, $productionEnv);

    foreach ($possiblePaths as $path) {
        if (is_dir($path) && file_exists($path . '/DwcArchiverCore.php')) {
            return realpath($path);
        }
    }

    throw new Exception('Could not auto-detect Symbiota class path. Please specify --symbiota-class-path');
}

function detectDbConnectionPath() {
    // Check for explicit testing configuration first
    $config = \Symbiota\Helpers\Core\Configuration::getInstance();
    if ($config) {
        $testingDbConnection = $config->getTestingDbConnectionPath();
        if ($testingDbConnection) {
            return $testingDbConnection;
        }
    }

    // Fall back to auto-detection
    $testingEnv = buildPosArr(['dev/dbconnection.php']);
    $productionEnv = buildPosArr(['config/dbconnection.php']);

    $possiblePaths = array_merge($testingEnv, $productionEnv);

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return realpath($path);
        }
    }

    throw new Exception('Could not auto-detect database connection file. Please specify --symbiota-dbconnection');
}

function detectSymbiniPath($symbiotaClassPath, $symbiotaDbConnection) {
    // Check for explicit testing configuration first
    $config = \Symbiota\Helpers\Core\Configuration::getInstance();
    if ($config) {
        $testingSymbini = $config->getTestingSymbiniPath();
        if ($testingSymbini) {
            return $testingSymbini;
        }
    }

    // Fall back to auto-detection
    $dbDir = dirname($symbiotaDbConnection);
    $possiblePaths = buildPosArr(['symbini.php', 'config/symbini.php', 'dev/symbini.php']);

    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return realpath($path);
        }
    }

    // symbini.php is optional
    return null;
}

function setSymbDwcArchiverCoreLib($wrapper, $classpath, $dbconn, $options) {
    // Set up global variables that Symbiota expects
    global $SERVER_ROOT, $TEMP_DIR_ROOT, $CLIENT_ROOT, $CHARSET, $DEFAULT_TITLE, $ADMIN_EMAIL, $LANG_TAG, $DEFAULT_LANG, $AVAILABLE_LANGS;

    $SERVER_ROOT = dirname($classpath);
    $TEMP_DIR_ROOT = $options['output_dir'] ?? sys_get_temp_dir();
    $CLIENT_ROOT = '/symbiota';
    $CHARSET = 'UTF-8';
    $DEFAULT_TITLE = 'Symbiota Collection Backup';
    $ADMIN_EMAIL = 'admin@example.com';

    // Set language variables if not already defined
    if (!isset($LANG_TAG)) {
        $LANG_TAG = 'en';
    }
    if (!isset($DEFAULT_LANG)) {
        $DEFAULT_LANG = 'en';
    }
    if (!isset($AVAILABLE_LANGS)) {
        $AVAILABLE_LANGS = ['en'];
    }

    // Set up $_SERVER variables that some Symbiota classes expect
    if (!isset($_SERVER['SERVER_NAME'])) $_SERVER['SERVER_NAME'] = 'localhost';
    if (!isset($_SERVER['SERVER_PORT'])) $_SERVER['SERVER_PORT'] = '80';
    if (!isset($_SERVER['HTTPS'])) $_SERVER['HTTPS'] = '';

    // Load required Symbiota classes
    $classDwcArchiverCore = $classpath . '/DwcArchiverCore.php';

    if(is_file($classDwcArchiverCore)){
        require_once $classpath . '/Manager.php';
        require_once $classpath . '/DwcArchiverCore.php';
        require_once $wrapper;

        // Only output status messages in CLI mode to avoid header conflicts
        if (php_sapi_name() === 'cli') {
            echo "✅ Symbiota environment initialized\n";
            echo "   Class path: $classpath\n";
            echo "   DB connection: $dbconn\n";
            echo "   Server root: $SERVER_ROOT\n";
        }
    }
}

// Initialize Symbiota environment
function initializeSymbiotaEnvironment($options = []) {
    // Set up essential global variables that Symbiota classes expect
    global $LANG_TAG, $DEFAULT_LANG, $AVAILABLE_LANGS, $USER_RIGHTS, $PARAMS_ARR, $SYMB_UID, $IS_ADMIN, $USERNAME, $USER_DISPLAY_NAME;

    // Set language variables if not already defined
    if (!isset($LANG_TAG)) {
        $LANG_TAG = 'en';
    }
    if (!isset($DEFAULT_LANG)) {
        $DEFAULT_LANG = 'en';
    }
    if (!isset($AVAILABLE_LANGS)) {
        $AVAILABLE_LANGS = ['en'];
    }

    // Set user-related variables as defaults if not already set by symbini.php
    if (!isset($USER_RIGHTS)) {
        $USER_RIGHTS = [];
    }
    if (!isset($PARAMS_ARR)) {
        $PARAMS_ARR = [];
    }
    if (!isset($SYMB_UID)) {
        $SYMB_UID = 0;
    }
    if (!isset($IS_ADMIN)) {
        $IS_ADMIN = 0;
    }
    if (!isset($USERNAME)) {
        $USERNAME = '';
    }
    if (!isset($USER_DISPLAY_NAME)) {
        $USER_DISPLAY_NAME = '';
    }

    // Auto-detect paths with explicit testing configuration support
    $symbiotaClassPath = $options['symbiota_class_path'] ?? detectSymbiotaClassPath();
    $symbiotaDbConnection = $options['symbiota_dbconnection'] ?? detectDbConnectionPath();
    $symbiotaSymbini = $options['symbiota_symbini'] ?? detectSymbiniPath($symbiotaClassPath, $symbiotaDbConnection);
    $symbDwcArchiverCoreWrapper = $options['symb_dwcarchivercore_wrapper'] ?? false;

    // Include database connection file (defines MySQLiConnectionFactory)
    require_once $symbiotaDbConnection;

    // Include symbini.php to get proper Symbiota environment
    // This will set all the global variables including $SYMB_UID from the session
    if ($symbiotaSymbini && file_exists($symbiotaSymbini)) {
        // Temporarily suppress warnings about session_start if session already active
        $originalErrorReporting = error_reporting();
        if (session_status() === PHP_SESSION_ACTIVE) {
            error_reporting($originalErrorReporting & ~E_NOTICE & ~E_WARNING);
        }

        require_once $symbiotaSymbini;

        // Restore error reporting
        error_reporting($originalErrorReporting);
    }

    if (($symbDwcArchiverCoreWrapper) && (is_file($symbDwcArchiverCoreWrapper))) {
        setSymbDwcArchiverCoreLib($symbDwcArchiverCoreWrapper, $symbiotaClassPath, $symbiotaDbConnection, $options);
    }
}
