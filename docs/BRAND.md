# MA Hotel brand

## Logo

The mark is a rounded square holding two arches that form an "M", like two doorways side by side. A tangerine dot sits above the middle stem, like a lamp over the door.
The wordmark "MA HOTEL" is drawn with strokes, so it needs no font: "MA" is heavier, "HOTEL" lighter.
Both were drawn by hand in SVG for this project.

Files in `portal/public/assets/brand/`:

| File | Use |
| --- | --- |
| `ma-hotel-logo-light.svg` | Full lockup on light backgrounds (site header, staff header, printed confirmation and receipt) |
| `ma-hotel-logo-dark.svg` | Full lockup on dark backgrounds and photos (landing hero, footer) |
| `ma-hotel-mark-light.svg` | Mark only on light backgrounds (staff header on phones) |
| `ma-hotel-mark-dark.svg` | Mark only on dark backgrounds (sign-in photo panel) |
| `favicon.svg`, `favicon-32.png` | Browser tab icon |
| `apple-touch-icon-180.png` | Home-screen icon; also the logo image in emails, since many email apps block SVG |

Rules:
- Keep clear space around the logo of at least the height of the tangerine dot's circle times two.
- Smallest size: 24 px tall for the lockup, 16 px for the mark.
- Do not recolour, stretch, rotate or add effects. Use the light or dark file instead.
- Always give the image `alt="MA Hotel"` when it is the only name on the page area, `alt=""` when the name is also written next to it.

## Colours

| Name | Hex | Use |
| --- | --- | --- |
| Navy 950 (`ink-950`) | `#121a30` | Headings, dark panels, footer |
| Navy 900 (`ink-900`) | `#1f2b4d` | The brand navy: logo square, dark buttons, email header |
| Navy 600 (`ink-600`) | `#454f6e` | Body text on white and canvas (7.4:1 or more) |
| Navy 500 (`ink-500`) | `#646b84` | Lightest text allowed, on white or canvas (4.8:1) |
| Canvas | `#f6f5f1` | Page background (warm white) |
| Tangerine 500 (`clay-500`) | `#f7941d` | Main action buttons (with navy text, 6.1:1), the logo dot, link underlines |
| Tangerine 700 (`clay-700`) | `#b35f05` | Focus rings and the few accent words on white (4.6:1) |
| Dusk 600 | `#3b5f8f` | Information and charts; white text on it is 6.5:1 |

The full scales are the `@theme` tokens in `portal/assets/app.src.css`.
Success, warning and error states use Tailwind's emerald, amber and red, with 800/900 text on 50/100 fills.

## Fonts

- **Inter Tight** (weights 400 to 700) for headings and big numbers, at weight 500 with tight tracking.
- **Inter** (weights 400 to 700) for body text and interface.

Both are by the Inter Project Authors under the SIL Open Font License 1.1. The Latin variable files and their licence texts are in `portal/public/assets/fonts/`. They are served from the site itself, never from a font CDN.
Emails cannot rely on web fonts, so they fall back to Arial.
