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
| Guidelines | Does it follow the handbooks and do it the way core does, with the place in the handbook or in core? Every row of `docs/guidelines-checklist.md` that applies has its evidence |

## Entry classes

The inventory is **drawn again for every review**, not copied from this table; the
table is the state, not the source.

Every command searches fixed strings (`grep -F`, one `-e` per string), so `$` and `(` mean
themselves in GNU grep and in the ugrep that some shells put behind `grep`. Each command
but the last must print at least one line; an empty result is a broken command, not an
empty class.

```bash
grep -rnF -e 'register_rest_route(' includes                                        # A
grep -rnF -e 'easyq_action' -e '$_POST' -e '$_FILES' includes/frontend includes/users   # B, I
grep -rnF -e '$_GET' -e '$_REQUEST' -e 'get_query_var(' includes                     # C, F
ls blocks && grep -rnF -e 'register_block_pattern' includes                         # D
grep -rlnF -e 'wp_kses' -e 'Content_Renderer' includes                               # E
grep -rnF -e 'add_menu_page' -e 'add_submenu_page' -e 'admin_post_' includes         # F
grep -rnF -e 'register_setting(' includes                                            # G
grep -rnF -e '$_COOKIE' -e '$_SERVER' includes                                       # H
grep -rnF -e 'wp_mail(' includes                                                     # J
grep -rnF -e 'wp_schedule_' includes                                                 # K
grep -rnF -e 'add_feed(' -e 'wp_sitemaps' includes                                   # L
grep -rnF -e 'WP_CLI::add_command' includes                                          # M
grep -rnF -e 'wp_privacy_personal_data_' includes                                    # N
grep -rnF -e 'register_activation_hook' -e 'register_deactivation_hook' -e 'wp_initialize_site' -e 'wp_uninitialize_site' easyq.php includes   # O
grep -rhnF -e 'apply_filters(' -e 'do_action(' includes | grep -F -e "'easyq_"       # P
grep -rnF -e 'site_status_tests' -e 'debug_information' includes                     # Q
grep -rnF -e 'wp_remote_' -e 'wp_safe_remote_' includes                              # must stay empty
```

An entry that fits no row is a new row.

| | Class | Where |
| --- | --- | --- |
| A | REST API | `includes/rest/` |
| B | Forms of the Q&A pages and of the registration | `includes/frontend/class-form-handler.php`, `includes/frontend/class-flash.php`, `includes/users/class-registration.php`, `includes/users/class-blocked-names.php`, `includes/limits/class-account-limits.php` |
| C | Addresses and query parameters of the Q&A pages | `includes/frontend/class-routes.php`, `includes/blocks/render/` |
| D | Block attributes and patterns | `blocks/`, `src/`, `includes/blocks/` |
| E | Text of users on output | `includes/posts/class-content-renderer.php`, `includes/blocks/render/` |
| F | Admin screens, with the users list and the profile screen of core | `includes/admin/` |
| G | Settings | `includes/settings/`, `includes/mail/class-templates.php` |
| H | Cookies, IP address and request headers | `includes/users/class-visitor-cookie.php`, `includes/util/class-ip.php`, `includes/notices/class-notices.php` |
| I | Uploads | `includes/users/class-avatars.php`, read from the request in `includes/frontend/class-form-handler.php` and `includes/rest/class-users-controller.php` |
| J | Emails and the links in them | `includes/mail/`, `includes/newsletter/class-newsletter.php` |
| K | Scheduled events | `includes/privacy/class-ip-retention.php`, `includes/newsletter/class-newsletter.php` |
| L | Feeds and sitemap | `includes/feeds/class-feeds.php`, `includes/frontend/class-sitemap-provider.php` |
| M | WP-CLI | `includes/cli/class-command.php` |
| N | Privacy tools of WordPress | `includes/privacy/` |
| O | Activation, upgrade, deactivation, uninstall, new and deleted sites | `includes/database/`, `includes/class-uninstaller.php` |
| P | Values other plugins return through the filters | `docs/hooks.md` |
| Q | Site Health | `includes/health/class-site-health.php` |

## State on 2026-11-14

The first round went through the rows A, B, F, G and I: the tools ran, the inventory
was drawn again, the source and the tests of the five rows were read completely, 104
checks were removed one at a time with the whole integration suite run against each,
and the findings were reproduced. The other rows are as the matrix was set up: their
`partly` cells are named from the tests that exist and no review has confirmed them.

No cell of the five rows is a ✓. No test of these rows both reads its set from the
plugin and asserts the allowed and the refused case; the one test that reads the
routes from the server holds less than it seemed (F-15). The column Guidelines is new
in this round and `open` wherever no review has read the row against the handbooks.

| | Form | Value | Identity | Frequency | Concurrency | Volume | Time | State | Abuse | Error path | Presentation | Multisite | Guidelines |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A | partly | partly | F-4 | partly | open | partly | partly | F-8 | open | partly | — | F-7 | F-26 |
| B | open | F-5 | partly | partly | open | — | partly | partly | F-17 | F-6 | partly | F-32 | F-31 |
| C | open | open | partly | open | — | partly | — | partly | open | partly | partly | partly | open |
| D | open | partly | open | — | — | partly | — | open | open | open | partly | partly | open |
| E | — | partly | partly | — | — | open | — | partly | open | — | partly | partly | open |
| F | open | partly | F-22 | open | open | F-21 | open | F-23 | open | partly | partly | partly | F-35 |
| G | partly | partly | partly | open | open | open | — | open | open | F-37 | partly | partly | F-37 |
| H | open | partly | — | partly | — | — | partly | open | open | open | — | open | open |
| I | open | partly | partly | partly | open | open | — | F-20 | open | F-19 | partly | open | F-34 |
| J | — | partly | partly | partly | open | partly | partly | partly | open | open | open | open | open |
| K | — | — | — | partly | open | partly | open | open | — | open | — | partly | open |
| L | open | open | partly | open | — | partly | open | partly | — | partly | open | open | open |
| M | partly | — | open | — | open | open | — | open | — | partly | — | open | open |
| N | — | — | partly | — | open | partly | — | partly | — | open | — | partly | open |
| O | — | — | open | — | open | open | — | partly | — | open | — | partly | open |
| P | open | open | — | — | — | — | — | — | open | open | — | — | open |
| Q | — | — | open | — | — | open | — | partly | — | partly | partly | open | open |

A cell names one finding; the other findings of the cell are below.

The release gate on this day:

1. Holds: six findings classed blocker are open (F-4, F-5, F-6, F-7, F-8, F-25).
2. Does not hold: no cell of Identity, Value or Error path is `open` in A, B, F, G
   or I. Seven of the fifteen carry a finding, eight are `partly`.
3. Holds: the rows C, D, E, H and J to Q have not been reviewed, and the column
   Guidelines is `open` outside the five rows.

### What the tools said

- Green: `composer lint`, `composer analyse`, `composer test:unit` (89 tests),
  `npm run lint:js`, `npm run test:php` (636 tests, 2 skipped: the network sign-up
  exists on a multisite only), `npm run test:php:multisite` (648 tests),
  `npm run test:e2e` (140 tests).
- `npm run plugin-check`: no error, two warnings for untracked files of the developer
  machine (F-39).
- Lines no test runs were not measured: the local test site runs without a coverage
  driver and the CI reports the sum only (F-2).
- Inventory: 39 calls of `register_rest_route()` in 18 files, 41 routes on the
  server; no outgoing request. Three commands of the inventory were empty in the
  shell of the review (F-3). No entry without a row; the places of B, G and I in the
  table above were completed.

### The cells of A, B, F, G and I

Each line names what holds the cell, the mutation that was seen red (the check
removed, the whole suite run, the file restored and compared), and what is missing
for a ✓. "Green" means the suite did not notice the removal.

#### A, REST API

- Form, partly: `REST_Schemas_Test::test_responses_match_their_schema` (red with a
  wrong type in the post formatter and in the IP response). It reads the routes only
  for "announces a schema"; the responses it checks are a list by hand, without
  messages, wall, notices, newsletter, feedback and the writing responses of
  questions, answers and comments. No test sends an array where a string is expected.
  F-1.
- Value, partly: `Post_Validator_Test` (red with the content limit removed),
  `Questions_REST_Test::test_filters_html_content`, the checks of categories,
  feedback, messages and user fields in their tests (each red). Green: the length
  limit of messages and `esc_like()` in the tag search. Missing: limits read from the
  schema of the tables, Unicode, control characters.
- Identity, F-4, also F-10, F-11, F-13, F-15: the resource tests refuse and allow by
  representatives (red: votes, post actions, the moderation list, editing questions,
  categories, tools, user fields, email templates, newsletter, IP blocks, messages,
  favorites, private profile fields, the unsubscribe token). Green: the edit check
  of answers and of comments, the view permit of `/tags`. No blocked, unconfirmed or
  unapproved user, no level of easyQ and no super admin goes through the routes.
- Frequency, partly: `Questions_REST_Test::test_rate_limits_and_read_only_mode`,
  `Feedback_Test::test_is_limited_per_hour`, the pause of the newsletter confirmation,
  `Limits_Test` (each red). Green: the limits of answers and of messages.
- Concurrency, open: no test. Limits are read in the permission callback and counted
  after the insert.
- Volume, partly: only the loading of the extra field for a list is held
  (`Extra_Field_Test::test_lists_load_the_values_of_all_questions_at_once`).
  `Query_Growth_Test` renders blocks and never sends a REST request.
- Time, partly: `Newsletter_Test::test_confirmation_keys_expire`,
  `Limits_Test::test_counts_restart_every_hour` (each red), both at service level.
- State, F-8, also F-9, F-12: hidden questions and posts, closed questions, favorites
  of hidden questions, walls and messages switched off (each red). Green: read-only on
  `POST /users/me`.
- Abuse, open: no test combines features or accounts.
- Error path, partly, F-14: statuses are asserted case by case (red: 401 for a
  visitor, the error of a refused upload, a 500 in the notices route). No test asserts
  an error code, and the reading routes are never checked for 5xx.
- Multisite, F-7: nothing in the row switches sites.
- Guidelines, F-26, also F-27 to F-30: see `docs/guidelines-checklist.md`.

#### B, forms and registration

- Form, open: no test sends an array, a doubled field or an unknown form.
- Value, F-5: `Registration_Test`, `Blocked_Names_Test` (red: blocked login, the
  exemption of administrators, the network sign-up under the multisite run).
- Identity, partly: `Form_Handler_Test::test_reports_permission_errors` (red), for
  the vote form as a visitor; `Registration_Test::test_requires_the_terms_and_stores_the_consent`
  (red). Ten of the sixteen forms never go through the handler in a test (F-24).
- Frequency, partly: `Account_Limits_Test` (red: counting registrations, the login
  limit). No form is sent over its limit or twice.
- Concurrency, open.
- Time, partly: `Form_Handler_Test::test_refuses_expired_forms`,
  `Registration_Test::test_ignores_submissions_without_a_valid_nonce` (each red).
- State, partly: the delete and unsubscribe forms (red: the return address after
  deleting an answer).
- Abuse, F-17, also F-18: `test_does_not_return_to_other_sites` (red).
- Error path, F-6, also F-16: `test_keeps_values_and_field_errors_after_failures`,
  `test_account_errors_keep_the_values` (red), for the ask and account forms.
- Presentation, partly: axe on the registration, account and Q&A pages; no page in
  its error state, no right-to-left run.
- Multisite, F-32: the two sign-up tests; none switches sites.
- Guidelines, F-31, also F-32, F-33.

#### F, admin screens

- Form, open: no test sends an array or a missing parameter to a handler.
- Value, partly: the per-page range, duplicate names with the input kept (each red).
- Identity, F-22, also F-24: categories, user fields and tools refuse an editor (red
  with `MANAGE` lowered to `MODERATE`); the nonces of posts, users and profile (red);
  `edit_user` on the profile (red); the dashboard widget and the User Titles menu
  (red). Green: the same lowering on the email templates screen, the capability and
  `edit_user` of the users bulk action, the limit of assignable levels, the nonce of
  the newsletter buttons. The screens are listed by hand, not read from the menu.
- Frequency, open: the reason for — did not hold; test emails, tools and bulk
  actions can be repeated by their users.
- Concurrency, open: a settings tab saved after an IP was blocked from the posts
  screen writes the old list back (read, not run).
- Volume, F-21: no test measures the queries of a list table.
- Time, open: invalid nonces only.
- State, F-23: a post already hidden, a category with children (each red).
- Abuse, open.
- Error path, partly: the missing confirmation of a tool, errors with the input, the
  count of failed bulk actions (each red). Refusals assert that the request dies, not
  its status.
- Presentation, partly: axe on every screen as administrator; the users list and the
  profile section are not among them.
- Multisite, partly: the second run only.
- Guidelines, F-35, also F-36, F-38.

#### G, settings

- Form, partly: `Settings_Test::test_every_field_belongs_to_a_setting_and_accepts_its_default`
  reads the fields from the plugin, for the allowed case and without the points.
  Green: the type check of a section.
- Value, partly: numbers, choices, email addresses, user titles, empty templates (each
  red), by representatives of each type.
- Identity, partly: the capability of the settings pages, the fields of the site
  owner, the templates (each red); the group capability is asserted for three of
  twelve groups by name.
- Frequency, open: the reason for — did not hold; saving the points updates every
  user inside the request.
- Concurrency, open: two managers who save the same section or two templates lose one
  change (read, not run).
- Volume, open: eleven autoloaded options hold texts without a limit.
- State, open, and Abuse, open: were —. A search provider whose plugin is gone and
  the line between `easyq_manage` and `manage_options` are cases no test holds.
- Error path, F-37.
- Presentation, partly: `test_layout_shows_every_field_once` reads every tab;
  `aria-current` of the tabs (red); axe on every tab.
- Multisite, partly: the second run only.
- Guidelines, F-37, also F-38.

#### I, uploads

- Form, open: `Avatars::file_entry()` has no test.
- Value, partly: a file that is no image, the allowed types, the crop,
  `Avatars::set()` with a non-image (each red).
- Identity, partly: the setting, the login (each red). No upload as a blocked user.
- Frequency, partly: the upload limit (red). Refused uploads are not counted.
- Concurrency, open. Volume, open: no limit on the dimensions before the image is
  decoded (read, not run). Abuse, open. Multisite, open.
- State, F-20: replace, remove, erase (each red). Green: the removal of the avatar
  mark on uninstall.
- Error path, F-19.
- Presentation, partly: axe on the account page.
- Guidelines, F-34.

### Read in this round and not reproduced

These were found by reading and are not findings until someone has seen them:

- A: `POST /users/me` stores avatar and fields and then answers with the error of the
  newsletter step; converting an answer accepts the question as target while comments
  on questions are off; a PUT with `content` and without `format` stores an HTML post
  as plain text; `notify` longer than its column; the lists of the REST API query per
  item; `POST /favorites` names users who are not members of the site.
- B and I: a second submit of an in-place form; metadata of square images with
  Imagick; a PNG with huge dimensions; two uploads at once.
- F and G: the count of the other view on the users list; the row-action nonce of the
  posts screen; integers beyond the column; a minimum above its maximum; the status
  checkboxes of the profile written from a stale form; a blocked user with the level
  Administrator keeps `easyq_manage`.

### What carries the `partly` cells of the other rows

- **C**: `Routing_Test`, `Blocks_Test`, `Query_Growth_Test`; hidden posts for
  visitors (Identity, State).
- **D**: `Blocks_Test`, `Block_Settings_Test`, `Patterns_Test`; the editor and theme
  tests end to end.
- **E**: `Content_Renderer_Test`, the censoring tests in `Blocks_Test` and
  `Routing_Test`.
- **H**: `Limits_Test` (blocked and anonymized addresses), `IP_Retention_Test`, the
  unit test of the IP class, the cookie test in `Permissions_Test`.
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
  cannot be called by a user, or where calling it again changes nothing. For F and G
  the reason did not hold for Frequency, nor for Volume, State and Abuse of G; these
  cells are now `open`. **Time** of G stays —: a setting has no deadline.
- **Volume** for B: a form sends one item; what a list costs is under A and C.
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
