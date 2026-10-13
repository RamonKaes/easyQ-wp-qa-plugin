# easyQ

A complete question and answer platform for WordPress, built from blocks.
easyQ is a port of [Question2Answer](https://www.question2answer.org/) 1.8.8 to
WordPress.

> **Status:** early development. The foundation and the core domain (posts,
> moderation, votes, flags, points, categories, search index, rate limits and
> events), users and permissions (levels, capabilities, profile integration), the
> REST API and the first part of the frontend (routes, block templates and the
> blocks for question lists, question pages and forms) are in place; features are
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
- PHP 8.1 or later
- A MySQL or MariaDB version supported by WordPress

## Development

### Prerequisites

- PHP 8.1+ and [Composer](https://getcomposer.org/)
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
<http://localhost:8889> (user `admin`, password `password`).

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
| `npm run plugin-check` | Official Plugin Check on the development site |
| `npm run i18n:pot` | Regenerate `languages/easyq.pot` |
| `npm run plugin-zip` | Build the release archive |

To test against another WordPress or PHP version, create `.wp-env.override.json`:

```json
{ "core": "WordPress/WordPress#6.7", "phpVersion": "8.4" }
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

### Conventions

- Code, comments and documentation are written in English.
- Classes live in the `EasyQ` namespace and follow the WordPress file naming
  conventions (`class-*.php`, lowercase directories).
- Every change keeps PHPCS, PHPStan (level max), all tests and Plugin Check clean.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/).

## License

easyQ is free software, licensed under the
[GNU General Public License v2 or later](LICENSE).

## Credits

easyQ is based on [Question2Answer](https://www.question2answer.org/) by Gideon
Greenspan and contributors, licensed under the GNU General Public License v2 or later.
