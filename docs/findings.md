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
- **Rejected** findings and **refuted suspicions** stay as well, so that the next
  review does not spend its time on them again.

The next free number is F-134.

## Open

Found by the review of the rows C, D, E, H and J to Q on 2026-11-15. Each was
reproduced by a test in the test environment (PHP 8.4, single site and multisite) or,
where it needs PHP 8.5, with `wp eval-file` on the PHP of the local site; the tests are
not part of the suite. A finding that says "decision" deviates from core, and the owner
decides whether it is fixed or kept with a reason.

### Blocker

- **F-57** (C × Identity, C × State, C × Abuse). A hidden or queued question answers
  visitors with 200 and names its title in `<title>`, in the `<h1>` of a classic theme
  and in the slug of the canonical address; the blocks themselves print nothing.
  Question2Answer and core answer 404. *Why no guard saw it:*
  `test_hidden_questions_render_nothing_for_visitors` renders the block only, never the
  page around it.
- **F-58** (D × Identity, C × Identity). `permit_view_q_page` holds the REST API (401)
  but not the question page (200 with the text) nor the `easyq/question` block, for
  visitors. *Why no guard saw it:* every test of this permission goes through the REST
  API, the feed, the sitemap or the settings.
- **F-59** (E × State). "Closed as a duplicate of …" names a hidden original to
  visitors, with its title and a link. *Why no guard saw it:*
  `test_duplicate_notes_censor_the_original_title` uses a visible original.
- **F-60** (E × Value, E × Abuse, E × Guidelines). A subscriber who writes in the HTML
  format keeps `data-wp-*` directives, `style` and `id` through `wp_kses_post()`, and they
  end up inside `data-wp-interactive="easyq"` of the question block, for visitors and
  users. The server evaluates them: `data-wp-text="state.restUrl"` prints the REST
  address, `data-wp-bind--hidden` removes `hidden`. Core gives commenters without
  `unfiltered_html` the much shorter `$allowedtags`. Whether a browser casts a vote for
  the reader with a scroll was not run (it needs an end-to-end test). *Why no guard saw
  it:* `Content_Renderer_Test` checks `onclick` and `<script>` only; PHPCS takes
  `wp_kses_post()` as enough.
- **F-61** (D × Identity, D × Abuse, D × Multisite). The `easyq/user` block with a
  `userId` shows the name and, through the binding `easyq/user`, the biography of a
  user whose profile route and REST response are 404: users who never took part, on a
  multisite also members of other sites only and super admins who are no member. An
  author can publish such a block. *Why no guard saw it:*
  `User_Blocks_Test::test_user_bindings` uses a user without `take_part()` and holds the
  leak as expected behavior.
- **F-62** (D × Value, D × Form). Block attributes beyond the integer range
  (`{"userId":1e20}`, `perPage`, `category`, `author`, `questionId`, `length`) give the
  warning "not representable as an int" on PHP 8.5 in `class-users-render.php`,
  `class-lists-render.php`, `class-query-resolver.php` and `class-post-fields.php`;
  PHP 8.4 says nothing. *Why no guard saw it:* the test environment runs PHP 8.4, and
  no test renders such attributes.
- **F-63** (N × Identity, N × State, N × Error path). The eraser compares the email
  address with case: for posts saved with `Pat@Example.org` and a request for
  `pat@example.org` it reports `items_removed: true` and `done: true` and changes nothing
  (name, address for notifications and the full IP stay). With more than 500 such posts
  every page reports `done: false` and the next page starts again, so erasing in the
  admin never ends. Core compares comment authors without case. *Why no guard saw it:*
  every test writes the post and the request with the same spelling.

### Should

- **F-64** (C × Guidelines). Page 2 of the answers has the canonical address of page 1,
  and the lists have none. Core adds `cpage` in `wp_get_canonical_url()`. *Why no guard
  saw it:* `Page_Meta_Test` checks that a canonical address is there.
- **F-65** (C × State, C × Guidelines). Pages behind the last one answer 200:
  `?easyq_page=99` with `noindex`, `?easyq_answers=99` without (an indexable copy of the
  question without answers), an unknown tag with 200. Core answers 404;
  Question2Answer also answers 200. *Why no guard saw it:* no test loads a page out of
  range.
- **F-66** (C × Value). `URLs::slug()` cuts a slug of non-Latin titles inside a
  percent sequence (`…%e5%a`), and the address does not decode as UTF-8. *Why no guard
  saw it:* the slug test uses ASCII only.
- **F-67** (D × Identity, P × Identity). With another search provider, the
  `related-questions` block shows hidden questions the provider returns; the search
  page filters them. *Why no guard saw it:* no test uses a second provider.
- **F-68** (E × Abuse, E × Guidelines). Links in the HTML format get no
  `rel="nofollow ugc"` and do not follow `links_in_new_window`; the text format does
  both. Core runs `wp_rel_ugc()`. *Why no guard saw it:* the test covers the text
  format only.
- **F-69** (E × Value, E × Abuse). Censored words stay in the slug of the address
  (`…/darn-widgets-do-not-work/` under a title `**** widgets …`). *Why no guard saw it:*
  the test checks the title text only.
- **F-70** (E × Guidelines, E × Value). The JSON-LD is printed without `JSON_HEX_TAG`:
  a title `</script><script>…` that comes in without the REST API (a service call, an
  import) closes the script element. The REST API refuses such a title, which is
  today's only protection. Core prints inline JSON with `wp_print_inline_script_tag()`
  and hex flags. *Why no guard saw it:* PHPCS accepts `wp_json_encode()` as escaping;
  no test writes `</script>` into a title.
- **F-71** (D × Value). With `showFewerFrom` below `showFewerCount` the comments block
  says "Show -2 previous comments" (also reachable through the settings). *Why no guard
  saw it:* the test uses 2 and 1.
- **F-72** (D × Presentation, D × Guidelines). The server drops `aria-pressed` of the
  vote buttons, because `state.isPressed` exists only in JavaScript; until the page is
  hydrated, and without JavaScript, a screen reader does not hear the state. *Why no
  guard saw it:* the end-to-end tests check after hydration.
- **F-73** (H × Abuse, H × Error path). Without a valid IP address (none, or a filter
  that returns a list such as `X-Forwarded-For`) nothing is counted: no limit per IP,
  no limit of failed logins, no blocked range. *Why no guard saw it:* every test sets
  a valid address.
- **F-74** (H × Multisite, H × State). The visitor cookie and the notice cookie use
  the path of the network for tables of one site: a visit to site 2 replaces the
  cookie, and site 1 no longer knows the visitor (anonymous posts cannot be edited).
  *Why no guard saw it:* no test sets a cookie across `switch_to_blog()`.
- **F-75** (H × Guidelines; decision). Both cookies are set without asking, although
  core asks before it sets the comment cookies (`show_comments_cookies_opt_in`).
  *Why no guard saw it:* no chapter on cookies in the checklist.
- **F-76** (J × Guidelines). The newsletter and its confirmation are sent in the
  language of the site, not of the recipient; the checklist says "Met" for the
  language per recipient, which holds for `Mailer` only. *Why no guard saw it:* the
  language test checks `Mailer`.
- **F-77** (J × Concurrency, K × Concurrency). The newsletter: cancelling during a
  batch is undone when the batch writes its state, and starting during a batch
  schedules a second one from the same position, so every recipient of the batch gets
  it twice. *Why no guard saw it:* `Newsletter_Test` runs the batches one after the
  other.
- **F-78** (K × State, K × Error path). A batch that dies (time limit, fatal error)
  leaves the newsletter `running` without an event, for good; starting again sends it
  twice to the recipients before the break. *Why no guard saw it:* as F-77.
- **F-79** (J × Multisite). Users removed from a site still get its newsletter. *Why
  no guard saw it:* the newsletter tests run on one site.
- **F-80** (J × State). Approving a comment below a hidden answer emails the text of
  the hidden answer to its author and to earlier commenters. *Why no guard saw it:*
  the notification tests use visible parents.
- **F-81** (J × Abuse; decision). An anonymous author can give any address for
  notifications, which then gets the answers of others without confirming; core does
  not mail comment authors about replies, Question2Answer does as easyQ. *Why no guard
  saw it:* no test looks at the address of an anonymous author as a third party.
- **F-82** (J × Error path, J × State). After a failed confirmation email the
  subscription stays `pending`, and every further request answers 200 without sending
  anything, also after the pause of ten minutes; only turning the newsletter off and
  on sends again. *Why no guard saw it:*
  `test_a_refused_subscription_saves_nothing_of_the_profile` checks the first 500 only.
- **F-83** (L × Value, L × Abuse). A control character in a title (`\x01`) is saved
  and makes the feed invalid XML for every reader. *Why no guard saw it:*
  `test_builds_valid_rss` uses `&` and `<script>`.
- **F-84** (L × Frequency, L × Guidelines). The feeds send no `Last-Modified` or `ETag`
  and ignore conditional requests, so every poll builds the whole feed; core answers
  304 (`class-wp.php`). *Why no guard saw it:*
  `test_does_not_send_cache_headers_of_wordpress_posts` holds the missing headers.
- **F-85** (L × Guidelines). The sitemap provider applies none of the filters of the
  core providers and is not registered with `wp_register_sitemap_provider()`. *Why no
  guard saw it:* no chapter on sitemaps in the checklist.
- **F-86** (L × Guidelines; decision). The RSS feed fires none of the hooks of
  `feed-rss2.php` (`rss2_ns`, `rss2_head`, `rss2_item`) and has no `lastBuildDate` or
  `sy:updatePeriod`. *Why no guard saw it:* no chapter on feeds in the checklist.
- **F-87** (N × Volume, N × Guidelines). Messages, notices, updates and favorites are
  exported and erased without pages: 600 messages give one page of 600 and `done`.
  The checklist says 500 per page. *Why no guard saw it:* the paging test covers posts
  only.
- **F-88** (N × State, N × Guidelines). The export leaves out stored data: the extra
  profile fields, the category levels, favorites of hidden questions (which erasing
  then reports as removed). *Why no guard saw it:* no test compares what is stored with
  what is exported.
- **F-89** (N × Guidelines). The suggested policy text promises more than the code
  does: rate-limit rows "after one hour" stay until the daily run, and views are
  counted "without storing your IP address" in a transient keyed by a hash of question
  and address. Wording is for the owner. *Why no guard saw it:* `Policy_Text_Test`
  reads names and periods, not promises.
- **F-90** (M × Volume, F × Volume). Every batch of `Tools::run()` counts the whole
  table twice; with 3,000 posts both counts read 3,000 rows, also the count of the
  rest, so a run grows with the square of the table. Question2Answer counts once per
  step. *Why no guard saw it:* `Tools_Test` counts no queries.
- **F-91** (O × Volume, O × Time, O × Guidelines). Network activation installs every
  site in one request (73 queries per site, up to 10,000 sites) and includes archived
  sites; core upgrades five sites per request and leaves out spam, deleted and archived
  sites. A request that hits the time limit leaves the plugin inactive with part of the
  sites installed. *Why no guard saw it:* no test calls `Installer::activate( true )`;
  `Multisite_Installer_Test` sets `active_sitewide_plugins` by hand.
- **F-92** (O × Multisite, O × State, O × Guidelines). The user meta of a deleted site
  (`wp_N_easyq_level` and the others) stays, after deleting the site and after the
  uninstall; the checklist says uninstall removes the data "on every site". *Why no
  guard saw it:* `test_deleting_a_site_drops_its_tables` checks tables only.
- **F-93** (P × Form). When `easyq_email_headers` returns the headers as an array of
  lines, the newsletter goes out without `List-Unsubscribe` and
  `List-Unsubscribe-Post`. *Why no guard saw it:* no test returns another shape.
- **F-94** (P × Form, P × Error path; decision on the class). A filter that returns
  the wrong type ends in a `TypeError` in `Users_Screen::column_value()`,
  `Templates::classic_content()` and, on a multisite, `Installer::filter_drop_tables()`.
  By the letter of the gate a fatal error is a blocker; it needs a broken plugin of a
  third party, which is why the owner decides the class. *Why no guard saw it:* no
  test sends a wrong type through a filter.
- **F-95** (P × Value). When `easyq_default_settings` loses a key, the stored value of
  that key is no longer read (`base_slug` becomes null although `fragen` is stored),
  and the uninstall then keeps the options of the missing sections. *Why no guard saw
  it:* no test removes a default.
- **F-96** (P × Value, P × Error path). A search provider that returns numbers instead
  of rows gives a warning on `GET /questions/similar`, and a provider with the ID
  `basic` replaces the built-in search, which `docs/hooks.md` does not say. *Why no
  guard saw it:* the provider tests return valid rows under a new ID.
- **F-97** (P × Guidelines). `Docs_Test` finds 39 of the 84 documented events: it
  reads literal first arguments only, so the events built from a prefix (votes, flags,
  favorites, moderation, `u_edit`) are documented by hand and unguarded. *Why no guard
  saw it:* the guard of the documents is this test.
- **F-98** (Q × Guidelines). The field `address` of the Site Health information is not
  marked `private` and is copied with "Copy site info to clipboard"; core marks
  `home_url` and `site_url` so. *Why no guard saw it:* the test reads the labels, not
  the flags.
- **F-99** (P × Guidelines, O × Guidelines). Two commands of the inventory in
  `docs/review.md` miss entries: P finds 8 of the 15 calls (not the dynamic names in
  double quotes, nor the filters of others easyQ applies), O misses
  `wpmu_drop_tables`. *Why no guard saw it:* a command that prints a line counts as
  working.

### Low

- **F-100** (C × Value). `sanitize_text_field()` on values from the address removes
  `%xx`: a search for `50%ab 0x%41` becomes `50 0x`, and the tag `50%41` leads to the tag
  `50`. *Why no guard saw it:* no test searches for `%`.
- **F-101** (C × Frequency, C × Volume, C × Abuse). Each view by a new address adds two
  rows to `wp_options` (the transient and its timeout), also for crawlers and
  prefetches; Question2Answer counts only `qa_is_human_probably()`. *Why no guard saw
  it:* no test counts rows of the options table.
- **F-102** (E × Value). Censoring breaks entities (`&amp;` becomes `&***;` for the word
  `amp`) and misses a word split by tags (`da<b></b>rn`). *Why no guard saw it:* no test
  with entities or tags in a word.
- **F-103** (E × Presentation). The JSON-LD holds HTML entities (`&#039;`, `&amp;`)
  instead of plain text. *Why no guard saw it:* `Page_Meta_Test` checks that JSON-LD is
  there.
- **F-104** (D × Guidelines). `ask-form` prints its own interactive region but does
  not declare `supports.interactivity`, so the server does not process its directives.
  *Why no guard saw it:* the registry test reads keywords and previews only.
- **F-105** (D × Guidelines). `Layouts::json()` escapes block attributes in its own
  way instead of `serialize_block_attributes()`; it parses back the same today. *Why
  no guard saw it:* the round trip test passes either way.
- **F-106** (D × Volume, D × Presentation). The pickers of the editor load the first
  100 questions and users, and `/questions` has no `search` argument to find others.
  The editor part was read, not run. *Why no guard saw it:* the editor tests use few
  items.
- **F-107** (D × Presentation). Two blocks of the same question or two ask forms on
  one page give duplicate IDs (`easyq-post-N`, `easyq-ask-title` and the others), and
  `label for` points to the first. *Why no guard saw it:* axe runs on pages with one
  of each.
- **F-108** (H × Time). The visitor cookie is set once and never renewed; core sets
  the comment cookies again with every comment. *Why no guard saw it:* cookies were
  not observable under the command line until this review.
- **F-109** (H × Guidelines). `secure` of both cookies follows `is_ssl()` of the
  request, not the scheme of `home_url()` as in core. *Why no guard saw it:* as F-108.
- **F-110** (H × Guidelines). The login link of the permission message is built from
  `HTTP_HOST`; core uses the permalink. `wp_safe_redirect()` stops the redirect, so only
  the link is wrong. *Why no guard saw it:* no test forges the host.
- **F-111** (H × Value). IPv4 rules and limits miss IPv4-mapped IPv6 addresses
  (`::ffff:203.0.113.7`); Question2Answer does the same. *Why no guard saw it:* the data
  of `IP_Test` has no mapped address.
- **F-112** (H × Abuse; decision). Without `ip_anonymize` each IPv6 address has its own
  limit, so a visitor with a /64 network has as many as they like; core and
  Question2Answer count exact addresses too. *Why no guard saw it:* no test with two
  addresses of one network.
- **F-113** (J × Presentation). Display names are HTML-encoded in plain-text emails
  (`Tom &amp; Jerry,`). *Why no guard saw it:* the test names have no `&`.
- **F-114** (K × Volume). Starting the newsletter loads every subscriber as a user to
  count them (about two queries each), and the batches do not prime the user cache.
  *Why no guard saw it:* the growth tests measure lists.
- **F-115** (J × Volume). Notifications of a thread load each recipient one by one and
  send synchronously in the request of the commenter (25 commenters: 205 queries and
  26 emails). *Why no guard saw it:* no growth test for writes.
- **F-116** (J × Value). The feedback email holds the full IP address although
  `ip_anonymize` is on; decision whether the setting covers emails. *Why no guard saw
  it:* the feedback test does not set it.
- **F-117** (J × Presentation, J × Guidelines). Emails about private messages and wall
  posts have no link or header to stop them. *Why no guard saw it:* the footer tests
  cover emails about posts.
- **F-118** (K × State). Deactivation clears the event of the newsletter but leaves it
  `running`; after activation the admin shows a sending that never moves. *Why no
  guard saw it:* the test checks the hook only.
- **F-119** (K × Guidelines, K × Multisite). The checklist says the events are cleared
  "on every site of a network"; on a large network the deactivation skips every site,
  the main site too. *Why no guard saw it:* `Docs_Test` does not read sentences.
- **F-120** (L × Frequency, L × Volume). The `<link rel="alternate">` of every Q&A page
  builds the whole feed with full text to find its title; the number of queries stays,
  the time grows with the items. *Why no guard saw it:* `Query_Growth_Test` measures
  blocks, not `wp_head`.
- **F-121** (L × State, L × Guidelines). Empty categories are listed in the sitemap
  although their page is `noindex`; core leaves out empty terms. The counter-check, a
  category with a question, did not run. *Why no guard saw it:* the sitemap test has no
  empty category.
- **F-122** (L × Identity, L × Guidelines). The profile sitemap lists users who only
  voted; core lists authors with published posts. *Why no guard saw it:* the test users
  wrote posts.
- **F-123** (N × State). Erasing the votes of a person leaves `answer_max_votes` of the
  question as it was. *Why no guard saw it:* the eraser test reads the votes table.
- **F-124** (M × Concurrency). Two runs of `refill-events` at the same time (command
  line and tools page) write every event twice. *Why no guard saw it:* no test
  interleaves two runs.
- **F-125** (M × Form). The list of tools in the synopsis of `wp easyq recalc` is a
  copy of `Tools::STEPS` by hand; it matches today and no test compares them. *Why no
  guard saw it:* nothing reads the docblock.
- **F-126** (O × Multisite). A site that never had easyQ keeps the uninstall from
  deleting the meta of the whole network (`easyq_unconfirmed`). *Why no guard saw it:*
  only the case "no site agreed" runs.
- **F-127** (O × Concurrency). `userfields.name` has no unique key, so two installs at
  the same time add the default field twice. *Why no guard saw it:* no test interleaves
  two installs.
- **F-128** (O × State). An upgrade brings back the default field the site owner
  deleted. *Why no guard saw it:* the upgrade test checks tables and version.
- **F-129** (O × Error path). The version is saved although a table could not be
  created, and no later upgrade creates it. *Why no guard saw it:* no test breaks a
  `CREATE TABLE`.
- **F-130** (P × Guidelines). `docs/hooks.md` does not name the three filters of others
  easyQ applies, nor the strings it passes to WPML and Polylang. *Why no guard saw it:*
  as F-97.
- **F-131** (P × Frequency). `easyq_default_settings` runs 356 times to render one
  question page. *Why no guard saw it:* no test counts filter calls.
- **F-132** (Q × Multisite). On a network-wide activation the action of the database
  test links to the plugins screen of the site, where the plugin cannot be changed.
  *Why no guard saw it:* no Site Health test on a multisite.
- **F-133** (Q × Error path). The list of the database test says "Created table …"
  although the dry run of `dbDelta()` created nothing. *Why no guard saw it:* the test
  reads the missing tables, not the wording.

## Deferred

| No. | Class | Finding | Why deferred | Comes back when |
| --- | --- | --- | --- | --- |
| F-2 | should | The coverage job of the CI only reports, and mutations are done by hand. | Measured on 2026-11-15 with Xdebug: 2,748 of 15,801 statements are reported as not covered, most of them code that runs while the test suite loads the plugin, and classes only the unit tests cover. A gate on that number would need thousands of exceptions. A gate on the lines a change adds works, but counts only tests that claim the lines with `@covers`: six test files, among them the guards that read whole classes (`REST_Roles_Test`, `Docs_Test`, `Admin_Capabilities_Test`), have `@coversNothing`. Which of the two to change is a decision of the owner. | The owner decides how the guards count (give them `@covers`, or drop the annotations and count every test); then the CI checks the added lines and the review command runs the mutation script. |
| F-25 | blocker | `readme.txt` says the plugin is "not ready for live sites yet", and `Contributors` is missing. | The sentence is true until the release, and the owner gives the WordPress.org username in phase 9 (decided on 2026-11-15). | Phase 9, before the plugin is submitted. |

## Rejected

| No. | Finding | Why rejected |
| --- | --- | --- |
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

Not reproduced in this round, because it needs a browser or the editor end to end
(each stays a suspicion, not a finding, and the next round runs it first):

- The vote, dismiss and favorite actions of the store have no `try`, so a failed
  request leaves the button pressed.
- A vote button driven by a `data-wp-` directive in an answer of a visitor (the
  server side is F-60).
- The user and question pickers of the editor with more than 100 entries (the
  server side is F-106).
- The tag feed with a tag of the same name in two languages.
