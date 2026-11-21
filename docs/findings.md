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

The next free number is F-249.

## Open

One list, in the order to work it off: blockers first, then should, then low; within a
class the cross-check of 2026-11-19 put its findings (F-214 to F-234) in the order of
their dependencies, and the others follow by number. On the owner's word of
2026-11-19 every finding here is fixed, except those the owner moves to the ledger as
over-engineering (see "Ledger"); the findings deferred earlier that day are back here.
Each row names the cell, the trigger and age, and why no guard saw it; rows that came
back from "Deferred" keep their text, with the remaining columns left out.

| No. | Class | Finding | Cell | Trigger, since | Why no guard saw it |
| --- | --- | --- | --- | --- | --- |

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
| should | A network user deleted while easyQ is active on one site only keeps their data there; moderators on a network can block only as super admins (F-218). | Data of a deleted user kept. | A network test with easyQ on a sub-site. |
| should | When core removes the fallback for `event.preventDefault()` without `withSyncEvent()`, forms of the store go out twice (F-230). | Double posts. | A WordPress version without the fallback. |
| low | A persistent object cache keeps `last_user_id` of others' posts and the levels of a deleted user (`User_Events::remove_data()`), and nothing is flushed on uninstall. | Stale author or level shown. | A Redis or Memcached drop-in. |
| low | Two starters take over the same expired lock of the tools at once. Cross-check 2026-11-19: reproduced in one process for the case `notoptions` (persistent object cache), as F-177 for the newsletter; `Tools::claim()` takes the lock over. | Two runs of the tools at the same time. | Two processes started in the same second after the lock expired. |
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

## Ledger

Findings the owner decided not to fix because the fix would cost more than it brings,
each with the reason and the date of the owner's word. Claude proposes candidates,
nothing moves here without the owner.

| No. | Class | Finding | Why not fixed | Owner's word |
| --- | --- | --- | --- | --- |
| F-196 | low | The branch of the censoring that skips separators in the map of bytes is held by no test. | The branch cannot be reached today: a match never holds a separator added for a block tag; the line says so (`// Not reachable today`). | 2026-11-20 |
| F-178 | low | Two tabs without the visitor cookie create two cookies, and the first anonymous post loses edit and selection rights. Question2Answer behaves the same. | Rare (two tabs before the first cookie), Question2Answer behaves the same, and the harm is the lost right to edit one anonymous post. | 2026-11-20 |
| F-205 | low | The store acts only on the submit of a form (F-138), because core removes `<form>` from the HTML of contributors; a plugin that lets `input` or `select` into the post filter brings `<form>` back (`wp_kses_allowed_html()`), and with it a form with directives in HTML of others. | Arises only when another plugin brings `<form>` back into the post filter of core; the regions of easyQ are protected on the server (F-137). | 2026-11-20 |
| F-147 | should | `X-WP-Total` and `X-WP-TotalPages` of `GET /questions?search=` change with the page asked (measured 3, 7, 8), while `docs/rest-api.md` promises the headers as core sends them. | A true total needs a count in the interface of every search provider; the search page of Question2Answer shows none. `docs/rest-api.md` says now that the headers of a search are a lower bound. | 2026-11-20 |
| F-223 | should | With `ip_anonymize` every limit per IP holds for the whole /24 (or /64): 20 failed logins from one address lock every neighbour out for the hour, also with the right password; likewise registrations, flags and questions. The help of the setting (`includes/settings/class-fields.php:1075`) does not say so. Bears on F-112 (Kept): its reason names the sharing as wanted, not the lock-out of others. | Follows from F-112 (kept): who shortens addresses shares the limits of a network; counting apart would store the full address again, if only hashed. The help of the setting says so now. | 2026-11-20 |
| F-234, part | low | Blocked IP addresses stay in the settings without a time limit, while the IP addresses of posts and limits are removed after the retention period. | Core keeps its list of disallowed comment keys (`disallowed_keys`) without a limit as well, and so does Question2Answer; the site owner keeps the list and removes an entry when it is no longer needed. | 2026-11-21 |
| F-198, part | should | The visibility check of the activity feed is held by no test. | A second net behind the activity query, which already leaves hidden posts out; no way reaches it. | 2026-11-20 |

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
