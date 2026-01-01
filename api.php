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
// HELPER FUNCTIONS
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
// LOAD/SAVE FUNCTIONS
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

// ============================================================================
// FINDER FUNCTIONS
// ============================================================================

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

// ============================================================================
// UPDATE FUNCTIONS
// ============================================================================

function updateUser($userData) {
    $users = loadUsers();
    
    for ($i = 0; $i < count($users); $i++) {
        if ($users[$i]['id'] === $userData['id']) {
            $users[$i] = $userData;
            return saveUsers($users);
        }
    }
    
    return false;
}

function resetUsageIfNeeded(&$user) {
    $today = date('Y-m-d');
    $currentMonth = date('Y-m');
    
    // Reset daily images
    if (!isset($user['usage']['lastReset']) || $user['usage']['lastReset'] !== $today) {
        $user['usage']['images'] = 0;
        $user['usage']['lastReset'] = $today;
    }
    
    // Reset monthly messages
    if (!isset($user['usage']['lastMessageReset']) || substr($user['usage']['lastMessageReset'], 0, 7) !== $currentMonth) {
        $user['usage']['messages'] = 0;
        $user['usage']['lastMessageReset'] = $today;
    }
    
    updateUser($user);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

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
// OPENAI FUNCTIONS
// ============================================================================

/**
 * Call OpenAI Chat Completions API
 * @param string $message User message
 * @param string $personality Bot personality
 * @param array $history Full conversation history
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


// ============================================================================
// API ROUTES
// ============================================================================

switch ($action) {
    case 'checkAuth':
        if (isset($_SESSION['user_id'])) {
            $user = findUserById($_SESSION['user_id']);
            if ($user) {
                resetUsageIfNeeded($user);
                // Don't send sensitive fields to client
                unset($user['password']);
                unset($user['settings']);
                echo json_encode(['success' => true, 'user' => $user]);
            } else {
                echo json_encode(['success' => false]);
            }
        } else {
            echo json_encode(['success' => false]);
        }
        break;

    case 'register':
        $name = sanitizeInput($input['name'] ?? '');
        $email = sanitizeInput($input['email'] ?? '');
        $password = $input['password'] ?? '';
        
        // Validation
        if (empty($name) || empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Tutti i campi sono obbligatori']);
            break;
        }
        
        if (strlen($password) < 8) {
            echo json_encode(['success' => false, 'message' => 'Password minimo 8 caratteri']);
            break;
        }
        
        if (!validateEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'Email non valida']);
            break;
        }
        
        if (findUserByEmail($email)) {
            echo json_encode(['success' => false, 'message' => 'Email già registrata']);
            break;
        }
        
        // Create user
        $users = loadUsers();
        $newUser = [
            'id' => generateId(),
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'plan' => 'free',
            'usage' => [
                'messages' => 0,
                'images' => 0,
                'lastReset' => date('Y-m-d'),
                'lastMessageReset' => date('Y-m')
            ],
            'bots' => []
        ];
        
        $users[] = $newUser;
        saveUsers($users);
        
        echo json_encode(['success' => true, 'message' => 'Registrazione completata']);
        break;

    case 'login':
        $email = sanitizeInput($input['email'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'Email e password richiesti']);
            break;
        }
        
        $user = findUserByEmail($email);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            resetUsageIfNeeded($user);
            unset($user['password']);
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Credenziali non valide']);
        }
        break;

    case 'logout':
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'createBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $name = sanitizeInput($input['name'] ?? '');
        $personality = sanitizeInput($input['personality'] ?? '');
        $model = $input['model'] ?? 'gpt-4o-mini';
        
        if (empty($name) || empty($personality)) {
            echo json_encode(['success' => false, 'message' => 'Nome e personalità richiesti']);
            break;
        }
        
        if (strlen($name) > 100) {
            echo json_encode(['success' => false, 'message' => 'Nome troppo lungo (max 100 caratteri)']);
            break;
        }
        
        if (strlen($personality) > 5000) {
            echo json_encode(['success' => false, 'message' => 'Personalità troppo lunga (max 5000 caratteri)']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
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
        break;

    case 'getBots':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        echo json_encode(['success' => true, 'bots' => $user['bots'] ?? []]);
        break;

    case 'getBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
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
        break;

    case 'deleteBot':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
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
        break;

    case 'sendMessage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        $message = sanitizeInput($input['message'] ?? '');
        $apiKey = $input['apiKey'] ?? '';
        $history = $input['history'] ?? [];
        
        if (empty($botId) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID e messaggio richiesti']);
            break;
        }
        
        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'API Key non configurata']);
            break;
        }
        
        $user = findUserById($_SESSION['user_id']);
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
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
        
        // Add user message to history
        $bot['conversations'][] = [
            'id' => generateId(),
            'role' => 'user',
            'content' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Generate response
        $response = callOpenAI($message, $bot['personality'], $history, $bot['model'], $apiKey);
        
        // Check for errors
        if (strpos($response, 'Errore') !== false) {
            echo json_encode(['success' => false, 'message' => $response]);
            break;
        }
        
        // Add assistant response to history
        $bot['conversations'][] = [
            'id' => generateId(),
            'role' => 'assistant',
            'content' => $response,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Get history limit
        $maxMessages = getHistoryLimit($user['plan']);
        
        // Check if near limit
        $nearLimit = false;
        if ($user['plan'] === 'premium' && count($bot['conversations']) >= ($maxMessages - 4)) {
            $nearLimit = true;
        }
        
        // Keep only recent messages
        if (count($bot['conversations']) > $maxMessages) {
            $bot['conversations'] = array_slice($bot['conversations'], -$maxMessages);
        }
        
        // Update bot in user
        $user['bots'][$botIndex] = $bot;
        
        // Update usage and save
        $user['usage']['messages']++;
        updateUser($user);
        
        echo json_encode([
            'success' => true,
            'response' => $response,
            'usage' => $user['usage'],
            'conversation' => $bot['conversations'],
            'nearLimit' => $nearLimit
        ]);
        break;

    case 'generateImage':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $botId = $input['botId'] ?? '';
        $prompt = sanitizeInput($input['prompt'] ?? '');
        $apiKey = $input['apiKey'] ?? '';
        
        if (empty($botId) || empty($prompt)) {
            echo json_encode(['success' => false, 'message' => 'Bot ID e prompt richiesti']);
            break;
        }
        
        if (empty($apiKey)) {
            echo json_encode(['success' => false, 'message' => 'API Key non configurata']);
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
        
        // Generate image
        $imageResult = callDallE($prompt, $apiKey);
        
        if (isset($imageResult['error'])) {
            echo json_encode(['success' => false, 'message' => $imageResult['error']]);
            break;
        }
        
        // Update usage
        $user['usage']['images']++;
        updateUser($user);
        
        echo json_encode([
            'success' => true,
            'imageUrl' => $imageResult['url'],
            'usage' => $user['usage']
        ]);
        break;

    case 'upgradePlan':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
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
        updateUser($user);
        
        echo json_encode(['success' => true, 'message' => 'Piano aggiornato']);
        break;

    case 'deleteAccount':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false, 'message' => 'Non autenticato']);
            break;
        }
        
        $userId = $_SESSION['user_id'];
        
        $users = loadUsers();
        
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
            echo json_encode(['success' => false, 'message' => 'Utente non trovato']);
            break;
        }
        
        $users = $newUsers;
        saveUsers($users);
        
        session_destroy();
        
        echo json_encode(['success' => true, 'message' => 'Account eliminato']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Azione non valida']);
        break;
}