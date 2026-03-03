<?php
require '../config/db.php';
require '../helpers/response.php';

$data = json_decode(file_get_contents("php://input"), true);
$trace = $data['trace_number'];

$pdo->beginTransaction();

$lock = $pdo->query("SELECT * FROM wallet_locks WHERE trace_number='$trace' FOR UPDATE")->fetch();

if (!$lock) json_response("NOT_FOUND");

$pdo->prepare("UPDATE instant_money_wallets 
SET balance = balance - ? WHERE wallet_id=?")
->execute([$lock['amount'], $lock['wallet_id']]);

$pdo->prepare("UPDATE wallet_locks SET status='USED' WHERE trace_number=?")
->execute([$trace]);

$pdo->commit();

json_response("FINALIZED");
