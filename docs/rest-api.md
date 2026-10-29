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

## Errors

Errors use the usual WordPress format with `code`, `message` and `data.status`.

| Code | Status | Meaning |
| --- | --- | --- |
| `easyq_login` | 401 | Log in first |
| `easyq_confirm` | 403 | Confirm the email address first |
| `easyq_approve` | 403 | The account must be approved first |
| `easyq_level` | 403 | Not allowed for this user |
| `easyq_userblock` | 403 | The user is blocked |
| `easyq_ipblock` | 403 | The IP address is blocked |
| `easyq_limit` | 429 | Rate limit reached for this hour |
| `easyq_read_only` | 503 | The Q&A area is read-only |
| `easyq_not_found` | 404 | Missing, or not visible to the user |
| `easyq_invalid` | 400 | Invalid input; `data.fields` holds a message per field |

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
| `GET /questions` | List questions. Parameters: `source` (`recent`, `hot`, `votes`, `answers`, `views`, `unanswered`, `unselected`, `unupvoted`), `category` (includes subcategories), `tag`, `author`, `page`, `per_page`. Totals in the `X-WP-Total` and `X-WP-TotalPages` headers |
| `POST /questions` | Ask a question: `title`, `content`, `format` (empty for plain text or `html`), `tags` (array, or text as entered by users), `category`, `notify`, `email` and `name` (anonymous authors), `extra` |
| `GET /questions/similar` | Similar questions and suggested tags for a `title`, as configured for the ask form |
| `GET /questions/{id}` | A question; `context=edit` adds the raw content for users who may edit |
| `PUT /questions/{id}` | Edit title, content, tags or category; `silent` hides the edit for users allowed to edit silently |

### Answers

| Method and route | Description |
| --- | --- |
| `GET /questions/{id}/answers` | Answers sorted as configured, optionally with the selected answer first; `page`, `per_page` |
| `POST /questions/{id}/answers` | Answer: `content`, `format`, `notify`, `email`, `name` |
| `GET /answers/{id}` | An answer |
| `PUT /answers/{id}` | Edit an answer: `content`, `format`, `silent` |

### Comments

| Method and route | Description |
| --- | --- |
| `GET /posts/{id}/comments` | Comments on a question or answer, oldest first |
| `POST /posts/{id}/comments` | Comment: `content`, `format`, `notify`, `email`, `name` |
| `GET /comments/{id}` | A comment |
| `PUT /comments/{id}` | Edit a comment |

### Votes and actions

| Method and route | Description |
| --- | --- |
| `POST /posts/{id}/vote` | `vote`: `1`, `0` or `-1` |
| `POST /posts/{id}/actions/{action}` | Perform an action; responds with the changed post, with its question if an answer or comment was deleted, or with `deleted` and `id` if a question was deleted |

| Action | Parameters | Description |
| --- | --- | --- |
| `select` | `answer` | Select the best answer of the question `{id}` |
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
| `DELETE /categories/{id}` | Delete a category without subcategories; `reassign` names the category that receives its posts (0 or omitted for none). Needs `easyq_manage` |
| `POST /categories/{id}/reassign` | Move the posts directly in a category, not those in its subcategories, to the category `to` (0 for none); the ID 0 stands for posts without a category. Returns the number of moved questions. Needs `easyq_manage` |
| `GET /tags` | Tags by popularity; `search` completes a prefix |
| `GET /users` | Users by points (`sort=points`, those who took part), the newest users (`newest`) or users with special levels (`special`); paginated, each with name, link, avatar, level, title and points. `newest` and `special` follow the permissions of the matching Q2A pages |
| `GET /users/{id}` | Public Q&A profile: name, avatar, level, title, points, rank, activity counts and the profile fields the viewer may see. On multisite, users of other sites only have a profile once they took part |
| `GET /users/me` | The profile of the current user, with all profile fields, including those hidden from others |
| `POST /users/me` | Change the own profile: `fields` (object of field name and value), `remove_avatar`, or a new avatar as multipart upload in the field `avatar` (JPEG, PNG, GIF or WebP; cropped to a square; counts towards the upload rate limit). Name, email address and password are changed in the WordPress profile |

Unless the site allows it in the posting settings, a question needs a category once
categories exist, and that category must not have subcategories. This applies to
`POST /questions`, to `category` in `PUT /questions/{id}` (`null` removes the
category) and to the `move` action; a question may always keep the category it has.

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

### Settings

The settings shown on the easyQ settings screen are part of the core endpoint
`/wp/v2/settings` (`GET` and `POST`, capability `manage_options`), one object per
section: `easyq_settings_general`, `easyq_settings_permissions`, `easyq_settings_users`,
`easyq_settings_posting`, `easyq_settings_viewing`, `easyq_settings_lists`,
`easyq_settings_feeds` and `easyq_settings_spam`. An update may pass only some settings
of a section; the others keep their value. Unknown settings and values outside the
allowed range are refused with `rest_invalid_param`.
