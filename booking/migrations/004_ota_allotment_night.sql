-- Per-night record of what an allotment reserved and how much of it OTA imports have used.
CREATE TABLE ota_allotment_night (
  allotment_id NCHAR(36) NOT NULL,
  stay_date    DATE      NOT NULL,
  reserved     INT       NOT NULL,
  used         INT       NOT NULL DEFAULT 0,
  CONSTRAINT pk_ota_allotment_night PRIMARY KEY (allotment_id, stay_date),
  CONSTRAINT fk_allot_night FOREIGN KEY (allotment_id) REFERENCES ota_allotment (allotment_id),
  CONSTRAINT ck_allot_night_use CHECK (used >= 0 AND used <= reserved)
);
