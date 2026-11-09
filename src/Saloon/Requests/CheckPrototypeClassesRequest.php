<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * The class names a prototype page uses, and nothing else of it: the studio
 * says which of them are worth checking against the app's stylesheets.
 */
class CheckPrototypeClassesRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  list<string>  $used
     */
    public function __construct(
        private readonly string $project,
        private readonly array $used,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/scan/prototype/classes';
    }

    /**
     * @return array{used: list<string>}
     */
    protected function defaultBody(): array
    {
        return ['used' => $this->used];
    }
}
