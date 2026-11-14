-- Booking: room type details and rates, guest records without accounts, reservation extras,
-- OTA partners and allotments, guest merge history, audit trail.

ALTER TABLE room_type
  ADD COLUMN slug        VARCHAR(40)  NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN tagline     VARCHAR(120) NOT NULL DEFAULT '' AFTER slug,
  ADD COLUMN description VARCHAR(700) NOT NULL DEFAULT '' AFTER tagline,
  ADD COLUMN max_guests  TINYINT      NOT NULL DEFAULT 2,
  ADD COLUMN bed_type    VARCHAR(60)  NOT NULL DEFAULT '',
  ADD COLUMN size_sqm    SMALLINT     NOT NULL DEFAULT 0,
  ADD COLUMN amenities   JSON         NULL,
  ADD COLUMN image       VARCHAR(80)  NOT NULL DEFAULT '',
  ADD COLUMN active      TINYINT      NOT NULL DEFAULT 1;

-- Rates in centavos. Standard PHP 3,800 / Deluxe PHP 5,600 / Suite PHP 9,800 per night (taxes included).
UPDATE room_type SET slug = 'standard', nightly_rate_minor = 380000, max_guests = 2, bed_type = 'Queen bed or twin beds', size_sqm = 24, image = 'room-standard.svg',
  tagline = 'Calm, compact and well equipped',
  description = 'A quiet city-facing room with a firm queen or twin beds, a work desk and a rain shower. Good for one or two guests who want a comfortable base close to the harbor district.',
  amenities = JSON_ARRAY('Free Wi-Fi', 'Air conditioning', '43-inch smart TV', 'Work desk', 'Rain shower', 'Coffee and tea set', 'In-room safe')
  WHERE name = 'Standard';
UPDATE room_type SET slug = 'deluxe', nightly_rate_minor = 560000, max_guests = 3, bed_type = 'King bed', size_sqm = 32, image = 'room-deluxe.svg',
  tagline = 'More space, better views',
  description = 'A larger corner room with a king bed, a sitting area and tall windows toward the harbor or the city skyline. Breakfast for two is included.',
  amenities = JSON_ARRAY('Free Wi-Fi', 'Air conditioning', '50-inch smart TV', 'Sitting area', 'Bathtub and shower', 'Espresso machine', 'Minibar', 'In-room safe', 'Breakfast for two')
  WHERE name = 'Deluxe';
UPDATE room_type SET slug = 'suite', nightly_rate_minor = 980000, max_guests = 4, bed_type = 'King bed and sofa bed', size_sqm = 58, image = 'room-suite.svg',
  tagline = 'Two rooms, a view of the whole bay',
  description = 'A separate living room and bedroom on the top floor with a panoramic harbor view, a dining table for four and a soaking tub. Includes breakfast for four and evening turndown.',
  amenities = JSON_ARRAY('Free Wi-Fi', 'Air conditioning', '55-inch smart TV', 'Separate living room', 'Soaking tub', 'Espresso machine', 'Minibar', 'In-room safe', 'Breakfast for four', 'Evening turndown')
  WHERE name = 'Suite';

ALTER TABLE room_type ADD UNIQUE KEY uq_room_type_slug (slug);

-- Guests created by staff (walk-ins, OTA imports) have no portal account.
ALTER TABLE guest
  MODIFY COLUMN account_id CHAR(36) NULL,
  ADD COLUMN merged_into   CHAR(36) NULL,
  ADD COLUMN anonymized_at DATETIME NULL,
  ADD KEY ix_guest_email (email),
  ADD KEY ix_guest_phone (phone),
  ADD KEY ix_guest_name (full_name);

ALTER TABLE reservation
  ADD COLUMN code                VARCHAR(10)  NULL AFTER reservation_id,
  ADD COLUMN source              ENUM('DIRECT','WALK_IN','OTA') NOT NULL DEFAULT 'DIRECT',
  ADD COLUMN adults              TINYINT      NOT NULL DEFAULT 1,
  ADD COLUMN special_requests    VARCHAR(300) NULL,
  ADD COLUMN guarantee_requested TINYINT      NOT NULL DEFAULT 0,
  ADD COLUMN paid_at             DATETIME     NULL,
  ADD COLUMN confirmed_at        DATETIME     NULL,
  ADD COLUMN cancelled_at        DATETIME     NULL,
  ADD COLUMN no_show_at          DATETIME     NULL,
  ADD COLUMN penalty_minor       BIGINT       NOT NULL DEFAULT 0,
  MODIFY COLUMN guarantee ENUM('NONE','PAID','PAY_AT_HOTEL','OTA_PREPAID') NOT NULL DEFAULT 'NONE',
  ADD UNIQUE KEY uq_res_code (code),
  ADD KEY ix_res_stay (check_in, status),
  ADD KEY ix_res_created (created_at);

-- Partners that send bookings by CSV. The partner id is part of the unique key on ota_booking.
CREATE TABLE ota_partner (
  partner_id VARCHAR(40) NOT NULL,
  name       VARCHAR(80) NOT NULL,
  active     TINYINT     NOT NULL DEFAULT 1,
  PRIMARY KEY (partner_id)
) ENGINE=InnoDB;

INSERT INTO ota_partner (partner_id, name, active) VALUES
  ('baybook',   'BayBook Travel',   1),
  ('tripwise',  'TripWise Asia',    1),
  ('islandhop', 'IslandHop Stays',  1);

-- A manager-approved block of dated stock for one partner. Approval moves stock from "available" to
-- the ota_remaining counter; each imported booking then moves it from ota_remaining to confirmed.
CREATE TABLE ota_allotment (
  allotment_id CHAR(36)    NOT NULL,
  partner_id   VARCHAR(40) NOT NULL,
  room_type_id CHAR(36)    NOT NULL,
  start_date   DATE        NOT NULL,
  end_date     DATE        NOT NULL,
  quantity     INT         NOT NULL,
  status       ENUM('PENDING','APPROVED','REJECTED','RETURNED') NOT NULL,
  requested_by CHAR(36)    NOT NULL,
  decided_by   CHAR(36)    NULL,
  decided_at   DATETIME    NULL,
  created_at   DATETIME    NOT NULL,
  PRIMARY KEY (allotment_id),
  CONSTRAINT fk_allot_partner FOREIGN KEY (partner_id) REFERENCES ota_partner (partner_id),
  CONSTRAINT fk_allot_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_allot_dates CHECK (end_date > start_date),
  CONSTRAINT ck_allot_qty CHECK (quantity BETWEEN 1 AND 45),
  KEY ix_allot_status (status, created_at)
) ENGINE=InnoDB;

CREATE TABLE ota_import (
  import_id     CHAR(36)    NOT NULL,
  partner_id    VARCHAR(40) NOT NULL,
  filename      VARCHAR(120) NOT NULL,
  rows_total    INT         NOT NULL,
  rows_ok       INT         NOT NULL,
  rows_rejected INT         NOT NULL,
  report        JSON        NOT NULL,
  imported_by   CHAR(36)    NOT NULL,
  created_at    DATETIME    NOT NULL,
  PRIMARY KEY (import_id),
  CONSTRAINT fk_import_partner FOREIGN KEY (partner_id) REFERENCES ota_partner (partner_id)
) ENGINE=InnoDB;

ALTER TABLE ota_booking ADD COLUMN import_id CHAR(36) NULL;

CREATE TABLE guest_merge (
  merge_id           CHAR(36)    NOT NULL,
  kept_guest_id      CHAR(36)    NOT NULL,
  merged_guest_id    CHAR(36)    NOT NULL,
  reservations_moved INT         NOT NULL,
  merged_by          CHAR(36)    NOT NULL,
  note               VARCHAR(200) NULL,
  created_at         DATETIME    NOT NULL,
  PRIMARY KEY (merge_id),
  UNIQUE KEY uq_merge_merged (merged_guest_id)
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
