-- seed.sql : pilot routes 33 and 111, stages and baseline travel times
-- NOTE: stage names come from published route descriptions; baseline minutes are
-- DRAFT estimates. Replace them with your own field-observation averages.
USE matatu_stage_db;

DELETE FROM travel_threshold; DELETE FROM stage; DELETE FROM route;

INSERT INTO route (route_id, route_number, route_name, route_details) VALUES
(1, '33', 'Route 33 - CBD to Pipeline', 'Eastlands corridor via Jogoo Road and Outer Ring Road. Starts at the Accra Road terminus.'),
(2, '111', 'Route 111 - CBD to Ngong', 'Ngong Road corridor. Starts at Railways / Haile Selassie Avenue.');

INSERT INTO stage (stage_id, route_id, stage_name, stage_sequence) VALUES
(1, 1, 'CBD (Accra Road)', 1),
(2, 1, 'Makadara', 2),
(3, 1, 'Jogoo Road', 3),
(4, 1, 'Donholm Roundabout', 4),
(5, 1, 'Quarry', 5),
(6, 1, 'Tumaini', 6),
(7, 1, 'Tassia', 7),
(8, 1, 'Avenue Park', 8),
(9, 1, 'Fedha', 9),
(10, 1, 'Pipeline', 10),
(11, 2, 'CBD (Railways)', 1),
(12, 2, 'Kenyatta Hospital', 2),
(13, 2, 'Adams Arcade', 3),
(14, 2, 'Junction Mall', 4),
(15, 2, 'Dagoretti Corner', 5),
(16, 2, 'Lenana', 6),
(17, 2, 'Karen', 7),
(18, 2, 'Ngong Town', 8);

INSERT INTO travel_threshold (route_id, from_stage_id, to_stage_id, baseline_travel_time, threshold_limit) VALUES
(1, 1, 2, 8, 24),
(1, 2, 3, 5, 15),
(1, 3, 4, 7, 21),
(1, 4, 5, 6, 18),
(1, 5, 6, 4, 12),
(1, 6, 7, 5, 15),
(1, 7, 8, 4, 12),
(1, 8, 9, 4, 12),
(1, 9, 10, 5, 15),
(2, 11, 12, 8, 24),
(2, 12, 13, 7, 21),
(2, 13, 14, 5, 15),
(2, 14, 15, 5, 15),
(2, 15, 16, 6, 18),
(2, 16, 17, 10, 30),
(2, 17, 18, 15, 45);

-- Admin account: do NOT put a password here. Run create_admin.php once instead.