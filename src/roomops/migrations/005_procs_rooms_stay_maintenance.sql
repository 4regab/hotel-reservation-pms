-- Room Operations: stored procedures and views for rooms, cleaning, arrivals, early-arrival overrides, stays and maintenance blocks.
-- RoomController, StayCoordinator and MaintenanceCoordinator call these through Lib\Db::call. A procedure never starts or
-- ends a transaction: the caller (Lib\Tx::run) owns it. Re-runnable: every object uses CREATE OR ALTER.
-- The service user gets EXECUTE and SELECT from scripts/migrate.php.

-- ---------------------------------------------------------------------------
-- Views (read side)
-- ---------------------------------------------------------------------------

-- Every room with its details.
CREATE OR ALTER VIEW dbo.vw_RoomOpsRoom AS
SELECT room_id, room_type_id, room_number, floor, view_type, bed_type, readiness, version, notes
  FROM dbo.room;
GO

-- Arrivals fed by reservation events.
CREATE OR ALTER VIEW dbo.vw_RoomOpsArrival AS
SELECT reservation_id, room_type_id, check_in, check_out, quantity, status
  FROM dbo.arrival;
GO

-- One row per stay and room (a stay without an assignment has NULL room columns).
CREATE OR ALTER VIEW dbo.vw_RoomOpsStayRoom AS
SELECT s.reservation_id, s.stay_id, s.actual_check_in, s.actual_check_out, s.identity_doc_type, s.override_reason, ro.room_id, ro.room_number
  FROM dbo.stay s
  LEFT JOIN dbo.room_assignment a ON a.stay_id = s.stay_id
  LEFT JOIN dbo.room ro ON ro.room_id = a.room_id;
GO

-- Cleaning tasks with the room's number, floor and version.
CREATE OR ALTER VIEW dbo.vw_RoomOpsCleaningTask AS
SELECT t.task_id, t.room_id, r.room_number, r.floor, r.version AS room_version, t.status, t.assignee, t.created_at, t.cleaned_at, t.completed_at
  FROM dbo.cleaning_task t
  JOIN dbo.room r ON r.room_id = t.room_id;
GO

-- Early-arrival requests.
CREATE OR ALTER VIEW dbo.vw_RoomOpsArrivalOverride AS
SELECT reservation_id, requested_by, reason, status, decided_by, decided_at, created_at
  FROM dbo.arrival_override;
GO

-- Maintenance blocks with the room's number and type.
CREATE OR ALTER VIEW dbo.vw_RoomOpsMaintenanceBlock AS
SELECT b.block_id, b.room_id, r.room_number, r.room_type_id, b.start_date, b.end_date, b.status, b.reason, b.request_id, b.requested_by,
       b.created_at, b.decided_by, b.decided_at, b.decision_note
  FROM dbo.maintenance_block b
  JOIN dbo.room r ON r.room_id = b.room_id;
GO

-- ---------------------------------------------------------------------------
-- Rooms
-- ---------------------------------------------------------------------------

-- Rooms by room number. @room_type_id NULL = all types. @with_stay = 1 adds the reservation that occupies each room.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listRooms
  @room_type_id NCHAR(36) = NULL,
  @with_stay    BIT       = 0
AS
BEGIN
  SET NOCOUNT ON;
  SELECT r.room_id AS roomId, r.room_type_id AS roomTypeId, r.room_number AS roomNumber, r.floor, r.view_type AS [view], r.bed_type AS bedType,
         r.readiness, r.version, r.notes,
         CASE WHEN @with_stay = 1
              THEN (SELECT TOP (1) s.reservation_id FROM dbo.room_assignment a JOIN dbo.stay s ON s.stay_id = a.stay_id WHERE a.room_id = r.room_id AND a.active = 1)
         END AS reservationId
    FROM dbo.vw_RoomOpsRoom r
   WHERE (@room_type_id IS NULL OR r.room_type_id = @room_type_id)
   ORDER BY r.room_number;
END;
GO

-- One room by id, row locked until the transaction ends (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_lockRoom
  @room_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT room_id, room_type_id, room_number, readiness, version FROM dbo.room WITH (UPDLOCK, ROWLOCK) WHERE room_id = @room_id;
END;
GO

-- One room by id, no lock (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_getRoom
  @room_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT room_id, room_type_id FROM dbo.vw_RoomOpsRoom WHERE room_id = @room_id;
END;
GO

-- Set a room's readiness and bump its version. For CLEANING, the room's open cleaning tasks go to IN_PROGRESS under @assignee.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_setRoomStatus
  @room_id   NCHAR(36),
  @readiness NVARCHAR(14),
  @assignee  NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.room SET readiness = @readiness, version = version + 1 WHERE room_id = @room_id;
  IF @readiness = N'CLEANING'
    UPDATE dbo.cleaning_task SET status = N'IN_PROGRESS', assignee = @assignee WHERE room_id = @room_id AND status = N'OPEN';
END;
GO

-- Take a room out of service when it is READY or DIRTY.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_takeRoomOutOfService
  @room_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.room SET readiness = N'OUT_OF_SERVICE', version = version + 1 WHERE room_id = @room_id AND readiness IN (N'READY', N'DIRTY');
END;
GO

-- The first @quantity READY rooms of a type with no active assignment in the dates, locked, in room-number order.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_pickReadyRooms
  @room_type_id NCHAR(36),
  @quantity     INT,
  @check_in     DATE,
  @check_out    DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (@quantity) r.room_id
    FROM dbo.room r WITH (UPDLOCK, ROWLOCK)
   WHERE r.room_type_id = @room_type_id AND r.readiness = N'READY'
     AND NOT EXISTS (SELECT 1 FROM dbo.room_assignment a WHERE a.room_id = r.room_id AND a.active = 1 AND a.start_date < @check_out AND a.end_date > @check_in)
   ORDER BY r.room_number;
END;
GO

-- One row (x = 1) when the room has an active assignment that overlaps [@start_date, @end_date); no row otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_hasAssignmentOverlap
  @room_id    NCHAR(36),
  @start_date DATE,
  @end_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) 1 AS x FROM dbo.room_assignment WHERE room_id = @room_id AND active = 1 AND start_date < @end_date AND end_date > @start_date;
END;
GO

-- ---------------------------------------------------------------------------
-- Arrivals and early-arrival overrides
-- ---------------------------------------------------------------------------

-- Up to 200 expected arrivals, earliest check-in first.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listExpectedArrivals
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (200) reservation_id AS reservationId, room_type_id AS roomTypeId, check_in AS checkIn, check_out AS checkOut, quantity, status
    FROM dbo.vw_RoomOpsArrival WHERE status = N'EXPECTED' ORDER BY check_in;
END;
GO

-- Record an arrival from ReservationConfirmed. An existing arrival goes back to EXPECTED unless the guest already ARRIVED.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_confirmArrival
  @reservation_id NCHAR(36),
  @room_type_id   NCHAR(36),
  @check_in       DATE,
  @check_out      DATE,
  @quantity       TINYINT
AS
BEGIN
  SET NOCOUNT ON;
  MERGE dbo.arrival WITH (HOLDLOCK) AS d
  USING (SELECT @reservation_id AS reservation_id, @room_type_id AS room_type_id, @check_in AS check_in, @check_out AS check_out, @quantity AS quantity) AS s
     ON d.reservation_id = s.reservation_id
  WHEN MATCHED THEN UPDATE SET status = IIF(d.status = N'ARRIVED', d.status, N'EXPECTED')
  WHEN NOT MATCHED THEN INSERT (reservation_id, room_type_id, check_in, check_out, quantity, status)
       VALUES (s.reservation_id, s.room_type_id, s.check_in, s.check_out, s.quantity, N'EXPECTED');
END;
GO

-- Set an arrival's status. @only_from NULL = from any status, otherwise only when it is currently that status.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_setArrivalStatus
  @reservation_id NCHAR(36),
  @status         NVARCHAR(9),
  @only_from      NVARCHAR(9) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.arrival SET status = @status WHERE reservation_id = @reservation_id AND (@only_from IS NULL OR status = @only_from);
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Save an early-arrival request. A repeat request updates the reason only while it is still PENDING.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_requestOverride
  @reservation_id NCHAR(36),
  @requested_by   NCHAR(36),
  @reason         NVARCHAR(200)
AS
BEGIN
  SET NOCOUNT ON;
  MERGE dbo.arrival_override WITH (HOLDLOCK) AS d
  USING (SELECT @reservation_id AS reservation_id, @requested_by AS requested_by, @reason AS reason) AS s ON d.reservation_id = s.reservation_id
  WHEN MATCHED THEN UPDATE SET reason = IIF(d.status = N'PENDING', s.reason, d.reason)
  WHEN NOT MATCHED THEN INSERT (reservation_id, requested_by, reason, status, created_at)
       VALUES (s.reservation_id, s.requested_by, s.reason, N'PENDING', DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Status and reason of a reservation's early-arrival request (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_getOverride
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status, reason FROM dbo.vw_RoomOpsArrivalOverride WHERE reservation_id = @reservation_id;
END;
GO

-- Reason and approver of an APPROVED early-arrival request (no row when none).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_getApprovedOverride
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT reason, decided_by FROM dbo.vw_RoomOpsArrivalOverride WHERE reservation_id = @reservation_id AND status = N'APPROVED';
END;
GO

-- Up to 100 early-arrival requests with one status, oldest first.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listOverrides
  @status NVARCHAR(8)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (100) reservation_id AS reservationId, reason, status, requested_by AS requestedBy, created_at AS createdAt
    FROM dbo.vw_RoomOpsArrivalOverride WHERE status = @status ORDER BY created_at;
END;
GO

-- Approve or reject a PENDING early-arrival request. Returns affected = 0 when none was pending.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_decideOverride
  @reservation_id NCHAR(36),
  @status         NVARCHAR(8),
  @decided_by     NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.arrival_override SET status = @status, decided_by = @decided_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE reservation_id = @reservation_id AND status = N'PENDING';
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- ---------------------------------------------------------------------------
-- Cleaning
-- ---------------------------------------------------------------------------

-- Up to 200 cleaning tasks whose status is in the comma list @statuses, oldest first.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listCleaningTasks
  @statuses NVARCHAR(100)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (200) task_id AS taskId, room_id AS roomId, room_number AS roomNumber, floor, room_version AS roomVersion, status, assignee,
         created_at AS createdAt, cleaned_at AS cleanedAt, completed_at AS completedAt
    FROM dbo.vw_RoomOpsCleaningTask
   WHERE status IN (SELECT value FROM STRING_SPLIT(@statuses, N','))
   ORDER BY created_at;
END;
GO

-- One cleaning task by id, row locked until the transaction ends (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_lockCleaningTask
  @task_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT task_id, room_id, status, assignee FROM dbo.cleaning_task WITH (UPDLOCK, ROWLOCK) WHERE task_id = @task_id;
END;
GO

-- The cleaner reports the task clean: status CLEANED.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_markTaskCleaned
  @task_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.cleaning_task SET status = N'CLEANED', cleaned_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE task_id = @task_id;
END;
GO

-- Inspection passed: the task is DONE and the room, if it is CLEANING, becomes READY.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_inspectTask
  @task_id      NCHAR(36),
  @room_id      NCHAR(36),
  @inspected_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.cleaning_task SET status = N'DONE', completed_at = DATETRUNC(SECOND, SYSUTCDATETIME()), inspected_by = @inspected_by WHERE task_id = @task_id;
  UPDATE dbo.room SET readiness = N'READY', version = version + 1 WHERE room_id = @room_id AND readiness = N'CLEANING';
END;
GO

-- A room left DIRTY after check-out gets an OPEN cleaning task.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_releaseRoom
  @room_id NCHAR(36),
  @task_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.room SET readiness = N'DIRTY', version = version + 1 WHERE room_id = @room_id;
  INSERT INTO dbo.cleaning_task (task_id, room_id, assignee, status, created_at)
  VALUES (@task_id, @room_id, NULL, N'OPEN', DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- ---------------------------------------------------------------------------
-- Stays
-- ---------------------------------------------------------------------------

-- Rooms and times for the reservations in the comma list @ids, by room number.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listStays
  @ids NVARCHAR(MAX)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT reservation_id, stay_id, actual_check_in, actual_check_out, identity_doc_type, override_reason, room_id, room_number
    FROM dbo.vw_RoomOpsStayRoom
   WHERE reservation_id IN (SELECT value FROM STRING_SPLIT(@ids, N','))
   ORDER BY room_number;
END;
GO

-- A reservation's stay id and check-out time (no row when it has no stay).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_getStay
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT stay_id, actual_check_out FROM dbo.stay WHERE reservation_id = @reservation_id;
END;
GO

-- Same as getStay, with the stay row locked until the transaction ends.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_lockStay
  @reservation_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT stay_id, actual_check_out FROM dbo.stay WITH (UPDLOCK, ROWLOCK) WHERE reservation_id = @reservation_id;
END;
GO

-- Start a stay now. A second stay for the same reservation fails with the duplicate-key error (2627).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_insertStay
  @stay_id              NCHAR(36),
  @reservation_id       NCHAR(36),
  @check_in             DATE,
  @check_out            DATE,
  @identity_verified_by NCHAR(36),
  @identity_doc_type    NVARCHAR(20),
  @queue_started_at     DATETIME2(0)  = NULL,
  @override_by          NCHAR(36)     = NULL,
  @override_reason      NVARCHAR(200) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.stay (stay_id, reservation_id, check_in, check_out, actual_check_in, identity_verified_by, identity_doc_type, queue_started_at, override_by, override_reason)
  VALUES (@stay_id, @reservation_id, @check_in, @check_out, DATETRUNC(SECOND, SYSUTCDATETIME()), @identity_verified_by, @identity_doc_type, @queue_started_at, @override_by, @override_reason);
END;
GO

-- Put a room into a stay for the dates and mark the room OCCUPIED.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_assignRoom
  @assignment_id NCHAR(36),
  @stay_id       NCHAR(36),
  @room_id       NCHAR(36),
  @start_date    DATE,
  @end_date      DATE
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.room_assignment (assignment_id, stay_id, room_id, start_date, end_date, active)
  VALUES (@assignment_id, @stay_id, @room_id, @start_date, @end_date, 1);
  UPDATE dbo.room SET readiness = N'OCCUPIED', version = version + 1 WHERE room_id = @room_id;
END;
GO

-- Room ids of a stay's active assignments, in id order.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listStayRooms
  @stay_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT room_id FROM dbo.room_assignment WHERE stay_id = @stay_id AND active = 1 ORDER BY room_id;
END;
GO

-- End a stay now and deactivate its assignments.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_endStay
  @stay_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.stay SET actual_check_out = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE stay_id = @stay_id;
  UPDATE dbo.room_assignment SET active = 0 WHERE stay_id = @stay_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Maintenance blocks
-- ---------------------------------------------------------------------------

-- Block found by request id, row locked until the transaction ends (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_lockBlockByRequest
  @request_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT block_id, status FROM dbo.maintenance_block WITH (UPDLOCK, HOLDLOCK) WHERE request_id = @request_id;
END;
GO

-- Save a new maintenance block.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_insertBlock
  @block_id     NCHAR(36),
  @room_id      NCHAR(36),
  @start_date   DATE,
  @end_date     DATE,
  @status       NVARCHAR(8),
  @reason       NVARCHAR(200),
  @request_id   NCHAR(36),
  @requested_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.maintenance_block (block_id, room_id, start_date, end_date, status, reason, request_id, requested_by, created_at)
  VALUES (@block_id, @room_id, @start_date, @end_date, @status, @reason, @request_id, @requested_by, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- One block by id with its room's type (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_roomops_getBlock
  @block_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT block_id, room_id, room_type_id, start_date, end_date, status, reason, request_id, requested_by, created_at, decided_by, decided_at, decision_note
    FROM dbo.vw_RoomOpsMaintenanceBlock WHERE block_id = @block_id;
END;
GO

-- Up to 100 blocks, newest first. @status NULL = all statuses.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_listBlocks
  @status NVARCHAR(8) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (100) block_id AS blockId, room_id AS roomId, room_number AS roomNumber, start_date AS startDate, end_date AS endDate, status, reason,
         requested_by AS requestedBy, created_at AS createdAt, decision_note AS decisionNote
    FROM dbo.vw_RoomOpsMaintenanceBlock
   WHERE (@status IS NULL OR status = @status)
   ORDER BY created_at DESC;
END;
GO

-- Set a block's status. @only_from NULL = from any status. Returns affected = 0 when the block was not in that status.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_setBlockStatus
  @block_id  NCHAR(36),
  @status    NVARCHAR(8),
  @only_from NVARCHAR(8) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.maintenance_block SET status = @status WHERE block_id = @block_id AND (@only_from IS NULL OR status = @only_from);
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Give a block a fresh request id.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_setBlockRequestId
  @block_id   NCHAR(36),
  @request_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.maintenance_block SET request_id = @request_id WHERE block_id = @block_id;
END;
GO

-- Reject a PENDING or CONFLICT block. @note NULL = no note. Returns affected = 0 when it was in neither status.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_rejectBlock
  @block_id   NCHAR(36),
  @decided_by NCHAR(36),
  @note       NVARCHAR(200) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.maintenance_block SET status = N'REJECTED', decided_by = @decided_by, decided_at = DATETRUNC(SECOND, SYSUTCDATETIME()), decision_note = @note
   WHERE block_id = @block_id AND status IN (N'CONFLICT', N'PENDING');
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Approve a block. An earlier decider and decision time are kept.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_approveBlock
  @block_id   NCHAR(36),
  @decided_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.maintenance_block SET status = N'APPROVED', decided_by = COALESCE(decided_by, @decided_by), decided_at = COALESCE(decided_at, DATETRUNC(SECOND, SYSUTCDATETIME()))
   WHERE block_id = @block_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Operations report
-- ---------------------------------------------------------------------------

-- Minutes from task creation to inspection for tasks finished from @from_date through @to_date.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_reportCleaningMinutes
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT DATEDIFF_BIG(SECOND, created_at, completed_at) / 60 AS minutes
    FROM dbo.cleaning_task
   WHERE status = N'DONE' AND completed_at >= @from_date AND completed_at < DATEADD(DAY, 1, @to_date);
END;
GO

-- Seconds from joining the queue to check-in for stays checked in from @from_date through @to_date.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_reportQueueSeconds
  @from_date DATE,
  @to_date   DATE
AS
BEGIN
  SET NOCOUNT ON;
  SELECT DATEDIFF_BIG(SECOND, queue_started_at, actual_check_in) AS seconds
    FROM dbo.stay
   WHERE queue_started_at IS NOT NULL AND actual_check_in >= @from_date AND actual_check_in < DATEADD(DAY, 1, @to_date);
END;
GO

-- Number of rooms in each readiness state.
CREATE OR ALTER PROCEDURE dbo.usp_roomops_reportRoomCounts
AS
BEGIN
  SET NOCOUNT ON;
  SELECT readiness, COUNT(*) AS n FROM dbo.vw_RoomOpsRoom GROUP BY readiness;
END;
GO
