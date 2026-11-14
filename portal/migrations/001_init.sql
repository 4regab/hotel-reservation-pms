-- Portal database (MySQL 8, InnoDB). Owns accounts, sessions, notification jobs and read-only reporting copies.
-- It cannot authorize a sale or change a payment.

CREATE TABLE account (
  account_id    CHAR(36)     NOT NULL,
  email         VARCHAR(160) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,                 -- password_hash() output, never plain text
  role          ENUM('guest','reception','housekeeping','maintenance','cashier','manager','it','steward') NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  phone         VARCHAR(40)  NOT NULL,
  totp_secret   VARCHAR(64)  NULL,                     -- staff second factor (base32)
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (account_id),
  UNIQUE KEY uq_account_email (email)
) ENGINE=InnoDB;

CREATE TABLE session (
  session_id VARCHAR(128) NOT NULL,
  data       MEDIUMTEXT   NOT NULL,
  updated_at INT          NOT NULL,
  PRIMARY KEY (session_id),
  KEY ix_session_updated (updated_at)
) ENGINE=InnoDB;

CREATE TABLE notification_job (
  job_id         CHAR(36)    NOT NULL,
  type           VARCHAR(32) NOT NULL,
  reservation_id CHAR(36)    NOT NULL,
  status         ENUM('PENDING','SENT','FAILED') NOT NULL,
  attempts       INT         NOT NULL DEFAULT 0,
  created_at     DATETIME    NOT NULL,
  sent_at        DATETIME    NULL,
  PRIMARY KEY (job_id),
  KEY ix_job_status (status, created_at)
) ENGINE=InnoDB;

-- Read-only reporting copy, one row per Manila calendar day. Shows its last update time.
CREATE TABLE report_daily (
  day            DATE     NOT NULL,
  confirmed      INT      NOT NULL DEFAULT 0,
  cancelled      INT      NOT NULL DEFAULT 0,
  revenue_minor  BIGINT   NOT NULL DEFAULT 0,
  refunded_minor BIGINT   NOT NULL DEFAULT 0,
  check_ins      INT      NOT NULL DEFAULT 0,
  check_outs     INT      NOT NULL DEFAULT 0,
  updated_at     DATETIME NOT NULL,
  PRIMARY KEY (day)
) ENGINE=InnoDB;

CREATE TABLE rate_limit (
  bucket       VARCHAR(120) NOT NULL,
  window_start INT          NOT NULL,
  hits         INT          NOT NULL,
  PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB;

-- Inbox: the portal consumes events from the other services and deduplicates by eventId.
CREATE TABLE inbox (
  event_id    CHAR(36) NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB;
