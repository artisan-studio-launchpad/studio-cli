<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class SubmitRepoHistoryRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  array{commit: ?string, total: int, conventional: bool, commits: list<array<string, mixed>>}  $history
     */
    public function __construct(
        private readonly string $project,
        private readonly array $history,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects/'.$this->project.'/repo-history';
    }

    /**
     * @return array{commit: ?string, total: int, conventional: bool, commits: list<array<string, mixed>>}
     */
    protected function defaultBody(): array
    {
        return $this->history;
    }
}
