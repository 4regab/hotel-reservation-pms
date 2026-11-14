-- Booking: room type details and rates, guest records without accounts, reservation extras,
-- OTA partners and allotments, guest merge history, audit trail.

ALTER TABLE room_type ADD
  slug        NVARCHAR(40)  NOT NULL CONSTRAINT df_room_type_slug DEFAULT '',
  tagline     NVARCHAR(120) NOT NULL CONSTRAINT df_room_type_tagline DEFAULT '',
  description NVARCHAR(700) NOT NULL CONSTRAINT df_room_type_description DEFAULT '',
  max_guests  TINYINT       NOT NULL CONSTRAINT df_room_type_max_guests DEFAULT 2,
  bed_type    NVARCHAR(60)  NOT NULL CONSTRAINT df_room_type_bed_type DEFAULT '',
  size_sqm    SMALLINT      NOT NULL CONSTRAINT df_room_type_size_sqm DEFAULT 0,
  amenities   JSON          NULL,
  image       NVARCHAR(80)  NOT NULL CONSTRAINT df_room_type_image DEFAULT '',
  active      TINYINT       NOT NULL CONSTRAINT df_room_type_active DEFAULT 1;
GO

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

ALTER TABLE room_type ADD CONSTRAINT uq_room_type_slug UNIQUE (slug);

-- Guests created by staff (walk-ins, OTA imports) have no portal account.
-- SQL Server allows only one NULL in a UNIQUE constraint, so account uniqueness becomes a filtered unique index.
ALTER TABLE guest DROP CONSTRAINT uq_guest_account;
ALTER TABLE guest ALTER COLUMN account_id NCHAR(36) NULL;
ALTER TABLE guest ADD
  merged_into   NCHAR(36)    NULL,
  anonymized_at DATETIME2(0) NULL;
CREATE UNIQUE INDEX uq_guest_account ON guest (account_id) WHERE account_id IS NOT NULL;
CREATE INDEX ix_guest_email ON guest (email);
CREATE INDEX ix_guest_phone ON guest (phone);
CREATE INDEX ix_guest_name ON guest (full_name);

ALTER TABLE reservation ADD
  code                NVARCHAR(10)  NULL,
  source              NVARCHAR(7)   NOT NULL CONSTRAINT df_reservation_source DEFAULT 'DIRECT',
  adults              TINYINT       NOT NULL CONSTRAINT df_reservation_adults DEFAULT 1,
  special_requests    NVARCHAR(300) NULL,
  guarantee_requested TINYINT       NOT NULL CONSTRAINT df_reservation_guarantee_requested DEFAULT 0,
  paid_at             DATETIME2(0)  NULL,
  confirmed_at        DATETIME2(0)  NULL,
  cancelled_at        DATETIME2(0)  NULL,
  no_show_at          DATETIME2(0)  NULL,
  penalty_minor       BIGINT        NOT NULL CONSTRAINT df_reservation_penalty_minor DEFAULT 0;
GO

ALTER TABLE reservation ADD CONSTRAINT ck_reservation_source CHECK (source IN (N'DIRECT', N'WALK_IN', N'OTA'));
ALTER TABLE reservation DROP CONSTRAINT ck_reservation_guarantee;
ALTER TABLE reservation ADD CONSTRAINT ck_reservation_guarantee CHECK (guarantee IN (N'NONE', N'PAID', N'PAY_AT_HOTEL', N'OTA_PREPAID'));
-- Same reason as uq_guest_account: many reservations may have no code.
CREATE UNIQUE INDEX uq_res_code ON reservation (code) WHERE code IS NOT NULL;
CREATE INDEX ix_res_stay ON reservation (check_in, status);
CREATE INDEX ix_res_created ON reservation (created_at);

-- Partners that send bookings by CSV. The partner id is part of the unique key on ota_booking.
CREATE TABLE ota_partner (
  partner_id NVARCHAR(40) NOT NULL,
  name       NVARCHAR(80) NOT NULL,
  active     TINYINT      NOT NULL DEFAULT 1,
  CONSTRAINT pk_ota_partner PRIMARY KEY (partner_id)
);

INSERT INTO ota_partner (partner_id, name, active) VALUES
  ('baybook',   'BayBook Travel',   1),
  ('tripwise',  'TripWise Asia',    1),
  ('islandhop', 'IslandHop Stays',  1);

-- A manager-approved block of dated stock for one partner. Approval moves stock from "available" to
-- the ota_remaining counter; each imported booking then moves it from ota_remaining to confirmed.
CREATE TABLE ota_allotment (
  allotment_id NCHAR(36)     NOT NULL,
  partner_id   NVARCHAR(40)  NOT NULL,
  room_type_id NCHAR(36)     NOT NULL,
  start_date   DATE          NOT NULL,
  end_date     DATE          NOT NULL,
  quantity     INT           NOT NULL,
  status       NVARCHAR(8)   NOT NULL,
  requested_by NCHAR(36)     NOT NULL,
  decided_by   NCHAR(36)     NULL,
  decided_at   DATETIME2(0)  NULL,
  created_at   DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_ota_allotment PRIMARY KEY (allotment_id),
  CONSTRAINT fk_allot_partner FOREIGN KEY (partner_id) REFERENCES ota_partner (partner_id),
  CONSTRAINT fk_allot_type FOREIGN KEY (room_type_id) REFERENCES room_type (room_type_id),
  CONSTRAINT ck_ota_allotment_status CHECK (status IN (N'PENDING', N'APPROVED', N'REJECTED', N'RETURNED')),
  CONSTRAINT ck_allot_dates CHECK (end_date > start_date),
  CONSTRAINT ck_allot_qty CHECK (quantity BETWEEN 1 AND 45)
);
CREATE INDEX ix_allot_status ON ota_allotment (status, created_at);

CREATE TABLE ota_import (
  import_id     NCHAR(36)     NOT NULL,
  partner_id    NVARCHAR(40)  NOT NULL,
  filename      NVARCHAR(120) NOT NULL,
  rows_total    INT           NOT NULL,
  rows_ok       INT           NOT NULL,
  rows_rejected INT           NOT NULL,
  report        JSON          NOT NULL,
  imported_by   NCHAR(36)     NOT NULL,
  created_at    DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_ota_import PRIMARY KEY (import_id),
  CONSTRAINT fk_import_partner FOREIGN KEY (partner_id) REFERENCES ota_partner (partner_id)
);

ALTER TABLE ota_booking ADD import_id NCHAR(36) NULL;

CREATE TABLE guest_merge (
  merge_id           NCHAR(36)     NOT NULL,
  kept_guest_id      NCHAR(36)     NOT NULL,
  merged_guest_id    NCHAR(36)     NOT NULL,
  reservations_moved INT           NOT NULL,
  merged_by          NCHAR(36)     NOT NULL,
  note               NVARCHAR(200) NULL,
  created_at         DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_guest_merge PRIMARY KEY (merge_id),
  CONSTRAINT uq_merge_merged UNIQUE (merged_guest_id)
);

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
