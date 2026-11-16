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

## State on 2026-11-15

The findings of the first round were fixed on this day, each with a guard that turned
red when the fix was taken back (the commits name the finding and the
counter-check). Open findings: none. Deferred: F-2 (the coverage gate needs a
decision on `@covers`) and F-25 (the readme, in phase 9). Rejected: one part of F-36.

A cell became ✓ where its guard reads the whole class from the plugin, holds the
allowed and the refused case, and was seen red:

- A × Identity: `REST_Roles_Test` sends every argument of every edit route of the
  server alone, as visitor and subscriber, and every reading route with blocks, with
  the view permission and in the edit context; the allowed side for authors,
  administrators and the right to retag.
- A × Frequency: every limit of `Limits::SETTINGS` through the REST API, the first
  request passing and the second answering 429.
- A × State: every route whose controller loads a post, every method and action,
  with a hidden parent and with a closing note.
- B × Value: every field of every form of `Form_Handler::FIELDS` reaches the REST API
  as typed; the REST API sanitizes.
- B × Error path: an expired submission of every form keeps every typed field and
  stores nothing, as visitor and as user.
- G × Error path: every field of every section that can refuse a value reports it.
- I × Error path: every upload error PHP knows reports its reason.
- A × Volume: `REST_Query_Growth_Test` reads the list routes from the server (every
  `get_items`, the wall and the similar questions; the email templates are a fixed
  set), fails for a list it does not measure, and expects no statement to run more
  often with more items, for all 16 lists (F-51).

The other cells of the five rows stay `partly`: their guards hold a part of the
class, or list what they check.

The rows C, D, E, H and J to Q were reviewed in a second round on the same day
(`/big-review C D E H J K L M N O P Q`): read against the handbooks and core, each
suspicion reproduced by a test before it became a finding (F-56 to F-133, in
`docs/findings.md`), and the guards of each row taken back by mutations. The
evidence per row is under "The cells of C, D, E, H and J to Q". No cell of these rows
is ✓: no guard reads its class from the plugin with both sides and was seen red.

| | Form | Value | Identity | Frequency | Concurrency | Volume | Time | State | Abuse | Error path | Presentation | Multisite | Guidelines |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A | partly | partly | ✓ | ✓ | open | ✓ | partly | ✓ | open | partly | — | partly | partly |
| B | partly | ✓ | partly | partly | partly | — | partly | partly | partly | ✓ | partly | partly | partly |
| C | open | partly | partly | partly | — | partly | — | partly | partly | partly | partly | partly | partly |
| D | open | partly | partly | — | — | partly | — | partly | partly | open | partly | partly | partly |
| E | — | partly | partly | — | — | open | — | partly | partly | — | partly | partly | partly |
| F | open | partly | partly | open | partly | partly | open | partly | open | partly | partly | partly | partly |
| G | partly | partly | partly | open | open | open | — | open | open | ✓ | partly | partly | partly |
| H | open | partly | — | partly | open | — | partly | partly | partly | partly | — | partly | partly |
| I | open | partly | partly | partly | partly | partly | — | partly | partly | ✓ | partly | partly | partly |
| J | — | partly | partly | partly | partly | partly | partly | partly | partly | partly | partly | partly | partly |
| K | — | — | — | partly | partly | partly | partly | partly | — | partly | — | partly | partly |
| L | partly | partly | partly | partly | — | partly | partly | partly | partly | partly | partly | open | partly |
| M | F-125 | — | — | — | F-124 | F-90 | — | partly | — | partly | — | open | partly |
| N | — | — | partly | — | open | F-87 | — | F-88, F-123 | — | partly | — | partly | F-87, F-89 |
| O | — | — | — | — | F-127 | F-91 | F-91 | F-92, F-128 | — | F-129 | — | F-92, F-126 | F-91, F-92, F-99 |
| P | F-93, F-94 | F-95, F-96 | partly | F-131 | — | — | — | — | — | F-94, F-96 | — | — | F-97, F-99, F-130 |
| Q | — | — | — | — | — | partly | — | partly | — | F-133 | partly | F-132 | F-98 |

The release gate after the second round:

1. Holds: F-25, classed blocker, is deferred to phase 9. The eight blockers of the
   second round (F-56 to F-63) were fixed on 2026-11-16, each with a guard and a
   counter-check in its commit.
2. Does not hold: no cell of Identity, Value or Error path is `open` in A, B, F, G
   or I; the second round did not touch these rows.
3. Holds: every cell of every row has now been looked at, but these cells stay
   `open` without a decision written down in `docs/findings.md`: A Concurrency and
   Abuse; F Form, Frequency, Time and Abuse; G Frequency, Concurrency, Volume, State
   and Abuse; I Form; C Form; D Form and Error path; E Volume; H Form and
   Concurrency; L Multisite; M Multisite; N Concurrency.

The sections below are the record of the round of 2026-11-14; the findings they
name are fixed, their commits say how. The cells that changed with F-42 to F-55 are
listed under "Reproduced on 2026-11-15".

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

### Reproduced on 2026-11-15

The 16 suspicions the round of 2026-11-14 found by reading and did not run were
reproduced, by a test, a measurement or, for the ImageMagick case, on the PHP of the
site. Fourteen were real and are fixed, each with a guard and a counter-check:

- A: the newsletter step after a half-saved profile (F-43), converting into a target
  that takes no comments (F-42), an edit without `format` (F-45), the address for
  notifications longer than its column (F-46), the lists of the REST API (F-51),
  favorite users whose profile is not shown, also on a single site (F-44).
- B and I: a second click on an in-place form (F-52), the metadata of avatars with
  ImageMagick, cropped or not (F-53), an image with more pixels than the memory holds,
  and uploads that were not counted when refused (F-54), two uploads at once (F-55).
- F and G: the counts of the views of the users list (F-47), the retention period and
  the bonus beyond what a timestamp and the column hold (F-50), statuses written from
  a stale profile form (F-48), a blocked user with the level Administrator (F-49).

Two were not: a minimum above its maximum still lets questions be asked (measured;
`docs/findings.md`), and the row-action nonce was bound to the action by F-33.

The cells this changed: A × Volume became ✓ (above); B × Concurrency (F-52),
F × Concurrency (F-48), I × Concurrency (F-55), I × Volume and I × Abuse (F-54) went
from `open` to `partly`. A × Concurrency stays `open`: the rate limits are read before
and counted after the insert, as in Question2Answer, and no test runs requests at
once.

### The cells of C, D, E, H and J to Q

Measured on 2026-11-15. Each mutation changed one check in the plugin, ran the
integration suite (701 tests single site; the survivors again as a multisite, 716
tests), and restored the file. **Red** names the test that saw it; **green** means no
test saw the change, so the cell it belongs to cannot be ✓. The survivors of D, K, L and
N were not run as a multisite in this round; their green holds for a single site. Run outside the
repository; the mutations are described here by what they removed.

| Tool on the state before the round | Result |
| --- | --- |
| `composer lint`, `composer analyse` | no message |
| `composer test:unit` | 89 tests, green |
| `npm run test:php` | 701 tests, green, 2 skipped |
| `npm run test:php:multisite` | 716 tests, green |
| `npm run test:e2e` | 143 tests, green |
| `npm run plugin-check` | no message |

- **C, routes and page meta.** Red: the user sections of `Routes::USER_SECTIONS`
  (`Routing_Test::test_resolves_routes`), hidden profiles
  (`Users_REST_Test::test_profiles_are_shown_as_core_shows_users`), robots for pages
  that open a form and for empty lists (`Page_Meta_Test`), the view count once an
  hour. Green, single site and multisite: a non-question post resolved as a question,
  an empty tag, the 404 of an unknown route in the router and in the templates, the
  login redirect, the page meta of a hidden question, page numbers below 1, and the
  allow-lists of the list sorts (`query-resolver`, `users-render`). The findings:
  F-56 (`TypeError` from numbers out of range), F-57 and F-58 (hidden or forbidden
  questions answered with 200), F-64 to F-66, F-100, F-101. Form stays `open`: the
  query variables were read, not sent in every type.
- **D, blocks.** Red: 22 of 49 mutations, among them the permission of the user
  blocks (`User_Blocks_Test`), hidden questions (`Blocks_Test`), the edit and close
  forms, query growth (`Query_Growth_Test`), and the block options of
  `Block_Settings_Test`. Green: the type checks of block attributes (`queryId`,
  `rows`, `tag`, `query`), the upper limits of `perPage`, heading level, avatar size,
  `midSize`, `length` and `buttonPosition`, the view check of child answers and
  comments in the lists, the profile field permission, the message button,
  `aria-pressed` (F-72), `supports.interactivity` (F-104) and the flags of
  `Layouts::json()` (F-105). The findings: F-58, F-61, F-62, F-67, F-71, F-72,
  F-104 to F-107. Form and Error path stay `open`: the attributes a theme or a
  pattern can send were not sent in every type, and the store actions without `try`
  need the browser.
- **E, rendering of user text.** Red: 10 of 14, all in `Content_Renderer_Test`,
  the censoring tests of `Blocks_Test` and `Routing_Test`, the wall of
  `Messages_Test`. Green: the view check of the block context, and the escaping of
  titles, excerpts and tag names after censoring. The findings: F-59, F-60 (data-wp
  directives in user HTML), F-68 to F-70, F-102, F-103. Volume stays `open`: long
  texts through the censor were not measured.
- **H, cookies and addresses.** Red: anonymized addresses share a limit
  (`Limits_Test`), the visitor notice (`Notices_Test`), empty login forms
  (`Account_Limits_Test`). Green, single site and multisite: a cookie whose row does
  not exist, `httponly`, an address that is not valid, the welcome notice for users,
  the in-place header of the form handler (e2e only), the headers removed for feeds.
  The findings: F-73 to F-75, F-108 to F-112. Form and Concurrency stay `open`:
  cookie values of every shape and two requests with the same new cookie were not
  sent.
- **J, emails and newsletter.** Red: 11 of 20, among them the language of the
  recipient, stopping the emails of a post, one comment notice per person, the
  welcome link, the approval notice, expired confirmation keys; on a multisite also
  tokens of another site (`Email_Tokens_Test`) and people who left the site
  (`Notifications_Test`). Green, single site and multisite: an invalid recipient or
  reply-to address, entities in the subject, the anonymous author, the owner's own
  actions, moderated users in the newsletter, quotes in the sender name, the
  confirmation check of the form, the stop of a run that is no longer ready. The
  findings: F-76 to F-82, F-113, F-115 to F-117.
- **K, scheduled events.** Red: the counters of past hours and the upper limit of
  the retention (`IP_Retention_Test`), every event cleared on deactivation, batches
  to confirmed users only. Green: scheduling during installation, scheduling twice,
  the next batch of the newsletter, the stop of a run that is no longer ready. The
  findings: F-77, F-78, F-114, F-118, F-119.
- **L, feeds and sitemap.** Red: the category feed, the feed when visitors may not
  view the Q&A area, valid RSS, the sitemap in that case. Green: hidden questions and
  answers in the feed items, the conditional headers, the upper limit of items,
  members only in the user sitemap. The findings: F-83 to F-86, F-120 to F-122.
  Multisite stays `open`: the sitemap of a site was not compared with another.
- **M, WP-CLI.** Red on both: the progress of the steps (`Tools_Test`). Green on
  both: the order of the tools. The mutations of the confirmation, the unknown tool
  and the exit code act only through `tests/e2e/cli.spec.js` and were not run. The
  findings: F-90, F-124, F-125. Multisite stays `open`: `--url` was not run.
- **N, privacy tools.** Red: 9 of 14, all in `Personal_Data_Test`,
  `Favorites_Test`, `Newsletter_Test` and `Policy_Text_Test`. Green: the visitor
  cookie in the erased posts, the count of users with points, the welcome notice
  meta, hidden questions in the exported favorites, the user limits. The findings:
  F-63 (addresses compared by case), F-87 to F-89, F-123. Concurrency stays `open`:
  two erasers at the same time were not run.
- **O, activation and removal.** Red: the drop list, the account limits table, the
  events cleared on uninstall; the tables of a new site only on a multisite
  (`Multisite_Installer_Test`). Green on both: four checks of the upgrade path and
  the uninstall. The findings: F-91, F-92, F-99, F-126 to F-129.
- **P, own hooks.** Red on both: the string translation of edited texts. Green on
  both: all seven checks of filter returns. The findings: F-93 to F-97, F-99,
  F-130, F-131.
- **Q, Site Health.** Red on both: the outdated database, the permalinks, the read
  only mode (`Site_Health_Test`). Green on both: two of the debug fields. The
  findings: F-98, F-132, F-133.

A cell that was `—` and is `open` now: H Concurrency (two requests with the same
new cookie). Cells that were `—` and are findings now: L Abuse (control characters in a feed), O Time (network activation runs
past the request), P Frequency (356 filter calls on a page).

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
- **M**: `tests/e2e/cli.spec.js` (order, unknown tool, the question before deleting),
  `Tools_Test` (the progress of the steps); Guidelines: the WP-CLI chapter of
  `docs/guidelines-checklist.md`.
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
- **Form** and **Value** for J, K, N, O and Q, and **Value** for M: the entry takes no
  free input of its own; what it handles came in through another row.
- **Identity** for H and K: cookies and scheduled events have no user; what a cookie
  may claim is under Abuse.
- **Identity** for M, O and Q: core decides who reaches them. A WP-CLI shell has the
  database and core's own commands check no capability; activation, deletion and
  `uninstall.php` are reached only through core's screens with their capabilities,
  and `uninstall.php` stops without `WP_UNINSTALL_PLUGIN`; Site Health is shown only
  with `view_site_health_checks`.
- **Frequency**, **Concurrency**, **Time**, **Abuse**: marked — only where the entry
  cannot be called by a user, or where calling it again changes nothing. For F and G
  the reason did not hold for Frequency, nor for Volume, State and Abuse of G; these
  cells are now `open`. **Time** of G stays —: a setting has no deadline.
- **Volume** for B: a form sends one item; what a list costs is under A and C.
- **P**: a filter is called by code the site owner installed, with their rights;
  what counts is whether a wrong return value breaks a page (Form, Value, Error
  path), and how often it is called (Frequency, F-131).

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
