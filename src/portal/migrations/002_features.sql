-- Portal: account controls, password reset, real email jobs and log, privacy requests, audit trail.

ALTER TABLE account ADD
  active            TINYINT      NOT NULL CONSTRAINT df_account_active DEFAULT 1,
  failed_logins     INT          NOT NULL CONSTRAINT df_account_failed_logins DEFAULT 0,
  locked_until      DATETIME2(0) NULL,
  last_login_at     DATETIME2(0) NULL,
  marketing_consent TINYINT      NOT NULL CONSTRAINT df_account_marketing_consent DEFAULT 0,
  anonymized_at     DATETIME2(0) NULL;
GO

CREATE INDEX ix_account_role ON account (role, active);

-- Only a hash of the reset token is stored. The email job holds the raw token until it is sent, then clears it.
CREATE TABLE password_reset (
  token_hash NCHAR(64)    NOT NULL,
  account_id NCHAR(36)    NOT NULL,
  expires_at DATETIME2(0) NOT NULL,
  used_at    DATETIME2(0) NULL,
  created_at DATETIME2(0) NOT NULL,
  CONSTRAINT pk_password_reset PRIMARY KEY (token_hash),
  CONSTRAINT fk_reset_account FOREIGN KEY (account_id) REFERENCES account (account_id)
);
CREATE INDEX ix_reset_account ON password_reset (account_id);

ALTER TABLE notification_job ALTER COLUMN reservation_id NCHAR(36) NULL;
ALTER TABLE notification_job ALTER COLUMN type NVARCHAR(40) NOT NULL;
ALTER TABLE notification_job ADD
  account_id      NCHAR(36)     NULL,
  payload         JSON          NULL,
  last_error      NVARCHAR(200) NULL,
  next_attempt_at DATETIME2(0)  NULL;

-- Every email, whether sent through Resend or only logged (no key configured).
CREATE TABLE email_log (
  email_id    NCHAR(36)     NOT NULL,
  template    NVARCHAR(40)  NOT NULL,
  to_email    NVARCHAR(160) NOT NULL,
  subject     NVARCHAR(200) NOT NULL,
  body_html   NVARCHAR(MAX) NOT NULL,
  status      NVARCHAR(6)   NOT NULL,
  provider_id NVARCHAR(80)  NULL,
  error       NVARCHAR(200) NULL,
  job_id      NCHAR(36)     NULL,
  created_at  DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_email_log PRIMARY KEY (email_id),
  CONSTRAINT ck_email_log_status CHECK (status IN (N'SENT', N'LOGGED', N'FAILED'))
);
CREATE INDEX ix_email_to ON email_log (to_email, created_at);
CREATE INDEX ix_email_status ON email_log (status, created_at);

CREATE TABLE privacy_request (
  request_id      NCHAR(36)     NOT NULL,
  account_id      NCHAR(36)     NOT NULL,
  type            NVARCHAR(10)  NOT NULL,
  details         NVARCHAR(600) NOT NULL,
  status          NVARCHAR(9)   NOT NULL,
  handled_by      NCHAR(36)     NULL,
  resolution_note NVARCHAR(400) NULL,
  result          NVARCHAR(MAX) NULL,
  due_at          DATETIME2(0)  NOT NULL,
  created_at      DATETIME2(0)  NOT NULL,
  completed_at    DATETIME2(0)  NULL,
  CONSTRAINT pk_privacy_request PRIMARY KEY (request_id),
  CONSTRAINT fk_privacy_account FOREIGN KEY (account_id) REFERENCES account (account_id),
  CONSTRAINT ck_privacy_request_type CHECK (type IN (N'ACCESS', N'CORRECTION', N'DELETION')),
  CONSTRAINT ck_privacy_request_status CHECK (status IN (N'OPEN', N'IN_REVIEW', N'COMPLETED', N'REJECTED'))
);
CREATE INDEX ix_privacy_status ON privacy_request (status, created_at);
CREATE INDEX ix_privacy_account ON privacy_request (account_id);

CREATE TABLE audit_log (
  audit_id    BIGINT        NOT NULL IDENTITY(1,1),
  at          DATETIME2(3)  NOT NULL,
  actor_id    NVARCHAR(64)  NOT NULL,
  actor_role  NVARCHAR(20)  NOT NULL,
  action      NVARCHAR(60)  NOT NULL,
  target_type NVARCHAR(30)  NOT NULL,
  target_id   NVARCHAR(64)  NOT NULL,
  detail      JSON          NULL,
  ip          NVARCHAR(45)  NULL,
  CONSTRAINT pk_audit_log PRIMARY KEY (audit_id)
);
CREATE INDEX ix_audit_at ON audit_log (at);
CREATE INDEX ix_audit_actor ON audit_log (actor_id);
CREATE INDEX ix_audit_action ON audit_log (action);
