-- Billing: payment methods and reconciliation, refund rejection, approved accounting exports, audit trail.

ALTER TABLE payment ADD
  method        NVARCHAR(20) NULL,
  reconciled_at DATETIME2(0) NULL,
  reconciled_by NCHAR(36)    NULL;
ALTER TABLE payment ALTER COLUMN provider NVARCHAR(16) NOT NULL;
GO
CREATE INDEX ix_payment_created ON payment (created_at);
CREATE INDEX ix_payment_recon ON payment (reconciled_at, created_at);

ALTER TABLE refund DROP CONSTRAINT ck_refund_approval;
ALTER TABLE refund DROP CONSTRAINT ck_refund_status;
ALTER TABLE refund ADD
  decision_note NVARCHAR(200) NULL,
  CONSTRAINT ck_refund_approval CHECK (approval IN (N'PENDING', N'APPROVED', N'REJECTED')),
  CONSTRAINT ck_refund_status CHECK (status IN (N'REQUESTED', N'COMPLETED', N'REJECTED'));
CREATE INDEX ix_refund_status ON refund (status, created_at);

-- A finance export is requested for a date range, approved by a manager (never the requester),
-- and only then downloadable. The CSV is stored with the batch so a retry returns the same file.
CREATE TABLE accounting_export (
  export_id    NCHAR(36)     NOT NULL,
  batch_ref    NVARCHAR(24)  NOT NULL,
  period_from  DATE          NOT NULL,
  period_to    DATE          NOT NULL,
  status       NVARCHAR(9)   NOT NULL,
  row_count    INT           NOT NULL,
  total_minor  BIGINT        NOT NULL,
  csv          NVARCHAR(MAX) NOT NULL,
  checksum     NCHAR(64)     NOT NULL,
  requested_by NCHAR(36)     NOT NULL,
  approved_by  NCHAR(36)     NULL,
  decided_at   DATETIME2(0)  NULL,
  downloads    INT           NOT NULL DEFAULT 0,
  created_at   DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_accounting_export PRIMARY KEY (export_id),
  CONSTRAINT uq_export_batch UNIQUE (batch_ref),
  CONSTRAINT ck_accounting_export_status CHECK (status IN (N'REQUESTED', N'APPROVED', N'REJECTED')),
  CONSTRAINT ck_export_period CHECK (period_to >= period_from)
);
CREATE INDEX ix_export_status ON accounting_export (status, created_at);

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
