<?php

return [
    'webpush' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:no-reply@example.test'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'sms' => [
        'provider' => env('SMS_PROVIDER', 'log'),
        'from' => env('SMS_FROM', 'Stuckbema'),
        'url' => env('SMS_URL'),
        'token' => env('SMS_TOKEN'),
        'elks_username' => env('SMS_46ELKS_USERNAME'),
        'elks_password' => env('SMS_46ELKS_PASSWORD'),
    ],

    'purchase_suppliers' => [
        'bauhaus_stockholm' => [
            'api_key' => env('BAUHAUS_API_KEY'),
            'api_secret' => env('BAUHAUS_API_SECRET'),
            'stock_location' => env('BAUHAUS_STOCK_LOCATION', 'Bromma'),
            'algolia_app_id' => env('BAUHAUS_ALGOLIA_APP_ID', 'TGPIEONN2S'),
            'algolia_api_key' => env('BAUHAUS_ALGOLIA_API_KEY', 'NTUwMmEzMjIzZWEwY2ZiOTliNjkwNDA4NGM5Yjc1MWM3NDc0NGIwZTQ4YjNjZDBiOTZlZGFiZTU0OWRjMjk5MnRhZ0ZpbHRlcnM9JnZhbGlkVW50aWw9MTc4MDYwNjczMg=='),
            'algolia_index' => env('BAUHAUS_ALGOLIA_INDEX', 'nordic_production_sv_products'),
        ],
        'bygma_stockholm' => [
            'api_key' => env('BYGMA_API_KEY'),
            'api_secret' => env('BYGMA_API_SECRET'),
            'stock_location' => env('BYGMA_STOCK_LOCATION', 'Bromma'),
        ],
        'jula_stockholm' => [
            'api_key' => env('JULA_API_KEY'),
            'api_secret' => env('JULA_API_SECRET'),
            'stock_location' => env('JULA_STOCK_LOCATION', 'Kungens Kurva'),
        ],
        'biltema_bredden' => [
            'api_key' => env('BILTEMA_API_KEY'),
            'api_secret' => env('BILTEMA_API_SECRET'),
            'stock_location' => env('BILTEMA_STOCK_LOCATION', 'Bredden'),
        ],
        'beijer_stockholm' => [
            'api_key' => env('BEIJER_API_KEY'),
            'api_secret' => env('BEIJER_API_SECRET'),
            'stock_location' => env('BEIJER_STOCK_LOCATION', 'Bromma'),
        ],
        'swedol_stockholm' => [
            'api_key' => env('SWEDOL_API_KEY'),
            'api_secret' => env('SWEDOL_API_SECRET'),
            'stock_location' => env('SWEDOL_STOCK_LOCATION', 'Stockholm'),
            'algolia_app_id' => env('SWEDOL_ALGOLIA_APP_ID', 'IMDE5JWBQM'),
            'algolia_api_key' => env('SWEDOL_ALGOLIA_API_KEY', 'NmRhNThiOWZlZjY3ZTYzZDc0YTgzNmEwYjZkYzhjNzNjY2Q4NjIzMDllYmVjMTNkZWFmODMxZDA4NmU2OTNiYWZpbHRlcnM9aXNfcHJvY3VyZW1lbnQlMjAlMjElM0QlMjAxJnRhZ0ZpbHRlcnM9JnZhbGlkVW50aWw9MTc4MDYwNjUwMw=='),
            'algolia_index' => env('SWEDOL_ALGOLIA_INDEX', 'mcprod_swedol_sek_sv_products'),
        ],
        'xlbygg_stockholm' => [
            'api_key' => env('XLBYGG_API_KEY'),
            'api_secret' => env('XLBYGG_API_SECRET'),
            'stock_location' => env('XLBYGG_STOCK_LOCATION', 'Stockholm'),
        ],
        'hornbach_stockholm' => [
            'api_key' => env('HORNBACH_API_KEY'),
            'api_secret' => env('HORNBACH_API_SECRET'),
            'stock_location' => env('HORNBACH_STOCK_LOCATION', 'Botkyrka'),
        ],
    ],
];
