-- Portal: views and stored procedures for accounts, password reset, sessions, privacy requests and the IT oversight pages.
-- The PHP code calls these with Lib\Db::call instead of holding SQL. A procedure never starts or ends a transaction:
-- the caller (Lib\Tx::run) owns it, so locks taken here (UPDLOCK, ROWLOCK) last until the caller's transaction ends.

-- ---------------------------------------------------------------------------
-- Views
-- ---------------------------------------------------------------------------

-- Accounts for the user list. Never exposes the password hash or the second-factor secret (has_totp says if one is set).
CREATE OR ALTER VIEW dbo.vw_AccountSummary AS
SELECT account_id, email, full_name, role, active, last_login_at, locked_until,
       CASE WHEN totp_secret IS NOT NULL THEN 1 ELSE 0 END AS has_totp, created_at, anonymized_at
  FROM account;
GO

-- Privacy requests joined to the account that filed them, with an overdue flag.
CREATE OR ALTER VIEW dbo.vw_PrivacyRequest AS
SELECT p.request_id, p.account_id, p.type, p.details, p.status, p.handled_by, p.resolution_note, p.result,
       p.due_at, p.created_at, p.completed_at, a.email, a.full_name,
       CASE WHEN p.due_at < DATETRUNC(SECOND, SYSUTCDATETIME()) THEN 1 ELSE 0 END AS overdue
  FROM privacy_request p
  JOIN account a ON a.account_id = p.account_id;
GO

-- Sent emails without the body (subject and status only).
CREATE OR ALTER VIEW dbo.vw_EmailLog AS
SELECT email_id, template, to_email, subject, status, created_at
  FROM email_log;
GO

-- ---------------------------------------------------------------------------
-- Accounts
-- ---------------------------------------------------------------------------

-- Add an account. A duplicate email raises the unique-key error (2627) for the caller to handle.
CREATE OR ALTER PROCEDURE dbo.usp_account_insert
  @account_id        NCHAR(36),
  @email             NVARCHAR(160),
  @password_hash     NVARCHAR(255),
  @role              NVARCHAR(12),
  @full_name         NVARCHAR(120),
  @phone             NVARCHAR(40),
  @totp_secret       NVARCHAR(64) = NULL,
  @marketing_consent TINYINT = 0
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.account (account_id, email, password_hash, role, full_name, phone, totp_secret, marketing_consent, created_at)
  VALUES (@account_id, @email, @password_hash, @role, @full_name, @phone, @totp_secret, @marketing_consent, DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- The id of the active account with this email (no row when there is none).
CREATE OR ALTER PROCEDURE dbo.usp_account_findActiveByEmail
  @email NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id FROM dbo.account WHERE email = @email AND active = 1;
END;
GO

-- Password hash, email and role of one account (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_account_getCredentials
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT password_hash, email, role FROM dbo.account WHERE account_id = @account_id;
END;
GO

-- Set a new password hash. @clear_lock = 1 also clears failed sign-ins and any lockout (used by a reset).
CREATE OR ALTER PROCEDURE dbo.usp_account_setPassword
  @account_id    NCHAR(36),
  @password_hash NVARCHAR(255),
  @clear_lock    BIT = 0
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account
     SET password_hash = @password_hash,
         failed_logins = CASE WHEN @clear_lock = 1 THEN 0 ELSE failed_logins END,
         locked_until  = CASE WHEN @clear_lock = 1 THEN NULL ELSE locked_until END
   WHERE account_id = @account_id;
END;
GO

-- Change name, phone and marketing consent.
CREATE OR ALTER PROCEDURE dbo.usp_account_updateProfile
  @account_id        NCHAR(36),
  @full_name         NVARCHAR(120),
  @phone             NVARCHAR(40),
  @marketing_consent TINYINT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account SET full_name = @full_name, phone = @phone, marketing_consent = @marketing_consent WHERE account_id = @account_id;
END;
GO

-- Up to 200 accounts that are not anonymized, staff first in role order, guests last, then by name.
-- @role and @like are optional filters (NULL = no filter). @like is a ready LIKE pattern that uses \ as its escape.
CREATE OR ALTER PROCEDURE dbo.usp_account_list
  @role NVARCHAR(12)  = NULL,
  @like NVARCHAR(400) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (200) account_id, email, full_name, role, active, last_login_at, locked_until, has_totp, created_at
    FROM dbo.vw_AccountSummary
   WHERE anonymized_at IS NULL
     AND (@role IS NULL OR role = @role)
     AND (@like IS NULL OR email LIKE @like ESCAPE '\' OR full_name LIKE @like ESCAPE '\')
   ORDER BY CASE role WHEN 'reception' THEN 1 WHEN 'housekeeping' THEN 2 WHEN 'maintenance' THEN 3 WHEN 'cashier' THEN 4
                      WHEN 'manager' THEN 5 WHEN 'it' THEN 6 WHEN 'steward' THEN 7 ELSE 8 END, full_name;
END;
GO

-- id, email and role of one account that is not anonymized (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_account_get
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id, email, role FROM dbo.account WHERE account_id = @account_id AND anonymized_at IS NULL;
END;
GO

-- Change an account's role.
CREATE OR ALTER PROCEDURE dbo.usp_account_setRole
  @account_id NCHAR(36),
  @role       NVARCHAR(12)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account SET role = @role WHERE account_id = @account_id;
END;
GO

-- Enable or disable an account, and clear failed sign-ins and any lockout.
CREATE OR ALTER PROCEDURE dbo.usp_account_setActive
  @account_id NCHAR(36),
  @active     TINYINT
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account SET active = @active, failed_logins = 0, locked_until = NULL WHERE account_id = @account_id;
END;
GO

-- Replace the second-factor secret.
CREATE OR ALTER PROCEDURE dbo.usp_account_setTotp
  @account_id  NCHAR(36),
  @totp_secret NVARCHAR(64)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account SET totp_secret = @totp_secret WHERE account_id = @account_id;
END;
GO

-- Account names for audit rows. @ids is a JSON array of account ids.
CREATE OR ALTER PROCEDURE dbo.usp_account_labels
  @ids NVARCHAR(MAX)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT account_id, full_name, role
    FROM dbo.account
   WHERE account_id IN (SELECT CAST([value] AS NCHAR(36)) FROM OPENJSON(@ids));
END;
GO

-- How many active IT accounts exist (one value, column n).
CREATE OR ALTER PROCEDURE dbo.usp_account_countActiveIt
AS
BEGIN
  SET NOCOUNT ON;
  SELECT COUNT(*) AS n FROM dbo.account WHERE role = 'it' AND active = 1;
END;
GO

-- True (one row) when another account already uses this email.
CREATE OR ALTER PROCEDURE dbo.usp_account_emailTaken
  @email      NVARCHAR(160),
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS taken FROM dbo.account WHERE email = @email AND account_id <> @account_id;
END;
GO

-- Apply a corrected name, email and phone.
CREATE OR ALTER PROCEDURE dbo.usp_account_correct
  @account_id NCHAR(36),
  @full_name  NVARCHAR(120),
  @email      NVARCHAR(160),
  @phone      NVARCHAR(40)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.account SET full_name = @full_name, email = @email, phone = @phone WHERE account_id = @account_id;
END;
GO

-- Remove identity from an account for a deletion request: blank the emails sent to it, anonymize the account,
-- and wipe the details of its correction requests. The caller wraps this in one transaction.
CREATE OR ALTER PROCEDURE dbo.usp_account_anonymize
  @account_id    NCHAR(36),
  @old_email     NVARCHAR(160),
  @new_email     NVARCHAR(160),
  @password_hash NVARCHAR(255)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.email_log SET to_email = N'deleted@deleted.invalid', body_html = N'[removed on request]' WHERE to_email = @old_email;
  UPDATE dbo.account
     SET email = @new_email, full_name = N'Deleted guest', phone = N'', password_hash = @password_hash, active = 0,
         anonymized_at = DATETRUNC(SECOND, SYSUTCDATETIME()), marketing_consent = 0
   WHERE account_id = @account_id;
  UPDATE dbo.privacy_request SET details = N'[removed]' WHERE account_id = @account_id AND type = N'CORRECTION';
END;
GO

-- The account fields a guest gets in an access request (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_account_getExport
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT email, full_name, phone, marketing_consent, created_at, last_login_at FROM dbo.account WHERE account_id = @account_id;
END;
GO

-- The IT lead created by bin/ensure-admin.php: the account row plus its audit entry.
CREATE OR ALTER PROCEDURE dbo.usp_account_createAdmin
  @account_id    NCHAR(36),
  @email         NVARCHAR(160),
  @password_hash NVARCHAR(255),
  @full_name     NVARCHAR(120),
  @totp_secret   NVARCHAR(64),
  @detail        NVARCHAR(MAX)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.account (account_id, email, password_hash, role, full_name, phone, totp_secret, created_at)
  VALUES (@account_id, @email, @password_hash, N'it', @full_name, N'', @totp_secret, DATETRUNC(SECOND, SYSUTCDATETIME()));
  INSERT INTO dbo.audit_log (at, actor_id, actor_role, action, target_type, target_id, detail, ip)
  VALUES (SYSUTCDATETIME(), N'system', N'it', N'user.created', N'account', @account_id, CAST(@detail AS JSON), NULL);
END;
GO

-- ---------------------------------------------------------------------------
-- Password reset and sessions
-- ---------------------------------------------------------------------------

-- Store a one-time reset token (hash only) that expires @ttl_seconds from now.
CREATE OR ALTER PROCEDURE dbo.usp_reset_create
  @token_hash  NCHAR(64),
  @account_id  NCHAR(36),
  @ttl_seconds INT
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.password_reset (token_hash, account_id, expires_at, created_at)
  VALUES (@token_hash, @account_id, DATEADD(SECOND, @ttl_seconds, DATETRUNC(SECOND, SYSUTCDATETIME())), DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- One row when the token exists, is unused and has not expired.
CREATE OR ALTER PROCEDURE dbo.usp_reset_isValid
  @token_hash NCHAR(64)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT 1 AS valid FROM dbo.password_reset
   WHERE token_hash = @token_hash AND used_at IS NULL AND expires_at > DATETRUNC(SECOND, SYSUTCDATETIME());
END;
GO

-- Lock and return the account behind a valid reset token (no row when the token is bad or the account is disabled).
CREATE OR ALTER PROCEDURE dbo.usp_reset_lock
  @token_hash NCHAR(64)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT r.account_id, a.email
    FROM dbo.password_reset r WITH (UPDLOCK, ROWLOCK)
    JOIN dbo.account a WITH (UPDLOCK, ROWLOCK) ON a.account_id = r.account_id
   WHERE r.token_hash = @token_hash AND r.used_at IS NULL AND r.expires_at > DATETRUNC(SECOND, SYSUTCDATETIME()) AND a.active = 1;
END;
GO

-- Mark every unused reset token of an account as used.
CREATE OR ALTER PROCEDURE dbo.usp_reset_markUsed
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.password_reset SET used_at = DATETRUNC(SECOND, SYSUTCDATETIME()) WHERE account_id = @account_id AND used_at IS NULL;
END;
GO

-- Sign an account out everywhere. Session data is the serialized PHP session, which holds the account id as plain text.
CREATE OR ALTER PROCEDURE dbo.usp_session_dropForAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  DELETE FROM dbo.session WHERE data LIKE N'%' + @account_id + N'%';
END;
GO

-- ---------------------------------------------------------------------------
-- Privacy requests
-- ---------------------------------------------------------------------------

-- How many open or in-review requests of this type the account has (one value, column n).
CREATE OR ALTER PROCEDURE dbo.usp_privacy_countOpen
  @account_id NCHAR(36),
  @type       NVARCHAR(10)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT COUNT(*) AS n FROM dbo.privacy_request WHERE account_id = @account_id AND type = @type AND status IN (N'OPEN', N'IN_REVIEW');
END;
GO

-- File a request. It is due 30 days from now.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_file
  @request_id NCHAR(36),
  @account_id NCHAR(36),
  @type       NVARCHAR(10),
  @details    NVARCHAR(600)
AS
BEGIN
  SET NOCOUNT ON;
  INSERT INTO dbo.privacy_request (request_id, account_id, type, details, status, due_at, created_at)
  VALUES (@request_id, @account_id, @type, @details, N'OPEN', DATEADD(DAY, 30, DATETRUNC(SECOND, SYSUTCDATETIME())), DATETRUNC(SECOND, SYSUTCDATETIME()));
END;
GO

-- All requests of one account, newest first.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_listForAccount
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT request_id, type, status, details, resolution_note, due_at, created_at, completed_at,
         CASE WHEN result IS NOT NULL THEN 1 ELSE 0 END AS has_result
    FROM dbo.vw_PrivacyRequest
   WHERE account_id = @account_id
   ORDER BY created_at DESC;
END;
GO

-- The data file of a completed access request, only for its owner (no row otherwise).
CREATE OR ALTER PROCEDURE dbo.usp_privacy_getResult
  @request_id NCHAR(36),
  @account_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT result FROM dbo.vw_PrivacyRequest
   WHERE request_id = @request_id AND account_id = @account_id AND status = N'COMPLETED' AND type = N'ACCESS';
END;
GO

-- Up to 100 requests by due date. @status NULL = open and in review.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_queue
  @status NVARCHAR(9) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (100) request_id, type, status, details, resolution_note, due_at, created_at, completed_at, account_id, email, full_name, overdue
    FROM dbo.vw_PrivacyRequest
   WHERE (@status IS NULL AND status IN (N'OPEN', N'IN_REVIEW')) OR status = @status
   ORDER BY due_at;
END;
GO

-- One request with the filer's email and name (no row when unknown).
CREATE OR ALTER PROCEDURE dbo.usp_privacy_get
  @request_id NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT request_id, account_id, type, details, status, handled_by, resolution_note, result, due_at, created_at, completed_at, email, full_name
    FROM dbo.vw_PrivacyRequest
   WHERE request_id = @request_id;
END;
GO

-- Move an open request to review. affected = 0 when it was not open.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_startReview
  @request_id NCHAR(36),
  @handled_by NCHAR(36)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.privacy_request SET status = N'IN_REVIEW', handled_by = @handled_by WHERE request_id = @request_id AND status = N'OPEN';
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Reject an open or in-review request with a note. affected = 0 when it was already closed.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_reject
  @request_id NCHAR(36),
  @handled_by NCHAR(36),
  @note       NVARCHAR(400)
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.privacy_request
     SET status = N'REJECTED', handled_by = @handled_by, resolution_note = @note, completed_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE request_id = @request_id AND status IN (N'OPEN', N'IN_REVIEW');
  SELECT @@ROWCOUNT AS affected;
END;
GO

-- Close a request as completed, with an optional note and the access-request data file.
CREATE OR ALTER PROCEDURE dbo.usp_privacy_complete
  @request_id NCHAR(36),
  @handled_by NCHAR(36),
  @note       NVARCHAR(400) = NULL,
  @result     NVARCHAR(MAX) = NULL
AS
BEGIN
  SET NOCOUNT ON;
  UPDATE dbo.privacy_request
     SET status = N'COMPLETED', handled_by = @handled_by, resolution_note = @note, result = @result,
         completed_at = DATETRUNC(SECOND, SYSUTCDATETIME())
   WHERE request_id = @request_id;
END;
GO

-- ---------------------------------------------------------------------------
-- Email log and notification jobs (IT oversight)
-- ---------------------------------------------------------------------------

-- Emails sent to one address, oldest first (subject and status only).
CREATE OR ALTER PROCEDURE dbo.usp_email_listFor
  @to_email NVARCHAR(160)
AS
BEGIN
  SET NOCOUNT ON;
  SELECT template, subject, status, created_at FROM dbo.vw_EmailLog WHERE to_email = @to_email ORDER BY created_at;
END;
GO

-- The newest emails, subject and status only, never the body. @limit is clamped by the caller.
CREATE OR ALTER PROCEDURE dbo.usp_email_listRecent
  @limit INT = 25
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (@limit) email_id, template, to_email, subject, status, created_at FROM dbo.vw_EmailLog ORDER BY created_at DESC;
END;
GO

-- Notification jobs counted by status (columns status, n).
CREATE OR ALTER PROCEDURE dbo.usp_oversight_jobCounts
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status, COUNT(*) AS n FROM dbo.notification_job GROUP BY status;
END;
GO

-- The 20 newest jobs that failed or were retried while still pending.
CREATE OR ALTER PROCEDURE dbo.usp_oversight_jobsNeedingAttention
AS
BEGIN
  SET NOCOUNT ON;
  SELECT TOP (20) job_id, type, attempts, last_error, created_at
    FROM dbo.notification_job
   WHERE status IN (N'FAILED') OR (status = N'PENDING' AND attempts > 0)
   ORDER BY created_at DESC;
END;
GO

-- Emails of the last 7 days counted by status (columns status, n).
CREATE OR ALTER PROCEDURE dbo.usp_oversight_emailCounts
AS
BEGIN
  SET NOCOUNT ON;
  SELECT status, COUNT(*) AS n FROM dbo.email_log
   WHERE created_at > DATEADD(DAY, -7, DATETRUNC(SECOND, SYSUTCDATETIME()))
   GROUP BY status;
END;
GO

-- When the oldest pending job was created (one value, NULL when none are pending).
CREATE OR ALTER PROCEDURE dbo.usp_oversight_oldestPendingJob
AS
BEGIN
  SET NOCOUNT ON;
  SELECT MIN(created_at) AS oldest FROM dbo.notification_job WHERE status = N'PENDING';
END;
GO
