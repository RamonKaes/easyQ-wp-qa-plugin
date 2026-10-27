# easyQ

[![CI](https://github.com/RamonKaes/easyQ-wp-qa-plugin/actions/workflows/ci.yml/badge.svg)](https://github.com/RamonKaes/easyQ-wp-qa-plugin/actions/workflows/ci.yml)

A complete question and answer community for WordPress, built from blocks.

Visitors ask questions, the community answers, comments and votes, and the asker
picks the best answer. easyQ brings the proven feature set of
[Question2Answer](https://www.question2answer.org/) 1.8.8 to WordPress, rebuilt
the WordPress way: every page is a block template, every part of it a block, and
your theme decides how it looks.

> **Status: early development, not ready for live sites yet.** Asking, answering,
> voting, moderation, profiles and all Q&A pages work. The settings screen, emails
> and notifications, the privacy tools and translations are still to come, see
> [what is next](#what-is-next).

## Features

### Questions and answers

- Questions with title, text, category and tags; answers and comments on both
- Up and down votes, best answer selection, related questions
- Question lists: recent, hot, unanswered, most votes, most answers, most views and
  recent activity
- Nested categories, a tag cloud and a full-text search with relevance ranking
- Editing, closing (as a duplicate or with a reason), hiding and restoring posts

### Community and moderation

- Points for questions, answers, votes and best answers, with user ranks and titles
- User levels from registered user to super administrator, mapped to WordPress roles
  and capabilities
- Flags, an approval queue, user approval and blocking, rate limits per user and IP
  address, a list of words that are censored
- Posting without an account, if you allow it
- User profiles with custom fields, uploaded avatars and activity lists

### Made for WordPress

- Over 40 blocks (question list, voting, answer form, user profile and more) and block
  patterns, arranged and styled in the Site Editor like any other template
- No stylesheet of its own: the Q&A area takes on the look of your block theme; classic
  themes are supported too
- Voting, answering and commenting without page reloads, built on the Interactivity API
- RSS feeds, XML sitemaps, search engine metadata and Q&A links for the Navigation
  block
- Users, roles, avatars and media are those of WordPress; works on multisite
- Accessible markup, checked automatically with axe
- A REST API for every action and documented hooks for extensions
- Privacy by design: no external requests, no tracking

## What is next

easyQ is built in phases, see the
[phase plan](docs/feature-mapping.md#13-phases):

1. **Admin:** a settings screen for all Question2Answer options (until then easyQ runs
   with the Question2Answer defaults)
2. **Communication:** emails and notifications, private messages, the user wall,
   favorites and the updates page
3. **Privacy and tooling:** personal data export and erasure, IP address retention,
   WP-CLI commands and Site Health checks
4. **Release:** translations (German included), screenshots and the wordpress.org
   release

## Try it

easyQ is not in the plugin directory yet. To try it on a test site:

1. Build the plugin archive from the source, see [Development](#development)
   (`npm run plugin-zip`), or start the bundled development site with `wp-env`.
2. Upload and activate it on a site with a block theme, e.g. Twenty Twenty-Five.
3. Open `/questions/` on your site. Add the Q&A pages to your menu with the Navigation
   block, and adjust the page templates under Appearance → Editor → Templates.

## Requirements

- WordPress 6.7 or later
- PHP 8.2 or later
- A MySQL or MariaDB version supported by WordPress

## Principles

- **WordPress core guidelines without exception.** Coding standards, Plugin Handbook,
  Block Editor Handbook, accessibility, internationalization and the wordpress.org
  plugin guidelines.
- **No plugin CSS.** All styling comes from the active theme.
- **Privacy by design and by default.**
- **High compatibility.** Everything is extensible through documented hooks.

The full mapping of Question2Answer features to WordPress is documented in
[docs/feature-mapping.md](docs/feature-mapping.md), the public hooks in
[docs/hooks.md](docs/hooks.md) and the REST API in [docs/rest-api.md](docs/rest-api.md).

## Development

### Prerequisites

- PHP 8.2+ and [Composer](https://getcomposer.org/)
- Node.js 20+ and npm
- Docker (for [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/))

### Setup

```bash
composer install
npm install
npm run build
npm run env:start
```

The development site runs at <http://localhost:8888>, the test site at
<http://localhost:8889> (user `admin`, password `password`). They are two separate
`wp-env` environments: `.wp-env.json` for development and `.wp-env.test.json` for the
integration and end-to-end tests, which reset its content. Run `wp-env` commands for the
test site with `npm run env:test -- <command>`, e.g. `npm run env:test -- run cli wp plugin list`.

### Commands

| Command | Purpose |
| --- | --- |
| `composer lint` | PHP coding standards (WPCS, PHPCompatibility) |
| `composer lint:fix` | Fix coding standard violations automatically |
| `composer analyse` | Static analysis with PHPStan |
| `composer test:unit` | Unit tests without WordPress |
| `npm run test:php` | Integration tests with the WordPress test suite |
| `npm run test:php:multisite` | Integration tests on multisite |
| `npm run test:e2e` | End-to-end tests with Playwright |
| `npm run build` | Build the editor script and the interactivity module |
| `npm run start` | Rebuild JavaScript on changes |
| `npm run lint:js` | JavaScript coding standards |
| `npm run lint:md` | Markdown coding standards |
| `npm run plugin-check` | Official Plugin Check on the development site (`WP_ENV_CONFIG=.wp-env.test.json` for the test site) |
| `npm run i18n:pot` | Regenerate `languages/easyq.pot` |
| `npm run plugin-zip` | Build the release archive |

To test against another WordPress or PHP version, create `.wp-env.test.override.json`
(`.wp-env.override.json` for the development site) and start the environment again:

```json
{ "core": "WordPress/WordPress#6.7", "phpVersion": "8.2" }
```

### Continuous integration

GitHub Actions checks every push to `main` and every pull request
([`.github/workflows/ci.yml`](.github/workflows/ci.yml)):

- coding standards (WPCS, PHPCompatibilityWP) and PHPStan, JavaScript and `package.json`
  linting;
- unit tests on PHP 8.2, 8.3, 8.4 and 8.5;
- integration tests on single site and multisite with the latest WordPress on PHP 8.2 to
  8.5, and WordPress 6.7 on PHP 8.2;
- end-to-end tests with Playwright and axe; the artifacts of a failed run are attached to it;
- [Plugin Check](https://github.com/WordPress/plugin-check-action) on the release archive,
  including experimental checks, with warnings failing the run.

Markdown files are linted by [`.github/workflows/docs.yml`](.github/workflows/docs.yml)
whenever one of them changes; commits that only change Markdown run nothing else. Before pushing, run the
commands above for the parts you changed, at least `composer lint`, `composer analyse`
and the tests.

### Project structure

| Path | Content |
| --- | --- |
| `easyq.php` | Plugin bootstrap |
| `uninstall.php` | Data removal when the plugin is deleted |
| `includes/` | PHP classes; `EasyQ\Database\Schema` lives in `includes/database/class-schema.php` |
| `blocks/` | Block metadata (`block.json`) of every block; they are rendered by `includes/blocks/` |
| `src/` | JavaScript sources: block editor (`src/editor`) and frontend interactivity (`src/view`) |
| `build/` | Built JavaScript, created by `npm run build` |
| `languages/` | Translation template and bundled translations |
| `tests/unit/` | Unit tests without WordPress |
| `tests/integration/` | Integration tests with the WordPress test suite |
| `tests/e2e/` | Playwright end-to-end tests |
| `docs/` | Project documentation |
| `.github/workflows/` | Continuous integration |

### Conventions

- Code, comments and documentation are written in English.
- Classes live in the `EasyQ` namespace and follow the WordPress file naming
  conventions (`class-*.php`, lowercase directories).
- Every change keeps PHPCS, PHPStan (level max), all tests and Plugin Check clean.
- The [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
  apply in full, including the rules PHPCS cannot check:
    - every file, class, method, function, property, constant and hook has a docblock
      with `@since`;
    - hooks get named callbacks, no closures;
    - calls pass boolean flags as named arguments, e.g. `error( $option, $actor, check_blocks: false )`;
    - HTML is valid, with self-closing void elements (`<br />`) and unique IDs;
    - no `@` error suppression;
    - test classes use capitalized words separated by underscores (`Feeds_Test`), in a
      file named like the class.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/).

## License

easyQ is free software, licensed under the
[GNU General Public License v2 or later](LICENSE).

## Credits

easyQ is based on [Question2Answer](https://www.question2answer.org/) by Gideon
Greenspan and contributors, licensed under the GNU General Public License v2 or later.
