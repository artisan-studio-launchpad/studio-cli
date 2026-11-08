<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class AuthorizeScanRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(private readonly string $project) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/authorize';
    }
}
