<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broken extends Record
{
    public function owner(): BelongsTo
    {
        return $this->belongsTo('ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models\Nowhere');
    }
}
