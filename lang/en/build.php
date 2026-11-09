<?php

declare(strict_types=1);

return [
    'migrate' => [
        'ask' => 'I added :files. A migration is PHP that runs against your database, so read it first if you like. Should I run it now?',
        'ran' => 'I ran php artisan migrate for what I added.',
        'left' => 'I did not run what I added, as you asked: run php artisan migrate when you are ready.',
        'risky' => 'I added :files, which drops or renames something, so I did not run it: read it, then run php artisan migrate.',
        'no_artisan' => 'I added a migration, so run php artisan migrate first.',
        'failed' => 'I added a migration, but php artisan migrate failed: :reason Run it yourself once that is fixed.',
    ],

    'styles' => [
        'timing' => 'checking the prototype’s classes against the stylesheet, in a browser',
        'fixed' => '{1} :count class had no style yet, so I asked your build tool to scan the new files, and now it does.|[2,*] :count classes had no style yet, so I asked your build tool to scan the new files, and now they do.',
        'still' => 'These classes still have no style in your stylesheet: :classes. If the page looks unstyled, rebuild your assets or restart your dev server so they are picked up.',
        'more' => 'and :count more',
    ],

    'screenshots' => [
        'not_sent' => 'Screenshots of the prototype are not sent to Artisan Studio, as your settings say, so I only measure it here.',
        'measured_only' => 'Nothing in it is cut off or overflowing. I did not send screenshots, so I cannot say more about how it looks.',
    ],
];
