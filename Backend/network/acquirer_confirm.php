<?php
header('Content-Type: application/json');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../helpers/crypto.php';
require __DIR__ . '/../helpers/response.php';

// Read JSON input safely
$data = json_decode(file_get_contents("php://input"), true);
$trace = $data['trace_number'] ?? null;
$atm_id = $data['atm_id'] ?? 'VIRTUALATM';

// Validate input
if (!$trace) {
    json_response("DECLINED", ["message" => "Missing trace_number"]);
}

// Fetch pre-advice / authorization
$stmt = $pdo->prepare("SELECT * FROM incoming_pre_advice WHERE trace_number=?");
$stmt->execute([$trace]);
$record = $stmt->fetch();

if (!$record) {
    json_response("DECLINED", ["message" => "Trace not found"]);
}

// Check status
if ($record['status'] == 'COMPLETED') {
    // Idempotent: return existing cashout info
    $cashout_info = [
        "trace_number" => $record['trace_number'],
        "amount"       => $record['amount'],
        "atm_id"       => $atm_id,
        "reference"    => $record['cashout_reference'],
        "timestamp"    => $record['cashout_created_at']
    ];
    json_response("CASHOUT_READY", $cashout_info);
}

if ($record['status'] != 'AUTHORIZED') {
    json_response("DECLINED", ["message" => "Pre-advice not authorized"]);
}

// Generate cashout info
$cashout_info = [
    "trace_number" => $record['trace_number'],
    "amount"       => $record['amount'],
    "atm_id"       => $atm_id,
    "reference"    => generate_trace(), // unique payout reference
    "timestamp"    => date("Y-m-d H:i:s")
];

// Mark pre-advice as completed and store cashout reference & timestamp
$update = $pdo->prepare("UPDATE incoming_pre_advice 
                         SET status='COMPLETED', cashout_reference=?, cashout_created_at=NOW()
                         WHERE trace_number=?");
$update->execute([$cashout_info['reference'], $trace]);

json_response("CASHOUT_READY", $cashout_info);
