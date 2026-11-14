-- Room Operations: room details, early-arrival overrides, inspection, maintenance decisions, no-shows, audit trail.

ALTER TABLE room
  ADD COLUMN floor     TINYINT     NOT NULL DEFAULT 1,
  ADD COLUMN view_type VARCHAR(30) NOT NULL DEFAULT '',
  ADD COLUMN bed_type  VARCHAR(40) NOT NULL DEFAULT '',
  ADD COLUMN notes     VARCHAR(200) NULL;

-- 101-120 Standard on floors 1-2 (101-110 floor 1, 111-120 floor 2), 201-215 Deluxe on floors 3-4, 301-310 Suite on floors 5-6.
UPDATE room SET
  floor = CASE
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 101 AND 110 THEN 1
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 111 AND 120 THEN 2
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 201 AND 207 THEN 3
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 208 AND 215 THEN 4
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 301 AND 305 THEN 5
    ELSE 6 END,
  view_type = CASE
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 101 AND 120 THEN IF(MOD(CAST(room_number AS UNSIGNED), 2) = 0, 'Courtyard', 'City')
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 201 AND 215 THEN IF(MOD(CAST(room_number AS UNSIGNED), 3) = 0, 'City', 'Harbor')
    ELSE 'Harbor panorama' END,
  bed_type = CASE
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 101 AND 120 THEN IF(MOD(CAST(room_number AS UNSIGNED), 4) < 2, 'Queen', 'Twin')
    WHEN CAST(room_number AS UNSIGNED) BETWEEN 201 AND 215 THEN 'King'
    ELSE 'King and sofa bed' END;

ALTER TABLE stay
  ADD COLUMN queue_started_at DATETIME     NULL,
  ADD COLUMN override_by      CHAR(36)     NULL,
  ADD COLUMN override_reason  VARCHAR(200) NULL;

ALTER TABLE arrival MODIFY COLUMN status ENUM('EXPECTED','ARRIVED','CANCELLED','NO_SHOW') NOT NULL;

-- Cleaning: OPEN -> IN_PROGRESS -> CLEANED (cleaner is done) -> DONE (inspected, room READY).
ALTER TABLE cleaning_task
  MODIFY COLUMN status ENUM('OPEN','IN_PROGRESS','CLEANED','DONE') NOT NULL,
  ADD COLUMN cleaned_at   DATETIME NULL,
  ADD COLUMN inspected_by CHAR(36) NULL;

ALTER TABLE maintenance_block
  MODIFY COLUMN status ENUM('PENDING','APPROVED','CONFLICT','REJECTED') NOT NULL,
  ADD COLUMN decided_by    CHAR(36)     NULL,
  ADD COLUMN decided_at    DATETIME     NULL,
  ADD COLUMN decision_note VARCHAR(200) NULL;

-- Early arrival (before the booked arrival date) needs a manager decision.
CREATE TABLE arrival_override (
  reservation_id CHAR(36)     NOT NULL,
  requested_by   CHAR(36)     NOT NULL,
  reason         VARCHAR(200) NOT NULL,
  status         ENUM('PENDING','APPROVED','REJECTED') NOT NULL,
  decided_by     CHAR(36)     NULL,
  decided_at     DATETIME     NULL,
  created_at     DATETIME     NOT NULL,
  PRIMARY KEY (reservation_id),
  KEY ix_override_status (status, created_at)
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
