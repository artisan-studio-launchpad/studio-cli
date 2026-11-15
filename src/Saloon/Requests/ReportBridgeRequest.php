<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ReportBridgeRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $project,
        private readonly string $state,
        private readonly ?string $answer = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/ai/bridge';
    }

    /**
     * @return array{state: string, runner: string, answer?: string}
     */
    protected function defaultBody(): array
    {
        return ['state' => $this->state, 'runner' => 'claude', ...($this->answer === null ? [] : ['answer' => $this->answer])];
    }
}
