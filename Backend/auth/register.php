<?php
// ===== CORS HEADERS =====
header("Access-Control-Allow-Origin: *"); // Change '*' to your frontend URL in production
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ===== ERROR REPORTING (remove in production) =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== DATABASE CONNECTION =====
require_once __DIR__ . "/../config/db.php";

// ===== REGISTER FUNCTION =====
function register($full_name, $email, $password_plain, $phone) {
    global $pdo;

    // Validate inputs
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Invalid email format'];
    }

    if (!preg_match('/^[0-9+\-\s()]{8,}$/', $phone)) {
        return ['success' => false, 'message' => 'Invalid phone number format'];
    }

    if (strlen($password_plain) < 8) {
        return ['success' => false, 'message' => 'Password must be at least 8 characters'];
    }

    // Hash the password
    $password_hash = password_hash($password_plain, PASSWORD_BCRYPT);
    $role = "customer";
    $status = "active";
    $created_at = date("Y-m-d H:i:s");

    try {
        // Check if email or phone exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ?");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Email or phone already registered'];
        }

        $pdo->beginTransaction();

        // Insert user - using correct column name 'password_hash'
        $stmt = $pdo->prepare("
            INSERT INTO users (
                full_name, 
                email, 
                phone, 
                password_hash, 
                role, 
                status, 
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $full_name, 
            $email, 
            $phone, 
            $password_hash, 
            $role, 
            $status, 
            $created_at
        ]);
        $user_id = $pdo->lastInsertId();

        // Create accounts
        $savingsAcc = "SAV" . str_pad($user_id, 8, "0", STR_PAD_LEFT);
        $currentAcc = "CUR" . str_pad($user_id, 8, "0", STR_PAD_LEFT);

        // Insert savings account
        $stmt = $pdo->prepare("
            INSERT INTO accounts (
                user_id, 
                account_number, 
                account_type, 
                balance, 
                currency, 
                status, 
                created_at
            ) VALUES (?, ?, 'savings', 0.00, 'BWP', 'active', ?)
        ");
        $stmt->execute([$user_id, $savingsAcc, $created_at]);

        // Insert current account
        $stmt = $pdo->prepare("
            INSERT INTO accounts (
                user_id, 
                account_number, 
                account_type, 
                balance, 
                currency, 
                status, 
                created_at
            ) VALUES (?, ?, 'current', 0.00, 'BWP', 'active', ?)
        ");
        $stmt->execute([$user_id, $currentAcc, $created_at]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Registration successful',
            'user_id' => $user_id,
            'full_name' => $full_name,
            'email' => $email,
            'phone' => $phone,
            'accounts' => [
                'savings' => [
                    'account_number' => $savingsAcc,
                    'type' => 'savings',
                    'balance' => 0.00,
                    'currency' => 'BWP'
                ],
                'current' => [
                    'account_number' => $currentAcc,
                    'type' => 'current',
                    'balance' => 0.00,
                    'currency' => 'BWP'
                ]
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Registration error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Registration failed. Please try again later.'];
    }
}

// ===== HANDLE POST REQUEST =====
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json; charset=UTF-8");

    // Accept JSON or form-data
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true) ?: $_POST;

    // Log incoming data for debugging (remove in production)
    error_log("Registration request received: " . json_encode($data));

    // Validate required fields
    $required = ['full_name', 'email', 'password', 'phone'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            $missing[] = $field;
        }
    }
    
    if (!empty($missing)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Missing required fields: ' . implode(', ', $missing)
        ]);
        exit;
    }

    // Call register function
    $result = register(
        trim($data['full_name']), 
        trim($data['email']), 
        $data['password'], 
        trim($data['phone'])
    );
    
    echo json_encode($result);
    exit;
}

// ===== HANDLE OTHER REQUESTS =====
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
exit;
?>
