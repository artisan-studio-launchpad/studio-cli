<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta
            name="viewport"
            content="width=device-width, initial-scale=1"
        >
        <meta
            name="csrf-token"
            content="{{ csrf_token() }}"
        >
        <title>{{ __('Prototype') }}</title>
        @if ($assets !== [])
            @vite($assets)
        @endif
        <style>
            html,
            body {
                height: 100%;
                margin: 0;
            }

            .studio-prototype {
                position: fixed;
                inset: 0;
                display: flex;
                flex-direction: column;
            }

            .studio-prototype .slidewire-controls,
            .studio-prototype .slidewire-progress {
                display: none !important;
            }
        </style>
    </head>
    <body>
        <div class="studio-prototype">{!! $prototype !!}</div>
    </body>
</html>
