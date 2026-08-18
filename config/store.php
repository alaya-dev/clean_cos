<?php

return [
    'hero_active_limit' => 5,
    'reassurance_limit' => 4,
    'settings' => [
        'shipping.fixed_fee_millimes' => ['type' => 'integer', 'default' => 0, 'min' => 0],
        'shipping.free_threshold_enabled' => ['type' => 'boolean', 'default' => false],
        'shipping.free_threshold_millimes' => ['type' => 'nullable_integer', 'default' => null, 'min' => 0],
        'checkout.promo_field_visible' => ['type' => 'boolean', 'default' => false],
        'checkout.schema_version' => ['type' => 'integer', 'default' => 1, 'min' => 1],
        'store.phone' => ['type' => 'nullable_string', 'default' => null, 'max' => 40],
        'store.email' => ['type' => 'nullable_string', 'default' => null, 'max' => 255],
        'store.address' => ['type' => 'nullable_string', 'default' => null, 'max' => 500],
        'store.whatsapp_url' => ['type' => 'nullable_string', 'default' => null, 'max' => 500],
        'store.social_links' => ['type' => 'array', 'default' => []],
        'store.announcement_text' => ['type' => 'nullable_string', 'default' => null, 'max' => 240],
        'store.footer_statement' => ['type' => 'nullable_string', 'default' => null, 'max' => 500],
        'store.hero_autoplay_enabled' => ['type' => 'boolean', 'default' => true],
    ],
    'approved_icons' => ['payment', 'phone', 'delivery', 'quality'],
    'social_hosts' => ['facebook.com', 'www.facebook.com', 'instagram.com', 'www.instagram.com'],
];
