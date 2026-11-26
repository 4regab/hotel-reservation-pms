-- requires: audit_log, inbox
-- Stored procedures for the tables every service keeps: audit_log and inbox.
-- The migration runner applies this file to each service database after that service's own migrations,
-- when the tables named on the first line exist there. A procedure never starts or ends a transaction: the caller (Lib\Tx::run) owns it.

CREATE OR ALTER PROCEDURE dbo.usp_audit_write
  @actor_id    NVARCHAR(64),
  @actor_role  NVARCHAR(20),
  @action      NVARCHAR(60),
  @target_type NVARCHAR(30),
  @target_id   NVARCHAR(64),
  @detail      NVARCHAR(MAX) = NULL,
  @ip          NVARCHAR(45)  = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO audit_log (at, actor_id, actor_role, action, target_type, target_id, detail, ip)
  VALUES (SYSUTCDATETIME(), @actor_id, @actor_role, @action, @target_type, @target_id, CAST(@detail AS JSON), @ip);
END;
GO

CREATE OR ALTER PROCEDURE dbo.usp_audit_search
  @action_prefix NVARCHAR(60) = NULL,
  @actor         NVARCHAR(64) = NULL,
  @from_date     DATE         = NULL,
  @to_date       DATE         = NULL,
  @row_limit     INT          = 100
AS
BEGIN
  SET NOCOUNT ON;
  DECLARE @pattern NVARCHAR(130) = NULL;
  IF @action_prefix IS NOT NULL
    SET @pattern = REPLACE(REPLACE(REPLACE(@action_prefix, N'\', N'\\'), N'%', N'\%'), N'_', N'\_') + N'%';

  SELECT audit_id AS auditId, at, actor_id AS actorId, actor_role AS actorRole, action,
         target_type AS targetType, target_id AS targetId, detail, ip
    FROM audit_log
   WHERE (@pattern IS NULL OR action LIKE @pattern ESCAPE N'\')
     AND (@actor IS NULL OR actor_id = @actor)
     AND (@from_date IS NULL OR at >= CAST(@from_date AS DATETIME2(3)))
     AND (@to_date IS NULL OR at < DATEADD(DAY, 1, CAST(@to_date AS DATETIME2(3))))
   ORDER BY audit_id DESC
  OFFSET 0 ROWS FETCH NEXT @row_limit ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.usp_inbox_record
  @event_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO inbox (event_id, received_at)
  SELECT @event_id, DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE NOT EXISTS (SELECT 1 FROM inbox WITH (UPDLOCK, HOLDLOCK) WHERE event_id = @event_id);
  SELECT @@ROWCOUNT AS affected;
END;
GO
