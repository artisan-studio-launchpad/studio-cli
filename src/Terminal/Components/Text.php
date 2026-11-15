<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Components;

use ArtisanStudio\StudioCli\Terminal\Canvas;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasColour;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasDescription;
use ArtisanStudio\StudioCli\Terminal\Components\Concerns\HasUrl;
use Closure;

final class Text extends Component
{
    use HasColour;
    use HasDescription;
    use HasUrl;

    private string|Closure $content = '';

    private bool $bold = false;

    private bool $wraps = false;

    private bool $centred = false;

    private string|Closure $linkLabel = '';

    private string|Closure $buttonLabel = '';

    private string $buttonAction = '';

    public static function make(string|Closure $content): self
    {
        $text = new self;
        $text->content = $content;

        return $text;
    }

    public function bold(bool $bold = true): self
    {
        $this->bold = $bold;

        return $this;
    }

    public function wrap(bool $wraps = true): self
    {
        $this->wraps = $wraps;

        return $this;
    }

    /**
     * Each wrapped line centred in the width it has.
     */
    public function center(bool $centred = true): self
    {
        $this->centred = $centred;

        return $this;
    }

    public function link(string|Closure $label, string|Closure|null $url): self
    {
        $this->linkLabel = $label;

        return $this->url($url);
    }

    public function button(string|Closure $label, string $action): self
    {
        $this->buttonLabel = $label;
        $this->buttonAction = $action;

        return $this;
    }

    public function render(Canvas $canvas, int $width, mixed $state): array
    {
        return $this->wraps ? $this->renderWrapped($canvas, $width, $state) : $this->renderLine($canvas, $width, $state);
    }

    /**
     * @return list<string>
     */
    private function renderWrapped(Canvas $canvas, int $width, mixed $state): array
    {
        $content = (string) $this->evaluate($this->content, $state);
        $description = (string) $this->getDescription($state);

        $indent = fn (string $line): string => $this->centred ? str_repeat(' ', max(0, intdiv($width - Canvas::visibleWidth($line), 2))).$line : $line;
        $url = $this->getUrl($state);

        return $content === '' && $url === null ? [] : [
            ...($content === '' ? [] : array_map(fn (string $line): string => $canvas->span($indent($line), $this->getColour($state), bold: $this->bold), $canvas->wrap($content, $width))),
            ...array_map(fn (string $line): string => $canvas->span($indent($line), $this->getDescriptionColour($state)), $description === '' ? [] : $canvas->wrap($description, $width)),
            ...($url === null ? [] : [$indent($canvas->link($canvas->span($url.' '.Canvas::OPENS, 'cyan', bold: true), $url))]),
        ];
    }

    /**
     * @return list<string>
     */
    private function renderLine(Canvas $canvas, int $width, mixed $state): array
    {
        $url = $this->getUrl($state);
        $text = $canvas->span((string) $this->evaluate($this->content, $state), $this->getColour($state), bold: $this->bold)
            .$canvas->span((string) $this->getDescription($state), $this->getDescriptionColour($state));
        $link = $url === null ? '' : $canvas->link($canvas->span($this->evaluate($this->linkLabel, $state).' '.Canvas::OPENS, 'cyan', bold: true), $url);
        $label = (string) $this->evaluate($this->buttonLabel, $state);
        $button = $label === '' ? '' : $canvas->button($label, $this->buttonAction, 'amber');
        $right = collect([implode($canvas->span('  ', 'ink'), array_filter([$button, $link])), $button])
            ->first(fn (string $right): bool => Canvas::visibleWidth($text) + Canvas::visibleWidth($right) < $width) ?? '';

        return [$canvas->spread($text, $right, $width)];
    }
}
