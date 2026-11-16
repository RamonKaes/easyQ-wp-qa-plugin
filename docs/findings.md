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

None open; F-56 to F-63 were fixed on 2026-11-16.

### Should

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
### Low

- **F-130** (P × Guidelines). `docs/hooks.md` does not name the three filters of others
  easyQ applies, nor the strings it passes to WPML and Polylang. *Why no guard saw it:*
  as F-97.
- **F-131** (P × Frequency). `easyq_default_settings` runs 356 times to render one
  question page. *Why no guard saw it:* no test counts filter calls.
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
