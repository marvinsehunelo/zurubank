<?php
require '../config/db.php';
require '../helpers/crypto.php';
require '../helpers/response.php';

$data = json_decode(file_get_contents("php://input"), true);

$wallet_id = $data['wallet_id'];
$amount = $data['amount'];

$pdo->beginTransaction();

$stmt = $pdo->prepare("SELECT balance FROM instant_money_wallets WHERE wallet_id=? FOR UPDATE");
$stmt->execute([$wallet_id]);
$wallet = $stmt->fetch();

if (!$wallet || $wallet['balance'] < $amount) {
    json_response("DECLINED");
}

$trace = generate_trace();
$auth_code = generate_auth_code();

$pdo->prepare("INSERT INTO wallet_locks(wallet_id, trace_number, amount, expires_at)
VALUES(?,?,?,NOW()+INTERVAL '15 minutes')")
->execute([$wallet_id, $trace, $amount]);

$pdo->prepare("INSERT INTO network_authorizations(trace_number, role, counterparty_bank, amount, auth_code, expiry_time)
VALUES(?,?,?,?,?,NOW()+INTERVAL '15 minutes')")
->execute([$trace, 'ISSUER', 'VOUCHMORPH', $amount, $auth_code]);

$pdo->commit();

json_response("AUTHORIZED", [
    "trace_number" => $trace,
    "auth_code" => $auth_code
]);
