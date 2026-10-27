# easyQ – Feature Mapping: Question2Answer 1.8.8 → WordPress

This document maps every feature, page, option, table and interface of
Question2Answer (Q2A) 1.8.8 to its target in WordPress. It is the binding basis for the
implementation; deviations are recorded here.

## Legend

| Code | Meaning |
| --- | --- |
| **P** | Ported – Q2A logic is carried over; target given |
| **WP** | Replaced by a WordPress core feature |
| **–** | Dropped; reason given |
| **N** | New – not in Q2A, added for WordPress conformance |
| **X** | Deferred to the extension selection at the end of the project |

---

## 0. Principles

1. **WordPress core guidelines, without exception.** WordPress Coding Standards (PHP,
   JS, HTML), Plugin Handbook, Block Editor Handbook, accessibility (WCAG 2.2 AA),
   internationalization, security best practices and the wordpress.org plugin
   guidelines. Every release passes the official Plugin Check tool without findings.
2. **No plugin CSS.** All styling comes from the active block theme. easyQ reuses core
   markup and class names (`wp-block-*`, `wp-element-button`, …), exposes block
   supports (color, typography, spacing, border, layout) and relies on `theme.json`
   presets. Layout is built with core blocks (Group, Row, Stack, Columns) in templates
   and patterns. The CSS that core generates from block supports and global styles is
   the only CSS on the page. Any unavoidable exception must be agreed on first and
   listed in section 12.
3. **Privacy by design and by default (GDPR).** No external requests without opt-in,
   minimal data, defined retention periods, opt-in for every non-essential email,
   full support for the WordPress privacy tools. Details in section 11.
4. **High compatibility.** No global state outside the plugin namespace, no
   overriding of core behaviour, everything extensible through documented hooks.
5. **Language.** All repository documentation and all code comments are written in
   English.

| Topic | Decision |
| --- | --- |
| Name / slug / text domain | easyQ / `easyq` / `easyq` |
| PHP namespace | `EasyQ\`; classes in `includes/` with WordPress file names (`class-*.php`) |
| Prefix for functions, options, hooks, meta | `easyq_` |
| Tables | `{$wpdb->prefix}easyq_*` (per site on multisite) |
| Blocks | `easyq/<name>` |
| REST namespace | `easyq/v1` |
| Capabilities | `easyq_*` |
| Minimum versions | PHP 8.4, WordPress 6.7 |
| Languages | English (source), German `de_DE` (informal) and `de_DE_formal` (formal) |
| License | GPLv2 or later |
| Basis | Q2A logic (`qa-include/app`, `qa-include/db`) ported into classes; no Q2A code is loaded as is |

**Configuration rule:** Global settings provide defaults. Every block can override its
behaviour (page size, columns, avatar size, visible metadata, …) per instance.

---

## 1. Data model

Q2A uses 31 tables. The schema is created with `dbDelta()`, versioned through the option
`easyq_db_version` and upgraded step by step.

| Q2A table | Target | Notes |
| --- | --- | --- |
| `posts` | **P** `easyq_posts` | Questions, answers, comments including states (`_HIDDEN`, `_QUEUED`); plus type `NOTE` for the reasons questions are closed with. `userid` becomes a WP user ID (`BIGINT UNSIGNED`) |
| `postmetas` | **P** `easyq_postmeta` | Compatible with the WP metadata API (`add_metadata( 'easyq_post', … )`) |
| `categories` | **P** `easyq_categories` | Up to 4 levels, `backpath`, counters, position |
| `categorymetas` | **P** `easyq_categorymeta` | Metadata API |
| `posttags`, `tagwords`, `tagmetas` | **P** `easyq_posttags`, `easyq_tagmeta` | Tags stay a custom structure (no taxonomy) because they are tied to the word index |
| `words`, `titlewords`, `contentwords` | **P** `easyq_words`, `easyq_titlewords`, `easyq_contentwords` | Search index; words are stored as binary UTF-8 with a unique key, so lookups are exact and no table locks are needed |
| `uservotes` | **P** `easyq_uservotes` | Votes and flags |
| `userpoints` | **P** `easyq_userpoints` | Aggregated points per user |
| `userlevels` | **P** `easyq_userlevels` | Per-category levels (section 2) |
| `userfields` | **P** `easyq_userfields` | Profile field configuration |
| `userprofile` | **WP** user meta `easyq_field_{name}` | The Q2A fields "website" and "about" map to the core user fields `user_url` and `description`; the default custom field is "location" |
| `usermetas` | **WP** user meta | |
| `userfavorites` | **P** `easyq_userfavorites` | Questions, users, tags, categories |
| `usernotices` | **P** `easyq_usernotices` | |
| `userevents`, `sharedevents` | **P** `easyq_userevents`, `easyq_sharedevents` | Basis for "My updates" |
| `messages` | **P** `easyq_messages` | Private messages and wall posts |
| `userlimits`, `iplimits` | **P** `easyq_userlimits`, `easyq_iplimits` | Rate limiting; IP data subject to retention (section 11) |
| `cookies` | **P** `easyq_cookies` | Anonymous posting only (section 11) |
| `users`, `userlogins` | **WP** `wp_users`, `wp_usermeta` | |
| `options` | **WP** `wp_options` | Grouped per settings section (`easyq_settings_<section>`) |
| `blobs` | **WP** Media Library | Avatar uploads and attachments |
| `pages` | **–** | Replaced by WP pages and navigation |
| `widgets` | **–** | Replaced by blocks |
| `cache` | **WP** Transients / object cache | |
| Cached counts in `options` (`cache_qcount`, …) | **P** option `easyq_counts` | Invalidated on change and recalculated on the next read instead of incremental updates |
| – | **N** `easyq_email_templates` *(or options)* | Editable email templates (section 8) |

**Uninstall (N):** `uninstall.php` removes tables, options and meta only if this is
explicitly enabled in the settings (default: keep data).

---

## 2. Users, levels, permissions

| Q2A | Target |
| --- | --- |
| Register, log in, log out, forgot/reset password | **WP** `wp-login.php` / core login block; Q2A pages `login`, `register`, `forgot`, `reset`, `logout` are dropped |
| Email confirmation (`confirm`) | **WP/P** WP confirms the address when the password is set; status stored as user meta `easyq_confirmed`. An own confirmation link only where the WP flow does not apply |
| User approval (`moderate_users`) | **P** user meta `easyq_approved`; "Pending approval" view and bulk action in the WP users list |
| Blocking users | **P** user meta `easyq_blocked`; view and bulk action in the WP users list |
| Change username (`allow_change_usernames`) | **–** WP logins are immutable; the public name is `display_name`, profile URLs use `user_nicename` |
| Log in with email (`allow_login_email_only`) | **WP** core feature |

**Levels.** Q2A knows Basic (0), Approved (10), Expert (20), Editor (50), Moderator (80),
Admin (100) and Super Admin (120).

- No new WP roles are created.
- A "WP role → Q&A level" mapping in the settings. Default: Subscriber, Contributor,
  Author → Basic; Editor → Moderator; Administrator → Admin; Super Admin (multisite) →
  Super Admin.
- Optional per user in the WP profile: a global Q&A level (can only raise the level
  given by the role) and per-category levels (`easyq_userlevels`). This covers experts
  and category moderators.
- "Approved" and "confirmed" are states (user meta), not levels. Users without
  these meta values count as confirmed and need no approval, so existing users
  keep working when the plugin is installed or approval is switched on. Only new
  registrations through the WordPress form are marked as unconfirmed or waiting
  for approval. Setting the password through the emailed link confirms the address.
- Approved users have at least the level Approved, as in Q2A.

**Permissions.** All `permit_*` options (section 5.9) are kept: minimum level plus an
optional points threshold. Implemented as meta capabilities (`easyq_post_question`,
`easyq_vote_answer`, `easyq_edit_post`, `easyq_moderate`, …) resolved in `map_meta_cap`,
taking level, category, points, status and blocking into account. The admin
capability `easyq_manage` (default: Administrator) controls the backend.

---

## 3. Frontend routes

All Q&A pages live below a configurable base slug (default `questions`; translatable,
e.g. `fragen`). Routing uses rewrite rules and query vars; output uses block templates
registered with `register_block_template()`, editable in the Site Editor. Without pretty
permalinks everything works through query parameters. Header and footer come from the
theme's template parts.

**Classic themes** cannot load block templates. For them easyQ renders the blocks of the
same template before the document head and prints them between the theme's
`get_header()` and `get_footer()` (`includes/frontend/views/classic.php`). Themes can
replace that file with `easyq/classic.php`, and the filter
`easyq_classic_template_content` changes the block markup; the Site Editor is only
available in block themes.

| Q2A route | Target | Block template |
| --- | --- | --- |
| `/` (home) | **P** | `easyq-home`: question query sorted by recent activity |
| `activity/` | **P** | `easyq-activity` |
| `questions/` (sort: newest, hot, votes, answers, views) | **P** | `easyq-questions` |
| `hot/` | **P** | `easyq-questions` with source "hot" |
| `unanswered/` (no answer, no selected answer, no upvotes) | **P** | `easyq-unanswered` |
| `answers/`, `comments/` | **P** | `easyq-answers`, `easyq-comments` |
| `{id}/{slug}` (question) | **P** | `easyq-single` |
| `ask` | **P** | `easyq-ask` |
| `categories/…` | **P** | `easyq-categories`, `easyq-category` |
| `tags`, `tag/{tag}` | **P** | `easyq-tags`, `easyq-tag` |
| `search` | **P** | `easyq-search` |
| `users`, `users/new`, `users/special` | **P** | `easyq-users` |
| `users/blocked` | **WP** | View in the WP users list |
| `user/{name}` (+ `/questions`, `/answers`, `/activity`, `/wall`) | **P** | `easyq-user` and sub-templates |
| `account` | **P/WP** | `easyq-account` for Q&A data (profile fields, avatar, notifications, privacy choices); email and password via the WP profile |
| `favorites`, `favorites/{questions,users,tags}` | **P** | `easyq-favorites` |
| `updates` | **P** | `easyq-updates` |
| `messages/`, `message/{name}` | **P** | `easyq-messages`, `easyq-message` |
| `feedback` | **P** | `easyq-feedback` |
| `unsubscribe` | **P** | `easyq-unsubscribe` (plus `List-Unsubscribe` / one-click headers) |
| `ip/{ip}` | **P → admin** | Admin screen "IP address" |
| `login`, `register`, `forgot`, `reset`, `logout`, `confirm` | **WP** | Section 2 |
| `admin/*` | **P → admin** | Section 5 |
| `feed/*` | **P** | Section 5.10 |
| Custom pages (`pages`) | **WP** | WP pages |

**SEO (N):** Canonical URLs, titles via `document_title_parts`, structured data as JSON-LD
(`QAPage`) instead of microdata, integration into the core sitemaps (provider `easyq`
with the subtypes `questions`, `tags`, `categories` and, only if `sitemap_users` is
enabled, `users`), `noindex` for empty lists.

**Navigation (N):** The main Q&A pages are variations of the core Navigation Link block
(`get_block_type_variations`), e.g. "Q&A: Ask a question", so menus are built with core
blocks only. The link search of the Navigation block only queries posts, terms and
media and ignores custom REST search handlers, so a search handler would not show up
there. The address of a Q&A link is taken from the route when it is rendered
(`render_block_core/navigation-link`), and the link of the current page is marked like
core marks pages.

---

## 4. Blocks

The block architecture follows core's Query Loop model so that every part can be
arranged and styled in the Site Editor without plugin CSS.

### Building principles

- **Context providers + small field blocks** instead of large monolithic blocks.
  Lists provide items through block context; each visible piece (title, votes,
  author, …) is its own small block.
- **Block Bindings first.** A binding source `easyq/post` (and `easyq/user`) lets core
  blocks show easyQ data: Heading/Paragraph for title and text, Button for links, Image
  for avatars. Dedicated blocks exist only where core blocks cannot do the job
  (interaction, structured output, forms).
- **Core markup.** Dynamic blocks render the same markup and class names as their core
  counterparts (pagination, search, buttons, lists), so the theme's styles apply.
- **Block supports** for color, typography, spacing, border and layout on every block.
- **Block settings** in the sidebar for what belongs to the block. Settings that have a Q2A
  option (answer order, best answer first, answers per page, collapsing comments, relative
  dates, separated votes) default to the site setting and can be overridden per block.
  Questions, users and categories are picked by name.
- **Icons** from `@wordpress/icons`, the same as the core counterpart where there is one,
  and inserter previews (`example`) for the blocks that work without a question or user.
- **Interactivity API** for voting, favorites, loading more comments, inline answering –
  always with a working no-JS fallback (regular form posts). Answer and comment forms
  are sent in the background to the same form handler (header `X-EasyQ-In-Place`, which
  returns the address to show as JSON instead of redirecting); the Interactivity Router
  then replaces the question and the notices (router regions), so new posts, field
  errors and messages appear without reloading. The focus moves to the first invalid
  field or the new post, and messages are announced with `@wordpress/a11y`. Long
  discussions show the latest `show_fewer_cs_count` of more than `show_fewer_cs_from`
  comments, with a link to the earlier ones (`?easyq_comments={post}`).

### Containers (context providers)

| Block | Q2A source | Key attributes |
| --- | --- | --- |
| `easyq/question-query` | All question lists | Source (recent, hot, votes, answers, views, unanswered variants, activity), category, tag, author, favorites, per page, inherit from route |
| `easyq/question-template` | List item | – (repeats inner blocks per item) |
| `easyq/query-pagination` (+ previous, numbers, next) | Paging | Same markup as core pagination; page numbers, arrows (none, ←→, «»), labels |
| `easyq/query-no-results` | Empty lists | Inner blocks |
| `easyq/question` | Question page | Provides the current question from the route |
| `easyq/answer-list` / `easyq/answer-template` | Answers | Sort order, selected first, page size |
| `easyq/comment-list` / `easyq/comment-template` | Comments | Show fewer from / count |
| `easyq/user-query` / `easyq/user-template` | User lists | Sort (points, newest, special), columns, per page |
| `easyq/user` | Profile page | Provides the current user from the route |

### Field blocks (consume context)

| Block | Content |
| --- | --- |
| `easyq/post-title` | Question title (link optional) |
| `easyq/post-content` | Formatted content |
| `easyq/post-excerpt` | Shortened content (list items) |
| `easyq/post-votes` | Vote score and buttons; difference or up and down votes separately (`votes_separated`) |
| `easyq/post-answer-count` | Answer count, "selected" state |
| `easyq/post-view-count` | Views |
| `easyq/post-date` | Created/updated, relative or absolute |
| `easyq/post-author` | Avatar, name, points, user title |
| `easyq/post-category` | Category, with prefix and suffix |
| `easyq/post-tags` | Tags, with separator, prefix and suffix |
| `easyq/post-status` | Closed, selected, hidden, queued |
| `easyq/post-actions` | Answer, comment, edit, close, select, flag, hide, delete … depending on permissions |
| `easyq/user-name`, `easyq/user-avatar`, `easyq/user-points`, `easyq/user-title`, `easyq/user-fields`, `easyq/user-stats` | Profile pieces |

### Forms and standalone blocks

| Block | Q2A source |
| --- | --- |
| `easyq/ask-form` | `ask` (similar-question check, tag suggestions, category picker, extra field) |
| `easyq/answer-form` | Question page |
| `easyq/comment-form` | Question page |
| `easyq/ask-box` | Widget "Ask box" |
| `easyq/search` | Search form (core search markup) |
| `easyq/categories` | Widget "Category list", `categories` |
| `easyq/tags` | `tags` |
| `easyq/related-questions` | Widget "Related questions" |
| `easyq/activity-count` | Widget "Activity count" |
| `easyq/post-activity` | What happened last to a question in activity lists ("answered 5 minutes ago by …") |
| `easyq/user-wall` | Wall |
| `easyq/account` | `account` |
| `easyq/updates` | `updates` |
| `easyq/messages` | `messages`, `message` |
| `easyq/feedback-form` | `feedback` |
| `easyq/notices` | Visitor, welcome and user notices |
| `easyq/sort-navigation` | Sort tabs of the question lists (rendered as a core-style list of links) |
| `easyq/breadcrumbs` | Category path |

**Patterns** for typical layouts, built from the blocks above and core blocks, in the
pattern category "Questions and answers". Every Q2A layout can be rebuilt with these
building blocks. Templates and patterns share their markup (`EasyQ\Blocks\Layouts`).

| Pattern | Use |
| --- | --- |
| `easyq/home` | Start page: title, ask box, sort links, question list; offered for the template `easyq-home` and in the inserter |
| `easyq/question-page` | Question page; offered for the template `easyq-question` only, hidden from the inserter because it needs the route |
| `easyq/profile` | User profile; offered for the template `easyq-user` only, hidden from the inserter |
| `easyq/recent-questions` | Heading and the five newest questions, one per line, for sidebars and front pages |
| `easyq/top-users` | Heading and the five users with the most points, for sidebars |

**Form styling.** `theme.json` has no element for form fields yet. easyQ renders fields
with the same markup as core forms (comment form, search) so they pick up the theme's
form styles. This is the area to check most carefully against several block themes
(section 12).

---

## 5. Admin area

All screens use core admin markup only (`wrap`, `form-table`, `WP_List_Table`,
`nav-tab-wrapper`, admin notices, `@wordpress/components` where React is needed) –
no plugin CSS.

### 5.1 Menu structure

Top-level menu **easyQ** (capability `easyq_manage`; moderation from `easyq_moderate`):

| Submenu | Q2A source | Implementation |
| --- | --- | --- |
| Dashboard | `admin/stats` | Figures, queues, quick links; plus a WP dashboard widget |
| Posts **(N)** | `admin/moderate`, `admin/flagged`, `admin/hidden` | One `WP_List_Table` for questions, answers and comments, modelled on the core Comments screen: views All, Pending, Flagged, Hidden and Closed, filters by type, category, author and date, search, row and bulk actions (approve, reject, hide, restore, delete, close/reopen, edit), counter badge in the menu and the admin bar |
| Categories | `admin/categories` | List and form, hierarchy, ordering, moving posts on delete |
| Points | `admin/points` | Settings API form |
| User titles | `admin/usertitles` | List and form |
| User fields | `admin/userfields` | List and form, ordering |
| Email templates **(N)** | language files `emails` | List of all templates, editor with placeholders, preview, test mail, reset to default (section 8) |
| Settings | `admin/general` … `admin/mailing` | Settings API with tabs (5.2–5.12) |
| Newsletter | `admin/mailing` | Own screen with progress; batches via WP-Cron |
| Tools | `admin/recalc` | Section 5.13 |
| IP address (hidden, linked) | `ip/{ip}` | Posts from an IP, block/unblock |

Integration into core screens:

- **Users → Profile:** "easyQ" section with level (global and per category), points,
  states (confirmed, approved, blocked), profile fields, avatar.
- **Users → List:** columns for points and level, views "Pending approval" and
  "Blocked", bulk actions.
- **Site Health (N):** checks for table version, cron, permalinks, search index.
- **WP-CLI (N):** `wp easyq` for recalculation, statistics, maintenance.

Dropped Q2A screens: `admin/approve` (→ users list), `admin/pages` (→ WP pages and
navigation), `admin/layoutwidgets` (→ blocks), `admin/plugins` (→ WP plugins).

### 5.2 Settings → General (`admin/general`)

| Option | Target |
| --- | --- |
| `site_title` | **WP** site title / page titles |
| `site_url` | **WP** `home_url()` |
| `neat_urls` | **WP** permalinks |
| `site_language` | **WP** site / user locale |
| `site_theme`, `site_theme_mobile` | **WP** block theme |
| `site_text_direction` | **WP** `is_rtl()` |
| `tags_or_categories` | **P** |
| `site_maintenance` | **P** as read-only mode for the Q&A area |
| Base slug | **N** |
| Role-to-level mapping | **N** (section 2) |
| Delete data on uninstall | **N** |

### 5.3 Settings → Email (`admin/emails`)

| Option | Target |
| --- | --- |
| `from_email` | **P** optional, otherwise WP default |
| `feedback_email` | **P** |
| `notify_admin_q_post` | **P** |
| `feedback_enabled` | **P** |
| `email_privacy` | **P** |
| `smtp_active`, `smtp_address`, `smtp_port`, `smtp_secure`, `smtp_authenticate`, `smtp_username`, `smtp_password` | **WP** `wp_mail()`; SMTP via dedicated plugins |

### 5.4 Settings → Users (`admin/users`)

| Option | Target |
| --- | --- |
| `show_notice_visitor`, `notice_visitor` | **P** |
| `show_notice_welcome`, `notice_welcome` | **P** |
| `show_custom_welcome`, `custom_welcome` | **P** |
| `show_custom_register`, `custom_register` | **P** via `register_form` on the WP registration form |
| `show_register_terms`, `register_terms` | **P** required checkbox on the WP registration form; links the WP privacy policy page |
| `allow_login_email_only` | **WP** |
| `allow_change_usernames` | **–** (section 2) |
| `register_notify_admin` | **P** via `wp_send_new_user_notification_to_admin` |
| `suspend_register_users` | **WP** "Anyone can register" |
| `block_bad_usernames` | **P** via `registration_errors` |
| `allow_private_messages`, `show_message_history`, `page_size_pms` | **P** |
| `allow_user_walls`, `page_size_wall` | **P** |
| `avatar_allow_gravatar`, `avatar_default_show` | **WP** Settings → Discussion (avatars) |
| `avatar_allow_upload`, `avatar_store_size` | **P** local avatar upload (Media Library), hooked into `pre_get_avatar_data` |
| `avatar_profile_size`, `avatar_users_size`, `avatar_q_page_q_size`, `avatar_q_page_a_size`, `avatar_q_page_c_size`, `avatar_q_list_size`, `avatar_message_list_size` | **P** as block defaults |

### 5.5 Settings → Layout (`admin/layout`)

All options are dropped; the block theme and the Site Editor handle layout.

| Option | Target |
| --- | --- |
| `logo_show`, `logo_url`, `logo_width`, `logo_height` | **WP** site logo |
| `show_custom_sidebar`, `custom_sidebar`, `show_custom_sidepanel`, `custom_sidepanel` | **WP** blocks in templates |
| `show_custom_header`, `custom_header`, `show_custom_footer`, `custom_footer` | **WP** template parts |
| `show_custom_in_head`, `custom_in_head` | **–** (theme/plugins; unfiltered HTML output is not allowed on wordpress.org) |
| `show_custom_home`, `custom_home_heading`, `custom_home_content` | **WP** home template content |
| `show_home_description`, `home_description` | **WP** meta description via SEO plugins; a filter is provided |

### 5.6 Settings → Viewing (`admin/viewing`)

| Option | Target |
| --- | --- |
| `q_urls_title_length` | **P** |
| `q_urls_remove_accents` | **WP** `sanitize_title()` always removes accents |
| `do_count_q_views`, `show_view_counts`, `show_view_count_q_page`, `recalc_hotness_q_view` | **P** (view counting without storing IPs, section 11) |
| `voting_on_qs`, `voting_on_q_page_only`, `voting_on_as`, `voting_on_cs`, `votes_separated` | **P** |
| `show_url_links`, `links_in_new_window` | **P** |
| `show_when_created`, `show_full_date_days` | **P** (date format from WP) |
| `show_user_titles`, `show_user_points` | **P** |
| `show_post_update_meta`, `show_compact_numbers` | **P** |
| `use_microdata` | **P** as JSON-LD `QAPage` |
| `minify_html` | **–** (job of caching/optimization plugins) |
| `sort_answers_by`, `show_selected_first`, `page_size_q_as`, `show_a_form_immediate` | **P** as block defaults |
| `show_fewer_cs_from`, `show_fewer_cs_count`, `show_c_reply_buttons` | **P** |
| `match_related_qs`, `page_size_related_qs` | **P** as defaults of `easyq/related-questions` |
| `pages_prev_next` | **P** |
| `sitemap_users` (new, off by default) | **N** user profiles in the sitemaps (section 3) |

### 5.7 Settings → Lists (`admin/lists`)

| Option | Target |
| --- | --- |
| `page_size_home`, `page_size_activity`, `page_size_qs`, `page_size_hot_qs`, `page_size_una_qs`, `page_size_tag_qs`, `page_size_search` | **P** as block defaults |
| `page_size_tags`, `columns_tags`, `page_size_users`, `columns_users` | **P** as block defaults |
| `search_module` | **P** as a search provider interface; the choice appears once a provider is registered |
| `hot_weight_q_age`, `hot_weight_a_age`, `hot_weight_answers`, `hot_weight_votes`, `hot_weight_views` | **P** |

### 5.8 Settings → Posting (`admin/posting`)

| Option | Target |
| --- | --- |
| `do_close_on_select`, `allow_close_questions`, `allow_close_own_questions` | **P** |
| `allow_self_answer`, `allow_multi_answers`, `follow_on_as` | **P** |
| `comment_on_qs`, `comment_on_as` | **P** |
| `allow_anonymous_naming` | **P** (section 11) |
| `editor_for_qs`, `editor_for_as`, `editor_for_cs` | **P** shown once more than one editor is registered; core ships the plain editor (textarea) |
| `show_custom_ask`, `custom_ask`, `show_custom_answer`, `custom_answer`, `show_custom_comment`, `custom_comment` | **P** (HTML through `wp_kses_post`) |
| `extra_field_active`, `extra_field_prompt`, `extra_field_display`, `extra_field_label` | **P** |
| `min_len_q_title`, `max_len_q_title`, `min_len_q_content`, `min_len_a_content`, `min_len_c_content` | **P** |
| `min_num_q_tags`, `max_num_q_tags`, `tag_separator_comma` | **P** |
| `notify_users_default` | **P** (default off, section 11) |
| `block_bad_words` | **P** |
| `do_ask_check_qs`, `match_ask_check_qs`, `page_size_ask_check_qs` | **P** |
| `do_example_tags`, `match_example_tags`, `do_complete_tags`, `page_size_ask_tags` | **P** |

### 5.9 Settings → Permissions (`admin/permissions`)

All **P**, each with a minimum level and, where applicable, minimum points (`*_points`):
`permit_view_q_page` (+ `allow_view_q_bots`), `permit_post_q`, `permit_post_a`,
`permit_post_c`, `permit_vote_q`, `permit_vote_a`, `permit_vote_c`, `permit_vote_down`,
`permit_retag_cat`, `permit_edit_q`, `permit_edit_a`, `permit_edit_c`,
`permit_edit_silent`, `permit_close_q`, `permit_select_a`, `permit_anon_view_ips`,
`permit_view_voters_flaggers`, `permit_flag`, `permit_moderate`, `permit_hide_show`,
`permit_delete_hidden`, `permit_view_new_users_page`, `permit_view_special_users_page`,
`permit_post_wall`.

### 5.10 Settings → Feeds (`admin/feeds`)

All **P**, implemented with `add_feed()` and RSS 2.0 output:
`feed_for_questions`, `feed_for_qa`, `feed_for_activity`, `feed_for_hot`,
`feed_for_unanswered`, `feed_for_tag_qs`, `feed_per_category`, `feed_for_search`,
`feed_number_items`, `feed_full_text`. Defaults as in Q2A.

Addresses: `/{base}/feed/{type}/{argument}` with the types `questions`, `hot`,
`unanswered`, `answers`, `comments`, `activity`, `qa`, `tag` and `search`; the argument
is a category path, a tag or search terms. Without pretty permalinks:
`?feed=easyq&easyq_route=feed&easyq_arg={type}&easyq_section={argument}`. Q&A list pages
link to their feed (`<link rel="alternate">`). Feeds show only what visitors may see,
contain no author names, and are not available when visitors may not view the Q&A area.
WordPress's conditional GET (based on its own posts) is turned off for these feeds.

### 5.11 Settings → Spam (`admin/spam`)

| Option | Target |
| --- | --- |
| `confirm_user_emails`, `confirm_user_required` | **P** (section 2) |
| `moderate_users` | **P** |
| `captcha_on_register`, `captcha_on_reset_password`, `captcha_on_anon_post`, `captcha_on_unapproved`, `captcha_on_unconfirmed`, `captcha_on_feedback`, `captcha_module` | **P** through a captcha interface; options appear once a provider is registered (providers: **X**) |
| `moderate_anon_post`, `moderate_unapproved`, `moderate_unconfirmed`, `moderate_by_points`, `moderate_points_limit`, `moderate_edited_again`, `moderate_notify_admin`, `moderate_update_time` | **P** |
| `flagging_of_posts`, `flagging_notify_first`, `flagging_notify_every`, `flagging_hide_after` | **P** |
| `block_ips_write` | **P** |
| `max_rate_ip_registers`, `max_rate_ip_logins` | **P** via WP hooks (`registration_errors`, `authenticate`) |
| `max_rate_ip_qs`, `max_rate_user_qs`, `max_rate_ip_as`, `max_rate_user_as`, `max_rate_ip_cs`, `max_rate_user_cs` | **P** |
| `max_rate_ip_votes`, `max_rate_user_votes`, `max_rate_ip_flags`, `max_rate_user_flags`, `max_rate_ip_uploads`, `max_rate_user_uploads`, `max_rate_ip_messages`, `max_rate_user_messages` | **P** |
| Akismet | **N** optional check, available only when the Akismet plugin is active and configured; off by default |
| IP retention and anonymization | **N** (section 11) |

### 5.12 Settings → Caching (`admin/caching`), Newsletter (`admin/mailing`)

| Option | Target |
| --- | --- |
| `caching_enabled`, `caching_driver`, `caching_q_start`, `caching_q_time`, `caching_catwidget_time` | **WP** object cache / transients, internal, no settings |
| `mailing_enabled`, `mailing_from_name`, `mailing_from_email`, `mailing_subject`, `mailing_body`, `mailing_per_minute` | **P** "Newsletter" screen; recipients are only users who opted in (section 11) |

### 5.13 Points, user titles, user fields, tools

| Area | Target |
| --- | --- |
| Points: `points_base`, `points_post_q`, `points_post_a`, `points_select_a`, `points_a_selected`, `points_vote_up_q`, `points_vote_down_q`, `points_vote_up_a`, `points_vote_down_a`, `points_per_q_voted_up/down`, `points_per_a_voted_up/down`, `points_per_c_voted_up/down`, `points_q_voted_max_gain/loss`, `points_a_voted_max_gain/loss`, `points_c_voted_max_gain/loss`, `points_multiple` | **P**; saving triggers a background recalculation |
| User titles (points → title) | **P** |
| User fields (name, type, visibility, on registration) | **P**; fields appear in the WP profile, the account block and optionally on the registration form |
| Tools: rebuild search index, recount posts, recalculate points, refill events, recalculate categories, delete hidden posts | **P** batch processing (REST + progress bar), plus `wp easyq recalc …` |
| Tools: move blobs to disk/database, clear cache | **–** (Media Library / object cache) |
| Version check (`ajax/version`) | **WP** plugin updates |

---

## 6. AJAX → REST API

All endpoints under `easyq/v1`, nonce via `wp_rest`, authorization via
`permission_callback`, validation via argument schemas.

| Q2A AJAX | REST |
| --- | --- |
| `vote` | `POST /posts/{id}/vote` |
| `favorite` | `POST` / `DELETE /favorites` |
| `answer` | `POST /questions/{id}/answers` |
| `comment` | `POST /posts/{id}/comments` |
| `click_a`, `click_c` (select, hide, restore, delete, flag, approve, …) | `POST /posts/{id}/actions/{action}` |
| `show_cs` | `GET /posts/{id}/comments` |
| `asktitle` | `GET /questions/similar` |
| `category` | `GET /categories?parent=` |
| `notice` | `DELETE /notices/{id}` |
| `wallpost`, `click_wall` | `POST` / `DELETE /users/{id}/wall` |
| `click_pm` | `DELETE /messages/{id}` |
| `click_admin` | Row actions of the admin posts list |
| `recalc`, `mailing` | `POST /admin/recalc`, `POST /admin/newsletter` |
| `version` | **–** |

Additional read endpoints for questions, answers, categories, tags and users serve the
block editor previews and future extensions.

---

## 7. Events → WordPress hooks

Every Q2A event fires the action `easyq_{event}` and the generic action
`easyq_event`, with the event parameters and the `EasyQ\Actor` who caused it.
Event names are kept from Q2A. The full list with parameters is maintained in
[hooks.md](hooks.md).

| Group | Events |
| --- | --- |
| Questions | `q_post`, `q_queue`, `q_edit`, `q_requeue`, `q_approve`, `q_reject`, `q_hide`, `q_reshow`, `q_close`, `q_reopen`, `q_move`, `q_claim`, `q_delete_before`, `q_delete` |
| Answers | `a_post`, `a_queue`, `a_edit`, `a_requeue`, `a_approve`, `a_reject`, `a_hide`, `a_reshow`, `a_select`, `a_unselect`, `a_to_c`, `a_claim`, `a_delete_before`, `a_delete` |
| Comments | `c_post`, `c_queue`, `c_edit`, `c_requeue`, `c_approve`, `c_reject`, `c_hide`, `c_reshow`, `c_claim`, `c_delete_before`, `c_delete` |
| Votes and flags | `{q,a,c}_vote_up`, `{q,a,c}_vote_down`, `{q,a,c}_vote_nil`, `{q,a,c}_flag`, `{q,a,c}_unflag`, `{q,a,c}_clearflags` |
| Other | `search`, `feedback`, `ip_block`, `ip_unblock` |
| Users | `u_register`, `u_confirmed`, `u_login`, `u_logout`, `u_password`, `u_reset`, `u_save`, `u_edit`, `u_level`, `u_block`, `u_unblock`, `u_delete_before`, `u_delete`, `u_message`, `u_wall_post`, `u_wall_delete`, `u_favorite`, `u_unfavorite` |

The three internal Q2A event modules become internal listeners: `event-limits`
(rate limits and last write activity, phase 2), `event-updates` and
`event-notify` (phase 7). Events WordPress already fires (login, registration,
password) are mapped onto the core hooks.

---

## 8. Email

Q2A ships 20 templates; 18 are kept and sent through `wp_mail()`:
`a_commented`, `a_followed`, `a_selected`, `c_commented`, `confirm`, `feedback`,
`flagged`, `moderate`, `remoderate`, `private_message`, `q_answered`, `q_commented`,
`q_posted`, `u_registered`, `u_to_approve`, `u_approved`, `wall_post`, `welcome`.
`new_password` and `reset` are dropped in favour of the core emails.

**Editable templates (N):**

- Admin screen "Email templates" (section 5.1): subject and body per template,
  list of available placeholders, preview with sample data, test mail to the current
  user, reset to default.
- Defaults are translatable strings; once edited, the stored text wins. On
  multilingual sites the edited text is passed through the WPML/Polylang string
  translation hooks where available.
- Placeholders are replaced with escaped values; bodies are plain text (as in Q2A),
  optional HTML through a filter.
- Filter `easyq_email_{name}` for programmatic changes.
- Every notification email contains an unsubscribe/manage link (section 11).

---

## 9. Extensibility (Q2A plugin system)

| Q2A mechanism | Target |
| --- | --- |
| Plugin folder `qa-plugin/`, `metadata.json` | **WP** standalone WP plugins built on top of easyQ |
| Overrides (`qa_to_override`) | **WP** filters at the relevant places |
| Layers (theme extensions) | **WP** block render filters, custom blocks, block hooks |
| Theme (`qa-theme`) | **WP** block theme |
| Module `page` | **P** registration API for additional Q&A routes |
| Module `widget` | **WP** blocks |
| Modules `editor`, `viewer` | **P** editor registry (interface) |
| Module `captcha` | **P** captcha registry (interface) |
| Module `search` | **P** search provider interface |
| Module `filter` | **P** validation filters for posts and profiles |
| Module `event` | **WP** actions (section 7) |
| Module `login` | **WP** login plugins |
| Module `process` | **WP** actions |
| Language phrases (`qa_register_plugin_phrases`) | **WP** gettext |

All public hooks and interfaces are documented in `docs/hooks.md`.

---

## 10. Bundled Q2A plugins (selection at the end, **X**)

| Plugin | Proposal |
| --- | --- |
| reCAPTCHA | Port (captcha interface); opt-in with privacy notice. Consider privacy-friendlier providers too |
| WYSIWYG editor (CKEditor) | Port as an editor based on core assets |
| Tag cloud widget | Port as a block |
| Event logger | Port (log screen in the admin) |
| Mouseover layer | Port as a block option of the question list |
| OpenSearch | Port |
| XML sitemap | Covered by the core sitemaps (section 3) |
| Facebook login | Drop (login plugins) |
| Basic AdSense | Drop |
| Example page | Drop (replaced by developer documentation) |

---

## 11. Privacy (GDPR)

| Topic | Implementation |
| --- | --- |
| External requests | None by default. Third-party services (captcha providers, Akismet) only after the site owner enables them; each adds a notice to the privacy policy guide |
| Privacy policy guide | Suggested text via `wp_add_privacy_policy_content()`: data stored, purposes, retention, cookies |
| Data export / erasure | Exporters and erasers for the core privacy tools: posts, votes, flags, favorites, messages, wall posts, profile fields, IP data, consent records. Erasure anonymizes the author of posts by default (like core comments); full deletion is configurable |
| IP addresses | Stored only for abuse prevention (post creation/edit, rate limits, IP blocks). Configurable retention (default 30 days), then removed by WP-Cron. Optional storage in anonymized form (last octet / last 80 bits removed) |
| View counting | No IP storage; duplicate views are filtered through a short-lived salted hash in a transient |
| Anonymous posting | Disabled by default. When enabled: a functional cookie is set only at the moment of posting, the form shows a privacy notice with link to the privacy policy, name is optional, an email address is asked for only for opt-in notifications |
| Notifications | `notify_users_default` defaults to off; every mail links to the notification settings; one-click unsubscribe |
| Newsletter | Explicit opt-in (checkbox in account/registration, default off), consent timestamp stored, double opt-in for the first subscription, `List-Unsubscribe` and one-click unsubscribe headers |
| Registration | Optional terms/privacy checkbox; consent stored with timestamp |
| Public profile | Visibility per profile field; users can hide themselves from user lists if the site owner allows it |
| Avatars | Gravatar governed by the core setting; local upload as an alternative |
| Cookies | Documented in the privacy guide; easyQ sets no cookies for visitors who only read |
| Fonts, scripts, assets | All bundled locally; no CDNs |

---

## 12. Quality assurance

| Level | Tool | Purpose |
| --- | --- | --- |
| Static analysis | PHPStan (with WordPress extension), PHPCS with WPCS, ESLint and Stylelint (`@wordpress/scripts`) | Type errors, coding standards |
| Unit tests | PHPUnit | Domain logic without WP: hotness, points, permissions, search index, validation |
| Integration tests | PHPUnit with the WP test suite (`wp-env`) | Database, hooks, REST, capabilities, privacy tools, multisite |
| End-to-end | Playwright (`@wordpress/e2e-test-utils-playwright`) | Ask, answer, vote, moderate, settings, blocks in the editor |
| Accessibility | axe in Playwright | |
| Theme compatibility | Playwright screenshots with Twenty Twenty-Five, Twenty Twenty-Four, Twenty Twenty-Three and at least two popular third-party block themes | Verifies the no-CSS approach, especially forms |
| Plugin review | Plugin Check (official) | wordpress.org requirements |
| CI | GitHub Actions on every push and pull request | PHP 8.4 and 8.5 × latest WordPress, PHP 8.4 × WordPress 6.7; single site and multisite; Plugin Check on the release archive |

**CSS exceptions:** none so far. Any exception is listed here with reason and approval.

---

## 13. Phases

| Phase | Scope | Acceptance criteria | Status |
| --- | --- | --- | --- |
| 1 Foundation | Bootstrap, autoloader, activation/uninstall, schema, options, capabilities, tooling, CI | Activates without errors on single site and multisite; tables created; Plugin Check, PHPCS and PHPStan clean | Done |
| 2 Core domain | Posts (Q/A/C), states, categories, tags, votes, flags, points, hotness, search index, limits, events | Unit and integration tests cover the ported logic; results match Q2A | Done |
| 3 Users and permissions | Levels, role mapping, `map_meta_cap`, profile integration, approval/blocking, avatars, profile fields | Permission matrix fully tested | Done |
| 4 REST API | Endpoints from section 6 | Integration test per endpoint including permission and validation errors | Done (favorites, notices, wall and messages follow in phase 7) |
| 5 Frontend | Routing, block templates, all blocks, bindings, Interactivity API, feeds, SEO | All Q2A pages rebuilt; E2E tests for the main flows; axe clean; theme compatibility checks pass | Done: all Q2A pages of this phase, feeds, sitemaps, navigation links, block patterns, in-place answering and comments, `noindex` for empty lists, classic theme fallback; E2E tests with axe in Twenty Twenty-Five, -Four, -Three, -Two (block themes) and Twenty Twenty-One, Twenty Twenty (classic). Favorites, updates, messages, wall, feedback and unsubscribe follow with their features in phase 7 |
| 6 Admin | Section 5 complete | Every Q2A option mapped and tested; E2E tests for admin flows | Open (profile and users screens done in phase 3) |
| 7 Communication | Emails and templates, notifications, private messages, wall, favorites, updates, newsletter | Mails captured and asserted in tests | Open |
| 8 Privacy and tooling | Section 11, WP-CLI, Site Health | Export/erasure tested; retention cron tested | Open |
| 9 Translation and release | `.pot`, `de_DE`, `de_DE_formal`, `readme.txt`, screenshots, developer docs | Plugin Check without findings; reproducible release ZIP | Open |
| 10 Extensions | Selection from section 10 | | Open |

---

## 14. Decisions log

| # | Decision | Date |
| --- | --- | --- |
| 1 | Minimum WordPress version 6.7 (`register_block_template()`) | 2026-10-13 |
| 2 | Ship both German language packs: `de_DE` (informal) and `de_DE_formal` (formal) | 2026-10-13 |
| 3 | One admin "Posts" list (modelled on the core Comments screen) replaces the separate Q2A moderation pages | 2026-10-13 |
| 4 | Privacy by design and by default, see section 11 | 2026-10-13 |
| 5 | Email templates editable in the backend | 2026-10-13 |
| 6 | All repository documentation and code comments in English | 2026-10-13 |
| 7 | No plugin CSS; styling comes from the block theme | 2026-10-13 |
| 8 | Full compliance with WordPress core guidelines | 2026-10-13 |
| 9 | PHP namespace `EasyQ\` (capitalized, as recommended by the PHP coding standards handbook) | 2026-10-13 |
| 10 | Site-wide counts are invalidated and recalculated on read instead of being updated incrementally | 2026-10-13 |
| 11 | The post type `NOTE` is kept for close reasons | 2026-10-13 |
| 12 | Category changes update posts and counts right away instead of needing a separate recalculation | 2026-10-13 |
| 13 | Approval and confirmation apply to new registrations only; existing users are treated as approved and confirmed | 2026-10-13 |
| 14 | Posting questions, answers and comments needs an account by default (privacy) | 2026-10-13 |
| 15 | WordPress administrators always keep `easyq_manage`, whatever the role mapping says | 2026-10-13 |
| 16 | Favorites, notices, wall and message endpoints come with their features in phase 7 | 2026-10-13 |
| 17 | Frontend forms work without JavaScript and are sent to the REST API internally (same checks), followed by a redirect with a message | 2026-10-13 |
| 18 | Blocks reuse core class names and core block style handles (buttons, search, pagination, post template, post terms) and the core flex layout support, so the theme styles them | 2026-10-13 |
| 19 | Views are counted once per visitor and hour using a salted hash in a transient; no IP address is stored | 2026-10-13 |
| 20 | The users list "by points" shows users who took part (as in Q2A); on multisite, users of other sites get a profile only once they took part here | 2026-10-26 |
| 21 | Uploaded avatars are cropped to a square, stored in the Media Library and replaced or deleted with the user's choice; the avatar is stored per site | 2026-10-26 |
| 22 | The account page is excluded from search engines; profile sections have their own canonical address | 2026-10-26 |
| 23 | Q&A pages are added to menus as variations of the core Navigation Link block instead of a REST search handler, which the link search does not query | 2026-10-26 |
| 24 | User profiles are left out of the sitemaps unless the site owner enables `sitemap_users` (privacy by default; Q2A lists them by default) | 2026-10-26 |
| 25 | Feeds contain no author names and are not available when visitors may not view the Q&A area | 2026-10-26 |
| 26 | The rewrite rules are rebuilt whenever they change (hash of the rules), not only when the base slug changes, so updates that add routes work without saving the permalinks | 2026-10-26 |
| 27 | Classic themes get the same block templates, rendered between the theme's header and footer, instead of separate PHP templates | 2026-10-26 |
| 28 | Answer and comment forms are sent in the background to the regular form handler and the page is updated with the Interactivity Router, so both ways share one implementation | 2026-10-26 |
| 29 | Counts in question lists keep their width through core flex child sizing, and text areas use `cols="45"` like the core comment form, so themes without form styles stay usable without plugin CSS | 2026-10-26 |
| 30 | Block settings with a Q2A counterpart default to the site setting (empty or 0) instead of copying its value, so changing the site setting later reaches every block that was not changed | 2026-10-27 |
| 31 | Minimum PHP version 8.4: PHP 8.1 has no security fixes since December 2025, PHP 8.2 loses them in December 2026 | 2026-10-27 |
| 32 | CI runs on GitHub Actions only, now that the repository is public: runs are free, pull requests from contributors are checked, and Plugin Check uses the official action on the release archive | 2026-10-27 |
