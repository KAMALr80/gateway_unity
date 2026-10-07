<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'erp_order_id',
        'invoice_no',
        'order_key',
        'created_via',
        'version',
        'transaction_date',
        'user_id',
        'erp_customer_id',
        'status',
        'payment_status',
        'erp_status',
        'erp_shipping_status',
        'erp_payment_status',
        'currency',
        'line_total',
        'discount_total',
        'shipping_total',
        'total_tax',
        'total',
        'billing_first_name',
        'billing_last_name',
        'billing_company',
        'billing_address_1',
        'billing_address_2',
        'billing_city',
        'billing_state',
        'billing_postcode',
        'billing_country',
        'billing_email',
        'billing_phone',
        'shipping_first_name',
        'shipping_last_name',
        'shipping_company',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_city',
        'shipping_state',
        'shipping_postcode',
        'shipping_country',
        'customer_ip_address',
        'customer_user_agent',
        'customer_note',
        'meta_data',
        'sync_status',
        'sync_attempts',
        'last_sync_attempt_at',
        'sync_error',
        'synced_at',
    ];

    protected $casts = [
        'transaction_date'      => 'datetime',
        'line_total'            => 'decimal:2',
        'discount_total'        => 'decimal:2',
        'shipping_total'        => 'decimal:2',
        'total_tax'             => 'decimal:2',
        'total'                 => 'decimal:2',
        'meta_data'             => 'array',
        'sync_attempts'         => 'integer',
        'last_sync_attempt_at'  => 'datetime',
        'synced_at'             => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    // Computed full name helpers
    public function getBillingFullNameAttribute(): string
    {
        return trim("{$this->billing_first_name} {$this->billing_last_name}");
    }

    public function getShippingFullNameAttribute(): string
    {
        return trim("{$this->shipping_first_name} {$this->shipping_last_name}");
    }

    public function isSynced(): bool
    {
        return $this->sync_status === 'synced';
    }

    public function hasFailed(): bool
    {
        return $this->sync_status === 'failed';
    }

    public function needsSync(): bool
    {
        return in_array($this->sync_status, ['pending', 'failed'])
            && $this->sync_attempts < 3;
    }
}