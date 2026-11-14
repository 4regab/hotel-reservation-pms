-- Billing database (MySQL 8, InnoDB). Owns folios, charges, payments, refunds.
-- Money is integer centavos. Only payment references are stored: never card numbers or CVV.

CREATE TABLE folio (
  folio_id       CHAR(36)   NOT NULL,
  reservation_id CHAR(36)   NOT NULL,               -- REF Booking reservation
  status         ENUM('OPEN','CLOSED') NOT NULL,
  total_minor    BIGINT     NOT NULL,
  currency       CHAR(3)    NOT NULL,
  created_at     DATETIME   NOT NULL,
  PRIMARY KEY (folio_id),
  UNIQUE KEY uq_folio_reservation (reservation_id),
  CONSTRAINT ck_folio_total CHECK (total_minor >= 0)
) ENGINE=InnoDB;

CREATE TABLE charge (
  charge_id    CHAR(36)     NOT NULL,
  folio_id     CHAR(36)     NOT NULL,
  description  VARCHAR(120) NOT NULL,
  amount_minor BIGINT       NOT NULL,
  currency     CHAR(3)      NOT NULL,
  created_by   VARCHAR(64)  NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (charge_id),
  CONSTRAINT fk_charge_folio FOREIGN KEY (folio_id) REFERENCES folio (folio_id),
  CONSTRAINT ck_charge_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB;

CREATE TABLE payment (
  payment_id          CHAR(36)    NOT NULL,
  reservation_id      CHAR(36)    NOT NULL,         -- REF Booking reservation
  folio_id            CHAR(36)    NULL,             -- set once the bill is open
  provider            VARCHAR(16) NOT NULL,         -- PAYMONGO or ONSITE
  provider_payment_id VARCHAR(80) NOT NULL,         -- a reference, never card data
  provider_event_id   VARCHAR(80) NULL,
  amount_minor        BIGINT      NOT NULL,
  currency            CHAR(3)     NOT NULL,
  status              ENUM('CAPTURED','REVIEW') NOT NULL,
  created_at          DATETIME    NOT NULL,
  PRIMARY KEY (payment_id),
  UNIQUE KEY uq_payment_provider_payment (provider_payment_id),
  UNIQUE KEY uq_payment_provider_event (provider_event_id),
  CONSTRAINT fk_payment_folio FOREIGN KEY (folio_id) REFERENCES folio (folio_id),
  CONSTRAINT ck_payment_amount CHECK (amount_minor > 0),
  KEY ix_payment_reservation (reservation_id, status)
) ENGINE=InnoDB;

CREATE TABLE refund (
  refund_id    CHAR(36)    NOT NULL,
  payment_id   CHAR(36)    NOT NULL,
  amount_minor BIGINT      NOT NULL,
  approval     ENUM('PENDING','APPROVED') NOT NULL,
  status       ENUM('REQUESTED','COMPLETED') NOT NULL,
  requested_by VARCHAR(64) NOT NULL,                -- account id, or 'system'
  approved_by  CHAR(36)    NULL,
  reason       VARCHAR(40) NOT NULL,
  created_at   DATETIME    NOT NULL,
  completed_at DATETIME    NULL,
  PRIMARY KEY (refund_id),
  UNIQUE KEY uq_refund_payment (payment_id),       -- one refund request per payment
  CONSTRAINT fk_refund_payment FOREIGN KEY (payment_id) REFERENCES payment (payment_id),
  CONSTRAINT ck_refund_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB;

-- Hosted checkout sessions we created; the webhook amount must match the saved price.
CREATE TABLE checkout_session (
  session_id     VARCHAR(80) NOT NULL,
  reservation_id CHAR(36)    NOT NULL,
  amount_minor   BIGINT      NOT NULL,
  currency       CHAR(3)     NOT NULL,
  status         ENUM('PENDING','PAID') NOT NULL,
  checkout_url   VARCHAR(400) NOT NULL,
  created_at     DATETIME    NOT NULL,
  PRIMARY KEY (session_id),
  KEY ix_session_reservation (reservation_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Support records (same shape in every service database)
-- ---------------------------------------------------------------------------

-- Idempotency-Key records, kept 24 hours by the application.
CREATE TABLE idempotency_key (
  idem_key        VARCHAR(128) NOT NULL,
  caller          VARCHAR(64)  NOT NULL,
  request_hash    CHAR(64)     NOT NULL,
  response_status SMALLINT     NOT NULL,
  response_body   MEDIUMTEXT   NOT NULL,
  created_at      DATETIME     NOT NULL,
  PRIMARY KEY (idem_key, caller),
  KEY ix_idem_created (created_at)
) ENGINE=InnoDB;

-- Transactional outbox: written in the same transaction as the business change.
CREATE TABLE outbox (
  event_id          CHAR(36)     NOT NULL,
  event_type        VARCHAR(64)  NOT NULL,
  aggregate_id      CHAR(36)     NOT NULL,
  aggregate_version INT          NOT NULL,
  correlation_id    CHAR(36)     NOT NULL,
  payload           JSON         NOT NULL,
  created_at        DATETIME     NOT NULL,
  PRIMARY KEY (event_id),
  KEY ix_outbox_created (created_at)
) ENGINE=InnoDB;

-- One delivery row per handler. After 5 failed attempts the row is flagged REVIEW (never deleted).
CREATE TABLE outbox_delivery (
  event_id        CHAR(36)    NOT NULL,
  handler_name    VARCHAR(32) NOT NULL,
  status          ENUM('PENDING','DELIVERED','REVIEW') NOT NULL DEFAULT 'PENDING',
  attempt_count   INT         NOT NULL DEFAULT 0,
  next_attempt_at DATETIME    NOT NULL,
  delivered_at    DATETIME    NULL,
  last_error      VARCHAR(255) NULL,
  PRIMARY KEY (event_id, handler_name),
  CONSTRAINT fk_delivery_event FOREIGN KEY (event_id) REFERENCES outbox (event_id),
  CONSTRAINT ck_delivery_attempts CHECK (attempt_count >= 0),
  KEY ix_delivery_due (status, next_attempt_at)
) ENGINE=InnoDB;

-- Inbox: consumers record each eventId with their local update, so retries are safe.
CREATE TABLE inbox (
  event_id    CHAR(36) NOT NULL,
  received_at DATETIME NOT NULL,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB;
