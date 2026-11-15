<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColour;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasDescription;
use Closure;

final class Alert extends Component
{
    use HasColour;
    use HasDescription;

    private function __construct(private readonly string|Closure $title) {}

    public static function make(string|Closure $title): self
    {
        return new self($title);
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        $title = (string) $this->evaluate($this->title, $state);

        if ($title === '') {
            return [];
        }

        $accent = $this->getColour($state);
        $inside = $width - 6;
        $side = $canvas->span('│', $accent);
        $line = fn (string $content): string => $side.$canvas->span('  ', 'ink').$canvas->cell($content, $inside).$canvas->span('  ', 'ink').$side;
        $heading = $canvas->wrap($title, $inside);
        $description = (string) $this->getDescription($state);
        $body = $description === '' ? [] : $canvas->wrap($description, $inside);

        return [
            $canvas->span('╭'.str_repeat('─', $width - 2).'╮', $accent),
            ...array_map(fn (string $text): string => $line($canvas->span($text, $accent, bold: true)), $heading),
            ...array_map(fn (string $text): string => $line($canvas->span($text, $this->getDescriptionColour($state))), $body),
            $canvas->span('╰'.str_repeat('─', $width - 2).'╯', $accent),
        ];
    }
}
