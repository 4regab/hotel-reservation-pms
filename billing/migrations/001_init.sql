-- Billing database (SQL Server 2025). Owns folios, charges, payments, refunds.
-- Money is integer centavos. Only payment references are stored: never card numbers or CVV.

CREATE TABLE folio (
  folio_id       NCHAR(36)  NOT NULL,
  reservation_id NCHAR(36)  NOT NULL,               -- REF Booking reservation
  status         NVARCHAR(6) NOT NULL,
  total_minor    BIGINT     NOT NULL,
  currency       NCHAR(3)   NOT NULL,
  created_at     DATETIME2(0) NOT NULL,
  CONSTRAINT pk_folio PRIMARY KEY (folio_id),
  CONSTRAINT uq_folio_reservation UNIQUE (reservation_id),
  CONSTRAINT ck_folio_status CHECK (status IN (N'OPEN', N'CLOSED')),
  CONSTRAINT ck_folio_total CHECK (total_minor >= 0)
);

CREATE TABLE charge (
  charge_id    NCHAR(36)     NOT NULL,
  folio_id     NCHAR(36)     NOT NULL,
  description  NVARCHAR(120) NOT NULL,
  amount_minor BIGINT        NOT NULL,
  currency     NCHAR(3)      NOT NULL,
  created_by   NVARCHAR(64)  NOT NULL,
  created_at   DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_charge PRIMARY KEY (charge_id),
  CONSTRAINT fk_charge_folio FOREIGN KEY (folio_id) REFERENCES folio (folio_id),
  CONSTRAINT ck_charge_amount CHECK (amount_minor > 0)
);

CREATE TABLE payment (
  payment_id          NCHAR(36)    NOT NULL,
  reservation_id      NCHAR(36)    NOT NULL,        -- REF Booking reservation
  folio_id            NCHAR(36)    NULL,            -- set once the bill is open
  provider            NVARCHAR(16) NOT NULL,        -- PAYMONGO or ONSITE
  provider_payment_id NVARCHAR(80) NOT NULL,        -- a reference, never card data
  provider_event_id   NVARCHAR(80) NULL,
  amount_minor        BIGINT       NOT NULL,
  currency            NCHAR(3)     NOT NULL,
  status              NVARCHAR(8)  NOT NULL,
  created_at          DATETIME2(0) NOT NULL,
  CONSTRAINT pk_payment PRIMARY KEY (payment_id),
  CONSTRAINT uq_payment_provider_payment UNIQUE (provider_payment_id),
  CONSTRAINT fk_payment_folio FOREIGN KEY (folio_id) REFERENCES folio (folio_id),
  CONSTRAINT ck_payment_status CHECK (status IN (N'CAPTURED', N'REVIEW')),
  CONSTRAINT ck_payment_amount CHECK (amount_minor > 0)
);
-- Many payments may have no provider event id (on-site, OTA), so only non-NULL values are unique.
CREATE UNIQUE INDEX uq_payment_provider_event ON payment (provider_event_id) WHERE provider_event_id IS NOT NULL;
CREATE INDEX ix_payment_reservation ON payment (reservation_id, status);

CREATE TABLE refund (
  refund_id    NCHAR(36)    NOT NULL,
  payment_id   NCHAR(36)    NOT NULL,
  amount_minor BIGINT       NOT NULL,
  approval     NVARCHAR(8)  NOT NULL,
  status       NVARCHAR(9)  NOT NULL,
  requested_by NVARCHAR(64) NOT NULL,               -- account id, or 'system'
  approved_by  NCHAR(36)    NULL,
  reason       NVARCHAR(40) NOT NULL,
  created_at   DATETIME2(0) NOT NULL,
  completed_at DATETIME2(0) NULL,
  CONSTRAINT pk_refund PRIMARY KEY (refund_id),
  CONSTRAINT uq_refund_payment UNIQUE (payment_id), -- one refund request per payment
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payment (payment_id),
  CONSTRAINT ck_refund_approval CHECK (approval IN (N'PENDING', N'APPROVED')),
  CONSTRAINT ck_refund_status CHECK (status IN (N'REQUESTED', N'COMPLETED')),
  CONSTRAINT ck_refund_amount CHECK (amount_minor > 0)
);

-- Hosted checkout sessions we created; the webhook amount must match the saved price.
CREATE TABLE checkout_session (
  session_id     NVARCHAR(80)  NOT NULL,
  reservation_id NCHAR(36)     NOT NULL,
  amount_minor   BIGINT        NOT NULL,
  currency       NCHAR(3)      NOT NULL,
  status         NVARCHAR(7)   NOT NULL,
  checkout_url   NVARCHAR(400) NOT NULL,
  created_at     DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_checkout_session PRIMARY KEY (session_id),
  CONSTRAINT ck_checkout_session_status CHECK (status IN (N'PENDING', N'PAID'))
);
CREATE INDEX ix_session_reservation ON checkout_session (reservation_id);

-- ---------------------------------------------------------------------------
-- Support records (same shape in every service database)
-- ---------------------------------------------------------------------------

-- Idempotency-Key records, kept 24 hours by the application.
CREATE TABLE idempotency_key (
  idem_key        NVARCHAR(128) NOT NULL,
  caller          NVARCHAR(64)  NOT NULL,
  request_hash    NCHAR(64)     NOT NULL,
  response_status SMALLINT      NOT NULL,
  response_body   NVARCHAR(MAX) NOT NULL,
  created_at      DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_idempotency_key PRIMARY KEY (idem_key, caller)
);
CREATE INDEX ix_idem_created ON idempotency_key (created_at);

-- Transactional outbox: written in the same transaction as the business change.
CREATE TABLE outbox (
  event_id          NCHAR(36)    NOT NULL,
  event_type        NVARCHAR(64) NOT NULL,
  aggregate_id      NCHAR(36)    NOT NULL,
  aggregate_version INT          NOT NULL,
  correlation_id    NCHAR(36)    NOT NULL,
  payload           JSON         NOT NULL,
  created_at        DATETIME2(0) NOT NULL,
  CONSTRAINT pk_outbox PRIMARY KEY (event_id)
);
CREATE INDEX ix_outbox_created ON outbox (created_at);

-- One delivery row per handler. After 5 failed attempts the row is flagged REVIEW (never deleted).
CREATE TABLE outbox_delivery (
  event_id        NCHAR(36)    NOT NULL,
  handler_name    NVARCHAR(32) NOT NULL,
  status          NVARCHAR(9)  NOT NULL DEFAULT N'PENDING',
  attempt_count   INT          NOT NULL DEFAULT 0,
  next_attempt_at DATETIME2(0) NOT NULL,
  delivered_at    DATETIME2(0) NULL,
  last_error      NVARCHAR(255) NULL,
  CONSTRAINT pk_outbox_delivery PRIMARY KEY (event_id, handler_name),
  CONSTRAINT fk_delivery_event FOREIGN KEY (event_id) REFERENCES outbox (event_id),
  CONSTRAINT ck_outbox_delivery_status CHECK (status IN (N'PENDING', N'DELIVERED', N'REVIEW')),
  CONSTRAINT ck_delivery_attempts CHECK (attempt_count >= 0)
);
CREATE INDEX ix_delivery_due ON outbox_delivery (status, next_attempt_at);

-- Inbox: consumers record each eventId with their local update, so retries are safe.
CREATE TABLE inbox (
  event_id    NCHAR(36)    NOT NULL,
  received_at DATETIME2(0) NOT NULL,
  CONSTRAINT pk_inbox PRIMARY KEY (event_id)
);
