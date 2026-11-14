-- Portal: account controls, password reset, real email jobs and log, privacy requests, audit trail.

ALTER TABLE account
  ADD COLUMN active            TINYINT  NOT NULL DEFAULT 1,
  ADD COLUMN failed_logins     INT      NOT NULL DEFAULT 0,
  ADD COLUMN locked_until      DATETIME NULL,
  ADD COLUMN last_login_at     DATETIME NULL,
  ADD COLUMN marketing_consent TINYINT  NOT NULL DEFAULT 0,
  ADD COLUMN anonymized_at     DATETIME NULL,
  ADD KEY ix_account_role (role, active);

-- Only a hash of the reset token is stored. The email job holds the raw token until it is sent, then clears it.
CREATE TABLE password_reset (
  token_hash CHAR(64) NOT NULL,
  account_id CHAR(36) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (token_hash),
  KEY ix_reset_account (account_id),
  CONSTRAINT fk_reset_account FOREIGN KEY (account_id) REFERENCES account (account_id)
) ENGINE=InnoDB;

ALTER TABLE notification_job
  MODIFY COLUMN reservation_id CHAR(36) NULL,
  MODIFY COLUMN type VARCHAR(40) NOT NULL,
  ADD COLUMN account_id CHAR(36) NULL,
  ADD COLUMN payload    JSON     NULL,
  ADD COLUMN last_error VARCHAR(200) NULL,
  ADD COLUMN next_attempt_at DATETIME NULL;

-- Every email, whether sent through Resend or only logged (no key configured).
CREATE TABLE email_log (
  email_id    CHAR(36)     NOT NULL,
  template    VARCHAR(40)  NOT NULL,
  to_email    VARCHAR(160) NOT NULL,
  subject     VARCHAR(200) NOT NULL,
  body_html   MEDIUMTEXT   NOT NULL,
  status      ENUM('SENT','LOGGED','FAILED') NOT NULL,
  provider_id VARCHAR(80)  NULL,
  error       VARCHAR(200) NULL,
  job_id      CHAR(36)     NULL,
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (email_id),
  KEY ix_email_to (to_email, created_at),
  KEY ix_email_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE privacy_request (
  request_id      CHAR(36)     NOT NULL,
  account_id      CHAR(36)     NOT NULL,
  type            ENUM('ACCESS','CORRECTION','DELETION') NOT NULL,
  details         VARCHAR(600) NOT NULL,
  status          ENUM('OPEN','IN_REVIEW','COMPLETED','REJECTED') NOT NULL,
  handled_by      CHAR(36)     NULL,
  resolution_note VARCHAR(400) NULL,
  result          MEDIUMTEXT   NULL,
  due_at          DATETIME     NOT NULL,
  created_at      DATETIME     NOT NULL,
  completed_at    DATETIME     NULL,
  PRIMARY KEY (request_id),
  KEY ix_privacy_status (status, created_at),
  KEY ix_privacy_account (account_id),
  CONSTRAINT fk_privacy_account FOREIGN KEY (account_id) REFERENCES account (account_id)
) ENGINE=InnoDB;

CREATE TABLE audit_log (
  audit_id    BIGINT       NOT NULL AUTO_INCREMENT,
  at          DATETIME(3)  NOT NULL,
  actor_id    VARCHAR(64)  NOT NULL,
  actor_role  VARCHAR(20)  NOT NULL,
  action      VARCHAR(60)  NOT NULL,
  target_type VARCHAR(30)  NOT NULL,
  target_id   VARCHAR(64)  NOT NULL,
  detail      JSON         NULL,
  ip          VARCHAR(45)  NULL,
  PRIMARY KEY (audit_id),
  KEY ix_audit_at (at),
  KEY ix_audit_actor (actor_id),
  KEY ix_audit_action (action)
) ENGINE=InnoDB;
