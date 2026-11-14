-- Room Operations database (SQL Server). Owns rooms, stays, assignments, cleaning and maintenance.

CREATE TABLE room (
  room_id      NCHAR(36)    NOT NULL,
  room_type_id NCHAR(36)    NOT NULL,                 -- REF Booking room type
  room_number  NVARCHAR(8)  NOT NULL,
  readiness    NVARCHAR(14) NOT NULL,
  version      INT          NOT NULL DEFAULT 1,       -- optimistic version for PATCH /rooms/{id}/status
  CONSTRAINT pk_room PRIMARY KEY (room_id),
  CONSTRAINT uq_room_number UNIQUE (room_number),
  CONSTRAINT ck_room_readiness CHECK (readiness IN (N'READY', N'OCCUPIED', N'DIRTY', N'CLEANING', N'OUT_OF_SERVICE'))
);
CREATE INDEX ix_room_type_ready ON room (room_type_id, readiness);

CREATE TABLE stay (
  stay_id              NCHAR(36)    NOT NULL,
  reservation_id       NCHAR(36)    NOT NULL,         -- REF Booking reservation
  check_in             DATE         NOT NULL,
  check_out            DATE         NOT NULL,
  actual_check_in      DATETIME2(0) NOT NULL,
  actual_check_out     DATETIME2(0) NULL,
  identity_verified_by NCHAR(36)    NOT NULL,         -- staff account that checked the ID
  identity_doc_type    NVARCHAR(20) NOT NULL,         -- document type only; the number is never stored
  CONSTRAINT pk_stay PRIMARY KEY (stay_id),
  CONSTRAINT uq_stay_reservation UNIQUE (reservation_id),
  CONSTRAINT ck_stay_dates CHECK (check_out > check_in)
);

CREATE TABLE room_assignment (
  assignment_id NCHAR(36) NOT NULL,
  stay_id       NCHAR(36) NOT NULL,
  room_id       NCHAR(36) NOT NULL,
  start_date    DATE      NOT NULL,                   -- occupied range [start_date, end_date)
  end_date      DATE      NOT NULL,
  active        TINYINT   NOT NULL DEFAULT 1,
  CONSTRAINT pk_room_assignment PRIMARY KEY (assignment_id),
  CONSTRAINT fk_assign_stay FOREIGN KEY (stay_id) REFERENCES stay (stay_id),
  CONSTRAINT fk_assign_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  CONSTRAINT ck_assign_dates CHECK (end_date > start_date)
);
CREATE INDEX ix_assign_room_active ON room_assignment (room_id, active, start_date, end_date);

CREATE TABLE cleaning_task (
  task_id      NCHAR(36)    NOT NULL,
  room_id      NCHAR(36)    NOT NULL,
  assignee     NCHAR(36)    NULL,
  status       NVARCHAR(11) NOT NULL,
  created_at   DATETIME2(0) NOT NULL,
  completed_at DATETIME2(0) NULL,
  CONSTRAINT pk_cleaning_task PRIMARY KEY (task_id),
  CONSTRAINT fk_task_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  CONSTRAINT ck_cleaning_task_status CHECK (status IN (N'OPEN', N'IN_PROGRESS', N'DONE'))
);
CREATE INDEX ix_task_status ON cleaning_task (status, created_at);

CREATE TABLE maintenance_block (
  block_id     NCHAR(36)     NOT NULL,
  room_id      NCHAR(36)     NOT NULL,
  start_date   DATE          NOT NULL,
  end_date     DATE          NOT NULL,
  status       NVARCHAR(8)   NOT NULL,
  reason       NVARCHAR(200) NOT NULL,
  request_id   NCHAR(36)     NOT NULL,                -- reused on retries when asking Booking for capacity
  requested_by NCHAR(36)     NOT NULL,
  created_at   DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_maintenance_block PRIMARY KEY (block_id),
  CONSTRAINT uq_block_request UNIQUE (request_id),
  CONSTRAINT fk_block_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  CONSTRAINT ck_block_dates CHECK (end_date > start_date),
  CONSTRAINT ck_maintenance_block_status CHECK (status IN (N'PENDING', N'APPROVED', N'CONFLICT'))
);

-- Arrivals view, fed by ReservationConfirmed / ReservationCancelled. No guest contact data.
CREATE TABLE arrival (
  reservation_id NCHAR(36)    NOT NULL,
  room_type_id   NCHAR(36)    NOT NULL,
  check_in       DATE         NOT NULL,
  check_out      DATE         NOT NULL,
  quantity       TINYINT      NOT NULL,
  status         NVARCHAR(9)  NOT NULL,
  CONSTRAINT pk_arrival PRIMARY KEY (reservation_id),
  CONSTRAINT ck_arrival_status CHECK (status IN (N'EXPECTED', N'ARRIVED', N'CANCELLED'))
);
CREATE INDEX ix_arrival_date ON arrival (status, check_in);

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
  event_id        NCHAR(36)     NOT NULL,
  handler_name    NVARCHAR(32)  NOT NULL,
  status          NVARCHAR(9)   NOT NULL DEFAULT N'PENDING',
  attempt_count   INT           NOT NULL DEFAULT 0,
  next_attempt_at DATETIME2(0)  NOT NULL,
  delivered_at    DATETIME2(0)  NULL,
  last_error      NVARCHAR(255) NULL,
  CONSTRAINT pk_outbox_delivery PRIMARY KEY (event_id, handler_name),
  CONSTRAINT fk_delivery_event FOREIGN KEY (event_id) REFERENCES outbox (event_id),
  CONSTRAINT ck_delivery_attempts CHECK (attempt_count >= 0),
  CONSTRAINT ck_outbox_delivery_status CHECK (status IN (N'PENDING', N'DELIVERED', N'REVIEW'))
);
CREATE INDEX ix_delivery_due ON outbox_delivery (status, next_attempt_at);

-- Inbox: consumers record each eventId with their local update, so retries are safe.
CREATE TABLE inbox (
  event_id    NCHAR(36)    NOT NULL,
  received_at DATETIME2(0) NOT NULL,
  CONSTRAINT pk_inbox PRIMARY KEY (event_id)
);
