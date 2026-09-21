<?php
// zurubank/Backend/api/v1/settlement/advice.php
// VouchMorph's settlement advice for ZuruBank: pay each line (see settlement_desk.php).
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';
require_once __DIR__ . '/../../settlement_stores.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid JSON']); exit; }
if ($err = SettlementDesk::verifyVouchMorph($input, new CertificateManager('ZURUBANK'))) {
    http_response_code(401); echo json_encode(['success' => false, 'message' => $err]); exit;
}
try {
    echo json_encode(zurubank_desk($pdo)->receiveAdvice($input));
} catch (Throwable $e) {
    error_log('[settlement/advice] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'message' => 'Advice could not be processed']);
}
