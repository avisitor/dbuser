-- Minimal users table for Common\User\DBUser
CREATE TABLE IF NOT EXISTS `users` (
  `id` varchar(32) NOT NULL,
  `username` varchar(191) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `name` varchar(191) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `rate` int DEFAULT 0,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
