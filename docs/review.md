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
grep -rnF -e 'register_activation_hook' -e 'register_deactivation_hook' -e 'wp_initialize_site' -e 'wp_uninitialize_site' -e 'wpmu_drop_tables' easyq.php uninstall.php includes   # O
grep -rnF -e 'apply_filters(' -e 'apply_filters_ref_array(' -e 'do_action(' -e 'do_action_ref_array(' includes   # P: own hooks, dynamic names and the filters of others easyQ applies
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

## State on 2026-11-18

The first run over the whole matrix (`/big-review` without rows), begun on 2026-11-17 on
`3c5790a` and halted for the night, continued on 2026-11-18, by one session (Claude Opus 5.5) with six reading agents (each Claude Opus 5.5, read from their
sessions), one per group of rows: A; B and I; C, D and E; F and G; H, J, K and L; M to Q.
The agents read the source of their rows whole, reproduced on the development instance
(wp-env 8888, over HTTP with `curl -i`, then `debug.log`) with fresh accounts per series,
and proposed cells, findings and mutations; the session ran the machines, the browser
tests, coverage and mutation, and fixed. Their reports are outside the repository.

### What the tools said

Set up from zero: `npm run build`, the test environment emptied with `wp-env clean all`.

| Tool | Result |
| --- | --- |
| `composer lint`, `composer analyse`, `composer test:unit`, `npm run lint:js` | Green (96 unit tests before the round, 98 after) |
| `npm run test:php`, `npm run test:php:multisite` | Green (773 and 788 tests on `3c5790a`); green again after the first group of fixes |
| `npm run test:e2e` | Green (144 tests) |
| `npm run plugin-check` | No errors (on 8888: the emptied test environment has no Plugin Check) |
| Inventory (`grep` per row) | Every command printed lines (A 39, B and I 21, C and F 57, D 54, E 9, F 11, G 3, H 13, J 12, K 4, L 8, M 1, N 2, O 5, P 21, Q 2); no outgoing request |

### The open suspicions first

The four suspicions of 2026-11-16 were run in the browser: the directive in the answer of a
visitor was measured not to act (`tests/e2e/user-html.spec.js`, red with the strip of
F-60 taken out), the tag feed in two scripts was measured correct, the pickers of the editor
find a question and a user behind more than a hundred; the failed request of the store and
the category select became F-134 and F-135.

### Measured, not read

Coverage (`mutate.py coverage`, Xdebug in the process, past PHPUnit, both suites green
with 781 and 796 tests): 830 executable lines under `includes/` run in no test, and two
files are loaded by none (`includes/frontend/views/classic.php`,
`includes/frontend/views/no-comments.php`). Calibrated: `Post_Service::edit_answer()` and
`Privacy::export_newsletter()`, which the first measurement through the report of PHPUnit
listed as not covered (it counts only the units a test names in `@covers`), are covered;
in the first only the branch that queues an answer with comments again is not. A decision
per line is not made yet; the list is kept with the results of the round, outside the
repository.

Mutation (`mutate.py run`): every proposal of the reading agents with an anchor found once,
145 in all, A and B/I on both suites in batches, C to Q on the single site and the green
ones again on multisite. 64 lines stayed unchecked on both suites (A 4, B/I 16, C to Q 44);
four were held on multisite only (O-3, O-4, O-5, O-7). Guards for four of them were added
(`32e5a5d`); the others are F-193 to F-203, two of them second nets behind a check that
already holds (the activity and search feeds), one equivalent (FG-M10: a third argument
`check_admin_referer()` does not take). Eight fix commits of earlier rounds taken back: all
red (F-7 on multisite only, which it is about). Attacks on the guards: five of six stayed
green (F-204); `REST_Query_Growth_Test` saw a query added to a permission check of a link.

### Fixed in this round

Eighteen findings, each in a commit with its guard and counter-check: F-134 (the store
submits the form without a usable answer), F-135 (every category in the question list),
F-136 (only the level of the whole site manages the Q&A area), F-137 and F-138 (no
directive in HTML of others acts on the store), F-139 (block words where lower case changes
the length), F-140 (messages only to shown profiles), F-141 (tags whatever their case),
F-142 (the locks of stopped runs on uninstall), F-143 (the readme on deleting data), F-148
(links only when `_fields` asks), F-173 (lists of block words of any length), F-176 (the
address the proxy appended), F-179 and F-180 (every offered placeholder replaced), F-182
(every email through the filters), F-188 (a filter that returns no string), F-201 (a dead
line).

### The matrix

A cell names an open finding where one is open; several findings of a cell are in
`docs/findings.md`. Cells that were ✓ and were refuted this round are back to `partly`
(rule 4): A Identity (F-140), B Value (F-156), B Error path (F-157), G Error path (F-174),
I Error path (F-157, F-158).

| | Form | Value | Identity | Frequency | Concurrency | Volume | Time | State | Abuse | Error path | Presentation | Multisite | Guidelines |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| A | partly | F-151 | partly | ✓ | F-144 | ✓ | partly | ✓ | F-144 | F-152 | — | partly | F-149 |
| B | F-161 | F-156 | partly | F-154 | partly | — | F-160 | partly | F-159 | F-157 | partly | partly | partly |
| C | partly | partly | partly | partly | — | partly | — | partly | partly | partly | partly | partly | partly |
| D | partly | partly | partly | — | F-166 | partly | — | F-165 | partly | F-190 | F-164 | partly | F-192 |
| E | — | partly | partly | — | — | open | — | partly | partly | — | partly | partly | partly |
| F | open | partly | partly | partly | F-172 | F-168 | partly | partly | F-171 | F-171 | partly | partly | F-170 |
| G | F-174 | partly | partly | partly | F-169 | F-175 | — | F-169 | partly | F-174 | partly | partly | partly |
| H | open | partly | partly | partly | F-178 | — | partly | partly | F-198 | partly | — | partly | partly |
| I | F-158 | partly | F-163 | partly | partly | partly | — | partly | F-155 | F-157 | partly | partly | partly |
| J | — | F-198 | partly | F-181 | F-177 | partly | partly | partly | F-181 | partly | partly | partly | partly |
| K | — | — | — | partly | F-177 | partly | partly | partly | — | partly | — | partly | partly |
| L | partly | partly | partly | partly | — | partly | partly | partly | partly | partly | partly | open | partly |
| M | partly | — | — | — | partly | partly | partly | partly | — | partly | — | open | partly |
| N | — | — | partly | — | open | F-185 | — | partly | — | partly | — | partly | F-185 |
| O | — | — | — | partly | F-186 | partly | partly | F-187 | — | F-187 | — | partly | partly |
| P | F-202 | partly | partly | partly | — | — | — | — | — | partly | — | — | F-204 |
| Q | — | — | — | — | — | partly | — | partly | — | partly | partly | F-189 | partly |

Cells that changed from `—`: H Identity (the visitor cookie decides what a visitor may
edit), M Time (an expired lock of the tools), O Frequency (an install on every request
until it is done); each has a test for a part (`Cookies_IP_Test`, `Tools_Test`,
`Lifecycle_Test`). Cells that changed from `open` to `partly` name a test that holds a part,
with both sides: C Form (`Request_Bounds_Test`), D Form (`Block_Attributes_Test`, numbers
only), F Frequency and F Time (`Tools_Test`, `Posts_Screen_Test`, `Profile_Screen_Test`), G
Frequency (`Users_Admin_Screens_Test`), G Abuse (`Capabilities_Test`, F-136). The cells
that stay `open` were looked at; the decision for each is under "Open cells" in
`docs/findings.md`.

### The rows

Every row was checked in this round: read whole by its agent, measured on the development
instance, its cells set against the tests above, and its guards attacked (see "Measured,
not read"). No row is reported without a finding; the findings per row are in
`docs/findings.md` by their cell.

### State of the release gate

1. Holds: no finding classed blocker is open but F-25, deferred to phase 9; no open
   suspicion is classed blocker. The blockers of this round (F-136, F-137, F-138,
   F-139, F-140, F-173) are fixed.
2. Holds: no cell of the columns Identity, Value or Error path is `open` in A, B, F, G
   or I; several of them name open findings.
3. Does not hold yet: the cells that stay `open` (E Volume, F Form, H Form, L
   Multisite, M Multisite, N Concurrency) have a decision proposed in
   `docs/findings.md`, "Open cells", which the owner has not taken.

Open after the round: 54 findings (24 should, 30 low) and ten suspicions (seven should,
three low).

### Does it count as a round

Not yet. Every row was read, measured and mutated, the machines ran from zero, and every
fix of this round was checked with the five questions of step 7a, after which the
end-to-end tests ran again in full (152, green) and F-205 was entered. One mandatory
part is not complete: the decision per line of the coverage (830 lines). The round counts
once it is made up. Settled: zero of two rounds,
since this round found findings of class should and blocker in old code.

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

1. A finding classed **blocker** is open, or an open suspicion whose worst plausible
   outcome is a blocker (`docs/findings.md`, "Open suspicions").
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

### What counts as a round

Only a review that looked can report that it found nothing (decided on 2026-11-16):

- **Only a run over the whole matrix**: `/big-review` without rows as argument, or a
  cross-check. A run over chosen rows reads those rows, not the others, so it counts
  neither for nor against the release. The first round of 2026-11-14 ran over A, B,
  F, G and I and reported no open finding; the other twelve rows carried eight
  blockers (F-56 to F-63). The two rounds of 2026-11-14 and 2026-11-15 were both
  run over chosen rows. The first run over the whole matrix began on 2026-11-17 (see
  "State on 2026-11-18").
- **Only a complete run.** If a mandatory part did not run (a tool of
  `/big-review` step 1, the multisite suite, the browser tests, coverage, a part
  auditor of the cross-check), because a permission, the time or the usage limit
  stopped it, the round counts once that part has been made up. The report names it
  under "What did not run", with the reason.
- **A state per row in every report**: checked (with the evidence) or not. "No
  finding" holds only for the rows the report lists as checked.
- **A suspicion that could not be reproduced is not "no finding".** It is classed by
  its worst plausible outcome, not by the failed attempt, and stays under "Open
  suspicions" in `docs/findings.md` until it is reproduced or its opposite measured.
  It counts in the release gate like a finding of its class.
- **Every report names the model of each session** that took part, read from the
  session, not assumed.

### When the reviews have settled

The owner decided on 2026-11-17: rounds and fixes go on until the reviews have
settled, and only then does work go on with phase 9 (translation and release) and
phase 10 (extensions). New phases bring new code and new rows, and translations and
screenshots made before the fixes would be made twice.

- **Settled means two rounds in a row without a finding of class should or blocker
  in old code.** A round is a run as defined above: over the whole matrix and
  complete. One round without such a finding can be luck, and a round nearly always
  finds something; two in a row cannot be luck.
- **Old code** is code that has been through a round over its row unchanged: the
  finding names the commit since which the place is unchanged. A finding in code a
  fix of the last round wrote is a gap of that fix: it is fixed like any other, but
  it neither resets the count nor keeps it going, or the rounds would never end,
  since every fix writes new code.
- **An open suspicion of class should or blocker** in old code counts like a
  finding until it is reproduced or refuted.
- **Low findings** are entered and fixed or decided like any other, and do not
  reset the count.
- **The rounds alternate**: a `/big-review` that fixes, then a `/cross-check-review`
  in a fresh context, which does not know the reasons of the review and finds other
  things. Every report ends with the count: how many rounds in a row now stand
  without a finding of class should or blocker in old code.

A review never reports "no errors". It reports the state of the release gate and
every cell that is not ✓.
