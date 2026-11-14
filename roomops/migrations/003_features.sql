-- Room Operations: room details, early-arrival overrides, inspection, maintenance decisions, no-shows, audit trail.

ALTER TABLE room ADD
  floor     TINYINT       NOT NULL CONSTRAINT df_room_floor DEFAULT 1,
  view_type NVARCHAR(30)  NOT NULL CONSTRAINT df_room_view_type DEFAULT '',
  bed_type  NVARCHAR(40)  NOT NULL CONSTRAINT df_room_bed_type DEFAULT '',
  notes     NVARCHAR(200) NULL;
GO

-- 101-120 Standard on floors 1-2 (101-110 floor 1, 111-120 floor 2), 201-215 Deluxe on floors 3-4, 301-310 Suite on floors 5-6.
UPDATE room SET
  floor = CASE
    WHEN CAST(room_number AS INT) BETWEEN 101 AND 110 THEN 1
    WHEN CAST(room_number AS INT) BETWEEN 111 AND 120 THEN 2
    WHEN CAST(room_number AS INT) BETWEEN 201 AND 207 THEN 3
    WHEN CAST(room_number AS INT) BETWEEN 208 AND 215 THEN 4
    WHEN CAST(room_number AS INT) BETWEEN 301 AND 305 THEN 5
    ELSE 6 END,
  view_type = CASE
    WHEN CAST(room_number AS INT) BETWEEN 101 AND 120 THEN IIF(CAST(room_number AS INT) % 2 = 0, 'Courtyard', 'City')
    WHEN CAST(room_number AS INT) BETWEEN 201 AND 215 THEN IIF(CAST(room_number AS INT) % 3 = 0, 'City', 'Harbor')
    ELSE 'Harbor panorama' END,
  bed_type = CASE
    WHEN CAST(room_number AS INT) BETWEEN 101 AND 120 THEN IIF(CAST(room_number AS INT) % 4 < 2, 'Queen', 'Twin')
    WHEN CAST(room_number AS INT) BETWEEN 201 AND 215 THEN 'King'
    ELSE 'King and sofa bed' END;

ALTER TABLE stay ADD
  queue_started_at DATETIME2(0)  NULL,
  override_by      NCHAR(36)     NULL,
  override_reason  NVARCHAR(200) NULL;

ALTER TABLE arrival DROP CONSTRAINT ck_arrival_status;
ALTER TABLE arrival ADD CONSTRAINT ck_arrival_status CHECK (status IN (N'EXPECTED', N'ARRIVED', N'CANCELLED', N'NO_SHOW'));

-- Cleaning: OPEN -> IN_PROGRESS -> CLEANED (cleaner is done) -> DONE (inspected, room READY).
ALTER TABLE cleaning_task DROP CONSTRAINT ck_cleaning_task_status;
ALTER TABLE cleaning_task ADD CONSTRAINT ck_cleaning_task_status CHECK (status IN (N'OPEN', N'IN_PROGRESS', N'CLEANED', N'DONE'));
ALTER TABLE cleaning_task ADD
  cleaned_at   DATETIME2(0) NULL,
  inspected_by NCHAR(36)    NULL;

ALTER TABLE maintenance_block DROP CONSTRAINT ck_maintenance_block_status;
ALTER TABLE maintenance_block ADD CONSTRAINT ck_maintenance_block_status CHECK (status IN (N'PENDING', N'APPROVED', N'CONFLICT', N'REJECTED'));
ALTER TABLE maintenance_block ADD
  decided_by    NCHAR(36)     NULL,
  decided_at    DATETIME2(0)  NULL,
  decision_note NVARCHAR(200) NULL;

-- Early arrival (before the booked arrival date) needs a manager decision.
CREATE TABLE arrival_override (
  reservation_id NCHAR(36)     NOT NULL,
  requested_by   NCHAR(36)     NOT NULL,
  reason         NVARCHAR(200) NOT NULL,
  status         NVARCHAR(8)   NOT NULL,
  decided_by     NCHAR(36)     NULL,
  decided_at     DATETIME2(0)  NULL,
  created_at     DATETIME2(0)  NOT NULL,
  CONSTRAINT pk_arrival_override PRIMARY KEY (reservation_id),
  CONSTRAINT ck_arrival_override_status CHECK (status IN (N'PENDING', N'APPROVED', N'REJECTED'))
);
CREATE INDEX ix_override_status ON arrival_override (status, created_at);

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
