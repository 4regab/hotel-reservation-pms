-- Room pages now use photographs (see docs/IMAGE-CREDITS.md) instead of drawings.
UPDATE room_type SET image = REPLACE(image, '.svg', '.webp') WHERE image LIKE 'room-%.svg';
