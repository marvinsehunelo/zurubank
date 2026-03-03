<?php
require '../config/db.php';

header('Content-Type: text/csv');
header('Content-Disposition: attachment;filename=settlement.csv');

$output = fopen('php://output', 'w');

$stmt = $pdo->query("SELECT * FROM interbank_clearing_positions WHERE settlement_status='PENDING'");

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row);
}

fclose($output);
exit;
