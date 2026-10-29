# GMA Testimonials

A single WordPress plugin that replaces **Strong Testimonials** on [Global Mentoring Academy](https://globalmentoringacademy.com) and absorbs the two standalone testimonial photo plugins.

**Current version:** 1.6

---

## Requirements

### Business requirements
- Strong Testimonials' form setup and view configuration proved clunky, and the filtering feature sits behind a paid extension tier that this install cannot use.
- Visitors should be able to filter testimonials by **mentor** and/or **service**.
- The display grid needs real admin control: columns, pagination, excerpt length, ordering, what each card shows.
- The Photo Reminder and Photo Uploader plugins should stop being separate installs.
- The switchover must not risk the ~50 existing testimonials, their photos, or the working WPForms intake.

### Functional requirements
1. Own the testimonial post type and render testimonials as a responsive grid via shortcode.
2. Mentor and service as two independent taxonomies, with AJAX dropdown filtering (AND logic across both).
3. Grid options configurable in wp-admin, with shortcode attributes overriding them per page.
4. Photo reminder emails continue unchanged, preserving send history.
5. Members continue to self-upload photos through a shortcode page.
6. Rollback to Strong Testimonials must remain possible.

### Technical requirements
- WordPress with WPForms Pro (Post Submissions addon) providing testimonial intake.
- PHP 8.x.
- All timestamps in Asia/Kolkata (IST), hardcoded, regardless of site timezone.

---

## Solution

### No-migration switchover

The plugin registers the post type **`wpm-testimonial`** — deliberately the same slug Strong Testimonials used. Testimonials are ordinary rows in `wp_posts`; deactivating Strong Testimonials doesn't delete them, it only leaves the post type unregistered. By adopting the slug, this plugin inherits every existing testimonial, featured image, category term and meta value with no data migration at all.

Consequences:
- **Rollback** = deactivate this, reactivate Strong Testimonials. Everything is where it was.
- **The WPForms form keeps working** — it already targets this post type.
- **The two photo modules keep working** — they already query this post type.

A guard prevents both plugins running at once: if Strong Testimonials is detected as active, this plugin declines to register the post type and shows an admin notice instead.

Because Strong Testimonials supplied the "testimonial" option to WPForms Post Submissions, the plugin re-supplies it via the `wpforms_post_submissions_post_type` filter.

### Modules

| Module | File | Provides |
|---|---|---|
| Core | `includes/class-gma-t-cpt.php` | Post type, 3 taxonomies, admin columns, meta-key detection |
| Settings | `includes/class-gma-t-settings.php` | One settings page for every module |
| Display | `includes/class-gma-t-display.php` | `[gma_testimonials]` grid, filter bar, AJAX paging |
| Reminder | `includes/class-gma-t-reminder.php` | Daily IST photo reminder emails (ported from v5.10) |
| Uploader | `includes/class-gma-t-uploader.php` | `[gma_testimonial_photo_upload]` (ported from v1.1) |
| Token | `includes/class-gma-t-token.php` | No-login upload links for reminder emails |
| Terms | `includes/class-gma-t-terms.php` | Assigns Mentor and Service terms from submissions; backfill tool |

### Taxonomies

- `wpm-testimonial-category` — the pre-existing Strong Testimonials category, preserved so any assigned terms keep working.
- `gma_mentor` — new.
- `gma_service` — new.

Two separate taxonomies (rather than one mixed category list) are what make independent mentor + service filtering possible. Populate the WPForms dropdowns using **Dynamic Choices → Taxonomy** so the form self-updates when a mentor or service is added.

### Display parity

Defaults reproduce the previous Strong Testimonials View exactly, so the page looks unchanged the moment the shortcode is swapped: newest first, 10 per page, title as H3 with no link, thumbnail photo with a configurable default image, 30-word excerpt, "Read more" expanding in place with "Show less" to collapse, and the post date shown.

Improvements on top: equal-height cards, circular photos, mentor/service badges, optional star rating, AJAX filter bar, and a responsive grid that collapses to two columns on tablets and one on phones.

### Shortcodes

```
[gma_testimonials]
[gma_testimonials mentor="nitin-naik" columns="2" per_page="6"]
[gma_testimonials service="university-placement" filters="0"]
[gma_testimonial_photo_upload]
```

Attributes: `columns`, `per_page`, `order`, `excerpt_words`, `mentor`, `service`, `category`, `search`, `filters`, `show_search`, `show_photo`, `show_date`, `show_rating`, `show_badges`, `pagination`, `pagination_pos`, `pagination_style`, `show_count`.

### Name search

The filter bar carries a search box for finding the person who left a testimonial.

It matches the **post title** and the configured **name meta field** only — never the testimonial body — so searching "Nitin" finds testimonials written *by* Nitin rather than every testimonial that mentions him.

- Partial words match: `narin` finds Narinder.
- Several words must all match, in any order: both `narinder kaur` and `kaur narinder` find the same person.
- Case-insensitive, and `%` or `_` typed in the box are treated as literal characters rather than SQL wildcards.
- Search narrows whatever the mentor and service dropdowns have already selected.

The term appears in the URL as `?q=`, so a search is shareable and bookmarkable in the same way as a filtered view:

```
/client-testimonials/?q=narinder
/client-testimonials/?mentor=nitin-naik&q=kaur
```

A `search` attribute on the shortcode pins the term and hides the box, the same way `mentor` does.

### Pre-filtered links

Add `?mentor=<slug>` or `?service=<slug>` to the testimonials page URL to open it already filtered:

```
/client-testimonials/?mentor=nitin-naik
/client-testimonials/?mentor=divya-sisodia&service=german-level-a1-part-i
```

The value is the term **slug**, listed under Testimonials → Mentors. An unknown slug falls back to showing everything rather than an empty page.

A URL filter only *preselects* the dropdown — visitors can change it or hit Reset. This differs from a shortcode attribute, which pins the grid to that term and hides the control entirely:

| | Grid filtered | Dropdown shown | Visitor can change |
|---|---|---|---|
| `?mentor=nitin-naik` | yes | yes, preselected | yes |
| `[gma_testimonials mentor="nitin-naik"]` | yes | no | no |

**Link builder** for mentor pages — takes the term name, so it survives slug changes:

```
[gma_testimonials_link mentor="Nitin Naik" text="Read his testimonials"]
[gma_testimonials_link service="Migration Services" text="See what clients say" class="my-button"]
```

Accepts a name or a slug, plus optional `text`, `class`, `url`, `arrow` and `style`.

`style` takes `outline` (default), `solid` or `text`:

```
[gma_testimonials_link mentor="Nitin Naik" text="Read his testimonials"]
[gma_testimonials_link mentor="Nitin Naik" text="Read his testimonials" style="text"]
[gma_testimonials_link mentor="Nitin Naik" text="See all reviews" style="solid" arrow="no"]
```

If no such term exists it renders nothing for visitors, and an explanation for administrators.

As visitors filter, the address bar updates via `replaceState`, so any view they reach is copyable and bookmarkable. A canonical tag points filtered views back at the clean page so search engines don't index the variants.

### Pagination

Three styles, set in Settings or per shortcode:

- **Numbered** — 1 2 3 buttons with prev/next arrows (default, matches the previous View).
- **Previous / Next only** — arrows with a "2 / 6" indicator; better for long lists.
- **Load more** — appends the next page into the existing grid instead of replacing it. Always renders below the grid regardless of the position setting, since a Load more button above the content makes no sense.

Position is *below the grid* or *above and below* (the latter matching the previous View's "before & after" navigation). Pagination can also be switched off entirely to show every testimonial on one page.

An optional result count line ("Showing 1-10 of 54") sits above the grid, with the wording editable via `{from}`, `{to}` and `{total}` placeholders. With Load more it counts up as more cards are appended.

### History preservation

The reminder module keeps the original option names for logs (`gma_tr_logs`) and per-recipient send counts (`gma_tr_send_counts`), and migrates settings across from `gma_tr_settings` on first load. Anyone who already received two reminders does not start again from zero. The standalone cron hook is cleared so it cannot fire alongside the new one.

---

## Installation and switchover

1. Install and activate **GMA Testimonials**. It will show a notice saying it is standing by.
2. Deactivate the two standalone photo plugins (Reminder and Uploader).
3. Copy the current Strong Testimonials shortcode off the testimonials page into a draft note — this is the rollback path.
4. Deactivate **Strong Testimonials** (do not delete it).
5. Replace the shortcode on the page with `[gma_testimonials]`.
6. Go to **Testimonials → Settings**, confirm the field mapping and set the default photo.
7. Assign mentor and service terms to existing testimonials (bulk edit from the Testimonials list).
8. Add the two dropdowns to WPForms form 2153 with Dynamic Choices → Taxonomy.
9. Purge LiteSpeed cache.

## Rollback

Deactivate this plugin, reactivate Strong Testimonials, restore the original shortcode. All data is untouched. Mentor and service terms assigned in the meantime stay in the database, dormant, until you switch forward again.

---

## Changelog

### v1.6
- **Name search box** in the filter bar, for finding the person who left a testimonial. Matches the post title and the name meta field only, not the testimonial body, so a search returns testimonials written *by* that person rather than ones mentioning them.
- Partial words match, and several words match in any order, so `narin`, `narinder kaur` and `kaur narinder` all find the same person.
- Typing is debounced; Enter searches immediately. A clear button resets just the search, and Reset clears the search along with the dropdowns.
- The term syncs to the URL as `?q=`, so searches are shareable, and an unmatched search names the term in the empty-state message.
- The filter bar now renders when search is enabled even if no mentor or service terms exist yet.
- `%` and `_` typed into the box are escaped, so they match literally instead of acting as SQL wildcards.

### v1.5.1
- Sidebar menu renamed from "Testimonials" to "GMA Testimonials", matching the naming of the other GMA plugins. The post type slug, labels elsewhere and all stored data are unchanged.

### v1.5
- **Tokenised upload links.** Reminder emails previously pointed at a page that required the recipient to log in. Members do have accounts — created silently when they submitted their testimonial — but most do not know that, and with PMPro restricting the page it read as "buy a membership". Recipients now get a personal link that works with no login.
- `{upload_url}` placeholder in the email body resolves to that recipient's own link, so the URL is no longer hand-maintained in the template.
- Token properties: 128 bits of randomness, scoped to one testimonial, authorises replacing that testimonial's photo and nothing else, expires after a configurable number of days (default 30), renewed automatically if a reminder would otherwise carry a link that dies before it is read, and cleared once a photo is uploaded. Reusable until then, so starting on a phone and finishing on a laptop still works.
- **Upload page URL** is now a setting rather than being buried in the email body.
- An expired or unknown link shows a plain explanation rather than a failure, and a logged-out visitor without a link is pointed back to their email instead of being told only to log in.

### v1.4.2
- Link now sizes with `width: fit-content` instead of `auto`, since themes and page builders commonly force `width:100%` on links inside their containers and `auto` does not override that. Add `class="gma-t-link-full"` if you do want it to fill the container.

### v1.4.1
- Fixed the link stretching into a tall oval inside page-builder containers. Spectra and most block themes make their containers flex, which stretches children across the full height of the column; a pill-shaped link stretched that way becomes an oval. The link now opts out of stretching on both axes and sizes to its text.

### v1.4
- **Link builder** on the settings page. Pick a mentor and/or service from dropdowns, set the link text, style and arrow, and it writes both the shortcode and the direct URL with copy buttons. Nothing is saved — it exists so building a link never means looking up a slug or recalling the syntax.
- The shortcode it writes uses term **names** and the URL uses **slugs**, matching how each is meant to be used.

### v1.3.2
- `[gma_testimonials_link]` gains a `style` attribute: `outline` (default, a pill button that reads as a button without competing with a solid primary CTA), `solid`, or `text` (bold with a growing underline). An arrow is appended by default and nudges right on hover; turn it off with `arrow="no"`.
- The stylesheet is now enqueued by the link shortcode as well, so the styling applies on mentor pages where the grid itself never runs.
- Any `class` you pass is added alongside the built-in classes rather than replacing them.

### v1.3.1
- `[gma_testimonials_link]` now matches terms tolerantly — by name, slug, or a case- and whitespace-insensitive scan — so a name typed on a mentor page need not match the stored term byte for byte.
- When it still can't build a link, it explains why **to administrators only**; visitors continue to see nothing rather than a broken link or an error.

### v1.3
- **Pre-filtered links.** `?mentor=` and `?service=` on the testimonials page URL preselect the dropdowns and filter the grid on first load, for linking from mentor profile pages. Slugs are validated against real terms, so a bad one falls back to "All" rather than showing an empty page.
- **`[gma_testimonials_link]` shortcode** builds those links from a term *name*, so mentor pages don't hand-code slugs and don't break when a slug changes.
- **Address-bar sync.** Filtering updates the URL via `replaceState`, making any filtered view shareable — and giving you the mentor links by just filtering and copying.
- **Canonical tag** on filtered views pointing at the clean page, to keep the variants out of search results.
- Shortcode attributes and URL parameters now behave differently on purpose: an attribute pins the grid and hides that dropdown, a URL parameter only preselects it.

### v1.2
- **Term assignment.** WPForms Post Submissions creates the testimonial but does not assign custom taxonomies, so new testimonials arrived with no Mentor or Service terms — no badges, and invisible to the filter dropdowns. The plugin now assigns them itself on `wpforms_process_complete`, matching the created post by email and creating terms that don't exist yet. Form and field IDs are configurable rather than hardcoded, so renumbering fields (or moving to another form plugin) is a settings change.
- **Assign Terms admin page** with a backfill tool: assign where missing, or re-assign across all testimonials from their stored entry data. Shows current coverage. Testimonials with no matching entry are left alone for Bulk edit.
- **Single testimonial URLs off by default.** Nothing links to them, they read as thin content, and Yoast counted every one as orphaned. The grid queries posts directly so it is unaffected. Toggleable in Settings; needs a permalink flush after changing.
- **Default photo shape** option — circle (matches headshots) or square (better for a wide graphic like the Thank You image). The fallback remains display-only and never becomes a featured image, so those testimonials keep receiving photo reminders.

### v1.1
- Pagination style: numbered, previous/next only, or Load more (AJAX append).
- Pagination position: below the grid, or above and below — restoring parity with the previous View's "before & after" navigation, which v1.0 had dropped.
- Pagination on/off toggle for a single-page grid.
- Optional result count line with an editable template.

### v1.0
Initial release: post type adoption with no data migration, mentor and service taxonomies with AJAX filtering, configurable responsive grid, plus the Photo Reminder (from standalone v5.10) and Photo Uploader (from standalone v1.1) modules.

---

## Upload links

Reminder emails carry `{upload_url}`, which resolves per recipient to:

```
/upload-testimonial-photo/?gma_photo_token=<32 hex characters>
```

The token is stored on the testimonial as `_gma_t_upload_token` with an expiry in `_gma_t_upload_token_expires`. It is not a login: it authorises replacing one testimonial's photo and nothing else, and it is cleared on success.

**The upload page must not be restricted by PMPro.** The shortcode performs its own access check — a visitor with no token and no matching account gets nothing useful — so a membership restriction only turns a reminder into a dead end for the very people it is chasing.

Turning tokens off makes `{upload_url}` fall back to the bare page, which then requires the member to log in.

## Development workflow

Standard process for every change:

1. Modify the source and increment the version in the plugin header.
2. Lint (`php -l`) and run the pre-packaging verification script.
3. Build the zip — internal folder name must be `gma-testimonials`.
4. Push source to GitHub and update this README when requirements, solution, or code structure change.
