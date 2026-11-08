<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Fix;

use ArtisanStudio\StudioCli\Bridge\BridgeGate;
use ArtisanStudio\StudioCli\Scan\ProjectReads;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The safety branch the studio's fixes land on, on this machine only.
 *
 * Nothing here pushes: for a pull request the branch's commits are read out
 * and sent to the studio, which puts them on GitHub. Untracked files are left
 * out of every commit unless a fixer names them as its own, such as the
 * assets a package update publishes.
 */
final class LocalBranch
{
    private const string PREFIX_SHAPE = '#^[A-Za-z0-9][A-Za-z0-9._/-]{1,59}$#';

    private const string NAME_SHAPE = '#^[A-Za-z0-9][A-Za-z0-9._-]{0,59}$#';

    /**
     * @var list<string>
     */
    public const array LOCK_FILES = ['composer.lock', 'pnpm-lock.yaml', 'package-lock.json', 'yarn.lock'];

    private const int QUICK_SECONDS = 15;

    private const int LARGEST_PATCH = 100_000;

    private const int MOST_PATCHES = 500;

    private const int LARGEST_SENT = 8_000_000;

    /**
     * @var list<string>
     */
    private const array SENT_MODES = ['100644', '100755', '120000'];

    private const string ENV = '/(^|\/)\.env(?!\.example$)(\.|$)/';

    public function __construct(private readonly string $root) {}

    /**
     * @return list<array{path: string, patch: string}>
     */
    public function changesSince(string $base): array
    {
        $diff = $this->git(['diff', '--no-color', '--no-ext-diff', '--unified=3', $base.'..HEAD', '--', ...$this->pathspecs([], self::LOCK_FILES)]);

        if ($diff === null || $diff === '') {
            return [];
        }

        return collect(preg_split('/^(?=diff --git )/m', $diff, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $patch): ?array => preg_match('~^diff --git a/.+? b/(.+)$~m', $patch, $file) === 1 && strlen($patch) <= self::LARGEST_PATCH ? ['path' => $file[1], 'patch' => rtrim($patch)."\n"] : null)
            ->filter()
            ->take(self::MOST_PATCHES)
            ->values()
            ->all();
    }

    /**
     * The same changes, as they may leave this machine: through the one
     * filter every file leaves by. A change to a file named like a secret, or
     * to a file whose patch holds a key or token, is left out. Used for what
     * is sent in a report; the changes this machine reads for itself stay
     * whole.
     *
     * @return list<array{path: string, patch: string}>
     */
    public function changesToSend(string $base): array
    {
        $reads = new ProjectReads($this->root);

        return array_values(array_filter($this->changesSince($base), fn (array $change): bool => $reads->mayLeave($change['path']) && ! $reads->holdsSecret($change['patch'])));
    }

    /**
     * What is changed and not committed in these files, as one patch. A patch
     * too large to read back whole is no patch at all.
     *
     * It is shown to the developer and sent with a question, so it goes
     * through the one filter every file leaves by: the change to a file named
     * like a secret, or whose patch holds a key or token, is left out of it,
     * file by file.
     *
     * @param  list<string>  $paths
     */
    public function patchOf(array $paths): string
    {
        $reads = new ProjectReads($this->root);
        $patches = array_filter(array_map(
            fn (string $path): string => $reads->mayLeave($path) ? (string) $this->git(['diff', '--no-color', '--no-ext-diff', '--unified=3', '--', $path]) : '',
            $paths,
        ), fn (string $patch): bool => $patch !== '' && ! $reads->holdsSecret($patch));
        $patch = implode("\n", $patches);

        return $patch === '' || strlen($patch) > self::LARGEST_PATCH * 2 ? '' : $patch."\n";
    }

    /**
     * Puts these files back as the last commit had them.
     *
     * @param  list<string>  $paths
     */
    public function restore(array $paths): bool
    {
        $paths === [] || BridgeGate::must();

        return $paths === [] || $this->git(['checkout', 'HEAD', '--', ...$paths]) !== null;
    }

    /**
     * The files that are not here as the project's remote has them: against
     * the scanned commit when the remote has it, or else the remote's own
     * default branch.
     *
     * @return list<string>|null
     */
    public function unlikeRemote(?string $scanned): ?array
    {
        $onRemote = $scanned !== null && ! in_array($this->git(['branch', '-r', '--contains', $scanned]), [null, ''], true);
        $ref = $onRemote ? $scanned : $this->git(['rev-parse', '--abbrev-ref', 'origin/HEAD']);
        $files = $ref === null || $ref === '' ? null : $this->git(['diff', '--name-only', $ref]);

        return $files === null ? null : array_values(array_filter(explode("\n", $files)));
    }

    public function isRepository(): bool
    {
        return $this->git(['rev-parse', '--is-inside-work-tree']) === 'true';
    }

    public function isClean(): bool
    {
        return $this->git(['status', '--porcelain', '--untracked-files=no']) === '';
    }

    public function head(): ?string
    {
        return $this->git(['rev-parse', 'HEAD']);
    }

    /**
     * What every fix branch's name starts with, from the config.
     */
    public static function fixPrefix(): string
    {
        return self::configured('fixes', self::PREFIX_SHAPE);
    }

    /**
     * What every build branch's name starts with, from the config.
     */
    public static function buildPrefix(): string
    {
        return self::configured('build', self::PREFIX_SHAPE);
    }

    /**
     * The scope the studio's own commits carry, as in feat(scope):.
     */
    public static function commitScope(): string
    {
        return self::configured('commit_scope', self::NAME_SHAPE);
    }

    public function isOnFixes(?string $branch = null): bool
    {
        $current = $this->current();

        return $current !== null && str_starts_with($current, self::fixPrefix()) && ($branch === null || $current === $branch);
    }

    /**
     * Whether this is on one of the studio's own branches: a fix branch or a build.
     */
    public function isOnStudioBranch(?string $branch = null): bool
    {
        $current = $this->current();

        return $current !== null && self::isStudioBranch($current) && ($branch === null || $current === $branch);
    }

    public static function isStudioBranch(string $branch): bool
    {
        return str_starts_with($branch, self::fixPrefix()) || str_starts_with($branch, self::buildPrefix());
    }

    /**
     * A setting under studio-cli.branches, refused unless it has the shape
     * of a plain name, so an empty prefix can never match every branch.
     */
    private static function configured(string $key, string $shape): string
    {
        $value = config('studio-cli.branches.'.$key);

        return is_string($value) && preg_match($shape, $value) === 1 && ! str_contains($value, '..')
            ? $value
            : throw new RuntimeException('studio-cli.branches.'.$key.' is not a plain name. Check config/studio-cli.php.');
    }

    public function current(): ?string
    {
        $branch = $this->git(['branch', '--show-current']);

        return $branch === null || $branch === '' ? null : $branch;
    }

    /**
     * Switches to a branch already on this machine, carrying nothing over: the
     * caller checks the tree is clean first.
     */
    public function switchTo(string $branch): bool
    {
        BridgeGate::must();

        return $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]) !== null && $this->git(['switch', $branch]) !== null;
    }

    public function start(string $stamp): ?string
    {
        return $this->startAt(self::fixPrefix().$stamp);
    }

    /**
     * Starts one of the studio's own branches by name, numbered on when that
     * name is taken.
     */
    public function startAt(string $name): ?string
    {
        BridgeGate::must();

        $free = self::isStudioBranch($name) ? collect(range(1, 9))
            ->map(fn (int $try): string => $name.($try === 1 ? '' : '-'.$try))
            ->first(fn (string $candidate): bool => $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$candidate]) === null) : null;

        return $free !== null && $this->git(['switch', '-c', $free]) !== null ? $free : null;
    }

    /**
     * The tracked files changed and not yet committed: only these, when
     * given, and never those excepted.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @return list<string>
     */
    public function changed(array $only = [], array $except = []): array
    {
        $files = $this->git(['diff', '--name-only', '--', ...$this->pathspecs($only, $except)]);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * The tracked lines that match, as path:line:text. A file the caller does
     * not allow to leave this machine is left out before any line is counted.
     *
     * @param  (Closure(string): bool)|null  $allows
     * @return array{matches: list<string>, cut: bool}
     */
    public function grep(string $pattern, ?string $in, int $most, string $secret, ?Closure $allows = null): array
    {
        $found = collect(explode("\n", (string) $this->git(['grep', '-n', '-I', '-E', '--no-color', '-e', $pattern, '--', $in ?? '.'])))
            ->filter(fn (string $line): bool => $line !== '' && preg_match($secret, (string) strstr($line, ':', true)) !== 1 && ($allows === null || $allows((string) strstr($line, ':', true))))
            ->map(fn (string $line): string => mb_substr($line, 0, 240))
            ->values();

        return ['matches' => $found->take($most)->all(), 'cut' => $found->count() > $most];
    }

    /**
     * @return list<string>
     */
    public function staged(): array
    {
        $files = $this->git(['diff', '--cached', '--name-only']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    public function lastChangeOf(string $path): string
    {
        return (string) $this->git(['log', '-1', '-p', '--no-color', '--format=', '--', $path]);
    }

    /**
     * The app files the repository tracks that hold this exact text, outside
     * the tests.
     *
     * @return list<string>
     */
    public function filesWith(string $text): array
    {
        $files = trim($text) === '' ? null : $this->git(['grep', '-l', '-F', '-e', $text, '--', 'app', 'resources', 'routes', 'config', 'database']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * The views and scripts that write any of these Livewire properties from
     * the browser: wire:model on it, $set or $wire on it, or entangle.
     *
     * @param  list<string>  $properties
     * @return list<string>
     */
    public function writersOf(array $properties): array
    {
        $names = implode('|', array_values(array_filter($properties, fn (string $name): bool => preg_match('/^\w+$/', $name) === 1)));
        $quote = '["\']';
        $pattern = implode('|', [
            'wire:model[.a-zA-Z0-9_-]*='.$quote.'('.$names.')['.'"\'.[]',
            '\$set\('.$quote.'('.$names.')['.'"\'.]',
            '\$wire\.(set\('.$quote.')?('.$names.')([^A-Za-z0-9_]|$)',
            'entangle\('.$quote.'('.$names.')['.'"\'.]',
        ]);
        $files = $names === '' ? null : $this->git(['grep', '-l', '-E', '-e', $pattern, '--', 'resources', 'app']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * The app classes the repository tracks that use this trait in their
     * body, such as `use HasActiveAgent;` or `use Foo, HasActiveAgent;`.
     *
     * @return list<string>
     */
    public function usersOfTrait(string $trait): array
    {
        $files = preg_match('/^\w+$/', $trait) !== 1 ? null : $this->git(['grep', '-l', '-E', '-e', '^[[:space:]]+use ([A-Za-z_]+, *)*'.$trait.' *[,;]', '--', 'app']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * Which of these files the repository tracks.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function tracked(array $paths): array
    {
        $files = $paths === [] ? '' : $this->git(['ls-files', '--', ...$paths]);

        return $files === null || $files === '' ? [] : array_values(array_intersect($paths, explode("\n", $files)));
    }

    /**
     * Files git does not track yet, and does not ignore.
     *
     * @return list<string>
     */
    public function untracked(): array
    {
        $files = $this->git(['ls-files', '--others', '--exclude-standard']);

        return $files === null || $files === '' ? [] : explode("\n", $files);
    }

    /**
     * Commits the changed files in scope, and the new ones named, as one
     * commit. Two fixers can run at once on the same branch, so staging and
     * committing hold a lock, and each stages only its own files.
     *
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @param  list<string>  $new
     */
    public function commit(string $subject, string $body = '', array $only = [], array $except = [], array $new = [], bool $byStudio = true): ?string
    {
        BridgeGate::must();

        $author = $byStudio ? config('studio-cli.author') : null;

        if (! $this->isOnStudioBranch()) {
            return null;
        }

        $gitDir = $this->git(['rev-parse', '--absolute-git-dir']);
        $lock = $gitDir === null ? false : fopen($gitDir.'/'.self::configured('lock', self::NAME_SHAPE), 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            return null;
        }

        try {
            $changed = $this->changed($only, $except);

            if (($changed === [] && $new === []) || ($changed !== [] && $this->git(['add', '--', ...$changed]) === null) || ($new !== [] && $this->git(['add', '--', ...$new]) === null)) {
                return null;
            }

            $committed = $this->git(['commit', '--quiet', ...(is_string($author) && $author !== '' ? ['--author', $author] : []), '-m', $subject, ...($body === '' ? [] : ['-m', $body])]);

            return $committed === null ? null : $this->git(['rev-parse', 'HEAD']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  list<string>  $only
     * @param  list<string>  $except
     * @return list<string>
     */
    private function pathspecs(array $only, array $except): array
    {
        return [...$only, ...($only === [] && $except !== [] ? ['.'] : []), ...array_map(fn (string $path): string => ':(exclude)'.$path, $except)];
    }

    /**
     * Brings what GitHub has of the base branch up to date. It never asks the
     * developer for a password or a key, and gives up after a few seconds: it
     * only improves what the pull request says, so a machine that cannot reach
     * GitHub still sends its branch.
     */
    public function fetched(string $into): void
    {
        $this->git(['remote', 'get-url', 'origin']) === null || $this->withoutAsking(['fetch', '--quiet', 'origin', $into]);
    }

    public function unpushed(string $into): int
    {
        $remote = $this->git(['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$into]);
        $local = $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$into]);

        return $remote === null || $local === null ? 0 : (int) $this->git(['rev-list', '--count', $remote.'..'.$local]);
    }

    /**
     * The commits on a fix branch that the branch it came from does not have,
     * exactly as git holds them, for the studio to put on GitHub: the commit
     * both share, then each commit with its author, committer, message and
     * the files it changed (base64, null when removed). A string says why the
     * branch cannot go.
     *
     * @return array{base: string, commits: list<array{sha: string, message: string, author: array{name: string, email: string, date: string}, committer: array{name: string, email: string, date: string}, signature: string|null, files: list<array{path: string, mode: string, content: string|null}>}>}|string
     */
    public function outgoing(string $branch, string $into, ?string $have = null): array|string
    {
        $head = self::isStudioBranch($branch) ? $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]) : null;
        $known = $have === null ? null : $this->git(['rev-parse', '--verify', '--quiet', $have.'^{commit}']);
        $from = $known ?? $this->git(['rev-parse', '--verify', '--quiet', 'refs/remotes/origin/'.$into]) ?? $this->git(['rev-parse', '--verify', '--quiet', 'refs/heads/'.$into]);
        $base = $head === null || $from === null ? null : $this->git(['merge-base', $from, $head]);
        $shas = $base === null ? '' : (string) $this->git(['rev-list', '--reverse', $base.'..'.$head]);

        $refused = match (true) {
            ! self::isStudioBranch($branch) => 'Only the studio’s own fix and build branches are sent, and '.$branch.' is not one.',
            $head === null => 'There is no branch called '.$branch.' on this machine.',
            $from === null => 'There is no branch called '.$into.' on this machine to compare '.$branch.' with.',
            $base === null => $branch.' shares no history with '.$into.'.',
            $shas === '' => $branch.' has no commits that '.$into.' does not have.',
            $this->git(['rev-list', '--min-parents=2', $base.'..'.$head]) !== '' => $branch.' has a merge in it, so it cannot be sent commit by commit.',
            default => null,
        };

        if ($refused !== null) {
            return $refused;
        }

        $commits = array_map($this->commitOf(...), explode("\n", $shas));
        $kept = collect($commits)->flatMap(fn (array $commit): array => $commit['files'])->map(fn (array $file): ?string => match (true) {
            preg_match(self::ENV, $file['path']) === 1 => $file['path'].' is an environment file, and those never leave your machine.',
            ! in_array($file['mode'], self::SENT_MODES, true) => $file['path'].' is a submodule, which cannot be sent.',
            strlen((string) $file['content']) > self::LARGEST_SENT => sprintf('%s is %.1f MB, more than the %d MB the studio takes in one file.', $file['path'], strlen((string) $file['content']) * 3 / 4 / 1_000_000, self::LARGEST_SENT * 3 / 4 / 1_000_000),
            default => null,
        })->filter()->first();

        return $kept ?? ['base' => $base, 'commits' => $commits];
    }

    /**
     * The history a commit stands on, newest first, down its first parents: what
     * the studio looks through to find the newest commit GitHub has.
     *
     * @return list<string>
     */
    public function ancestors(string $sha, int $most = 300): array
    {
        return array_values(array_filter(explode("\n", (string) $this->git(['rev-list', '--first-parent', '-n', (string) $most, $sha]))));
    }

    /**
     * @return array{sha: string, message: string, author: array{name: string, email: string, date: string}, committer: array{name: string, email: string, date: string}, signature: string|null, files: list<array{path: string, mode: string, content: string|null}>}
     */
    private function commitOf(string $sha): array
    {
        [$header, $message] = explode("\n\n", (string) $this->raw(['cat-file', 'commit', $sha]), 2) + [1 => ''];
        $fields = collect(preg_split('/\n(?! )/', $header) ?: [])->mapWithKeys(function (string $line): array {
            [$name, $value] = explode(' ', $line, 2) + [1 => ''];

            return [$name => str_replace("\n ", "\n", $value)];
        });
        $changes = array_chunk(array_values(array_filter(explode("\0", (string) $this->raw(['diff-tree', '-r', '--no-renames', '--no-commit-id', '--raw', '-z', $sha])), fn (string $part): bool => $part !== '')), 2);

        return [
            'sha' => $sha,
            'message' => $message,
            'author' => self::person((string) $fields->get('author')),
            'committer' => self::person((string) $fields->get('committer')),
            'signature' => $fields->get('gpgsig'),
            'files' => array_map($this->fileOf(...), $changes),
        ];
    }

    /**
     * @param  array{0: string, 1?: string}  $change
     * @return array{path: string, mode: string, content: string|null}
     */
    private function fileOf(array $change): array
    {
        [$oldMode, $newMode, , $blob, $status] = explode(' ', ltrim($change[0], ':')) + [4 => ''];

        return $status === 'D'
            ? ['path' => (string) ($change[1] ?? ''), 'mode' => $oldMode, 'content' => null]
            : ['path' => (string) ($change[1] ?? ''), 'mode' => $newMode, 'content' => base64_encode((string) $this->raw(['cat-file', 'blob', $blob]))];
    }

    /**
     * @return array{name: string, email: string, date: string}
     */
    private static function person(string $line): array
    {
        preg_match('/^(.*) <(.*)> (\d+) ([+-]\d{4})$/', $line, $parts);

        return [
            'name' => $parts[1] ?? '',
            'email' => $parts[2] ?? '',
            'date' => (new DateTimeImmutable('@'.($parts[3] ?? '0')))->setTimezone(new DateTimeZone($parts[4] ?? '+0000'))->format(DATE_ATOM),
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->root, timeout: 120);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function withoutAsking(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->root, ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => 'echo', 'SSH_ASKPASS' => 'echo'], timeout: self::QUICK_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return null;
        }

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function raw(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->root, timeout: 120);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}
