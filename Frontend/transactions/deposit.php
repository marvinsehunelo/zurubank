<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../../backend/controllers/transactions.php';
require_once '../../backend/controllers/accounts.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$accounts = getUserAccounts($user_id);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $account_id = $_POST['account_id'];
    $amount = $_POST['amount'];

    if (deposit($account_id, $amount)) {
        $success = "Deposit successful!";
    } else {
        $error = "Deposit failed!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deposit Money - Zuru Bank</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-800 font-inter min-h-screen flex flex-col items-center justify-center p-4">

    <!-- Header -->
    <header class="w-full bg-gray-900 text-white p-5 text-center text-lg font-bold shadow-sm z-50 fixed top-0 left-0">
        ZURU BANK
    </header>

    <div class="card bg-white p-8 rounded-lg shadow-lg max-w-sm w-full mt-24">
        <h2 class="text-3xl font-bold text-center mb-6">Deposit Money</h2>

        <!-- Success/Error Messages -->
        <?php if(isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>
        <?php if(isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Deposit Form -->
        <form method="POST" class="space-y-4">
            <div class="relative">
                <label for="account_id" class="block text-sm font-medium text-gray-700 mb-1">To Account:</label>
                <select id="account_id" name="account_id" class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent">
                    <?php foreach($accounts as $acc): ?>
                        <option value="<?= htmlspecialchars($acc['account_id']) ?>">
                            <?= ucfirst(htmlspecialchars($acc['account_type'])) ?> - Balance: P<?= number_format($acc['balance'],2) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="number" name="amount" placeholder="Amount" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent"/>
            <button type="submit" class="w-full bg-gray-900 text-white border border-gray-900 px-4 py-3 font-bold cursor-pointer transition-colors duration-200 hover:bg-gray-800">Deposit</button>
        </form>

        <!-- Back to Dashboard Link -->
        <p class="text-center mt-6">
            <a href="../dashboard/user_dashboard.php" class="text-gray-900 font-bold hover:underline">Back to Dashboard</a>
        </p>
    </div>

</body>
</html>
