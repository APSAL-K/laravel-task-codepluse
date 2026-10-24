<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isCancellable(): bool
    {
        return $this->order_status === 'pending' && $this->payment_status === 'unpaid';
    }

    public function hasInvoice(): bool
    {
        return $this->payment_status === 'paid' && $this->order_status === 'confirmed';
    }

    public function invoiceNumber(): string
    {
        return str_replace('ORD-', 'INV-', $this->order_number);
    }

    public function invoiceUrl(): ?string
    {
        return $this->hasInvoice() ? url('/invoice/'.$this->order_number) : null;
    }
}
