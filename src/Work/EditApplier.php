<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Work;

use ArtisanStudio\StudioCli\Says;

/**
 * Applies the edits the developer agreed to in the app: exact text to find,
 * and the text to put there. Each must match exactly once in the file as it is
 * on this machine, so nothing lands anywhere it was not meant to.
 */
final class EditApplier
{
    /**
     * @param  list<array{find: string, replace: string}>  $edits
     * @return array{content: string}|array{error: string}
     */
    public static function apply(string $content, array $edits): array
    {
        if ($edits === []) {
            return ['error' => 'There was nothing to change.'];
        }

        $error = null;
        $result = array_reduce($edits, function (string $text, array $edit) use (&$error): string {
            $find = (string) ($edit['find'] ?? '');
            $count = $find === '' ? 0 : substr_count($text, $find);

            if ($count !== 1) {
                $error ??= $count === 0
                    ? Says::named('The file on your machine is not the one {name} read: a piece of it is not there as written.')
                    : 'A piece of the change matches more than one place in your file.';

                return $text;
            }

            return substr_replace($text, (string) ($edit['replace'] ?? ''), (int) strpos($text, $find), strlen($find));
        }, $content);

        return $error === null ? ['content' => $result] : ['error' => $error];
    }
}
