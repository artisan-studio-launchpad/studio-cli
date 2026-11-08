<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Terminal;

use Closure;

final class Action
{
    public const string CONFIRM = 'confirm';

    public const string CANCEL = 'cancel';

    private string $label;

    private ?Closure $action = null;

    private ?Closure $options = null;

    private ?Closure $visible = null;

    private ?string $confirmation = null;

    private ?string $question = null;

    private ?string $asks = null;

    private bool $secret = false;

    private bool $optional = false;

    private ?string $then = null;

    private bool|Closure $closes = false;

    private bool|Closure $goesBack = false;

    private function __construct(private readonly string $name)
    {
        $this->label = $name;
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function action(Closure $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function options(Closure $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function visible(Closure $visible): self
    {
        $this->visible = $visible;

        return $this;
    }

    public function requiresConfirmation(string $question): self
    {
        $this->confirmation = $question;

        return $this;
    }

    /**
     * A question shown above the choices of an action, whose own choices say
     * what was picked. Unlike a confirmation, every choice comes back to the
     * action, so a no can be told from a yes.
     */
    public function question(string $text): self
    {
        $this->question = $text;

        return $this;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function asks(string $hint, bool $secret = false, bool $optional = false): self
    {
        $this->asks = $hint;
        $this->secret = $secret;
        $this->optional = $optional;

        return $this;
    }

    public function then(string $action): self
    {
        $this->then = $action;

        return $this;
    }

    public function closes(bool|Closure $closes = true): self
    {
        $this->closes = $closes;

        return $this;
    }

    public function goesBack(bool|Closure $goesBack = true): self
    {
        $this->goesBack = $goesBack;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getConfirmation(): ?string
    {
        return $this->confirmation;
    }

    public function getAsk(): ?string
    {
        return $this->asks;
    }

    public function isSecret(): bool
    {
        return $this->secret;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function getThen(): ?string
    {
        return $this->then;
    }

    public function shouldClose(): bool
    {
        return (bool) ($this->closes instanceof Closure ? ($this->closes)() : $this->closes) || $this->shouldGoBack();
    }

    public function shouldGoBack(): bool
    {
        return (bool) ($this->goesBack instanceof Closure ? ($this->goesBack)() : $this->goesBack);
    }

    public function isVisible(mixed $state): bool
    {
        return $this->visible === null || (bool) ($this->visible)($state);
    }

    /**
     * @return array<string, string>|null
     */
    public function getChoices(mixed $state): ?array
    {
        return match (true) {
            $this->options !== null => array_map(strval(...), (array) ($this->options)($state)),
            $this->confirmation !== null => [self::CONFIRM => $this->label, self::CANCEL => 'Cancel'],
            default => null,
        };
    }

    public function run(?string $choice = null): ?string
    {
        $said = $this->action === null ? null : ($this->action)($choice);

        return is_string($said) ? $said : null;
    }
}
