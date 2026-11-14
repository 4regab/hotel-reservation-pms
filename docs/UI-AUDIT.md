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
