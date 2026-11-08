<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

/**
 * What running one task came to.
 */
final readonly class Outcome
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, int|float|string>  $usage
     */
    public function __construct(
        public bool $ok,
        public ?string $text = null,
        public ?array $structured = null,
        public ?string $error = null,
        public array $usage = [],
    ) {}

    /**
     * @param  array<string, int|float|string>  $usage
     */
    public static function failed(string $error, array $usage = []): self
    {
        return new self(false, error: $error, usage: $usage);
    }

    /**
     * @return array<string, mixed>
     */
    public function toReport(): array
    {
        return array_filter([
            'status' => $this->ok ? 'done' : 'failed',
            'text' => $this->text,
            'structured' => $this->structured,
            'error' => $this->error,
            'usage' => $this->usage === [] ? null : $this->usage,
        ], fn (mixed $value): bool => $value !== null);
    }
}
