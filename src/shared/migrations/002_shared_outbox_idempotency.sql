-- requires: outbox, outbox_delivery, idempotency_key
-- Stored procedures and views for the event outbox and the Idempotency-Key records. The portal has neither table, so the
-- migration runner skips this file there. A procedure never starts or ends a transaction: the caller (Lib\Tx::run) owns it.

-- Outbox deliveries that are not yet DELIVERED, with the event type and last error.
CREATE OR ALTER VIEW dbo.vw_outbox_stuck
AS
  SELECT d.event_id AS eventId, d.handler_name AS handler, d.status, d.attempt_count AS attempts,
         d.last_error AS lastError, o.event_type AS eventType, o.created_at AS createdAt
    FROM outbox_delivery AS d
    JOIN outbox AS o ON o.event_id = d.event_id
   WHERE d.status <> N'DELIVERED';
GO

-- Outbox deliveries in PENDING state, with their payload and next attempt time.
CREATE OR ALTER VIEW dbo.vw_outbox_due
AS
  SELECT d.event_id, d.handler_name, d.attempt_count, d.next_attempt_at, o.created_at, o.payload
    FROM outbox_delivery AS d
    JOIN outbox AS o ON o.event_id = d.event_id
   WHERE d.status = N'PENDING';
GO

-- Counts outbox deliveries by status, for the status page.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_counts
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status, COUNT(*) AS n FROM outbox_delivery GROUP BY status;
END;
GO

-- Lists the undelivered outbox entries, oldest first, up to a limit.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_stuck
  @row_limit INT = 20
AS
BEGIN
  SET NOCOUNT ON;
  SELECT eventId, handler, status, attempts, lastError, eventType, createdAt
    FROM dbo.vw_outbox_stuck
   ORDER BY createdAt
  OFFSET 0 ROWS FETCH NEXT @row_limit ROWS ONLY;
END;
GO

-- Queues one event and creates one PENDING delivery row per handler named in the JSON list.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_add
  @event_id          NCHAR(36),
  @event_type        NVARCHAR(64),
  @aggregate_id      NCHAR(36),
  @aggregate_version INT,
  @correlation_id    NCHAR(36),
  @payload           NVARCHAR(MAX),
  @handlers          NVARCHAR(MAX)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO outbox (event_id, event_type, aggregate_id, aggregate_version, correlation_id, payload, created_at)
  VALUES (@event_id, @event_type, @aggregate_id, @aggregate_version, @correlation_id, CAST(@payload AS JSON), DATETRUNC(SECOND, SYSUTCDATETIME()));

  INSERT INTO outbox_delivery (event_id, handler_name, status, attempt_count, next_attempt_at)
  SELECT @event_id, h.handler, N'PENDING', 0, DATETRUNC(SECOND, SYSUTCDATETIME())
    FROM OPENJSON(@handlers) WITH (handler NVARCHAR(32) '$') AS h;
END;
GO

-- Returns the PENDING deliveries whose retry time has come, oldest first, up to a limit.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_due
  @row_limit INT = 50
AS
BEGIN
  SET NOCOUNT ON;
  SELECT event_id, handler_name, attempt_count, payload
    FROM dbo.vw_outbox_due
   WHERE next_attempt_at <= DATETRUNC(SECOND, SYSUTCDATETIME())
   ORDER BY created_at
  OFFSET 0 ROWS FETCH NEXT @row_limit ROWS ONLY;
END;
GO

-- Marks one delivery as DELIVERED and clears its last error.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_mark_delivered
  @event_id     NCHAR(36),
  @handler_name NVARCHAR(32)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE outbox_delivery
     SET status = N'DELIVERED', delivered_at = DATETRUNC(SECOND, SYSUTCDATETIME()), last_error = NULL
   WHERE event_id = @event_id AND handler_name = @handler_name;
END;
GO

-- Saves the attempt count, new status and error of a failed delivery, and sets its next retry time.
CREATE OR ALTER PROCEDURE dbo.usp_outbox_mark_failed
  @event_id      NCHAR(36),
  @handler_name  NVARCHAR(32),
  @attempt_count INT,
  @status        NVARCHAR(9),
  @delay_seconds INT,
  @last_error    NVARCHAR(255)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE outbox_delivery
     SET attempt_count = @attempt_count, status = @status,
         next_attempt_at = DATEADD(SECOND, @delay_seconds, DATETRUNC(SECOND, SYSUTCDATETIME())),
         last_error = @last_error
   WHERE event_id = @event_id AND handler_name = @handler_name;
END;
GO

-- Looks up the saved answer for an Idempotency-Key and caller, so a repeated request gets the same reply.
CREATE OR ALTER PROCEDURE dbo.usp_idem_find
  @idem_key NVARCHAR(128),
  @caller   NVARCHAR(64)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT request_hash, response_status AS status, response_body AS body,
         DATEDIFF_BIG(SECOND, '1970-01-01', created_at) AS created_at
    FROM idempotency_key
   WHERE idem_key = @idem_key AND caller = @caller;
END;
GO

-- Saves the answer for an Idempotency-Key and caller. An existing key is overwritten.
CREATE OR ALTER PROCEDURE dbo.usp_idem_save
  @idem_key        NVARCHAR(128),
  @caller          NVARCHAR(64),
  @request_hash    NCHAR(64),
  @response_status INT,
  @response_body   NVARCHAR(MAX),
  @created_epoch   INT
AS
BEGIN
  SET NOCOUNT ON;
  MERGE idempotency_key WITH (HOLDLOCK) AS t
  USING (SELECT @idem_key AS idem_key, @caller AS caller, @request_hash AS request_hash,
                @response_status AS response_status, @response_body AS response_body,
                DATEADD(SECOND, @created_epoch, CAST('1970-01-01' AS DATETIME2(0))) AS created_at) AS s
     ON t.idem_key = s.idem_key AND t.caller = s.caller
   WHEN MATCHED THEN
     UPDATE SET request_hash = s.request_hash, response_status = s.response_status,
                response_body = s.response_body, created_at = s.created_at
   WHEN NOT MATCHED THEN
     INSERT (idem_key, caller, request_hash, response_status, response_body, created_at)
     VALUES (s.idem_key, s.caller, s.request_hash, s.response_status, s.response_body, s.created_at);
END;
GO
