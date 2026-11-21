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

The next free number is F-247.

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
| F-246 | low | The activity line of posts is one template over a phrase of another string, `%1$s %2$s by %3$s` with "answered", "commented" and 18 more (`includes/blocks/render/class-post-fields.php`): translators can reorder it, but not make the phrase agree with "by". Whole sentences need one string per kind of activity, with and without the author (40 strings). Left from F-230 on 2026-11-20; candidate for the ledger. | D × Guidelines | Every list of activity; old. | PHPCS and Plugin Check do not compare with core's texts. |
| F-234 | low | Cross-check 2026-11-19, low findings of the part auditors, each with its evidence in the audit notes: the posts list takes 1.7 s at 262,144 rows; `permit_view_voters_flaggers` has no consumer while the privacy text promises it; blocked IPs stay without limit. No help sidebar on any screen (core has one on all 38 with help tabs); REST error codes `easyq_not_found`/`easyq_login` instead of core's; `languages/easyq.pot` is stale (6 strings missing). | several | old; Cross-check 2026-11-19 on `76f1989`. | Not guarded; deferred like the other low findings unless the owner decides otherwise. |
| F-150 | low | The single reading routes (`GET /questions/{id}` and others) have `__return_true` and check visibility in the handler, where core does it in `get_item_permissions_check()`; the status is the same. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-152 | low | Input errors of messages and the wall do not come as `rest_invalid_param` with `params`, as core's do. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-153 | low | `DELETE /notices/{id}` answers without `previous`, as core's delete routes do. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-158 | low | A complete image without `Content-Type` in its multipart part is refused as "only partly arrived" and counted against the limit. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-159 | low | `slug` through `/wp/v2/users/me` sets the profile address past the blocked usernames (measured: `/questions/user/anonymous/`). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-160 | low | Registration with an expired nonce says "Please accept the terms" although the box is ticked; without terms, fields and the newsletter wish are lost silently. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-161 | low | A field sent as an array shows "Missing parameter(s): title" on the page. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-162 | low | Rows of `docs/guidelines-checklist.md` name fixed findings as their state (rows near 190, 193, 196 and 220 of B and I, F-22 and F-23 in F, row 268 "in steps … five sites"). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-163 | low | Unconfirmed users may upload avatars, where Question2Answer hides the upload until the email is confirmed. Needs a decision: a deviation from Q2A, or wanted. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-167 | low | The count after a vote changes without a live region or `speak()`; screen readers hear nothing (WCAG 4.1.3). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-169 | low | A stale Spam tab saved after a moderator blocked an IP on the posts screen takes the block back without a word (both write `block_ips_write`). Question2Answer has the same gap. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-172 | low | A double click on "Add User Field" creates two fields with the same label (`fg-twice`, `fg-twice-2`). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-174 | low | A value of the wrong type for `bool`, `lines`, `html` or `text` settings is stored or dropped without a message (`Fields::problem()` reports `int`, `choice`, `slug` and `email` only). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-175 | low | The sections of the settings are added with `autoload` on, also with large texts (newsletter, lists of IPs). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-181 | low | Each anonymous post sends a confirmation email to any address, without a pause per address. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-183 | low | The footer of private message emails names a setting to turn them off that does not exist. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-184 | low | Prose that says more than the code: `class-unsubscribe.php` (tokens for confirmation too), `IP_Retention` ("as soon as their hour is over"; rows stay until the daily run), the privacy text "at most one hour" for the hash of an address, a dead branch in `Installer::activate()`, the test name `test_people_who_left_the_site_are_not_notified`. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-189 | low | On a network, the action of the database test of Site Health ("deactivate and activate") repairs the main site only. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-190 | low | The guard of F-134 measures the fallback of the store for `favorite` only; `vote` and `dismissNotice` share `requestJson()` but no test makes their request fail. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-191 | low | A user who never took part sends a private message; the recipient cannot answer, because the message page of the sender answers 404 (F-140 made the route answer the same). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-192 | low | The blocks `post-actions`, `comment-form`, `comment-template` and `post-content` (while editing) print directives without `supports.interactivity`, as the Interactivity API handbook asks of blocks with directives; they are rendered inside the question block, which has it. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-194 | low | The start of a search is not bounded by any test (`Query_Resolver::MAX_SEARCH_START` in `GET /questions?search=`). | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-197 | low | No test holds the length of user titles on save (`MAX_TITLE_LENGTH`) nor that the posts list offers only the row actions the moderator may use. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-203 | low | The pattern that reads the indexes `dbDelta()` added for Site Health is held by no test. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-207 | low | Authors without an account cannot change their name when they edit, which Question2Answer offers (`qa-include/pages/question-post.php:394`): `Post_Service::edit_question()` and `update_content()` take a name, but the REST API never passes one. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-208 | low | Site Health shows the message `dbDelta()` gives for a changed default value untranslated: its patterns know added tables, columns and indexes only. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-213 | low | 170 further blocks no test runs, each decided to need a test whose loss would show but harm little (line numbers of `e4eb98a`): `includes/admin/class-categories-list-table.php` 88–89; `includes/admin/class-categories-screen.php` 211, 253, 256, 440, 494–497, 542–543; `includes/admin/class-dashboard-screen.php` 193–197; `includes/admin/class-email-templates-screen.php` 331; `includes/admin/class-posts-list-table.php` 168–169, 220–222, 320, 402, 389, 392, 396, 449; `includes/admin/class-posts-screen.php` 150, 300, 366, 402, 404, 406, 408, 412, 414, 418–419, 435–448, 480, 490; `includes/admin/class-profile-screen.php` 90, 291; `includes/admin/class-settings-screen.php` 253, 265–267, 281, 305, 255, 299–301; `includes/admin/class-tools-screen.php` 202–204, 243, 233–237; `includes/admin/class-user-fields-list-table.php` 93–94; `includes/admin/class-user-fields-screen.php` 196, 381, 217, 466; `includes/admin/class-users-screen.php` 133, 154, 199, 257, 333; `includes/settings/class-fields.php` 324, 333; `includes/settings/class-registry.php` 116; `includes/blocks/class-blocks.php` 133–139, 165–168, 195–198; `includes/blocks/class-render.php` 278, 402; `includes/blocks/render/class-forms.php` 238; `includes/blocks/render/class-messages-render.php` 91, 158, 190, 326–339; `includes/blocks/render/class-post-fields.php` 139–143, 210, 308, 399, 419, 528–530; `includes/blocks/render/class-widgets.php` 66, 132–135, 223–227, 374–375, 507–526; `includes/frontend/class-form-handler.php` 155, 380, 386, 390; `includes/frontend/class-navigation.php` 98–102; `includes/frontend/class-page-meta.php` 138, 162, 253; `includes/frontend/class-router.php` 154; `includes/frontend/class-routes.php` 175; `includes/frontend/class-sitemap-provider.php` 145, 148; `includes/frontend/class-templates.php` 214–215, 280, 303, 323, 343, 385; `includes/rest/class-email-templates-controller.php` 187, 210, 235; `includes/rest/class-favorites-controller.php` 185; `includes/rest/class-ips-controller.php` 189, 209, 231; `includes/rest/class-messages-controller.php` 190, 275; `includes/rest/class-newsletter-controller.php` 142–144, 149; `includes/rest/class-questions-controller.php` 406; `includes/rest/class-rest-errors.php` 33, 34, 36; `includes/rest/class-tools-controller.php` 121; `includes/rest/class-user-fields-controller.php` 231, 261; `includes/categories/class-categories.php` 330, 348, 429, 453, 709, 744; `includes/limits/class-ip-blocks.php` 75; `includes/limits/class-limits.php` 226; `includes/points/class-points.php` 686; `includes/posts/class-activity-item.php` 120-132, 149-163; `includes/posts/class-activity-query.php` 136; `includes/posts/class-content-renderer.php` 210; `includes/posts/class-post-repository.php` 642-643, 692-693; `includes/posts/class-post-service.php` 259, 584, 635, 724, 728, 751, 772, 791, 830-838, 998, 1063-1065, 1095, 1111, 1140-1141, 1167; `includes/posts/class-post-validator.php` 109, 220; `includes/posts/class-question-query.php` 105; `includes/search/class-basic-search.php` 314; `includes/search/class-search.php` 85, 162; `includes/users/class-avatars.php` 122, 312, 452-453; `includes/users/class-permissions.php` 126; `includes/users/class-registration.php` 130, 261; `includes/users/class-user-events.php` 97-98; `includes/users/class-user-fields.php` 164, 194, 212, 258, 286; `includes/users/class-user-levels.php` 71; `includes/users/class-user-query.php` 164; `includes/users/class-user-status.php` 167, 195, 263; `includes/util/class-ip.php` 211, 248-249; `includes/util/class-text.php` 365-366; `includes/votes/class-votes.php` 305, 352, 455; `includes/class-capabilities.php` 171, 204; `includes/database/class-db.php` 105; `includes/events/class-events.php` 133; `includes/feedback/class-feedback.php` 97, 105; `includes/feeds/class-feeds.php` 214, 216–218, 220, 268, 333, 338–339, 352–353, 405, 482–484; `includes/health/class-site-health.php` 360; `includes/mail/class-templates.php` 468; `includes/messages/class-messages.php` 290, 335–339, 546–547; `includes/notices/class-notices.php` 98–101; `includes/privacy/class-ip-retention.php` 68; `includes/privacy/class-post-data.php` 470, 475–477; `includes/updates/class-updates.php` 214–215, 234–235, 365–366, 410, 424, 797. | — | Deferred on 2026-11-19, back on the owner's word of the same evening. | — |
| F-235 | low | `docs/hooks.md` does not name the binding source `easyq/user`. | P × Guidelines (prose) | Cross-check 2026-11-19, Hygiene H-1. | `Docs_Test` checks filters and actions, not binding sources. |
| F-236 | low | `docs/hooks.md` says the services do not check permissions, which is not true for all of them. | P (prose) | Hygiene H-2. | No guard reads sentences. |
| F-237 | low | `docs/rest-api.md` is incomplete: parameters, setting sections, object types. | A × Guidelines (prose) | Hygiene H-3. | `Docs_Test` compares routes, not every parameter. |
| F-238 | low | The FAQ of `readme.txt` lists the setting tabs without Email and Newsletter. | O (prose) | Hygiene H-4. | No guard reads the readme. |
| F-239 | low | Eight `// Not covered:` comments give a wrong reason (a failing insert can be faked, e.g. through a filter on the query). | All rows (prose) | Hygiene H-5. | No guard reads comments. |
| F-240 | low | The docblock of `Router::set_current()` names a caller that does not exist. | C (prose) | Hygiene H-6. | No guard reads docblocks. |
| F-241 | low | Four docblocks put the description after `@since`, against the inline documentation standard. | Guidelines | Hygiene H-7. | PHPCS does not check the order. |
| F-242 | low | `.gitattributes` speaks of release archives built with `git archive`; the release archive is built by `plugin-zip`. | O (prose) | Hygiene H-8. | No guard reads it. |
| F-243 | low | `mutate.py` (outside the plugin): unit tests never run with a mutation, mutations in `src/*.js` never reach `build/`, anchors may sit in comments, a batch can hide two mutations that cancel out, a timeout is not caught. | Tools | Hygiene M-1 to M-5. | The tool is checked by no one. |

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
