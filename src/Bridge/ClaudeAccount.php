<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * Whether Claude Code is on this machine and signed in, and as whom.
 *
 * `claude auth status` prints the account as JSON: the email, the organization
 * and the plan. They are only shown to the developer here and are never sent
 * to the studio.
 */
final class ClaudeAccount
{
    private const int KEPT_SECONDS = 30;

    /**
     * @var array{at: int, status: array{installed: bool, signed_in: bool, email: ?string, organization: ?string, plan: ?string, config_directory: ?string}}|null
     */
    private ?array $known = null;

    public function __construct(private readonly Shell $shell) {}

    /**
     * @return array{installed: bool, signed_in: bool, email: ?string, organization: ?string, plan: ?string, config_directory: ?string}
     */
    public function status(): array
    {
        if ($this->known !== null && time() - $this->known['at'] < self::KEPT_SECONDS) {
            return $this->known['status'];
        }

        $this->known = ['at' => time(), 'status' => $this->read()];

        return $this->known['status'];
    }

    public function forget(): void
    {
        $this->known = null;
    }

    /**
     * Who Claude Code is signed in as, for a line on the screen: the email,
     * else the organization, with the organization and plan after it. Null when
     * it is not signed in, or says nothing about who.
     */
    public function who(): ?string
    {
        $status = $this->status();
        $name = $status['email'] ?? $status['organization'];

        if (! $status['signed_in'] || $name === null) {
            return null;
        }

        $more = array_values(array_filter([$status['email'] === null ? null : $status['organization'], $status['plan']]));

        return $more === [] ? $name : $name.' ('.implode(', ', $more).')';
    }

    /**
     * @return array{installed: bool, signed_in: bool, email: ?string, organization: ?string, plan: ?string, config_directory: ?string}
     */
    private function read(): array
    {
        $none = ['installed' => false, 'signed_in' => false, 'email' => null, 'organization' => null, 'plan' => null, 'config_directory' => null];

        if ($this->shell->find('claude') === null) {
            return $none;
        }

        $ran = $this->shell->run(['claude', 'auth', 'status'], '', sys_get_temp_dir(), 20);
        $json = json_decode($ran['out'], true);
        $json = is_array($json) ? $json : [];
        $text = fn (string $key): ?string => is_string($json[$key] ?? null) && $json[$key] !== '' ? $json[$key] : null;

        return [
            'installed' => true,
            'signed_in' => $ran['exit'] === 0 && ($json['loggedIn'] ?? false) === true,
            'email' => $text('email'),
            'organization' => $text('orgName'),
            'plan' => $text('subscriptionType'),
            'config_directory' => $text('configDirectory'),
        ];
    }
}
