<?php
// db_connect.php
// AUTO-DETECTS the folder name so it works whether you call it
// dysonconnect, v4, v3, or anything else.

if (!defined('BASE_URL')) {
    // Detect the subfolder automatically from the script path
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Get just the first segment (e.g. /dysonconnect/ or /v4/dysonconnect/)
    $parts = explode('/', trim($scriptDir, '/'));
    // Walk back up to find where index.php / the root is
    $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
    // Find project root by going up from current script until we find db_connect
    $dir = dirname($scriptFile);
    while ($dir !== $docRoot && $dir !== dirname($dir)) {
        if (file_exists($dir . '/includes/db_connect.php')) {
            break;
        }
        $dir = dirname($dir);
    }
    $baseUrl = str_replace($docRoot, '', $dir);
    $baseUrl = rtrim(str_replace('\\', '/', $baseUrl), '/') . '/';
    if (empty($baseUrl) || $baseUrl === '/') {
        $baseUrl = '/dysonconnect/'; // fallback
    }
    define('BASE_URL', $baseUrl);
}

if (!defined('DB_HOST')) {
    define('DB_HOST',    'localhost');
    define('DB_USER',    'root');
    define('DB_PASS',    '');
    define('DB_NAME',    'dysonconnect');
    define('DB_CHARSET', 'utf8mb4');
}

if (!isset($conn)) {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('DB connection failed: ' . $conn->connect_error);
        die('Could not connect to the database. Check XAMPP is running and dysonconnect_v3.sql has been imported.');
    }
    $conn->set_charset(DB_CHARSET);
}
