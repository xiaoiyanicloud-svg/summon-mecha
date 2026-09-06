<?php

return [
    'checkout' => [
        'cart' => [
            'integrity' => [
                'qty-missing'   => 'At least one product should have more than 1 quantity.',
            ],

            'invalid-file-extension'   => 'Invalid file extension found.',
            'inventory-warning'        => 'The requested quantity is not available, please try again later.',
            'missing-links'            => 'Downloadable links are missing for this product.',
            'missing-options'          => 'Options are missing for this product.',
            'selected-products-simple' => 'Selected products must be of simple product type.',
        ],
    ],

    'datagrid' => [
        'copy-of-slug'                  => 'copy-of-:value',
        'copy-of'                       => 'Copy Of :value',
        'variant-already-exist-message' => 'Variant with same attribute options already exists.',
    ],

    'response' => [
        'product-can-not-be-copied' => 'Products of type :type can not be copied',
    ],

    'sort-by'  => [
        'options' => [
            'cheapest-first'  => 'Cheapest First',
            'expensive-first' => 'Expensive First',
            'from-a-z'        => 'From A-Z',
            'from-z-a'        => 'From Z-A',
            'latest-first'    => 'Newest First',
            'oldest-first'    => 'Oldest First',
        ],
    ],

    'type'     => [
        'abstract'     => [
            'offers' => 'Buy :qty for :price each and save :discount',
        ],

        'bundle'       => 'Bundle（套装/组合，机器人+配件打包卖）',
        'booking'      => 'Booking（预约/预订，含租赁——本次租用暂不用）',
        'configurable' => 'Configurable（多规格，同款多版本，如 Go2/Go2 Pro）',
        'downloadable' => 'Downloadable（可下载/数字文件，卖资料用）',
        'grouped'      => 'Grouped（分组/组合，多款机器人在一个分组下）',
        'simple'       => 'Simple（简单商品/单款本体，机器人首选）',
        'virtual'      => 'Virtual（虚拟商品，不发货）',
    ],
];
