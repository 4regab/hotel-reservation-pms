-- Room types. Capacities match the 45 physical rooms: 20 + 15 + 10.
-- Rates are integer centavos: PHP 2,500.00 = 250000.
INSERT INTO room_type (room_type_id, name, capacity, nightly_rate_minor, currency) VALUES
  ('11111111-1111-4111-8111-111111111111', 'Standard', 20, 250000, 'PHP'),
  ('22222222-2222-4222-8222-222222222222', 'Deluxe',   15, 380000, 'PHP'),
  ('33333333-3333-4333-8333-333333333333', 'Suite',    10, 650000, 'PHP');
