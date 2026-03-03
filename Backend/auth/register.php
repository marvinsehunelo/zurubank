<?php
// ===== CORS HEADERS =====
header("Access-Control-Allow-Origin: *"); // Change '*' to your frontend URL in production
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ===== DATABASE CONNECTION =====
require_once __DIR__ . "/../config/db.php";

// ===== REGISTER FUNCTION =====
function register($full_name, $email, $password_plain, $phone) {
    global $pdo;

    $password = password_hash($password_plain, PASSWORD_BCRYPT);
    $role = "customer";
    $created_at = date("Y-m-d H:i:s");

    try {
        // Check if email or phone exists
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email=? OR phone=?");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Email or phone already registered'];
        }

        $pdo->beginTransaction();

        // Insert user
        $stmt = $pdo->prepare("
            INSERT INTO users (full_name, email, phone, password, role, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$full_name, $email, $phone, $password, $role, $created_at]);
        $user_id = $pdo->lastInsertId();

        // Create accounts
        $savingsAcc = "SAV" . str_pad($user_id, 8, "0", STR_PAD_LEFT);
        $currentAcc = "CUR" . str_pad($user_id, 8, "0", STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'savings')");
        $stmt->execute([$user_id, $savingsAcc]);

        $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'current')");
        $stmt->execute([$user_id, $currentAcc]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Registration successful',
            'user_id' => $user_id,
            'accounts' => [
                'savings' => $savingsAcc,
                'current' => $currentAcc
            ],
            'instant_money_wallet' => [
                'wallet_no' => null,
                'wallet_id' => null,
                'balance' => 0.00,
                'currency' => 'BWP'
            ]
        ];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

// ===== HANDLE POST REQUEST =====
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json; charset=UTF-8");

    // Accept JSON or form-data
    $rawInput = file_get_contents("php://input");
    $data = json_decode($rawInput, true) ?: $_POST;

    $required = ['full_name', 'email', 'password', 'phone'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            exit;
        }
    }

    $result = register($data['full_name'], $data['email'], $data['password'], $data['phone']);
    echo json_encode($result);
    exit;
}

// ===== HANDLE OTHER REQUESTS =====
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);

