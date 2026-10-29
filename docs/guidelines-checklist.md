# WordPress guidelines checklist

How easyQ meets the WordPress developer documentation, chapter by chapter. Checked
on 2026-10-29 against WordPress 7.1.4.

Sources:

- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Code Reference](https://developer.wordpress.org/reference/)
- [Common APIs Handbook](https://developer.wordpress.org/apis/),
  [REST API Handbook](https://developer.wordpress.org/rest-api/),
  [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [Developer Blog](https://developer.wordpress.org/news/) and
  [dev notes](https://make.wordpress.org/core/tag/dev-notes/) for recent changes

Status: **Met**, **Open** (with the phase it belongs to), **Not used** (easyQ has
nothing the chapter applies to), **Kept** (a deliberate choice, with the reason).

Automated checks cover part of this on every change: PHPCS with the WordPress Coding
Standards (escaping, sanitizing, nonces, prepared SQL, i18n), Plugin Check, PHPStan
and axe.

## Plugin basics

| Rule | Status | Where |
| --- | --- | --- |
| Header: name, description under 140 characters, version, `Requires at least`, `Requires PHP`, license, text domain, domain path | Met | `easyq.php` |
| Prefix of at least four letters for everything global | Met | Namespace `EasyQ`; options, transients, meta, capabilities, hooks, blocks, REST namespace, cookies `easyq_` |
| Only the main file and `uninstall.php` in the root; other files in folders | Met | `includes/`, `blocks/`, `src/`, `languages/`; tooling files are left out of the release archive (`.gitattributes`) |
| `ABSPATH` check in files with code outside classes | Met | Every PHP file |
| Admin code loaded with `is_admin()`, capabilities still checked | Met | `includes/class-plugin.php`, each screen checks its capability |
| Activation creates tables and defaults | Met | `Database\Installer::activate()`, network-wide on multisite |
| Deactivation removes temporary data only, never content | Met | `Installer::deactivate()` deletes the rewrite rules; flushing in the same request would add the easyQ rules again |
| Uninstall in `uninstall.php` with the `WP_UNINSTALL_PLUGIN` check; deletes options, tables and meta, on every site | Met | `uninstall.php`, `Uninstaller` (only when the site owner chose to delete the data) |
| Paths and URLs through `plugins_url()` and friends, nothing hardcoded | Met | `\EasyQ\PLUGIN_FILE`, `plugins_url()` |

## Security

| Rule | Status | Where |
| --- | --- | --- |
| Capability checks with `current_user_can()` for every action | Met | REST `permission_callback`s, admin screens, `Capabilities` (meta capabilities) |
| Sanitize input, validate before saving | Met | Settings API `sanitize_callback`, REST argument schemas, services validate |
| Escape late, on output | Met | PHPCS `EscapeOutput` |
| Nonces on forms and links that change something | Met | Admin forms and row actions, site forms; REST uses the core `wp_rest` nonce |
| SQL only through `$wpdb->prepare()` | Met | PHPCS `PreparedSQL`, fixed SQL with placeholders |
| Redirects with `wp_safe_redirect()` | Met | No `wp_redirect()` in the code |

## Hooks

| Rule | Status | Where |
| --- | --- | --- |
| Own hooks prefixed and documented | Met | `easyq_*`, [hooks.md](hooks.md), doc blocks at each hook |

## Privacy

| Rule | Status | Where |
| --- | --- | --- |
| Suggested privacy policy text (`wp_add_privacy_policy_content()`) | Open, phase 8 | Section 11 of the [feature mapping](feature-mapping.md) |
| Personal data exporter and eraser | Open, phase 8 | Same |
| No tracking, privacy by default, collect only what is needed | Met | No external requests; IP addresses optional shortened, retention in phase 8 |

## Administration menus

| Rule | Status | Where |
| --- | --- | --- |
| Top-level menu only if needed | Met | easyQ has eight screens; a single page would belong under Settings |
| Capability per screen | Met | `easyq_manage`, Posts from `easyq_moderate` |
| Menu icon | Kept | `dashicons-format-chat`. Dashicons are to be replaced in menus (Developer Blog, October 2026), without a replacement yet; a base64 SVG would need `base64_encode()`, which the coding standards discourage. Switch once core offers the replacement |

## Settings

| Rule | Status | Where |
| --- | --- | --- |
| Settings API: `register_setting()`, sections, fields, `options.php` | Met | `Settings\Registry`, `Admin\Settings_Screen` |
| Layout of the core settings screens | Met | Sections with headings; related settings in one row with a fieldset (Settings → Discussion); dependent fields disabled, not hidden (Settings → Reading) |

## Metadata, post types and taxonomies

| Rule | Status | Where |
| --- | --- | --- |
| User meta prefixed and site specific on multisite | Met | `Users\Site_Meta` |
| Custom post types and taxonomies | Kept | Custom tables instead (decided 2026-10-13). The handbook explains post types but neither requires them nor rules out tables; `dbDelta()` is the core way to create them. The Q&A data needs indexed columns for its lists (hot, unanswered, votes, per category), per-user votes and flags, points, rate limits and a word index; as post meta these would be unindexed joins. In exchange, core screens, export, revisions and plugins that only know posts do not cover the Q&A content; easyQ provides its own screens, sitemaps, feeds and a search provider interface, and the privacy tools follow in phase 8. See the [feature mapping](feature-mapping.md), section 1 |

## Users

| Rule | Status | Where |
| --- | --- | --- |
| Check capabilities, not roles | Met | Meta capabilities in `map_meta_cap`; easyQ adds no roles |

## HTTP API

| Rule | Status | Where |
| --- | --- | --- |
| Requests through the HTTP API | Not used | easyQ makes no external requests; the dashboard has no version check and the IP screen no host name lookup |

## JavaScript

| Rule | Status | Where |
| --- | --- | --- |
| Enqueue on the right hook, only on the screen that needs it | Met | Settings and tools scripts on their `load-` hook; blocks through `block.json` |
| Dependencies and version from the build, in the footer with a loading strategy | Met | `Util\Assets`, `in_footer` and `defer` |
| Bundled WordPress libraries instead of own copies | Met | `wp-api-fetch`, `wp-i18n`, React from core (`react-jsx-runtime`) |
| Script translations | Met | `wp_set_script_translations()` |

## Cron

| Rule | Status | Where |
| --- | --- | --- |
| Schedule on activation, clear on deactivation | Not used yet | Comes with the IP address retention in phase 8 |

## Internationalization

| Rule | Status | Where |
| --- | --- | --- |
| Text domain `easyq` as a literal, placeholders, translator comments, plurals | Met | PHPCS i18n sniffs |
| `load_plugin_textdomain()` on `init` for bundled translations | Open, phase 9 | No translations are bundled yet; only `languages/easyq.pot` |

## Plugin directory

| Rule | Status | Where |
| --- | --- | --- |
| Guidelines 1–18 (GPL, readable code, no tracking, no third-party code, no forced credits, no dashboard hijacking, bundled libraries) | Met | — |
| `readme.txt` with `Contributors` | Open, phase 9 | The wordpress.org user name is still missing |

## REST API

| Rule | Status | Where |
| --- | --- | --- |
| Namespace `vendor/v1`, routes on `rest_api_init` | Met | `easyq/v1`, `REST\REST_API` |
| `permission_callback` on every route | Met | All controllers |
| Arguments with types and validation; `WP_Error` with a status | Met | Argument schemas, `REST\REST_Errors` |
| Controllers extend `WP_REST_Controller` | Met | `REST\Controller` |
| Response schema for each endpoint | Open | Questions, answers and comments have one; categories, users, user fields, moderation, IP addresses, tools and actions do not yet |

## Blocks

| Rule | Status | Where |
| --- | --- | --- |
| `block.json` with the current `apiVersion`, namespace and text domain | Met | `apiVersion` 3 for all 46 blocks |
| Component props `__next40pxDefaultSize` and `__nextHasNoMarginBottom` | Kept | Without effect since WordPress 7.1, but needed from 6.7 to 7.0; remove once the minimum is 7.1 |
| Editor canvas is always an iframe (7.1) | Met | The editor script uses no global `document` or `window` |

## Admin list tables

| Rule | Status | Where |
| --- | --- | --- |
| Core list table markup, primary column as row header | Met | `WP_List_Table` subclasses |
| Short row header name (`get_primary_column_aria_label()`, 7.1) | Met | Posts, categories and user fields lists |
