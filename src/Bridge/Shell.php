<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Bridge;

use ArtisanStudio\StudioCli\ProcessTree;
use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The one place the bridge starts a program on the developer's machine.
 *
 * Kept apart so a test can stand in for it, and so everything the bridge is
 * able to launch can be read in one file.
 */
class Shell
{
    /**
     * The only variables a program the bridge starts is given: enough to find
     * itself and the developer's own sign-in. Nothing else of the studio's
     * environment goes with it, such as the keys and secrets in a project's
     * .env, and nothing of the terminal's editor integration.
     */
    /**
     * What every program the bridge starts is told. Without this, about
     * half of Claude Code's calls add its own 46,000-token default prompt to
     * what the studio asked, and the developer's usage pays for it each time.
     */
    private const array GIVEN = ['CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1'];

    private const array KEPT = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'TMPDIR', 'TZ', 'TERM', 'LANG', 'LC_ALL', 'LC_CTYPE', 'LC_MESSAGES',
        'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'XDG_CACHE_HOME', 'XDG_STATE_HOME',
        'CLAUDE_CONFIG_DIR', 'CODEX_HOME',
        'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY', 'ALL_PROXY', 'http_proxy', 'https_proxy', 'no_proxy', 'all_proxy',
        'SSL_CERT_FILE', 'SSL_CERT_DIR', 'NODE_EXTRA_CA_CERTS', 'REQUESTS_CA_BUNDLE',
    ];

    /**
     * Asked while a program runs: when it says yes, the program and what it
     * started are stopped. The bridge uses it to stop its work when the studio
     * that started it is gone, so nothing is left running on the machine.
     *
     * @var (Closure(): bool)|null
     */
    private static ?Closure $abortWhen = null;

    /**
     * @param  (Closure(): bool)|null  $abortWhen
     */
    public static function abortWhen(?Closure $abortWhen): void
    {
        self::$abortWhen = $abortWhen;
    }

    public function find(string $program): ?string
    {
        return (new ExecutableFinder)->find($program);
    }

    /**
     * Every variable the studio has, unset, except the ones a program needs.
     *
     * @return array<string, string|false>
     */
    public static function environment(): array
    {
        $everything = array_keys([...getenv(), ...$_ENV, ...$_SERVER]);
        $kept = [];

        foreach (self::KEPT as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $kept[$name] = $value;
            }
        }

        $folder = config('studio-cli.bridge.claude_config_dir');

        if (is_string($folder) && $folder !== '') {
            $kept['CLAUDE_CONFIG_DIR'] = $folder;
        }

        return [...array_fill_keys(array_map('strval', $everything), false), ...$kept, ...self::GIVEN];
    }

    /**
     * @param  list<string>  $command
     * @return array{exit: int, out: string, err: string, timedOut: bool}
     */
    public function run(array $command, string $input, string $cwd, int $timeout): array
    {
        $process = new Process($command, $cwd, self::environment(), $input, $timeout);

        try {
            $process->start();

            while ($process->isRunning()) {
                $process->checkTimeout();

                if (self::$abortWhen !== null && (self::$abortWhen)()) {
                    $pid = $process->getPid();

                    $pid === null || ProcessTree::stop($pid);
                    $process->stop(1);

                    return ['exit' => 143, 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'timedOut' => false];
                }

                usleep(100_000);
            }
        } catch (ProcessTimedOutException) {
            return ['exit' => 124, 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'timedOut' => true];
        }

        return ['exit' => (int) $process->getExitCode(), 'out' => $process->getOutput(), 'err' => $process->getErrorOutput(), 'timedOut' => false];
    }
}
