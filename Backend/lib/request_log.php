<?php
// Backend/lib/request_log.php
//
// What may be written to the log about a request body: its action and the
// names of its fields, never their values. A body sent by VouchMorph carries
// its certificate and signature, and for a voucher or wallet a customer's
// PIN; none of that belongs in a log.

if (!function_exists('request_shape_for_log')) {
    function request_shape_for_log($input): string
    {
        if (!is_array($input)) {
            return 'body=(not a JSON object)';
        }
        $safe = static function ($value, int $max): string {
            return substr((string)preg_replace('/[^\x20-\x7E]/', '?', is_scalar($value) ? (string)$value : ''), 0, $max);
        };
        $action = isset($input['action']) && is_string($input['action']) ? $safe($input['action'], 32) : '(none)';
        return 'action=' . $action . ', fields=' . $safe(implode(',', array_map('strval', array_keys($input))), 300);
    }
}

if (!function_exists('masked_for_log')) {
    /**
     * A voucher, card or account number as it may be written to a log: its
     * last four characters only. A number guessed from a request's fields can
     * turn out to be a PIN, so the whole value is never logged.
     */
    function masked_for_log($value): string
    {
        $text = is_scalar($value) ? preg_replace('/[^\x20-\x7E]/', '?', (string)$value) : '';
        $text = (string)$text;
        return strlen($text) <= 4 ? str_repeat('*', strlen($text)) : '***' . substr($text, -4);
    }
}
