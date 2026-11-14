-- Room Operations database (MySQL 8, InnoDB). Owns rooms, stays, assignments, cleaning and maintenance.

CREATE TABLE room (
  room_id      CHAR(36)    NOT NULL,
  room_type_id CHAR(36)    NOT NULL,                 -- REF Booking room type
  room_number  VARCHAR(8)  NOT NULL,
  readiness    ENUM('READY','OCCUPIED','DIRTY','CLEANING','OUT_OF_SERVICE') NOT NULL,
  version      INT         NOT NULL DEFAULT 1,       -- optimistic version for PATCH /rooms/{id}/status
  PRIMARY KEY (room_id),
  UNIQUE KEY uq_room_number (room_number),
  KEY ix_room_type_ready (room_type_id, readiness)
) ENGINE=InnoDB;

CREATE TABLE stay (
  stay_id              CHAR(36)    NOT NULL,
  reservation_id       CHAR(36)    NOT NULL,         -- REF Booking reservation
  check_in             DATE        NOT NULL,
  check_out            DATE        NOT NULL,
  actual_check_in      DATETIME    NOT NULL,
  actual_check_out     DATETIME    NULL,
  identity_verified_by CHAR(36)    NOT NULL,         -- staff account that checked the ID
  identity_doc_type    VARCHAR(20) NOT NULL,         -- document type only; the number is never stored
  PRIMARY KEY (stay_id),
  UNIQUE KEY uq_stay_reservation (reservation_id),
  CONSTRAINT ck_stay_dates CHECK (check_out > check_in)
) ENGINE=InnoDB;

CREATE TABLE room_assignment (
  assignment_id CHAR(36) NOT NULL,
  stay_id       CHAR(36) NOT NULL,
  room_id       CHAR(36) NOT NULL,
  start_date    DATE     NOT NULL,                   -- occupied range [start_date, end_date)
  end_date      DATE     NOT NULL,
  active        TINYINT  NOT NULL DEFAULT 1,
  PRIMARY KEY (assignment_id),
  CONSTRAINT fk_assign_stay FOREIGN KEY (stay_id) REFERENCES stay (stay_id),
  CONSTRAINT fk_assign_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  CONSTRAINT ck_assign_dates CHECK (end_date > start_date),
  KEY ix_assign_room_active (room_id, active, start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE cleaning_task (
  task_id      CHAR(36)    NOT NULL,
  room_id      CHAR(36)    NOT NULL,
  assignee     CHAR(36)    NULL,
  status       ENUM('OPEN','IN_PROGRESS','DONE') NOT NULL,
  created_at   DATETIME    NOT NULL,
  completed_at DATETIME    NULL,
  PRIMARY KEY (task_id),
  CONSTRAINT fk_task_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  KEY ix_task_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE maintenance_block (
  block_id     CHAR(36)     NOT NULL,
  room_id      CHAR(36)     NOT NULL,
  start_date   DATE         NOT NULL,
  end_date     DATE         NOT NULL,
  status       ENUM('PENDING','APPROVED','CONFLICT') NOT NULL,
  reason       VARCHAR(200) NOT NULL,
  request_id   CHAR(36)     NOT NULL,                -- reused on retries when asking Booking for capacity
  requested_by CHAR(36)     NOT NULL,
  created_at   DATETIME     NOT NULL,
  PRIMARY KEY (block_id),
  UNIQUE KEY uq_block_request (request_id),
  CONSTRAINT fk_block_room FOREIGN KEY (room_id) REFERENCES room (room_id),
  CONSTRAINT ck_block_dates CHECK (end_date > start_date)
) ENGINE=InnoDB;

-- Arrivals view, fed by ReservationConfirmed / ReservationCancelled. No guest contact data.
CREATE TABLE arrival (
  reservation_id CHAR(36) NOT NULL,
  room_type_id   CHAR(36) NOT NULL,
  check_in       DATE     NOT NULL,
  check_out      DATE     NOT NULL,
  quantity       TINYINT  NOT NULL,
  status         ENUM('EXPECTED','ARRIVED','CANCELLED') NOT NULL,
  PRIMARY KEY (reservation_id),
  KEY ix_arrival_date (status, check_in)
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
