<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Check login & token
if (!isset($_SESSION['authToken']) || !isset($_SESSION['user']['id'])) {
    header("Location: ../auth/login.php"); 
    exit;
}

$token = $_SESSION['authToken'];
$user_id = $_SESSION['user']['id'];
$full_name = $_SESSION['user']['full_name'];

// --- Backend Dependency Check ---
require_once __DIR__.'/../../Backend/config/db.php'; 



// Fetch user accounts
try {
    $stmt = $pdo->prepare("SELECT account_id, account_number, account_type, balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Account summary
    $summary = [
        'total_balance' => array_sum(array_column($accounts, 'balance')),
        'account_count' => count($accounts)
    ];

    // Recent transactions
    $stmt = $pdo->prepare("
        SELECT t.created_at, t.type, t.amount, t.status, t.description, a.account_number
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        WHERE a.user_id = ?
        ORDER BY t.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // JS account options with account_id as value (for transfers)
    $js_account_options = '';
    foreach ($accounts as $acc) {
        $js_account_options .= '<option value="'.$acc['account_id'].'">'
            .htmlspecialchars(ucfirst($acc['account_type'])).' - '.$acc['account_number'].' (P'.number_format($acc['balance'],2).')</option>';
    }

} catch (PDOException $e) {
    // Handle database errors gracefully on the dashboard
    $summary = ['total_balance' => 0.00, 'account_count' => 0];
    $accounts = [];
    $recent_transactions = [];
    $js_account_options = '<option value="">Error loading accounts</option>';
    error_log("Dashboard DB Error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZuruBank - Executive Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
/* ULTRA-MINIMALIST ZURUBANK STYLE */
body { font-family: 'Inter', sans-serif; font-size: 14px; } /* Base font size reduced */
.sharp-edge { border-radius: 0; } /* Absolutely no rounded corners */
.sharp-btn { border-radius: 0; border-width: 1px; transition: all 0.2s; }
.modal-bg { background: rgba(0,0,0,0.6); }

/* Primary Branding Color: Deep Navy Blue */
.color-primary { background-color: #0B1D51; }
.text-primary { color: #0B1D51; }
.btn-primary { background-color: #0B1D51; color: white; border: 1px solid #0B1D51; }
.btn-primary:hover { background-color: #1a3466; }

/* Status Colors */
.text-success { color: #059669; }
.text-danger { color: #DC2626; }

/* Toast Styling */
.toast { position: fixed; top: 20px; right: 20px; z-index: 9999; padding: 8px 16px; border-radius: 0; color: white; font-weight: 600; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2); display: none; font-size: 14px; }
.toast-success { background-color: #059669; }
.toast-error { background-color: #DC2626; }

/* Input/Select Styling (Slimmer, professional focus effect) */
.input-field { border: 1px solid #E5E7EB; padding: 6px 8px; height: 30px; font-size: 13px; }
.input-field:focus { border-color: #0B1D51; box-shadow: 0 0 0 1px #0B1D51; outline: none; }

/* Quick Action Buttons - Very slim and clean */
.action-btn { font-size: 13px; padding-top: 6px; padding-bottom: 6px; }
.action-btn:hover { background-color: #F9FAFB; border-color: #E5E7EB; }
</style>
</head>
<body class="min-h-screen bg-gray-50">

<header class="color-primary py-3 px-8 flex justify-between items-center text-white shadow-md">
    <h1 class="text-xl font-extrabold tracking-widest">ZURUBANK</h1>
    <div class="flex items-center space-x-4 text-sm">
        <span class="font-medium text-gray-300">Welcome, <?= htmlspecialchars($full_name) ?></span>
        <button id="logoutBtn" class="py-1 px-3 text-xs sharp-btn border border-red-500 bg-red-600 hover:bg-red-700 text-white font-semibold">LOGOUT</button>
    </div>
</header>

<main class="max-w-7xl mx-auto px-4 lg:px-8 py-8">
    
    <div class="bg-white p-6 mb-8 sharp-edge shadow-xl border-t-4 border-t-primary">
        <p class="text-sm font-medium text-gray-500">Total Account Value</p>
        <p class="mt-1 text-4xl font-extrabold text-gray-900 tracking-tight">P<?= number_format($summary['total_balance'],2) ?></p>
        <div class="mt-3 text-gray-600 font-medium">
             <span class="inline-block mr-4 text-xs"><?= $summary['account_count'] ?> Accounts Linked</span> 
             <span class="text-xs text-gray-400">| Secure Access</span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <div class="lg:col-span-1 space-y-6">
            
            <div class="bg-white p-5 shadow-lg sharp-edge border border-gray-100">
                <h2 class="text-lg font-bold text-primary mb-3 border-b pb-2">Your Accounts</h2>
                <div class="space-y-2 text-sm">
                    <?php foreach ($accounts as $acc): ?>
                        <div class="p-2 border-b border-gray-100 sharp-edge bg-gray-50 hover:bg-white transition-colors flex justify-between">
                            <p class="font-semibold text-gray-700"><?= htmlspecialchars(ucfirst($acc['account_type'])) ?> - <?= htmlspecialchars($acc['account_number']) ?></p>
                            <p class="font-bold text-gray-900">P<?= number_format($acc['balance'],2) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white p-5 shadow-lg sharp-edge border border-gray-100">
                <h3 class="text-lg font-bold text-primary mb-3 border-b pb-2">Quick Services</h3>
                <div class="space-y-2">
                    <button id="transferFundsBtn" class="action-btn w-full sharp-btn border border-gray-200 bg-white text-gray-900 font-medium text-left px-3 flex justify-between items-center shadow-sm">
                        <span>Transfer Funds</span>
                        <span class="text-sm text-primary">&rarr;</span>
                    </button>
                    <button id="instantMoneyBtn" class="action-btn w-full sharp-btn border border-gray-200 bg-white text-gray-900 font-medium text-left px-3 flex justify-between items-center shadow-sm">
                        <span>Instant Money Voucher</span>
                        <span class="text-sm text-primary">&rarr;</span>
                    </button>
                    <button id="bankStatementBtn" class="action-btn w-full sharp-btn border border-gray-200 bg-white text-gray-900 font-medium text-left px-3 flex justify-between items-center shadow-sm">
                        <span>Bank Statement</span>
                        <span class="text-sm text-primary">&rarr;</span>
                    </button>
                </div>
            </div>
        </div>

       <div class="lg:col-span-2 space-y-6">

           <!-- Recent Activity Collapsible -->
           <div class="bg-white p-5 shadow-lg sharp-edge border border-gray-100">
                <div class="flex justify-between items-center cursor-pointer" onclick="toggleBox('recentActivityBox','recentActivityIcon')">
                    <h4 class="text-lg font-bold text-primary mb-0">Recent Activity</h4>
                    <span id="recentActivityIcon" class="text-primary font-bold">&darr;</span>
                </div>
                <div id="recentActivityBox" class="hidden mt-3">
                    <ul class="divide-y divide-gray-100">
                        <?php if (empty($recent_transactions)): ?>
                            <li class="py-2 text-sm text-gray-500">No recent transactions.</li>
                        <?php else: ?>
                            <?php foreach ($recent_transactions as $t): ?>
                            <li class="py-2 flex justify-between items-center text-xs">
                                <div class="text-gray-800 font-medium"><?= htmlspecialchars($t['description'] ?? 'Transaction') ?> 
                                    <span class="text-xs text-gray-400 font-normal">(<?= htmlspecialchars($t['account_number']) ?>)</span>
                                </div>
                                <span class="<?= ($t['type'] ?? 'debit')==='credit'?'text-success':'text-danger' ?> font-bold">
                                    <?= ($t['type'] ?? 'debit')==='credit'?'+':'-' ?>P<?= number_format($t['amount'] ?? 0,2) ?>
                                </span>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Active Instant Money Vouchers Collapsible -->
            <div class="bg-white p-5 shadow-lg sharp-edge border border-gray-100">
                <div class="flex justify-between items-center cursor-pointer" onclick="toggleBox('voucherListContainer','voucherListIcon')">
                    <h4 class="text-lg font-bold text-primary mb-0">Active Instant Money Vouchers</h4>
                    <span id="voucherListIcon" class="text-primary font-bold">&darr;</span>
                </div>
                <div id="voucherListContainer" class="hidden text-xs overflow-x-auto mt-3">Loading vouchers...</div>
            </div>

        </div>

    </div>
</main>

<footer class="bg-gray-100 border-t border-gray-200 py-3 px-8 text-center text-xs text-gray-600">
    &copy; <?= date('Y') ?> ZuruBank. All Rights Reserved. Executive Security Protocol Active.
</footer>

<div id="toast" class="toast"></div>

<!-- Transfer Modal -->
<div id="transferModal" class="hidden fixed inset-0 modal-bg z-50 flex justify-center items-center p-4">
    <div class="bg-white w-full max-w-md p-5 shadow-2xl sharp-edge" onclick="event.stopPropagation()">
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-primary">Transfer Funds</h3>
        <div class="flex space-x-2 mb-4 border-b pb-2">
            <button data-type="own" class="transfer-type-btn py-1.5 px-3 sharp-btn border text-sm font-medium bg-gray-100 hover:bg-gray-200">Own Account</button>
            <button data-type="internal" class="transfer-type-btn py-1.5 px-3 sharp-btn border text-sm font-medium bg-gray-100 hover:bg-gray-200">Internal</button>
            <button data-type="external" class="transfer-type-btn py-1.5 px-3 sharp-btn border text-sm font-medium bg-gray-100 hover:bg-gray-200">External</button>
        </div>
        <div id="transferFormContainer" class="text-xs text-gray-600">Select transfer type to begin.</div>
        <button onclick="closeModal('transferModal')" class="mt-4 w-full py-1.5 sharp-btn border bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm">Cancel</button>
    </div>
</div>

<!-- Instant Money Modal --> 
<div id="instantMoneyModal" class="hidden fixed inset-0 modal-bg z-50 flex justify-center items-center p-4">
    <div class="bg-white w-full max-w-sm p-5 shadow-2xl sharp-edge" onclick="event.stopPropagation()">
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-primary">Instant Money Voucher</h3>
        <form id="instantMoneyForm" method="POST" action="../../Backend/controllers/instant_money.php">
            <!-- Account Selection -->
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-700 mb-1">Select Account to Debit</label>
                <select name="from_account_id" class="w-full input-field sharp-btn" required>
                    <option value="">Select Account</option>
                    <?= $js_account_options ?> 
                </select>
            </div>

            <!-- Recipient Phone -->
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-700 mb-1">Recipient Phone</label>
                <input type="text" name="recipient_phone" class="w-full input-field sharp-btn" placeholder="0917xxxxxxx" required>
            </div>

            <!-- Amount -->
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">Amount (P)</label>
                <input type="number" name="amount" class="w-full input-field sharp-btn" min="1" step="0.01" required>
            </div>

            <!-- Swap Option -->
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-700 mb-1">Enable Swap Option?</label>
                <div class="flex items-center space-x-4 text-sm">
                    <label class="inline-flex items-center">
                        <input type="radio" name="swap_option" value="none" checked class="mr-2">
                        No Swap
                    </label>
                    <label class="inline-flex items-center">
                        <input type="radio" name="swap_option" value="sender" class="mr-2">
                        Swap by Sender (You Pay)
                    </label>
                </div>
            </div>

            <button type="submit" class="w-full py-2 sharp-btn btn-primary font-semibold text-sm">GENERATE VOUCHER</button>
        </form>
        <button onclick="closeModal('instantMoneyModal')" class="mt-3 w-full py-1.5 sharp-btn border bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm">Cancel</button>
    </div>
</div>

<!-- Statement Modal -->
<div id="statementModal" class="hidden fixed inset-0 modal-bg z-50 flex justify-center items-center p-4">
    <div class="bg-white w-full max-w-sm p-5 shadow-2xl sharp-edge" onclick="event.stopPropagation()">
        <h3 class="text-xl font-bold mb-4 border-b pb-2 text-primary">Download Statement</h3>
        <form id="statementForm" method="GET" action="../../Backend/reports/bank_statement.php">
            <div class="mb-3">
                <label class="block text-xs font-medium text-gray-700 mb-1">Start Date</label>
                <input type="date" name="from_date" class="w-full input-field sharp-btn" required>
            </div>
            <div class="mb-4">
                <label class="block text-xs font-medium text-gray-700 mb-1">End Date</label>
                <input type="date" name="to_date" class="w-full input-field sharp-btn" required>
            </div>
            <button type="submit" class="w-full py-2 sharp-btn btn-primary font-semibold text-sm">DOWNLOAD PDF</button>
        </form>
        <button onclick="closeModal('statementModal')" class="mt-3 w-full py-1.5 sharp-btn border bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm">Cancel</button>
    </div>
</div>

<script>
// ------------------ TOKEN DEFINITION ------------------
const AUTH_TOKEN = "<?php echo htmlspecialchars($token, ENT_QUOTES); ?>";

// Utility headers for URL-encoded POST
function getAuthHeaders() {
    return { 
        'Authorization': AUTH_TOKEN,
        'Content-Type': 'application/x-www-form-urlencoded' 
    };
}

// ------------------ UTILITY ------------------
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
function showToast(message, success = true) {
    const t = document.getElementById('toast');
    t.className = 'toast ' + (success ? 'toast-success' : 'toast-error');
    t.textContent = message;
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3000);
}
// -------------- TOGGLE FUNCTION --------------
function toggleBox(boxId, iconId) {
    const box = document.getElementById(boxId);
    box.classList.toggle('hidden');
    if(iconId){
        const icon = document.getElementById(iconId);
        icon.innerHTML = box.classList.contains('hidden') ? '&darr;' : '&uarr;';
    }
}

// ------------------ MODAL TRIGGERS ------------------
['transferFundsBtn','instantMoneyBtn','bankStatementBtn'].forEach(btnId => {
    document.getElementById(btnId).addEventListener('click', () => {
        const modalId = btnId==='transferFundsBtn'?'transferModal': btnId==='instantMoneyBtn'?'instantMoneyModal':'statementModal';
        document.getElementById(modalId).classList.remove('hidden');
    });
});
document.querySelectorAll('.modal-bg').forEach(bg => bg.addEventListener('click', () => closeModal(bg.id)));


// ------------------ TRANSFER MODAL LOGIC ------------------

const transferFormContainer = document.getElementById('transferFormContainer');
const accountsOptions = `<?= $js_account_options ?>`; 

document.querySelectorAll('.transfer-type-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const type = this.dataset.type;
        let formHtml = '';

        // Highlight active button
        document.querySelectorAll('.transfer-type-btn').forEach(b => {
            b.classList.remove('btn-primary', 'text-white');
            b.classList.add('bg-gray-100', 'text-gray-900');
        });
        this.classList.add('btn-primary', 'text-white');
        this.classList.remove('bg-gray-100', 'text-gray-900');


        // Re-generate forms with new input-field class
        switch(type){
            case 'own':
                formHtml = `
                <form id="ownTransferForm" class="transfer-form" action="../../Backend/transactions/own_transfer.php">
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">From Account</label><select name="source" class="w-full input-field sharp-btn" required>${accountsOptions}</select></div>
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">To Account</label><select name="target" class="w-full input-field sharp-btn" required>${accountsOptions}</select></div>
                    <div class="mb-4"><label class="block text-xs font-medium mb-1">Amount (P)</label><input type="number" name="amount" class="w-full input-field sharp-btn" min="1" step="0.01" required></div>
                    <button type="submit" class="w-full py-2 sharp-btn btn-primary font-semibold text-sm">TRANSFER FUNDS</button>
                </form>`;
                break;

            case 'internal':
                formHtml = `
                <form id="internalTransferForm" class="transfer-form" action="../../Backend/transactions/internal_transfer.php">
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">Recipient Account Number</label><input type="text" name="target_account" class="w-full input-field sharp-btn" required></div>
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">From Account</label><select name="source" class="w-full input-field sharp-btn" required>${accountsOptions}</select></div>
                    <div class="mb-4"><label class="block text-xs font-medium mb-1">Amount (P)</label><input type="number" name="amount" class="w-full input-field sharp-btn" min="1" step="0.01" required></div>
                    <button type="submit" class="w-full py-2 sharp-btn btn-primary font-semibold text-sm">TRANSFER FUNDS</button>
                </form>`;
                break;

            case 'external':
                formHtml = `
                <form id="externalTransferForm" class="transfer-form" action="../../Backend/transactions/external_transfer.php">
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">Recipient Bank Name</label><input type="text" name="bank_name" class="w-full input-field sharp-btn" required></div>
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">Recipient Account Number</label><input type="text" name="external_account" class="w-full input-field sharp-btn" required></div>
                    <div class="mb-3"><label class="block text-xs font-medium mb-1">From Account</label><select name="source" class="w-full input-field sharp-btn" required>${accountsOptions}</select></div>
                    <div class="mb-4"><label class="block text-xs font-medium mb-1">Amount (P)</label><input type="number" name="amount" class="w-full input-field sharp-btn" min="1" step="0.01" required></div>
                    <button type="submit" class="w-full py-2 sharp-btn btn-primary font-semibold text-sm">TRANSFER FUNDS</button>
                </form>`;
                break;
        }

        transferFormContainer.innerHTML = formHtml;

        // Attach submit handler to the newly created form
        const form = transferFormContainer.querySelector('.transfer-form');
        form.addEventListener('submit', function(e){
            e.preventDefault();
            const formData = new FormData(form);
            const body = new URLSearchParams(formData);

            fetch(form.action, { 
                method:'POST', 
                body: body,
                headers: getAuthHeaders() 
            })
            .then(r => r.json())
            .then(res => {
                if(res.status === 'success'){ 
                    showToast(res.message, true);
                    form.reset();
                    closeModal('transferModal');
                    window.location.reload(); 
                } else showToast(res.message || 'Transfer failed.', false);
            })
            .catch(err => {
                console.error(err);
                showToast('Transfer failed due to network error.', false);
            });
        });
    });
});

// Select the first button to show a form by default
document.addEventListener('DOMContentLoaded', () => {
    const firstTransferBtn = document.querySelector('.transfer-type-btn');
    if (firstTransferBtn) {
        firstTransferBtn.click();
    }
});


// ------------------ INSTANT MONEY VOUCHER SUBMIT ------------------
document.getElementById('instantMoneyForm').addEventListener('submit', function(e){
    e.preventDefault();
    const form = e.target;

    const data = new URLSearchParams(new FormData(form));

    const source = data.get('from_account_id');
    const recipient = data.get('recipient_phone');
    const amount = parseFloat(data.get('amount'));
    const swapOption = data.get('swap_option'); // <--- new swap field

    if(!source || !recipient || amount <= 0){
        showToast('Please select an account, enter a valid phone number, and a positive amount.', false);
        return;
    }

    // Append extra info for backend
    data.append('action','transfer');
    data.append('swap_option', swapOption); // <--- send swap option

    fetch('../../Backend/controllers/instant_money.php', {
        method: 'POST',
        body: data,
        headers: getAuthHeaders() 
    })
    .then(r => r.json())
    .then(res => {
        if(res.success){
            showToast(res.message, true);
            form.reset();
            closeModal('instantMoneyModal');
            loadVouchers(); // refresh voucher list
        } else {
            showToast(res.message, false);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Network/server error while sending transfer.', false);
    });
});


// ----------------- LOAD VOUCHERS -----------------
function loadVouchers(){
    const c=document.getElementById('voucherListContainer');
    fetch('../../Backend/controllers/instant_money.php', {
        method:'POST',
        headers:getAuthHeaders(),
        body:new URLSearchParams({action:'list_vouchers'})
    })
    .then(r=>r.json())
    .then(res=>{
        if(!res.success || !res.vouchers){ 
            c.innerHTML='<div class="text-gray-500 py-2 text-xs">Error loading vouchers or access denied.</div>'; 
            return; 
        }
        if(!res.vouchers.length){ 
            c.innerHTML='<div class="text-gray-500 py-2 text-xs">No active vouchers.</div>'; 
            return; 
        }
        
        c.innerHTML='<table class="min-w-full text-xs border border-gray-200"><thead><tr class="bg-gray-100 text-gray-700 font-semibold"><th class="border px-3 py-1.5 text-left">Code</th><th class="border px-3 py-1.5 text-left">Phone</th><th class="border px-3 py-1.5 text-left">Amt</th><th class="border px-3 py-1.5 text-left">Swap</th><th class="border px-3 py-1.5 text-left">Swap Paid By</th><th class="border px-3 py-1.5 text-left">Status</th><th class="border px-3 py-1.5 text-left">Action</th></tr></thead><tbody>'
          + res.vouchers.map(v=>`
            <tr class="hover:bg-gray-50">
              <td class="border px-3 py-1.5 text-gray-800 font-medium">${v.voucher_number}</td>
              <td class="border px-3 py-1.5">${v.recipient_phone ?? 'N/A'}</td>
              <td class="border px-3 py-1.5 font-bold">P${v.amount}</td>
              <td class="border px-3 py-1.5">${v.swap_enabled ? 'Yes' : 'No'}</td>
              <td class="border px-3 py-1.5">${v.swap_fee_paid_by ?? '—'}</td>
              <td class="border px-3 py-1.5"><span class="font-bold ${v.status==='active'?'text-success':'text-gray-500'}">${v.status}</span></td>
              <td class="border px-3 py-1.5">${v.status==='active'?`<button onclick="reverseVoucher('${v.voucher_number}')" class='text-danger underline hover:text-red-800 font-medium'>Reverse</button>`:'—'}</td>
            </tr>`).join('')
          +'</tbody></table>';
    })
    .catch(()=>c.innerHTML='<div class="text-red-500 py-2 text-xs">Error loading vouchers (Network failure).</div>');
}

// ----------------- REVERSE VOUCHER -----------------
function reverseVoucher(code) {
    if (!confirm(`Are you sure you want to reverse voucher ${code}? Funds will be credited back to your account.`)) return;
    
    // 1. FIX: Use the parameter 'code' instead of the undefined variable 'number'
    showToast('Reversing voucher ' + code + '...', true);
    
    fetch('../../Backend/controllers/instant_money.php', {
        method: 'POST',
        headers: getAuthHeaders(),
        // 2. FIX: Use the key 'voucher_number' (to match DB column) 
        // and the value 'code' (the parameter received)
        body: new URLSearchParams({ action: 'reverse', voucher_number: code })
    }).then(r => r.json()).then(res => {
        showToast(res.message, res.success);
        loadVouchers();
        window.location.reload();
    }).catch(() => showToast('Failed to reverse voucher.', false));
}
document.addEventListener('DOMContentLoaded', loadVouchers);


// ------------------ BANK STATEMENT ------------------
document.getElementById('statementForm').addEventListener('submit', function(e){
    e.preventDefault();
    const form = e.target;
    const fromDate = form.from_date.value;
    const toDate = form.to_date.value;

    if(!fromDate || !toDate || fromDate > toDate){ showToast('Invalid date range', false); return; }

    const params = new URLSearchParams({from_date: fromDate, to_date: toDate}).toString();
    window.open(form.action + '?' + params, '_blank');
    closeModal('statementModal');
});

// ------------------ LOGOUT ------------------
document.getElementById('logoutBtn').addEventListener('click', () => {
    fetch('../../Backend/auth/logout.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ token: AUTH_TOKEN })
    }).finally(()=>window.location.href='../auth/login.php');
});


</script>
</body>
</html>
