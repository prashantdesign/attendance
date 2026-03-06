-- Location attendance configuration
CREATE TABLE IF NOT EXISTS `location_attendance_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `global_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `office_latitude` decimal(10,7) DEFAULT NULL,
  `office_longitude` decimal(10,7) DEFAULT NULL,
  `office_radius_meters` int(11) NOT NULL DEFAULT 150,
  `half_day_first_half_cutoff_ist` time NOT NULL DEFAULT '14:00:00',
  `half_day_second_half_cutoff_ist` time NOT NULL DEFAULT '18:30:00',
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `location_attendance_settings` (`id`, `global_enabled`, `office_radius_meters`, `half_day_first_half_cutoff_ist`, `half_day_second_half_cutoff_ist`)
SELECT 1, 0, 150, '14:00:00', '18:30:00'
WHERE NOT EXISTS (SELECT 1 FROM `location_attendance_settings` WHERE `id` = 1);

CREATE TABLE IF NOT EXISTS `user_location_prefs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `location_attendance_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `background_tracking_required` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_location_pref` (`user_id`),
  CONSTRAINT `fk_user_location_prefs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `location_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` varchar(255) NOT NULL,
  `event_type` enum('enter_geofence','exit_geofence','heartbeat','manual_override') NOT NULL DEFAULT 'heartbeat',
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `distance_from_office_m` decimal(10,2) DEFAULT NULL,
  `device_time` datetime DEFAULT NULL,
  `server_time` datetime NOT NULL DEFAULT current_timestamp(),
  `action_taken` enum('auto_clock_in','auto_break_start','auto_break_end','auto_clock_out','none') NOT NULL DEFAULT 'none',
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  PRIMARY KEY (`id`),
  KEY `idx_location_events_user_time` (`user_id`,`server_time`),
  CONSTRAINT `fk_location_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
