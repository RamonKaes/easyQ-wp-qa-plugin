# WordPress guidelines checklist

How easyQ meets the WordPress developer documentation, chapter by chapter. Checked
on 2026-10-29 against WordPress 7.1.4. Checked again on 2026-11-14 for the REST API,
the forms, the admin screens, the settings and the uploads (rows A, B, F, G and I of
[review.md](review.md)), against the handbooks and against core; the evidence is in
the last section. A **Met** without evidence there counts as not checked.

Sources:

- [Plugin Handbook](https://developer.wordpress.org/plugins/)
- [Code Reference](https://developer.wordpress.org/reference/)
- [Common APIs Handbook](https://developer.wordpress.org/apis/),
  [REST API Handbook](https://developer.wordpress.org/rest-api/),
  [Block Editor Handbook](https://developer.wordpress.org/block-editor/)
- [Developer Blog](https://developer.wordpress.org/news/) and
  [dev notes](https://make.wordpress.org/core/tag/dev-notes/) for recent changes

Status: **Met**, **Open** (with the phase it belongs to), **Not used** (easyQ has
nothing the chapter applies to), **Kept** (a deliberate choice, with the reason and
the word of the site owner). A status with **F-n** names an open finding in
[findings.md](findings.md).

Automated checks cover part of this on every change: PHPCS with the WordPress Coding
Standards (escaping, sanitizing, nonces, prepared SQL, i18n), Plugin Check, PHPStan,
axe, every Q&A page at 320 pixels and the buttons against the core block in 21 themes,
PHP messages on the pages, the number of queries of every list, named hook callbacks, the options an uninstalled site keeps, the documents against the
code, and known vulnerabilities of the dependencies.

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
| Capability checks with `current_user_can()` for every action | Met; F-22 | REST `permission_callback`s, admin screens, `Capabilities` (meta capabilities) |
| Sanitize input, validate before saving | Met | Settings API through `sanitize_option_{option}` with a named callback, REST argument schemas, services validate; the forms hand on what was typed and the REST API sanitizes (F-5) |
| Escape late, on output | Met | PHPCS `EscapeOutput` |
| Nonces on forms and links that change something | Met | Admin forms and row actions, site forms, each per action and post as core's `delete-comment_{id}` (`Form_Handler::nonce_action()`, `easyq-post-{action}_{id}`); REST uses the core `wp_rest` nonce. Evidence: `Form_Handler_Test`, `Posts_Screen_Test`, `Users_Screen_Test` |
| SQL only through `$wpdb->prepare()` | Met | PHPCS `PreparedSQL`, fixed SQL with placeholders |
| Redirects with `wp_safe_redirect()` | Met | No `wp_redirect()` in the code |

## Hooks

| Rule | Status | Where |
| --- | --- | --- |
| Own hooks prefixed and documented | Met | `easyq_*`, [hooks.md](hooks.md), doc blocks at each hook |

## Privacy

| Rule | Status | Where |
| --- | --- | --- |
| Suggested privacy policy text (`wp_add_privacy_policy_content()`) | Met | `EasyQ\Privacy\Policy_Text` on `admin_init`, in the markup of the suggested text of core, following the settings (section 11 of the [feature mapping](feature-mapping.md)); `Policy_Text_Test` |
| Personal data exporter and eraser | Met | `EasyQ\Privacy\Privacy`, `Post_Data` and `Profile_Data` after `wp_comments_personal_data_exporter()` and `_eraser()`: posts, votes, flags, profile, favorites, updates, notices, messages, wall posts and the newsletter, 500 posts per page; `Personal_Data_Test`, `Privacy_Test` (decisions 54 and 77) |
| Users visible to visitors as core shows them | Met | `User_Query::is_shown()` after `WP_REST_Users_Controller::get_item_permissions_check()`: public once a user took part; the registration date in the edit context only (`shows_registration()`), also for the row "Member for" of the profile block. Evidence: `Users_REST_Test`, `User_Blocks_Test`, counter-checks in the commit of F-13 |
| No tracking, privacy by default, collect only what is needed | Met | No external requests; IP addresses optional shortened and removed after `ip_retention_days` (`IP_Retention_Test`) |
| Consent recorded where it is asked | Met | Newsletter (time of the confirmed opt-in) and the registration terms (`Users\Registration`: time and text), both exported; the registration form adds its parts through `register_form` and `registration_errors` (`Registration_Test`). On a network the sign-up of `wp-signup.php` asks for the same fields and terms (`signup_extra_fields`, `wpmu_validate_user_signup`), keeps them in the sign-up meta and saves them when the account is activated (`wpmu_activate_user`); evidence: `Registration_Test` on the multisite suite |

## Administration menus

| Rule | Status | Where |
| --- | --- | --- |
| Top-level menu only if needed | Met | easyQ has nine screens; a single page would belong under Settings |
| Capability per screen | Met | `easyq_manage`, Posts from `easyq_moderate` |
| Menu icon | Kept; reasons to be rewritten, F-38 | `dashicons-format-chat`. Dashicons are to be replaced in menus (Developer Blog, October 2026), without a replacement yet; a base64 SVG would need `base64_encode()`, which the coding standards discourage. Switch once core offers the replacement |

## Settings

| Rule | Status | Where |
| --- | --- | --- |
| Settings API: `register_setting()` with `default`, sections, fields, `options.php`, `add_settings_error()` for a value that cannot be saved | Met | `Settings\Registry`, `Settings\Fields::problem()`, `Admin\Settings_Screen`; evidence: `Settings_Test` (every field that can refuse a value, read from the sections) |
| Layout of the core settings screens | Met | Sections with headings; related settings in one row with a fieldset (Settings → Discussion); dependent fields disabled, not hidden (Settings → Reading) |
| Design system of 7.1 (`@wordpress/theme`, `--wpds-*` tokens, `ThemeProvider`) | Not used | It styles React interfaces built from `@wordpress/components`; the classic admin styles (`forms.css`, `common.css`, `list-tables.css`) do not use it. easyQ's screens are Settings API and list table pages with core classes; the editor controls come from `@wordpress/components` and take the tokens from core |

## Metadata, post types and taxonomies

| Rule | Status | Where |
| --- | --- | --- |
| User meta prefixed and site specific on multisite | Met | `Users\Site_Meta`, also for profile field values and the time of the last post |
| Custom post types and taxonomies | Kept | Custom tables instead (decided 2026-10-13). The handbook explains post types but neither requires them nor rules out tables; `dbDelta()` is the core way to create them. The Q&A data needs indexed columns for its lists (hot, unanswered, votes, per category), per-user votes and flags, points, rate limits and a word index; as post meta these would be unindexed joins. In exchange, core screens, export, revisions and plugins that only know posts do not cover the Q&A content; easyQ provides its own screens, sitemaps, feeds and a search provider interface, and the privacy tools follow in phase 8. See the [feature mapping](feature-mapping.md), section 1 |

## Users

| Rule | Status | Where |
| --- | --- | --- |
| Check capabilities, not roles | Met | Meta capabilities in `map_meta_cap`; easyQ adds no roles |

## Email

| Rule | Status | Where |
| --- | --- | --- |
| `wp_mail()`, filters and language per recipient as in `wp_notify_postauthor()` | Met | `Mail\Mailer` (`switch_to_user_locale()`, decoded site name, `blog_charset`), filters in [hooks.md](hooks.md) |
| Registration emails through core's filters | Met | `Mail\Notifications`; core's link to set the password stays |
| Editable texts through the Settings API | Met | `Mail\Templates` with `register_setting()`, `Admin\Email_Templates_Screen` with `settings_fields()`, list through `WP_List_Table` |
| Stop emails with one click | Met | Signed link to a page with a form, `List-Unsubscribe` and `List-Unsubscribe-Post` (RFC 8058) |
| `Reply-To` of the author, as core's comment emails | Kept | Addresses stay private on a Q&A site; Q2A sets none there either |

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
| Schedule on activation, clear on deactivation | Met | Newsletter batches as single events (`wp_schedule_single_event()`), and the daily removal of IP addresses (`wp_schedule_event()` on `init`, like `wp_schedule_delete_old_privacy_export_files()`), cleared on deactivation and uninstall, on every site of a network; `IP_Retention_Test` finds every `CRON_HOOK` and checks both |

## Internationalization

| Rule | Status | Where |
| --- | --- | --- |
| Text domain `easyq` as a literal, placeholders, translator comments, plurals | Met for what PHPCS checks; F-31 | PHPCS i18n sniffs |
| `load_plugin_textdomain()` on `init` for bundled translations | Open, phase 9; F-38 | No translations are bundled yet; only `languages/easyq.pot` |

## Plugin directory

| Rule | Status | Where |
| --- | --- | --- |
| Guidelines 1–18 (GPL, readable code, no tracking, no third-party code, no forced credits, no dashboard hijacking, bundled libraries) | F-25 for guideline 16; the others as in the last section | — |
| `readme.txt` with `Contributors` | Open, phase 9; F-25 | The wordpress.org user name is still missing |

## REST API

| Rule | Status | Where |
| --- | --- | --- |
| Namespace `vendor/v1`, routes on `rest_api_init` | Met | `easyq/v1`, `REST\REST_API` |
| `permission_callback` on every route | Met | All controllers; writing routes check in `*_permissions_check()` methods. `__return_true` only on reading routes, whose items check what the viewer may see; the unsubscribe and newsletter confirmation routes check the signed token or key of the email in theirs. `REST_Roles_Test` fails on a writing route with `__return_true` |
| Arguments with types and validation; `WP_Error` with a status | Met; F-30 | Argument schemas, `REST\REST_Errors` |
| Controllers extend `WP_REST_Controller` | Met | `REST\Controller` |
| Response schema for each endpoint | Met | Every route names its schema in `register_rest_route()`; a test checks real responses of all routes against them, both types and undocumented properties |
| Controller pattern of `WP_REST_Controller` | F-26 to F-30 | One controller per resource; `get_item_schema()` cached with `add_additional_fields_schema()`, `get_public_item_schema()` for the routes, `prepare_item_for_response()` with `register_rest_field()` and `filter_response_by_context()`, `context` on every property and reading route, `deleted` with `previous` (decision 51) |
| Page states like `replytocom` | Met | Edit and close states: `wp_robots_no_robots()`, `rel="nofollow"`, cancel links back to the post (decision 51) |

## Blocks

| Rule | Status | Where |
| --- | --- | --- |
| `block.json` with the current `apiVersion`, namespace and text domain | Met | `apiVersion` 3 for all 52 blocks |
| Component props `__next40pxDefaultSize` and `__nextHasNoMarginBottom` | Kept | Without effect since WordPress 7.1, but needed from 6.7 to 7.0; remove once the minimum is 7.1 |
| Editor canvas is always an iframe (7.1) | Met | The editor script uses no global `document` or `window` |
| Frontend markup styled by the theme, no plugin stylesheet | Met | Core block classes and style handles; forms take on the comment form block, so text fields, buttons and checkboxes look as in the theme's comment form ([feature mapping](feature-mapping.md), decision 46). Selects keep the browser look, as in core |
| Buttons in a buttons block, as core places them | Met | Vote buttons, post actions and favorites through the layout support of their blocks; other buttons rendered through the core buttons block; form submit fields as `p.form-submit.wp-block-button`, as the comment form block (decisions 47, 69) |
| Reflow at 320 pixels (WCAG 1.4.10) | Met | Core columns break long words (decision 58); the E2E run loads every page in 21 themes |
| Outline buttons as in core | Met | Rendered through the core button block; the E2E run compares them with it in 21 themes (decision 59) |
| Classic themes | Met | The theme's own page template shows the Q&A pages, with its title, containers and sidebars (decision 48) |

## Admin list tables

| Rule | Status | Where |
| --- | --- | --- |
| Core list table markup, primary column as row header | Met | `WP_List_Table` subclasses with the screen of the page, the column headers computed by core (`get_default_primary_column_name()`), views from `get_views_links()`. Evidence: `Admin_Capabilities_Test`, `Posts_Screen_Test` |
| Short row header name (`get_primary_column_aria_label()`, 7.1) | Met | Posts, categories, user fields and email templates lists |

## Chapters added on 2026-11-14

| Rule | Status | Where |
| --- | --- | --- |
| REST: links of a resource (`_links`, `prepare_links()`) and filters of the response (`rest_prepare_{type}`) | F-26 | Core: `prepare_item_for_response()` of the posts, users and comments controllers. Plugin: none in `includes/rest/` |
| REST: pagination as core (`X-WP-Total`, `X-WP-TotalPages`, `Link`, 400 beyond the last page) | F-27 | Totals on eight collections through `REST\Controller`; no `Link`, no 400, six collections without totals |
| REST: `DELETE` with `force` where nothing can be trashed | F-28 | Seven routes delete without the argument |
| REST: `context` on every reading route, `rest_forbidden_context` | F-29 | Five reading routes without the argument (read from the server) |
| REST: 201 with `Location` on create, 401 for visitors and 403 for users, 404 for what is missing | Met for posts, categories and user fields; F-30 | `REST\Controller`, `REST\REST_Errors` |
| REST: `_fields` and `register_rest_field()` | Met | `REST\Controller` (`get_fields_for_response()`, `add_additional_fields_to_object()`) |
| Forms: handled on `template_redirect`, `wp_safe_redirect()` and `exit` | Met | `Frontend\Form_Handler` |
| Forms: whole sentences for translators | F-31 | `includes/blocks/render/class-forms.php` |
| Forms: nonce action as specific as possible; required fields marked as the comment form does (`wp_required_field_indicator()`, `wp_required_field_message()`); fields kept after a failure, also on the registration form | Met | `Frontend\Form_Handler`, `includes/blocks/render/class-forms.php`, `Users\Registration`; a failure shows the page again (decision 81). Evidence: `Blocks_Test`, `Registration_Test` |
| Registration: `register_form`, `registration_errors`, `user_register`; markup of `wp-login.php` | Met on a single site | `Users\Registration` |
| Registration on a multisite: `signup_extra_fields`, `wpmu_validate_user_signup` | F-32 | `Users\Blocked_Names` and `Limits\Account_Limits` cover the sign-up, `Users\Registration` does not |
| Uploads: `wp_handle_upload()` with the allowed types, `wp_get_image_editor()` and the file its `save()` writes, `wp_delete_file()`, no direct file functions | Met | `Users\Avatars`; evidence: `Users_REST_Test`, `Avatars_Test` |
| Avatar uploads without `upload_files` | Kept, decided by the site owner of the project on 2026-11-15 | Question2Answer lets every user upload an avatar, and so do common avatar plugins; the image is the user's own and goes with them (F-20). The setting is off by default (decision 84), so a site owner allows it on purpose |
| Uploads: capability `upload_files` | F-34, to decide | Login and the setting instead |
| Avatars through `pre_get_avatar_data` | Met | `Users\Avatars` |
| Admin notices through `wp_admin_notice()` | Met | Every notice of the screens; field errors stay next to their fields |
| List screens: hidden headings (`set_screen_reader_content()`), columns in Screen Options, clean address after a filter | Met | The four list tables are built when their screen loads, as in `wp-admin/edit.php`; the posts screen drops `_wpnonce` and `_wp_http_referer` after a filter. Evidence: `Admin_Capabilities_Test` |
| Users list of core: columns, views, bulk actions through the hooks of core | Hooks Met; F-23 | `Admin\Users_Screen` |
| Profile screen: the four hooks with `edit_user` | Met; F-22 | `Admin\Profile_Screen` |
| Help tabs as the core screens have them | Met | An overview on each screen with a `load()` handler. Evidence: `Admin_Capabilities_Test` |
| No inline styles, no duplicate IDs | Met | The newsletter buttons are one form; delete and move buttons have names of their own. Evidence: `Admin_Capabilities_Test` (no `style` attribute under `includes/admin/`) |
| Inline documentation: `@since` on classes, methods, properties, constants and hooks | Met | Checked on 2026-11-15 for every constant |
| Readme: a plugin that is ready, source and build named | F-25 | `readme.txt` |
| WP-CLI, Site Health, privacy tools | Open: rows M, N and Q of the review have not been read against the handbooks | `CLI\Command`, `Health\Site_Health`, `Privacy\Privacy` |

## Evidence of 2026-11-14

What the rows marked **Met** rest on, for the rows A, B, F, G and I of the review. Core
is WordPress 7.1.4; a file without a path prefix of the plugin is a file of core.

| Row | Rule set by | Evidence in the plugin | Held by |
| --- | --- | --- | --- |
| Header | Plugin Handbook, Header Requirements | `easyq.php`: all fields, description of 84 characters | Plugin Check |
| Prefix | Plugin Handbook, Best Practices (at least four letters) | Namespace `EasyQ`, `easyq_` everywhere, the result arguments of the screens included, which `removable_query_args` removes | PHPCS `PrefixAllGlobals` |
| `ABSPATH` check | Best Practices; Common Issues, "Direct file access" | Every file of `includes/admin/` and `includes/settings/`; `uninstall.php` checks `WP_UNINSTALL_PLUGIN` | Plugin Check |
| Uninstall | Plugin Handbook, Uninstall Methods | `uninstall.php`, `Uninstaller` | `Uninstaller_Test` |
| Capability for every action | REST API Handbook, Adding Custom Endpoints; `wp-admin/options.php` (capability of the option page) | A `permission_callback` on each of the 41 routes; the handlers of the screens check `easyq_manage`; `option_page_capability_{group}` in `Settings\Registry` and `Mail\Templates` | Mutations of 2026-11-14 (red for 22 checks, green for 5: F-15, F-24) |
| Sanitizing | Common Issues, "Sanitize" | Named keys with a sanitizer each in `Frontend\Form_Handler`; `sanitize_callback` of every setting | PHPCS `ValidatedSanitizedInput` |
| Escaping | PHP Coding Standards | Every `phpcs:ignore` for output in rows B, F and G was read: the value is escaped where it is built | PHPCS `EscapeOutput` |
| Nonces | Common APIs, Nonces | `check_admin_referer()` in every `load-` handler; site forms verify before they act: an expired form only hands what was typed back to its page, in the same request | Mutations (red for posts, users, profile, forms, registration; green for the newsletter buttons) |
| Redirects | `wp-comments-post.php` | `wp_safe_redirect()` and `exit`; `wp_validate_redirect()` for the return address | `Form_Handler_Test::test_does_not_return_to_other_sites` (mutation red) |
| Settings API | Plugin Handbook, Settings API; `wp-admin/options.php` | `settings_fields()`, `do_settings_sections()`, `submit_button()`, `settings_errors()`; registered on `init` because `show_in_rest` needs the setting outside the admin | `Settings_Test` |
| Layout of the settings | `wp-admin/options-discussion.php`, `options-general.php` | Fieldset with a hidden legend, `aria-describedby` on descriptions, tabs with `aria-current` | `Settings_Test` (mutation red), axe |
| Capabilities, not roles | Plugin Handbook, Users | No role name in the five rows | PHPCS `WordPress.WP.Capabilities` |
| REST namespace and hook | REST API Handbook, Adding Custom Endpoints | Namespace of the plugin with `v1`, routes on `rest_api_init` in `REST\REST_API` | `REST_Roles_Test` reads the routes |
| REST schema | REST API Handbook, Schema | `$schema` and `title` in `REST\Schemas` and `REST\Post_Schema` | `REST_Schemas_Test` (mutation red; the list of responses is by hand) |
| Scripts on the screens | Common Issues, "Use wp_enqueue commands" | Enqueued from the `load-` hook of the settings and tools screens, no inline script | — (read) |
| Dashboard widget | Common APIs, Dashboard Widgets; guideline 11 | `wp_add_dashboard_widget()` on `wp_dashboard_setup`, capability checked | `Dashboard_Tools_Screens_Test` (mutation red) |
| Guidelines 1, 5, 7, 8, 11, 12, 13, 15, 17 | Detailed Plugin Guidelines | GPL in header, readme and `LICENSE`; no locked features; no request and no remote script in the five rows; one widget that can be hidden; five tags; `wp-api-fetch` and `wp-i18n` from core; one version in header and readme; "Question2Answer" in descriptions only | Plugin Check for part; the rest read |
| Guidelines 4 and 16 | Detailed Plugin Guidelines | `src/` ships next to `build/`; the readme names neither the repository nor the build; the readme says the plugin is not ready | F-25 |

Not checked against a source in this round: the row on the design system of 7.1, the
Kept rows on custom tables and on `Reply-To`, and every section the five rows do not
touch (blocks, cron, email, hooks, JavaScript of the blocks).

