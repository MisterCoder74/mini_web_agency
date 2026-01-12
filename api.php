<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

session_start();
header('Content-Type: application/json');
// CORS security: Remove wildcard, allow only same origin
header('Access-Control-Allow-Origin: same-origin');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Read JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Data files - use relative paths
$usersFile = './data/users.json';


// Ensure data directory exists
if (!file_exists('./data')) {
    mkdir('./data', 0755, true);
}

// Initialize files if they don't exist
if (!file_exists($usersFile)) {
    file_put_contents($usersFile, json_encode([]));
}


// ============================================================================
// SANITIZATION & VALIDATION HELPERS
// ============================================================================

/**
 * Sanitize user input - trim and escape HTML special characters
 */
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    $input = trim($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    return $input;
}

/**
 * Validate email format
 */
function validateEmail($email) {
    $email = trim($email);
    if (empty($email)) {
        return false;
    }
    // Use filter_var for validation, also check for basic regex pattern
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    // Additional check for common email patterns
    return preg_match('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $email) === 1;
}

/**
 * Validate password strength
 */
function validatePassword($password) {
    if (empty($password)) {
        return false;
    }
    if (strlen($password) < 8) {
        return false;
    }
    return true;
}

/**
 * Validate bot name (max 100 characters)
 */
function validateBotName($name) {
    $name = sanitizeInput($name);
    if (empty(trim($name))) {
        return false;
    }
    if (strlen($name) > 100) {
        return false;
    }
    return $name;
}

/**
 * Validate bot personality (max 5000 characters)
 */
function validateBotPersonality($personality) {
    $personality = sanitizeInput($personality);
    if (empty(trim($personality))) {
        return false;
    }
    if (strlen($personality) > 5000) {
        return false;
    }
    return $personality;
}

/**
 * Validate message content (max 10000 characters)
 */
function validateMessage($message) {
    $message = sanitizeInput($message);
    if (empty(trim($message))) {
        return false;
    }
    if (strlen($message) > 10000) {
        return false;
    }
    return $message;
}

/**
 * Validate conversation history
 */
function validateHistory($history) {
    if (!is_array($history)) {
        return [];
    }

    $validated = [];
    foreach ($history as $entry) {
        if (!is_array($entry) || !isset($entry['role']) || !isset($entry['content'])) {
            continue;
        }

        $role = sanitizeInput($entry['role']);
        $content = sanitizeInput($entry['content']);

        if (!in_array($role, ['user', 'assistant', 'system'], true)) {
            continue;
        }

        if (strlen($content) > 10000) {
            $content = substr($content, 0, 10000);
        }

        $validatedEntry = [
            'role' => $role,
            'content' => $content
        ];

        if (isset($entry['id'])) {
            $id = sanitizeInput($entry['id']);
            if (!empty($id)) {
                $validatedEntry['id'] = substr($id, 0, 128);
            }
        }

        if (isset($entry['timestamp'])) {
            $timestamp = sanitizeInput($entry['timestamp']);
            if (!empty($timestamp)) {
                $validatedEntry['timestamp'] = substr($timestamp, 0, 64);
            }
        }

        $validated[] = $validatedEntry;
    }

    return $validated;
}


// ============================================================================
// FILE LOCKING MECHANISM
// ============================================================================

/**
 * Acquire exclusive lock on file
 */
function acquireLock($filePath) {
    $fp = fopen($filePath, 'c+');
    if (!$fp) {
        return false;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    
    return $fp;
}

/**
 * Release lock and close file handle
 */
function releaseLock($fp) {
if (is_resource($fp)) {
@flock($fp, LOCK_UN);
@fclose($fp);
}
}


/**
 * Execute function with file lock
 */
function withLock($filePath, $callback) {
    $fp = acquireLock($filePath);
    if (!$fp) {
        return ['success' => false, 'message' => 'Impossibile acquisire lock sul file'];
    }
    
    try {
        $result = $callback($fp);
        releaseLock($fp);
        return $result;
    } catch (Exception $e) {
        releaseLock($fp);
        throw $e;
    }
}


// ============================================================================
// USER DATA FUNCTIONS (with file locking)
// ============================================================================

function loadUsers() {
    global $usersFile;
    $data = file_get_contents($usersFile);
    return json_decode($data, true) ?: [];
}

function saveUsers($users) {
    global $usersFile;
    return file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
}

function loadUsersWithLock() {
    global $usersFile;
    return withLock($usersFile, function($fp) {
        global $usersFile;
        $data = file_get_contents($usersFile);
        $users = json_decode($data, true) ?: [];
        return ['success' => true, 'users' => $users, 'fp' => $fp];
    });
}

function saveUsersWithLock($users) {
    global $usersFile;
    return withLock($usersFile, function($fp) use ($users, $usersFile) {
        file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
        return ['success' => true];
    });
}

function findUserById($userId) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['id'] === $userId) {
            return $user;
        }
    }
    return null;
}

function findUserByEmail($email) {
    $users = loadUsers();
    foreach ($users as $user) {
        if ($user['email'] === $email) {
            return $user;
        }
    }
    return null;
}

function updateUser($userData) {
    $result = loadUsersWithLock();
    if (!$result['success']) {
        return false;
    }
    
    $users = $result['users'];
    $fp = $result['fp'];
    
    for ($i = 0; $i < count($users); $i++) {
        if ($users[$i]['id'] === $userData['id']) {
            $users[$i] = $userData;
            saveUsers($users);
            releaseLock($fp);
            return true;
        }
    }
    
    releaseLock($fp);
    return false;
}

function resetUsageIfNeeded(&$user) {
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    
    // Reset giornaliero immagini
    if (!isset($user['usage']['lastReset']) || $user['usage']['lastReset'] !== $today) {
        $user['usage']['images'] = 0;
        $user['usage']['lastReset'] = $today;
    }
    
    // Reset mensile messaggi
    if (!isset($user['usage']['lastMessageReset']) || substr($user['usage']['lastMessageReset'], 0, 7) !== $currentMonth) {
        $user['usage']['messages'] = 0;
        $user['usage']['lastMessageReset'] = $today;
    }
    
    updateUser($user);
}

function getPlanLimits($plan) {
    $limits = [
        'free' => ['messages' => 100, 'images' => 3],
        'basic' => ['messages' => 5000, 'images' => 10],
        'premium' => ['messages' => PHP_INT_MAX, 'images' => PHP_INT_MAX]
    ];
    return $limits[$plan] ?? $limits['free'];
}

function getHistoryLimit($plan) {
    switch ($plan) {
        case 'premium':
            return 100;
        case 'basic':
            return 50;
        default:
            return 20;
    }
}

/**
 * Check if a model is allowed for a given plan
 * @param string $model The model name
 * @param string $plan The user's plan
 * @return bool True if model is allowed for the plan
 */
function isModelAllowedForPlan($model, $plan) {
    // Define allowed models per plan
    $allowedModels = [
        'free' => ['gpt-4.1-nano'],
        'basic' => ['gpt-4o-mini', 'gpt-4.1-nano'],
        'premium' => ['gpt-4o-mini', 'gpt-4.1-nano']
    ];
    
    return in_array($model, $allowedModels[$plan] ?? $allowedModels['free'], true);
}

function canSendMessage($user) {
    $limits = getPlanLimits($user['plan']);
    return $user['plan'] === 'premium' || $user['usage']['messages'] < $limits['messages'];
}

function canGenerateImage(&$user) {
    resetUsageIfNeeded($user);
    $limits = getPlanLimits($user['plan']);
    return $user['plan'] === 'premium' || $user['usage']['images'] < $limits['images'];
}

function generateId() {
    return uniqid('', true);
}

// ============================================================================
// OPENAI API FUNCTIONS (apiKey passed from client)
// ============================================================================

/**
 * Call OpenAI Chat Completions API
 * @param string $message User message
 * @param string $personality Bot personality
 * * @param array $history Full conversation history
 * @param string $model Model to use
 * @param string $apiKey OpenAI API key from client
 */
function callOpenAI($message, $personality = '', $history = [], $model = 'gpt-4o-mini', $apiKey = '') {
    if (empty($apiKey)) {
        return "Errore: API Key OpenAI non configurata. Vai nelle impostazioni per configurarla.";
    }
    
    $messages = [];
    
    // Add system personality if provided
    if (!empty($personality)) {
        $messages[] = [
            'role' => 'system',
            'content' => $personality
        ];
    }
    
    // Add conversation history (validated and sanitized)
    foreach ($history as $entry) {
        $messages[] = [
            'role' => $entry['role'],
            'content' => $entry['content']
        ];
    }
    
    // Add current user message
    $messages[] = [
        'role' => 'user',
        'content' => $message
    ];
    
    $data = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => 5000,
        'temperature' => 0.7
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return "Errore API OpenAI: " . $httpCode;
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        return "Errore OpenAI: " . $result['error']['message'];
    }
    
    return $result['choices'][0]['message']['content'] ?? 'Risposta non disponibile';
}

/**
 * Call DALL-E Image Generation API
 * @param string $prompt Image prompt
 * @param string $apiKey OpenAI API key from client
 */
function callDallE($prompt, $apiKey = '') {
    if (empty($apiKey)) {
        return ['error' => 'API Key OpenAI non configurata'];
    }
    
    $data = [
        'model' => 'dall-e-3',
        'prompt' => $prompt,
        'n' => 1,
        'size' => '1024x1024',
        'quality' => 'standard'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/images/generations');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => 'Errore API DALL-E: ' . $httpCode];
    }
    
    $result = json_decode($response, true);
    
    if (isset($result['error'])) {
        return ['error' => 'Errore DALL-E: ' . $result['error']['message']];
    }
    
    return ['url' => $result['data'][0]['url'] ?? null];
}

function sendEmail($to, $subject, $message) {
$headers = "From: no-reply@vivacitydesign.net\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
return mail($to, $subject, $message, $headers);
}

// ============================================================================
// RATE LIMITING SYSTEM
// ============================================================================

/**
 * Initialize rate limits file
 */
function initializeRateLimits() {
    $file = './data/rate_limits.json';
    if (!file_exists($file)) {
        $initialData = [
            'hourly' => [],
            'daily' => [],
            'login_attempts' => [],
            'last_cleanup' => time()
        ];
        file_put_contents($file, json_encode($initialData, JSON_PRETTY_PRINT));
    }
}

/**
 * Load rate limits data with file locking
 */
function loadRateLimitsWithLock() {
    $file = './data/rate_limits.json';
    $fp = fopen($file, 'c+');
    if (!$fp) {
        return ['success' => false, 'data' => null];
    }
    
    if (!flock($fp, LOCK_SH)) {
        fclose($fp);
        return ['success' => false, 'data' => null];
    }
    
    $content = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    
    $data = json_decode($content, true);
    if (!$data) {
        $data = [
            'hourly' => [],
            'daily' => [],
            'login_attempts' => [],
            'last_cleanup' => time()
        ];
    }
    
    return ['success' => true, 'data' => $data];
}

/**
 * Save rate limits data with file locking
 */
function saveRateLimitsWithLock($data) {
    $file = './data/rate_limits.json';
    $fp = fopen($file, 'w');
    if (!$fp) {
        return false;
    }
    
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    
    $result = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return $result !== false;
}

/**
 * Clean up old rate limit entries (older than 24 hours)
 */
function cleanupRateLimits() {
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return false;
    }
    
    $data = $result['data'];
    $cutoff = time() - (24 * 60 * 60); // 24 hours ago
    
    // Clean hourly limits
    foreach ($data['hourly'] as $key => $userLimits) {
        foreach ($userLimits as $action => $timestamps) {
            $data['hourly'][$key][$action] = array_filter($timestamps, function($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            });
            
            // Remove empty action arrays
            if (empty($data['hourly'][$key][$action])) {
                unset($data['hourly'][$key][$action]);
            }
        }
        
        // Remove empty user entries
        if (empty($data['hourly'][$key])) {
            unset($data['hourly'][$key]);
        }
    }
    
    // Clean daily limits
    foreach ($data['daily'] as $key => $userLimits) {
        foreach ($userLimits as $action => $dateData) {
            foreach ($dateData as $date => $timestamps) {
                $data['daily'][$key][$action][$date] = array_filter($timestamps, function($timestamp) use ($cutoff) {
                    return $timestamp > $cutoff;
                });
                
                // Remove empty date entries
                if (empty($data['daily'][$key][$action][$date])) {
                    unset($data['daily'][$key][$action][$date]);
                }
            }
            
            // Remove empty action arrays
            if (empty($data['daily'][$key][$action])) {
                unset($data['daily'][$key][$action]);
            }
        }
        
        // Remove empty user entries
        if (empty($data['daily'][$key])) {
            unset($data['daily'][$key]);
        }
    }
    
    // Clean login attempts
    foreach ($data['login_attempts'] as $email => $attempts) {
        // Filter out old attempts (older than 24 hours)
        $data['login_attempts'][$email] = array_filter($attempts, function($timestamp) use ($cutoff) {
            return $timestamp > $cutoff;
        });
        
        // Remove email entries with no recent attempts
        if (empty($data['login_attempts'][$email])) {
            unset($data['login_attempts'][$email]);
        }
    }
    
    $data['last_cleanup'] = time();
    return saveRateLimitsWithLock($data);
}

/**
 * Get rate limits configuration
 */
function getRateLimitConfig($action) {
    $configs = [
        'sendMessage' => ['limit' => 50, 'period' => 'hourly'],
        'generateImage' => ['limit' => 10, 'period' => 'daily'],
        'createBot' => ['limit' => 20, 'period' => 'hourly'],
        'register' => ['limit' => 5, 'period' => 'hourly'],
        'login' => ['limit' => 3, 'period' => 'lockout'], // Special case for login
        'forgotPassword' => ['limit' => 5, 'period' => 'hourly'],
        
        // Generic actions that get default 50/hour limit
        'checkAuth' => ['limit' => 50, 'period' => 'hourly'],
        'getBots' => ['limit' => 50, 'period' => 'hourly'],
        'getBot' => ['limit' => 50, 'period' => 'hourly'],
        'deleteBot' => ['limit' => 50, 'period' => 'hourly'],
        'exportConversation' => ['limit' => 50, 'period' => 'hourly'],
        'deleteAccount' => ['limit' => 50, 'period' => 'hourly'],
        'logout' => ['limit' => 50, 'period' => 'hourly'],
        'initiatePayment' => ['limit' => 50, 'period' => 'hourly'],
        'upgradePlan' => ['limit' => 50, 'period' => 'hourly'],
        'verifyOtp' => ['limit' => 50, 'period' => 'hourly'],
        'resetPassword' => ['limit' => 50, 'period' => 'hourly']
    ];
    
    // Default for other actions
    if (!isset($configs[$action])) {
        return ['limit' => 50, 'period' => 'hourly'];
    }
    
    return $configs[$action];
}

/**
 * Check rate limit for user and action
 * Returns: ['exceeded' => bool, 'remaining' => int, 'resetTime' => timestamp]
 */
function checkRateLimit($userId, $action) {
    // Initialize rate limits if needed
    initializeRateLimits();
    
    // Clean up old entries periodically
    $result = loadRateLimitsWithLock();
    if ($result['success']) {
        $data = $result['data'];
        if (time() - ($data['last_cleanup'] ?? 0) > 3600) { // Cleanup every hour
            cleanupRateLimits();
        }
    }
    
    $config = getRateLimitConfig($action);
    
    // Special handling for login (tracks by email, not userId)
    if ($action === 'login') {
        return ['exceeded' => false, 'remaining' => 999, 'resetTime' => time()];
    }
    
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return ['exceeded' => false, 'remaining' => 999, 'resetTime' => time()];
    }
    
    $data = $result['data'];
    $currentTime = time();
    
    if ($config['period'] === 'daily') {
        // Daily limits - track by date
        $today = date('Y-m-d');
        $key = $userId;
        
        if (!isset($data['daily'][$key][$action][$today])) {
            $data['daily'][$key][$action][$today] = [];
        }
        
        $todayRequests = $data['daily'][$key][$action][$today];
        $requestCount = count($todayRequests);
        
        if ($requestCount >= $config['limit']) {
            // Calculate reset time (midnight)
            $resetTime = strtotime('tomorrow');
            return [
                'exceeded' => true,
                'remaining' => 0,
                'resetTime' => $resetTime
            ];
        }
        
        // Calculate remaining requests
        $remaining = $config['limit'] - $requestCount;
        $resetTime = strtotime('tomorrow'); // Daily reset at midnight
        
        return [
            'exceeded' => false,
            'remaining' => $remaining,
            'resetTime' => $resetTime
        ];
        
    } else {
        // Hourly limits - sliding window
        $key = $userId;
        $oneHourAgo = $currentTime - 3600; // 1 hour ago
        
        if (!isset($data['hourly'][$key][$action])) {
            $data['hourly'][$key][$action] = [];
        }
        
        // Count requests in the last hour
        $recentRequests = array_filter($data['hourly'][$key][$action], function($timestamp) use ($oneHourAgo) {
            return $timestamp > $oneHourAgo;
        });
        
        $requestCount = count($recentRequests);
        
        if ($requestCount >= $config['limit']) {
            // Find the oldest request to calculate reset time
            sort($recentRequests);
            $oldestRequest = reset($recentRequests);
            $resetTime = $oldestRequest + 3600; // Reset 1 hour after oldest request
            
            return [
                'exceeded' => true,
                'remaining' => 0,
                'resetTime' => $resetTime
            ];
        }
        
        // Calculate remaining requests
        $remaining = $config['limit'] - $requestCount;
        $resetTime = $currentTime + 3600; // Reset in 1 hour
        
        return [
            'exceeded' => false,
            'remaining' => $remaining,
            'resetTime' => $resetTime
        ];
    }
}

/**
 * Record a request for rate limiting
 */
function recordRequest($userId, $action) {
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return false;
    }
    
    $data = $result['data'];
    $currentTime = time();
    $config = getRateLimitConfig($action);
    
    if ($config['period'] === 'daily') {
        // Daily tracking
        $today = date('Y-m-d');
        $key = $userId;
        
        if (!isset($data['daily'][$key][$action][$today])) {
            $data['daily'][$key][$action][$today] = [];
        }
        
        $data['daily'][$key][$action][$today][] = $currentTime;
        
    } elseif ($config['period'] === 'hourly') {
        // Hourly tracking
        $key = $userId;
        
        if (!isset($data['hourly'][$key][$action])) {
            $data['hourly'][$key][$action] = [];
        }
        
        $data['hourly'][$key][$action][] = $currentTime;
    }
    
    return saveRateLimitsWithLock($data);
}

/**
 * Check login brute force protection
 */
function checkLoginAttempts($email) {
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return ['locked' => false, 'attempts' => 0, 'resetTime' => time()];
    }
    
    $data = $result['data'];
    $currentTime = time();
    $fifteenMinutesAgo = $currentTime - (15 * 60); // 15 minutes ago
    
    if (!isset($data['login_attempts'][$email])) {
        $data['login_attempts'][$email] = [];
    }
    
    // Filter recent attempts (last 15 minutes)
    $recentAttempts = array_filter($data['login_attempts'][$email], function($timestamp) use ($fifteenMinutesAgo) {
        return $timestamp > $fifteenMinutesAgo;
    });
    
    $attemptCount = count($recentAttempts);
    
    // If 3 or more failed attempts in last 15 minutes, lock account
    if ($attemptCount >= 3) {
        // Find the oldest attempt to calculate reset time
        sort($recentAttempts);
        $oldestAttempt = reset($recentAttempts);
        $resetTime = $oldestAttempt + (15 * 60); // 15 minutes lock
        
        return [
            'locked' => true,
            'attempts' => $attemptCount,
            'resetTime' => $resetTime
        ];
    }
    
    return [
        'locked' => false,
        'attempts' => $attemptCount,
        'resetTime' => $currentTime + (15 * 60) // Lock expires in 15 minutes
    ];
}

/**
 * Record failed login attempt
 */
function recordFailedLogin($email) {
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return false;
    }
    
    $data = $result['data'];
    $currentTime = time();
    
    if (!isset($data['login_attempts'][$email])) {
        $data['login_attempts'][$email] = [];
    }
    
    $data['login_attempts'][$email][] = $currentTime;
    
    return saveRateLimitsWithLock($data);
}

/**
 * Reset login attempts on successful login
 */
function resetLoginAttempts($email) {
    $result = loadRateLimitsWithLock();
    if (!$result['success']) {
        return false;
    }
    
    $data = $result['data'];
    
    if (isset($data['login_attempts'][$email])) {
        unset($data['login_attempts'][$email]);
    }
    
    return saveRateLimitsWithLock($data);
}

// ============================================================================
// API ROUTES
// ============================================================================

switch ($action) {
    case 'checkAuth':
        // Check generic rate limit for authentication checks
        if (isset($_SESSION['user_id'])) {
            $rateLimit = checkRateLimit($_SESSION['user_id'], 'checkAuth');
            
            if ($rateLimit['exceeded']) {
                $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
                echo json_encode([
                    'success' => false,
                    'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                    'retryAfter' => $rateLimit['resetTime'] - time()
                ]);
                break;
            }
        }
        
        if (isset($_SESSION['user_id'])) {
            $user = findUserById($_SESSION['user_id']);
            if ($user) {
                resetUsageIfNeeded($user);
                echo json_encode(['success' => true, 'user' => $user]);
                
                // Record successful auth check
                recordRequest($_SESSION['user_id'], 'checkAuth');
            } else {
                echo json_encode(['success' => false]);
            }
        } else {
            echo json_encode(['success' => false]);
        }
        break;

    case 'register':
        // Check rate limit for registration (rate limited by IP/session since no user_id yet)
        $rateLimitKey = $_SERVER['REMOTE_ADDR'] . '_register';
        $rateLimit = checkRateLimit($rateLimitKey, 'register');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $name = sanitizeInput($input['name']);
        $email = sanitizeInput($input['email']);
        $password = $input['password'];

if (empty($name) || empty($email) || empty($password)) {
echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori']);
break;
}

if (!validateEmail($email)) {
echo json_encode(['success' => false, 'message' => 'Formato email non valido']);
break;
}

if (!validatePassword($password)) {
echo json_encode(['success' => false, 'message' => 'Password troppo corta (min. 8 caratteri)']);
break;
}

if (findUserByEmail($email)) {
echo json_encode(['success' => false, 'message' => 'Email già registrata']);
break;
}

// Genera OTP casuale e scadenza
$otp = rand(100000, 999999);
$expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

$newUser = [
'id' => generateId(),
'name' => $name,
'email' => $email,
'password' => $password,
'plan' => 'free',
'usage' => [
    'messages' => 0,
    'images' => 0,
    'lastReset' => date('Y-m-d')
],
'bots' => [],
'settings' => [],
'subscription' => [
    'status' => 'active',
    'plan' => 'free',
    'nextBillingDate' => null,
    'lastPaymentDate' => null,
    'paymentMethod' => 'none'
],
'status' => 'pending',
'otp' => (string)$otp,
'otp_expiry' => $expiry
];

$result = loadUsersWithLock();
if (!$result['success']) {
echo json_encode(['success' => false, 'message' => 'Errore durante la registrazione']);
break;
}

$users = $result['users'];
$fp = $result['fp'];
$users[] = $newUser;
saveUsers($users);
releaseLock($fp);

// Invia OTP via email
$subject = "Verifica la tua email";
$message = "Ciao $name,\n\nIl tuo codice di verifica è: $otp\nScadrà tra 15 minuti.\n";
sendEmail($email, $subject, $message);

echo json_encode(['success' => true, 'message' => 'Codice OTP inviato via email.']);
        
        // Record successful registration attempt
        recordRequest($rateLimitKey, 'register');
        break;
                
                
                
case 'verifyOtp':
$email = sanitizeInput($input['email'] ?? '');
$otp = sanitizeInput($input['otp'] ?? '');

if (empty($email) || empty($otp)) {
echo json_encode(['success' => false, 'message' => 'Email e codice OTP richiesti']);
break;
}

$user = findUserByEmail($email);
if (!$user) {
echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
break;
}

if ($user['status'] === 'active') {
echo json_encode(['success' => true, 'message' => 'Account già attivo']);
break;
}

if ($user['otp'] !== $otp) {
echo json_encode(['success' => false, 'message' => 'Codice OTP errato']);
break;
}

if (strtotime($user['otp_expiry']) < time()) {
echo json_encode(['success' => false, 'message' => 'Codice OTP scaduto']);
break;
}

// Attiva account
$user['status'] = 'active';
unset($user['otp']);
unset($user['otp_expiry']);
updateUser($user);

echo json_encode(['success' => true, 'message' => 'Account attivato con successo!']);
break;

                
case 'forgotPassword':
        // Check rate limit for password reset requests
        $rateLimitKey = $_SERVER['REMOTE_ADDR'] . '_forgotPassword';
        $rateLimit = checkRateLimit($rateLimitKey, 'forgotPassword');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $email = sanitizeInput($input['email'] ?? '');
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email richiesta']);
            break;
        }

$user = findUserByEmail($email);
if (!$user) {
echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
break;
}

$otp = rand(100000, 999999);
$expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
$user['otp'] = (string)$otp;
$user['otp_expiry'] = $expiry;
updateUser($user);

$subject = "Reset password";
$message = "Ciao,\nIl tuo codice di reset è: $otp\nScadrà tra 15 minuti.\n";
sendEmail($email, $subject, $message);

echo json_encode(['success' => true, 'message' => 'Codice di reset inviato via email.']);
        
        // Record successful password reset request
        recordRequest($rateLimitKey, 'forgotPassword');
        break;
                
case 'resetPassword':
$email = sanitizeInput($input['email'] ?? '');
$otp = sanitizeInput($input['otp'] ?? '');
$newPass = $input['password'] ?? '';

if (empty($email) || empty($otp) || empty($newPass)) {
echo json_encode(['success' => false, 'message' => 'Email, OTP e nuova password richiesti']);
break;
}

$user = findUserByEmail($email);
if (!$user) {
echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
break;
}

if ($user['otp'] !== $otp || strtotime($user['otp_expiry']) < time()) {
echo json_encode(['success' => false, 'message' => 'Codice OTP errato o scaduto']);
break;
}

if (!validatePassword($newPass)) {
echo json_encode(['success' => false, 'message' => 'Password troppo corta']);
break;
}

$user['password'] = $newPass;
unset($user['otp']);
unset($user['otp_expiry']);
updateUser($user);

echo json_encode(['success' => true, 'message' => 'Password reimpostata con successo']);
break;                

    case 'login':
        // Check login brute force protection
        $loginCheck = checkLoginAttempts($email);
        
        if ($loginCheck['locked']) {
            echo json_encode([
                'success' => false,
                'message' => 'Troppi tentativi falliti. Riprova tra 15 minuti.',
                'retryAfter' => 900 // 15 minutes in seconds
            ]);
            break;
        }
        
        $email = sanitizeInput($input['email'] ?? '');
        $password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
echo json_encode(['success' => false, 'message' => 'Email e password richiesti']);
break;
}

$user = findUserByEmail($email);

// 1️⃣ Utente non trovato
if (!$user) {
echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
break;
}

// 2️⃣ Controllo stato account
if ($user['status'] !== 'active') {
echo json_encode(['success' => false, 'message' => 'Account non verificato. Controlla la tua email.']);
break;
}

// 3️⃣ Verifica della password con hash
if ($password === $user['password']) {
    // Successful login - reset failed attempts
    resetLoginAttempts($email);
    
    $_SESSION['user_id'] = $user['id'];
    resetUsageIfNeeded($user);

    // Non inviare password al client
    echo json_encode(['success' => true, 'user' => $user]);
} else {
    // Failed login - record attempt
    recordFailedLogin($email);
    echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
}
break;


    case 'logout':
        // Check rate limit for logout (if user is logged in)
        if (isset($_SESSION['user_id'])) {
            $rateLimit = checkRateLimit($_SESSION['user_id'], 'logout');
            
            if ($rateLimit['exceeded']) {
                $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
                echo json_encode([
                    'success' => false,
                    'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                    'retryAfter' => $rateLimit['resetTime'] - time()
                ]);
                break;
            }
        }
        
        session_destroy();
        echo json_encode(['success' => true]);
        
        // Record successful logout if user was logged in
        if (isset($_SESSION['user_id'])) {
            recordRequest($_SESSION['user_id'], 'logout');
        }
        break;

    case 'initiatePayment':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for payment initiation
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'initiatePayment');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $plan = sanitizeInput($input['plan'] ?? '');
        $paymentMethod = sanitizeInput($input['paymentMethod'] ?? '');
        $allowedPlans = ['basic', 'premium'];
        $allowedMethods = ['stripe', 'paypal'];
        
        if (!in_array($plan, $allowedPlans)) {
            echo json_encode(['success' => false, 'message' => 'Piano non valido']);
            break;
        }
        
        if (!in_array($paymentMethod, $allowedMethods)) {
            echo json_encode(['success' => false, 'message' => 'Metodo di pagamento non valido']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        // Define prices
        $prices = [
            'basic' => 9.99,
            'premium' => 19.99
        ];
        
        $price = $prices[$plan];
        
        // Generate payment details based on method
        if ($paymentMethod === 'stripe') {
            // Stripe checkout URL generation
            // TODO: Configure real Stripe keys from environment
            $checkoutUrl = 'https://checkout.stripe.com/c/pay/';
            $checkoutSessionId = generateId();
            
            $stripeUrl = $checkoutUrl . $checkoutSessionId . '?client_reference_id=' . urlencode($user['id'] . '|' . $plan);
            
            // Store pending payment
            $user['subscription'] = [
                'status' => 'pending_payment',
                'plan' => $plan,
                'nextBillingDate' => null,
                'lastPaymentDate' => null,
                'paymentMethod' => 'stripe',
                'pendingSessionId' => $checkoutSessionId
            ];
            updateUser($user);
            
            echo json_encode([
                'success' => true,
                'paymentUrl' => $stripeUrl,
                'sessionId' => $checkoutSessionId
            ]);
            
            // Record successful payment initiation
            recordRequest($_SESSION['user_id'], 'initiatePayment');
            
        } else if ($paymentMethod === 'paypal') {
            // PayPal form generation
            $paypalFormId = generateId();
            
            // Store pending payment
            $user['subscription'] = [
                'status' => 'pending_payment',
                'plan' => $plan,
                'nextBillingDate' => null,
                'lastPaymentDate' => null,
                'paymentMethod' => 'paypal',
                'pendingPaymentId' => $paypalFormId
            ];
            updateUser($user);
            
            echo json_encode([
                'success' => true,
                'paypalForm' => [
                    'business' => 'merchant@example.com',
                    'amount' => $price,
                    'currency' => 'EUR',
                    'itemName' => ucfirst($plan) . ' Plan',
                    'custom' => $user['id'] . '|' . $plan,
                    'returnUrl' => 'paypalCallback.php?status=success&paymentId=' . $paypalFormId,
                    'cancelUrl' => 'index.html'
                ],
                'paymentId' => $paypalFormId
            ]);
            
            // Record successful payment initiation
            recordRequest($_SESSION['user_id'], 'initiatePayment');
        }
        break;

    case 'upgradePlan':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for plan upgrade
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'upgradePlan');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $plan = sanitizeInput($input['plan'] ?? '');
        $allowedPlans = ['basic', 'premium'];
        
        if (!in_array($plan, $allowedPlans)) {
            echo json_encode(['success' => false, 'message' => 'Piano non valido']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $user['plan'] = $plan;
        $user['subscription'] = [
            'status' => 'active',
            'plan' => $plan,
            'nextBillingDate' => date('Y-m-d', strtotime('+1 month')),
            'lastPaymentDate' => date('Y-m-d'),
            'paymentMethod' => 'none'
        ];
        updateUser($user);
        
        echo json_encode(['success' => true, 'message' => 'Piano aggiornato']);
        
        // Record successful plan upgrade
        recordRequest($_SESSION['user_id'], 'upgradePlan');
        break;

    case 'createBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for bot creation
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'createBot');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        // Validate and sanitize bot name
        $name = validateBotName($input['name'] ?? '');
        if ($name === false) {
            echo json_encode(['success' => false, 'message' => 'Nome bot non valido (max 100 caratteri)']);
            break;
        }
        
        // Validate and sanitize bot personality
        $personality = validateBotPersonality($input['personality'] ?? '');
        if ($personality === false) {
            echo json_encode(['success' => false, 'message' => 'Personalità non valida (max 5000 caratteri)']);
            break;
        }

        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }

        // Validate and set model with plan-based restrictions
        $model = sanitizeInput($input['model'] ?? 'gpt-4o-mini');
        $allowedModels = ['gpt-4o-mini', 'gpt-4.1-nano'];
        
        if (!in_array($model, $allowedModels, true)) {
            $model = 'gpt-4o-mini';
        }
        
        // Check if model is allowed for user's plan
        if (!isModelAllowedForPlan($model, $user['plan'])) {
            $allowedForPlan = $user['plan'] === 'free' ? 'gpt-4.1-nano' : 'gpt-4o-mini e gpt-4.1-nano';
            $planDisplay = $user['plan'];
            echo json_encode(['success' => false, 'message' => "Il modello $model non è disponibile per il tuo piano. Piano $planDisplay: $allowedForPlan"]);
            break;
        }
        
        $newBot = [
            'id' => generateId(),
            'name' => $name,
            'personality' => $personality,
            'model' => $model,
            'conversations' => [],
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $user['bots'][] = $newBot;
        updateUser($user);
        
        echo json_encode(['success' => true, 'bot' => $newBot]);
        
        // Record successful bot creation
        recordRequest($_SESSION['user_id'], 'createBot');
        break;

    case 'getBots':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for getting bots
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'getBots');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        echo json_encode(['success' => true, 'bots' => $user['bots'] ?? []]);
        
        // Record successful bot retrieval
        recordRequest($_SESSION['user_id'], 'getBots');
        break;

    case 'getBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for getting bot
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'getBot');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $botId = sanitizeInput($input['botId'] ?? '');
        if (empty($botId)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID richiesto']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $bot = null;
        foreach ($user['bots'] as $userBot) {
            if ($userBot['id'] === $botId) {
                $bot = $userBot;
                break;
            }
        }
        
        if (!$bot) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }
        
        echo json_encode(['success' => true, 'bot' => $bot]);
        
        // Record successful bot retrieval
        recordRequest($_SESSION['user_id'], 'getBot');
        break;

    case 'deleteBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for deleting bot
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'deleteBot');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $botId = sanitizeInput($input['botId'] ?? '');
        if (empty($botId)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID richiesto']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $newBots = [];
        $found = false;
        foreach ($user['bots'] as $bot) {
            if ($bot['id'] !== $botId) {
                $newBots[] = $bot;
            } else {
                $found = true;
            }
        }
        
        if (!$found) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }
        
        $user['bots'] = $newBots;
        updateUser($user);
        
        echo json_encode(['success' => true]);
        
        // Record successful bot deletion
        recordRequest($_SESSION['user_id'], 'deleteBot');
        break;

    case 'sendMessage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for sending messages
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'sendMessage');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }
        
        $botId = sanitizeInput($input['botId'] ?? '');
        $message = validateMessage($input['message'] ?? '');
        $history = validateHistory($input['history'] ?? []);
        $apiKey = sanitizeInput($input['apiKey'] ?? '');
        
        if (empty($botId) || $message === false) {
            echo json_encode(['success' => false, 'message' => 'Bot ID e messaggio richiesti']);
            break;
        }
        
        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'API Key OpenAI richiesta']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }

        resetUsageIfNeeded($user);

        if (!canSendMessage($user)) {
            echo json_encode(['success' => false, 'message' => 'Limite messaggi raggiunto']);
            break;
        }

        // Find bot
        $bot = null;
        $botIndex = -1;
        foreach ($user['bots'] as $index => $userBot) {
            if ($userBot['id'] === $botId) {
                $bot = $userBot;
                $botIndex = $index;
                break;
            }
        }

        if (!$bot) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }

        // Get personality and model
        $personality = $bot['personality'] ?? '';
        $model = $bot['model'] ?? 'gpt-4o-mini';
        $historyLimit = getHistoryLimit($user['plan'] ?? 'free');

        // Build full conversation history from server storage + client history
        $fullHistory = [];
        if (isset($bot['conversations']) && is_array($bot['conversations'])) {
            $fullHistory = $bot['conversations'];
        }

        // Merge with client history (client sends recent messages that might have been missed)
        $existingIds = [];
        foreach ($fullHistory as $entry) {
            if (isset($entry['id'])) {
                $existingIds[] = $entry['id'];
            }
        }

        foreach ($history as $entry) {
            if (!isset($entry['id']) || !in_array($entry['id'], $existingIds, true)) {
                $fullHistory[] = $entry;
            }
        }

        // Send only recent context to OpenAI (full history is still persisted server-side)
        $contextHistory = array_slice($fullHistory, -$historyLimit);

        // Call OpenAI API with apiKey from client
        $response = callOpenAI($message, $personality, $contextHistory, $model, $apiKey);
        
        // Check if it's an error
        if (strpos($response, 'Errore') !== false) {
            echo json_encode(['success' => false, 'message' => $response]);
            break;
        }
        
        // Update usage
        $user['usage']['messages']++;
        updateUser($user);
        
        // Update bot with new conversation - with file locking
        $result = loadUsersWithLock();
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio']);
            break;
        }
        
        $users = $result['users'];
        $fp = $result['fp'];
        
        for ($i = 0; $i < count($users); $i++) {
            if ($users[$i]['id'] === $user['id']) {
                foreach ($users[$i]['bots'] as &$userBot) {
                    if ($userBot['id'] === $botId) {
                        // Add user message to conversations
                        $userBot['conversations'][] = [
                            'id' => generateId(),
                            'role' => 'user',
                            'content' => $message,
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                        // Add assistant response to conversations
                        $userBot['conversations'][] = [
                            'id' => generateId(),
                            'role' => 'assistant',
                            'content' => $response,
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                        break;
                    }
                }
                break;
            }
        }
        
        saveUsers($users);
        releaseLock($fp);
        
        // Get updated bot + updated usage
        $user = findUserById($_SESSION['user_id']);
        $updatedBot = null;
        foreach ($user['bots'] as $userBot) {
            if ($userBot['id'] === $botId) {
                $updatedBot = $userBot;
                break;
            }
        }

        $limits = getPlanLimits($user['plan'] ?? 'free');
        $nearLimit = false;
        if (($user['plan'] ?? 'free') !== 'premium') {
            $remaining = $limits['messages'] - ($user['usage']['messages'] ?? 0);
            $nearLimit = $remaining <= 10;
        }

        unset($user['password']);
        unset($user['settings']);

        echo json_encode([
            'success' => true,
            'response' => $response,
            'usage' => $user['usage'] ?? null,
            'conversation' => $updatedBot['conversations'] ?? [],
            'nearLimit' => $nearLimit,
            'bot' => $updatedBot
        ]);
        
        // Record successful message sending
        recordRequest($_SESSION['user_id'], 'sendMessage');
        break;

    case 'exportConversation':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for conversation export
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'exportConversation');
        
        if ($rateLimit['exceeded']) {
            $minutes = ceil(($rateLimit['resetTime'] - time()) / 60);
            echo json_encode([
                'success' => false,
                'message' => "Limite di richieste raggiunto. Riprova tra {$minutes} minuti.",
                'retryAfter' => $rateLimit['resetTime'] - time()
            ]);
            break;
        }

        $botId = sanitizeInput($input['botId'] ?? '');
        if (empty($botId)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID richiesto']);
            break;
        }

        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }

        $plan = $user['plan'] ?? 'free';
        if ($plan !== 'premium') {
            echo json_encode(['success' => false, 'message' => 'Esportazione non disponibile']);
            break;
        }

        $bot = null;
        foreach (($user['bots'] ?? []) as $userBot) {
            if (($userBot['id'] ?? '') === $botId) {
                $bot = $userBot;
                break;
            }
        }

        if (!$bot) {
            echo json_encode(['success' => false, 'message' => 'Bot non trovato']);
            break;
        }

        $conversations = $bot['conversations'] ?? [];
        if (!is_array($conversations) || count($conversations) === 0) {
            echo json_encode(['success' => false, 'message' => 'Conversazione vuota']);
            break;
        }

        $botName = (string)($bot['name'] ?? '');
        $model = (string)($bot['model'] ?? '');
        $createdAt = (string)($bot['created_at'] ?? '');
        $exportedAt = date('Y-m-d H:i:s');
        $personality = (string)($bot['personality'] ?? '');

        $lines = [];
        $lines[] = 'ChatBot Hub - Conversation Export';
        $lines[] = '===================================';
        $lines[] = '';
        $lines[] = 'Bot Name: ' . ($botName !== '' ? $botName : 'N/A');
        $lines[] = 'Model: ' . ($model !== '' ? $model : 'N/A');
        $lines[] = 'Created: ' . ($createdAt !== '' ? $createdAt : 'N/A');
        $lines[] = 'Exported: ' . $exportedAt;
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'System Prompt / Personalità:';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = ($personality !== '' ? $personality : 'N/A');
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = 'Conversation History:';
        $lines[] = '---';
        $lines[] = '';

        foreach ($conversations as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $timestamp = (string)($entry['timestamp'] ?? 'N/A');
            $role = (string)($entry['role'] ?? '');
            $content = (string)($entry['content'] ?? '');

            $label = 'Bot';
            if ($role === 'user') {
                $label = 'User';
            } elseif ($role === 'assistant') {
                $label = 'Bot';
            } elseif ($role === 'system') {
                $label = 'System';
            }

            $lines[] = '[' . $timestamp . '] ' . $label . ':';
            $lines[] = $content;
            $lines[] = '';
        }

        $lines[] = '===================================';
        $lines[] = 'End of Export';

        $txt = implode("\n", $lines);
        if ($txt === '') {
            echo json_encode(['success' => false, 'message' => 'Errore nella generazione del file']);
            break;
        }

        $safeBotName = preg_replace('/[^A-Za-z0-9_-]+/', '_', strtolower($botName));
        $safeBotName = trim((string)$safeBotName, '_');
        if ($safeBotName === '') {
            $safeBotName = 'bot';
        }

        $filename = 'bot_' . $safeBotName . '_' . date('Y-m-d') . '.txt';

        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        
        // Record successful conversation export
        recordRequest($_SESSION['user_id'], 'exportConversation');
        
        echo $txt;
        exit;

    case 'generateImage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        // Check rate limit for image generation (daily limit)
        $rateLimit = checkRateLimit($_SESSION['user_id'], 'generateImage');
        
        if ($rateLimit['exceeded']) {
            echo json_encode([
                'success' => false,
                'message' => 'Limite giornaliero immagini raggiunto (10/giorno). Riprova domani.'
            ]);
            break;
        }
        
        $prompt = sanitizeInput($input['prompt'] ?? '');
        $botId = sanitizeInput($input['botId'] ?? '');
        $apiKey = sanitizeInput($input['apiKey'] ?? '');
        
        if (empty($prompt)) {
            echo json_encode(['success' => false, 'message' => 'Prompt richiesto']);
            break;
        }
        
        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'API Key OpenAI richiesta']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        if (!canGenerateImage($user)) {
            echo json_encode(['success' => false, 'message' => 'Limite immagini raggiunto']);
            break;
        }
        
        // Call DALL-E API with apiKey from client
        $result = callDallE($prompt, $apiKey);
        
        if (isset($result['error'])) {
            echo json_encode(['success' => false, 'message' => $result['error']]);
            break;
        }
        
        // Update usage
        $user['usage']['images']++;
        updateUser($user);

        echo json_encode([
            'success' => true,
            'url' => $result['url'],
            'imageUrl' => $result['url'],
            'usage' => $user['usage'] ?? null
        ]);
        
        // Record successful image generation
        recordRequest($_SESSION['user_id'], 'generateImage');
        break;

    case 'deleteAccount':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $userId = $_SESSION['user_id'];
        
        $result = loadUsersWithLock();
        if (!$result['success']) {
            echo json_encode(['success' => false, 'message' => 'Errore durante l\'eliminazione']);
            break;
        }
        
        $users = $result['users'];
        $fp = $result['fp'];
        
        $newUsers = [];
        $found = false;
        foreach ($users as $user) {
            if ($user['id'] !== $userId) {
                $newUsers[] = $user;
            } else {
                $found = true;
            }
        }
        
        if (!$found) {
            releaseLock($fp);
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $users = $newUsers;
        saveUsers($users);
        releaseLock($fp);
        
        session_destroy();
        
        echo json_encode(['success' => true, 'message' => 'Account eliminato']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Azione non valida']);
        break;
}
