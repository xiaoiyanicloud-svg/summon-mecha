<?php

namespace Webkul\Shop\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 客户常用发票抬头簿（支持个人抬头、企业增值税普通发票、企业增值税专用发票）。
 */
class CustomerInvoiceHeader extends Model
{
    protected $table = 'customer_invoice_headers';

    public const TYPE_PERSONAL = 'personal';

    public const TYPE_COMPANY = 'company';

    public const KIND_ORDINARY = 'ordinary';

    public const KIND_SPECIAL = 'special';

    protected $fillable = [
        'customer_id',
        'type',
        'invoice_kind',
        'title',
        'tax_no',
        'receive_email',
        'receive_phone',
        'bank_name',
        'bank_account',
        'company_address',
        'company_phone',
        'is_default',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'is_default'  => 'boolean',
    ];
}
