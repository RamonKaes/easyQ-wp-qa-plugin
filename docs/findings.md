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

The next free number is F-40.

## Open

| No. | Class | Cell | Finding | Why no guard saw it |
| --- | --- | --- | --- | --- |
| F-2 | should | Cross-cutting | The coverage job of the CI only reports. A line no test runs does not turn anything red, and a line that runs without an assertion looks covered. Mutations are done by hand for each change and are not repeated when the code around them changes. | There is no guard; this is the gap. To decide: a list of the uncovered lines with a reason for each, red at every new one, and a script that removes the candidates and runs the tests. |
| F-19 | should | I × Error path | A failed upload counts as "no file": for the PHP errors 1, 2, 3, 6, 7 and 8 `Avatars::file_entry()` returns null (PHP leaves `tmp_name` and `type` empty), so the profile is saved and the form says so. The branches for `easyq_avatar_upload` and `easyq_avatar_size` cannot be reached from a request. Question2Answer reports the size limit. | `file_entry()` has no test; the upload tests build the entry of a successful upload. |
| F-20 | should | I × State | When a user is deleted and the content goes to another user, the avatar stays: the attachment moves to the new owner and keeps the mark of the deleted user. Nothing in the plugin listens to the deletion of a user for avatars. | The tests cover replace, remove, erase and uninstall, not `wp_delete_user()` with a new owner. |
| F-21 | should | F × Volume | The screen option of the posts screen accepts 1 to 999 posts per page, the query caps at 100, and the pagination counts with the chosen number: with 230 posts and 200 per page the screen shows two pages and 200 posts, 30 are never listed. | `test_screen_options_set_the_number_of_posts_per_page` tries 2, 0 and 1000. |
| F-22 | should | F × Identity | A blocked user changes the Q&A profile fields on the profile screen of the admin; `POST /users/me` refuses the same user with 403, and Question2Answer does not save the profile of a blocked user. | `Profile_Screen_Test` has no blocked user. |
| F-25 | blocker | Cross-cutting × Guidelines | `readme.txt` says the plugin is "not ready for live sites yet", and `Contributors` is missing. The plugin team does not accept plugins that are not ready (Plugin Developer FAQ; guideline 16), and the readme has to validate. Not verifiable yet: the slug. It is derived from the name, and text domain, main file and the bug address of the template assume `easyq`. | The checklist had "Guidelines 1–18: Met" as one row without evidence; Plugin Check does not read sentences. |
| F-26 | should | A × Guidelines | No response carries `_links` (`prepare_links()` in every core controller), and `includes/rest/` has no filter such as `rest_prepare_{type}`: other plugins can add fields but cannot change a response. | Not in the checklist; `REST_Schemas_Test` checks the body, not links or hooks. |
| F-27 | should | A × Guidelines | Pagination differs from core: no `Link` header, a page beyond the last answers 200 with an empty list instead of 400, and `/categories`, `/posts/{id}/comments`, `/notices`, `/user-fields`, `/email-templates` and the conversation of `/messages` send no totals. | Not in the checklist. |
| F-28 | should | A × Guidelines | The seven `DELETE` routes delete for good without the `force` argument; core answers 501 `rest_trash_not_supported` without it. To decide: add it or keep with a reason. | Decision 51 records `deleted` and `previous`, not `force`. |
| F-29 | should | A × Guidelines | Five reading routes declare no `context`: `/questions`, `/questions/similar`, `/questions/{id}/answers`, `/posts/{id}/comments`, `/newsletter`. The checklist said "on every reading route". `context=edit` without the right drops fields instead of answering `rest_forbidden_context`. | The row was "Met" without a test that reads the routes. |
| F-30 | should | A × Guidelines | Smaller deviations from the core controllers: 36 path variables without an entry in `args`; `readonly` on properties their routes accept (categories, user fields); `/users/me` takes POST only where core takes POST, PUT and PATCH; `POST /favorites` answers 200, wall posts and messages 201 without `Location`; method names of five controllers differ from `WP_REST_Controller`; validation errors of the services have another shape than `rest_invalid_param`; a few fixed 403 where a visitor gets 401 from core. | Not in the checklist. |
| F-31 | should | B × Guidelines | Two sentences are built from fragments: "Log in or register to take part." from four strings and the link text "privacy policy" inserted into another sentence (`includes/blocks/render/class-forms.php`). The handbook asks for whole sentences. | The i18n sniffs of PHPCS check domain and placeholders, not sentences. |
| F-32 | should | B × Guidelines | On a multisite the registration is `wp-signup.php`, where `register_form` never runs: terms, profile fields, newsletter request and the text above the form do not appear and no consent is recorded. Core offers `signup_extra_fields` there. The checklist row on consent holds for a single site only. | `Registration_Test` calls the hooks of the single-site form in both runs. |
| F-33 | should | B × Guidelines | Smaller deviations in the forms: one nonce action per form, not per post and action (core: `delete-comment_{id}`), also for the row actions of the posts screen; required fields without the marker of `wp_required_field_indicator()`; the registration fields come back empty after an error; failures redirect with a message where core ends with `wp_die()`. The last is kinder than core and needs a decision. | Not in the checklist. |
| F-34 | should | I × Guidelines | Uploading an avatar needs a login and the setting, not `upload_files` as every upload of core; the file becomes an attachment of the media library. To decide: keep as the feature of Question2Answer. Also: the result of the image editor's `save()` is not read, so a mapped output format (`image_editor_output_format`) leaves the attachment on the unprocessed file. The second part was read, not run. | Not in the checklist. |
| F-35 | should | F × Guidelines | The list tables differ from core: no `set_screen_reader_content()`, so the hidden headings are missing; the page slug as `screen` and hand-set column headers, so Screen Options offers no columns; `_wpnonce` and `_wp_http_referer` stay in the address after a filter or search; views and the checkbox column built by hand. | The checklist row checks the base class and the row header only. |
| F-36 | should | F × Guidelines | Smaller deviations on the screens: notices written by hand in six files although `wp_admin_notice()` is used in two; one inline `style` attribute on the newsletter buttons; `id="submit"` twice on three screens; the whole `$_POST` handed to four handlers; `did`, `done` and `failed` unprefixed in `removable_query_args` while `easyq_done` is never removed; no help tabs; the label of "Level" on the profile; one constant without `@since`. | PHPCS and axe see none of these as errors. |
| F-37 | should | G × Error path | A value the sanitizing refuses is dropped or clamped without a message and the screen says "Settings saved."; an invalid email address is stored as empty. Core reports with `add_settings_error()`, and the email templates of the plugin do too. Also: the sections are registered without `default`. | `Settings_Test` asserts the stored value, not what the user is told. |
| F-38 | should | Cross-cutting × Guidelines | The checklist said more than was checked: the reasons of "Kept: menu icon" do not hold (a data URI needs no `base64_encode()`, and WordPress 7.2 accepts a registered icon name), "three lists" are four, `load_plugin_textdomain()` is not needed for a plugin translated on WordPress.org, and no "Met" named its evidence. | The checklist had no column for evidence; `Docs_Test` checks names. |

## Deferred

None.

## Rejected

None.

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
