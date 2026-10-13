# easyQ – Feature-Mapping Question2Answer 1.8.8 → WordPress

Dieses Dokument ordnet jeder Funktion, Seite, Option, Tabelle und Schnittstelle von
Question2Answer (Q2A) 1.8.8 ein Ziel in WordPress zu. Es ist die verbindliche Grundlage
für die Umsetzung; Abweichungen werden hier nachgetragen.

**Legende**

| Kürzel | Bedeutung |
|---|---|
| **Ü** | übernommen (portiert), Ziel angegeben |
| **WP** | durch eine WordPress-Bordfunktion ersetzt |
| **–** | entfällt ersatzlos (Begründung angegeben) |
| **N** | neu – in Q2A nicht vorhanden, aber für WP-Konformität sinnvoll |
| **P** | später, Teil der Plugin-Auswahl am Projektende |

---

## 0. Rahmen und Konventionen

| Thema | Festlegung |
|---|---|
| Name / Slug / Textdomain | easyQ / `easyq` / `easyq` |
| PHP-Namespace | `easyQ\` (PSR-4, `src/`) |
| Präfix Funktionen, Optionen, Hooks, Meta | `easyq_` |
| Tabellen | `{$wpdb->prefix}easyq_*` (Multisite: pro Site) |
| Blöcke | `easyq/<name>` |
| REST-Namespace | `easyq/v1` |
| Capabilities | `easyq_*` |
| Mindestversionen | PHP 8.1, WordPress 6.6 (siehe offene Punkte) |
| Sprachen | Englisch (Quellsprache), Deutsch |
| Standards | WordPress Coding Standards, wordpress.org Plugin Guidelines, GPLv2+ |
| Basis | Portierung der Q2A-Logik (`qa-include/app`, `qa-include/db`) in Klassen; kein Q2A-Code wird unverändert geladen |

**Grundsatz Konfiguration:** Globale Einstellungen liefern Standardwerte. Jeder Block kann
seine Darstellung (Seitengröße, Spalten, Avatargröße, Ein-/Ausblenden von Metadaten …)
pro Instanz überschreiben. Dadurch lassen sich die Komponenten frei kombinieren.

---

## 1. Datenmodell

Q2A hat 31 Tabellen. Schema wird per `dbDelta()` angelegt, Versionierung über Option
`easyq_db_version`, Upgrades schrittweise.

| Q2A-Tabelle | Ziel | Anmerkung |
|---|---|---|
| `posts` | **Ü** `easyq_posts` | Fragen, Antworten, Kommentare inkl. Zustände (`_HIDDEN`, `_QUEUED`); `NOTE` entfällt (ungenutzt). `userid` → WP-User-ID (`BIGINT UNSIGNED`) |
| `postmetas` | **Ü** `easyq_postmeta` | Struktur kompatibel zu WP-Meta-API (`add_metadata('easyq_post', …)`) |
| `categories` | **Ü** `easyq_categories` | Hierarchie bis 4 Ebenen, `backpath`, Zähler, Position |
| `categorymetas` | **Ü** `easyq_categorymeta` | Meta-API |
| `posttags`, `tagwords`, `tagmetas` | **Ü** `easyq_posttags`, `easyq_tagmeta` | Tags bleiben eigene Struktur (keine WP-Taxonomie, da Tags an Wortindex hängen) |
| `words`, `titlewords`, `contentwords` | **Ü** `easyq_words`, `easyq_titlewords`, `easyq_contentwords` | Suchindex |
| `uservotes` | **Ü** `easyq_uservotes` | Stimmen und Meldungen (Flags) |
| `userpoints` | **Ü** `easyq_userpoints` | Punkte-Aggregat pro User |
| `userlevels` | **Ü** `easyq_userlevels` | Stufen pro Kategorie (s. Abschnitt 2) |
| `userfields` | **Ü** `easyq_userfields` | Konfiguration der Profilfelder |
| `userprofile` | **WP** User-Meta `easyq_field_{name}` | |
| `usermetas` | **WP** User-Meta | |
| `userfavorites` | **Ü** `easyq_userfavorites` | Fragen, Benutzer, Tags, Kategorien |
| `usernotices` | **Ü** `easyq_usernotices` | |
| `userevents`, `sharedevents` | **Ü** `easyq_userevents`, `easyq_sharedevents` | Basis für „Meine Updates“ |
| `messages` | **Ü** `easyq_messages` | Private Nachrichten und Pinnwand |
| `userlimits`, `iplimits` | **Ü** `easyq_userlimits`, `easyq_iplimits` | Ratenbegrenzung |
| `cookies` | **Ü** `easyq_cookies` | Nur für anonymes Posten; Datenschutz-Hinweis über WP-Privacy-API |
| `users`, `userlogins` | **WP** `wp_users`, `wp_usermeta` | |
| `options` | **WP** `wp_options` | gruppiert je Einstellungsbereich (`easyq_settings_<tab>`) |
| `blobs` | **WP** Mediathek | Avatar-Uploads und Dateianhänge als Attachments |
| `pages` | **–** | ersetzt durch WP-Seiten und Navigationsmenüs |
| `widgets` | **–** | ersetzt durch Blöcke |
| `cache` | **WP** Transients / Object Cache | |

**Datenschutz (N):** Exporter und Eraser für die WP-Privacy-Tools (Beiträge, Stimmen,
Nachrichten, Favoriten, IP-Adressen); Hinweistext für die Datenschutzerklärung über
`wp_add_privacy_policy_content()`.

**Deinstallation (N):** `uninstall.php` entfernt Tabellen, Optionen, Meta – nur wenn in
den Einstellungen ausdrücklich aktiviert (Standard: Daten behalten).

---

## 2. Benutzer, Stufen, Berechtigungen

| Q2A | Ziel |
|---|---|
| Registrierung, Login, Logout, Passwort vergessen/zurücksetzen | **WP** `wp-login.php` bzw. Login-Block; Q2A-Seiten `login`, `register`, `forgot`, `reset`, `logout` entfallen |
| E-Mail-Bestätigung (`confirm`) | **WP/Ü** WP bestätigt die Adresse beim Setzen des Passworts; Status als User-Meta `easyq_confirmed`. Eigener Bestätigungslink nur für Fälle ohne WP-Flow |
| Benutzerfreigabe (`moderate_users`) | **Ü** User-Meta `easyq_approved`, Ansicht „Freigabe ausstehend“ und Sammelaktion in der WP-Benutzerliste |
| Benutzer sperren | **Ü** User-Meta `easyq_blocked`, Sammelaktion in der Benutzerliste |
| Benutzername ändern (`allow_change_usernames`) | **–** WP erlaubt keine Änderung des Logins; öffentlich angezeigt wird `display_name`, Profil-URL über `user_nicename` |
| Login mit E-Mail (`allow_login_email_only`) | **WP** Kernfunktion |

**Stufen.** Q2A kennt Basis (0), Freigeschaltet (10), Experte (20), Editor (50),
Moderator (80), Admin (100), Super-Admin (120).

- Neue WP-Rollen werden **nicht** angelegt.
- Zuordnung „WP-Rolle → Q&A-Stufe“ in den Einstellungen. Standard: Abonnent → Basis,
  Mitarbeiter/Autor → Basis, Redakteur → Moderator, Administrator → Admin,
  Super-Admin (Multisite) → Super-Admin.
- Optional pro Benutzer im WP-Profil: globale Q&A-Stufe (überschreibt die Rolle nach
  oben) sowie Stufen pro Kategorie (`easyq_userlevels`). Damit sind Experten und
  Kategorie-Moderatoren abbildbar.
- „Freigeschaltet“ und „bestätigt“ sind Status (User-Meta), keine Stufen.

**Berechtigungen.** Alle `permit_*`-Optionen (Abschnitt 5.9) bleiben erhalten: Mindeststufe
plus optional Mindestpunkte. Umsetzung über Meta-Capabilities (`easyq_post_question`,
`easyq_vote_answer`, `easyq_edit_post`, `easyq_moderate` …), aufgelöst in
`map_meta_cap` unter Berücksichtigung von Stufe, Kategorie, Punkten, Status und Sperre.
Zusätzlich Admin-Capability `easyq_manage` für das Backend (Standard: Administrator).

---

## 3. Frontend-Seiten

Alle Q&A-Seiten liegen unter einem konfigurierbaren Basis-Slug (Standard `questions`,
deutsche Installationen `fragen`). Routing über Rewrite-Regeln und Query-Vars, Ausgabe
über Block-Templates des Plugins, die im Site-Editor angepasst werden können. Ohne
Permalinks funktioniert alles über Query-Parameter.

| Q2A-Route | Ziel | Block-Template / Blöcke |
|---|---|---|
| `/` (Startseite) | **Ü** | `easyq-home`: Fragenliste (Sortierung „neueste Aktivität“) |
| `activity/` | **Ü** | Aktivitätenliste |
| `questions/` (Sortierungen neu, heiß, Stimmen, Antworten, Aufrufe) | **Ü** | Fragenliste mit Sortier-Navigation |
| `hot/` | **Ü** | Fragenliste „heiß“ |
| `unanswered/` (ohne Antwort, ohne ausgewählte, ohne Stimmen) | **Ü** | Fragenliste „unbeantwortet“ |
| `answers/`, `comments/` | **Ü** | Listen neuester Antworten/Kommentare |
| `{id}/{slug}` (Frage) | **Ü** | `easyq-single`: Frage, Antworten, Kommentare, Antwortformular |
| `ask` | **Ü** | Frageformular (inkl. Ähnliche-Fragen-Prüfung, Tag-Vorschläge, Kategorieauswahl) |
| `categories/…` | **Ü** | Kategorienliste, Kategoriearchiv |
| `tags`, `tag/{tag}` | **Ü** | Tag-Übersicht, Tag-Archiv |
| `search` | **Ü** | Suchergebnisse |
| `users`, `users/new`, `users/special` | **Ü** | Benutzerliste (Punkte, neueste, mit Sonderrechten) |
| `users/blocked` | **WP** | Ansicht in der WP-Benutzerliste |
| `user/{name}` (+ `/questions`, `/answers`, `/activity`, `/wall`) | **Ü** | öffentliches Profil mit Unterseiten |
| `account` | **Ü/WP** | Konto-Block für Q&A-Daten (Profilfelder, Avatar, Benachrichtigungen, Pinnwand/PN-Freigabe); E-Mail und Passwort über WP-Profil |
| `favorites`, `favorites/{questions,users,tags}` | **Ü** | Favoriten |
| `updates` | **Ü** | Meine Updates |
| `messages/`, `message/{name}` | **Ü** | Postfach, Unterhaltung |
| `feedback` | **Ü** | Feedback-Formular-Block |
| `unsubscribe` | **Ü** | Abmeldung vom Rundschreiben (+ `List-Unsubscribe`-Header) |
| `ip/{ip}` | **Ü→Admin** | Admin-Seite „IP-Adresse“ (Beiträge der IP, sperren/entsperren) |
| `login`, `register`, `forgot`, `reset`, `logout`, `confirm` | **WP** | s. Abschnitt 2 |
| `admin/*` | **Ü→Admin** | s. Abschnitt 5 |
| `feed/*` | **Ü** | s. Abschnitt 5.10 |
| Benutzerdefinierte Seiten (`pages`) | **WP** | WP-Seiten |

**SEO (N):** Kanonische URLs, `<title>` über `document_title_parts`, strukturierte Daten
als JSON-LD (`QAPage`) statt Microdata, Einbindung in die WP-Core-Sitemaps
(`wp_sitemaps_add_provider`), `noindex` für Listen ohne Inhalt.

---

## 4. Blöcke

Jede Komponente ist ein eigener, konfigurierbarer Block (dynamisch, serverseitig
gerendert, `block.json`, API v3). Interaktivität über die Interactivity API
(Abstimmen, Favoriten, Antworten nachladen) mit Fallback ohne JavaScript.

| Block | Quelle in Q2A | Wichtige Attribute |
|---|---|---|
| `easyq/question-list` | Listenseiten | Quelle (neu/heiß/Stimmen/Antworten/Aufrufe/unbeantwortet/Aktivität), Kategorie, Tag, Anzahl, Paginierung, Metadaten ein/aus, Avatargröße |
| `easyq/question` | Fragenseite | Bereiche ein/aus (Stimmen, Tags, Kategorie, Kommentare) |
| `easyq/answers` | Fragenseite | Sortierung, ausgewählte zuerst, Seitengröße |
| `easyq/answer-form` | Fragenseite | sofort anzeigen ja/nein |
| `easyq/ask-form` | `ask` | Kategorie vorbelegen, Hinweistext |
| `easyq/ask-box` | Widget „Ask box“ | Platzhalter, Zielseite |
| `easyq/search` | Suchfeld | |
| `easyq/search-results` | `search` | Seitengröße |
| `easyq/categories` | Widget „Category list“, `categories` | Tiefe, Zähler, Darstellung (Liste/Kacheln) |
| `easyq/tags` | `tags` | Anzahl, Spalten, Sortierung |
| `easyq/related-questions` | Widget „Related questions“ | Anzahl, Übereinstimmungsgrad |
| `easyq/activity-count` | Widget „Activity count“ | angezeigte Zähler |
| `easyq/users` | `users` | Sortierung, Spalten, Anzahl |
| `easyq/user-profile` | `user` | Bereiche ein/aus |
| `easyq/user-wall` | `user/wall` | Seitengröße |
| `easyq/account` | `account` | |
| `easyq/favorites` | `favorites` | Typen |
| `easyq/updates` | `updates` | |
| `easyq/messages` | `messages`, `message` | |
| `easyq/feedback-form` | `feedback` | |
| `easyq/navigation` | Q2A-Hauptmenü und Unternavigation | Einträge ein/aus; zusätzlich Links im Navigations-Block |
| `easyq/notices` | Besucher-/Willkommens-/Benutzerhinweise | |
| `easyq/breadcrumbs` | Kategoriepfad | |

Ziel ist, dass sich jedes Q2A-Layout aus diesen Blöcken nachbauen lässt. Ergänzend
gibt es Block-Patterns für typische Seiten (Startseite, Fragenseite, Profil).

---

## 5. Admin-Bereich

### 5.1 Menüstruktur

Hauptmenü **easyQ** (Capability `easyq_manage`, Moderation ab `easyq_moderate`):

| Untermenü | Q2A-Quelle | Umsetzung |
|---|---|---|
| Übersicht | `admin/stats` | Kennzahlen, Warteschlangen, Schnellzugriffe; zusätzlich WP-Dashboard-Widget |
| Fragen **(N)** | – | `WP_List_Table` aller Fragen/Antworten/Kommentare mit Filter, Suche, Bearbeiten, Verstecken, Löschen – analog zur WP-Kommentarverwaltung |
| Moderation | `admin/moderate`, `admin/flagged`, `admin/hidden` | eine `WP_List_Table` mit Ansichten „Ausstehend | Gemeldet | Versteckt“, Sammelaktionen, Zähler-Badge am Menü und in der Admin-Leiste |
| Kategorien | `admin/categories` | Liste + Formular, Hierarchie, Sortierung, Beiträge verschieben beim Löschen |
| Punkte | `admin/points` | Formular (Settings API) |
| Benutzertitel | `admin/usertitles` | Liste + Formular |
| Benutzerfelder | `admin/userfields` | Liste + Formular, Sortierung |
| Einstellungen | `admin/general` … `admin/mailing` | Settings API mit Tabs (5.2–5.12) |
| Rundschreiben | `admin/mailing` | eigener Bildschirm mit Fortschritt, Versand in Batches über WP-Cron |
| Wartung | `admin/recalc` | s. 5.13 |
| IP-Adresse (versteckt, verlinkt) | `ip/{ip}` | Beiträge einer IP, IP sperren/entsperren |

Weitere Integrationen in WP-Bildschirme:

- **Benutzer → Profil:** Abschnitt „easyQ“ mit Stufe global/pro Kategorie, Punkten,
  Status (bestätigt, freigegeben, gesperrt), Profilfeldern, Avatar.
- **Benutzer → Liste:** Spalten Punkte und Stufe, Ansichten „Freigabe ausstehend“ und
  „Gesperrt“, Sammelaktionen.
- **Website-Zustand (N):** Prüfungen (Tabellenversion, Cron, Permalinks, Suchindex).
- **WP-CLI (N):** `wp easyq` für Wartung, Neuberechnung, Statistik.

Die Q2A-Seiten `admin/approve` (→ Benutzerliste), `admin/pages` (→ WP-Seiten/Menüs),
`admin/layoutwidgets` (→ Blöcke) und `admin/plugins` (→ WP-Plugins) entfallen.

### 5.2 Einstellungen → Allgemein (`admin/general`)

| Option | Ziel |
|---|---|
| `site_title` | **WP** Seitentitel / Titel der Q&A-Seite |
| `site_url` | **WP** `home_url()` |
| `neat_urls` | **WP** Permalinks |
| `site_language` | **WP** Sprache der Website/des Benutzers |
| `site_theme`, `site_theme_mobile` | **WP** Block-Theme |
| `site_text_direction` | **WP** `is_rtl()` |
| `tags_or_categories` | **Ü** |
| `site_maintenance` | **Ü** als „Nur-Lesen-Modus“ für den Q&A-Bereich |
| Basis-Slug **(N)** | **N** |
| Rollen-Zuordnung **(N)** | **N** (Abschnitt 2) |
| Daten bei Deinstallation löschen **(N)** | **N** |

### 5.3 Einstellungen → E-Mails (`admin/emails`)

| Option | Ziel |
|---|---|
| `from_email` | **Ü** optional, sonst WP-Standard |
| `feedback_email` | **Ü** |
| `notify_admin_q_post` | **Ü** |
| `feedback_enabled` | **Ü** |
| `email_privacy` | **Ü** |
| `smtp_active`, `smtp_address`, `smtp_port`, `smtp_secure`, `smtp_authenticate`, `smtp_username`, `smtp_password` | **WP** `wp_mail()`; SMTP über SMTP-Plugins |

### 5.4 Einstellungen → Benutzer (`admin/users`)

| Option | Ziel |
|---|---|
| `show_notice_visitor`, `notice_visitor` | **Ü** |
| `show_notice_welcome`, `notice_welcome` | **Ü** |
| `show_custom_welcome`, `custom_welcome` | **Ü** |
| `show_custom_register`, `custom_register` | **Ü** über `register_form` im WP-Registrierungsformular |
| `show_register_terms`, `register_terms` | **Ü** Pflicht-Checkbox im WP-Registrierungsformular |
| `allow_login_email_only` | **WP** |
| `allow_change_usernames` | **–** (Abschnitt 2) |
| `register_notify_admin` | **Ü** über `wp_send_new_user_notification_to_admin` |
| `suspend_register_users` | **WP** Option „Jeder kann sich registrieren“ |
| `block_bad_usernames` | **Ü** über `registration_errors` |
| `allow_private_messages`, `show_message_history`, `page_size_pms` | **Ü** |
| `allow_user_walls`, `page_size_wall` | **Ü** |
| `avatar_allow_gravatar`, `avatar_default_show` | **WP** Einstellungen → Diskussion (Avatare) |
| `avatar_allow_upload`, `avatar_store_size` | **Ü** eigener Avatar-Upload (Mediathek), eingebunden über `pre_get_avatar_data` |
| `avatar_profile_size`, `avatar_users_size`, `avatar_q_page_q_size`, `avatar_q_page_a_size`, `avatar_q_page_c_size`, `avatar_q_list_size`, `avatar_message_list_size` | **Ü** als Standardwerte, pro Block überschreibbar |

### 5.5 Einstellungen → Layout (`admin/layout`)

Alle Optionen entfallen; das Block-Theme und der Site-Editor übernehmen das Layout.

| Option | Ziel |
|---|---|
| `logo_show`, `logo_url`, `logo_width`, `logo_height` | **WP** Website-Logo |
| `show_custom_sidebar`, `custom_sidebar`, `show_custom_sidepanel`, `custom_sidepanel` | **WP** Blöcke im Template |
| `show_custom_header`, `custom_header`, `show_custom_footer`, `custom_footer` | **WP** Template-Teile |
| `show_custom_in_head`, `custom_in_head` | **–** (Theme/Plugins; ungefiltertes HTML ist für wordpress.org unzulässig) |
| `show_custom_home`, `custom_home_heading`, `custom_home_content` | **WP** Inhalte der Startseite im Editor |
| `show_home_description`, `home_description` | **WP** Meta-Beschreibung über SEO-Plugins; Filter wird angeboten |

### 5.6 Einstellungen → Darstellung (`admin/viewing`)

| Option | Ziel |
|---|---|
| `q_urls_title_length` | **Ü** |
| `q_urls_remove_accents` | **WP** `sanitize_title()` entfernt Akzente immer |
| `do_count_q_views`, `show_view_counts`, `show_view_count_q_page`, `recalc_hotness_q_view` | **Ü** |
| `voting_on_qs`, `voting_on_q_page_only`, `voting_on_as`, `voting_on_cs`, `votes_separated` | **Ü** |
| `show_url_links`, `links_in_new_window` | **Ü** |
| `show_when_created`, `show_full_date_days` | **Ü** (Datumsformat aus WP) |
| `show_user_titles`, `show_user_points` | **Ü** |
| `show_post_update_meta`, `show_compact_numbers` | **Ü** |
| `use_microdata` | **Ü** als JSON-LD `QAPage` |
| `minify_html` | **–** (Aufgabe von Caching-/Optimierungs-Plugins) |
| `sort_answers_by`, `show_selected_first`, `page_size_q_as`, `show_a_form_immediate` | **Ü** als Standardwerte der Blöcke |
| `show_fewer_cs_from`, `show_fewer_cs_count`, `show_c_reply_buttons` | **Ü** |
| `match_related_qs`, `page_size_related_qs` | **Ü** als Standardwerte des Blocks `related-questions` |
| `pages_prev_next` | **Ü** |

### 5.7 Einstellungen → Listen (`admin/lists`)

| Option | Ziel |
|---|---|
| `page_size_home`, `page_size_activity`, `page_size_qs`, `page_size_hot_qs`, `page_size_una_qs`, `page_size_tag_qs`, `page_size_search` | **Ü** als Standardwerte der Blöcke |
| `page_size_tags`, `columns_tags`, `page_size_users`, `columns_users` | **Ü** als Standardwerte der Blöcke |
| `search_module` | **Ü** als Filter für alternative Suchanbieter; Auswahl erscheint nur, wenn ein Anbieter registriert ist |
| `hot_weight_q_age`, `hot_weight_a_age`, `hot_weight_answers`, `hot_weight_votes`, `hot_weight_views` | **Ü** |

### 5.8 Einstellungen → Beiträge (`admin/posting`)

| Option | Ziel |
|---|---|
| `do_close_on_select`, `allow_close_questions`, `allow_close_own_questions` | **Ü** |
| `allow_self_answer`, `allow_multi_answers`, `follow_on_as` | **Ü** |
| `comment_on_qs`, `comment_on_as` | **Ü** |
| `allow_anonymous_naming` | **Ü** |
| `editor_for_qs`, `editor_for_as`, `editor_for_cs` | **Ü** Auswahl erscheint, sobald mehr als ein Editor registriert ist; Kern liefert den einfachen Editor (Textarea) |
| `show_custom_ask`, `custom_ask`, `show_custom_answer`, `custom_answer`, `show_custom_comment`, `custom_comment` | **Ü** (HTML über `wp_kses_post`) |
| `extra_field_active`, `extra_field_prompt`, `extra_field_display`, `extra_field_label` | **Ü** |
| `min_len_q_title`, `max_len_q_title`, `min_len_q_content`, `min_len_a_content`, `min_len_c_content` | **Ü** |
| `min_num_q_tags`, `max_num_q_tags`, `tag_separator_comma` | **Ü** |
| `notify_users_default` | **Ü** |
| `block_bad_words` | **Ü** |
| `do_ask_check_qs`, `match_ask_check_qs`, `page_size_ask_check_qs` | **Ü** |
| `do_example_tags`, `match_example_tags`, `do_complete_tags`, `page_size_ask_tags` | **Ü** |

### 5.9 Einstellungen → Berechtigungen (`admin/permissions`)

Alle **Ü**, jeweils Mindeststufe und bei Bedarf Mindestpunkte (`*_points`):
`permit_view_q_page` (+ `allow_view_q_bots`), `permit_post_q`, `permit_post_a`,
`permit_post_c`, `permit_vote_q`, `permit_vote_a`, `permit_vote_c`, `permit_vote_down`,
`permit_retag_cat`, `permit_edit_q`, `permit_edit_a`, `permit_edit_c`,
`permit_edit_silent`, `permit_close_q`, `permit_select_a`, `permit_anon_view_ips`,
`permit_view_voters_flaggers`, `permit_flag`, `permit_moderate`, `permit_hide_show`,
`permit_delete_hidden`, `permit_view_new_users_page`, `permit_view_special_users_page`,
`permit_post_wall`.

### 5.10 Einstellungen → Feeds (`admin/feeds`)

Alle **Ü**, umgesetzt über `add_feed()` mit RSS-2.0-Ausgabe:
`feed_for_questions`, `feed_for_qa`, `feed_for_activity`, `feed_for_hot`,
`feed_for_unanswered`, `feed_for_tag_qs`, `feed_per_category`, `feed_for_search`,
`feed_number_items`, `feed_full_text`.

### 5.11 Einstellungen → Spam (`admin/spam`)

| Option | Ziel |
|---|---|
| `confirm_user_emails`, `confirm_user_required` | **Ü** (Abschnitt 2) |
| `moderate_users` | **Ü** |
| `captcha_on_register`, `captcha_on_reset_password`, `captcha_on_anon_post`, `captcha_on_unapproved`, `captcha_on_unconfirmed`, `captcha_on_feedback`, `captcha_module` | **Ü** über eine Captcha-Schnittstelle; Optionen erscheinen, sobald ein Anbieter registriert ist (Anbieter: **P**) |
| `moderate_anon_post`, `moderate_unapproved`, `moderate_unconfirmed`, `moderate_by_points`, `moderate_points_limit`, `moderate_edited_again`, `moderate_notify_admin`, `moderate_update_time` | **Ü** |
| `flagging_of_posts`, `flagging_notify_first`, `flagging_notify_every`, `flagging_hide_after` | **Ü** |
| `block_ips_write` | **Ü** |
| `max_rate_ip_registers`, `max_rate_ip_logins` | **Ü** über WP-Hooks (`registration_errors`, `authenticate`) |
| `max_rate_ip_qs`, `max_rate_user_qs`, `max_rate_ip_as`, `max_rate_user_as`, `max_rate_ip_cs`, `max_rate_user_cs` | **Ü** |
| `max_rate_ip_votes`, `max_rate_user_votes`, `max_rate_ip_flags`, `max_rate_user_flags`, `max_rate_ip_uploads`, `max_rate_user_uploads`, `max_rate_ip_messages`, `max_rate_user_messages` | **Ü** |
| Akismet **(N)** | **N** optionale Prüfung über Akismet, nur wenn das Akismet-Plugin aktiv und eingerichtet ist |

### 5.12 Einstellungen → Caching (`admin/caching`), Rundschreiben (`admin/mailing`)

| Option | Ziel |
|---|---|
| `caching_enabled`, `caching_driver`, `caching_q_start`, `caching_q_time`, `caching_catwidget_time` | **WP** Object Cache / Transients, intern ohne Einstellungen |
| `mailing_enabled`, `mailing_from_name`, `mailing_from_email`, `mailing_subject`, `mailing_body`, `mailing_per_minute` | **Ü** Bildschirm „Rundschreiben“ |

### 5.13 Punkte, Benutzertitel, Benutzerfelder, Wartung

| Bereich | Ziel |
|---|---|
| Punkte: `points_base`, `points_post_q`, `points_post_a`, `points_select_a`, `points_a_selected`, `points_vote_up_q`, `points_vote_down_q`, `points_vote_up_a`, `points_vote_down_a`, `points_per_q_voted_up/down`, `points_per_a_voted_up/down`, `points_per_c_voted_up/down`, `points_q_voted_max_gain/loss`, `points_a_voted_max_gain/loss`, `points_c_voted_max_gain/loss`, `points_multiple` | **Ü**; Speichern stößt die Neuberechnung im Hintergrund an |
| Benutzertitel (Punkte → Titel) | **Ü** |
| Benutzerfelder (Name, Typ, Sichtbarkeit, bei Registrierung) | **Ü**; Felder erscheinen im WP-Profil, im Konto-Block und optional im Registrierungsformular |
| Wartung: Suchindex neu aufbauen, Beiträge neu zählen, Punkte neu berechnen, Ereignisse neu füllen, Kategorien neu berechnen, versteckte Beiträge löschen | **Ü** Batch-Verarbeitung (REST + Fortschrittsanzeige), zusätzlich `wp easyq recalc …` |
| Wartung: Blobs in Dateien/Datenbank verschieben, Cache leeren | **–** (Mediathek bzw. Object Cache) |
| Versionsprüfung (`ajax/version`) | **WP** Plugin-Updates |

---

## 6. AJAX → REST-API

Alle Endpunkte unter `easyq/v1`, Nonce über `wp_rest`, Rechteprüfung über
`permission_callback`, Validierung über Argument-Schemas.

| Q2A-AJAX | REST |
|---|---|
| `vote` | `POST /posts/{id}/vote` |
| `favorite` | `POST/DELETE /favorites` |
| `answer` | `POST /questions/{id}/answers` |
| `comment` | `POST /posts/{id}/comments` |
| `click_a`, `click_c` (auswählen, verstecken, wiederherstellen, löschen, melden, freigeben …) | `POST /posts/{id}/actions/{action}` |
| `show_cs` | `GET /posts/{id}/comments` |
| `asktitle` | `GET /questions/similar` |
| `category` | `GET /categories?parent=` |
| `notice` | `DELETE /notices/{id}` |
| `wallpost`, `click_wall` | `POST/DELETE /users/{id}/wall` |
| `click_pm` | `DELETE /messages/{id}` |
| `click_admin` | Admin-Aktionen in der Moderationsliste |
| `recalc`, `mailing` | `POST /admin/recalc`, `POST /admin/mailing` |
| `version` | **–** |

Zusätzlich lesende Endpunkte für Fragen, Antworten, Kategorien, Tags und Benutzer
(Grundlage für die Blöcke im Editor und spätere Erweiterungen).

---

## 7. Ereignisse → WordPress-Hooks

Jedes Q2A-Ereignis wird zu einer Action `easyq_{event}` mit typisierten Parametern:

`q_post`, `a_post`, `c_post`, `q_edit`, `a_edit`, `c_edit`, `a_select`, `a_unselect`,
`q_close`, `q_reopen`, `q_move`, `a_to_c`, `q_claim`, `a_claim`, `c_claim`,
`q_requeue`, `a_requeue`, `c_requeue`, `q_delete`, `a_delete`, `c_delete`
(jeweils mit `*_before`), `search`, `feedback`, `ip_block`, `ip_unblock`,
`u_register`, `u_confirmed`, `u_login`, `u_logout`, `u_password`, `u_reset`, `u_save`,
`u_edit`, `u_level`, `u_delete` (+ `_before`), `u_message`, `u_wall_post`,
`u_wall_delete`.

Die drei Q2A-internen Event-Module (`event-limits`, `event-notify`, `event-updates`)
werden zu internen Listenern auf diese Actions. Ereignisse, die WP bereits auslöst
(Login, Registrierung, Passwort), werden auf die WP-Hooks abgebildet.

---

## 8. E-Mails

Alle 20 Q2A-Vorlagen bleiben erhalten, Versand über `wp_mail()`, Texte übersetzbar:
`a_commented`, `a_followed`, `a_selected`, `c_commented`, `confirm`, `feedback`,
`flagged`, `moderate`, `remoderate`, `new_password`, `reset`, `private_message`,
`q_answered`, `q_commented`, `q_posted`, `u_registered`, `u_to_approve`, `u_approved`,
`wall_post`, `welcome`. `new_password` und `reset` entfallen zugunsten der WP-Mails.

Jede Mail ist über Filter (`easyq_email_{name}`) anpassbar.

---

## 9. Erweiterbarkeit (Q2A-Plugin-System)

| Q2A-Mechanismus | Ziel |
|---|---|
| Plugin-Verzeichnis `qa-plugin/`, `metadata.json` | **WP** eigenständige WP-Plugins, die auf easyQ aufbauen |
| Overrides (`qa_to_override`) | **WP** Filter an den relevanten Stellen |
| Layer (Theme-Erweiterungen) | **WP** Filter auf Block-Ausgabe, eigene Blöcke |
| Theme (`qa-theme`) | **WP** Block-Theme |
| Modul `page` | **Ü** Registrierungs-API für zusätzliche Q&A-Routen |
| Modul `widget` | **WP** Blöcke |
| Modul `editor`, `viewer` | **Ü** Editor-Registry (Interface) |
| Modul `captcha` | **Ü** Captcha-Registry (Interface) |
| Modul `search` | **Ü** Suchanbieter-Interface |
| Modul `filter` | **Ü** Filter bei Validierung von Beiträgen/Profilen |
| Modul `event` | **WP** Actions (Abschnitt 7) |
| Modul `login` | **WP** Login-Plugins |
| Modul `process` | **WP** Actions |
| Sprachphrasen (`qa_register_plugin_phrases`) | **WP** gettext |

Alle öffentlichen Hooks und Interfaces werden in `docs/hooks.md` dokumentiert.

---

## 10. Mitgelieferte Q2A-Plugins (Auswahl am Projektende, **P**)

| Plugin | Vorschlag |
|---|---|
| reCAPTCHA | übernehmen (Captcha-Interface) |
| WYSIWYG-Editor (CKEditor) | übernehmen als Editor auf Basis des in WP enthaltenen TinyMCE |
| Tag Cloud Widget | übernehmen als Block |
| Event Logger | übernehmen (Protokoll im Admin) |
| Mouseover Layer | übernehmen als Block-Option der Fragenliste |
| OpenSearch | übernehmen |
| XML Sitemap | im Kern über die WP-Core-Sitemaps erledigt (Abschnitt 3) |
| Facebook Login | streichen (Login-Plugins) |
| Basic AdSense | streichen |
| Example Page | streichen (wird durch Entwicklerdokumentation ersetzt) |

---

## 11. Querschnittsthemen

| Thema | Umsetzung |
|---|---|
| HTML-Filterung | `wp_kses` mit eigener Erlaubnisliste statt htmLawed |
| Mailer | `wp_mail()` statt PHPMailer-Kopie |
| Sicherheit | Nonces, Capability-Prüfung, Escaping bei der Ausgabe, `$wpdb->prepare()` |
| IP-Adressen | wie in Q2A binär gespeichert (`VARBINARY(16)`) |
| Zeit | UTC in der Datenbank, Ausgabe über `wp_date()` |
| Assets | `@wordpress/scripts`, nur auf Seiten laden, die sie brauchen |
| Barrierefreiheit | WCAG 2.2 AA für Frontend und Admin |
| Multisite | Tabellen pro Site, Aktivierung netzwerkweit und pro Site, `wp_initialize_site` / `wp_uninitialize_site` |
| Übersetzung | Textdomain `easyq`, `.pot`, `de_DE` (PHP und JS) |
| Q2A-Fremdbibliotheken (htmLawed, PHPMailer, CKEditor, Facebook-SDK) | entfallen |

---

## 12. Qualitätssicherung

| Ebene | Werkzeug | Zweck |
|---|---|---|
| Statische Analyse | PHPStan (mit WordPress-Erweiterung), PHPCS mit WPCS, ESLint, Stylelint | Typfehler, Standards |
| Unit-Tests | PHPUnit | Fachlogik ohne WP: Hotness, Punkte, Rechte, Suchindex, Validierung |
| Integrationstests | PHPUnit mit WP-Testsuite (`wp-env`) | Datenbank, Hooks, REST, Capabilities, Multisite |
| End-to-End | Playwright (`@wordpress/e2e-test-utils-playwright`) | Fragen stellen, antworten, abstimmen, moderieren, Admin-Einstellungen, Blöcke im Editor |
| Barrierefreiheit | axe in Playwright | |
| Plugin-Prüfung | Plugin Check (offizielles WP-Tool) | Anforderungen von wordpress.org |
| CI | GitHub Actions | PHP 8.1–8.4 × WP 6.6 / aktuell, Single- und Multisite |

---

## 13. Phasenplan

| Phase | Inhalt | Abnahmekriterien |
|---|---|---|
| 1 Grundgerüst | Bootstrap, Autoloader, Aktivierung/Deinstallation, Schema, Optionen, Capabilities, Tooling, CI | Plugin aktiviert sich auf Single- und Multisite fehlerfrei; Tabellen werden angelegt; Plugin Check, PHPCS und PHPStan ohne Fehler |
| 2 Kern | Beiträge (Q/A/C), Zustände, Kategorien, Tags, Stimmen, Meldungen, Punkte, Hotness, Suchindex, Limits, Ereignisse | Unit- und Integrationstests decken die portierte Logik ab; Ergebnisse entsprechen Q2A |
| 3 Rechte und Benutzer | Stufen, Rollen-Zuordnung, `map_meta_cap`, Profilintegration, Freigabe/Sperre, Avatare, Profilfelder | Rechte-Matrix vollständig getestet |
| 4 REST-API | Endpunkte aus Abschnitt 6 | Integrationstests je Endpunkt inkl. Rechte- und Validierungsfehler |
| 5 Frontend | Routing, Block-Templates, alle Blöcke, Interactivity API, Feeds, SEO | Alle Q2A-Seiten nachgebaut; E2E-Tests der Hauptabläufe; axe ohne Verstöße |
| 6 Admin | Abschnitt 5 vollständig | Jede Q2A-Option zugeordnet und getestet; E2E-Tests der Admin-Abläufe |
| 7 Kommunikation | E-Mails, Benachrichtigungen, PN, Pinnwand, Favoriten, Updates, Rundschreiben | Mails in Tests abgefangen und geprüft |
| 8 Datenschutz und Werkzeuge | Privacy-Exporter/Eraser, WP-CLI, Website-Zustand | |
| 9 Übersetzung und Release | `.pot`, `de_DE`, `readme.txt`, Screenshots, Entwicklerdoku | Plugin Check ohne Befunde; Release-ZIP baut reproduzierbar |
| 10 Erweiterungen | Auswahl aus Abschnitt 10 | |

---

## 14. Offene Punkte

1. **Mindestversion WordPress.** Block-Templates lassen sich ab WP 6.7 über
   `register_block_template()` sauber registrieren. Für 6.6 wäre ein Workaround über
   Template-Filter nötig. Vorschlag: Mindestversion 6.7.
2. **Anrede in der deutschen Übersetzung.** WordPress führt zwei deutsche Sprachpakete:
   `de_DE` (Du) und `de_DE_formal` (Sie). Vorschlag: beide liefern; welche Form ist für
   Texte in diesem Dokument und in Beispielen Standard?
3. **Admin-Liste „Fragen“ (N):** gewünscht? Q2A hat keine solche Liste, in WP wird sie
   erwartet.
4. **Anonymes Posten:** beibehalten (setzt ein Cookie und speichert IP-Adressen) oder
   nur für angemeldete Benutzer?
5. **E-Mail-Vorlagen im Backend bearbeitbar** (N) oder nur über Übersetzung und Filter?
