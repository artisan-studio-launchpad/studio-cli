<?php

declare(strict_types=1);

namespace ArtisanStudio\StudioCli;

use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelInspector;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class Blueprint
{
    private const string CAST = '/^[A-Za-z0-9_\\\\:,.-]{1,255}$/';

    private const array NOT_THEIR_CODE = ['vendor', 'node_modules', 'tests', 'database', 'storage'];

    /**
     * @param  array<string, list<string>>  $roots  namespace prefix => directories, beside the one app path
     */
    public function __construct(
        private readonly string $appPath,
        private readonly string $namespace,
        private readonly ModelInspector $inspector,
        private readonly array $roots = [],
    ) {}

    /**
     * Every PSR-4 root that is the project's own code, wherever it lives: the
     * app, the modules and domains a larger project splits into, and the
     * packages published by whoever owns the project.
     *
     * A package is theirs when its composer vendor is the owner of the
     * project's repository. That reaches a package symlinked in from a sibling
     * folder as well as one installed into vendor, and leaves every package
     * somebody else published alone, however it was installed: their models
     * are not part of this project's blueprint.
     *
     * @param  array<string, string>|null  $packages  package name => install path, from Composer when omitted
     * @param  list<ClassLoader>|null  $loaders  Composer's loaders when omitted
     * @return array<string, list<string>>
     */
    public static function rootsOf(string $basePath, ?string $owner = null, ?array $packages = null, ?array $loaders = null): array
    {
        $base = realpath($basePath);

        if ($base === false) {
            return [];
        }

        $owned = self::packagesOwnedBy($owner, $packages);

        return collect($loaders ?? ClassLoader::getRegisteredLoaders())
            ->flatMap(fn (ClassLoader $loader): array => $loader->getPrefixesPsr4())
            ->map(fn (array $directories): array => collect($directories)
                ->map(fn (string $directory): string|false => realpath($directory))
                ->filter(fn (string|false $directory): bool => $directory !== false && (self::isInsideProject($directory, $base) || self::isInside($directory, $owned)))
                ->values()
                ->all())
            ->filter()
            ->all();
    }

    /**
     * The owner of the repository the project was cloned from: the user or
     * organisation in `owner/repo`.
     */
    public static function ownerOf(?string $originRepository): ?string
    {
        return $originRepository !== null && str_contains($originRepository, '/') ? Str::before($originRepository, '/') : null;
    }

    private static function isInsideProject(string $directory, string $base): bool
    {
        return str_starts_with($directory, $base.DIRECTORY_SEPARATOR) && ! self::isNotTheirCode(substr($directory, strlen($base) + 1));
    }

    /**
     * @param  list<string>  $packagePaths
     */
    private static function isInside(string $directory, array $packagePaths): bool
    {
        return collect($packagePaths)->contains(fn (string $path): bool => str_starts_with($directory, $path.DIRECTORY_SEPARATOR) && ! self::isNotTheirCode(substr($directory, strlen($path) + 1)));
    }

    /**
     * @param  array<string, string>|null  $packages
     * @return list<string>
     */
    private static function packagesOwnedBy(?string $owner, ?array $packages): array
    {
        if ($owner === null || $owner === '') {
            return [];
        }

        $packages ??= collect(InstalledVersions::getInstalledPackages())
            ->mapWithKeys(fn (string $name): array => [$name => (string) InstalledVersions::getInstallPath($name)])
            ->all();

        return collect($packages)
            ->filter(fn (string $path, string $name): bool => strcasecmp(Str::before($name, '/'), $owner) === 0)
            ->map(fn (string $path): string|false => realpath($path))
            ->filter()
            ->values()
            ->all();
    }

    private static function isNotTheirCode(string $relative): bool
    {
        return collect(explode(DIRECTORY_SEPARATOR, $relative))->intersect(self::NOT_THEIR_CODE)->isNotEmpty();
    }

    /**
     * @return array{models: array<int, array{class: string, table: string, columns: array<int, array{name: string, database_type: string, enum: bool, nullable: bool, cast: ?string, fillable: bool, hidden: bool, unique: bool}>, relationships: array<int, array{name: string, type: string, related: string}>, observers: array<int, array{event: string, observer: string}>}>, skipped: array<int, string>}
     */
    public function map(): array
    {
        $inspected = $this->models()->mapWithKeys(fn (string $class): array => [$class => $this->inspect($class)]);

        return [
            'models' => $inspected->filter()->values()->all(),
            'skipped' => $inspected->filter(fn (?array $model): bool => $model === null)->keys()->all(),
        ];
    }

    /**
     * @return Collection<int, string>
     */
    public function models(): Collection
    {
        return collect([$this->namespace => [$this->appPath], ...$this->roots])
            ->flatMap(fn (array $directories, string $namespace): array => collect($directories)
                ->filter(fn (string $directory): bool => is_dir($directory))
                ->flatMap(fn (string $directory): array => collect(Finder::create()->in($directory)->files()->name('*.php')->contains('/\bextends\b/'))
                    ->map(fn (SplFileInfo $file): string => $namespace.str_replace(['/', '\\'], '\\', Str::beforeLast($file->getRelativePathname(), '.php')))
                    ->all())
                ->all())
            ->unique()
            ->filter(fn (string $class): bool => $this->isAModel($class))
            ->sort()
            ->values();
    }

    /**
     * @return array{class: string, table: string, columns: array<int, array{name: string, database_type: string, enum: bool, nullable: bool, cast: ?string, fillable: bool, hidden: bool, unique: bool}>, relationships: array<int, array{name: string, type: string, related: string}>, observers: array<int, array{event: string, observer: string}>}|null
     */
    private function inspect(string $class): ?array
    {
        try {
            $info = $this->inspector->inspect($class);
        } catch (Throwable) {
            return null;
        }

        return [
            'class' => $class,
            'table' => (string) data_get($info, 'table'),
            'columns' => collect(data_get($info, 'attributes'))
                ->filter(fn (array $attribute): bool => $attribute['type'] !== null)
                ->map(fn (array $attribute): array => [
                    'name' => (string) $attribute['name'],
                    'database_type' => Str::of((string) $attribute['type'])->before('(')->trim()->before(' ')->lower()->limit(60, '')->toString(),
                    'enum' => is_string($attribute['cast'] ?? null) && $attribute['cast'] !== '' && enum_exists($attribute['cast']),
                    'nullable' => (bool) $attribute['nullable'],
                    'cast' => is_string($attribute['cast'] ?? null) && preg_match(self::CAST, $attribute['cast']) === 1 ? $attribute['cast'] : null,
                    'fillable' => (bool) ($attribute['fillable'] ?? false),
                    'hidden' => (bool) ($attribute['hidden'] ?? false),
                    'unique' => (bool) ($attribute['unique'] ?? false),
                ])
                ->values()
                ->all(),
            'observers' => collect(data_get($info, 'observers'))
                ->flatMap(fn (array $listener): array => array_map(
                    fn (string $observer): array => ['event' => (string) $listener['event'], 'observer' => $observer === 'Closure' ? 'booted()' : Str::before($observer, '@')],
                    (array) $listener['observer'],
                ))
                ->unique(fn (array $listener): string => $listener['event'].$listener['observer'])
                ->values()
                ->all(),
            'relationships' => collect(data_get($info, 'relations'))
                ->map(fn (array $relation): array => [
                    'name' => (string) $relation['name'],
                    'type' => (string) $relation['type'],
                    'related' => (string) $relation['related'],
                ])
                ->values()
                ->all(),
        ];
    }

    private function isAModel(string $class): bool
    {
        try {
            return class_exists($class)
                && is_subclass_of($class, Model::class)
                && ! (new ReflectionClass($class))->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }
}
