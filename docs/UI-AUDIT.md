# UI and UX audit

Every page was rendered with sample data and checked at 375, 768 and 1280 px.
The sample data uses long names, long email addresses and very large amounts on purpose, to show wrapping problems.

The pages checked:
- **Public site:** home, rooms, room detail, search with results, search before choosing dates.
- **Booking flow:** guest details, pay with the hold countdown, hold expired, waiting for payment, confirmed, and the test checkout (card, card errors, 3-D Secure step, GCash).
- **Guest account:** my bookings (with bookings and empty), booking detail, profile, privacy.
- **Sign-in:** sign in, sign-in error, register, forgot password, reset password.
- **Staff:** dashboard (with cards and empty), front desk (with arrivals and empty), booking detail, walk-in, guests (with results and empty), housekeeping, maintenance, approvals, finance (payments, refunds, exports), partners, import result, rates, reports, users, new-account credentials, audit log, system health, privacy queue and detail, duplicate guests.
- **Errors, print views and emails:** the 404 page, printable confirmation, receipt, and the four emails.

How it was checked:
1. A headless Chrome script (a browser with no window) looked for sideways scrolling, boxes past the screen edge, and text spilling out of its box. It also checked tap targets under 44 px, buttons of different heights side by side, and text contrast below WCAG AA.
2. Contact sheets of every page at each width were read by eye.
3. Text over photos was measured separately, pixel by pixel, because the script skips it.
4. The design-taste-frontend skill's checker ran its contrast, focus, tap-size, labelling and overflow checks on the main public pages and three staff pages.

## Issues found and fixed

| # | Area | Page | Width | Issue | Fix |
| --- | --- | --- | --- | --- | --- |
| 1 | Public site | Home | 375 | Dates in the hero booking card were cut off by the calendar icon. | The date fields take the full width on phones. |
| 2 | Public site | Home | 375 | Room photo tiles squashed their text against the bottom edge. | Tiles are flex columns, so the caption always sits at the foot of the photo. |
| 3 | Public site | Home | 1280 | The second headline line over the bright skyline measured 3.3:1. | Darker left-side shade over the photo. Every hero line now measures 5.4:1 or more at all widths. |
| 4 | Public site | Home | 375 | The hero subline was as large as the headline, so the first screen was crowded. | The headline is split into three short lines and the subline is body size. |
| 5 | Public site | All pages | all | Header and menu links were 36 px tall (tap target). | Links are 44 px tall. |
| 6 | Public site | Home, room, search | all | Search fields were 36 px tall. | Fields are 44 px tall. |
| 7 | Public site | Room detail | all | The breadcrumb link was 17 px tall. | The link has a 44 px tap area. |
| 8 | Booking flow | Pay, guest details, checkout | all | The price showed only a total, with no tax line. | A breakdown everywhere a guest pays: nightly rate times nights, price before VAT, VAT (12%) and the total. Amounts are unchanged, since prices already include VAT. |
| 9 | Booking flow | Test checkout | 375 | The card number hint was cut off by the space kept for the card brand. | Less space reserved for the brand label. |
| 10 | Booking flow | Test checkout | all | The demo checkout used the full site menu, so it did not feel like a separate payment page. | Its own simple frame: logo, "Secure checkout" and a test-mode badge. |
| 11 | Booking flow, account | All pages with small buttons | all | Small buttons ("View", "Pay now", "Cancel this hold", "Open") were 36 px tall. | All buttons are at least 44 px tall. |
| 12 | Guest account | Booking detail | 375 | Payment amounts broke onto two lines ("PHP" / "11,200.00"). | Amounts never wrap. |
| 13 | Print views | Receipt | 375 | Amounts in the receipt table broke onto two lines. | The amount column never wraps, and the table fits the page. |
| 14 | Print views | Confirmation, receipt | 375 | The "Metro Manila, Philippines" line was squeezed beside the Print button. | That line is hidden on phones. The logo already names the hotel. |
| 15 | Staff | Guests, users, privacy queue, maintenance, import result | 375 | Tables squeezed their columns down to one word per line, and emails broke letter by letter. | Tables keep a readable width and scroll sideways inside their frame on small screens. |
| 16 | Staff | Reports | 375 | Chart labels shrank to about 4 px and could not be read. | Charts keep a readable width and scroll sideways on small screens. |
| 17 | Staff | Reports | 375 | Fix 16 at first pushed the whole page 259 px sideways. | The chart grid lets its items shrink, so only the chart scrolls. |
| 18 | Staff | Reports | 375, 768 | Figures such as "PHP 5,412.00" broke onto two lines. | Figures never wrap and are a size smaller on phones. |
| 19 | Staff | Finance, reports tables | 375 | Amounts in table cells broke onto two lines. | Money cells never wrap. |
| 20 | Staff | Housekeeping | all | Room counts were loose badges with small numbers. | Each status is a small card with a large count. |
| 21 | Staff | Housekeeping | 375 | Two narrow columns of room cards made the buttons wrap and crowd. | One column on phones, then 2, 3 and 5 columns as the screen grows. |
| 22 | Staff | All staff pages | all | Sidebar and tab links were 40 px tall. | 44 px. |
| 23 | Staff | Users | all | The role picker in each row was 36 px tall, and "Create a staff account" was 28 px tall. | Both are 44 px. |
| 24 | Staff | Users | 375 | The "Name or email" filter was squeezed to about 40 px wide. | It takes its own row on phones. |
| 25 | Staff | Reports | all | "Show as a table" was a 20 px tall link. | 44 px tall, with the same underline as other links. |
| 26 | Emails | All four | 375 | Labels such as "Confirmation code" broke onto two lines beside long values. | Labels stay on one line and values take the rest of the row. |

**Count by area:**

| Area | Issues fixed |
| --- | --- |
| Public site | 7 |
| Booking flow | 3, plus the shared small-button fix |
| Guest account | 1, plus the shared small-button fix |
| Print views | 2 |
| Staff | 11 |
| Emails | 1 |
| **Total** | **26** |

Earlier rounds of this redesign also fixed these issues, which the same checks found:
- The room page form was too wide for its sidebar at 1024 to 1279 px.
- Body text contrast on the canvas was 6.9:1, below the 7:1 target the copy checker uses.
- The search fields had no visible focus ring.
- The pay page scrolled sideways at 320 px.
- The landing hero text had low contrast on phones.

## Checked and left as is

| Page | Width | What the check reported | Why it stays |
| --- | --- | --- | --- |
| Home | 1280 | Header links "1.08:1". | False reading. These links are white on the dark hero photo. The script compared them with the light page colour behind the photo. |
| Home | all | The booking card overlaps the bottom of the hero photo. | Intended. It is the floating booking bar from the references. |
| Staff header | 375 | The tab row is cut off at the right edge. | Intended. It is a horizontally scrolling row. |
| Emails | all | The logo image is missing in local renders. | The PNG loads from the site's public address, which was not running here. Its alt text is empty because "MA HOTEL" is written next to it. |

## Not checked

- Real email apps (Gmail, Outlook, Apple Mail). The emails were checked as rendered HTML in Chrome.
- Browsers other than Chrome.
- Pages showing live data from SQL Server. The end-to-end tests in CI do drive these pages over HTTP.

## Round 3: landing page copy and motion, staff workspace

The client asked for copy the length of real hotel sites, the look of the two Dribbble references, scroll animations, and a more professional staff workspace.

**Copy length, measured.** Home pages of real hotel brands, counted with headless Chrome at 1440 px (visible words in the main area):

| Site | Words | Median paragraph |
| --- | --- | --- |
| The Hoxton | 560 | 17 words |
| YOTEL | 566 | 16 words |
| Aman | 404 | 15 words |
| citizenM | 905 | 11 words |
| Raffles Makati | 1,339 | 19 words |
| MA Hotel before | 544 | |
| MA Hotel now | 738 | about 20 words |

So the home page now sits in the same range, with paragraphs of 15 to 30 words like these sites: a two-sentence hero line, a lead under each section heading, a line for each of the four stay steps, and a closing sentence.

**What changed**

| # | Area | Change |
| --- | --- | --- |
| 1 | Home hero | Headline "A boutique hotel in Metro Manila" (two lines, was four). A 30-word line names the room mix and the night desk. Button "Find a room". |
| 2 | Home story | The statement now turns from readable grey to ink as you scroll (was 18% opacity, which failed contrast). A row of four facts under it. |
| 3 | Home rooms | Two tiles a row on a tablet with the Suite full width under them (was one tall photo a row). A lead line names the three room types. |
| 4 | Home steps | Replaced the floating photos with a pinned photo story in four steps. |
| 5 | Home FAQ | The side photo is cropped to the height of the questions instead of towering over them. |
| 6 | Home close | A sentence under the headline about booking direct. |
| 7 | Motion | Removed the fade-in of every section and the floating photos. Kept one hero entrance and the three scroll moments above. Hover lift 2 px (was 4), photo zoom 3% (was 4.5%). |
| 8 | Phone menu | Escape and a click outside close it, and focus goes back to the menu button. |
| 9 | Buttons and fields | One height: 48 px (44 px for small buttons). |
| 10 | Staff shell | Fixed sidebar with icons, the person and Sign out at its foot; the top bar only below `xl`. Removed the footer sentence. |
| 11 | Staff look | Grey page, sans-serif titles, navy main buttons, flat cards, quieter tables, 36 px desktop controls. |
| 12 | Staff tabs | Front desk, finance and privacy lists use one segmented tab style (were orange and white pill buttons). |
| 13 | Staff figures | Report figures sit in one joined strip; dashboard cards use the same label, value and note styles. |
| 14 | Search | The "Check availability" button no longer shrinks to 24 px on phones. |

All rendered pages were checked again at 375, 768 and 1280 px: no sideways scroll on any page.
