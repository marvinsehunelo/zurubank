<?php
require '../config/db.php';

$result = $pdo->query("
SELECT debtor_bank, creditor_bank, SUM(amount) as total
FROM interbank_clearing_positions
WHERE settlement_status='PENDING'
GROUP BY debtor_bank, creditor_bank
");

echo json_encode($result->fetchAll(PDO::FETCH_ASSOC));
