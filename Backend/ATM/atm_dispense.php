<?php
// /opt/lampp/htdocs/zurubank/Backend/ATM/atm_dispense.php
header('Content-Type: application/json');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

function atm_dispense($data) {
    $trace = $data['trace_number'] ?? $_GET['trace'] ?? null;
    
    if (!$trace) {
        throw new Exception("Missing trace number");
    }
    
    // Call acquirer_confirm
    $context = stream_context_create([
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/json\r\n",
            "content" => json_encode(["trace_number" => $trace, "atm_id" => "VIRTUALATM001"])
        ]
    ]);
    
    $response = @file_get_contents("http://localhost/zurubank/Backend/network/acquirer_confirm.php", false, $context);
    
    if ($response === false) {
        throw new Exception("Failed to contact API");
    }
    
    $result = json_decode($response, true);
    
    if (!is_array($result)) {
        throw new Exception("Invalid JSON from API");
    }
    
    if ($result['status'] == "CASHOUT_READY") {
        return $result['data'];
    } else {
        throw new Exception($result['data']['message'] ?? "Cashout Declined");
    }
}

// If called directly
if (basename($_SERVER['SCRIPT_FILENAME']) == basename(__FILE__)) {
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_GET;
        $result = atm_dispense($data);
        echo json_encode(['status' => 'success', 'data' => $result]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}
