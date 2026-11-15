<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli\Dashboard;

use ArtisanStudio\StudioCli\Studio;
use ArtisanStudio\StudioCli\Terminal\Components\Alert;
use ArtisanStudio\StudioCli\Terminal\Components\Component;
use ArtisanStudio\StudioCli\Terminal\Components\Text;

final readonly class NotConnected
{
    public const string WHERE_THE_TOKEN_IS = "In Artisan Studio, open this project's onboarding and press Generate token under Your studio token, or go to Settings → Studio CLI. Each project has its own token.";

    public function __construct(private Studio $studio) {}

    /**
     * @return list<Component>
     */
    public function panel(): array
    {
        $url = $this->studio->url();
        $project = $this->studio->project();

        $message = match ($this->studio->connection()) {
            Studio::UNLINKED => [
                'cyan',
                __('studio-cli::welcome.title'),
                __('studio-cli::welcome.body'),
                'Press s to paste your token.',
            ],
            Studio::REFUSED => [
                'amber',
                'This project needs a fresh token',
                "{$url} doesn't know the token in your .env any more. It was replaced, or it came from another studio. ".self::WHERE_THE_TOKEN_IS,
                'Press s to paste the new one.',
            ],
            Studio::FORBIDDEN => [
                'amber',
                'This token was made for another project',
                "It works, but not for project {$project} on {$url}. Each project has its own token. ".self::WHERE_THE_TOKEN_IS,
                'Press s to link with this project\'s token.',
            ],
            Studio::MISSING => [
                'amber',
                "Project {$project} isn't on {$url}",
                'It may have been deleted, or it lives on another studio. '.self::WHERE_THE_TOKEN_IS,
                'Press s to link with this project\'s token.',
            ],
            Studio::UNREACHABLE => $this->studio->hasConnected() ? null : [
                'rose',
                "Can't reach Artisan Studio",
                "Nothing answered at {$url}. Is it running, and is that the right address?",
                'Trying again in the background. Press s to check the address.',
            ],
            default => null,
        };

        if ($message === null) {
            return [];
        }

        [$colour, $title, $detail, $hint] = $message;

        $signIn = $this->studio->signInUrl();

        return [
            Alert::make($title)->colour($colour)->description($detail)->descriptionColour('soft'),
            ...($this->studio->connection() === Studio::UNLINKED ? [Text::make($signIn)->colour('cyan')->bold()->link(__('studio-cli::welcome.open'), $signIn)] : []),
            Text::make($hint)->colour('cyan'),
        ];
    }
}
