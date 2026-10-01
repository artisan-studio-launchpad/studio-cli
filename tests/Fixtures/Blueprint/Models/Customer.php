<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Record
{
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
