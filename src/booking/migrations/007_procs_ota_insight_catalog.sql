-- Booking: stored procedures and views for OTA allotments and imports, the manager report, and room type updates.
-- Used by OtaService, InsightController and CatalogController. A procedure never starts or ends a transaction:
-- the caller (Lib\Tx::run) owns it. Row locks (UPDLOCK) stay inside the procedure that reads the row.

-- ---------------------------------------------------------------------------
-- Views
-- ---------------------------------------------------------------------------

-- Every column of an OTA allotment request.
CREATE OR ALTER VIEW dbo.vw_OtaAllotment AS
SELECT allotment_id, partner_id, room_type_id, start_date, end_date, quantity, status, requested_by, decided_by, decided_at, created_at
  FROM ota_allotment;
GO

-- An allotment with its partner and room type names and how many room-nights are reserved and already used.
CREATE OR ALTER VIEW dbo.vw_OtaAllotmentUsage AS
SELECT a.allotment_id AS allotmentId, a.partner_id AS partnerId, p.name AS partnerName, a.room_type_id AS roomTypeId, t.name AS roomTypeName,
       a.start_date AS startDate, a.end_date AS endDate, a.quantity, a.status, a.requested_by AS requestedBy, a.created_at AS createdAt,
       COALESCE((SELECT SUM(n.used) FROM ota_allotment_night n WHERE n.allotment_id = a.allotment_id), 0) AS usedNights,
       COALESCE((SELECT SUM(n.reserved) FROM ota_allotment_night n WHERE n.allotment_id = a.allotment_id), 0) AS reservedNights
  FROM ota_allotment a
  JOIN ota_partner p ON p.partner_id = a.partner_id
  JOIN room_type t ON t.room_type_id = a.room_type_id;
GO

-- One row per CSV import with its counts.
CREATE OR ALTER VIEW dbo.vw_OtaImport AS
SELECT import_id AS importId, partner_id AS partnerId, filename, rows_total AS rowsTotal, rows_ok AS rowsOk, rows_rejected AS rowsRejected, created_at AS createdAt
  FROM ota_import;
GO

-- One row per confirmed room-night that counts as sold (booking confirmed, in house or finished), with the saved nightly price.
CREATE OR ALTER VIEW dbo.vw_SoldRoomNight AS
SELECT a.stay_date, a.room_type_id, a.quantity, r.nightly_minor
  FROM allocation a
  JOIN reservation r ON r.reservation_id = a.reservation_id
 WHERE a.state = 'CONFIRMED' AND r.status IN ('CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT');
GO

-- Guests that are still in use (not merged into another record and not anonymised).
CREATE OR ALTER VIEW dbo.vw_ActiveGuest AS
SELECT guest_id, full_name, email, phone, CASE WHEN account_id IS NOT NULL THEN 1 ELSE 0 END AS has_account, created_at
  FROM guest
 WHERE merged_into IS NULL AND anonymized_at IS NULL;
GO

-- ---------------------------------------------------------------------------
-- OTA allotments
-- ---------------------------------------------------------------------------

-- Lists the active OTA partners by name.
CREATE OR ALTER PROCEDURE dbo.usp_ota_listPartners
AS
BEGIN
  SET NOCOUNT ON;
  SELECT partner_id AS partnerId, name FROM ota_partner WHERE active = 1 ORDER BY name;
END;
GO

-- Returns one row when the partner exists and is active, none otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_ota_partnerExists
  @partner_id NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS found FROM ota_partner WHERE partner_id = @partner_id AND active = 1;
END;
GO

-- Lists the newest 100 allotments with usage. A NULL status means no filter.
CREATE OR ALTER PROCEDURE dbo.usp_ota_listAllotments
  @status NVARCHAR(8) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (100) allotmentId, partnerId, partnerName, roomTypeId, roomTypeName, startDate, endDate, quantity, status, requestedBy, createdAt, usedNights, reservedNights
    FROM vw_OtaAllotmentUsage
   WHERE @status IS NULL OR status = @status
   ORDER BY createdAt DESC;
END;
GO

-- Saves a new allotment request in the PENDING state.
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertAllotment
  @allotment_id NCHAR(36),
  @partner_id   NVARCHAR(40),
  @room_type_id NCHAR(36),
  @start_date   DATE,
  @end_date     DATE,
  @quantity     INT,
  @requested_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO ota_allotment (allotment_id, partner_id, room_type_id, start_date, end_date, quantity, status, requested_by, created_at)
  VALUES (@allotment_id, @partner_id, @room_type_id, @start_date, @end_date, @quantity, 'PENDING', @requested_by, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Returns one allotment row, or none when the id is unknown.
CREATE OR ALTER PROCEDURE dbo.usp_ota_getAllotment
  @allotment_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT allotment_id, partner_id, room_type_id, start_date, end_date, quantity, status, requested_by, decided_by, decided_at, created_at
    FROM vw_OtaAllotment
   WHERE allotment_id = @allotment_id;
END;
GO

-- Locks the allotment row for update and returns its current status.
CREATE OR ALTER PROCEDURE dbo.usp_ota_lockAllotment
  @allotment_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status FROM ota_allotment WITH (UPDLOCK, ROWLOCK) WHERE allotment_id = @allotment_id;
END;
GO

-- Marks a pending allotment as REJECTED. Does nothing if it was already decided.
CREATE OR ALTER PROCEDURE dbo.usp_ota_rejectAllotment
  @allotment_id NCHAR(36),
  @decided_by   NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE ota_allotment SET status = 'REJECTED', decided_by = @decided_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE allotment_id = @allotment_id AND status = 'PENDING';
END;
GO

-- Marks an allotment as APPROVED.
CREATE OR ALTER PROCEDURE dbo.usp_ota_approveAllotment
  @allotment_id NCHAR(36),
  @decided_by   NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE ota_allotment SET status = 'APPROVED', decided_by = @decided_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE allotment_id = @allotment_id;
END;
GO

-- Marks an allotment as RETURNED (unused stock goes back to direct sales).
CREATE OR ALTER PROCEDURE dbo.usp_ota_returnAllotment
  @allotment_id NCHAR(36),
  @decided_by   NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE ota_allotment SET status = 'RETURNED', decided_by = @decided_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE allotment_id = @allotment_id;
END;
GO

-- Lists the reserved and used count of every night of an allotment, oldest night first.
CREATE OR ALTER PROCEDURE dbo.usp_ota_listAllotmentNights
  @allotment_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT stay_date, reserved, used FROM ota_allotment_night WHERE allotment_id = @allotment_id ORDER BY stay_date;
END;
GO

-- Adds one night to an allotment with its reserved count and nothing used yet.
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertAllotmentNight
  @allotment_id NCHAR(36),
  @stay_date    DATE,
  @reserved     INT
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO ota_allotment_night (allotment_id, stay_date, reserved, used) VALUES (@allotment_id, @stay_date, @reserved, 0);
END;
GO

-- Gives the unused part of one night back: reserved becomes equal to used.
CREATE OR ALTER PROCEDURE dbo.usp_ota_releaseAllotmentNight
  @allotment_id NCHAR(36),
  @stay_date    DATE
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE ota_allotment_night SET reserved = used WHERE allotment_id = @allotment_id AND stay_date = @stay_date;
END;
GO

-- ---------------------------------------------------------------------------
-- OTA imports
-- ---------------------------------------------------------------------------

-- Lists the 30 newest imports.
CREATE OR ALTER PROCEDURE dbo.usp_ota_listImports
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (30) importId, partnerId, filename, rowsTotal, rowsOk, rowsRejected, createdAt FROM vw_OtaImport ORDER BY createdAt DESC;
END;
GO

-- Saves the summary and row-by-row report of one import. The report is JSON text.
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertImport
  @import_id     NCHAR(36),
  @partner_id    NVARCHAR(40),
  @filename      NVARCHAR(120),
  @rows_total    INT,
  @rows_ok       INT,
  @rows_rejected INT,
  @report        NVARCHAR(MAX),
  @imported_by   NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO ota_import (import_id, partner_id, filename, rows_total, rows_ok, rows_rejected, report, imported_by, created_at)
  VALUES (@import_id, @partner_id, @filename, @rows_total, @rows_ok, @rows_rejected, CAST(@report AS JSON), @imported_by, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Returns one row when this partner booking id was already imported, none otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_ota_bookingExists
  @partner_id          NVARCHAR(40),
  @external_booking_id NVARCHAR(80)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS found FROM ota_booking WHERE partner_id = @partner_id AND external_booking_id = @external_booking_id;
END;
GO

-- Finds the oldest approved allotment of the partner and room type that has enough unused rooms on every night
-- of the stay, locks it, and returns its id. Returns no row when none fits.
CREATE OR ALTER PROCEDURE dbo.usp_ota_lockAllotmentForImport
  @partner_id   NVARCHAR(40),
  @room_type_id NCHAR(36),
  @check_in     DATE,
  @check_out    DATE,
  @quantity     INT,
  @night_count  INT
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) a.allotment_id
    FROM ota_allotment a WITH (UPDLOCK, ROWLOCK)
   WHERE a.partner_id = @partner_id AND a.room_type_id = @room_type_id AND a.status = 'APPROVED' AND a.start_date <= @check_in AND a.end_date >= @check_out
     AND (SELECT COUNT(*) FROM ota_allotment_night n WHERE n.allotment_id = a.allotment_id AND n.stay_date >= @check_in AND n.stay_date < @check_out AND n.reserved - n.used >= @quantity) = @night_count
   ORDER BY a.created_at;
END;
GO

-- Adds the imported rooms to the used count of every night of the stay.
CREATE OR ALTER PROCEDURE dbo.usp_ota_useAllotmentNights
  @allotment_id NCHAR(36),
  @quantity     INT,
  @check_in     DATE,
  @check_out    DATE
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE ota_allotment_night SET used = used + @quantity WHERE allotment_id = @allotment_id AND stay_date >= @check_in AND stay_date < @check_out;
END;
GO

-- Returns the id of the active guest with this email, or no row.
CREATE OR ALTER PROCEDURE dbo.usp_ota_findGuestByEmail
  @email NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) guest_id FROM vw_ActiveGuest WHERE email = @email;
END;
GO

-- Saves a guest who has no portal account (created by an OTA import).
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertGuest
  @guest_id  NCHAR(36),
  @full_name NVARCHAR(120),
  @email     NVARCHAR(160),
  @phone     NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO guest (guest_id, account_id, full_name, email, phone, created_at)
  VALUES (@guest_id, NULL, @full_name, @email, @phone, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Saves a confirmed, prepaid reservation that came from an OTA import.
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertReservation
  @reservation_id NCHAR(36),
  @code           NVARCHAR(10),
  @guest_id       NCHAR(36),
  @room_type_id   NCHAR(36),
  @check_in       DATE,
  @check_out      DATE,
  @quantity       TINYINT,
  @quote_minor    BIGINT,
  @nightly_minor  INT,
  @adults         TINYINT
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO reservation (reservation_id, code, guest_id, room_type_id, check_in, check_out, quantity, status, guarantee, quote_minor, nightly_minor, currency, free_cancel_hours, hold_expires_at, source, adults, version, confirmed_at, created_at, updated_at)
  VALUES (@reservation_id, @code, @guest_id, @room_type_id, @check_in, @check_out, @quantity, 'CONFIRMED', 'OTA_PREPAID', @quote_minor, @nightly_minor, 'PHP', 48, DATETRUNC(SECOND, SYSUTCDATETIME()), 'OTA', @adults, 1, DATETRUNC(SECOND, SYSUTCDATETIME()), DATETRUNC(SECOND, SYSUTCDATETIME()), DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Links an imported partner booking to its reservation and allotment.
CREATE OR ALTER PROCEDURE dbo.usp_ota_insertBooking
  @partner_id          NVARCHAR(40),
  @external_booking_id NVARCHAR(80),
  @reservation_id      NCHAR(36),
  @allotment_ref       NVARCHAR(80)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO ota_booking (partner_id, external_booking_id, reservation_id, allotment_ref) VALUES (@partner_id, @external_booking_id, @reservation_id, @allotment_ref);
END;
GO

-- ---------------------------------------------------------------------------
-- Occupancy report
-- ---------------------------------------------------------------------------

-- Returns one row: the number of rooms the hotel owns (sum of room type capacity).
CREATE OR ALTER PROCEDURE dbo.usp_insight_totalRooms
AS
BEGIN
  SET NOCOUNT ON;
  SELECT COALESCE(SUM(capacity), 0) AS total_rooms FROM room_type;
END;
GO

-- Rooms sold and revenue per night and room type between two dates (inclusive).
CREATE OR ALTER PROCEDURE dbo.usp_insight_soldByNight
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT stay_date, room_type_id, SUM(quantity) AS rooms, SUM(quantity * nightly_minor) AS revenue
    FROM vw_SoldRoomNight
   WHERE stay_date BETWEEN @from_date AND @to_date
   GROUP BY stay_date, room_type_id;
END;
GO

-- Blocked, held and OTA-reserved rooms per night between two dates (inclusive).
CREATE OR ALTER PROCEDURE dbo.usp_insight_stockByNight
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT stay_date, SUM(blocked) AS blocked, SUM(held) AS held, SUM(ota_remaining) AS ota
    FROM inventory_night
   WHERE stay_date BETWEEN @from_date AND @to_date
   GROUP BY stay_date;
END;
GO

-- Lists room types cheapest first, for the per-room-type totals.
CREATE OR ALTER PROCEDURE dbo.usp_insight_listRoomTypes
AS
BEGIN
  SET NOCOUNT ON;
  SELECT room_type_id, name FROM room_type ORDER BY nightly_rate_minor;
END;
GO

-- Report counts in one row: oversold nights, live bookings, paid direct bookings (and how many were confirmed
-- within 2 minutes of payment, for bookings created in the date range), and active guests.
CREATE OR ALTER PROCEDURE dbo.usp_insight_kpiCounts
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT
    (SELECT COUNT(*) FROM inventory_night WHERE blocked + held + confirmed + ota_remaining > capacity) AS oversold,
    (SELECT COUNT(*) FROM reservation WHERE status IN ('CONFIRMED', 'CHECKED_IN', 'CHECKED_OUT')) AS confirmed,
    (SELECT COUNT(*) FROM reservation
      WHERE source = 'DIRECT' AND guarantee = 'PAID' AND paid_at IS NOT NULL AND confirmed_at IS NOT NULL AND created_at >= @from_date AND created_at < DATEADD(DAY, 1, @to_date)) AS paid_n,
    (SELECT SUM(CASE WHEN DATEDIFF_BIG(SECOND, paid_at, confirmed_at) <= 120 THEN 1 ELSE 0 END) FROM reservation
      WHERE source = 'DIRECT' AND guarantee = 'PAID' AND paid_at IS NOT NULL AND confirmed_at IS NOT NULL AND created_at >= @from_date AND created_at < DATEADD(DAY, 1, @to_date)) AS paid_fast,
    (SELECT COUNT(*) FROM vw_ActiveGuest) AS active_guests;
END;
GO

-- Lists active guests in the shape the duplicate-guest check expects (bookings is always 0 here).
CREATE OR ALTER PROCEDURE dbo.usp_insight_listActiveGuests
AS
BEGIN
  SET NOCOUNT ON;
  SELECT guest_id, full_name, email, phone, has_account, created_at, 0 AS bookings FROM vw_ActiveGuest;
END;
GO

-- Counts reservations created in the date range by status and source.
CREATE OR ALTER PROCEDURE dbo.usp_insight_bookingCounts
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status, source, COUNT(*) AS n
    FROM reservation
   WHERE created_at >= @from_date AND created_at < DATEADD(DAY, 1, @to_date)
   GROUP BY status, source;
END;
GO

-- ---------------------------------------------------------------------------
-- Room types
-- ---------------------------------------------------------------------------

-- Changes a room type. A NULL parameter leaves that column as it is.
CREATE OR ALTER PROCEDURE dbo.usp_catalog_updateRoomType
  @room_type_id       NCHAR(36),
  @nightly_rate_minor INT           = NULL,
  @tagline            NVARCHAR(120) = NULL,
  @description        NVARCHAR(700) = NULL,
  @active             TINYINT       = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE room_type
     SET nightly_rate_minor = COALESCE(@nightly_rate_minor, nightly_rate_minor),
         tagline            = COALESCE(@tagline, tagline),
         description        = COALESCE(@description, description),
         active             = COALESCE(@active, active)
   WHERE room_type_id = @room_type_id;
END;
GO
