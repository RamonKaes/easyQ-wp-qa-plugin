# Review: what is checked, by what, and what is still open

This document is the state of the checks of easyQ. It does not say that the plugin is
free of errors; no review can. It says, for every place where something foreign enters
the plugin, along which lines that place has been attacked and which test holds the
result. A cell without a test is `open`, and `open` is said out loud.

Findings are in `docs/findings.md`. How easyQ meets the WordPress guidelines is in
`docs/guidelines-checklist.md`.

## Rules of the matrix

Rows are **entry classes**: every place through which something foreign reaches the
plugin, or through which the plugin acts on the outside. Columns are **axes** along
which each entry is attacked. Each cell holds exactly one of:

- **✓**: a test or tool holds the axis for the **whole** class, the allowed **and** the
  refused case. The test is named below the matrix.
- **partly**: shown for a part of the class, or by a test no review has confirmed yet.
- **F-n**: an open finding in `docs/findings.md`.
- **—**: does not apply, with the reason below the matrix.
- **open**: nobody has checked it.

Four rules decide what a cell may say:

1. **A ✓ reads the class from the plugin.** The test takes the set it checks from the
   registered routes, blocks, hooks, settings or the source. A test that lists
   representatives is `partly`, even if none is missing today.
2. **A guard on the source is a net, not a proof.** It carries a ✓ only next to a test
   that measures the behavior, or when it reads the form completely (tokens instead
   of text) and names its blind spot.
3. **A cell only gets better with evidence.** A review that raises a cell names the
   test and has seen it fail: the fix or the checked line removed, the test red, the
   file restored and compared.
4. **A cross-check that refutes a ✓ sets the cell back** and adds a finding. The
   finding names why the guard did not see it.

There is no severity threshold for what is unchecked: every `open` cell is listed in
the report of a review, whatever its row.

## Axes

| Axis | Question |
| --- | --- |
| Form | Array instead of string, empty, missing, twice, wrong type? |
| Value | Limits, Unicode, control characters, `%` and `_` in searches, `../`, length against the column, nested or pathological HTML |
| Identity | Visitor, subscriber, blocked user, unconfirmed user, author, other author, each level of easyQ, administrator, super admin: each allowed **and** refused |
| Frequency | What does the hundredth call cost, is it limited, can a form or a link be replayed? |
| Concurrency | Two tabs, double click, two users on the same row |
| Volume | Many rows: growth of the queries instead of their number, size of the response, batches |
| Time | Deadlines, the clock, expired nonces and keys, missed scheduled events |
| State | Target hidden, deleted, closed, moved, already done; a stale tab |
| Abuse | A sequence over several features, several accounts, several days; content that hits third parties |
| Error path | Which status, which message, which log entry, and does the typed text stay? |
| Presentation | Block themes and classic themes, 320 pixels, keyboard, screen reader, right-to-left, extreme user content |
| Multisite | Per-site tables and options, switched sites, network activation, super admins |

## Entry classes

The inventory is **drawn again for every review**, not copied from this table; the
table is the state, not the source.

```bash
grep -rn "register_rest_route(" includes                       # A
grep -n "easyq_action\|\$_POST\|\$_FILES" includes/frontend includes/users   # B, I
grep -rn "\$_GET\|\$_REQUEST\|get_query_var(" includes         # C, F
ls blocks && grep -rn "register_block_pattern" includes    # D
grep -rln "wp_kses\|Content_Renderer" includes                 # E
grep -rn "add_menu_page\|add_submenu_page\|admin_post_" includes   # F
grep -rn "register_setting(" includes                          # G
grep -rn "\$_COOKIE\|\$_SERVER" includes                       # H
grep -rn "wp_mail(" includes                                   # J
grep -rn "wp_schedule_" includes                               # K
grep -rn "add_feed(\|wp_sitemaps" includes                     # L
grep -rn "WP_CLI::add_command" includes                        # M
grep -rn "wp_privacy_personal_data_" includes                  # N
grep -rn "register_activation_hook\|register_deactivation_hook\|wp_initialize_site\|wp_uninitialize_site" easyq.php includes   # O
grep -rhn "apply_filters(\|do_action(" includes | grep easyq_  # P
grep -rn "site_status_tests\|debug_information" includes       # Q
grep -rn "wp_remote_\|wp_safe_remote_" includes                # must stay empty
```

An entry that fits no row is a new row.

| | Class | Where |
| --- | --- | --- |
| A | REST API | `includes/rest/` |
| B | Forms of the Q&A pages and of the registration | `includes/frontend/class-form-handler.php`, `includes/users/class-registration.php` |
| C | Addresses and query parameters of the Q&A pages | `includes/frontend/class-routes.php`, `includes/blocks/render/` |
| D | Block attributes and patterns | `blocks/`, `src/`, `includes/blocks/` |
| E | Text of users on output | `includes/posts/class-content-renderer.php`, `includes/blocks/render/` |
| F | Admin screens | `includes/admin/` |
| G | Settings | `includes/settings/` |
| H | Cookies, IP address and request headers | `includes/users/class-visitor-cookie.php`, `includes/util/class-ip.php`, `includes/notices/class-notices.php` |
| I | Uploads | `includes/users/class-avatars.php` |
| J | Emails and the links in them | `includes/mail/`, `includes/newsletter/class-newsletter.php` |
| K | Scheduled events | `includes/privacy/class-ip-retention.php`, `includes/newsletter/class-newsletter.php` |
| L | Feeds and sitemap | `includes/feeds/class-feeds.php`, `includes/frontend/class-sitemap-provider.php` |
| M | WP-CLI | `includes/cli/class-command.php` |
| N | Privacy tools of WordPress | `includes/privacy/` |
| O | Activation, upgrade, deactivation, uninstall, new and deleted sites | `includes/database/`, `includes/class-uninstaller.php` |
| P | Values other plugins return through the filters | `docs/hooks.md` |
| Q | Site Health | `includes/health/class-site-health.php` |

## State on 2026-11-14

The matrix was set up on this day from the tests that exist. No review has confirmed
a cell yet, so no cell carries a ✓: what a test appears to hold is `partly`, and
the first review either raises it with evidence or says what is missing.

| | Form | Value | Identity | Frequency | Concurrency | Volume | Time | State | Abuse | Error path | Presentation | Multisite |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A | partly | partly | partly | partly | open | partly | open | partly | open | partly | — | partly |
| B | open | partly | partly | partly | open | — | partly | partly | open | partly | partly | partly |
| C | open | open | partly | open | — | partly | — | partly | open | partly | partly | partly |
| D | open | partly | open | — | — | partly | — | open | open | open | partly | partly |
| E | — | partly | partly | — | — | open | — | partly | open | — | partly | partly |
| F | open | open | partly | — | open | open | open | open | open | open | partly | partly |
| G | open | partly | partly | — | open | — | — | — | — | open | partly | partly |
| H | open | partly | — | partly | — | — | partly | open | open | open | — | open |
| I | open | partly | open | open | open | open | — | open | open | open | open | open |
| J | — | partly | partly | partly | open | partly | partly | partly | open | open | open | open |
| K | — | — | — | partly | open | partly | open | open | — | open | — | partly |
| L | open | open | partly | open | — | partly | open | partly | — | partly | open | open |
| M | partly | — | open | — | open | open | — | open | — | partly | — | open |
| N | — | — | partly | — | open | partly | — | partly | — | open | — | partly |
| O | — | — | open | — | open | open | — | partly | — | open | — | partly |
| P | open | open | — | — | — | — | — | — | open | open | — | — |
| Q | — | — | open | — | — | open | — | partly | — | partly | partly | open |

### What carries the `partly` cells

- **A**: `REST_Roles_Test` takes every route from the server and calls it as each
  role (Identity, Error path); `REST_Schemas_Test` holds every response against its
  schema (Form, of the responses only); `Post_Validator_Test` and the REST tests of
  each resource (Value, State); `Limits_Test` (Frequency); `Query_Growth_Test`
  (Volume, lists only).
- **B**: `Form_Handler_Test` (expired forms, kept values, return address),
  `Registration_Test`, `Limits_Test`; the end-to-end tests with axe (Presentation).
- **C**: `Routing_Test`, `Blocks_Test`, `Query_Growth_Test`; hidden posts for
  visitors (Identity, State).
- **D**: `Blocks_Test`, `Block_Settings_Test`, `Patterns_Test`; the editor and theme
  tests end to end.
- **E**: `Content_Renderer_Test`, the censoring tests in `Blocks_Test` and
  `Routing_Test`.
- **F**: the screen tests (`Posts_Screen_Test`, `Users_Screen_Test`,
  `Categories_Screen_Test` and the others) and their end-to-end tests; they list
  screens, they do not read them from the menu.
- **G**: `Settings_Test`, the settings end to end.
- **H**: `Limits_Test` (blocked and anonymized addresses), `IP_Retention_Test`, the
  unit test of the IP class, the cookie test in `Permissions_Test`.
- **I**: `Avatars_Test` (only images).
- **J**: `Notifications_Test`, `Newsletter_Test`, `Email_Templates_Test`,
  `Unsubscribe_REST_Test`.
- **K**: `IP_Retention_Test`, `Newsletter_Test` (batches),
  `Multisite_Installer_Test` (events of every site).
- **L**: `Feeds_Test`, `Sitemap_Provider_Test`.
- **M**: `tests/e2e/cli.spec.js` (order, unknown tool, the question before deleting).
- **N**: `Personal_Data_Test`, `Privacy_Test`.
- **O**: `Installer_Test`, `Multisite_Installer_Test`, `Uninstaller_Test`,
  `tests/e2e/activation.spec.js`.
- **Q**: `Site_Health_Test`, `tests/e2e/site-health.spec.js`.
- **Multisite**: the integration tests run a second time as a multisite in the CI.
  That shows that nothing breaks on a multisite, not that sites are separated; only
  the tests that switch sites show that.

### Why a cell does not apply

- **Presentation** for A, H, K, M, N, O, P: nothing is shown; the REST responses are
  under Form.
- **Form** and **Value** for J to O and Q: the entry takes no free input of its own;
  what it handles came in through another row.
- **Identity** for H and K: cookies and scheduled events have no user; what a cookie
  may claim is under Abuse.
- **Frequency**, **Concurrency**, **Time**, **Abuse**: marked — only where the entry
  cannot be called by a user, or where calling it again changes nothing.
- **P**: a filter is called by code the site owner installed, with their rights;
  what counts is whether a wrong return value breaks a page (Form, Value, Error
  path).

Each — is a claim like any other cell: a review checks whether the reason still
holds.

## Cross-cutting standards

| Standard | Held by | Not covered |
| --- | --- | --- |
| WordPress Coding Standards, escaping, nonces, prepared SQL | PHPCS in the CI | Whether the escaping fits the context; whether a nonce is also a permission check |
| Types | PHPStan at level max, without a baseline | Values that arrive at run time with another type |
| WordPress.org guidelines | Plugin Check, `docs/guidelines-checklist.md` | The manual review of the plugin team |
| WCAG 2.2 AA | axe on the pages of the end-to-end tests | What axe cannot see: order of focus, names that make sense, announcements after an action without reload; a walk with a screen reader |
| Privacy | No outgoing request in `includes/`; `Personal_Data_Test`, `IP_Retention_Test`, `Policy_Text_Test` | Whether the suggested policy text names every stored value |
| Documents match the code | `Docs_Test` | Sentences; it checks names, routes, hooks and paths |
| Lines no test runs | Coverage in the CI, as a report only | Lines that run without being checked; see F-2 |

## Release gate

Version 0.1.0 is not released while one of these holds:

1. A finding classed **blocker** is open.
2. A cell of the columns Identity, Value or Error path is `open` in the rows A, B, F,
   G or I.
3. A cell that applies has never been looked at by a review: `open` is allowed
   elsewhere at the release, but only as a decision that is written down with its
   reason in `docs/findings.md`.

A finding is a **blocker** when it lets someone read or change what their role does
not allow; when it runs foreign script or SQL; when it loses or damages data; when
it is a fatal error or a PHP message on a supported version of WordPress or PHP;
when it keeps someone from finishing a task with the keyboard or a screen reader;
when it breaks a promise of the readme (no external requests, no tracking, data
removed on request); or when it would fail the review of WordPress.org. Everything
else is **should** (fixed before the release unless deferred with a reason) or
**low**.

## When another round is needed

- After every phase of `docs/feature-mapping.md`: the rows the phase touched.
- After a finding a guard should have seen: every cell that guard carries.
- Before the release: the whole matrix, then a cross-check in a fresh context that
  does not get the reasons of the review and tries to refute the ✓ cells first.

A review never reports "no errors". It reports the state of the release gate and
every cell that is not ✓.
