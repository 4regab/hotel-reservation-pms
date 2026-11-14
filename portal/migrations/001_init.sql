-- Portal database (SQL Server 2025). Owns accounts, sessions, notification jobs and read-only reporting copies.
-- It cannot authorize a sale or change a payment.

CREATE TABLE account (
  account_id    NCHAR(36)     NOT NULL,
  email         NVARCHAR(160) NOT NULL,
  password_hash NVARCHAR(255) NOT NULL,                 -- password_hash() output, never plain text
  role          NVARCHAR(12)  NOT NULL,
  full_name     NVARCHAR(120) NOT NULL,
  phone         NVARCHAR(40)  NOT NULL,
  totp_secret   NVARCHAR(64)  NULL,                     -- staff second factor (base32)
  created_at    DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_account PRIMARY KEY (account_id),
  CONSTRAINT uq_account_email UNIQUE (email),
  CONSTRAINT ck_account_role CHECK (role IN (N'guest', N'reception', N'housekeeping', N'maintenance', N'cashier', N'manager', N'it', N'steward'))
);

CREATE TABLE session (
  session_id NVARCHAR(128) NOT NULL,
  data       NVARCHAR(MAX) NOT NULL,
  updated_at INT           NOT NULL,
  CONSTRAINT pk_session PRIMARY KEY (session_id)
);
CREATE INDEX ix_session_updated ON session (updated_at);

CREATE TABLE notification_job (
  job_id         NCHAR(36)    NOT NULL,
  type           NVARCHAR(32) NOT NULL,
  reservation_id NCHAR(36)    NOT NULL,
  status         NVARCHAR(7)  NOT NULL,
  attempts       INT          NOT NULL DEFAULT 0,
  created_at     DATETIME2(0) NOT NULL,
  sent_at        DATETIME2(0) NULL,
  CONSTRAINT pk_notification_job PRIMARY KEY (job_id),
  CONSTRAINT ck_notification_job_status CHECK (status IN (N'PENDING', N'SENT', N'FAILED'))
);
CREATE INDEX ix_job_status ON notification_job (status, created_at);

-- Read-only reporting copy, one row per Manila calendar day. Shows its last update time.
CREATE TABLE report_daily (
  day            DATE         NOT NULL,
  confirmed      INT          NOT NULL DEFAULT 0,
  cancelled      INT          NOT NULL DEFAULT 0,
  revenue_minor  BIGINT       NOT NULL DEFAULT 0,
  refunded_minor BIGINT       NOT NULL DEFAULT 0,
  check_ins      INT          NOT NULL DEFAULT 0,
  check_outs     INT          NOT NULL DEFAULT 0,
  updated_at     DATETIME2(0) NOT NULL,
  CONSTRAINT pk_report_daily PRIMARY KEY (day)
);

CREATE TABLE rate_limit (
  bucket       NVARCHAR(120) NOT NULL,
  window_start INT           NOT NULL,
  hits         INT           NOT NULL,
  CONSTRAINT pk_rate_limit PRIMARY KEY (bucket, window_start)
);

-- Inbox: the portal consumes events from the other services and deduplicates by eventId.
CREATE TABLE inbox (
  event_id    NCHAR(36)    NOT NULL,
  received_at DATETIME2(0) NOT NULL,
  CONSTRAINT pk_inbox PRIMARY KEY (event_id)
);
