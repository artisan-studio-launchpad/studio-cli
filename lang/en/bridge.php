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
        'not_given' => 'The AI bridge is off until you allow it. Choose it in Settings in php artisan studio.',
        'not_ready' => ':reason Scan and the report still work; fixing needs the bridge.',
        'account' => 'Claude Code is signed in as :who.',
    ],

    'blocked' => 'Fixing needs the AI bridge. Turn it on in Settings in php artisan studio.',

    'cli' => [
        'bridge' => [
            'on' => 'bridge on',
            'key' => 'bridge on a key',
            'off' => 'bridge off',
            'attention' => 'bridge needs attention',
        ],
        'explain' => [
            'on' => 'The bridge is on. Fixing, working and building run through your Claude Code on this machine.',
            'key' => 'The AI calls run through Artisan Studio with your API key. Fixing, working and building can run.',
            'consent_not_given' => 'The bridge is off because you have not allowed it. Scan and the report still work; fixing, working and building wait. Choose the AI bridge below and say yes.',
            'stopped' => 'You allowed the bridge, but it is not running. It starts with this screen, so leave this screen open and give it a moment.',
            'signed_out' => 'No AI is connected: your AI tool is installed, but you are signed out. Fixing, working and building wait. The steps are below.',
            'not_installed' => 'No AI is connected: no AI tool is installed. Fixing, working and building wait. The steps are below.',
        ],
    ],

    'block' => [
        'title' => 'No AI connected',
        'lead' => 'Fixing, working and building are on hold until an AI is connected. Scan and the report still work.',
        'recommended' => 'Recommended: install :runners and sign in to your subscription',
        'runner' => [
            'not_installed' => ':runner is not installed. Install it, then sign in with :command.',
            'signed_out' => ':runner is installed, but you are signed out. Run :command and sign in.',
        ],
        'reopen' => 'Then reopen the studio screen. The AI runs on this machine, with your subscription.',
        'key_title' => 'Alternative: add an API key in the app',
        'key_cost' => 'An API key is billed per token, so it is not the best option. A subscription is recommended to save on token usage.',
        'key_where' => 'Add the key on this page in Artisan Studio, then come back: :url. This screen never asks for the key or keeps it.',
        'key_route' => 'With a key, the AI calls run through Artisan Studio with your key.',
        'unsupported' => 'Other assistants, such as Copilot, are not supported yet.',
        'coming' => 'Claude Code is supported now, and :runner support is coming soon.',
        'understood' => 'Got it',
    ],

    'runner' => [
        'names' => [
            'claude' => 'Claude Code',
            'codex' => 'Codex',
        ],
        'commands' => [
            'claude' => 'claude',
            'codex' => 'codex login',
        ],
        'or' => 'or',
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
