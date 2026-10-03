<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Scan;

use PhpToken;

final class ClassFacts
{
    private const array DECLARES = [T_CLASS => 'class', T_INTERFACE => 'interface', T_TRAIT => 'trait', T_ENUM => 'enum'];

    private const array NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** @var list<PhpToken> */
    private array $tokens = [];

    private int $at = 0;

    private string $namespace = '';

    /** @var array<string, string> */
    private array $imports = [];

    /**
     * @return array{kind: string, name: string, extends: list<string>, implements: list<string>, traits: list<string>}|null
     */
    public static function of(string $source): ?array
    {
        return (new self)->read($source);
    }

    /**
     * @return array{kind: string, name: string, extends: list<string>, implements: list<string>, traits: list<string>}|null
     */
    private function read(string $source): ?array
    {
        $this->tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));

        for ($this->at = 0; $this->at < count($this->tokens); $this->at++) {
            $token = $this->tokens[$this->at];

            if ($token->is(T_NAMESPACE)) {
                $this->namespace = $this->nameAfter();

                continue;
            }

            if ($token->is(T_USE)) {
                $this->import();

                continue;
            }

            if ($token->is(array_keys(self::DECLARES)) && ! $this->isAReference()) {
                return $this->declaration(self::DECLARES[$token->id]);
            }
        }

        return null;
    }

    private function isAReference(): bool
    {
        $previous = $this->tokens[$this->at - 1] ?? null;

        return $previous !== null && $previous->is([T_DOUBLE_COLON, T_NEW]);
    }

    /**
     * @return array{kind: string, name: string, extends: list<string>, implements: list<string>, traits: list<string>}
     */
    private function declaration(string $kind): array
    {
        $name = $this->nameAfter();
        $extends = [];
        $implements = [];

        while (isset($this->tokens[$this->at]) && $this->tokens[$this->at]->text !== '{') {
            $token = $this->tokens[$this->at];
            $extends = $token->is(T_EXTENDS) ? $this->namesAfter() : $extends;
            $implements = $token->is(T_IMPLEMENTS) ? $this->namesAfter() : $implements;
            $this->at += $token->is([T_EXTENDS, T_IMPLEMENTS]) ? 0 : 1;
        }

        return [
            'kind' => $kind,
            'name' => $this->resolve($name),
            'extends' => array_map($this->resolve(...), $extends),
            'implements' => array_map($this->resolve(...), $implements),
            'traits' => array_map($this->resolve(...), $this->traitsInBody()),
        ];
    }

    /**
     * @return list<string>
     */
    private function traitsInBody(): array
    {
        $depth = 0;
        $traits = [];

        for (; isset($this->tokens[$this->at]); $this->at++) {
            $token = $this->tokens[$this->at];
            $depth += match (true) {
                $token->text === '{', $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) => 1,
                $token->text === '}' => -1,
                default => 0,
            };

            if ($depth === 0 && $token->text === '}') {
                break;
            }

            $traits = $depth === 1 && $token->is(T_USE) ? [...$traits, ...$this->namesAfter()] : $traits;
        }

        return $traits;
    }

    private function import(): void
    {
        $names = $this->namesAfter(withAliases: true);
        $this->imports = [...$this->imports, ...$names];
    }

    private function nameAfter(): string
    {
        $this->at++;
        $name = '';

        while (isset($this->tokens[$this->at]) && $this->tokens[$this->at]->is(self::NAMES)) {
            $name .= $this->tokens[$this->at]->text;
            $this->at++;
        }

        return $name;
    }

    /**
     * @return ($withAliases is true ? array<string, string> : list<string>)
     */
    private function namesAfter(bool $withAliases = false): array
    {
        $this->at++;
        $names = [];
        $current = '';
        $alias = null;

        for (; isset($this->tokens[$this->at]); $this->at++) {
            $token = $this->tokens[$this->at];

            if ($token->text === ';' || $token->text === '{' || $token->is(T_IMPLEMENTS)) {
                break;
            }

            if ($token->text === ',') {
                $names = $this->withName($names, $current, $alias, $withAliases);
                [$current, $alias] = ['', null];

                continue;
            }

            $alias = $token->is(T_AS) ? '' : ($alias === '' && $token->is(T_STRING) ? $token->text : $alias);
            $current .= $alias === null && $token->is(self::NAMES) ? $token->text : '';
        }

        $names = $this->withName($names, $current, $alias, $withAliases);

        return $withAliases ? array_combine(array_map(strval(...), array_keys($names)), $names) : array_values($names);
    }

    /**
     * @param  array<int|string, string>  $names
     * @return array<int|string, string>
     */
    private function withName(array $names, string $name, ?string $alias, bool $withAliases): array
    {
        if ($name === '') {
            return $names;
        }

        if (! $withAliases) {
            return [...$names, $name];
        }

        $short = $alias ?: substr((string) strrchr('\\'.$name, '\\'), 1);

        return [...$names, $short => ltrim($name, '\\')];
    }

    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $first = strstr($name, '\\', true) ?: $name;

        if (isset($this->imports[$first])) {
            return $this->imports[$first].substr($name, strlen($first));
        }

        return ltrim($this->namespace.'\\'.$name, '\\');
    }
}
