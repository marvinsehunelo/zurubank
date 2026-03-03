<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../models/Ewallet.php';

$ewallet = new Ewallet($pdo);
$wallet = $ewallet->getWallet($_SESSION['user_id']);
$transactions = $ewallet->getTransactions($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zuru Bank - eWallet</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg-gray-100 text-gray-800 font-inter min-h-screen flex flex-col items-center justify-start p-4">

    <!-- Header -->
    <header class="w-full bg-gray-900 text-white p-5 text-center text-lg font-bold shadow-sm z-50 fixed top-0 left-0">
        ZURU BANK
    </header>

    <div class="card bg-white p-8 rounded-lg shadow-lg max-w-lg w-full mt-24">
        <h2 class="text-3xl font-bold text-center mb-2">Welcome, <?= htmlspecialchars($_SESSION['full_name']); ?>!</h2>
        <h3 class="text-xl font-medium text-center mb-6">eWallet Balance: P<?= number_format($wallet['balance'], 2); ?></h3>

        <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($_GET['success']); ?></span>
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?= htmlspecialchars($_GET['error']); ?></span>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <!-- Deposit Form -->
            <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                <h4 class="text-xl font-bold mb-4 text-center">Deposit</h4>
                <form method="post" action="../../controllers/ewallet.php" class="space-y-4">
                    <input type="number" step="0.01" name="amount" placeholder="Amount" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent"/>
                    <input type="hidden" name="action" value="deposit">
                    <button type="submit" class="w-full bg-gray-900 text-white border border-gray-900 px-4 py-3 font-bold cursor-pointer transition-colors duration-200 hover:bg-gray-800">Deposit</button>
                </form>
            </div>

            <!-- Withdraw Form -->
            <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                <h4 class="text-xl font-bold mb-4 text-center">Withdraw</h4>
                <form method="post" action="../../controllers/ewallet.php" class="space-y-4">
                    <input type="number" step="0.01" name="amount" placeholder="Amount" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent"/>
                    <input type="hidden" name="action" value="withdraw">
                    <button type="submit" class="w-full bg-gray-900 text-white border border-gray-900 px-4 py-3 font-bold cursor-pointer transition-colors duration-200 hover:bg-gray-800">Withdraw</button>
                </form>
            </div>

            <!-- Transfer Form -->
            <div class="bg-gray-50 p-6 rounded-lg border border-gray-200">
                <h4 class="text-xl font-bold mb-4 text-center">Transfer</h4>
                <form method="post" action="../../controllers/ewallet.php" class="space-y-4">
                    <input type="number" step="0.01" name="amount" placeholder="Amount" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent"/>
                    <input type="number" name="to_user" placeholder="Recipient User ID" required class="w-full px-4 py-3 border border-gray-300 rounded-md focus:ring-2 focus:ring-gray-900 focus:border-transparent"/>
                    <input type="hidden" name="action" value="transfer">
                    <button type="submit" class="w-full bg-gray-900 text-white border border-gray-900 px-4 py-3 font-bold cursor-pointer transition-colors duration-200 hover:bg-gray-800">Transfer</button>
                </form>
            </div>
        </div>

        <h4 class="text-2xl font-bold mb-4">Transaction History</h4>
        <div class="overflow-x-auto">
            <table class="min-w-full bg-white rounded-lg shadow overflow-hidden">
                <thead class="bg-gray-200 text-gray-700">
                    <tr>
                        <th class="py-3 px-4 text-left font-bold uppercase text-sm">Date</th>
                        <th class="py-3 px-4 text-left font-bold uppercase text-sm">Type</th>
                        <th class="py-3 px-4 text-left font-bold uppercase text-sm">Amount</th>
                        <th class="py-3 px-4 text-left font-bold uppercase text-sm">Reference</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($transactions as $t): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-3 px-4 text-sm"><?= htmlspecialchars($t['created_at']); ?></td>
                            <td class="py-3 px-4 text-sm"><?= ucfirst(htmlspecialchars($t['type'])); ?></td>
                            <td class="py-3 px-4 text-sm">P<?= number_format($t['amount'], 2); ?></td>
                            <td class="py-3 px-4 text-sm"><?= htmlspecialchars($t['reference']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <p class="text-center mt-6">
            <a href="../dashboard/user_dashboard.php" class="text-gray-900 font-bold hover:underline">Back to Dashboard</a>
        </p>

    </div>

</body>
</html>
