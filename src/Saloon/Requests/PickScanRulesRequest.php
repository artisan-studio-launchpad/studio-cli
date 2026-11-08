<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class PickScanRulesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $tools
     */
    public function __construct(
        private readonly string $project,
        private readonly array $tools,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/picks';
    }

    /**
     * @return array{tools: list<string>}
     */
    protected function defaultBody(): array
    {
        return ['tools' => $this->tools];
    }
}
