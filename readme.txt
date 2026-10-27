=== easyQ ===
Tags: questions, answers, q&a, community, forum
Requires at least: 6.7
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete question and answer platform built from blocks, based on Question2Answer.

== Description ==

easyQ adds a question and answer community to your WordPress site. Visitors ask questions, the community answers, votes and selects the best answers.

easyQ is a port of the open source platform [Question2Answer](https://www.question2answer.org/) to WordPress:

* Every part of the Q&A area is a block that you arrange and style in the Site Editor.
* All styling comes from your block theme. easyQ ships no stylesheet of its own.
* Users, roles, emails and media are handled by WordPress.
* Privacy by default: no external requests, configurable IP retention, full support for the WordPress privacy tools.

easyQ is under active development. This version contains the foundation only.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/easyq/` or install it through the Plugins screen.
2. Activate the plugin through the Plugins screen. On multisite, activate it for a single site or the whole network.

== Frequently Asked Questions ==

= Does easyQ delete my data when I delete the plugin? =

No. Data is only removed if you enable "Delete data on uninstall" in the settings before deleting the plugin.

== Changelog ==

= 0.1.0 =
* Foundation: database schema, installation and upgrades on single sites and multisite networks, capabilities, uninstall routine.
