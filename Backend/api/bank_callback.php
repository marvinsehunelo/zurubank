<?php
// zurubank/backend/api/bank_callback.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/hmac.php';

$raw = file_get_contents('php://input');
$ts = getallheaders()['X-CB-Callback-Timestamp'] ?? getallheaders()['x-cb-callback-timestamp'] ?? null;
$sig = getallheaders()['X-CB-Callback-Signature'] ?? getallheaders()['x-cb-callback-signature'] ?? null;
$central_secret = getenv('CENTRAL_BANK_CALLBACK_SECRET') ?: 'central-callback-secret';

if (!verify_request_hmac($raw, $sig, $ts, $central_secret)) {
    http_response_code(401); echo json_encode(['status'=>'error','message'=>'Invalid signature or timestamp']); exit;
}
$data = json_decode($raw, true);
if (!$data) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Invalid JSON']); exit; }

$transfer_id = $data['transfer_id'] ?? null;
$origin_tx = $data['origin_transaction_id'] ?? null;
$status = $data['status'] ?? null;
$amount = floatval($data['amount'] ?? 0);
if (!$transfer_id || !$origin_tx || !$status) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Missing fields']); exit; }

// Try to find local queued transfer
$stmt = $pdo->prepare("SELECT * FROM external_transfer_queue WHERE transaction_id = ? OR origin_transaction_id = ? LIMIT 1");
$stmt->execute([$transfer_id, $origin_tx]); $row = $stmt->fetch();

if (!$row) {
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE transaction_id = ? OR origin_transaction_id = ? LIMIT 1");
    $stmt->execute([$transfer_id, $origin_tx]); $row = $stmt->fetch();
}
if (!$row) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Local transfer not found']); exit; }

try {
    if ($status === 'approved') {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE external_transfer_queue SET status='approved', processed_at = NOW() WHERE transaction_id = ?");
        $stmt->execute([$transfer_id]);
        $stmt = $pdo->prepare("UPDATE transactions SET status='completed', completed_at = NOW() WHERE transaction_id = ? OR origin_transaction_id = ?");
        $stmt->execute([$transfer_id, $origin_tx]);
        $pdo->commit();
        echo json_encode(['status'=>'success','message'=>'Marked approved']);
        exit;
    } elseif ($status === 'rejected') {
        $pdo->beginTransaction();
        // refund sender (adjust to your schema)
        $sourceAccount = $row['source_account'] ?? $row['account_number'] ?? null;
        if ($sourceAccount) {
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_number = ?");
            $stmt->execute([$amount, $sourceAccount]);
        }
        $stmt = $pdo->prepare("UPDATE external_transfer_queue SET status='rejected', processed_at = NOW() WHERE transaction_id = ?");
        $stmt->execute([$transfer_id]);
        $stmt = $pdo->prepare("UPDATE transactions SET status='failed', completed_at = NOW() WHERE transaction_id = ? OR origin_transaction_id = ?");
        $stmt->execute([$transfer_id, $origin_tx]);
        $pdo->commit();
        echo json_encode(['status'=>'success','message'=>'Refunded and marked rejected']);
        exit;
    } else {
        http_response_code(400); echo json_encode(['status'=>'error','message'=>'Unknown status']); exit;
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500); echo json_encode(['status'=>'error','message'=>$e->getMessage()]); exit;
}
