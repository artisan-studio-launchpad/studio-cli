<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models;

use Illuminate\Database\Eloquent\Model;

abstract class Record extends Model
{
    public $timestamps = false;
}
