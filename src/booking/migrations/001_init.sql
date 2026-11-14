-- Booking database (SQL Server 2025). Owns guests, room types, reservations, dated stock, OTA allotments.
-- All times are UTC. Stay dates are Asia/Manila calendar dates. Money is integer centavos.

CREATE TABLE room_type (
  room_type_id       NCHAR(36)    NOT NULL,
  name               NVARCHAR(40) NOT NULL,
  capacity           INT          NOT NULL,
  nightly_rate_minor INT          NOT NULL,
  currency           NCHAR(3)     NOT NULL DEFAULT 'PHP',
  CONSTRAINT pk_room_type PRIMARY KEY (room_type_id),
  CONSTRAINT uq_room_type_name UNIQUE (name),
  CONSTRAINT ck_room_type_capacity CHECK (capacity >= 0),
  CONSTRAINT ck_room_type_rate CHECK (nightly_rate_minor > 0)
);

CREATE TABLE guest (
  guest_id   NCHAR(36)     NOT NULL,
  account_id NCHAR(36)     NOT NULL,           -- REF Portal account
  full_name  NVARCHAR(120) NOT NULL,
  email      NVARCHAR(160) NOT NULL,
  phone      NVARCHAR(40)  NOT NULL,
  created_at DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_guest PRIMARY KEY (guest_id),
  CONSTRAINT uq_guest_account UNIQUE (account_id)
);

CREATE TABLE reservation (
  reservation_id    NCHAR(36)    NOT NULL,
  guest_id          NCHAR(36)    NOT NULL,
  room_type_id      NCHAR(36)    NOT NULL,
  check_in          DATE         NOT NULL,
  check_out         DATE         NOT NULL,
  quantity          TINYINT      NOT NULL,
  status            NVARCHAR(11) NOT NULL,
  guarantee         NVARCHAR(12) NOT NULL DEFAULT 'NONE',
  quote_minor       BIGINT       NOT NULL,     -- agreed price saved with the booking
  nightly_minor     INT          NOT NULL,
  currency          NCHAR(3)     NOT NULL,
  free_cancel_hours INT          NOT NULL,     -- agreed cancellation terms
  hold_expires_at   DATETIME2(0) NOT NULL,
  version           INT          NOT NULL,
  created_at        DATETIME2(0) NOT NULL,
  updated_at        DATETIME2(0) NOT NULL,
  CONSTRAINT pk_reservation PRIMARY KEY (reservation_id),
  CONSTRAINT fk_res_guest FOREIGN KEY (guest_id) REFERENCES guest (guest_id),
  CONSTRAINT fk_res_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_reservation_status CHECK (status IN (N'HELD', N'CONFIRMED', N'EXPIRED', N'CANCELLED', N'CHECKED_IN', N'CHECKED_OUT', N'NO_SHOW')),
  CONSTRAINT ck_reservation_guarantee CHECK (guarantee IN (N'NONE', N'PAID', N'PAY_AT_HOTEL')),
  CONSTRAINT ck_res_dates CHECK (check_out > check_in),
  CONSTRAINT ck_res_qty CHECK (quantity BETWEEN 1 AND 5),
  CONSTRAINT ck_res_money CHECK (quote_minor >= 0 AND nightly_minor > 0)
);
CREATE INDEX ix_res_status_expiry ON reservation (status, hold_expires_at);
CREATE INDEX ix_res_guest ON reservation (guest_id);

-- One row per room type per night. Stay nights are check-in .. check-out minus one day.
CREATE TABLE inventory_night (
  room_type_id  NCHAR(36) NOT NULL,
  stay_date     DATE      NOT NULL,
  capacity      INT       NOT NULL,
  blocked       INT       NOT NULL DEFAULT 0,
  held          INT       NOT NULL DEFAULT 0,
  confirmed     INT       NOT NULL DEFAULT 0,
  ota_remaining INT       NOT NULL DEFAULT 0,
  CONSTRAINT pk_inventory_night PRIMARY KEY (room_type_id, stay_date),
  CONSTRAINT fk_night_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_night_nonneg CHECK (capacity >= 0 AND blocked >= 0 AND held >= 0 AND confirmed >= 0 AND ota_remaining >= 0),
  CONSTRAINT ck_night_capacity CHECK (blocked + held + confirmed + ota_remaining <= capacity)
);

CREATE TABLE allocation (
  reservation_id NCHAR(36)    NOT NULL,
  stay_date      DATE         NOT NULL,
  room_type_id   NCHAR(36)    NOT NULL,
  quantity       TINYINT      NOT NULL,
  state          NVARCHAR(9)  NOT NULL,
  CONSTRAINT pk_allocation PRIMARY KEY (reservation_id, stay_date),
  CONSTRAINT fk_alloc_res FOREIGN KEY (reservation_id) REFERENCES reservation (reservation_id),
  CONSTRAINT fk_alloc_night FOREIGN KEY (room_type_id, stay_date) REFERENCES inventory_night (room_type_id, stay_date),
  CONSTRAINT ck_allocation_state CHECK (state IN (N'HELD', N'CONFIRMED', N'RELEASED')),
  CONSTRAINT ck_alloc_qty CHECK (quantity BETWEEN 1 AND 5)
);

-- OTA imports (staff-managed). External booking ids are unique per partner.
-- ponytail: schema only; the import screen is out of scope for this proof of concept.
CREATE TABLE ota_booking (
  partner_id          NVARCHAR(40) NOT NULL,
  external_booking_id NVARCHAR(80) NOT NULL,
  reservation_id      NCHAR(36)    NOT NULL,
  allotment_ref       NVARCHAR(80) NULL,
  CONSTRAINT pk_ota_booking PRIMARY KEY (partner_id, external_booking_id),
  CONSTRAINT fk_ota_res FOREIGN KEY (reservation_id) REFERENCES reservation (reservation_id)
);

-- Capacity blocks requested by Room Operations for maintenance. request_id makes retries safe.
CREATE TABLE maintenance_capacity (
  request_id   NCHAR(36)    NOT NULL,
  room_type_id NCHAR(36)    NOT NULL,
  start_date   DATE         NOT NULL,
  end_date     DATE         NOT NULL,
  created_at   DATETIME2(0) NOT NULL,
  CONSTRAINT pk_maintenance_capacity PRIMARY KEY (request_id),
  CONSTRAINT fk_maint_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_maint_dates CHECK (end_date > start_date)
);

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
  status          NVARCHAR(9)   NOT NULL DEFAULT 'PENDING',
  attempt_count   INT           NOT NULL DEFAULT 0,
  next_attempt_at DATETIME2(0)  NOT NULL,
  delivered_at    DATETIME2(0)  NULL,
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
