<?php

declare(strict_types=1);

return [
    'task' => [
        'label' => 'AI bridge',
        'working' => 'Letting SAMI use your Claude Code on this machine…',
    ],

    'runner' => [
        'codex_off' => 'Codex is switched off in this release. It is not offered and it is not used.',
        'unknown' => 'The runner is claude.',
    ],

    'consent' => [
        'label' => 'AI bridge through Claude Code',
        'question' => 'Artisan Studio can run its AI tasks on your Claude Code subscription. Allow it?',
        'yes' => 'Yes, allow it',
        'no' => 'No, keep it off',
        'allowed' => 'The AI bridge is on. Artisan Studio runs its AI tasks through your Claude Code login, on this machine.',
        'refused' => 'The AI bridge is off. Scan and the report still work; fixing needs the bridge.',
        'not_given' => 'The AI bridge is off until you allow it. Run php artisan studio:bridge --start, or choose it in Settings in php artisan studio.',
        'not_ready' => ':reason Scan and the report still work; fixing needs the bridge.',
        'account' => 'Claude Code is signed in as :who.',
    ],

    'blocked' => 'Fixing needs the AI bridge. Turn it on in Settings in php artisan studio, or run php artisan studio:bridge --start.',

    'cli' => [
        'running' => 'CLI running',
        'bridge' => [
            'on' => 'bridge on',
            'off' => 'bridge off',
            'attention' => 'bridge needs attention',
        ],
    ],

    'setup' => [
        'not_installed' => 'Claude Code is not installed on this machine. Install it, run claude once to sign in, then choose this again.',
        'not_signed_in' => 'Claude Code is installed but not signed in. Run claude, sign in, then choose this again.',
    ],

    'status' => [
        'running' => 'AI bridge running on your Claude Code login',
        'running_as' => 'AI bridge running on your Claude Code login, :who',
        'running_short' => 'running',
        'row' => 'AI bridge',
        'on' => 'on',
        'off' => 'off',
        'asks' => 'asks first',
        'value' => [
            'on' => 'Runs its AI tasks through your Claude Code login',
            'on_as' => 'Runs its AI tasks through your Claude Code login, :who',
            'off' => 'Your Claude Code login is not used',
            'undecided' => 'Not decided yet',
            'undecided_as' => 'Not decided yet. Claude Code is signed in as :who',
        ],
    ],

    'signature' => [
        'no_sodium' => 'This PHP cannot check signatures, so the task was refused.',
        'no_key' => 'No studio key is set to check signatures with, so the task was refused.',
        'missing' => 'The task was not signed by Artisan Studio, so it was refused.',
        'expired' => 'The task signature has run out, or runs too long, so the task was refused.',
        'other_project' => 'The task was signed for another project, so it was refused.',
        'invalid' => 'The task signature is not Artisan Studio\'s, so the task was refused.',
    ],
];
