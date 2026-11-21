# easyQ REST API

All endpoints live in the namespace `easyq/v1` of the WordPress REST API, e.g.
`https://example.com/wp-json/easyq/v1/questions`.

## Authentication

The API uses the WordPress REST API authentication:

- **Cookies with nonce** for requests from the site itself: send the `X-WP-Nonce`
  header with a nonce for the action `wp_rest`.
- **Application passwords** for other clients.

Visitors can read whatever the permission settings allow. Anonymous authors (if
the site allows anonymous posting) receive the cookie `easyq_visitor` with a
random number, so they can edit and later claim their posts.

## Schemas, contexts and fields

The controllers follow `WP_REST_Controller`, one controller per resource: posts,
questions, answers, comments, categories, tags, users, profile fields, IP addresses
and tools.

- The callbacks have the names of `WP_REST_Controller` (`get_items`, `get_item`,
  `create_item`, `update_item`, `delete_item`), every part of a path is described as an
  argument with its type, and the properties a route accepts are not read-only.
- Every route describes its response in a JSON schema; an `OPTIONS` request returns
  it, e.g. `OPTIONS /easyq/v1/categories`. Lists are described by the schema of one
  item. Deleting responds with `deleted` and the `previous` item, as core does.
- Every property names its contexts. Every reading route takes `context`: `view`
  (default), `embed` for the properties that identify an item, and `edit`, which adds
  the raw title and content of a post. As in core, the edit context is refused with
  `rest_forbidden_context` to those who may not edit: a post to those who may not edit
  it, the lists of posts to those who may not moderate, users to those who may not list
  them. User lists default to `embed`, without profile fields and counts.
- Lists take `page` and `per_page` and page as core does: `X-WP-Total` and
  `X-WP-TotalPages`, a `Link` header to the previous and next page, and `400
  rest_invalid_page_number` for a page beyond the last. Categories and comments are
  paginated too, ten per page by default, as core paginates terms and comments. Lists
  of settings, such as notices, profile fields and email templates, are complete, as
  core lists post types and statuses.
- A DELETE that deletes for good (categories, profile fields, wall posts) asks for
  `force=true`, as core asks for terms, and answers `501 rest_trash_not_supported`
  without it; a DELETE that changes a state (a notice, a favorite, a template, a
  message hidden for one side) takes none, as core's application passwords take none.
- Each item links itself, its collection, its author and the post it belongs to
  (`_links`, the last two embeddable), as core's controllers do, and plugins change a
  response with `easyq_rest_prepare_{object}` (`docs/hooks.md`).
- Plugins can add fields with `register_rest_field()` for the object types
  `easyq-question`, `easyq-answer`, `easyq-comment`, `easyq-post` (moderation list and
  actions), `easyq-category`, `easyq-tag`, `easyq-user`, `easyq-user-field`,
  `easyq-ip`, `easyq-tool-batch`, `easyq-email-template`, `easyq-favorite`, `easyq-notice`, `easyq-message`, `easyq-feedback` and `easyq-newsletter`. `_fields` limits a response to some fields.

## Errors

Errors use the usual WordPress format with `code`, `message` and `data.status`.

| Code | Status | Meaning |
| --- | --- | --- |
| `rest_not_logged_in` | 401 | Log in first, with core's code for it (as `/wp/v2/users/me`) |
| `easyq_confirm` | 403 | Confirm the email address first |
| `easyq_approve` | 403 | The account must be approved first |
| `rest_forbidden` | 401 or 403 | Not allowed: 401 for visitors, who may be allowed once logged in, 403 for users, as core decides (`rest_authorization_required_code()`) |
| `easyq_author` | 403 | Only the author can change the notification settings of a post |
| `easyq_userblock` | 403 | The user is blocked; a block stops changes, not reading, as in Q2A |
| `easyq_ipblock` | 403 | The IP address is blocked; like a blocked user, it may still read |
| `easyq_limit` | 429 | Rate limit reached for this hour |
| `easyq_duplicate` | 409 | The same content was just posted (`POST /questions`, `/questions/{id}/answers`, `/posts/{id}/comments`): a question with the words of title and content of one of the five newest visible questions, or of a waiting one of the same author, as in Question2Answer; an answer or comment with the words of one of the same author on the same post, as core refuses comments (`comment_duplicate`). A guest is the visitor cookie, or the address without it. Two equal requests at the same moment pass once |
| `easyq_changed` | 409 | Someone else changed the status of the post first (`hide`, `reject`, `approve`, `reshow` read the post as it was before): only the first of two moderators acting at once goes through, so its events and emails happen once |
| `easyq_read_only` | 503 | The Q&A area is read-only: every request that changes something answers this before its own checks, unless the user manages easyQ; reading stays open |
| `rest_post_invalid_id`, `rest_user_invalid_id`, `rest_term_invalid` | 404 | A post (question, answer, comment), user or category that is missing or not visible to the user, with core's codes for them |
| `easyq_not_found` | 404 | Another item that is missing or not visible to the user: a user field, email template, IP address, notice or message, which core does not have |
| `rest_invalid_param` | 400 | Invalid input, as core answers invalid parameters: `data.params` holds a message per field, `data.details` its error |

## Posts

Questions, answers and comments share one representation:

| Field | Description |
| --- | --- |
| `id` | Post ID |
| `type` | `question`, `answer`, `comment` or `note` |
| `status` | `normal`, `hidden` or `queued` |
| `parent`, `question` | Parent post and the question the post belongs to |
| `date_gmt`, `modified_gmt` | Creation and last change in UTC (RFC 3339) |
| `author` | `id` (null for anonymous authors), `name`, `avatar`, `points` |
| `content` | `rendered` HTML, `format`; `raw` in the `edit` context for users who may edit |
| `excerpt` | Shortened plain text |
| `votes` | `up`, `down`, `net` and the current user's vote in `user` |
| `flags` | `user` (flagged by the current user); `count` for moderators only |
| `can` | Actions the current user can perform: `answer`, `comment`, `edit`, `retag`, `select`, `vote`, `vote_down`, `flag`, `unflag`, `clear_flags`, `close`, `reopen`, `moderate`, `hide`, `reshow`, `delete`, `claim`, `follow` |
| `ip` | IP address of anonymous authors, for users allowed to see it |

Questions also have `title` (`rendered`, and `raw` in the `edit` context), `tags`,
`category`, `answer_count`, `views`, `selected_answer`, `closed` and `closed_by`.

Newly created posts carry `queued: true` if they wait for moderation.

## Endpoints

### Questions

| Method and route | Description |
| --- | --- |
| `GET /questions` | List questions. Parameters: `source` (`recent`, `hot`, `votes`, `answers`, `views`, `unanswered`, `unselected`, `unupvoted`, or `favorites` for the favorites of the current user), `category` (includes subcategories), `tag`, `author`, `search` (words, best match first, as the search page finds them; the editor pickers use it), `page`, `per_page`. Totals in the `X-WP-Total` and `X-WP-TotalPages` headers; with `search` they are a lower bound (the results up to the page asked, and one more), as the search page of Question2Answer shows no total |
| `POST /questions` | Ask a question: `title`, `content`, `format` (empty for plain text or `html`; HTML of questions and answers is filtered as core filters posts, without `data-wp-*` attributes, HTML of comments as core filters comments), `tags` (array, or text as entered by users), `category`, `notify`, `email`, `name` and `remember` (anonymous authors; `remember` is the consent to the visitor cookie while the site asks for it, `show_comments_cookies_opt_in`), `extra` (only while the extra field is on) |
| `GET /questions/similar` | Similar questions and suggested tags for a `title`, as configured for the ask form |
| `GET /questions/{id}` | A question; `context=edit` adds the raw content for users who may edit. While the extra field is on, `extra` holds its value as `rendered` (empty unless question pages show it) and, in the edit context, `raw` |
| `PUT /questions/{id}` | Edit title, content, tags, category or, while the extra field is on, `extra`; `silent` hides the edit for users allowed to edit silently. Every field sent needs the right to edit, except `tags` and `category`, which the right to retag allows as well; a request without fields needs one of the two, as in core. `notify` and `email` change the notifications of the author and only the author may send them |

### Answers

| Method and route | Description |
| --- | --- |
| `GET /questions/{id}/answers` | Answers sorted as configured, optionally with the selected answer first; `page`, `per_page` |
| `POST /questions/{id}/answers` | Answer: `content`, `format`, `notify`, `email`, `name` |
| `GET /answers/{id}` | An answer |
| `PUT /answers/{id}` | Edit an answer: `content`, `format`, `silent`; `notify` and `email` by the author only, as for questions |

### Comments

| Method and route | Description |
| --- | --- |
| `GET /posts/{id}` | A question, answer or comment by its ID, with the fields of its type, or the closing note of a question (`closed_by`); `context=edit` adds the raw content for users who may edit it. Posts the user may not see answer `404`, and so does a post below one: a comment below a hidden answer is hidden with it on every route |
| `GET /posts/{id}/comments` | Comments on a question or answer, oldest first |
| `POST /posts/{id}/comments` | Comment: `content`, `format`, `notify`, `email`, `name` |
| `GET /comments/{id}` | A comment |
| `PUT /comments/{id}` | Edit a comment: `content`, `format`, `silent`; `notify` and `email` by the author only, as for questions |

### Votes and actions

| Method and route | Description |
| --- | --- |
| `POST /posts/{id}/vote` | `vote`: `1`, `0` or `-1`. Questions, answers and comments only: a closing note changes only when its question is closed or reopened, so votes and actions on it answer `404` |
| `POST /posts/{id}/actions/{action}` | Perform an action; responds with the changed post, with its question if an answer or comment was converted or moved away, or, after `delete`, with `deleted` and the `previous` post, as core responds to deleting |

| Action | Parameters | Description |
| --- | --- | --- |
| `select` | `answer` | Select the best answer of the question `{id}`; without `answer`, `400` |
| `unselect` | – | Remove the selection |
| `flag`, `unflag`, `clear-flags` | – | Flag, remove own flag, remove all flags |
| `hide`, `reshow` | – | Hide, show again |
| `approve`, `reject` | – | Moderate a queued post |
| `delete` | – | Delete a hidden post without dependents |
| `close` | `duplicate_of` or `note` | Close as duplicate or with a reason |
| `reopen` | – | Reopen |
| `claim` | – | Take over an anonymous post written with the same visitor cookie |
| `convert` | `parent` | Convert an answer into a comment on the question or another answer |
| `move` | `category` | Move a question to another category |

### Categories, tags and users

| Method and route | Description |
| --- | --- |
| `GET /categories` | All categories; `parent=0` for the top level, `parent={id}` for children |
| `GET /categories/{id}` | A category with its slug path |
| `POST /categories` | Add a category: `title`, optional `slug` (derived from the name, with a `category-…` fallback as in Q2A), `description`, `parent` (0 for the top level) and `position` (1 for first). Needs `easyq_manage` |
| `PUT`/`PATCH /categories/{id}` | Change name, slug, description, parent or position; parameters that are not passed keep their value. A category that gets another parent goes to the end of its new siblings unless `position` is given. Every change is checked before any is stored. Needs `easyq_manage` |
| `DELETE /categories/{id}` | Delete a category without subcategories; `reassign` names the category that receives its posts (0 or omitted for none). Needs `easyq_manage` and, as core for terms, `force=true`: there is no trash, so without it `501 rest_trash_not_supported` |
| `POST /categories/{id}/reassign` | Move the posts directly in a category, not those in its subcategories, to the category `to` (0 for none); the ID 0 stands for posts without a category. Returns the number of moved questions. Needs `easyq_manage` |
| `GET /tags` | Tags by popularity; `search` completes a prefix, `page` and `per_page` paginate |
| `GET /users` | Users by points (`sort=points`, those who took part), the newest users (`newest`), users with special levels (`special`) or the favorites of the current user (`favorites`); paginated, each with name, link, avatar, level, title and points. `newest` and `special` follow the permissions of the matching Q2A pages and list the profiles the viewer may open: users who took part, or every user for those who may list users (`list_users`), as core sorts users by their registration only for them. `search` finds users shown to the viewer by name, in the columns core searches for those who may not list users |
| `GET /users/{id}` | Public Q&A profile: name, avatar, level, title, points, rank, activity counts and the profile fields the viewer may see. As core shows users, a profile is public once its user took part in the Q&A area; until then only the user and those who may list or edit users see it, and others get `404`, here, on the wall and on the profile page. The date of the registration is in the edit context, which, as in core, only they may ask for (`rest_forbidden_context` otherwise). On multisite, users of other sites only have a profile once they took part |
| `POST /users/{id}/actions/{action}` | `approve`, `block` or `unblock` a user, as a moderator may on the profile page of Question2Answer: a user below the level of the moderator (any user for a super administrator, or for who may edit the user in WordPress), never oneself; `approve` only while new users are moderated (400 otherwise), `block` not for moderators and higher (403). Answers `id`, `awaiting_approval` and `blocked`. The profile page offers the same as buttons that work without JavaScript |
| `GET /users/me` | The profile of the current user, with all profile fields, including those hidden from others |
| `POST /users/me` | Also `PUT` and `PATCH`, as `/wp/v2/users/me`. Change the own profile: `fields` (object of field name and value), `remove_avatar`, `notifications` (whether the user gets emails about their posts; only the current user sees this field), or a new avatar as multipart upload in the field `avatar` (JPEG, PNG, GIF or WebP; turned upright, cropped to a square, without metadata; refused with `400` if it has more pixels than the memory for images can decode; every attempt counts towards the upload rate limit, as in Question2Answer). Name, email address and password are changed in the WordPress profile |

Unless the site allows it in the posting settings, a question needs a category once
categories exist, and that category must not have subcategories. This applies to
`POST /questions`, to `category` in `PUT /questions/{id}` (`null` removes the
category) and to the `move` action; a question may always keep the category it has.

### Favorites

| Method and route | Description |
| --- | --- |
| `GET /favorites` | The favorites of the logged in user: `type` (`question`, `user`, `tag`, `category`), `page`, `per_page`. Each item has `type`, `id`, `title` and `link` |
| `POST /favorites` | Add a favorite: `type`, and `id` with the ID, for tags the name; `201`, without `Location`, since a favorite has no route of its own. Adding it twice changes nothing. Your own user answers `400`, what does not exist `404` |
| `DELETE /favorites` | Remove a favorite: `type` and `id`; responds with `deleted` and `previous` |

### Walls and private messages

| Method and route | Description |
| --- | --- |
| `GET /users/{id}/wall` | Posts on the wall of a user, newest first, with `page` and `per_page`; `404` while walls are off |
| `POST /users/{id}/wall` | Post on a wall: `content`. Needs the permission of the settings; users can turn off posts by others on their wall |
| `DELETE /users/{id}/wall/{message}` | Delete a wall post for good, with `force=true` as for categories: the owner of the wall, the author until someone else posted after it, and moderators who may hide and delete posts |
| `GET /messages` | Private messages of the logged in user: `box` (`inbox`, `sent`), `page`, `per_page`; or `with` a user for the latest 20 between both, oldest first. `404` without the message history |
| `POST /messages` | Send a private message: `to`, a user whose profile the sender may see (else `404`, as for no user), `content`; `201`, without `Location`, since a message has no route to read it alone. It is emailed, and kept if the history is on; otherwise the response has `stored` false |
| `DELETE /messages/{id}` | Delete a message from your box; it is gone once both sides deleted it, so one request deletes nothing for good and takes no `force` |

### Feedback

| Method and route | Description |
| --- | --- |
| `POST /feedback` | Send feedback to the site owner: `message`, and optionally `name`, `email` (the address replies go to) and `previous` (the page the sender came from). Open to visitors, limited per hour like posting; `404` while the form is off |

### Newsletter

| Method and route | Description |
| --- | --- |
| `GET /newsletter` | Progress of the sending: `running`, `done`, `total`. Needs `easyq_manage` |
| `POST /newsletter/{action}` | `start` or `cancel` the sending in batches by WP-Cron, or send a `test` to yourself. Needs `easyq_manage` |
| `POST /newsletter/confirm` | Confirm a subscription: `user` and `key` from the confirmation email; the key is used up and expires after a day (`user_request_key_expiration`) |
| `POST /users/me` with `newsletter` | `true` sends the email to confirm, `false` ends the subscription |
| `POST /unsubscribe` with `user` and `token` | Stops the newsletter, also as the one-click unsubscribe of mail programs (RFC 8058) |

### Notices

| Method and route | Description |
| --- | --- |
| `GET /notices` | The notices of the current user or visitor, with `id` and `content` as HTML |
| `DELETE /notices/{id}` | Dismiss a notice: its number, `welcome`, or `visitor`, which sets the cookie `easyq_noticed`. Only your own; others answer `404` |

### Emails

| Endpoint | Purpose |
| --- | --- |
| `GET /email-templates`, `GET /email-templates/{name}` | Texts of the emails with who gets them, whether they were edited, their placeholders and a preview (`easyq_manage`) |
| `PUT /email-templates/{name}` | Change `subject` and `body`; both are required, as an email needs them |
| `DELETE /email-templates/{name}` | Go back to the default text; responds with `deleted` and `previous` |
| `POST /email-templates/{name}/test` | Send the template with sample values to the current user; responds with `sent` and `to` |
| `POST /unsubscribe` | Stop the emails about a post: `post` and `token` from the email, without logging in. Mail programs post here for a one-click unsubscribe (RFC 8058), from the `List-Unsubscribe` header. With `notify=confirm` and the token of the confirmation email, which holds for the address it was sent to, the author of the post without an account confirms that address instead (double opt-in); no email goes to it before. A changed address is asked again: each address once per post, three at most |

### Moderation

| Method and route | Description |
| --- | --- |
| `GET /posts` | Questions, answers and comments for moderators (`easyq_moderate`), newest first: `view` (`all`, `pending`, `flagged` with the most flags first, `hidden`, `closed`), `type` (`question`, `answer`, `comment`), `category` (includes subcategories), `author`, `ip`, `search` (title and text), `month` (`YYYYMM`), `page`, `per_page`. Posts awaiting approval and hidden posts are only listed where the user may handle them; with category levels, that is in those categories. With `ip`, users who may see IP addresses (`easyq_view_ips`) may list too |
| `GET /ips/{ip}` | Whether an IP address is blocked, the rules of the block list that match it, and whether the user may block it (`can_block`) and hide its posts (`can_hide_all`). Needs `easyq_view_ips` |
| `POST /ips/{ip}/block` | Add the address to the block list of the spam settings. Needs `easyq_view_ips` and the level Moderator in any category, as in Q2A |
| `POST /ips/{ip}/unblock` | Remove every rule that matches the address, including ranges and wildcards, as in Q2A; responds with the removed rules in `removed` |
| `POST /ips/{ip}/hide-all` | Hide the visible posts created from the address, each through the `hide` action and its checks; responds with the number in `hidden`. Also needs the permission to hide posts |

Actions on single posts, such as `approve`, `hide` or `delete`, are under
`POST /posts/{id}/actions/{action}`.

### User fields

| Method and route | Description |
| --- | --- |
| `GET /user-fields` | The profile fields in order, each with `id`, `name`, `label`, `type` (`text`, `multi_line`, `link`), `permit` (who may see it), `on_register` and `position`. Needs `easyq_manage` |
| `POST /user-fields` | Add a field: `label`, optional `name` (derived from the label, as in Q2A), `type`, `permit`, `on_register`, `position` |
| `GET`, `PUT`/`PATCH /user-fields/{id}` | A field; change label, type, visibility, registration form and position. Parameters that are not passed keep their value; the name cannot change |
| `DELETE /user-fields/{id}` | Delete a field and the values users entered, with `force=true` as for categories |

The values users enter are part of `/users/{id}` and `/users/me`.

### Tools

| Method and route | Description |
| --- | --- |
| `POST /tools/{tool}` | Run one batch of a maintenance tool: `recount_posts` (answers, votes, flags, hotness and the counts of the site), `reindex` (search index), `recalc_points`, `recalc_categories`, `refill_events` (what the updates pages of users show) or `delete_hidden` (hidden posts without answers or comments of their own). Pass `step`, `after`, `total`, `done` and `run` from the previous response, or nothing to start; repeat until `complete` is true. The items of a step are counted once, at its start. While a run goes on, another one is refused with 409, so two runs never write the same items twice; a run without a batch for five minutes is over. Each response also has `step_number` and `steps` for the progress. Needs `easyq_manage` |

### Settings

The settings shown on the easyQ settings screen are part of the core endpoint
`/wp/v2/settings` (`GET` and `POST`, capability `manage_options`), one object per
section: `easyq_settings_general`, `easyq_settings_permissions`, `easyq_settings_users`,
`easyq_settings_posting`, `easyq_settings_viewing`, `easyq_settings_lists`,
`easyq_settings_feeds`, `easyq_settings_spam` and `easyq_settings_points`. The points
include `user_titles`, an object of titles keyed by the points needed; changing a points
value recalculates the points of all users. An update may pass only some settings
of a section; the others keep their value. Unknown settings and values outside the
allowed range are refused with `rest_invalid_param`.
