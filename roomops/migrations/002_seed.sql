-- The 45 physical rooms, all ready. 101-120 Standard, 201-215 Deluxe, 301-310 Suite.
-- Room type ids match Booking's seed.
INSERT INTO room (room_id, room_type_id, room_number, readiness, version)
WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 20)
SELECT UUID(), '11111111-1111-4111-8111-111111111111', CAST(100 + i AS CHAR), 'READY', 1 FROM n;

INSERT INTO room (room_id, room_type_id, room_number, readiness, version)
WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 15)
SELECT UUID(), '22222222-2222-4222-8222-222222222222', CAST(200 + i AS CHAR), 'READY', 1 FROM n;

INSERT INTO room (room_id, room_type_id, room_number, readiness, version)
WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10)
SELECT UUID(), '33333333-3333-4333-8333-333333333333', CAST(300 + i AS CHAR), 'READY', 1 FROM n;
