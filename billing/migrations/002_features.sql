-- Billing: payment methods and reconciliation, refund rejection, approved accounting exports, audit trail.

ALTER TABLE payment
  ADD COLUMN method        VARCHAR(20) NULL,
  ADD COLUMN reconciled_at DATETIME    NULL,
  ADD COLUMN reconciled_by CHAR(36)    NULL,
  MODIFY COLUMN provider VARCHAR(16) NOT NULL,
  ADD KEY ix_payment_created (created_at),
  ADD KEY ix_payment_recon (reconciled_at, created_at);

ALTER TABLE refund
  MODIFY COLUMN approval ENUM('PENDING','APPROVED','REJECTED') NOT NULL,
  MODIFY COLUMN status ENUM('REQUESTED','COMPLETED','REJECTED') NOT NULL,
  ADD COLUMN decision_note VARCHAR(200) NULL,
  ADD KEY ix_refund_status (status, created_at);

-- A finance export is requested for a date range, approved by a manager (never the requester),
-- and only then downloadable. The CSV is stored with the batch so a retry returns the same file.
CREATE TABLE accounting_export (
  export_id    CHAR(36)    NOT NULL,
  batch_ref    VARCHAR(24) NOT NULL,
  period_from  DATE        NOT NULL,
  period_to    DATE        NOT NULL,
  status       ENUM('REQUESTED','APPROVED','REJECTED') NOT NULL,
  row_count    INT         NOT NULL,
  total_minor  BIGINT      NOT NULL,
  csv          MEDIUMTEXT  NOT NULL,
  checksum     CHAR(64)    NOT NULL,
  requested_by CHAR(36)    NOT NULL,
  approved_by  CHAR(36)    NULL,
  decided_at   DATETIME    NULL,
  downloads    INT         NOT NULL DEFAULT 0,
  created_at   DATETIME    NOT NULL,
  PRIMARY KEY (export_id),
  UNIQUE KEY uq_export_batch (batch_ref),
  KEY ix_export_status (status, created_at),
  CONSTRAINT ck_export_period CHECK (period_to >= period_from)
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
