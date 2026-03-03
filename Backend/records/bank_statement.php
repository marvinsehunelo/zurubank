<?php
require_once(__DIR__ . '/../includes/secure_api_header.php'); // $pdo, $user_id
require_once(__DIR__ . '/../includes/tcpdf/tcpdf.php'); // TCPDF library

header('Content-Type: application/pdf');

// Optional: Get dates from GET or POST
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date   = $_GET['end_date'] ?? date('Y-m-d');

// Fetch user info
$stmt = $pdo->prepare("SELECT name, phone FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch transactions
$stmt = $pdo->prepare("
    SELECT created_at, type, amount, status, description 
    FROM transactions 
    WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id, $start_date, $end_date]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize TCPDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('ZuruBank');
$pdf->SetAuthor('ZuruBank');
$pdf->SetTitle('ZuruBank Statement');

// Sharp-edged header
$pdf->SetHeaderData('', 0, 'ZuruBank', "Statement for {$user['name']} ({$user['phone']})\nPeriod: $start_date to $end_date");
$pdf->setHeaderFont(['helvetica', '', 11]);
$pdf->setFooterFont(['helvetica', '', 9]);
$pdf->SetMargins(15, 25, 15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// Build table with ZuruBank styling
$html = '<table border="1" cellpadding="6" style="border-collapse:collapse;">
<tr style="background-color:#1f2937; color:white; font-weight:bold;">
<th>Date</th>
<th>Type</th>
<th>Description</th>
<th>Status</th>
<th style="text-align:right;">Amount (P)</th>
</tr>';

foreach ($transactions as $t) {
    $statusColor = $t['status'] === 'Completed' ? '#10B981' : '#EF4444'; // green/red
    $html .= '<tr>
    <td>'.date('Y-m-d', strtotime($t['created_at'])).'</td>
    <td>'.htmlspecialchars($t['type']).'</td>
    <td>'.htmlspecialchars($t['description']).'</td>
    <td style="color:'.$statusColor.';">'.htmlspecialchars($t['status']).'</td>
    <td style="text-align:right;">'.number_format($t['amount'],2).'</td>
    </tr>';
}

$html .= '</table>';

// Output table
$pdf->writeHTML($html, true, false, true, false, '');

// Footer summary
$pdf->Ln(6);
$total = array_sum(array_column($transactions, 'amount'));
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Write(0, "Total transactions in period: P" . number_format($total,2));

// Output PDF to browser
$pdf->Output('zurubank_statement.pdf', 'I');
