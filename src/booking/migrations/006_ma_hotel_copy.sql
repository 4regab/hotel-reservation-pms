-- The hotel is now MA Hotel. Room copy describes the city view instead of a harbor.
UPDATE room_type SET
  description = 'A quiet city-facing room with a queen bed or twin beds, a work desk and a rain shower. A comfortable base for one or two guests.'
  WHERE slug = 'standard';
UPDATE room_type SET
  description = 'A larger corner room with a king bed, a sitting area and tall windows over the city skyline. Breakfast for two is included.'
  WHERE slug = 'deluxe';
UPDATE room_type SET
  tagline = 'Two rooms and a skyline view',
  description = 'A separate living room and bedroom on the top floor with a wide skyline view, a dining table for four and a soaking tub. Includes breakfast for four and evening turndown.'
  WHERE slug = 'suite';
