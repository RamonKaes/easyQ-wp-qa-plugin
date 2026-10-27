# easyQ

A complete question and answer platform for WordPress, built from blocks.
easyQ is a port of [Question2Answer](https://www.question2answer.org/) 1.8.8 to
WordPress.

> **Status:** early development. The foundation and the core domain (posts,
> moderation, votes, flags, points, categories, search index, rate limits and
> events), users and permissions (levels, capabilities, profile integration), the
> REST API and the frontend (block templates, blocks, patterns, feeds, sitemaps,
> navigation links and a fallback for classic themes) are in place; features are
> added phase by phase, see
> [docs/feature-mapping.md](docs/feature-mapping.md#13-phases).

## Principles

- **WordPress core guidelines without exception.** Coding standards, Plugin Handbook,
  Block Editor Handbook, accessibility, internationalization and the wordpress.org
  plugin guidelines.
- **No plugin CSS.** All styling comes from the active block theme.
- **Privacy by design and by default.**
- **High compatibility.** Everything is extensible through documented hooks.

The full mapping of Question2Answer features to WordPress is documented in
[docs/feature-mapping.md](docs/feature-mapping.md), the public hooks in
[docs/hooks.md](docs/hooks.md) and the REST API in [docs/rest-api.md](docs/rest-api.md).

## Requirements

- WordPress 6.7 or later
- PHP 8.4 or later
- A MySQL or MariaDB version supported by WordPress

## Development

### Prerequisites

- PHP 8.4+ and [Composer](https://getcomposer.org/)
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
| `bash bin/ci.sh [--full] [commit]` | Local CI for a commit, see below |

To test against another WordPress or PHP version, create `.wp-env.test.override.json`
(`.wp-env.override.json` for the development site) and start the environment again:

```json
{ "core": "WordPress/WordPress#6.7", "phpVersion": "8.5" }
```

### Continuous integration

CI runs locally, not on GitHub. `bin/ci.sh` checks a commit (default `HEAD`) in its own
worktree in `~/easyq-wp-audit/easyq`, with its own instance of the test environment on
port 8891 (`CI_PORT`), so the development and test sites keep running. It runs the coding standards,
static analysis, JavaScript, Markdown and `package.json` linting, the unit tests on PHP
8.4 and 8.5 (Docker images), the integration tests on single site and multisite, the
end-to-end tests and Plugin Check. `--full` runs the integration tests on WordPress 6.7
and the latest version with PHP 8.4 and 8.5 (WordPress 6.7 with PHP 8.4 only). A commit that only changes Markdown
files is only linted. The result is written to `~/easyq-wp-audit/logs/{commit}/result.txt`.

Run it before every push through the pre-push hook:

```bash
ln -sf ../../bin/pre-push.sh .git/hooks/pre-push
```

### Project structure

| Path | Content |
| --- | --- |
| `easyq.php` | Plugin bootstrap |
| `uninstall.php` | Data removal when the plugin is deleted |
| `includes/` | PHP classes; `EasyQ\Database\Schema` lives in `includes/database/class-schema.php` |
| `blocks/` | Block metadata (`block.json`) and server-side rendering of every block |
| `src/` | JavaScript sources: block editor (`src/editor`) and frontend interactivity (`src/view`) |
| `build/` | Built JavaScript, created by `npm run build` |
| `languages/` | Translation template and bundled translations |
| `tests/unit/` | Unit tests without WordPress |
| `tests/integration/` | Integration tests with the WordPress test suite |
| `tests/e2e/` | Playwright end-to-end tests |
| `docs/` | Project documentation |
| `bin/` | Local CI and the pre-push hook |

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
