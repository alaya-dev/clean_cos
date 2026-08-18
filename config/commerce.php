<?php

return [
    // Store-management settings will replace these safe defaults in Milestone 5.
    'shipping_fixed_fee_millimes' => (int) env('COMMERCE_SHIPPING_FIXED_FEE_MILLIMES', 0),
    'shipping_free_threshold_millimes' => env('COMMERCE_SHIPPING_FREE_THRESHOLD_MILLIMES') === null ? null : (int) env('COMMERCE_SHIPPING_FREE_THRESHOLD_MILLIMES'),
];
