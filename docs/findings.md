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

The next free number is F-42.

## Open

None.

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
