# Stored procedures and views

All data access in the four services goes through stored procedures on SQL Server. PHP never holds a SQL statement of its own.

## How it works

- PHP calls a procedure with `Lib\Db::call($pdo, 'usp_name', [params])` (`src/shared/src/Db.php`). Parameters are bound, so there is no string-built SQL and no injection path.
- Reads go through views (`vw_*`). A view joins and shapes the tables once, and procedures read from it.
- Writes happen inside procedures. Locks (`UPDLOCK`, `HOLDLOCK`) sit in the procedure that needs them, next to the statement they protect.
- Transactions stay in PHP (`Tx::run`), so one business action can call several procedures and commit or roll back together.
- Optional filters are parameters that default to `NULL`, where `NULL` means "no filter".
- Row counts for updates come back as `SELECT @@ROWCOUNT AS affected`, because `PDOStatement::rowCount()` is unreliable with `SET NOCOUNT ON`.
- Each service connects with its own login. `scripts/migrate.php` grants `EXECUTE` on the `dbo` schema and `SELECT` on views to that login only.
- `scripts/check-no-inline-sql.php` runs in CI and fails the build if SQL text appears in PHP under `src/`.

## Naming

- Procedures: `usp_<area>_<verb><Noun>`, for example `usp_folio_getSummary`.
- Views: `vw_<Noun>`.
- Every procedure has a one or two line comment above it. This page is built from those comments.

## Where each object is defined

| Service | Migration files |
|---|---|
| shared | `src/shared/migrations/` |
| booking | `src/booking/migrations/` (007, 008 for procedures) |
| billing | `src/billing/migrations/` (003) |
| roomops | `src/roomops/migrations/` (005) |
| portal | `src/portal/migrations/` (003, 004) |

The data dictionary and logical model are generated from the live catalog in CI (`database-docs` artifact).

## Catalog

### shared (13 objects)

| Object | Kind | What it does | File |
|---|---|---|---|
| `usp_audit_write` | Procedure | Appends one entry to the audit log (who, what, which record, from which address). | `001_shared_audit_inbox.sql` |
| `usp_audit_search` | Procedure | Lists audit entries newest first. Filters by action prefix, actor and date range; every filter is optional (NULL = no filter). | `001_shared_audit_inbox.sql` |
| `usp_inbox_record` | Procedure | Records that an incoming event was handled. Returns affected = 1 the first time and 0 for a repeat, so a consumer can skip duplicates. | `001_shared_audit_inbox.sql` |
| `vw_outbox_stuck` | View | Outbox deliveries that are not yet DELIVERED, with the event type and last error. | `002_shared_outbox_idempotency.sql` |
| `vw_outbox_due` | View | Outbox deliveries in PENDING state, with their payload and next attempt time. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_counts` | Procedure | Counts outbox deliveries by status, for the status page. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_stuck` | Procedure | Lists the undelivered outbox entries, oldest first, up to a limit. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_add` | Procedure | Queues one event and creates one PENDING delivery row per handler named in the JSON list. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_due` | Procedure | Returns the PENDING deliveries whose retry time has come, oldest first, up to a limit. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_mark_delivered` | Procedure | Marks one delivery as DELIVERED and clears its last error. | `002_shared_outbox_idempotency.sql` |
| `usp_outbox_mark_failed` | Procedure | Saves the attempt count, new status and error of a failed delivery, and sets its next retry time. | `002_shared_outbox_idempotency.sql` |
| `usp_idem_find` | Procedure | Looks up the saved answer for an Idempotency-Key and caller, so a repeated request gets the same reply. | `002_shared_outbox_idempotency.sql` |
| `usp_idem_save` | Procedure | Saves the answer for an Idempotency-Key and caller. An existing key is overwritten. | `002_shared_outbox_idempotency.sql` |

### booking (76 objects)

| Object | Kind | What it does | File |
|---|---|---|---|
| `vw_OtaAllotment` | View | Every column of an OTA allotment request. | `007_procs_ota_insight_catalog.sql` |
| `vw_OtaAllotmentUsage` | View | An allotment with its partner and room type names and how many room-nights are reserved and already used. | `007_procs_ota_insight_catalog.sql` |
| `vw_OtaImport` | View | One row per CSV import with its counts. | `007_procs_ota_insight_catalog.sql` |
| `vw_SoldRoomNight` | View | One row per confirmed room-night that counts as sold (booking confirmed, in house or finished), with the saved nightly price. | `007_procs_ota_insight_catalog.sql` |
| `vw_ActiveGuest` | View | Guests that are still in use (not merged into another record and not anonymised). | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_listPartners` | Procedure | Lists the active OTA partners by name. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_partnerExists` | Procedure | Returns one row when the partner exists and is active, none otherwise. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_listAllotments` | Procedure | Lists the newest 100 allotments with usage. A NULL status means no filter. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertAllotment` | Procedure | Saves a new allotment request in the PENDING state. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_getAllotment` | Procedure | Returns one allotment row, or none when the id is unknown. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_lockAllotment` | Procedure | Locks the allotment row for update and returns its current status. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_rejectAllotment` | Procedure | Marks a pending allotment as REJECTED. Does nothing if it was already decided. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_approveAllotment` | Procedure | Marks an allotment as APPROVED. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_returnAllotment` | Procedure | Marks an allotment as RETURNED (unused stock goes back to direct sales). | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_listAllotmentNights` | Procedure | Lists the reserved and used count of every night of an allotment, oldest night first. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertAllotmentNight` | Procedure | Adds one night to an allotment with its reserved count and nothing used yet. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_releaseAllotmentNight` | Procedure | Gives the unused part of one night back: reserved becomes equal to used. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_listImports` | Procedure | Lists the 30 newest imports. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertImport` | Procedure | Saves the summary and row-by-row report of one import. The report is JSON text. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_bookingExists` | Procedure | Returns one row when this partner booking id was already imported, none otherwise. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_lockAllotmentForImport` | Procedure | Finds the oldest approved allotment of the partner and room type that has enough unused rooms on every night of the stay, locks it, and returns its id. Returns no row when none fits. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_useAllotmentNights` | Procedure | Adds the imported rooms to the used count of every night of the stay. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_findGuestByEmail` | Procedure | Returns the id of the active guest with this email, or no row. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertGuest` | Procedure | Saves a guest who has no portal account (created by an OTA import). | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertReservation` | Procedure | Saves a confirmed, prepaid reservation that came from an OTA import. | `007_procs_ota_insight_catalog.sql` |
| `usp_ota_insertBooking` | Procedure | Links an imported partner booking to its reservation and allotment. | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_totalRooms` | Procedure | Returns one row: the number of rooms the hotel owns (sum of room type capacity). | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_soldByNight` | Procedure | Rooms sold and revenue per night and room type between two dates (inclusive). | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_stockByNight` | Procedure | Blocked, held and OTA-reserved rooms per night between two dates (inclusive). | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_listRoomTypes` | Procedure | Lists room types cheapest first, for the per-room-type totals. | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_kpiCounts` | Procedure | Report counts in one row: oversold nights, live bookings, paid direct bookings (and how many were confirmed within 2 minutes of payment, for bookings created in the date range), and active guests. | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_listActiveGuests` | Procedure | Lists active guests in the shape the duplicate-guest check expects (bookings is always 0 here). | `007_procs_ota_insight_catalog.sql` |
| `usp_insight_bookingCounts` | Procedure | Counts reservations created in the date range by status and source. | `007_procs_ota_insight_catalog.sql` |
| `usp_catalog_updateRoomType` | Procedure | Changes a room type. A NULL parameter leaves that column as it is. | `007_procs_ota_insight_catalog.sql` |
| `vw_BookingRoomType` | View | Every room type with all its columns. | `008_procs_guest_booking_inventory.sql` |
| `vw_BookingGuestSummary` | View | One row per guest with the booking count and the last stay that was started or finished. | `008_procs_guest_booking_inventory.sql` |
| `vw_BookingReservation` | View | One row per reservation with the guest's contact details, the guest's account and the room type name. | `008_procs_guest_booking_inventory.sql` |
| `vw_BookingInventoryNight` | View | Nightly stock counters for each room type and date. | `008_procs_guest_booking_inventory.sql` |
| `usp_booking_listRoomTypes` | Procedure | All room types, cheapest first. | `008_procs_guest_booking_inventory.sql` |
| `usp_booking_getRoomType` | Procedure | One room type by id (no row when unknown). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_search` | Procedure | Search guests that are neither merged nor deleted. @like is a ready LIKE pattern with backslash escapes, or NULL for no filter. At most 100 rows. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_listActive` | Procedure | Every guest that is neither merged nor deleted, with the booking count (for duplicate review). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_findByEmail` | Procedure | The oldest live guest with this email (no row when none). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_findByAccount` | Procedure | The guest id for a portal account (no row when none). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_exists` | Procedure | Whether a guest id exists (one row when it does). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_create` | Procedure | Create a guest. @account_id is NULL for a walk-in without a portal account. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_getContact` | Procedure | The name, email and phone of one guest (no row when unknown). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_lockPair` | Procedure | Lock two guest rows for a merge (UPDLOCK, in id order) and return them. Fewer than two rows back means one id is unknown. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_moveReservations` | Procedure | Move every reservation from one guest to another. Returns the number moved as affected. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_retireMerged` | Procedure | Retire a merged guest: point it at the kept guest and release its portal account. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_setAccount` | Procedure | Attach a portal account to a guest. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_recordMerge` | Procedure | Record a guest merge in the merge history. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_getByAccount` | Procedure | The guest record for a portal account, for a privacy access request (no row when none). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_correctByAccount` | Procedure | Correct a guest's name, email and phone by portal account. Returns affected = rows whose text really changed (the BIN2 comparisons skip rows that are identical; SQL Server would otherwise count every matched row). | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_lockByAccount` | Procedure | Lock the guest row of a portal account (UPDLOCK) and return its id (no row when none). | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_countLiveForGuest` | Procedure | How many held, confirmed or in-house reservations a guest still has. | `008_procs_guest_booking_inventory.sql` |
| `usp_guest_anonymize` | Procedure | Remove a guest's identity (name, email, phone, account) and clear the special requests on their reservations. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_getById` | Procedure | One reservation by id with the guest's account and contact details (no row when unknown). | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_lock` | Procedure | One reservation by id, locked with UPDLOCK (take this before any inventory locks). Only the reservation row is locked. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_findIdByCode` | Procedure | The reservation id for a confirmation code (no row when unknown). | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_getCode` | Procedure | The confirmation code of one reservation. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_search` | Procedure | List reservations (newest first, or by arrival). Every filter is optional: NULL means no filter. @statuses and @ids are comma lists; an empty @ids ('') matches nothing. @like is a ready LIKE pattern with backslash escapes. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_listForGuest` | Procedure | The reservations of one guest with the room type name, oldest first (privacy export). | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_insert` | Procedure | Create a reservation in status HELD with no guarantee yet, version 1. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_markConfirmed` | Procedure | Record when a reservation became CONFIRMED and, when known, when payment arrived. Clears the guarantee request. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_setStatus` | Procedure | Set the status (and the guarantee when given), add one to the version and stamp the update time. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_markCancelled` | Procedure | Stamp the cancellation time. The penalty is saved only when given (a cancelled hold has none). | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_markNoShow` | Procedure | Stamp the no-show time and save the penalty. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_flagGuaranteeRequested` | Procedure | Flag a hold as waiting for a manager's pay-at-hotel guarantee. | `008_procs_guest_booking_inventory.sql` |
| `usp_reservation_listExpiredHoldIds` | Procedure | Ids of holds whose time is up, oldest first. | `008_procs_guest_booking_inventory.sql` |
| `usp_inventory_ensureNight` | Procedure | Create the stock row for one room type and night when it is missing (starts with nothing blocked, held or confirmed). | `008_procs_guest_booking_inventory.sql` |
| `usp_inventory_getNights` | Procedure | Stock rows for a room type between two dates, in date order. @lock = 1 takes UPDLOCK on them (inside a transaction); 0 reads without locks. | `008_procs_guest_booking_inventory.sql` |
| `usp_inventory_saveNight` | Procedure | Save the four stock counters for one room type and night. | `008_procs_guest_booking_inventory.sql` |
| `usp_allocation_upsert` | Procedure | Save one night of a reservation's allocation: update it when it exists, insert it otherwise. | `008_procs_guest_booking_inventory.sql` |
| `usp_allocation_setState` | Procedure | Set the state of every allocation night of a reservation. | `008_procs_guest_booking_inventory.sql` |
| `usp_maintenance_recordBlock` | Procedure | Record a maintenance block once per request id. Returns affected = 1 when saved, 0 when the request id was already used. | `008_procs_guest_booking_inventory.sql` |

### billing (43 objects)

| Object | Kind | What it does | File |
|---|---|---|---|
| `vw_Payment` | View | Every payment, with the Manila calendar day it was taken. | `003_procs_folio_finance_payment.sql` |
| `vw_Refund` | View | Every refund joined to its payment, with the Manila calendar day it was completed. | `003_procs_folio_finance_payment.sql` |
| `vw_Charge` | View | Charges on a bill, with the reservation the bill belongs to. | `003_procs_folio_finance_payment.sql` |
| `vw_AccountingExportSummary` | View | Accounting exports without the CSV body (the list screen does not need it). | `003_procs_folio_finance_payment.sql` |
| `vw_PendingCheckoutSession` | View | Hosted checkout sessions that are still waiting for payment. | `003_procs_folio_finance_payment.sql` |
| `usp_folio_getSummary` | Procedure | Bill totals for one reservation: folio fields (NULL when there is no bill), captured payments and completed refunds. @lock = 1 takes an update lock on the folio row first, for callers that go on to change the bill. | `003_procs_folio_finance_payment.sql` |
| `usp_folio_getForUpdate` | Procedure | Locks and returns the bill of a reservation (folio_id, status, currency). No row when there is no bill. | `003_procs_folio_finance_payment.sql` |
| `usp_folio_getId` | Procedure | Returns the folio id of a reservation without locking. No row when there is no bill. | `003_procs_folio_finance_payment.sql` |
| `usp_folio_open` | Procedure | Opens the bill with the room charge and attaches earlier payments. Does nothing when the bill already exists. For OTA_PREPAID bookings it also records the partner payment (once) so the bill balances. | `003_procs_folio_finance_payment.sql` |
| `usp_folio_close` | Procedure | Closes the bill of a reservation (check-out). | `003_procs_folio_finance_payment.sql` |
| `usp_folio_addCharge` | Procedure | Adds one charge to a bill and raises the bill total by the same amount. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_insertOnsite` | Procedure | Records an on-site payment (cash, terminal, GCash). @folio_id is NULL for a payment taken before the bill is open. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_insertProvider` | Procedure | Records a payment reported by the online provider. A repeated event or payment id raises a duplicate-key error. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_get` | Procedure | Returns one payment (payment_id, amount_minor, status). No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_getFirstCaptured` | Procedure | Returns the id of the earliest captured payment of a reservation. No row when there is none. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_hasCapturedForUpdate` | Procedure | Locks the captured payments of a reservation and returns a row when one exists (guards against double payment). | `003_procs_folio_finance_payment.sql` |
| `usp_payment_list` | Procedure | Payment list for a date range (Manila days), newest first, at most 300 rows. @reconciled: '0' = captured and not yet reconciled, '1' = reconciled, NULL = any.  @status: 'REVIEW' = only payments in review, NULL = any. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_reconcile` | Procedure | Marks one captured payment as reconciled. Returns affected = 0 when it is unknown, not captured or already reconciled. | `003_procs_folio_finance_payment.sql` |
| `usp_payment_reconcileUpTo` | Procedure | Reconciles every unreconciled captured payment taken on or before a Manila day. Returns how many. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_request` | Procedure | Creates a refund request. A second request for the same payment raises a duplicate-key error. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_getByPayment` | Procedure | Returns the refund id requested for a payment. No row when none. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_getForApproval` | Procedure | Locks a refund and returns it with its payment details, for approval. No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_getForUpdate` | Procedure | Locks a refund and returns who requested it and its status, for a reject decision. No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_approve` | Procedure | Marks a refund as approved by a manager. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_complete` | Procedure | Marks a refund as completed (once only; an already completed refund is left alone). | `003_procs_folio_finance_payment.sql` |
| `usp_refund_reject` | Procedure | Marks a refund as rejected, with the manager's note. | `003_procs_folio_finance_payment.sql` |
| `usp_refund_list` | Procedure | Refund queue, newest first, at most 200 rows. @status NULL = all statuses. | `003_procs_folio_finance_payment.sql` |
| `usp_receipt_get` | Procedure | Everything a receipt shows, as three result sets in this order: captured payments, refunds, charges. | `003_procs_folio_finance_payment.sql` |
| `usp_checkout_createSession` | Procedure | Saves a hosted checkout session we created, with the price the webhook must match. | `003_procs_folio_finance_payment.sql` |
| `usp_checkout_getPending` | Procedure | Returns a checkout session that is still pending (reservation_id, amount_minor, currency). No row otherwise. | `003_procs_folio_finance_payment.sql` |
| `usp_checkout_getForUpdate` | Procedure | Locks a checkout session (any status) and returns the price saved for it. No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_checkout_markPaid` | Procedure | Marks a checkout session as paid. | `003_procs_folio_finance_payment.sql` |
| `usp_export_getLines` | Procedure | Lines for a closed-day range as two result sets: captured payments (by payment day), then completed refunds (by completion day). | `003_procs_folio_finance_payment.sql` |
| `usp_export_insert` | Procedure | Stores a requested export with its CSV and checksum. | `003_procs_folio_finance_payment.sql` |
| `usp_export_list` | Procedure | The 50 newest exports, without the CSV body. | `003_procs_folio_finance_payment.sql` |
| `usp_export_getForUpdate` | Procedure | Locks an export and returns who requested it, its status and batch reference. No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_export_decide` | Procedure | Records a manager's decision on an export. @status is APPROVED or REJECTED. | `003_procs_folio_finance_payment.sql` |
| `usp_export_get` | Procedure | Returns an export with its CSV (csv, batch_ref, checksum, status). No row when unknown. | `003_procs_folio_finance_payment.sql` |
| `usp_export_addDownload` | Procedure | Counts one more download of an export. | `003_procs_folio_finance_payment.sql` |
| `usp_report_paymentsByDay` | Procedure | Captured payments per Manila day and payment method (method, or provider when there is no method). | `003_procs_folio_finance_payment.sql` |
| `usp_report_refundsByDay` | Procedure | Completed refunds per Manila day (the day they were completed). | `003_procs_folio_finance_payment.sql` |
| `usp_report_openCounts` | Procedure | Two counters for the revenue screen: refunds waiting for a decision and payments held for review. | `003_procs_folio_finance_payment.sql` |
| `usp_report_reconciliationInput` | Procedure | Captured payments in a Manila day range with their reconciliation time (for the on-time reconciliation rate). | `003_procs_folio_finance_payment.sql` |

### roomops (44 objects)

| Object | Kind | What it does | File |
|---|---|---|---|
| `vw_RoomOpsRoom` | View | Every room with its details. | `005_procs_rooms_stay_maintenance.sql` |
| `vw_RoomOpsArrival` | View | Arrivals fed by reservation events. | `005_procs_rooms_stay_maintenance.sql` |
| `vw_RoomOpsStayRoom` | View | One row per stay and room (a stay without an assignment has NULL room columns). | `005_procs_rooms_stay_maintenance.sql` |
| `vw_RoomOpsCleaningTask` | View | Cleaning tasks with the room's number, floor and version. | `005_procs_rooms_stay_maintenance.sql` |
| `vw_RoomOpsArrivalOverride` | View | Early-arrival requests. | `005_procs_rooms_stay_maintenance.sql` |
| `vw_RoomOpsMaintenanceBlock` | View | Maintenance blocks with the room's number and type. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listRooms` | Procedure | Rooms by room number. @room_type_id NULL = all types. @with_stay = 1 adds the reservation that occupies each room. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_lockRoom` | Procedure | One room by id, row locked until the transaction ends (no row when unknown). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_getRoom` | Procedure | One room by id, no lock (no row when unknown). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_setRoomStatus` | Procedure | Set a room's readiness and bump its version. For CLEANING, the room's open cleaning tasks go to IN_PROGRESS under @assignee. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_takeRoomOutOfService` | Procedure | Take a room out of service when it is READY or DIRTY. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_pickReadyRooms` | Procedure | The first @quantity READY rooms of a type with no active assignment in the dates, locked, in room-number order. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_hasAssignmentOverlap` | Procedure | One row (x = 1) when the room has an active assignment that overlaps [@start_date, @end_date); no row otherwise. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listExpectedArrivals` | Procedure | Up to 200 expected arrivals, earliest check-in first. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_confirmArrival` | Procedure | Record an arrival from ReservationConfirmed. An existing arrival goes back to EXPECTED unless the guest already ARRIVED. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_setArrivalStatus` | Procedure | Set an arrival's status. @only_from NULL = from any status, otherwise only when it is currently that status. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_requestOverride` | Procedure | Save an early-arrival request. A repeat request updates the reason only while it is still PENDING. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_getOverride` | Procedure | Status and reason of a reservation's early-arrival request (no row when none). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_getApprovedOverride` | Procedure | Reason and approver of an APPROVED early-arrival request (no row when none). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listOverrides` | Procedure | Up to 100 early-arrival requests with one status, oldest first. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_decideOverride` | Procedure | Approve or reject a PENDING early-arrival request. Returns affected = 0 when none was pending. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listCleaningTasks` | Procedure | Up to 200 cleaning tasks whose status is in the comma list @statuses, oldest first. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_lockCleaningTask` | Procedure | One cleaning task by id, row locked until the transaction ends (no row when unknown). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_markTaskCleaned` | Procedure | The cleaner reports the task clean: status CLEANED. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_inspectTask` | Procedure | Inspection passed: the task is DONE and the room, if it is CLEANING, becomes READY. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_releaseRoom` | Procedure | A room left DIRTY after check-out gets an OPEN cleaning task. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listStays` | Procedure | Rooms and times for the reservations in the comma list @ids, by room number. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_getStay` | Procedure | A reservation's stay id and check-out time (no row when it has no stay). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_lockStay` | Procedure | Same as getStay, with the stay row locked until the transaction ends. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_insertStay` | Procedure | Start a stay now. A second stay for the same reservation fails with the duplicate-key error (2627). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_assignRoom` | Procedure | Put a room into a stay for the dates and mark the room OCCUPIED. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listStayRooms` | Procedure | Room ids of a stay's active assignments, in id order. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_endStay` | Procedure | End a stay now and deactivate its assignments. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_lockBlockByRequest` | Procedure | Block found by request id, row locked until the transaction ends (no row when unknown). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_insertBlock` | Procedure | Save a new maintenance block. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_getBlock` | Procedure | One block by id with its room's type (no row when unknown). | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_listBlocks` | Procedure | Up to 100 blocks, newest first. @status NULL = all statuses. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_setBlockStatus` | Procedure | Set a block's status. @only_from NULL = from any status. Returns affected = 0 when the block was not in that status. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_setBlockRequestId` | Procedure | Give a block a fresh request id. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_rejectBlock` | Procedure | Reject a PENDING or CONFLICT block. @note NULL = no note. Returns affected = 0 when it was in neither status. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_approveBlock` | Procedure | Approve a block. An earlier decider and decision time are kept. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_reportCleaningMinutes` | Procedure | Minutes from task creation to inspection for tasks finished from @from_date through @to_date. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_reportQueueSeconds` | Procedure | Seconds from joining the queue to check-in for stays checked in from @from_date through @to_date. | `005_procs_rooms_stay_maintenance.sql` |
| `usp_roomops_reportRoomCounts` | Procedure | Number of rooms in each readiness state. | `005_procs_rooms_stay_maintenance.sql` |

### portal (65 objects)

| Object | Kind | What it does | File |
|---|---|---|---|
| `vw_AccountSummary` | View | Accounts for the user list. Never exposes the password hash or the second-factor secret (has_totp says if one is set). | `003_procs_accounts_privacy.sql` |
| `vw_PrivacyRequest` | View | Privacy requests joined to the account that filed them, with an overdue flag. | `003_procs_accounts_privacy.sql` |
| `vw_EmailLog` | View | Sent emails without the body (subject and status only). | `003_procs_accounts_privacy.sql` |
| `usp_account_insert` | Procedure | Add an account. A duplicate email raises the unique-key error (2627) for the caller to handle. | `003_procs_accounts_privacy.sql` |
| `usp_account_findActiveByEmail` | Procedure | The id of the active account with this email (no row when there is none). | `003_procs_accounts_privacy.sql` |
| `usp_account_getCredentials` | Procedure | Password hash, email and role of one account (no row when unknown). | `003_procs_accounts_privacy.sql` |
| `usp_account_setPassword` | Procedure | Set a new password hash. @clear_lock = 1 also clears failed sign-ins and any lockout (used by a reset). | `003_procs_accounts_privacy.sql` |
| `usp_account_updateProfile` | Procedure | Change name, phone and marketing consent. | `003_procs_accounts_privacy.sql` |
| `usp_account_list` | Procedure | Up to 200 accounts that are not anonymized, staff first in role order, guests last, then by name. @role and @like are optional filters (NULL = no filter). @like is a ready LIKE pattern that uses \ as its escape. | `003_procs_accounts_privacy.sql` |
| `usp_account_get` | Procedure | id, email and role of one account that is not anonymized (no row when unknown). | `003_procs_accounts_privacy.sql` |
| `usp_account_setRole` | Procedure | Change an account's role. | `003_procs_accounts_privacy.sql` |
| `usp_account_setActive` | Procedure | Enable or disable an account, and clear failed sign-ins and any lockout. | `003_procs_accounts_privacy.sql` |
| `usp_account_setTotp` | Procedure | Replace the second-factor secret. | `003_procs_accounts_privacy.sql` |
| `usp_account_labels` | Procedure | Account names for audit rows. @ids is a JSON array of account ids. | `003_procs_accounts_privacy.sql` |
| `usp_account_countActiveIt` | Procedure | How many active IT accounts exist (one value, column n). | `003_procs_accounts_privacy.sql` |
| `usp_account_emailTaken` | Procedure | True (one row) when another account already uses this email. | `003_procs_accounts_privacy.sql` |
| `usp_account_correct` | Procedure | Apply a corrected name, email and phone. | `003_procs_accounts_privacy.sql` |
| `usp_account_anonymize` | Procedure | Remove identity from an account for a deletion request: blank the emails sent to it, anonymize the account, and wipe the details of its correction requests. The caller wraps this in one transaction. | `003_procs_accounts_privacy.sql` |
| `usp_account_getExport` | Procedure | The account fields a guest gets in an access request (no row when unknown). | `003_procs_accounts_privacy.sql` |
| `usp_account_createAdmin` | Procedure | The IT lead created by bin/ensure-admin.php: the account row plus its audit entry. | `003_procs_accounts_privacy.sql` |
| `usp_reset_create` | Procedure | Store a one-time reset token (hash only) that expires @ttl_seconds from now. | `003_procs_accounts_privacy.sql` |
| `usp_reset_isValid` | Procedure | One row when the token exists, is unused and has not expired. | `003_procs_accounts_privacy.sql` |
| `usp_reset_lock` | Procedure | Lock and return the account behind a valid reset token (no row when the token is bad or the account is disabled). | `003_procs_accounts_privacy.sql` |
| `usp_reset_markUsed` | Procedure | Mark every unused reset token of an account as used. | `003_procs_accounts_privacy.sql` |
| `usp_session_dropForAccount` | Procedure | Sign an account out everywhere. Session data is the serialized PHP session, which holds the account id as plain text. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_countOpen` | Procedure | How many open or in-review requests of this type the account has (one value, column n). | `003_procs_accounts_privacy.sql` |
| `usp_privacy_file` | Procedure | File a request. It is due 30 days from now. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_listForAccount` | Procedure | All requests of one account, newest first. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_getResult` | Procedure | The data file of a completed access request, only for its owner (no row otherwise). | `003_procs_accounts_privacy.sql` |
| `usp_privacy_queue` | Procedure | Up to 100 requests by due date. @status NULL = open and in review. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_get` | Procedure | One request with the filer's email and name (no row when unknown). | `003_procs_accounts_privacy.sql` |
| `usp_privacy_startReview` | Procedure | Move an open request to review. affected = 0 when it was not open. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_reject` | Procedure | Reject an open or in-review request with a note. affected = 0 when it was already closed. | `003_procs_accounts_privacy.sql` |
| `usp_privacy_complete` | Procedure | Close a request as completed, with an optional note and the access-request data file. | `003_procs_accounts_privacy.sql` |
| `usp_email_listFor` | Procedure | Emails sent to one address, oldest first (subject and status only). | `003_procs_accounts_privacy.sql` |
| `usp_email_listRecent` | Procedure | The newest emails, subject and status only, never the body. @limit is clamped by the caller. | `003_procs_accounts_privacy.sql` |
| `usp_oversight_jobCounts` | Procedure | Notification jobs counted by status (columns status, n). | `003_procs_accounts_privacy.sql` |
| `usp_oversight_jobsNeedingAttention` | Procedure | The 20 newest jobs that failed or were retried while still pending. | `003_procs_accounts_privacy.sql` |
| `usp_oversight_emailCounts` | Procedure | Emails of the last 7 days counted by status (columns status, n). | `003_procs_accounts_privacy.sql` |
| `usp_oversight_oldestPendingJob` | Procedure | When the oldest pending job was created (one value, NULL when none are pending). | `003_procs_accounts_privacy.sql` |
| `vw_AuthAccount` | View | Accounts with the columns sign-in and the page header need. | `004_procs_auth_notifications.sql` |
| `vw_PendingNotificationJob` | View | Email jobs waiting to be sent, oldest first by created_at (the caller orders). | `004_procs_auth_notifications.sql` |
| `vw_ReportDaily` | View | Daily reporting copy with the names the API sends. | `004_procs_auth_notifications.sql` |
| `usp_health_ping` | Procedure | Proves the database answers. | `004_procs_auth_notifications.sql` |
| `usp_session_read` | Procedure | Session data when the session was updated after @min_updated_at (no row when missing or expired). | `004_procs_auth_notifications.sql` |
| `usp_session_write` | Procedure | Creates or replaces a session. | `004_procs_auth_notifications.sql` |
| `usp_session_destroy` | Procedure | Deletes one session. | `004_procs_auth_notifications.sql` |
| `usp_session_purge` | Procedure | Deletes sessions last updated before @before. Returns how many. | `004_procs_auth_notifications.sql` |
| `usp_rateLimit_hit` | Procedure | Counts one hit in the bucket's current window and returns the total hits in that window. | `004_procs_auth_notifications.sql` |
| `usp_rateLimit_purge` | Procedure | Deletes counters from windows that started before @before. | `004_procs_auth_notifications.sql` |
| `usp_auth_getLogin` | Procedure | Account row for sign-in, with locked = 1 while the lock has not run out. | `004_procs_auth_notifications.sql` |
| `usp_auth_recordFailure` | Procedure | Counts one failed sign-in and locks the account for @lock_minutes once it reaches @max_fails. "+ 2" because T-SQL reads the old failed_logins on the right of SET, so the lock lands on the same failure as before. | `004_procs_auth_notifications.sql` |
| `usp_auth_recordSuccess` | Procedure | Clears the failure count and the lock, and stamps the last sign-in time. | `004_procs_auth_notifications.sql` |
| `usp_auth_getAccount` | Procedure | Account id, email, role, name and phone for one account. | `004_procs_auth_notifications.sql` |
| `usp_auth_getProfile` | Procedure | Same as usp_auth_getAccount plus the marketing consent flag (page header and account page). | `004_procs_auth_notifications.sql` |
| `usp_auth_getActiveContact` | Procedure | Email and name of an active account (for the password reset email). | `004_procs_auth_notifications.sql` |
| `usp_auth_purgeResets` | Procedure | Deletes password reset rows that expired more than a day ago. | `004_procs_auth_notifications.sql` |
| `usp_notification_queue` | Procedure | Queues one email job, due now. | `004_procs_auth_notifications.sql` |
| `usp_notification_listDue` | Procedure | Up to @limit pending jobs that are due, oldest first. | `004_procs_auth_notifications.sql` |
| `usp_notification_markSent` | Procedure | Marks a job sent and clears its payload and last error. | `004_procs_auth_notifications.sql` |
| `usp_notification_markFailed` | Procedure | Records a failed attempt: new status (PENDING or FAILED), attempt count, error, and the next try in @delay_seconds. | `004_procs_auth_notifications.sql` |
| `usp_emailLog_isDone` | Procedure | One row when the job already has a SENT or LOGGED email in the log, none otherwise. | `004_procs_auth_notifications.sql` |
| `usp_emailLog_insert` | Procedure | Adds one line to the email log. | `004_procs_auth_notifications.sql` |
| `usp_reportDaily_add` | Procedure | Adds to the day's counters (a missing day starts at zero). Pass only the counters that change. | `004_procs_auth_notifications.sql` |
| `usp_reportDaily_list` | Procedure | The latest 30 days of the reporting copy, newest first. | `004_procs_auth_notifications.sql` |
