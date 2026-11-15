<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Saloon;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * Asks the AI on this machine how much of its usage is left, with the login
 * already here. The login is used for this one question and goes nowhere else:
 * not into the studio, not into a file.
 */
class ProviderUsageConnector extends Connector
{
    use AcceptsJson;
    use HasTimeout;

    protected int $connectTimeout = 4;

    protected int $requestTimeout = 5;

    public function __construct(private readonly string $accessToken) {}

    public function resolveBaseUrl(): string
    {
        return (string) config('studio-cli.bridge.usage_url', 'https://api.anthropic.com');
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->accessToken,
            'anthropic-beta' => (string) config('studio-cli.bridge.usage_beta', 'oauth-2025-04-20'),
        ];
    }
}
