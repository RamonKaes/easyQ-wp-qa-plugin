# Findings

Every finding of a review is entered here, whatever its weight, and keeps its number
for good. The rules of the reviews and the matrix the findings refer to are in
`docs/review.md`.

- **Open** findings carry their class (**blocker**, **should**, **low**; the criteria
  are in the release gate), the cell of the matrix, and why no guard saw them.
- A **fixed** finding leaves this file. Its commit names the number (`F-7`), the
  guard that holds it from now on, and the counter-check: the fix taken back, the
  guard seen red, the file restored. The history is `git log --grep='F-7'`.
- **Deferred** findings stay with the reason and with the condition that brings them
  back. A deferred finding is no free pass: the next review checks whether the
  reason still holds.
- **Open suspicions** could not be reproduced yet. Each is classed by its worst
  plausible outcome, not by the failed attempt, names what reproducing it needs, and
  counts in the release gate like a finding of its class until it is reproduced (then
  it becomes a finding) or its opposite is measured (then it is refuted).
- **Rejected** findings and **refuted suspicions** stay as well, so that the next
  review does not spend its time on them again. A suspicion is refuted only by a
  measurement or a reading that shows the opposite, never because an attempt to
  reproduce it failed.

The next free number is F-206.

## Open

Found on 2026-11-17 in the first round over the whole matrix (`/big-review` without
rows, on `3c5790a`). Twelve findings of that round were fixed on the same day, each
with a guard and a counter-check in its commit: F-134 to F-143, F-173 and F-188. The
others are open. F-193 to F-204 are lines and guards the mutations of the round left
unchecked (2026-11-18, mutate.py); the guards for A-M11, A-M12, A-M14 and BI-I-Value-1
were added the same day, so those lines are not listed. Each
row names the cell, the trigger (who, on what condition), the commit since which the
place is unchanged ("old" means it survived a run over its row), and why no guard saw
it. Every one was reproduced (a test, `curl` against the development instance, `wp
eval` or the browser) unless it says "read".

| No. | Class | Finding | Cell | Trigger, since | Why no guard saw it |
| --- | --- | --- | --- | --- | --- |
| F-144 | should | No check for the same content posted twice: two equal questions at once, and a third right after, all answer 201. Question2Answer refuses it (`qa-include/pages/ask.php:148`, `app/post-create.php`), core answers `comment_duplicate` 409. | A × Abuse, A × Concurrency | Any user who may post, by a double submission or on purpose; old. | No test posts the same content twice. |
| F-145 | should | Three `approve` actions on the same queued question at once answer 200, 200, 403 and fire `q_post` twice: the followers get two emails. | A × Concurrency | Two moderators, or a double click without JavaScript; old. | No test runs an action twice at the same moment. |
| F-146 | should | Eight answers to the same question at once caused 13 deadlocks in `debug.log` (`update_hotness`, `Basic_Search::recount`); Question2Answer has the same form of query. | A × Concurrency | A busy question; old. | No test writes concurrently. |
| F-147 | should | `X-WP-Total` and `X-WP-TotalPages` of `GET /questions?search=` change with the page asked (measured 3, 7, 8), while `docs/rest-api.md` promises the headers as core sends them. | A × Volume, Guidelines | Any client that pages a search; old. Needs a decision: a true total means loading up to 19,999 results or a count in the interface of search providers; Question2Answer's search page shows no total. | `test_every_paginated_list_pages_as_core_does` never sends `search`. |
| F-149 | should | `POST`/`DELETE /messages` and `/favorites` check rights in the handler, their `permission_callback` only asks for a signed-in user, so `OPTIONS` and the target hints offer the method to a blocked user; `docs/guidelines-checklist.md` calls it "Met". | A × Guidelines, A × Identity | Read; old. | `REST_Roles_Test` compares the callback as a string (`'__return_true'`). |
| F-150 | low | The single reading routes (`GET /questions/{id}` and others) have `__return_true` and check visibility in the handler, where core does it in `get_item_permissions_check()`; the status is the same. | A × Guidelines | Read; old. | As F-149. |
| F-151 | low | A tag longer than 80 characters is accepted and then cannot be reached through its address. | A × Value | Any user who may tag; old. | No test sends a tag over the length of the column. |
| F-152 | low | Input errors of messages and the wall do not come as `rest_invalid_param` with `params`, as core's do. | A × Error path, Guidelines | Any client; old. | `test_errors_and_methods_follow_core` checks one case. |
| F-153 | low | `DELETE /notices/{id}` answers without `previous`, as core's delete routes do. | A × Guidelines | Any client; old. | The delete test covers categories only. |
| F-154 | should | Every successful form submission writes two rows (a flash transient) to `wp_options` without bound: 201 anonymous dismissals gave 402 rows, 50 toggled favorites of a new account 100 rows. | B × Frequency, B × Abuse (exhaustion) | Every visitor while the visitor notice is on (its nonce of user 0 is on every page), every account through favorites; old (since `d620d5c`, the fix of D-4 covered the failure path only). | `test_a_failure_stores_nothing_and_keeps_what_was_typed_in_every_form` counts options for failures only. |
| F-155 | should | `/wp/v2/media` lists "Avatar of {display name}" with the user ID for every uploaded avatar, also of users core and easyQ hide; the old name stays after a change of name. | I × Abuse (probing), I × Identity | Any visitor, once uploads are on (off by default) and a user uploaded an avatar; old (`3af3b0c`). | Tests ask the routes of easyQ only. |
| F-156 | should | Registration strips percent encoding from link fields (`K%C3%B6ln` becomes `Kln`): `sanitize_textarea_field()` runs before `esc_url_raw()`. A neighbor of F-5. | B × Value | Anyone who registers with a link field marked for registration; old (`166191e`). | `test_every_field_of_every_form_reaches_the_rest_api_as_typed` reads `Form_Handler::FIELDS`, not the registration. |
| F-157 | should | A request over `post_max_size` (a large avatar) ends without any message: PHP empties `$_POST`, and the form handler ignores the request. Question2Answer reports it. | I × Error path, B × Error path | Any user with a large photo on a host with a small limit; old (`d620d5c`, F-19 covered the PHP error codes only). | Tests set `$_POST` by hand; an empty `$_POST` on a POST request never occurs. |
| F-158 | low | A complete image without `Content-Type` in its multipart part is refused as "only partly arrived" and counted against the limit. | I × Form | Clients that send no part type; new (`d34ed5b`). | The guard of F-19 tests a missing `tmp_name` only. |
| F-159 | low | `slug` through `/wp/v2/users/me` sets the profile address past the blocked usernames (measured: `/questions/user/anonymous/`). | B × Abuse (other identity) | Any account; old (`cb80caf`). | `test_hooks_into_every_path_that_sets_a_shown_name` lists four hooks; no test reads the parameters of `/wp/v2/users` from the server. |
| F-160 | low | Registration with an expired nonce says "Please accept the terms" although the box is ticked; without terms, fields and the newsletter wish are lost silently. | B × Time, B × Error path | A registration form left open over the nonce's lifetime; old (`166191e`, named D-11 in the round of 2026-11-14 and never entered). | No test sends an expired registration nonce. |
| F-161 | low | A field sent as an array shows "Missing parameter(s): title" on the page. | B × Form | Built forms only; old (`9097541`). | No test sends arrays to the forms. |
| F-162 | low | Rows of `docs/guidelines-checklist.md` name fixed findings as their state (rows near 190, 193, 196 and 220 of B and I, F-22 and F-23 in F, row 268 "in steps … five sites"). | Guidelines (prose) | Read; old. | `Docs_Test` checks names and paths, not whether a named finding is open. |
| F-163 | low | Unconfirmed users may upload avatars, where Question2Answer hides the upload until the email is confirmed. Needs a decision: a deviation from Q2A, or wanted. | I × Identity | Read; old (`3af3b0c`). | No upload test with an unconfirmed user. |
| F-164 | should | The breadcrumbs do not follow `core/breadcrumbs` (`wp-includes/blocks/breadcrumbs.php:188-204`): no list, no `aria-current`, a fixed `›` that points the wrong way in right-to-left, and the question is missing although `block.json` promises it. | D × Presentation, D × Guidelines | Every page with the block; old (`d620d5c`). | axe does not report a missing list; no test compares with the core block. |
| F-165 | low | After a vote in place the hidden `vote` fields of the forms keep the value of the page load, so a later fallback to the form sends the old vote again. | D × State, D × Error path | A vote in place, then a failed request; old. | The end-to-end tests check the successful vote only. |
| F-166 | low | `vote`, `favorite` and `dismissNotice` have no busy guard like `submit`: a double click sends two requests. | D × Concurrency | A double click; old. | Not measured in the browser; read. |
| F-167 | low | The count after a vote changes without a live region or `speak()`; screen readers hear nothing (WCAG 4.1.3). | D × Presentation | Screen reader users; old. | axe sees no announcement. |
| F-168 | should | The admin lists load per row: the users list the points of each user (`Points::prime()` exists but is not used), the posts list the author and the votes of the moderator; 5 → 40 rows gave 11 → 41 and 12 → 75 queries. | F × Volume | Every moderator with up to 999 rows per page (Screen Options); old (`9cc9b43`, `0aa0539`). | `Query_Growth_Test` renders blocks and `REST_Query_Growth_Test` routes; no admin list. |
| F-169 | low | A stale Spam tab saved after a moderator blocked an IP on the posts screen takes the block back without a word (both write `block_ips_write`). Question2Answer has the same gap. | G × State, G × Concurrency | A manager with the tab open while a moderator blocks; old (`0aa0539`). | No test with two ways of writing the same setting. |
| F-170 | should | The Dashboard, Points and User Titles screens have no help tab, as core's screens do. | F × Guidelines | Everyone; old (`4f83eb0`). | The help test skips classes without `load()`. |
| F-171 | should | "Delete User Field" (removes the values of every user) and "Delete Category" delete at once, where core asks first (`wp-admin/js/tags.js`) and Question2Answer wants a check box. | F × Error path, F × Guidelines | A slip of a manager; old (`4f83eb0`, `2b57269`). | No test checks a confirmation before data is lost, except for the tools. |
| F-172 | low | A double click on "Add User Field" creates two fields with the same label (`fg-twice`, `fg-twice-2`). | F × Concurrency | A double submission; old. | `test_derives_a_free_name_from_the_label` wants the free name; nobody checks the label. |
| F-174 | low | A value of the wrong type for `bool`, `lines`, `html` or `text` settings is stored or dropped without a message (`Fields::problem()` reports `int`, `choice`, `slug` and `email` only). | G × Form, G × Error path | Built forms on `options.php` only; old. | The error path test sends the types each field expects. |
| F-175 | low | The sections of the settings are added with `autoload` on, also with large texts (newsletter, lists of IPs). | G × Volume, Guidelines | Large texts; old. | No test reads the autoload of the options. |
| F-177 | should | The newsletter lock does not work like `WP_Upgrader::create_lock()`: when `get_option()` reads `false` from `notoptions`, easyQ takes the lock where core stops, and a stale lock is not taken over atomically; with a persistent object cache the newsletter can go out twice. Measured in one process, not with a real object cache. | K × Concurrency, J × Concurrency | WP-Cron on a site with Redis or Memcached and two overlapping runs; new (`0c154e8`, F-77 and F-118). | The test runs the two batches nested in one process. |
| F-178 | low | Two tabs without the visitor cookie create two cookies, and the first anonymous post loses edit and selection rights. Question2Answer behaves the same. | H × Concurrency | A visitor with two tabs; old. | No test with two requests without a cookie. |
| F-181 | low | Each anonymous post sends a confirmation email to any address, without a pause per address. | J × Abuse (amplification) | Only when the site lets visitors post; old. | No test counts emails per address. |
| F-183 | low | The footer of private message emails names a setting to turn them off that does not exist. | J × Abuse (dark pattern) | Everyone who gets such a mail; old. | No test reads the footer against the settings. |
| F-184 | low | Prose that says more than the code: `class-unsubscribe.php` (tokens for confirmation too), `IP_Retention` ("as soon as their hour is over"; rows stay until the daily run), the privacy text "at most one hour" for the hash of an address, a dead branch in `Installer::activate()`, the test name `test_people_who_left_the_site_are_not_notified`. | Guidelines (prose) | Read; partly new (`a6681c2`). | No guard reads sentences. |
| F-185 | should | The eraser of votes works without pages (3,000 votes: 9,022 queries in one request; a request that breaks off leaves the counts wrong), and each page of the exports loads every row (49 MB per page with 20,000 messages). Core pages in the query (`wp-includes/comment.php:4192-4201`). | N × Volume, N × Guidelines | An administrator handles a request of a very active member; old (`53b60f7`) and new (`a6681c2`). | `test_every_kind_of_data_is_exported_page_by_page` counts entries, not memory or queries. |
| F-186 | should | `maybe_upgrade()` runs without a lock: two requests at once after an update gave 25 database errors "already exists" and one "Duplicate entry 'location'". | O × Concurrency | The first visits after an update that raises `DB_VERSION`; old (`cbf2b9c`). | No test starts two installs at once. |
| F-187 | should | An upgrade stores the new version although `dbDelta()` could not apply a change (a unique key over duplicate field names); Site Health then stays critical, and its action does not repair it. F-129 checks missing tables only. | O × State, O × Error path | An upgrade of data the next schema change does not take; new (`3da8653`). | `test_the_version_is_saved_only_with_every_table` simulates a missing table only. |
| F-189 | low | On a network, the action of the database test of Site Health ("deactivate and activate") repairs the main site only. | Q × Multisite | Read; new (`f08362f`, `3da8653`). | The test checks the link, not its effect on a subsite. |
| F-190 | low | The guard of F-134 measures the fallback of the store for `favorite` only; `vote` and `dismissNotice` share `requestJson()` but no test makes their request fail. | D × Error path | A failed connection while voting or dismissing; new (F-134). | The end-to-end test covers one of three actions. |
| F-191 | low | A user who never took part sends a private message; the recipient cannot answer, because the message page of the sender answers 404 (F-140 made the route answer the same). | A × State | Members who message before they post; old. | No test answers a message of a hidden user. |
| F-192 | low | The blocks `post-actions`, `comment-form`, `comment-template` and `post-content` (while editing) print directives without `supports.interactivity`, as the Interactivity API handbook asks of blocks with directives; they are rendered inside the question block, which has it. | D × Guidelines | Read; old. | `Rendering_Test::test_blocks_with_directives_declare_interactivity_and_give_unique_ids` asks for the flag only where the markup has `data-wp-interactive`, in the default state. |
| F-193 | should | Lines of the forms and uploads no test holds (mutations green on both suites): the type checks of the form name and the fields (`Form_Handler`), the fallback for an unknown form, the sanitizer of the registration fields, the TTL of the flash, the message of a REST error other than `rest_invalid_param`, both multisite branches of the registration (the stage check and `switch_to_blog()`), and in `Avatars` the part type, the error range, the default error, the size against `wp_max_upload_size()`, the partial upload and the attachment kept on deletion. | B × Form, B × Multisite, I × Form, I × Error path | Mutations of the round of 2026-11-17 (BI-B-Form-1 to 3, BI-B-Value-2, BI-B-Abuse-2, BI-B-Error-1, BI-B-Multisite-1 and 2, BI-I-Form-1 to 3, BI-I-Error-1 and 2, BI-I-State-3); old. | `test_every_field_of_every_form_reaches_the_rest_api_as_typed` sends strings only; the upload tests cover the eight error codes, not the forms PHP sends without one. |
| F-194 | low | The start of a search is not bounded by any test (`Query_Resolver::MAX_SEARCH_START` in `GET /questions?search=`). | A × Volume | Mutation A-M13; old. | `test_every_paginated_list_pages_as_core_does` never sends `search` (see F-147). |
| F-195 | should | The type checks of the query parameters (`sort`, `by`, `show`, `title`, the page of the messages) and of the block attributes (`query.tag`, `query.source`, `separator`, `rows`, `query` of the user list) are held by no test: an array there would reach `sanitize_key()` or a string function. | C × Form, D × Form | Mutations C-F1 to C-F4, C-W1, D-F1 to D-F5; old. | `Request_Bounds_Test` sends numbers and strings, `Block_Attributes_Test` numbers only (see F-204). |
| F-196 | low | The branch of the censoring that skips separators in the map of bytes is held by no test. | E × Value | Mutation E-V1; new (F-139 kept it). | The tests of the censoring use texts where the branch changes nothing. |
| F-197 | low | No test holds the length of user titles on save (`MAX_TITLE_LENGTH`) nor that the posts list offers only the row actions the moderator may use. | G × Value, F × Identity | Mutations FG-M23, FG-M24; old. | The tests save short titles and look at what the REST API refuses, not at the links. |
| F-198 | should | Lines of cookies, mail, scheduled events and feeds no test holds: the digits of the visitor cookie and the check that its row exists, the validation of a mapped IP address, the remembered notice of a signed-in user, `is_email()` of a recipient, the retention run during an install, `COALESCE(written, created)` of the retention, the visibility check of the activity feed (a second net behind the query), the maximum of feed items, and the membership of a user in the sitemap. | H × Form, H × Value, J × Value, K × State, L × Volume, L × Multisite | Mutations H1, H2, H4, H5, J1, K3, K4, L1, L3, L5; old. | Each test covers the allowed case only. |
| F-199 | should | Five lines of `wp easyq recalc` no test holds: the check that all tools were listed, the confirmation before the deleting tool, the handling of a `WP_Error`, the cache clearing between steps, and which tools `--all` takes. | M × State, M × Error path | Mutations M-1 to M-5; old. | `Tools_Test` runs the tools, not the command. |
| F-200 | should | Lines of the privacy tools no test holds: the welcome notice erased with the profile, the row of limits of the user, hidden questions left out of the export of favorites or updates, and notes left out of the export of posts. | N × State | Mutations N-2, N-3, N-5, N-6; old and new (`a6681c2`). | `Personal_Data_Test` exports and erases visible data. |
| F-202 | should | No test holds any check of the values the filters of easyQ return: `easyq_rest_prepare_*`, `easyq_email_*`, `easyq_sitemaps_entry`, `user_request_key_expiration`, `wpml_translate_single_string` and `easyq_default_settings`; a value of another type would reach the code unchecked. | P × Form | Mutations P-1 to P-6; old. | `Filter_Values_Test` hands wrong types to the callbacks of easyQ on the filters of others, not to the filters easyQ applies (the net of F-188 forbids casts, not missing checks). |
| F-203 | low | The pattern that reads the indexes `dbDelta()` added for Site Health is held by no test. | Q × State | Mutation Q-3; old. | `Site_Health_Test` simulates missing tables, not indexes. |
| F-204 | should | Five guards stay green against a violation in a form they are not built from: `REST_Roles_Test` reads a `permission_callback` as the string `__return_true` (a closure that returns true passes); `Request_Bounds_Test` does not see `filter_input()`; the guard against styles in the admin markup does not see an attribute built from a `printf()` argument; `Docs_Test` asks no document for literal actions starting with `easyq_` and does not read `apply_filters_ref_array()`. | A, C, F, P × Guidelines | Attacks A-W1, CDE-W1, FG-W3, MNOPQ-A1, MNOPQ-A2 of the round of 2026-11-17; old. | The guards compare forms of the source, not the behavior. |
| F-205 | low | The store acts only on the submit of a form (F-138), because core removes `<form>` from the HTML of contributors; a plugin that lets `input` or `select` into the post filter brings `<form>` back (`wp_kses_allowed_html()`), and with it a form with directives in HTML of others. | D × Abuse | A site with such a plugin and an author without `unfiltered_html`; new (F-138). | The end-to-end test uses core's filter. |

## Open suspicions

Each is classed by its worst plausible outcome and counts in the release gate like a
finding of its class.

| Class | Suspicion | Worst plausible outcome | Reproducing needs |
| --- | --- | --- | --- |
| should | When the site lets visitors post (`permit_post_* = ALL`), another site can make the browser of a visitor `POST /easyq/v1/questions` form-encoded, without a form key: Question2Answer asks for `code` and `qa_key` also from visitors, core refuses anonymous REST comments by default. Measured only to 401 with the default setting. | A post or a feedback mail under the visitor's IP; their limit and an IP block hit them. | An own instance with `permit_post_q = ALL` and a cross-site form. |
| should | The network sign-up with a site (`stage=validate-blog-signup`) skips the terms and loses fields, consent and newsletter wish (`class-registration.php:215-245`, new with F-32). | Accounts without the consent the site asks for. | A network with sign-up of sites and easyQ terms. |
| should | The sitemap or a feed of one site lists questions or profiles of another site of the network. Read: tables and options per site. | Content of a private site in the sitemap of another. | Feeds and sitemaps read on a network. |
| should | Right to left was never measured. | Separators and arrows point the wrong way, buttons mirrored wrongly. | An end-to-end run with an RTL locale. |
| should | `aria-busy` stays when `navigateTo()` throws after a successful post (the router module fails to load). | The answer is stored but not shown, and the button stays disabled. | A browser test that blocks the router module after the POST. |
| should | Deactivating on a network and uninstalling walk up to 10,000 sites in one request (`class-installer.php:97-108`, `Uninstaller::run()`). | The request ends halfway, part of the sites keep their data. | A network with thousands of sites. |
| should | The count of posts waiting for approval runs twice per admin page and once per frontend page for moderators, without a cache. | A costly count with category levels on every page view of a moderator. | A site with many waiting posts and `EXPLAIN`. |
| low | Two starters take over the same expired lock of the tools at once. | Two runs of the tools at the same time. | Two processes started in the same second after the lock expired. |
| low | Two erasures at once in the mode `delete` give the wrong message "has replies by others". | A wrong message to the administrator. | Two requests of the privacy tools at once. |
| low | A full quota on a network reports "Please choose one of at most 0 B". | A useless message on upload. | A network site with its quota used up. |

## Open cells

The cells of `docs/review.md` that stay `open` after the round of 2026-11-17 were looked
at; no test holds them. Each needs this decision, or a test, before the release (gate 3).

| Cell | What was looked at | Decision proposed |
| --- | --- | --- |
| E × Volume | Censoring plain text with 10,000 matches in 50,000 bytes took 0.08 s; it grows with the square of the length (500,000 bytes: 13 s), but posts are limited to 12,000 characters. | No finding; a test of the largest post with block words would hold it. |
| F × Form | Arrays in every query parameter of eight screens answer 200 without a message of easyQ; the only message (`s[]` on the posts screen) comes from core's `_admin_search_query()`, and core's own `edit.php?s[]=a` ends in a fatal error. | No finding; core does the same. |
| H × Form | Cookies as arrays, out of range, zero, negative, `%00` and `1e5` against a page and a route: 200, no message in `debug.log`. | No finding; `Cookies::get()` checks the type. |
| L × Multisite | Read: tables, options and addresses per site; no feed or sitemap of two sites compared. | Open suspicion (see above) until a multisite test compares them. |
| M × Multisite | Read: the lock and the tables per site, `--url` picks the site. | A multisite test of `wp easyq recalc --url` would hold it. |
| N × Concurrency | Read: the erasers are idempotent; two at once give a wrong message only (low suspicion above). | Open suspicion until two requests run at once. |

## Deferred

| No. | Class | Finding | Why deferred | Comes back when |
| --- | --- | --- | --- | --- |
| F-2 | should | The coverage job of the CI only reports, and mutations are done by hand. | Measured on 2026-11-15 with Xdebug: 2,748 of 15,801 statements are reported as not covered, most of them code that runs while the test suite loads the plugin, and classes only the unit tests cover. A gate on that number would need thousands of exceptions. A gate on the lines a change adds works, but counts only tests that claim the lines with `@covers`: six test files, among them the guards that read whole classes (`REST_Roles_Test`, `Docs_Test`, `Admin_Capabilities_Test`), have `@coversNothing`. Which of the two to change is a decision of the owner. | The owner decides how the guards count (give them `@covers`, or drop the annotations and count every test); then the CI checks the added lines and the review command runs the mutation script. |
| F-25 | blocker | `readme.txt` says the plugin is "not ready for live sites yet", and `Contributors` is missing. | The sentence is true until the release, and the owner gives the WordPress.org username in phase 9 (decided on 2026-11-15). | Phase 9, before the plugin is submitted. |

## Rejected

| No. | Finding | Why rejected |
| --- | --- | --- |
| F-112 | Without `ip_anonymize` each IPv6 address has its own limit, so a visitor with a /64 network has as many as they like. | Kept with the owner's word (2026-11-16): core and Question2Answer count exact addresses, and easyQ stays as close to core as it can; with `ip_anonymize` the addresses of a network share a limit already. |
| F-36, part | The whole `$_POST` is handed to four handlers of the admin screens. | Each handler reads the fields it needs one by one and checks their type, after the nonce and the capability; core's own handlers read `$_POST` directly. Handing over the unslashed copy changes no check; the other parts of F-36 are fixed with F-35. |

## Refuted suspicions

Read on 2026-11-14 in the rows A, B, F, G and I, with what was read:

- A lower role reaches the `load-` handler of a screen: core dies before the hook
  (`wp-admin/includes/menu.php`), and each handler checks `easyq_manage` itself.
- The form handler runs REST requests without the rights of the user: the internal
  request goes through the permission callbacks as the current user.
- Open redirect through the return address: `wp_validate_redirect()`
  (`Form_Handler_Test::test_does_not_return_to_other_sites`, mutation red).
- The registration saves fields without its nonce (REST, `user-new.php`, WP-CLI):
  `save()` stops without it (mutation red).
- An upload decides its type from the client: `wp_handle_upload()` checks the content
  and the image is re-encoded; removing the allowed types turns a test red.
- Tokens of the emails compared without `hash_equals()`, or one kind accepted for the
  other: both use it, with different prefixes.
- Messages, favorites and notices of other users: the user comes from the session in
  every query (mutations red).
- `options.php` saves the settings for a lower role: `option_page_capability_{group}`
  is set for every group (mutation red for the templates and the registry).
- SQL without `prepare()` or a `LIKE` without `esc_like()` in the services behind the
  routes: none found.

Reproduced on 2026-11-15, of the suspicions the round of 2026-11-14 found by reading
(the others became F-42 to F-55):

- A minimum above its maximum (`min_len_q_title` over `max_len_q_title`,
  `min_num_q_tags` over `max_num_q_tags`) blocks asking: measured with 50 over 20 and
  5 over 2, a title of 50 characters and two tags pass. The validator takes the
  larger of the two for titles and caps the minimum of tags, as
  `qa-include/plugins/qa-filter-basic.php` does.
- The row-action nonce of the posts screen is not bound to the action: F-33 made it
  `easyq-post-{action}_{id}`.
- A huge hotness weight breaks the order: measured, it gives a float near 5e19, which
  the `float` column holds; the order follows the weights the site owner chose.
- Category levels below Expert are accepted on save although the form offers Expert
  and higher: Question2Answer accepts every level up to the editor's own as well and
  even offers Approved, and a category level only ever raises the level.
- Rate limits are read before and counted after the insert, so requests at the same
  moment pass together: so does `qa_user_limits_remaining()` with
  `qa_limits_increment()`, and core has no lock for comments either. It stays under
  A × Concurrency, `open`.

Measured on 2026-11-17, of the open suspicions of 2026-11-16 (the other two became
F-134 and F-135):

- A `data-wp-` directive in the answer of a visitor drives an action of the store: an
  answer with directives in six forms (init, submit in its own region, click in upper
  case, async, text, bind) leaves no `data-wp-` attribute on the page, sends no
  request and adds no favorite (`tests/e2e/user-html.spec.js`); with the directive
  strip of `Content_Renderer` taken out, the test fails. Other ways into the region
  were open and became F-137 and F-138.
- The tag feed with tags of the same name in two scripts: NFC, NFD, a Cyrillic `а` and
  an accent each make a tag of their own, and each feed lists exactly its question
  (measured with `curl` on the development instance).
- The question and user pickers of the editor with more than 100 entries: each finds
  the first of 106 by search (`tests/e2e/pickers.spec.js`); the category select did
  not and became F-135.

Read or measured on 2026-11-15 in the rows C, D, E, H and J to Q:

- WP-CLI help lines longer than 80 characters: WP-CLI wraps the synopsis itself; a
  line of 137 characters in the source came out at 79 in `wp help easyq`.
- Interactivity directives in user text of the lists: the lists print excerpts
  without HTML; only the block metadata part became F-104.
- A tag address loses `+` (`tag/c++`): the tag resolves as `c++`.
- Deleted users leave empty pages in the profile sitemap: the count follows the
  rows that are listed; no empty page came out.
- `Templates::require_login()` differs from `auth_redirect()`: read, it builds the
  same login address with the same return address.
