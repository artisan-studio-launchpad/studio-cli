# studio-cli

Artisan Studio in your terminal. Scan your Laravel project on your own machine, follow the fixes and work on what the scan found as they happen, and keep your project's health in view.

```bash
php artisan studio
```

One full-screen command with a tab for each part:

| Tab | What it shows |
|---|---|
| **Dashboard** | Your project's health, the scan as it runs, and what to do next. |
| **Scan** | Each checking tool you switched on, as it runs and what it found. |
| **Insights** | The fixes: the rules that can be fixed for you, and the jobs worked through with you. |
| **Build** | Each file of a build as it is written, checked and committed. |
| **Activity** | Everything happening, newest first. Down the right of every tab once the terminal is wide enough. |

## Install

```bash
composer require artisan-studio-projects/studio-cli --dev
php artisan studio:settings
```

`studio:settings` asks for the token Artisan Studio gives you for this project, checks it, and writes `ARTISAN_STUDIO_TOKEN` and `ARTISAN_STUDIO_PROJECT` to your `.env`. You can also run `php artisan studio` and press `s`.

## What it does

**Scans on your machine, read-only.** Your project's own checking tools run here: PHPStan, Pint, Rector, your test suite and the others you switch on in Artisan Studio. Each tool's findings go to the studio as file, line, rule and message. If a tool is not installed, it offers to install it, asking before each one.

**Maps your project.** The models, their tables, column types and relationships, and counts of how your code is written, so Artisan Studio knows your conventions.

**Fixes on a branch of its own.** The rules you ask Artisan Studio to fix are fixed on a new local branch, `sami/fixes-…` by default, one commit per rule, and each rule is checked again as its commit lands. Your project's own Rector, Pint and package audits run here too. Nothing is pushed: the pull request is opened by Artisan Studio when you say so.

**Works through Insights jobs with you.** One kind of problem at a time: the changes are applied on their own branch, checked by the tool that found them, and committed when you say it works. When you are needed, the file being worked on opens in your editor.

## What comes from Artisan Studio

The CLI talks to Artisan Studio's API with your project's token. What it runs and what it says come from there, so they change without an update to this package:

- **Which checking tools to run.** PHPStan, Pint, Rector, Pest's type coverage and arch presets, composer and npm audits, the security scan and your tests are run by this package. Other tools are described by Artisan Studio: which of your project's own binaries to run, when it is set up, and which parts of what it prints to send back to be read.
- **The fixes.** They are made in Artisan Studio from the files sent up, and written here. So are the Rector rules to run, and the versions to patch npm packages to.
- **The security scan's Livewire checks.** They run in Artisan Studio on the files they ask for.
- **The avatar.** Its name, what it says on each tab, and its clips for the desktop.

Nothing Artisan Studio sends is run as code. A tool it describes runs only from a binary already in your project's `vendor/bin`, with plain arguments, or as `php artisan config:validate`. Changes to your `package.json` are only plain version ranges for packages it already lists, and overrides held to one version line.

## What leaves your machine

- **Scanning:** findings as file, line, rule and message; test counts with the failing tests' names and lines; your packages' names and versions, and the namespaces each one loads; the map of your models; counts of how your code is written. For the checking tools Artisan Studio describes, only the parts of their output it reads, with your project's path taken out. Never rows from your database.
- **The security scan's Livewire checks:** the classes under `app/` that mention Livewire or are traits, the views your components render and the Blade components they use, your scripts in `resources/js`, your route files, `bootstrap/app.php` and `composer.lock`. Artisan Studio reads them in that same request, sends back what it found, and keeps none of them.
- **Fixing the rules:** the files being fixed, the ones the fixes need to read, and your PHPStan config when it is the one being fixed. They are fixed in that same request, sent straight back, and never kept.
- **Patching npm packages:** the audit's advisories and the dependencies your `package.json` lists.
- **Working through a job:** the files of that job, and the searches it asks for. Artisan Studio holds them encrypted for up to two hours, never in its database.
- **Opening the pull request:** the files on the fixes branch, so Artisan Studio can open the pull request on GitHub.
- **Always:** that this terminal is open, and the repository's name.

Files from `vendor`, `node_modules` and `storage`, `.env` files, and any file holding something that looks like a key or token are never sent.

## Commands

| Command | |
|---|---|
| `studio` | The full-screen studio. `studio scan`, `studio insights` and so on open on that tab. |
| `studio:settings` | Link this project, choose your editor, switch project or unlink. |
| `studio:dashboard`, `studio:scan`, `studio:insights`, `studio:build`, `studio:watch` | Open the studio on that tab. |
| `studio:fix` | Fix the rules asked for in Artisan Studio, on a new local branch. |
| `studio:work` | Work through an Insights job. |

The studio runs these in the background as it needs them, and you can run them yourself:

| Command | |
|---|---|
| `studio:tools` | Run your checking tools read-only and send what they found. |
| `studio:phpstan` | Run PHPStan last, in the background. |
| `studio:tests` | Run your test suite in parallel, when you switched it on. |
| `studio:install-tools` | Install the checking tools you switched on, asking first. |
| `studio:blueprint` | Send the map of your models. |
| `studio:conventions` | Count how your project is written. |
| `studio:check` | Artisan Studio's own security checks, printed as JSON. |
| `studio:render` | Open one page of your app as its first user and say whether it rendered. |
| `studio:avatar-sync` | Download the avatar's clips, when they changed. |

## Configuration

```bash
php artisan vendor:publish --tag=studio-cli-config
```

| | |
|---|---|
| `ARTISAN_STUDIO_URL` | Where Artisan Studio lives. |
| `ARTISAN_STUDIO_TOKEN` | Written by `studio:settings`. |
| `ARTISAN_STUDIO_PROJECT` | Written by `studio:settings`. |
| `ARTISAN_STUDIO_EDITOR` | `phpstorm`, `vscode` or `none`. Without it, the editor whose terminal the studio runs in. |
| `ARTISAN_STUDIO_THEME` | `dark` or `light`. Every colour can be changed in the config. |
| `ARTISAN_STUDIO_POLL` | Seconds between fetches when the live connection is down. Default 30. |
| `ARTISAN_STUDIO_DEV_TAB` | `true` also adds the Activity tab to `php artisan dev`. |
| `STUDIO_CLI_FIX_BRANCH_PREFIX` | What fix branches are named with. Default `sami/fixes-`. |
| `STUDIO_CLI_BUILD_BRANCH_PREFIX` | What build branches are named with. Default `sami/build-`. |
| `STUDIO_CLI_COMMIT_SCOPE` | The scope the studio's commits carry, as in `feat(scope):`. Default `sami`. |

The tabs, their order and the Activity column down the side are set in the config's `tabs` and `rail` sections. Artisan Studio opens pull requests only from branches it knows, so change a branch prefix only once your studio accepts it.

## The avatar on the desktop

On a Mac, your avatar can stand at the bottom of your screen while the studio is open. It hides when you switch to another application, follows the terminal between displays, and leaves when the studio closes. Its clips come from Artisan Studio and are downloaded once, then only when they change.

It is optional in every direction: anywhere but macOS, without the player, or without clips, it never appears and nothing else changes.

```dotenv
STUDIO_CLI_PRESENCE=false          # turn it off
STUDIO_CLI_PRESENCE_SIZE=300       # how tall it stands, in points
STUDIO_CLI_PRESENCE_POSITION=left  # left, center, right, or a percentage across
```

The player ships with the package. To rebuild it after changing `src/AvatarDesktop.swift` (needs the Xcode command line tools):

```bash
php artisan studio:build-avatar
```

If the avatar does not appear when you expect it, a player left behind by a studio that was killed may be in the way: `pkill -f avatardesktop`.

## Requirements

- PHP 8.3+ with `dom`, `mbstring` and `simplexml`
- Laravel 12 or 13
- git
- macOS for the avatar on the desktop, optionally

## How it listens

Artisan Studio talks to the terminal over server-sent events, read through a subprocess rather than an HTTP client. An HTTP client waits for a response to end, and this one never does, so the events would sit unread. A subprocess hands each event over the moment it arrives. A dropped connection resumes from the last event it saw.

## Licence

Source-available. See [LICENSE.md](LICENSE.md).
