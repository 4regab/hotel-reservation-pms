-- Booking database (MySQL 8, InnoDB). Owns guests, room types, reservations, dated stock, OTA allotments.
-- All times are UTC. Stay dates are Asia/Manila calendar dates. Money is integer centavos.

CREATE TABLE room_type (
  room_type_id       CHAR(36)    NOT NULL,
  name               VARCHAR(40) NOT NULL,
  capacity           INT         NOT NULL,
  nightly_rate_minor INT         NOT NULL,
  currency           CHAR(3)     NOT NULL DEFAULT 'PHP',
  PRIMARY KEY (room_type_id),
  UNIQUE KEY uq_room_type_name (name),
  CONSTRAINT ck_room_type_capacity CHECK (capacity >= 0),
  CONSTRAINT ck_room_type_rate CHECK (nightly_rate_minor > 0)
) ENGINE=InnoDB;

CREATE TABLE guest (
  guest_id   CHAR(36)     NOT NULL,
  account_id CHAR(36)     NOT NULL,           -- REF Portal account
  full_name  VARCHAR(120) NOT NULL,
  email      VARCHAR(160) NOT NULL,
  phone      VARCHAR(40)  NOT NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (guest_id),
  UNIQUE KEY uq_guest_account (account_id)
) ENGINE=InnoDB;

CREATE TABLE reservation (
  reservation_id    CHAR(36)   NOT NULL,
  guest_id          CHAR(36)   NOT NULL,
  room_type_id      CHAR(36)   NOT NULL,
  check_in          DATE       NOT NULL,
  check_out         DATE       NOT NULL,
  quantity          TINYINT    NOT NULL,
  status            ENUM('HELD','CONFIRMED','EXPIRED','CANCELLED','CHECKED_IN','CHECKED_OUT','NO_SHOW') NOT NULL,
  guarantee         ENUM('NONE','PAID','PAY_AT_HOTEL') NOT NULL DEFAULT 'NONE',
  quote_minor       BIGINT     NOT NULL,     -- agreed price saved with the booking
  nightly_minor     INT        NOT NULL,
  currency          CHAR(3)    NOT NULL,
  free_cancel_hours INT        NOT NULL,     -- agreed cancellation terms
  hold_expires_at   DATETIME   NOT NULL,
  version           INT        NOT NULL,
  created_at        DATETIME   NOT NULL,
  updated_at        DATETIME   NOT NULL,
  PRIMARY KEY (reservation_id),
  CONSTRAINT fk_res_guest FOREIGN KEY (guest_id) REFERENCES guest (guest_id),
  CONSTRAINT fk_res_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_res_dates CHECK (check_out > check_in),
  CONSTRAINT ck_res_qty CHECK (quantity BETWEEN 1 AND 5),
  CONSTRAINT ck_res_money CHECK (quote_minor >= 0 AND nightly_minor > 0),
  KEY ix_res_status_expiry (status, hold_expires_at),
  KEY ix_res_guest (guest_id)
) ENGINE=InnoDB;

-- One row per room type per night. Stay nights are check-in .. check-out minus one day.
CREATE TABLE inventory_night (
  room_type_id  CHAR(36) NOT NULL,
  stay_date     DATE     NOT NULL,
  capacity      INT      NOT NULL,
  blocked       INT      NOT NULL DEFAULT 0,
  held          INT      NOT NULL DEFAULT 0,
  confirmed     INT      NOT NULL DEFAULT 0,
  ota_remaining INT      NOT NULL DEFAULT 0,
  PRIMARY KEY (room_type_id, stay_date),
  CONSTRAINT fk_night_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_night_nonneg CHECK (capacity >= 0 AND blocked >= 0 AND held >= 0 AND confirmed >= 0 AND ota_remaining >= 0),
  CONSTRAINT ck_night_capacity CHECK (blocked + held + confirmed + ota_remaining <= capacity)
) ENGINE=InnoDB;

CREATE TABLE allocation (
  reservation_id CHAR(36) NOT NULL,
  stay_date      DATE     NOT NULL,
  room_type_id   CHAR(36) NOT NULL,
  quantity       TINYINT  NOT NULL,
  state          ENUM('HELD','CONFIRMED','RELEASED') NOT NULL,
  PRIMARY KEY (reservation_id, stay_date),
  CONSTRAINT fk_alloc_res FOREIGN KEY (reservation_id) REFERENCES reservation (reservation_id),
  CONSTRAINT fk_alloc_night FOREIGN KEY (room_type_id, stay_date) REFERENCES inventory_night (room_type_id, stay_date),
  CONSTRAINT ck_alloc_qty CHECK (quantity BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- OTA imports (staff-managed). External booking ids are unique per partner.
-- ponytail: schema only; the import screen is out of scope for this proof of concept.
CREATE TABLE ota_booking (
  partner_id          VARCHAR(40)  NOT NULL,
  external_booking_id VARCHAR(80)  NOT NULL,
  reservation_id      CHAR(36)     NOT NULL,
  allotment_ref       VARCHAR(80)  NULL,
  PRIMARY KEY (partner_id, external_booking_id),
  CONSTRAINT fk_ota_res FOREIGN KEY (reservation_id) REFERENCES reservation (reservation_id)
) ENGINE=InnoDB;

-- Capacity blocks requested by Room Operations for maintenance. request_id makes retries safe.
CREATE TABLE maintenance_capacity (
  request_id   CHAR(36) NOT NULL,
  room_type_id CHAR(36) NOT NULL,
  start_date   DATE     NOT NULL,
  end_date     DATE     NOT NULL,
  created_at   DATETIME NOT NULL,
  PRIMARY KEY (request_id),
  CONSTRAINT fk_maint_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_maint_dates CHECK (end_date > start_date)
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
