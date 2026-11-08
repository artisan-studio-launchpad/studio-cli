<?php

declare(strict_types=1);

use ArtisanStudio\StudioCli\Scan\Tools\TypeCoverage;
use ArtisanStudio\StudioCli\Scan\TypeCoverageCache;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/studio-tc-cache-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/app', 0755, true);
    mkdir($this->root.'/'.TypeCoverage::PLUGIN.'/.temp', 0755, true);
    $this->cache = $this->root.'/'.TypeCoverage::PLUGIN.'/.temp/v3.php';
    $this->entry = fn (string $hash, string $path): string => "  '{$hash}' => \n  array (\n    0 => '{$path}',\n    1 => \n    array (\n    ),\n    2 => \n    array (\n    ),\n  ),\n";
});

it('drops the cached files that no longer exist as they were, and those of another project, keeping the rest', function (): void {
    file_put_contents($this->root.'/app/A.php', "<?php\n\nclass A {}\n");
    file_put_contents($this->root.'/app/B.php', "<?php\n\nclass B {}\n");
    $a = md5_file($this->root.'/app/A.php');
    $b = md5_file($this->root.'/app/B.php');
    file_put_contents($this->cache, "<?php return array (\n"
        .($this->entry)($a, $this->root.'/app/A.php')
        .($this->entry)(md5('old version of A'), $this->root.'/app/A.php')
        .($this->entry)($b, '/somewhere/else/app/B.php')
        .($this->entry)(md5('deleted'), $this->root.'/app/Gone.php')
        .');');

    $dropped = (new TypeCoverageCache($this->root))->prune();
    $kept = file_get_contents($this->cache);

    expect($dropped)->toBe(3)
        ->and($kept)->toContain($a)->not->toContain(md5('old version of A'))->not->toContain('/somewhere/else')
        ->and(token_get_all($kept, TOKEN_PARSE))->not->toBeEmpty();
});

it('leaves a cache alone when most of it is still wanted, or when it cannot be read', function (): void {
    foreach (['A', 'B', 'C'] as $name) {
        file_put_contents($this->root.'/app/'.$name.'.php', "<?php\n\nclass ".$name." {}\n");
    }

    $wanted = collect(['A', 'B', 'C'])->map(fn (string $name): string => ($this->entry)(md5_file($this->root.'/app/'.$name.'.php'), $this->root.'/app/'.$name.'.php'))->implode('');
    $whole = "<?php return array (\n".$wanted.($this->entry)(md5('x'), $this->root.'/app/X.php').');';
    file_put_contents($this->cache, $whole);

    expect((new TypeCoverageCache($this->root))->prune())->toBe(0)
        ->and(file_get_contents($this->cache))->toBe($whole);

    file_put_contents($this->cache, 'not a cache');

    expect((new TypeCoverageCache($this->root))->prune())->toBe(0);
});
