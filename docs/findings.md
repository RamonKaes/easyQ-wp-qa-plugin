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

The next free number is F-298.

## Open

One list, in the order to work it off: blockers first, then should, then low; within a
class a finding comes before the one that builds on it (same file, same guard, same
rule), and each names what it waits on. F-251 to F-297 are from the cross-check of
2026-11-22 on commit f935125 (`.claude/audit/2026-11-22/` of the environment holds the
measurements); related findings of its part auditors are merged into one row. On the
owner's word of 2026-11-19 the findings of class should in the behavior and the missing
tests of rights, data and privacy are fixed before phase 9; the others may be deferred
with a reason.

| No. | Class | Finding | Cell | Trigger, since | Why no guard saw it |
| --- | --- | --- | --- | --- | --- |
| F-254 | blocker | A network sign-up that creates a site skips easyQ's terms and loses the consent record, the profile fields and the newsletter wish: `Registration::validate_signup()` (`includes/users/class-registration.php:241`) checks only at `stage=validate-user-signup`; nothing hooks `signup_hidden_fields`/`signup_blogform`, so the inputs and the nonce never reach stage 2 (`wp-signup.php:787,847`); `activate()` listens to `wpmu_activate_user` only, while a site sign-up fires `wpmu_activate_blog` (`ms-functions.php:1323`). Measured over HTTP on a network: after activation no `easyq_terms_consent`, no field, no subscription; a direct POST of stage 2 with the nonce of stage 1 and no terms is accepted. Breaks the promise of `readme.txt` ("If new users accept your terms on registration, the time and the text are stored as proof"), of `README.md` and of the privacy text. The single-site registration stores the record (measured). Lever: carry the inputs and the nonce into the site form, validate at `validate-blog-signup` too, apply on `wpmu_activate_blog` (fifth argument: the meta); neighbours `gimmeanotherblog`. Reproduces the open suspicion of the network sign-up. Waits on nothing. Cross-check 2026-11-22, commit f935125. | B Multisite | A network with sign-up of sites and terms. Old code: d19c3e92 (2026-11-15). | `Registration_Test` covers the user sign-up only. |
| F-255 | should | The read-only mode is bypassed by writing the namespace in other case: `REST_API::refuse_while_read_only()` (`includes/rest/class-rest-api.php:106`) checks `str_starts_with( $route, '/easyq/v1/' )`, while core matches routes without case (`class-wp-rest-server.php:1172`, `@i`). Measured: with `read_only` on, `POST /easyq/v1/questions` 503, `POST /EASYQ/v1/questions` 201 for a subscriber. `docs/rest-api.md` promises every change answers this first. `Blocked_Names::check_rest()` decides by the handler for this reason (7195b263). Lever: decide by the handler (`Controller` instance), as `Blocked_Names` does. By the letter of the gate (a change the role may not make while the area is read-only) close to blocker; classed should because the writer keeps every permission they have otherwise and the switch is a maintenance window. Cross-check 2026-11-22, commit f935125. | A State | A request with the namespace in capitals. Old code: 6a658262 (2026-11-15, F-12), a neighbour case that fix missed. | `REST_Roles_Test::test_the_read_only_mode_refuses_every_change_except_for_managers` walks the registered routes in lower case only. |
| F-257 | should | Writes of visitors have no token bound to the browser: with `permit_post_* = ALL` a cross-site form-encoded `POST /easyq/v1/questions` without cookie creates a live question; the form handler's visitor nonce is the same for every visitor (user 0, no `nonce_user_logged_out`), so a cross-site POST of `/questions/ask/` passes; with the defaults `POST /feedback` reaches the mail. Question2Answer binds visitor codes to the `qa_key` cookie (`qa-include/app/users.php:1332-1384`); core's REST refuses anonymous comments by default. Logged-out nonces otherwise add only an expiry failure (registration `includes/users/class-registration.php:114,180`; core's register and guest comment forms have none). Lever: bind visitor nonces to a form-key cookie (`nonce_user_logged_out`) and require them for visitor writes in REST; page caches and the cookie text follow. Reproduces the open suspicion of cross-site visitor posts. Cross-check 2026-11-22, commit f935125. | B Identity, A Identity | Non-default `permit_post_*`; feedback by default (a mail, the victim's limit). Old code. | No test sends a visitor write without or with another's token. |
| F-258 | should | Moderation ignores category levels: `Permissions::moderation_reason()` (`includes/users/class-permissions.php:180-205`) reads only the global level; Question2Answer takes the level for the categories (`qa-include/pages/ask.php:113-119`, question-submit, question-post). Measured: an Expert of category 17 with `moderate_by_points` on gets their question queued there. Lever: pass the post or the categories and use `level_for_post()`/`level_for_categories()`, at every caller (questions, answers, comments controllers on create and edit, reshow). Cross-check 2026-11-22, commit f935125. | A Abuse | Moderation on and category levels in use. Old code: 6b8bddd (2026-10-13). | `Permissions_Test::test_moderation_reasons` uses global levels only. |
| F-259 | should | A network user deleted while easyQ is active on one site only keeps their data there: the network admin loads the main site's plugins, so `User_Events::deleting_from_network()` (`includes/users/class-user-events.php:63`) does not run. Measured on a network (HTTP and WP-CLI): posts keep the `user_id`, the points row stays. Lever: remove data of users that no longer exist in the daily run, next to the removal of deleted sites. Reproduces the open suspicion (deletion part); the rights part of that suspicion is refuted, see F-261. Waits on F-253 (same daily run). Cross-check 2026-11-22, commit f935125. | O State | easyQ active per site, not on the main site. Old code: 6b8bddd. | `User_Events_Test.php:92` deletes with easyQ loaded (V10). |
| F-260 | should | Uninstalling on a network walks every site in one request: `Uninstaller::run()` (`includes/class-uninstaller.php:67-85`) has no guard for large networks; measured 304 sites in 33 s over HTTP (about 133 ms a site), extrapolated 10,000 sites about 22 minutes; deactivation has the guard (`wp_is_large_network()`). Core upgrades a network five sites a request (`network/upgrade.php`). Reproduces the open suspicion in part (deactivation refuted). Cross-check 2026-11-22, commit f935125. | O Volume | Networks with thousands of sites and a wall-clock limit. Old code. | No test of the size. |
| F-261 | should | On a network only super administrators set Q&A levels, category levels and bonus points: they are set only on `user-edit.php` (`Profile_Screen`), which core gives only to `manage_network_users` (`wp-admin/user-edit.php:99-105`, `capabilities.php:74-79`); core changes roles on a site with `promote_user` (`wp-admin/users.php`). Measured on the multisite suite: a site administrator's save leaves the level unset. Lever: a place under the site's users with `promote_user`, as core changes roles. Cross-check 2026-11-22, commit f935125. | F Multisite | Every network. Old code. | `Profile_Screen_Test.php:217-221` grants `manage_network_users` to pass. |
| F-262 | should | Question titles lose `%xx` and `<word>` and get entities: `sanitize_text_field()` (`includes/rest/class-questions-controller.php:239,339`); measured `Why does <div> collapse at 100%ab width <3 ok?` stored as `Why does collapse at 100 width &lt;3 ok?`; plain-text mails show the entities, the search index gets `lt`/`amp`/`quot`, the form says nothing. A name of a visitor grows past `author_name varchar(40)` the same way and gives 500 `easyq_not_created` (`includes/rest/class-post-input.php:290`, measured in process). Question2Answer keeps titles raw, core keeps `%20` (kses), and `Text::query_text()` fixed the same for the search. Lever: clean titles and names as `query_text()` does and escape on output; decode for plain-text mails; validate the stored length. Cross-check 2026-11-22, commit f935125. | A Value, B Value, J Value | Any title with `%`, `<` or `&`. Old code: c410abc3 (2026-10-13). | No test of the round trip of a title. |
| F-263 | should | A failed write of the store is silent, and a stale tab without JavaScript loses the text when the question was hidden: the branch for an unusable answer (`src/view/index.js:388-396`) calls `navigateTo( location.href )` without `force`, and core's router shows its cached copy (dist `interactivity-router/index.js:862-872`): 404 of a stale tab, 502 after storing, invalid JSON, 429 give no message, no announcement, and after a 502 the stored answer is not shown (measured in the browser); without JavaScript a hidden question answers the 404 page and the text is gone (measured), against the promise of `Form_Handler` and F-224. Lever: show and announce an error and keep the text; reload fresh for 5xx and broken JSON; the 404 of a hidden post shows the form's error as F-224 does for a closed one. Waits on nothing. Cross-check 2026-11-22, commit f935125. | D Error path, B Error path | A stale tab, a proxy error. New code: 893c12d0 (2026-11-20) and the neighbour case of F-224 (ce2dc64, 2026-11-20). | `in-place.spec.js` counts posts and answers only; `Form_Handler_Test` covers the closed question. |
| F-264 | should | The form stays busy and the stored answer unseen when the router module fails to load after a successful post: no catch around `import( '@wordpress/interactivity-router' )` in `navigateTo()` (`src/view/index.js:75-115`), busy set at `:353-354`, no `finally`; a second click sends nothing. `actions.navigate` (`:211-212`) does nothing offline. Measured in the browser. Lever: `try`/`finally` around every `navigateTo`, fall back to `location.assign()` as core's router does (`forcePageReload`). Reproduces the open suspicion of `aria-busy`. Waits on F-263 (same branch). Cross-check 2026-11-22, commit f935125. | D Error path | A lost connection or a blocked module after the post. Old code: 98c4b3ed (2026-10-26). | No test with the router module blocked; mutation of the busy reset GREEN. |
| F-265 | should | Tags are accepted that their own address cannot reach: the write path (`Text::normalize_tags()`/`parse_tags()`) keeps `<…>`, double spaces and control characters, the router reads `Text::query_text( wp_strip_all_tags( … ) )` (`includes/frontend/class-router.php:268`); measured `/tag/x%00y/` and `/tag/%3Cb%3E…` 404. Lever: normalize on write with the function the router reads with, drop control characters. Cross-check 2026-11-22, commit f935125. | A Value | Tags typed with markup or control characters. New code: 065f2d8a (2026-11-16). | Mutation of the router's cleaning GREEN on all suites. |
| F-266 | should | Guest email and name fields have no `autocomplete` (WCAG 1.3.5): `Forms::author_fields()` (`includes/blocks/render/class-forms.php:988,991`, guest edit `:662`); core's comment form has `autocomplete="name"`/`"email"`, easyQ's feedback form too (`:260-261`). Measured on the ask form with visitors allowed. Cross-check 2026-11-22, commit f935125. | B Presentation, B Guidelines | Every guest form. New code: fb8de90d (2026-11-15). | No test reads the attribute. |
| F-267 | should | REST deletes and moderates posts with `POST /posts/{id}/actions/{action}` (`includes/rest/class-posts-controller.php:67-83,402-437`) instead of `DELETE` on the item with `force` and `PATCH` of the status (`class-wp-rest-comments-controller.php:97,101,1630`); `DELETE /questions/1` 404 `rest_no_route` (measured). Refutes "REST: DELETE with force where nothing can be trashed — Met" of `docs/guidelines-checklist.md` for posts. Consumers: the store, `docs/rest-api.md`, tests. Kept only with the owner's word. Cross-check 2026-11-22, commit f935125. | A Guidelines | Design. Old code: c410abc (2026-10-13). | Guideline column, no test. |
| F-268 | should | Refusals answer `rest_forbidden` and `rest_not_logged_in` for every action (`REST_Errors::permission()`, `includes/rest/class-rest-errors.php:29-53`); core uses `rest_cannot_delete`/`_view`/`_read`/`_edit`/`_create` with messages per resource and `rest_comment_login_required`, `rest_not_logged_in` only on `/users/me`; `rest_invalid_page_number` is a `rest_` code core does not have (core: `rest_post_invalid_page_number`), measured. A gap of F-234, part 21 (af9ac43, 2026-11-21), which chose the generic codes. Cross-check 2026-11-22, commit f935125. | A Guidelines | Every refusal. New code: af9ac43. | Docs_Test lists the codes, not their source. |
| F-269 | should | 42 of 52 blocks declare no `supports.interactivity`, so no `clientNavigation`, and core then turns enhanced pagination off for a whole Query (`wp-includes/blocks/query.php:97-123`); `comment-form`, `post-actions` and `post-content` declare interactivity but load no `viewScriptModule` and have no `parent` (core blocks with directives enqueue their own module, `search.php:84`). Neighbour of F-192 (06e6f7d). Cross-check 2026-11-22, commit f935125. | D Guidelines | Blocks in an enhanced Query or a custom template. Old code, and 06e6f7d (2026-11-20). | The guard of F-192 checks the flag, not `clientNavigation` or the module. |
| F-270 | should | Permission screens and texts differ from core: core dies with `<h1>You need a higher level of permission.</h1><p>Sorry, you are not allowed to …</p>` and 403 (`wp-admin/options.php:51`, `users.php:15`); easyQ has bare sentences in three wordings ("Sorry, you are not allowed to do this." `class-rest-errors.php:41`, `class-messages.php:557`, `class-users-screen.php:296`; "You are not allowed to do this." `class-forms.php:1073`; "…do that." `class-site-health.php:77`), core's REST says "Sorry, you are not allowed to do that.". A gap of F-230 (5f42055). Cross-check 2026-11-22, commit f935125. | F Guidelines | Every refusal. New code in part. | No guard compares texts with core. |
| F-271 | should | On WordPress before 7.0 easyQ registers core's style handle `wp-block-breadcrumbs` with its own inline CSS (`includes/blocks/render/class-widgets.php:585-590`): a core handle and a stylesheet of its own, against "no stylesheet of its own" in `README.md`. Not measured (the test site runs 7). Cross-check 2026-11-22, commit f935125. | D Guidelines | Sites on 6.7 to 6.9. New code: 67c9189 (2026-11-20). | The line is marked not covered. |
| F-272 | should | Protection tests of the rights to moderate users are missing: the 403 `easyq_not_blockable` of `POST /users/{id}/actions/block` (`includes/rest/class-users-controller.php:214-216`) and the rule "not while blocked" of `User_Status::may_moderate()` are asserted by no test (mutations GREEN on both suites; the behaviour of the first is held by `User_Status::block()`). Promised by F-218 (15eb750). Cross-check 2026-11-22, commit f935125. | A Identity | — New code: 15eb750 (2026-11-20). | `User_Moderation_Test` lacks the refused cases. |
| F-273 | should | CSRF checks of the posts screen are held by no test: `check_admin_referer( 'easyq-ip_' … )` (`includes/admin/class-posts-screen.php:308`; block, unblock, hide all posts of an address — the only guard, the action goes through `rest_do_request`) and `check_admin_referer( 'bulk-easyq-posts' )` (`:330`); also the repair of Site Health (`includes/health/class-site-health.php:74`, idempotent). Mutations GREEN on both suites. Cross-check 2026-11-22, commit f935125. | F Identity | — Old code: 0aa0539e (2026-10-29); a098b039 (2026-11-21). | `Admin_Guards_Test::provide_forms` lists four screens by hand (V13). |
| F-274 | should | Escaping held by no test: `esc_attr( $value )` of `Forms::field()` (`includes/blocks/render/class-forms.php:853`; the value includes `?title=` from the address) and `Content_Renderer::kses_post()` of the welcome notice (`includes/notices/class-notices.php:171`; directives would reach the store's region). Mutations GREEN on both suites; the source net against `wp_kses_post()` cannot see unfiltered output. Cross-check 2026-11-22, commit f935125. | E Value, B Value | — Old code. | `User_HTML_Test` covers the visitor notice only. |
| F-275 | should | The rule that withdrawing a vote needs no right to vote down (`includes/rest/class-posts-controller.php`, `< 0`) is held by no test (mutation `<= 0` GREEN on both suites); Question2Answer `app/votes.php`. Cross-check 2026-11-22, commit f935125. | A Abuse | — Old code. | Only the forward cases are tested. |
| F-276 | should | Removing the call of `strip_profiles()` in `Avatars::upload()` (`includes/users/class-avatars.php:235`) stays GREEN on both suites: the end-to-end test `Avatars_Test::test_an_uploaded_avatar_keeps_no_metadata_of_the_photo` is skipped everywhere (the ImageMagick of wp-env reads no formats; the CI uses wp-env). Behaviour correct today (measured with GD on the test site and with ImageMagick on the host). Promise: `docs/feature-mapping.md:697`. Cross-check 2026-11-22, commit f935125. | I Value | — Old code. | The only test that goes through `upload()` with ImageMagick never runs. |
| F-278 | should | The guard of `withSyncEvent()` (`tests/e2e/in-place.spec.js:69`) clicks two of seven actions; vote and submit without the wrapper stay GREEN. With core's fallback removed (simulated in the browser) a missing wrapper sends writes twice. Promised by F-230 (5f42055). Cross-check 2026-11-22, commit f935125. | D Concurrency | — New code: 5f42055. | The test lists its actions by hand (V13). |
| F-279 | should | Size against core: the easyQ dashboard calls `count_users()` without the guard core uses (`includes/admin/class-dashboard-screen.php:137`; core skips it when `wp_is_large_user_count()`, `class-wp-users-list-table.php:179`), 172–282 ms at 5,000–50,000 users; the daily removal of deleted sites runs on every site (`Uninstaller::remove_data_of_deleted_sites()`, meta query of type ALL, 554 ms at a million user meta rows), where core runs network jobs once a network (`ms-functions.php:2484`). Cross-check 2026-11-22, commit f935125. | F Volume, K Volume | Large sites and networks. Old code; new code 6b12514. | No test of size. |
| F-280 | should | Admin screens differ from core's markup: Categories, User Fields and Email Templates print `<h1>` without `wp-heading-inline` and no `<hr class="wp-header-end">` (core `edit-tags.php:336/350`; `common.js` places notices after it); the category name uses `required` where core uses `aria-required="true"` (`includes/admin/class-categories-screen.php:309`). Cross-check 2026-11-22, commit f935125. | F Guidelines | — Old code. | No guard compares the markup. |
| F-281 | should | Hook names follow Question2Answer, not core: abbreviations (`easyq_q_post`, `easyq_a_to_c`), a `_delete_before` suffix where core prefixes `before_delete_post`, and `easyq_u_login/logout/register/reset/password/delete` fire core's `wp_login`, `wp_logout`, `user_register`, `password_reset`, `delete_user` again under new names (`includes/users/class-user-events.php:74-189`); the docblock of the dynamic hook lacks core's form ("The dynamic portion of the hook name…", `includes/events/class-events.php:136-146`). `docs/hooks.md` gives the reason (Question2Answer's names); a candidate for "Kept" with the owner's word. Cross-check 2026-11-22, commit f935125. | P Guidelines | — Old code: 40ff4ea. | Guideline column. |
| F-282 | should | A guest's erasure leaves the full IP addresses in the row of the visitor cookie: `Post_Data::anonymize()` (`includes/privacy/class-post-data.php:406`) clears the post's `cookie_id` but not `created_ip`/`written_ip` of `easyq_cookies`; they stay until the retention run (up to 30 days). Measured with answer 691. Core shortens the address of every erased comment. Mutation of `'cookie_id' => null` GREEN on both suites (`Personal_Data_Test` erases a visitor without cookie). Cross-check 2026-11-22, commit f935125. | N State | An erasure request of a visitor with cookie. Old code: 53b60f7c (2026-11-13). | The test's visitor had no cookie. |
| F-283 | should | A protection test of a consent default is missing: the notify checkbox forced to ticked (`includes/blocks/render/class-forms.php:983`, ignoring `posting.notify_users_default`) stays GREEN on both suites. Also GREEN: server-side `aria-pressed` of the vote buttons forced to false (`class-post-fields.php:704`, also `frontend.spec.js`), `role="alert"` removed from `Forms::field()` errors (`:871`), the pagination `aria-label` removed. Cross-check 2026-11-22, commit f935125. | B Presentation | — Old code. | Tests of the defaults read the newsletter box only. |
| F-284 | low | Approving a blocked user works (Question2Answer's approve button needs not blocked, `pages/user-profile.php:112-116`), and `unblock` lacks Question2Answer's limit below Moderator (`:264`); the block route answers 403 for a hidden user and 404 for a missing one, while every other route answers 404 for both (core's `/wp/v2/users/{id}` shows existence anyway); the users list "Blocked" of a site misses blocked network users who are not members (`WP_User_Query` limits to the site). Gaps of F-218. Cross-check 2026-11-22, commit f935125. | A Identity, F Multisite | — New code: 15eb750. | — |
| F-285 | low | Tests missing for rules of the moderation of users and posts (mutations GREEN on both suites): the super-administrator clause (`$level >= Levels::SUPER`, or) and the `edit_user` branch of `User_Status::may_moderate()`; the gate of own status in `class-profile-screen.php:242`; the APPROVED exemption of the approve rule; flags clearable `( $user_flag ? 2 : 1 )`; reopen on unselect only for questions closed by selection; the Expert boundary of moderation; close on select of a closed question; the newsletter and confirm token compared as a prefix (`class-newsletter.php:315`, `class-unsubscribe.php:202`); raw output of about and multi-line fields (`class-users-render.php:261,276`). Code correct today. Cross-check 2026-11-22, commit f935125. | A Abuse, A Identity | — | — |
| F-286 | low | `User_Titles::title()` replaces the points title with the level label from Expert up; Question2Answer shows the points title on the profile (`user-profile.php:777-781`) and title and level apart beside posts (`format.php:606-612`). The docblock says intended; a candidate for "Kept" with the owner's word. Cross-check 2026-11-22, commit f935125. | C Guidelines | — Old code. | — |
| F-287 | low | Two starters take over the same expired lock of the tools: `Tools::claim()` (`includes/tools/class-tools.php:400-407`) with `update_option`; 8 parallel starts with a stale lock gave three runs; with a persistent cache `add_option` overwrites a live lock. `Newsletter::lock()` does it with compare-and-set (V8). The expiry (5 minutes) is held by no test. Reproduces the open suspicion of the tools lock. Cross-check 2026-11-22, commit f935125. | M Concurrency | Two starts in the same second. New code: a5108d28 (2026-11-16). | `Tools_Test` uses a lock one hour old. |
| F-288 | low | A double submit creates two top-level categories with one slug: `UNIQUE KEY parent_slug(parent_id,slug)` does not hold for `parent_id IS NULL`, check then insert (b2c0e06d); measured 201, 201; core checks after the insert (`taxonomy.php:2659-2664`). Cross-check 2026-11-22, commit f935125. | G Concurrency | A double click. Old code. | — |
| F-289 | low | Small things of input: integers of the settings use `is_numeric` and `(int)` (`includes/settings/class-fields.php:330-335`), not `Util\Number` (`12.7` becomes 12, `2e19` the largest integer); bidi controls (U+202E) in tags render without isolation; `avatar[]` is ignored silently; tag trim and invalid UTF-8 cleaning of `Text` held by no test. Cross-check 2026-11-22, commit f935125. | G Value, A Value | — | — |
| F-290 | low | Deactivating on a network cancels newsletter runs of sites where easyQ stays active through their own `active_plugins` (`includes/database/class-installer.php:118-129`); `get_sites()` without `network_id` walks other networks. Tests of the large-network branch of `Installer::deactivate` and of `deleting_from_network` skipping sites without easyQ are missing (mutations GREEN). Cross-check 2026-11-22, commit f935125. | O State | — New code: ba490e3e (2026-11-06). | — |
| F-291 | low | A full upload quota says "Please choose one of at most 0 B" (`includes/users/class-avatars.php:182-185`); core says "You have used your space quota" (`wp-admin/includes/ms.php:36,45`). Reproduces the open suspicion of the quota. Cross-check 2026-11-22, commit f935125. | I Multisite | Quotas turned on. New code: d34ed5bd (2026-11-15). | — |
| F-292 | low | The count of waiting posts runs twice per admin page and three times on the dashboard without a cache (`includes/admin/class-posts-screen.php:117,148`; index-only, 10–16 ms at 20,000 waiting); core shares one cached `wp_count_comments()`, Question2Answer caches `cache_queuedcount`. Reproduces the admin part of the open suspicion; the frontend part is refuted. Cross-check 2026-11-22, commit f935125. | F Volume | — Old code. | — |
| F-293 | low | An upgrade from schema 1 with duplicate names of user fields never finishes (`ADD UNIQUE KEY name` fails, retried hourly; Site Health does not say why). Only development installs. Cross-check 2026-11-22, commit f935125. | O Error path | — | — |
| F-294 | low | The policy text says IP addresses are deleted after the retention period (`includes/privacy/class-policy-text.php:132-133`) without naming the blocked addresses, which stay (F-234, part, in the ledger: keeping them is decided; the sentence is not). A newsletter wish at registration is dropped silently when the confirmation mail fails (`includes/newsletter/class-newsletter.php:216-219`). Cross-check 2026-11-22, commit f935125. | N Presentation, J Error path | — | — |
| F-295 | low | Admin screens do not reflow at 320 px: user titles (`includes/admin/class-points-screen.php:270`, width 530) and the edit screen of an email template (334, the test button); 43 void elements without ` />` in eight admin files, against the convention of `README.md`. Focus falls to the body after a notice is dismissed in place (core's admin does the same). The double click of a vote is held by no test. Cross-check 2026-11-22, commit f935125. | F Presentation, D Concurrency | — | — |
| F-296 | low | Documents and tools against the state: the release archive ships `README.md`, `package.json` and `LICENSE` (npm-packlist adds them) while `.gitattributes` leaves README.md and package.json out, and `Docs_Test::test_git_archive_and_the_release_archive_leave_out_the_same_paths` models the archive by `files` only (its comment in `.gitattributes` is false; the shipped README links `docs/`); `npm run plugin-check` fails on a fresh setup (Plugin Check is not in `.wp-env.json`, while README and `CLAUDE.md` name the command); the override section of `README.md` misses `WP_BASE_URL` for E2E on other ports; `readme.txt:76` and `README.md:68-69` miss two tools (refill events, delete hidden posts); the plugin `CLAUDE.md` names one `@phpstan-ignore` of two (`class-page-meta.php:148`); 16 garbled `@return mixed …>` docblocks from 40ff4ea; the `// Not reachable:` comment of `includes/frontend/class-templates.php:206` is refuted (the classic view renders when a theme's templates are filtered away; no test loads it); about 70 `NotPrepared` exceptions exist only because `prepare()` and `query()` are split, where core writes them in one call. Cross-check 2026-11-22, commit f935125. | P Value, delivery | — | `Docs_Test` models the archive wrongly. |
| F-297 | low | Guards with blind spots (mutations GREEN on both suites): `Filter_Values_Test` only catches a `TypeError` (a foreign value replaced by `array()` passes); a cast after an assignment before `apply_filters` passes the source net; the documented `rss_tag_pre` is not checked (`class-feeds.php:393`); the help guard reads only `$submenu['easyq']`; reverts of 5f42055 (`restore_previous_locale()` unconditional) and a39aebd (cache key of voters without the site) stay GREEN; `Docs_Test` compares REST paths, not methods. Lines added since 2026-11-19 without a test and without a comment: 81 (67 in `includes/posts/class-activity-item.php`, the sentences of most activity types; the approve button of a profile). Cross-check 2026-11-22, commit f935125. | P, all rows | — | — |

## Open suspicions

Each is classed by its worst plausible outcome and counts in the release gate like a
finding of its class.

| Class | Suspicion | Worst plausible outcome | Reproducing needs |
| --- | --- | --- | --- |
| should | Uninstalling clears no group of a persistent object cache (only `alloptions`); after a reinstall the IDs start again and `Post_Repository::find()` could serve deleted posts from the cache. Cross-check 2026-11-22 (the rest of the earlier low suspicion of stale `last_user_id` is refuted: `get_userdata()` gives false and the line says "edited", user IDs are never reused). | Deleted posts shown under new IDs. | A Redis or Memcached drop-in on an instance of its own, uninstall, reinstall. |
| should | Six PHP warnings `Attempt to read property "ID"/"post_type" on null` in `get_body_class()` (`wp-includes/post-template.php:679-680`) on the development site at 2026-11-22 19:16 UTC, while a part auditor posted guest answers and switched themes, and while a mutation batch of `mutate.py` was applied to the plugin folder that site serves. Not reproduced: every route of `Navigation::routes()` and the question, category, user, tag, message and feed pages, as visitor and logged in, under Twenty Twenty-One and Twenty Twenty-Five, and a refused and an accepted guest answer without JavaScript, write no warning. | A PHP message on a page of easyQ (blocker by the gate) if easyQ causes it; most likely a mutated file. | The page and theme of those three requests; measure again under the classic themes of the test site. |
| low | `Post_Service::select_answer()` reads the previous selection and writes without a lock: two selections at once could leave the points of `answers_selected` with the answerer of the overwritten answer until `wp easyq recalc`. Not reproduced in 120 rounds of 2 and 6 parallel requests. Cross-check 2026-11-22. | Wrong points until recalc. | The read of one request inside the write window of the other. |

The other suspicions of 2026-11-21 were measured by the cross-check of 2026-11-22: the
cross-site visitor post became F-257, the network sign-up F-254, `aria-busy` F-264, the
large network F-260 (deactivation refuted), the count of waiting posts F-292 (frontend
refuted), the deleted network user F-259 (rights part refuted, see F-261), the lock of
the tools F-287, two erasures at once F-252, the full quota F-291; the sitemap of
another site, right to left and `withSyncEvent()` are refuted (see "Refuted
suspicions").

## Open cells

The cells of `docs/review.md` that stay `open` after the round of 2026-11-17 were looked
at; no test holds them. Each needs this decision, or a test, before the release (gate 3).

| Cell | What was looked at | Decision proposed |
| --- | --- | --- |
| E × Volume | Censoring plain text with 10,000 matches in 50,000 bytes took 0.08 s; it grows with the square of the length (500,000 bytes: 13 s), but posts are limited to 12,000 characters. | No finding; a test of the largest post with block words would hold it. |
| F × Form | Arrays in every query parameter of eight screens answer 200 without a message of easyQ; the only message (`s[]` on the posts screen) comes from core's `_admin_search_query()`, and core's own `edit.php?s[]=a` ends in a fatal error. | No finding; core does the same. |
| H × Form | Cookies as arrays, out of range, zero, negative, `%00` and `1e5` against a page and a route: 200, no message in `debug.log`. | No finding; `Cookies::get()` checks the type. |
| L × Multisite | Measured on a network on 2026-11-22: sitemaps and feeds of each site list only their own content; a site with `blog_public=0` answers 404 for its sitemap, as core. | No finding; a multisite test of two feeds would hold it. |
| M × Multisite | Measured on a network on 2026-11-22: `wp easyq recalc --all --url=two` repaired site two and left the main site as it was. | No finding; a multisite test of `wp easyq recalc --url` would hold it. |

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
| F-2 | should | The coverage job of the CI only reports, and mutations are done by hand. | Measured on 2026-11-15 with Xdebug: 2,748 of 15,801 statements are reported as not covered, most of them code that runs while the test suite loads the plugin, and classes only the unit tests cover. A gate on that number would need thousands of exceptions. A gate on the lines a change adds works, but counts only tests that claim the lines with `@covers`: six test files, among them the guards that read whole classes (`REST_Roles_Test`, `Docs_Test`, `Admin_Capabilities_Test`), have `@coversNothing`. Which of the two to change is a decision of the owner. | The owner decides how the guards count (give them `@covers`, or drop the annotations and count every test); then the CI checks the added lines and the review command runs the mutation script. Cross-check 2026-11-22: since 2026-11-19, 81 executable lines came without a test and without a comment (F-297), and the cross-check found eleven guards that stay green when their line is removed; the reason still holds, the weight grew. |
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

Measured on 2026-11-22 by the cross-check, of the open suspicions of 2026-11-21:

- The sitemap or a feed of one site lists content of another site of the network:
  measured on a network, each site's sitemaps (`wp-sitemap-easyq-*`) and feeds list
  only its own questions, profiles and tags.
- Right to left was never measured: with `dir="rtl"` in Twenty Twenty-Five at 1280 and
  320 pixels on 13 pages, and in seven other themes, nothing overflows, the layout is
  mirrored, the arrows of the pagination flip with core's style, the breadcrumbs follow
  core's rules.
- Forms of the store go out twice once core removes its fallback for
  `preventDefault()`: core 7.1.5 still has it; with the module patched in the browser
  to defer every action without `withSyncEvent()`, every action wrote once, and the
  control run with every action deferred wrote twice. The guard gap is F-278.
- Moderators on a network block only as super administrators: a site administrator
  blocks (200) and is refused for an editor (403) on the multisite suite; what holds is
  F-261.
