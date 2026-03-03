<?php
return [
    /*
     |--------------------------------------------------------------------------
     | Environment
     |--------------------------------------------------------------------------
     | 'production', 'staging', 'development'
     */
    'environment' => 'production',

    /*
     |--------------------------------------------------------------------------
     | Timezone (all times stored in UTC)
     |--------------------------------------------------------------------------
     */
    'timezone' => 'UTC',

    /*
     |--------------------------------------------------------------------------
     | Default Limits (can be overridden per country)
     |--------------------------------------------------------------------------
     */
    'limits' => [
        'min_swap_amount' => 1.00,   // minimum voucher/ewallet amount
        'max_swap_amount' => 10000.00, // maximum voucher/ewallet amount
        'default_expiry_hours' => 24, // default swap expiry
    ],

    /*
     |--------------------------------------------------------------------------
     | Default Currency (can be overridden per country)
     |--------------------------------------------------------------------------
     */
    'currency' => 'USD',

    /*
     |--------------------------------------------------------------------------
     | Fee Structure (defaults; negotiator adjustable)
     |--------------------------------------------------------------------------
     */
    'fees' => [
        'creation_fee' => 10.00,        // highest ewallet/voucher creation fee in default currency
        'swap_fee'     => 0.20,         // 20% of creation_fee
        'admin_fee'    => 0.01,         // 1% of creation_fee
        'sms_fee'      => 0.01,         // 1% of creation_fee
    ],

    /*
     |--------------------------------------------------------------------------
     | Fee Split Rules (numeric, auditable)
     |--------------------------------------------------------------------------
     | Split ratios are applied to fee components. Must sum appropriately.
     */
    'split' => [
        'used_swap' => [
            'bank_share'      => 0.60,   // 60% of swap+admin fee
            'middleman_share' => 0.40,   // 40% of swap+admin fee
            'sms_share'       => 1.00,   // 100% of sms fee
        ],
        'unused_swap' => [
            'bank_share'      => 0.60,   // 60% of total fees
            'middleman_share' => 0.40,   // 40% of total fees
            'sms_share'       => 0.00,   // no sms fee
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | Payer-of-Record Rules
     |--------------------------------------------------------------------------
     | Determines who pays first swap (sender/receiver) and subsequent swaps.
     */
    'payer_of_record' => [
        'sender_pays_first' => true,  // if true, receiver’s first swap is free
        'receiver_pays_after_first' => true, // subsequent swaps always paid by receiver
        'no_sender_funding' => true,  // if sender never pre-funds, receiver pays from start
    ],

    /*
     |--------------------------------------------------------------------------
     | Negotiator Adjustable (can be updated after agreements)
     |--------------------------------------------------------------------------
     */
    'negotiator_adjustable' => [
        'limits',
        'fees',
        'split',
        'payer_of_record',
    ],
];
