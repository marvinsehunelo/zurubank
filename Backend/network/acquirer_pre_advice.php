<?php
header('Content-Type: application/json');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/../helpers/response.php';

$data = json_decode(file_get_contents("php://input"), true);

$pdo->prepare("INSERT INTO incoming_pre_advice(trace_number, issuer_bank, amount, expiry_time, token_hash)
VALUES(?,?,?,?,?)")
->execute([
    $data['trace_number'],
    $data['issuer_bank'],
    $data['amount'],
    $data['expiry_time'],
    $data['token_hash']
]);

json_response("RECEIVED");
