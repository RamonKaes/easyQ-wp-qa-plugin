=== easyQ ===
Tags: questions, answers, q&a, community, forum
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A question and answer community built from blocks, with the features of Question2Answer.

== Description ==

easyQ adds a question and answer community to your WordPress site. Visitors ask questions, the community answers, comments and votes, and the asker picks the best answer.

easyQ brings the proven feature set of the open source platform [Question2Answer](https://www.question2answer.org/) to WordPress, rebuilt the WordPress way: every Q&A page is a block template, every part of it a block, and your theme decides how it looks.

**easyQ is in early development and not ready for live sites yet.** Asking, answering, voting, moderation, profiles and all Q&A pages work. A settings screen, emails and notifications, the privacy export and erasure tools and translations follow in the next versions.

= Questions and answers =

* Questions with title, text, category and tags; answers and comments on both
* Up and down votes, best answer selection, related questions
* Question lists: recent, hot, unanswered, most votes, most answers, most views and recent activity
* Nested categories, a tag cloud and a full-text search with relevance ranking
* Editing, closing (as a duplicate or with a reason), hiding and restoring posts

= Community and moderation =

* Points for questions, answers, votes and best answers, with user ranks and titles
* User levels mapped to WordPress roles and capabilities
* Flags, an approval queue, user approval and blocking, rate limits, censored words
* Posting without an account, if you allow it
* User profiles with custom fields, uploaded avatars and activity lists

= Made for WordPress =

* Over 40 blocks and block patterns, arranged and styled in the Site Editor
* No stylesheet of its own: the Q&A area takes on the look of your block theme; classic themes are supported too
* Voting, answering and commenting without page reloads, built on the Interactivity API
* RSS feeds, XML sitemaps, search engine metadata and Q&A links for the Navigation block
* Works with WordPress users, roles, avatars and media, and on multisite
* Accessible markup
* A REST API and documented hooks for developers

= Privacy =

easyQ makes no requests to external services and does not track visitors. Posting without an account stores a cookie, so the author can edit the post later; the forms point to your privacy policy.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/easyq/` or install it through the Plugins screen.
2. Activate the plugin through the Plugins screen. On multisite, activate it for a single site or the whole network.
3. Visit `/questions/` on your site.
4. Add the Q&A pages to your menu with the Navigation block and adjust their templates under Appearance → Editor → Templates.

== Frequently Asked Questions ==

= Does easyQ work with my theme? =

easyQ is made for block themes, such as Twenty Twenty-Five. Classic themes show the Q&A pages between their header and footer.

= Where do I change the settings? =

The settings screen comes with the next version. Until then easyQ uses the defaults of Question2Answer.

= Does easyQ delete my data when I delete the plugin? =

No. Your questions, answers and users stay in the database when you delete the plugin.

== Changelog ==

= 0.1.0 =
* Questions, answers, comments, votes, best answers, categories, tags and search.
* Points, user levels, moderation, flags, rate limits and user profiles.
* Block templates, over 40 blocks, block patterns, feeds, sitemaps and Navigation block links.
* Support for single sites and multisite networks.
