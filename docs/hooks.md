# easyQ hooks and extension points

This document lists the public actions, filters and interfaces of easyQ. Hook
names and parameters are part of the public API and only change with a major
version.

## Events

Every change to questions, answers, comments and votes is reported as an event.
Each event fires two actions:

- `easyq_{event}`, e.g. `easyq_q_post`, with the arguments `( array $params, EasyQ\Actor $actor )`
- `easyq_event` with the arguments `( string $event, array $params, EasyQ\Actor $actor )`

`$actor` describes who caused the event: `$actor->user_id` (WordPress user ID or
null), `$actor->cookie_id` (anonymous visitor cookie or null) and `$actor->ip`
(IP address of the request or null). Automatic changes, e.g. hiding a post after
too many flags, use an actor without user, cookie and IP address.

```php
add_action(
	'easyq_q_post',
	static function ( array $params, EasyQ\Actor $actor ): void {
		// $params['post_id'], $params['title'], $params['tags'], …
	},
	10,
	2
);
```

The event names are those of Question2Answer, so existing knowledge and event
logs carry over.

### Questions

| Event | When | Notable parameters |
| --- | --- | --- |
| `q_post` | A question was published, also after approval (then with `delayed`) | `post_id`, `parent_id` (follow-on answer), `title`, `content`, `format`, `text`, `tags`, `category_id`, `extra`, `name`, `notify`, `delayed` |
| `q_queue` | A new question waits for moderation | as `q_post` |
| `q_edit` | A question was edited | `post_id`, `title`, `content`, `format`, `text`, `tags`, `extra`, `name`, `old`, `silent`, `title_changed`, `content_changed`, `tags_changed` |
| `q_requeue` | A question went back to moderation | `post_id`, `old`, … |
| `q_approve` | A queued question was approved | `post_id`, `old`, … |
| `q_reject` | A queued question was rejected (hidden) | `post_id`, `old`, … |
| `q_hide` | A question was hidden | `post_id`, `old`, … |
| `q_reshow` | A hidden question was shown again | `post_id`, `old`, … |
| `q_close` | A question was closed | `post_id`, `old`, `reason` (`duplicate`, `other`, `answer-selected`), `original_id`, `note` |
| `q_reopen` | A closed question was reopened | `post_id`, `old` |
| `q_move` | A question moved to another category | `post_id`, `old`, `category_id`, `old_category_id` |
| `q_claim` | An anonymous question was assigned to a user | `post_id`, `parent_id`, `old` |
| `q_delete_before` | A question is about to be deleted | `post_id`, `old` |
| `q_delete` | A question was deleted | `post_id`, `old` |
| `q_vote_up`, `q_vote_down`, `q_vote_nil` | A vote on a question was set or removed | `post_id`, `user_id` (author), `vote`, `old_vote` |
| `q_flag`, `q_unflag`, `q_clearflags` | A question was flagged, unflagged, or all flags were cleared | `post_id`, `old`, `flag_count`, `question_id` |

### Answers

| Event | When | Notable parameters |
| --- | --- | --- |
| `a_post` | An answer was published, also after approval (then with `delayed`) | `post_id`, `parent_id`, `content`, `format`, `text`, `category_id`, `name`, `notify`, `delayed` |
| `a_queue` | A new answer waits for moderation | as `a_post` |
| `a_edit` | An answer was edited | `post_id`, `parent_id`, `content`, `format`, `text`, `name`, `old`, `silent`, `content_changed` |
| `a_requeue`, `a_approve`, `a_reject`, `a_hide`, `a_reshow` | Moderation and visibility changes | `post_id`, `old`, … |
| `a_select`, `a_unselect` | An answer was selected as best answer, or the selection was removed | `parent_id` (question), `post_id` (answer) |
| `a_to_c` | An answer was converted into a comment | `post_id`, `parent_id`, `parent_type`, `question_id`, `old`, `silent`, `content_changed` |
| `a_claim` | An anonymous answer was assigned to a user | `post_id`, `parent_id`, `old` |
| `a_delete_before`, `a_delete` | An answer is deleted | `post_id`, `parent_id`, `old` |
| `a_vote_up`, `a_vote_down`, `a_vote_nil` | Votes on answers | as for questions |
| `a_flag`, `a_unflag`, `a_clearflags` | Flags on answers | as for questions |

### Comments

| Event | When | Notable parameters |
| --- | --- | --- |
| `c_post` | A comment was published, also after approval (then with `delayed`) | `post_id`, `parent_id`, `parent_type`, `question_id`, `thread` (IDs of the earlier visible comments on the same parent), `content`, `format`, `text`, `category_id`, `name`, `notify`, `delayed` |
| `c_queue` | A new comment waits for moderation | as `c_post` |
| `c_edit` | A comment was edited | `post_id`, `parent_id`, `parent_type`, `question_id`, `content`, `old`, `silent`, `content_changed` |
| `c_requeue`, `c_approve`, `c_reject`, `c_hide`, `c_reshow` | Moderation and visibility changes | `post_id`, `old`, … |
| `c_claim` | An anonymous comment was assigned to a user | `post_id`, `parent_id`, `old` |
| `c_delete_before`, `c_delete` | A comment is deleted | `post_id`, `parent_id`, `parent_type`, `question_id`, `old` |
| `c_vote_up`, `c_vote_down`, `c_vote_nil` | Votes on comments | as for questions |
| `c_flag`, `c_unflag`, `c_clearflags` | Flags on comments | as for questions |

`old` is the `EasyQ\Posts\Post` before the change. User, message and wall events
follow with the corresponding features.

## Filters

| Filter | Arguments | Purpose |
| --- | --- | --- |
| `easyq_default_settings` | `array $defaults` | Default values of all settings, keyed by section and setting |
| `easyq_search_providers` | `EasyQ\Search\Search_Provider[] $providers` | Add search engines; the built-in search is always kept |
| `easyq_client_ip` | `string $ip` | IP address of the current request; easyQ trusts only `REMOTE_ADDR` by default. Sites behind a proxy return the client address from a trusted header here |

## Interfaces

### `EasyQ\Search\Search_Provider`

A search engine. Every registered provider is informed when posts are indexed,
removed or moved; the provider selected in the settings answers searches. See
`includes/search/interface-search-provider.php`.

## Services

Extensions can use the plugin services through `EasyQ\Services`, for example:

```php
$question = EasyQ\Services::posts()->find( 42 );
$results  = EasyQ\Services::search()->search( 'block themes', 0, 10 );
```

| Accessor | Service |
| --- | --- |
| `Services::posts()` | `EasyQ\Posts\Post_Repository`: read posts |
| `Services::post_service()` | `EasyQ\Posts\Post_Service`: create, edit, moderate and delete posts |
| `Services::validator()` | `EasyQ\Posts\Post_Validator`: validate user input |
| `Services::votes()` | `EasyQ\Votes\Votes`: votes and flags |
| `Services::categories()` | `EasyQ\Categories\Categories`: categories |
| `Services::search()` | `EasyQ\Search\Search`: search and index |
| `Services::points()` | `EasyQ\Points\Points`: user points |
| `Services::limits()` | `EasyQ\Limits\Limits`: rate limits |

The services do not check permissions; the REST API and the blocks do that.
