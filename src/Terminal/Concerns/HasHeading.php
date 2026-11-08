<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal\Concerns;

use Closure;

trait HasHeading
{
    private string|Closure $heading = '';

    private string|Closure|null $description = null;

    private string|Closure|null $aside = null;

    private string|Closure|null $status = null;

    private string|Closure $statusColour = 'soft';

    private string|Closure|null $notice = null;

    private string|Closure $noticeColour = 'soft';

    public function heading(string|Closure $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function aside(string|Closure|null $aside): static
    {
        $this->aside = $aside;

        return $this;
    }

    /**
     * A short status shown in its own colour before the aside, on every tab.
     */
    public function status(string|Closure|null $status, string|Closure $colour = 'soft'): static
    {
        $this->status = $status;
        $this->statusColour = $colour;

        return $this;
    }

    /**
     * A line of its own under the heading, on every tab, while it is not empty.
     */
    public function notice(string|Closure|null $notice, string|Closure $colour = 'soft'): static
    {
        $this->notice = $notice;
        $this->noticeColour = $colour;

        return $this;
    }

    public function getNotice(): string
    {
        return (string) $this->evaluate($this->notice);
    }

    public function getNoticeColour(): string
    {
        return (string) $this->evaluate($this->noticeColour);
    }

    public function getStatus(): string
    {
        return (string) $this->evaluate($this->status);
    }

    public function getStatusColour(): string
    {
        return (string) $this->evaluate($this->statusColour);
    }

    public function getHeading(): string
    {
        return (string) $this->evaluate($this->heading);
    }

    public function getDescription(): ?string
    {
        $description = $this->evaluate($this->description);

        return $description === null || $description === '' ? null : (string) $description;
    }

    public function getAside(): string
    {
        return (string) $this->evaluate($this->aside);
    }
}
