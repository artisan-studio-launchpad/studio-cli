<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Record
{
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'settled' => 'boolean',
            'lines' => 'array',
        ];
    }

    protected function totalInPounds(): Attribute
    {
        return Attribute::get(fn (): float => $this->total / 100);
    }
}
