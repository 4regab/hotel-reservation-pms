-- Portal: views and stored procedures for sessions, rate limits, sign-in, the email queue, the email log and the reporting copy.
-- The PHP code calls these with Lib\Db::call instead of holding SQL. A procedure never starts or ends a transaction.

-- ---------------------------------------------------------------------------
-- Views
-- ---------------------------------------------------------------------------

-- Accounts with the columns sign-in and the page header need.
CREATE OR ALTER VIEW dbo.vw_AuthAccount AS
SELECT account_id, email, role, full_name, phone, marketing_consent, password_hash, totp_secret, active,
       failed_logins, locked_until
  FROM account;
GO

-- Email jobs waiting to be sent, oldest first by created_at (the caller orders).
CREATE OR ALTER VIEW dbo.vw_PendingNotificationJob AS
SELECT job_id, type, reservation_id, account_id, payload, attempts, created_at, next_attempt_at
  FROM notification_job
 WHERE status = N'PENDING';
GO

-- Daily reporting copy with the names the API sends.
CREATE OR ALTER VIEW dbo.vw_ReportDaily AS
SELECT day, confirmed, cancelled, revenue_minor AS revenueMinor, refunded_minor AS refundedMinor,
       check_ins AS checkIns, check_outs AS checkOuts, updated_at AS updatedAt
  FROM report_daily;
GO

-- ---------------------------------------------------------------------------
-- Health
-- ---------------------------------------------------------------------------

-- Proves the database answers.
CREATE OR ALTER PROCEDURE dbo.usp_health_ping
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS ok;
END;
GO

-- ---------------------------------------------------------------------------
-- Sessions
-- ---------------------------------------------------------------------------

-- Session data when the session was updated after @min_updated_at (no row when missing or expired).
CREATE OR ALTER PROCEDURE dbo.usp_session_read
  @session_id     NVARCHAR(128),
  @min_updated_at INT
AS
BEGIN
  SET NOCOUNT ON;
  SELECT data FROM session WHERE session_id = @session_id AND updated_at > @min_updated_at;
END;
GO

-- Creates or replaces a session.
CREATE OR ALTER PROCEDURE dbo.usp_session_write
  @session_id NVARCHAR(128),
  @data       NVARCHAR(MAX),
  @updated_at INT
AS
BEGIN
  SET NOCOUNT ON;
  MERGE session WITH (HOLDLOCK) AS d
  USING (SELECT @session_id AS session_id, @data AS data, @updated_at AS updated_at) AS s ON d.session_id = s.session_id
  WHEN MATCHED THEN UPDATE SET data = s.data, updated_at = s.updated_at
  WHEN NOT MATCHED THEN INSERT (session_id, data, updated_at) VALUES (s.session_id, s.data, s.updated_at);
END;
GO

-- Deletes one session.
CREATE OR ALTER PROCEDURE dbo.usp_session_destroy
  @session_id NVARCHAR(128)
AS
BEGIN
  SET NOCOUNT ON;
  DELETE FROM session WHERE session_id = @session_id;
END;
GO

-- Deletes sessions last updated before @before. Returns how many.
CREATE OR ALTER PROCEDURE dbo.usp_session_purge
  @before INT
AS
BEGIN
  SET NOCOUNT ON;
  DELETE FROM session WHERE updated_at < @before;
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- ---------------------------------------------------------------------------
-- Rate limit
-- ---------------------------------------------------------------------------

-- Counts one hit in the bucket's current window and returns the total hits in that window.
CREATE OR ALTER PROCEDURE dbo.usp_rateLimit_hit
  @bucket       NVARCHAR(120),
  @window_start INT
AS
BEGIN
  SET NOCOUNT ON;
  MERGE rate_limit WITH (HOLDLOCK) AS d
  USING (SELECT @bucket AS bucket, @window_start AS window_start) AS s ON d.bucket = s.bucket AND d.window_start = s.window_start
  WHEN MATCHED THEN UPDATE SET hits = d.hits + 1
  WHEN NOT MATCHED THEN INSERT (bucket, window_start, hits) VALUES (s.bucket, s.window_start, 1);
  SELECT hits FROM rate_limit WHERE bucket = @bucket AND window_start = @window_start;
END;
GO

-- Deletes counters from windows that started before @before.
CREATE OR ALTER PROCEDURE dbo.usp_rateLimit_purge
  @before INT
AS
BEGIN
  SET NOCOUNT ON;
  DELETE FROM rate_limit WHERE window_start < @before;
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- ---------------------------------------------------------------------------
-- Sign-in and accounts
-- ---------------------------------------------------------------------------

-- Account row for sign-in, with locked = 1 while the lock has not run out.
CREATE OR ALTER PROCEDURE dbo.usp_auth_getLogin
  @email NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id, role, password_hash, totp_secret, active, failed_logins,
         CASE WHEN locked_until IS NOT NULL AND locked_until > DATETRUNC(SECOND, SYSUTCDATETIME()) THEN 1 ELSE 0 END AS locked
    FROM vw_AuthAccount
   WHERE email = @email;
END;
GO

-- Counts one failed sign-in and locks the account for @lock_minutes once it reaches @max_fails.
-- "+ 2" because T-SQL reads the old failed_logins on the right of SET, so the lock lands on the same failure as before.
CREATE OR ALTER PROCEDURE dbo.usp_auth_recordFailure
  @account_id   NCHAR(36),
  @max_fails    INT,
  @lock_minutes INT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE account
     SET failed_logins = failed_logins + 1,
         locked_until = IIF(failed_logins + 2 >= @max_fails, DATEADD(MINUTE, @lock_minutes, DATETRUNC(SECOND, SYSUTCDATETIME())), locked_until)
   WHERE account_id = @account_id;
END;
GO

-- Clears the failure count and the lock, and stamps the last sign-in time.
CREATE OR ALTER PROCEDURE dbo.usp_auth_recordSuccess
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE account
     SET failed_logins = 0, locked_until = NULL, last_login_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE account_id = @account_id;
END;
GO

-- Account id, email, role, name and phone for one account.
CREATE OR ALTER PROCEDURE dbo.usp_auth_getAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id, email, role, full_name, phone FROM vw_AuthAccount WHERE account_id = @account_id;
END;
GO

-- Same as usp_auth_getAccount plus the marketing consent flag (page header and account page).
CREATE OR ALTER PROCEDURE dbo.usp_auth_getProfile
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id, email, role, full_name, phone, marketing_consent FROM vw_AuthAccount WHERE account_id = @account_id;
END;
GO

-- Email and name of an active account (for the password reset email).
CREATE OR ALTER PROCEDURE dbo.usp_auth_getActiveContact
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT email, full_name FROM vw_AuthAccount WHERE account_id = @account_id AND active = 1;
END;
GO

-- Deletes password reset rows that expired more than a day ago.
CREATE OR ALTER PROCEDURE dbo.usp_auth_purgeResets
AS
BEGIN
  SET NOCOUNT ON;
  DELETE FROM password_reset WHERE expires_at < DATEADD(DAY, -1, DATETRUNC(SECOND, SYSUTCDATETIME()));
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- ---------------------------------------------------------------------------
-- Email queue and log
-- ---------------------------------------------------------------------------

-- Queues one email job, due now.
CREATE OR ALTER PROCEDURE dbo.usp_notification_queue
  @job_id         NCHAR(36),
  @type           NVARCHAR(40),
  @reservation_id NCHAR(36)     = NULL,
  @account_id     NCHAR(36)     = NULL,
  @payload        NVARCHAR(MAX) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO notification_job (job_id, type, reservation_id, account_id, payload, status, attempts, next_attempt_at, created_at)
  VALUES (@job_id, @type, @reservation_id, @account_id, @payload, N'PENDING', 0, DATETRUNC(SECOND, SYSUTCDATETIME()), DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- Up to @limit pending jobs that are due, oldest first.
CREATE OR ALTER PROCEDURE dbo.usp_notification_listDue
  @limit INT
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (@limit) job_id, type, reservation_id, account_id, payload, attempts
    FROM vw_PendingNotificationJob
   WHERE next_attempt_at IS NULL OR next_attempt_at <= DATETRUNC(SECOND, SYSUTCDATETIME())
   ORDER BY created_at;
END;
GO

-- Marks a job sent and clears its payload and last error.
CREATE OR ALTER PROCEDURE dbo.usp_notification_markSent
  @job_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE notification_job
     SET status = N'SENT', sent_at = DATETRUNC(SECOND, SYSUTCDATETIME()), attempts = attempts + 1, last_error = NULL, payload = NULL
   WHERE job_id = @job_id;
END;
GO

-- Records a failed attempt: new status (PENDING or FAILED), attempt count, error, and the next try in @delay_seconds.
CREATE OR ALTER PROCEDURE dbo.usp_notification_markFailed
  @job_id        NCHAR(36),
  @status        NVARCHAR(7),
  @attempts      INT,
  @last_error    NVARCHAR(200),
  @delay_seconds INT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE notification_job
     SET status = @status, attempts = @attempts, last_error = @last_error,
         next_attempt_at = DATEADD(SECOND, @delay_seconds, DATETRUNC(SECOND, SYSUTCDATETIME()))
   WHERE job_id = @job_id;
END;
GO

-- One row when the job already has a SENT or LOGGED email in the log, none otherwise.
CREATE OR ALTER PROCEDURE dbo.usp_emailLog_isDone
  @job_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (1) 1 AS done FROM email_log WHERE job_id = @job_id AND status IN (N'SENT', N'LOGGED');
END;
GO

-- Adds one line to the email log.
CREATE OR ALTER PROCEDURE dbo.usp_emailLog_insert
  @email_id    NCHAR(36),
  @template    NVARCHAR(40),
  @to_email    NVARCHAR(160),
  @subject     NVARCHAR(200),
  @body_html   NVARCHAR(MAX),
  @status      NVARCHAR(6),
  @provider_id NVARCHAR(80)  = NULL,
  @error       NVARCHAR(200) = NULL,
  @job_id      NCHAR(36)     = NULL
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO email_log (email_id, template, to_email, subject, body_html, status, provider_id, error, job_id, created_at)
  VALUES (@email_id, @template, @to_email, @subject, @body_html, @status, @provider_id, @error, @job_id, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- ---------------------------------------------------------------------------
-- Reporting copy
-- ---------------------------------------------------------------------------

-- Adds to the day's counters (a missing day starts at zero). Pass only the counters that change.
CREATE OR ALTER PROCEDURE dbo.usp_reportDaily_add
  @day            DATE,
  @confirmed      INT    = 0,
  @cancelled      INT    = 0,
  @revenue_minor  BIGINT = 0,
  @refunded_minor BIGINT = 0,
  @check_ins      INT    = 0,
  @check_outs     INT    = 0
AS
BEGIN
  SET NOCOUNT ON;
  MERGE report_daily WITH (HOLDLOCK) AS d
  USING (SELECT @day AS day) AS s ON d.day = s.day
  WHEN MATCHED THEN UPDATE SET confirmed = d.confirmed + @confirmed, cancelled = d.cancelled + @cancelled,
         revenue_minor = d.revenue_minor + @revenue_minor, refunded_minor = d.refunded_minor + @refunded_minor,
         check_ins = d.check_ins + @check_ins, check_outs = d.check_outs + @check_outs,
         updated_at = DATETRUNC(SECOND, SYSUTCDATETIME())
  WHEN NOT MATCHED THEN INSERT (day, confirmed, cancelled, revenue_minor, refunded_minor, check_ins, check_outs, updated_at)
         VALUES (s.day, @confirmed, @cancelled, @revenue_minor, @refunded_minor, @check_ins, @check_outs, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- The latest 30 days of the reporting copy, newest first.
CREATE OR ALTER PROCEDURE dbo.usp_reportDaily_list
AS
BEGIN
  SET NOCOUNT ON;
  SELECT day, confirmed, cancelled, revenueMinor, refundedMinor, checkIns, checkOuts, updatedAt
    FROM vw_ReportDaily
   ORDER BY day DESC
   OFFSET 0 ROWS FETCH NEXT 30 ROWS ONLY;
END;
GO
