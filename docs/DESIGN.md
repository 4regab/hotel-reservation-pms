# MA Hotel design plan

This is the plan for the site redesign: what the new look is, the design tokens, the shared components, and the page-by-page changes.
It covers the public site, the booking flow, guest account pages, sign-in pages, every staff area, error and empty states, and the emails.
Behaviour does not change. Routes, forms, field names, roles, CSRF and business rules stay exactly as they are.

## 1. What we took from the references

We studied three hotel booking concepts on Dribbble as direction for quality, not as a source of assets.
No images, logos, illustrations or text from them are used. They share one visual language:

| Area | What the references do | What MA Hotel does |
| --- | --- | --- |
| Layout | Wide pages (about 1200 px of content), a 12-column grid, big hero at the top. The hero photo sits inside a large rounded frame with a margin around it, not edge to edge. | `container-page` max width 80rem (1280 px) with 16 / 24 / 32 px side padding. The landing hero is an inset rounded frame (`rounded-[2rem]`) with the nav on top of the photo. |
| Spacing | Generous and regular: sections 96 to 128 px apart, cards 16 to 24 px apart. | An 8 px rhythm. Sections `mt-24 sm:mt-32`, card gaps `gap-4 sm:gap-6`, card padding `p-5 sm:p-6`. |
| Type | One neo-grotesk family. Very large, light to medium headlines (64 to 96 px) with tight tracking and leading near 1. Body 14 to 16 px, muted gray. Few bold weights. | Display: **Inter Tight** (500, tight tracking). Body and UI: **Inter** (400 to 600). Both self-hosted under the SIL Open Font License. |
| Colour | Near-black ink, off-white canvas, white cards, muted grays, and one warm or bright accent used only for the main action. | Navy ink, warm-white canvas `#f6f5f1`, white surfaces, one accent **tangerine** (token name `clay`) for main actions, and a soft **dusk** blue for information and charts. |
| Radii | Large: 24 to 32 px on hero and photo cards, 16 px on cards, fully round pills on buttons, search fields and tags. | `rounded-[2rem]` hero, `rounded-3xl` photos, `rounded-2xl` cards, `rounded-full` buttons, inputs inside the search bar and badges. Staff tables and cards use `rounded-2xl` / `rounded-xl`. |
| Shadows | Almost none. Cards are flat or have a very soft, wide shadow. The search bar floats with one larger shadow. | `shadow-soft` on cards, `shadow-float` only on the search bar and menus. Borders are hairline `ink-200/70`. |
| Images | Calm, slightly dark photos, cropped wide, rounded, with a dark gradient where text sits on them. | The existing CC0 photos, with an ink gradient under hero text. No new decoration images. |
| Search / booking bar | A white rounded panel overlapping the bottom of the hero. Labelled fields in light gray rounded wells, one accent button at the end. | `search-bar` component: white panel, `field-well` groups (label above value), accent pill button. Stacks to two columns on phones. |
| Cards | Photo on top, then name and price on one line, a small line of facts under it. Very little chrome. | `room-card`: photo, name + "from" price on one line, facts row, amenity chips. |
| Buttons | Pills. The main one is the accent colour, often with a small round arrow chip on the right. Secondary is white or outlined. | `btn` is a pill. `btn-primary` (clay), `btn-dark` (ink), `btn-secondary` (white, outlined), `btn-ghost`, `btn-danger`. `btn-arrow` adds the round arrow chip. |
| Nav | Minimal: logo left, four or five text links, one pill button right. On the landing page it sits on the photo. | Same. White text on the landing hero, ink text on a canvas bar on other pages. A disclosure menu on phones. |
| Section headers | A small eyebrow label with a dot, a large two-line heading on the left, an action on the right. | `eyebrow` component (dot + label) and `section-title`. |
| Footer | Dark, rounded or full-width, simple columns of links and contact lines. | An ink footer panel with the light logo, three short columns and a bottom line. |

## 2. Design tokens (Tailwind theme)

All tokens live in `portal/assets/app.src.css` under `@theme`. Only Tailwind's built-in breakpoints are used (sm 640, md 768, lg 1024, xl 1280, 2xl 1536).

**Fonts**
- `--font-sans`: Inter, then system UI fonts.
- `--font-display`: Inter Tight, then Inter, then system UI fonts.
- Files: `portal/public/assets/fonts/*.woff2` (Latin subset, variable weight), licences next to them. `font-display: swap`. No CDN at runtime.

**Type scale** (Tailwind sizes, display font for headings)
- Hero: `text-5xl sm:text-6xl lg:text-7xl xl:text-8xl`, weight 500, `tracking-tight`, leading 0.95 to 1.
- Page title (public): `text-4xl sm:text-5xl`. Page title (staff): `text-2xl sm:text-3xl`.
- Section title: `text-3xl sm:text-4xl lg:text-5xl`.
- Card title: `text-xl` to `text-2xl`. Body: `text-base` (public), `text-sm` (staff tables). Small print: `text-xs` / `text-sm` in `ink-500` or darker.

**Colour**

The palette is "navy on white" with a tangerine accent: a known hotel pairing (deep navy type and panels, warm white pages, one bright orange action colour). It also matches the orange-on-dark accents of the references.
The token names `ink` (navy) and `clay` (tangerine) are kept from the first draft so the markup did not change.

| Token | Use | Contrast note |
| --- | --- | --- |
| `ink-50 … ink-950` (navy, brand navy `ink-900` `#1f2b4d`) | Text, dark surfaces, borders | `ink-500` `#646b84` is the lightest text allowed on white or canvas (4.8:1). Body text uses `ink-600` or darker. |
| `canvas` `#f6f5f1` | Page background | |
| `clay-50 … clay-900` (tangerine, `clay-500` `#f7941d`) | Main action buttons, the logo dot, small accents | Buttons use navy text on `clay-500` (6.1:1). Focus rings and accent text use `clay-700` (4.6:1 on white). |
| `dusk-50 … dusk-900` (soft blue, `dusk-600` `#3b5f8f`) | Information badges and alerts, charts | White on `dusk-600` is 6.5:1. |
| Tailwind `emerald`, `amber`, `red` | Success, warning, error states | Text always uses the 800/900 shades on 50/100 fills. |

**Radii**: `rounded-full` (buttons, inputs in public forms, badges), `rounded-xl` (inputs in staff forms, small tiles), `rounded-2xl` (cards, tables), `rounded-3xl` (photos), `rounded-[2rem]` (hero frame, footer, closing panel).

**Shadows**: `--shadow-soft` (cards), `--shadow-float` (search bar, menus, popovers). Nothing else.

**Motion**: transform and opacity only, under 300 ms for feedback, one-time reveals on scroll. All motion is off when the visitor asks for reduced motion.

## 3. Components (in `@layer components`)

Kept names (so every page picks up the new look): `container-page`, `card`, `card-pad`, `btn` + `btn-lg` / `btn-sm` / `btn-primary` / `btn-secondary` / `btn-danger` / `btn-ghost`, `label`, `hint`, `input`, `badge` + colour variants, `table-wrap`, `table`, `alert` + variants, `link`.

New:
- `btn-dark` (ink pill) and `btn-arrow` (a round chip with an arrow inside a button).
- `eyebrow` (dot + small label) and `section-title`.
- `page-head` (title block at the top of inner pages), `page-title`, `page-lead`.
- `search-bar` and `field-well` (the floating booking bar).
- `photo` (rounded photo with a subtle inner border).
- `chip` (amenity tag).
- `steps` (booking progress: numbered dots and a line, current step marked with `aria-current="step"`).
- `stat` (big number + label, used on the landing page, the staff dashboard and reports).
- `empty` (empty state: icon, one line, one action).
- `staff-shell` pieces: `staff-nav` (sidebar on `xl`, top scroller below), `staff-main`.

## 4. Logo

An original mark drawn by hand in SVG: a rounded square holding two arches that form an "M" (two doorways side by side), with a tangerine dot above the middle stem like a lamp over the door.
The wordmark "MA HOTEL" is drawn with strokes (no font needed): "MA" heavier, "HOTEL" lighter and spaced.
Files in `portal/public/assets/brand/`: full lockup and mark only, each in a light-background and a dark-background version, an SVG favicon, and 32 px and 180 px PNG icons. Usage rules are in `docs/BRAND.md`.

## 5. Page-by-page plan

Order of work: largest layout pieces first (page shell, header, footer, staff shell), then shared components, then each page.

**Shell**
- `layout.php`: canvas background, new header (logo, links, account actions, phone menu), new footer. The landing page gets a transparent header over the hero photo. Staff pages get the staff shell: a left sidebar from `xl` up, a horizontal scrolling tab bar below `xl`.
- `layout_doc.php` (printable confirmation and receipt): white page, light logo, same type.

**Public site**
- Home: inset rounded photo hero with the nav on it, a very large headline, a short lead and a rating-style fact line. The search bar overlaps the bottom of the hero. Then: an "about" block (eyebrow, large statement, three numbers), room cards in a three-column grid, "how booking works" as four numbered steps on an ink panel, amenities grid, FAQ as separate rounded cards, closing panel.
- Rooms: page head, then one large card per room type (photo left on `md`+, details right, price on the title line, chips, two buttons).
- Room detail: breadcrumb, wide rounded photo, title and price, facts as `stat` tiles, amenities, and a sticky booking card with a stacked search form (fixes the overflow the old wide form had in the narrow sidebar at 1024 to 1279 px).
- Search results: page head with the search bar, a summary line, result cards (photo, facts, chips, price panel with the Select button). Sold-out and "too small" states stay as text.

**Booking flow**
- `steps` progress on every step.
- Guest details: form card left, summary card right with photo.
- Pay: a countdown card (big tabular numbers, `role="timer"`, polite live milestones as today), summary, and the pay button. The expired state keeps `role="alert"`.
- Confirmation: success panel with a check icon, summary, print/download buttons. The "waiting for payment" state keeps its `aria-live` text.
- Demo checkout page: same card style with a clear demo warning.

**Guest account**: page head with greeting, booking rows as cards with status badges, empty states with an action, profile and privacy forms in cards.

**Sign-in, register, forgot and reset password**: a split layout on `lg` (form left, photo panel right with the brand line), single column on phones.

**Staff areas** (calmer and denser): same tokens and components with smaller sizes. Page heads use `page-title` at staff size. Tables keep their structure. Dashboard numbers use `stat`. Housekeeping board and room grid, approvals queue, finance, reports and charts (chart colours move to ink, clay and dusk), users, audit log, health, privacy queue and details, duplicates, partners, rates and walk-in all inherit the new components.

**Errors and empty states**: centred `empty` block with the status number, heading, message and two actions.

**Emails**: table layout with inline styles (email clients ignore stylesheets). Navy header with the mark and the wordmark as text, white card, tangerine button, canvas background, plain-text twin unchanged in content.

## 6. How each breakpoint is checked

Pages are rendered with sample data and captured with headless Chrome at 375, 768 and 1280 px (plus 1024 for the room page sidebar). The capture script also fails a page that scrolls sideways.
The real flows are covered by the existing end-to-end tests in CI, which drive the site over HTTP.

## 7. Accessibility rules

- Semantic landmarks, one `h1` per page, labelled form fields, the skip link stays.
- Visible focus ring (2 px tangerine-700 outline with offset) on every interactive element.
- Everything works by keyboard. The phone menu is a native `<details>` disclosure.
- Text contrast at least 4.5:1 (3:1 for large text and chart marks).
- The hold countdown and form errors are announced (`role="timer"`, `aria-live`, `role="alert"`), as before.
- `prefers-reduced-motion` turns off reveals, the hero drift and button press effects.
