<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Work\EditApplier;

it('applies agreed edits only where the text matches exactly once', function (): void {
    $file = "<?php\n\$a = 1;\n\$b = 2;\n\$b = 2;\n";

    expect(EditApplier::apply($file, [['find' => '$a = 1;', 'replace' => '$a = 3;']]))->toBe(['content' => "<?php\n\$a = 3;\n\$b = 2;\n\$b = 2;\n"])
        ->and(EditApplier::apply($file, [['find' => '$a = 1;', 'replace' => '$a = 3;'], ['find' => '$b = 2;', 'replace' => 'x']])['error'])->toContain('more than one place')
        ->and(EditApplier::apply($file, [['find' => '$c = 1;', 'replace' => 'x']])['error'])->toContain('not the one SAMI read')
        ->and(EditApplier::apply($file, [])['error'])->toContain('nothing to change');
});
