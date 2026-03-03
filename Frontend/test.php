<?php
// test.php - Test Insert into instant_money_vouchers with all columns

// -------------------------
// 1. Database Connection
// -------------------------
$host = '127.0.0.1';
$db   = 'zurubank';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "✅ Database connected successfully.\n";
} catch (\PDOException $e) {
    die("❌ Database connection failed: " . $e->getMessage() . "\n");
}

// -------------------------
// 2. Test Voucher Data
// -------------------------
$amount = 500.00;                // Amount for the voucher
$user_id = 28;                    // Creator user ID
$recipient_phone = '+26770000000'; // Recipient phone number

// -------------------------
// 3. Generate Unique Voucher Number
// -------------------------
do {
    $voucher_number = str_pad(mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("SELECT voucher_id FROM instant_money_vouchers WHERE voucher_number=? LIMIT 1");
    $stmt->execute([$voucher_number]);
    $exists = $stmt->fetch();
} while ($exists);

// -------------------------
// 4. Generate Voucher PIN
// -------------------------
$voucher_pin = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

// -------------------------
// 5. Insert Voucher
// -------------------------
try {
    $stmt = $pdo->prepare("
        INSERT INTO instant_money_vouchers
        (voucher_number, voucher_pin, amount, currency, status, created_by, recipient_phone, redeemed_by, 
         voucher_created_at, voucher_expires_at, swap_enabled, swap_fee_paid_by, swap_expires_at, 
         swap_made_at, swap_middleman_account_id)
        VALUES (?, ?, ?, 'BWP', 'active', ?, ?, NULL, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY), 1, NULL, NULL, NULL, NULL)
    ");

    $stmt->execute([
        $voucher_number,
        $voucher_pin,
        $amount,
        $user_id,
        $recipient_phone
    ]);

    $voucher_id = $pdo->lastInsertId();

    echo "✅ Voucher created successfully!\n";
    echo "Voucher ID: $voucher_id\n";
    echo "Voucher Number: $voucher_number\n";
    echo "Voucher PIN: $voucher_pin\n";

} catch (Exception $e) {
    echo "❌ Failed to create voucher: " . $e->getMessage() . "\n";
}
