<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ClaimAiTaskRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $project) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/ai/tasks/next';
    }
}
