<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SubmitAiTaskRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        private readonly string $project,
        private readonly string $task,
        private readonly array $report,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/ai/tasks/'.$this->task;
    }

    protected function defaultBody(): array
    {
        return $this->report;
    }
}
