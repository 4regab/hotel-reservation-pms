-- Booking: stored procedures and views for guests, reservations, nightly stock, allocations and maintenance blocks.
-- The PHP code (GuestController, BookingRepository, InventoryAllocator, BookingWorkflow, ReservationController) calls these
-- through Lib\Db::call. A procedure never starts or ends a transaction: the caller (Lib\Tx::run) owns it.
-- Re-runnable: every object uses CREATE OR ALTER. The service user gets EXECUTE and SELECT from scripts/migrate.php.

-- ---------------------------------------------------------------------------
-- Views (read side)
-- ---------------------------------------------------------------------------

-- Every room type with all its columns.
CREATE OR ALTER VIEW dbo.vw_BookingRoomType AS
SELECT room_type_id, name, capacity, nightly_rate_minor, currency, slug, tagline, description, max_guests, bed_type, size_sqm, amenities, image, active
  FROM dbo.room_type;
GO

-- One row per guest with the booking count and the last stay that was started or finished.
CREATE OR ALTER VIEW dbo.vw_BookingGuestSummary AS
SELECT g.guest_id, g.full_name, g.email, g.phone,
       CASE WHEN g.account_id IS NOT NULL THEN 1 ELSE 0 END AS has_account,
       g.account_id, g.created_at, g.merged_into, g.anonymized_at,
       (SELECT COUNT(*) FROM dbo.reservation r WHERE r.guest_id = g.guest_id) AS bookings,
       (SELECT MAX(r.check_out) FROM dbo.reservation r WHERE r.guest_id = g.guest_id AND r.status IN (N'CHECKED_OUT', N'CHECKED_IN')) AS last_stay
  FROM dbo.guest g;
GO

-- One row per reservation with the guest's contact details, the guest's account and the room type name.
CREATE OR ALTER VIEW dbo.vw_BookingReservation AS
SELECT r.reservation_id, r.guest_id, r.room_type_id, r.check_in, r.check_out, r.quantity, r.status, r.guarantee, r.quote_minor, r.nightly_minor, r.currency,
       r.free_cancel_hours, r.hold_expires_at, r.version, r.created_at, r.updated_at, r.code, r.source, r.adults, r.special_requests, r.guarantee_requested,
       r.paid_at, r.confirmed_at, r.cancelled_at, r.no_show_at, r.penalty_minor,
       g.account_id AS guest_account_id, g.full_name, g.email, g.phone, t.name AS type_name, t.slug AS type_slug
  FROM dbo.reservation r
  JOIN dbo.guest g ON g.guest_id = r.guest_id
  JOIN dbo.room_type t ON t.room_type_id = r.room_type_id;
GO

-- Nightly stock counters for each room type and date.
CREATE OR ALTER VIEW dbo.vw_BookingInventoryNight AS
SELECT room_type_id, stay_date, capacity, blocked, held, confirmed, ota_remaining
  FROM dbo.inventory_night;
GO

-- ---------------------------------------------------------------------------
-- Room types
-- ---------------------------------------------------------------------------

-- All room types, cheapest first.
CREATE OR ALTER PROCEDURE dbo.usp_booking_listRoomTypes
AS
BEGIN
  SET NOCOUNT ON;
  SELECT * FROM dbo.vw_BookingRoomType ORDER BY nightly_rate_minor;
END;
GO

-- One room type by id (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_booking_getRoomType
  @room_type_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT * FROM dbo.vw_BookingRoomType WHERE room_type_id = @room_type_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Guests
-- ---------------------------------------------------------------------------

-- Search guests that are neither merged nor deleted. @like is a ready LIKE pattern with backslash escapes, or NULL for no filter. At most 100 rows.
CREATE OR ALTER PROCEDURE dbo.usp_guest_search
  @like NVARCHAR(MAX) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (100) guest_id, full_name, email, phone, has_account, created_at, bookings, last_stay
    FROM dbo.vw_BookingGuestSummary
   WHERE merged_into IS NULL AND anonymized_at IS NULL
     AND (@like IS NULL OR full_name LIKE @like ESCAPE N'\' OR email LIKE @like ESCAPE N'\' OR phone LIKE @like ESCAPE N'\')
   ORDER BY full_name;
END;
GO

-- Every guest that is neither merged nor deleted, with the booking count (for duplicate review).
CREATE OR ALTER PROCEDURE dbo.usp_guest_listActive
AS
BEGIN
  SET NOCOUNT ON;
  SELECT guest_id, full_name, email, phone, has_account, created_at, bookings
    FROM dbo.vw_BookingGuestSummary
   WHERE merged_into IS NULL AND anonymized_at IS NULL;
END;
GO

-- The oldest live guest with this email (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_guest_findByEmail
  @email NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) guest_id FROM dbo.guest WHERE email = @email AND merged_into IS NULL AND anonymized_at IS NULL ORDER BY created_at;
END;
GO

-- The guest id for a portal account (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_guest_findByAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT guest_id FROM dbo.guest WHERE account_id = @account_id;
END;
GO

-- Whether a guest id exists (one row when it does).
CREATE OR ALTER PROCEDURE dbo.usp_guest_exists
  @guest_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS found FROM dbo.guest WHERE guest_id = @guest_id;
END;
GO

-- Create a guest. @account_id is NULL for a walk-in without a portal account.
CREATE OR ALTER PROCEDURE dbo.usp_guest_create
  @guest_id   NCHAR(36),
  @account_id NCHAR(36) = NULL,
  @full_name  NVARCHAR(120),
  @email      NVARCHAR(160),
  @phone      NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.guest (guest_id, account_id, full_name, email, phone, created_at)
  VALUES (@guest_id, @account_id, @full_name, @email, @phone, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- The name, email and phone of one guest (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_guest_getContact
  @guest_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT full_name, email, phone FROM dbo.guest WHERE guest_id = @guest_id;
END;
GO

-- Lock two guest rows for a merge (UPDLOCK, in id order) and return them. Fewer than two rows back means one id is unknown.
CREATE OR ALTER PROCEDURE dbo.usp_guest_lockPair
  @guest_id_a NCHAR(36),
  @guest_id_b NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT * FROM dbo.guest WITH (UPDLOCK, ROWLOCK) WHERE guest_id IN (@guest_id_a, @guest_id_b) ORDER BY guest_id;
END;
GO

-- Move every reservation from one guest to another. Returns the number moved as affected.
CREATE OR ALTER PROCEDURE dbo.usp_guest_moveReservations
  @from_guest_id NCHAR(36),
  @to_guest_id   NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation SET guest_id = @to_guest_id WHERE guest_id = @from_guest_id;
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Retire a merged guest: point it at the kept guest and release its portal account.
CREATE OR ALTER PROCEDURE dbo.usp_guest_retireMerged
  @guest_id    NCHAR(36),
  @merged_into NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.guest SET merged_into = @merged_into, account_id = NULL WHERE guest_id = @guest_id;
END;
GO

-- Attach a portal account to a guest.
CREATE OR ALTER PROCEDURE dbo.usp_guest_setAccount
  @guest_id   NCHAR(36),
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.guest SET account_id = @account_id WHERE guest_id = @guest_id;
END;
GO

-- Record a guest merge in the merge history.
CREATE OR ALTER PROCEDURE dbo.usp_guest_recordMerge
  @merge_id           NCHAR(36),
  @kept_guest_id      NCHAR(36),
  @merged_guest_id    NCHAR(36),
  @reservations_moved INT,
  @merged_by          NCHAR(36),
  @note               NVARCHAR(200) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.guest_merge (merge_id, kept_guest_id, merged_guest_id, reservations_moved, merged_by, note, created_at)
  VALUES (@merge_id, @kept_guest_id, @merged_guest_id, @reservations_moved, @merged_by, @note, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- The guest record for a portal account, for a privacy access request (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_guest_getByAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT guest_id, full_name, email, phone, created_at FROM dbo.guest WHERE account_id = @account_id;
END;
GO

-- Correct a guest's name, email and phone by portal account. Returns affected = rows whose text really changed
-- (the BIN2 comparisons skip rows that are identical; SQL Server would otherwise count every matched row).
CREATE OR ALTER PROCEDURE dbo.usp_guest_correctByAccount
  @account_id NCHAR(36),
  @full_name  NVARCHAR(120),
  @email      NVARCHAR(160),
  @phone      NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.guest SET full_name = @full_name, email = @email, phone = @phone
   WHERE account_id = @account_id AND anonymized_at IS NULL
     AND (full_name COLLATE Latin1_General_100_BIN2 <> @full_name
          OR email COLLATE Latin1_General_100_BIN2 <> @email
          OR phone COLLATE Latin1_General_100_BIN2 <> @phone);
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Lock the guest row of a portal account (UPDLOCK) and return its id (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_guest_lockByAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT guest_id FROM dbo.guest WITH (UPDLOCK, ROWLOCK) WHERE account_id = @account_id;
END;
GO

-- How many held, confirmed or in-house reservations a guest still has.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_countLiveForGuest
  @guest_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT COUNT(*) AS live FROM dbo.reservation WHERE guest_id = @guest_id AND status IN (N'HELD', N'CONFIRMED', N'CHECKED_IN');
END;
GO

-- Remove a guest's identity (name, email, phone, account) and clear the special requests on their reservations.
CREATE OR ALTER PROCEDURE dbo.usp_guest_anonymize
  @guest_id NCHAR(36),
  @email    NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.guest SET full_name = N'Deleted guest', email = @email, phone = N'', account_id = NULL, anonymized_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE guest_id = @guest_id;
  UPDATE dbo.reservation SET special_requests = NULL WHERE guest_id = @guest_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Reservations
-- ---------------------------------------------------------------------------

-- One reservation by id with the guest's account and contact details (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_reservation_getById
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT * FROM dbo.vw_BookingReservation WHERE reservation_id = @reservation_id;
END;
GO

-- One reservation by id, locked with UPDLOCK (take this before any inventory locks). Only the reservation row is locked.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_lock
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT r.*, g.account_id AS guest_account_id
    FROM dbo.reservation r WITH (UPDLOCK, ROWLOCK)
    JOIN dbo.guest g ON g.guest_id = r.guest_id
   WHERE r.reservation_id = @reservation_id;
END;
GO

-- The reservation id for a confirmation code (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_reservation_findIdByCode
  @code NVARCHAR(10)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT reservation_id FROM dbo.reservation WHERE code = @code;
END;
GO

-- The confirmation code of one reservation.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_getCode
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT code FROM dbo.reservation WHERE reservation_id = @reservation_id;
END;
GO

-- List reservations (newest first, or by arrival). Every filter is optional: NULL means no filter.
-- @statuses and @ids are comma lists; an empty @ids ('') matches nothing. @like is a ready LIKE pattern with backslash escapes.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_search
  @guest_account_id     NCHAR(36)     = NULL,
  @like                 NVARCHAR(MAX) = NULL,
  @statuses             NVARCHAR(200) = NULL,
  @check_in             DATE          = NULL,
  @check_out            DATE          = NULL,
  @arrive_from          DATE          = NULL,
  @arrive_to            DATE          = NULL,
  @ids                  NVARCHAR(MAX) = NULL,
  @guarantee_requested  BIT           = NULL,
  @source               NVARCHAR(7)   = NULL,
  @order_by_arrival     BIT           = 0,
  @row_limit            INT           = 100
AS
BEGIN
  SET NOCOUNT ON;
  SELECT *
    FROM dbo.vw_BookingReservation r
   WHERE (@guest_account_id IS NULL OR r.guest_account_id = @guest_account_id)
     AND (@like IS NULL OR r.code LIKE @like ESCAPE N'\' OR r.full_name LIKE @like ESCAPE N'\' OR r.email LIKE @like ESCAPE N'\')
     AND (@statuses IS NULL OR r.status IN (SELECT value FROM STRING_SPLIT(@statuses, N',')))
     AND (@check_in IS NULL OR r.check_in = @check_in)
     AND (@check_out IS NULL OR r.check_out = @check_out)
     AND (@arrive_from IS NULL OR r.check_in >= @arrive_from)
     AND (@arrive_to IS NULL OR r.check_in <= @arrive_to)
     AND (@ids IS NULL OR r.reservation_id IN (SELECT value FROM STRING_SPLIT(@ids, N',')))
     AND (@guarantee_requested IS NULL OR (r.guarantee_requested = 1 AND r.status = N'HELD' AND r.hold_expires_at > DATETRUNC(SECOND, SYSUTCDATETIME())))
     AND (@source IS NULL OR r.source = @source)
   ORDER BY CASE WHEN @order_by_arrival = 1 THEN r.check_in END ASC,
            CASE WHEN @order_by_arrival = 1 THEN r.created_at END ASC,
            CASE WHEN @order_by_arrival = 0 THEN r.created_at END DESC
  OFFSET 0 ROWS FETCH NEXT @row_limit ROWS ONLY
  OPTION (RECOMPILE);
END;
GO

-- The reservations of one guest with the room type name, oldest first (privacy export).
CREATE OR ALTER PROCEDURE dbo.usp_reservation_listForGuest
  @guest_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT code, status, check_in, check_out, quantity, adults, quote_minor, currency, special_requests, created_at, type_name AS room_type
    FROM dbo.vw_BookingReservation
   WHERE guest_id = @guest_id
   ORDER BY created_at;
END;
GO

-- Create a reservation in status HELD with no guarantee yet, version 1.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_insert
  @reservation_id    NCHAR(36),
  @code              NVARCHAR(10),
  @guest_id          NCHAR(36),
  @room_type_id      NCHAR(36),
  @check_in          DATE,
  @check_out         DATE,
  @quantity          TINYINT,
  @quote_minor       BIGINT,
  @nightly_minor     INT,
  @currency          NCHAR(3),
  @free_cancel_hours INT,
  @hold_expires_at   DATETIME2(0),
  @source            NVARCHAR(7),
  @adults            TINYINT,
  @special_requests  NVARCHAR(300) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.reservation (reservation_id, code, guest_id, room_type_id, check_in, check_out, quantity, status, guarantee, quote_minor, nightly_minor, currency,
                               free_cancel_hours, hold_expires_at, source, adults, special_requests, version, created_at, updated_at)
  VALUES (@reservation_id, @code, @guest_id, @room_type_id, @check_in, @check_out, @quantity, N'HELD', N'NONE', @quote_minor, @nightly_minor, @currency,
          @free_cancel_hours, @hold_expires_at, @source, @adults, @special_requests, 1, DATETRUNC(SECOND, SYSUTCDATETIME()), DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Record when a reservation became CONFIRMED and, when known, when payment arrived. Clears the guarantee request.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_markConfirmed
  @reservation_id NCHAR(36),
  @paid_at        DATETIME2(0) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation
     SET confirmed_at = DATETRUNC(SECOND, SYSUTCDATETIME()), paid_at = COALESCE(@paid_at, paid_at), guarantee_requested = 0
   WHERE reservation_id = @reservation_id;
END;
GO

-- Set the status (and the guarantee when given), add one to the version and stamp the update time.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_setStatus
  @reservation_id NCHAR(36),
  @status         NVARCHAR(11),
  @guarantee      NVARCHAR(12) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation
     SET status = @status, guarantee = COALESCE(@guarantee, guarantee), version = version + 1, updated_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE reservation_id = @reservation_id;
END;
GO

-- Stamp the cancellation time. The penalty is saved only when given (a cancelled hold has none).
CREATE OR ALTER PROCEDURE dbo.usp_reservation_markCancelled
  @reservation_id NCHAR(36),
  @penalty_minor  BIGINT = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation
     SET cancelled_at = DATETRUNC(SECOND, SYSUTCDATETIME()), penalty_minor = COALESCE(@penalty_minor, penalty_minor)
   WHERE reservation_id = @reservation_id;
END;
GO

-- Stamp the no-show time and save the penalty.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_markNoShow
  @reservation_id NCHAR(36),
  @penalty_minor  BIGINT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation SET no_show_at = DATETRUNC(SECOND, SYSUTCDATETIME()), penalty_minor = @penalty_minor WHERE reservation_id = @reservation_id;
END;
GO

-- Flag a hold as waiting for a manager's pay-at-hotel guarantee.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_flagGuaranteeRequested
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.reservation SET guarantee_requested = 1 WHERE reservation_id = @reservation_id;
END;
GO

-- Ids of holds whose time is up, oldest first.
CREATE OR ALTER PROCEDURE dbo.usp_reservation_listExpiredHoldIds
  @row_limit INT = 100
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (@row_limit) reservation_id
    FROM dbo.reservation
   WHERE status = N'HELD' AND hold_expires_at <= DATETRUNC(SECOND, SYSUTCDATETIME())
   ORDER BY hold_expires_at;
END;
GO

-- ---------------------------------------------------------------------------
-- Nightly stock
-- ---------------------------------------------------------------------------

-- Create the stock row for one room type and night when it is missing (starts with nothing blocked, held or confirmed).
CREATE OR ALTER PROCEDURE dbo.usp_inventory_ensureNight
  @room_type_id NCHAR(36),
  @stay_date    DATE
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.inventory_night (room_type_id, stay_date, capacity, blocked, held, confirmed, ota_remaining)
  SELECT room_type_id, @stay_date, capacity, 0, 0, 0, 0 FROM dbo.room_type WHERE room_type_id = @room_type_id
     AND NOT EXISTS (SELECT 1 FROM dbo.inventory_night WITH (UPDLOCK, HOLDLOCK) WHERE room_type_id = @room_type_id AND stay_date = @stay_date);
END;
GO

-- Stock rows for a room type between two dates, in date order. @lock = 1 takes UPDLOCK on them (inside a transaction); 0 reads without locks.
CREATE OR ALTER PROCEDURE dbo.usp_inventory_getNights
  @room_type_id NCHAR(36),
  @from_date    DATE,
  @to_date      DATE,
  @lock         BIT = 0
AS
BEGIN
  SET NOCOUNT ON;
  IF @lock = 1
    SELECT stay_date, capacity, blocked, held, confirmed, ota_remaining
      FROM dbo.inventory_night WITH (UPDLOCK, ROWLOCK)
     WHERE room_type_id = @room_type_id AND stay_date BETWEEN @from_date AND @to_date
     ORDER BY stay_date;
  ELSE
    SELECT stay_date, capacity, blocked, held, confirmed, ota_remaining
      FROM dbo.vw_BookingInventoryNight
     WHERE room_type_id = @room_type_id AND stay_date BETWEEN @from_date AND @to_date
     ORDER BY stay_date;
END;
GO

-- Save the four stock counters for one room type and night.
CREATE OR ALTER PROCEDURE dbo.usp_inventory_saveNight
  @room_type_id  NCHAR(36),
  @stay_date     DATE,
  @blocked       INT,
  @held          INT,
  @confirmed     INT,
  @ota_remaining INT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.inventory_night
     SET blocked = @blocked, held = @held, confirmed = @confirmed, ota_remaining = @ota_remaining
   WHERE room_type_id = @room_type_id AND stay_date = @stay_date;
END;
GO

-- ---------------------------------------------------------------------------
-- Allocations
-- ---------------------------------------------------------------------------

-- Save one night of a reservation's allocation: update it when it exists, insert it otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_allocation_upsert
  @reservation_id NCHAR(36),
  @stay_date      DATE,
  @room_type_id   NCHAR(36),
  @quantity       TINYINT,
  @state          NVARCHAR(9)
AS
BEGIN
  SET NOCOUNT ON;
  MERGE dbo.allocation WITH (HOLDLOCK) AS d
  USING (SELECT @reservation_id AS reservation_id, @stay_date AS stay_date, @room_type_id AS room_type_id, @quantity AS quantity, @state AS state) AS s
     ON d.reservation_id = s.reservation_id AND d.stay_date = s.stay_date
   WHEN MATCHED THEN UPDATE SET quantity = s.quantity, state = s.state
   WHEN NOT MATCHED THEN INSERT (reservation_id, stay_date, room_type_id, quantity, state) VALUES (s.reservation_id, s.stay_date, s.room_type_id, s.quantity, s.state);
END;
GO

-- Set the state of every allocation night of a reservation.
CREATE OR ALTER PROCEDURE dbo.usp_allocation_setState
  @reservation_id NCHAR(36),
  @state          NVARCHAR(9)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.allocation SET state = @state WHERE reservation_id = @reservation_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Maintenance capacity blocks
-- ---------------------------------------------------------------------------

-- Record a maintenance block once per request id. Returns affected = 1 when saved, 0 when the request id was already used.
CREATE OR ALTER PROCEDURE dbo.usp_maintenance_recordBlock
  @request_id   NCHAR(36),
  @room_type_id NCHAR(36),
  @start_date   DATE,
  @end_date     DATE
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.maintenance_capacity (request_id, room_type_id, start_date, end_date, created_at)
  SELECT @request_id, @room_type_id, @start_date, @end_date, DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE NOT EXISTS (SELECT 1 FROM dbo.maintenance_capacity WITH (UPDLOCK, HOLDLOCK) WHERE request_id = @request_id);
  SELECT @@ROWCOUNT AS affected;
END;
GO
