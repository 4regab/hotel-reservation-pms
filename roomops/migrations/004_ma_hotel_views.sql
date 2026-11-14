-- The hotel is now MA Hotel. Room views are named after the skyline instead of a harbor.
UPDATE room SET view_type = 'Skyline' WHERE view_type = 'Harbor';
UPDATE room SET view_type = 'Skyline panorama' WHERE view_type = 'Harbor panorama';
