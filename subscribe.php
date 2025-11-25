<?php
/**
 * rad.typo Mailing List - Constraint-Embracing Email Collection
 * Philosophy: Simple file-based storage, zero dependencies
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get email from POST data
$input = json_decode(file_get_contents('php://input'), true);
$email = isset($input['email']) ? trim($input['email']) : '';

// Fallback to form data if JSON parsing failed
if (empty($email) && isset($_POST['email'])) {
    $email = trim($_POST['email']);
}

// Validate email
if (empty($email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid email']);
    exit;
}

// File paths
$dataDir = '/var/www/html/data';
$subscribersFile = $dataDir . '/subscribers.csv';
$logFile = $dataDir . '/subscribe_log.txt';

// Create data directory if it doesn't exist
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

// Check if email already exists (simple duplicate prevention)
if (file_exists($subscribersFile)) {
    $existingEmails = file($subscribersFile, FILE_IGNORE_NEW_LINES);
    foreach ($existingEmails as $line) {
        $existingEmail = explode(',', $line)[0];
        if (trim($existingEmail, '"') === $email) {
            echo json_encode(['status' => 'success', 'message' => 'Already subscribed']);
            exit;
        }
    }
}

// Prepare subscriber data
$timestamp = date('c'); // ISO 8601 format
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

// CSV line: email,timestamp,ip_address,user_agent
$csvLine = sprintf('"%s","%s","%s","%s"', 
    addslashes($email), 
    $timestamp, 
    $ipAddress, 
    addslashes($userAgent)
) . "\n";

// Append to subscribers file
$writeResult = file_put_contents($subscribersFile, $csvLine, FILE_APPEND | LOCK_EX);

if ($writeResult === false) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Storage failed']);
    exit;
}

// Log the subscription
$logEntry = sprintf("[%s] SUBSCRIBE: %s from %s\n", $timestamp, $email, $ipAddress);
file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

// Success response
echo json_encode([
    'status' => 'success', 
    'message' => 'Subscribed successfully',
    'timestamp' => $timestamp
]);
?>
