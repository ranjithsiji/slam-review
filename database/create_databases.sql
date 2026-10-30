-- Creates the application and test databases and the app user.
-- Run as a MariaDB admin:   sudo mariadb < database/create_databases.sql
-- Names and passwords must match .env (database.default.* and database.tests.*).
-- Change the password here and in .env before using this on a shared or production server.

CREATE DATABASE IF NOT EXISTS slam      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS slam_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'slam'@'localhost' IDENTIFIED BY 'slampass';
CREATE USER IF NOT EXISTS 'slam'@'127.0.0.1' IDENTIFIED BY 'slampass';

GRANT ALL PRIVILEGES ON slam.*      TO 'slam'@'localhost', 'slam'@'127.0.0.1';
GRANT ALL PRIVILEGES ON slam_test.* TO 'slam'@'localhost', 'slam'@'127.0.0.1';

FLUSH PRIVILEGES;
