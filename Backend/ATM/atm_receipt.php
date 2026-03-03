<?php
/**
 * ATM Receipt Generator
 * Generates a JSON-ready receipt for a cash dispense transaction.
 *
 * @param array $dispenseData  Data from atm_dispense()
 * @return array               Receipt details
 */
function atm_receipt(array $dispenseData): array
{
    // Validate required fields
    if (!isset($dispenseData['swapRef']) || !isset($dispenseData['amount']) || !isset($dispenseData['currency'])) {
        throw new Exception('Missing required dispense data');
    }

    // Generate a unique receipt number
    $receiptNumber = 'RCPT-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();

    // Generate timestamp
    $timestamp = date('Y-m-d H:i:s');

    // Build receipt
    $receipt = [
        'receipt_number' => $receiptNumber,
        'swap_reference' => $dispenseData['swapRef'],
        'amount'         => $dispenseData['amount'],
        'currency'       => $dispenseData['currency'],
        'dispensed_to'   => $dispenseData['account'] ?? 'UNKNOWN',
        'atm_id'         => $dispenseData['atm_id'] ?? 'ATM-001',
        'timestamp'      => $timestamp,
        'status'         => 'SUCCESS',
    ];

    return $receipt;
}
