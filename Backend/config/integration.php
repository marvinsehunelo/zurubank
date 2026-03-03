<?php
// backend/config/integration.php — CazaCom/ZuruBank integration numbers

return [
    // CazaCom SMS REST API
    // 💥 FIX: Change the placeholder URL to the likely local development URL
    'CAZACOM_SMS_URL' => 'http://localhost/cazacom/api.php?path=sms/send', 
    'CAZACOM_API_KEY' => 'ZURUBANK_API_KEY_ABC123',
    'CAZACOM_WEBHOOK_SECRET' => 'REPLACE_WITH_SHARED_SECRET_FOR_USSD',

    // System / test numbers — these now exist in CazaCom database
    'SYSTEM_SENDER_NUMBER'     => '+26770012345',    // ZuruBank system sender
    'TEST_RECIPIENT_NUMBER_1'  => '+26771111111',    // Test recipient 1
    'TEST_RECIPIENT_NUMBER_2'  => '+26772222222',    // Test recipient 2
];