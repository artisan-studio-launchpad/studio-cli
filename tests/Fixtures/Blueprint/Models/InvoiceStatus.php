<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Tests\Fixtures\Blueprint\Models;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
}
