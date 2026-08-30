-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Aug 30, 2026 at 06:44 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `barbershop_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `failed_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `email` varchar(191) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `full_name`, `created_at`, `failed_attempts`, `locked_until`, `email`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Barbershop Owner', '2026-08-02 19:30:46', 0, NULL, 'addaefrederick40@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `admin_2fa`
--

CREATE TABLE `admin_2fa` (
  `admin_id` int(11) NOT NULL,
  `secret_key` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) DEFAULT 0,
  `backup_codes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `last_used_counter` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_2fa`
--

INSERT INTO `admin_2fa` (`admin_id`, `secret_key`, `is_enabled`, `backup_codes`, `created_at`, `last_used_counter`) VALUES
(1, '5ec15ac5101f6da2f80d7af4b8c6ef78280029cd', 0, NULL, '2026-08-03 23:14:27', 0);

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity_log`
--

CREATE TABLE `admin_activity_log` (
  `id` int(11) NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `admin_name` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `booking_id` int(11) DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `barbers`
--

CREATE TABLE `barbers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `offers_home_service` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `barbers`
--

INSERT INTO `barbers` (`id`, `name`, `phone`, `specialization`, `image`, `is_active`, `created_at`, `offers_home_service`) VALUES
(4, 'Kwame Adu', '0595854746', 'Very good in woman styling', 'uploads/barbers/6a6fd1167c9b8_1785712918.jpeg', 1, '2026-08-02 23:21:58', 0);

-- --------------------------------------------------------

--
-- Table structure for table `barber_schedules`
--

CREATE TABLE `barber_schedules` (
  `id` int(11) NOT NULL,
  `barber_id` int(11) NOT NULL,
  `day_of_week` int(11) NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_working` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bookings`
--

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL,
  `booking_reference` varchar(50) DEFAULT NULL,
  `client_name` varchar(100) NOT NULL,
  `client_phone` varchar(15) NOT NULL,
  `client_email` varchar(100) DEFAULT NULL,
  `barber_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `booking_date` date NOT NULL,
  `booking_time` time NOT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('pending','confirmed','completed','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `package_id` int(11) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `payment_status` enum('pending','success','failed') DEFAULT 'pending',
  `payment_reference` varchar(100) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `paid_amount` decimal(10,2) DEFAULT NULL,
  `refund_status` enum('none','requested','processed','failed') DEFAULT 'none',
  `refund_reference` varchar(100) DEFAULT NULL,
  `refund_amount` decimal(10,2) DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `confirmation_token` varchar(64) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `service_type` text DEFAULT 'shop' CHECK (`service_type` in ('shop','home')),
  `client_address` text DEFAULT NULL,
  `home_service_fee` double DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `bookings`
--
DELIMITER $$
CREATE TRIGGER `before_booking_insert` BEFORE INSERT ON `bookings` FOR EACH ROW BEGIN
        IF NEW.status IN ('confirmed', 'completed') THEN
            IF EXISTS (
                SELECT 1 FROM bookings 
                WHERE barber_id = NEW.barber_id 
                AND booking_date = NEW.booking_date 
                AND booking_time = NEW.booking_time 
                AND status IN ('confirmed', 'completed')
                AND id != NEW.id
            ) THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This time slot is already booked and confirmed. Please choose a different time.';
            END IF;
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `before_booking_update` BEFORE UPDATE ON `bookings` FOR EACH ROW BEGIN
        IF NEW.status IN ('confirmed', 'completed') THEN
            IF EXISTS (
                SELECT 1 FROM bookings 
                WHERE barber_id = NEW.barber_id 
                AND booking_date = NEW.booking_date 
                AND booking_time = NEW.booking_time 
                AND status IN ('confirmed', 'completed')
                AND id != NEW.id
            ) THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'This time slot is already booked and confirmed. Please choose a different time.';
            END IF;
        END IF;
    END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `business_hours`
--

CREATE TABLE `business_hours` (
  `id` int(11) NOT NULL,
  `day_of_week` int(11) NOT NULL COMMENT '0=Sunday, 1=Monday, ..., 6=Saturday',
  `open_time` time DEFAULT NULL,
  `close_time` time DEFAULT NULL,
  `is_closed` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `business_hours`
--

INSERT INTO `business_hours` (`id`, `day_of_week`, `open_time`, `close_time`, `is_closed`, `created_at`, `updated_at`) VALUES
(1, 0, '09:00:00', '19:00:00', 1, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(2, 1, '09:00:00', '19:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(3, 2, '09:00:00', '19:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(4, 3, '09:00:00', '19:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(5, 4, '09:00:00', '19:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(6, 5, '09:00:00', '19:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52'),
(7, 6, '09:00:00', '17:00:00', 0, '2026-08-02 20:32:52', '2026-08-02 20:32:52');

-- --------------------------------------------------------

--
-- Table structure for table `client_notes`
--

CREATE TABLE `client_notes` (
  `id` int(11) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `notes` text DEFAULT NULL,
  `preferences` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gallery`
--

CREATE TABLE `gallery` (
  `id` int(11) NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `media_type` enum('image','video') DEFAULT 'image',
  `file_path` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gallery`
--

INSERT INTO `gallery` (`id`, `title`, `media_type`, `file_path`, `created_at`) VALUES
(1, 'Low Fade', 'image', 'uploads/gallery/6a6fb6cb3f9e6_1785706187.jpg', '2026-08-02 21:29:47'),
(2, 'small girl cut', 'image', 'uploads/gallery/6a6fcee66cc35_1785712358.jpg', '2026-08-02 23:12:38'),
(3, 'Fair lady cuts', 'image', 'uploads/gallery/6a6fcf110e5e6_1785712401.jpg', '2026-08-02 23:13:21');

-- --------------------------------------------------------

--
-- Table structure for table `loyalty`
--

CREATE TABLE `loyalty` (
  `id` int(11) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `points` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `packages` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `service_ids` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `packages`
--
DELIMITER $$
CREATE TRIGGER `update_packages_timestamp` BEFORE UPDATE ON `packages` FOR EACH ROW SET NEW.updated_at = CURRENT_TIMESTAMP
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `payment_reference` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'GHS',
  `status` enum('pending','success','failed') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `gateway_response` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `bucket` varchar(191) NOT NULL,
  `attempted_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `id` int(11) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `rating` int(11) NOT NULL,
  `comment` text DEFAULT NULL,
  `service_name` varchar(100) DEFAULT NULL,
  `is_approved` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `image` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `description`, `price`, `duration_minutes`, `image`, `is_active`, `created_at`) VALUES
(4, 'Hot Towel Shave', 'Luxurious straight razor shave with hot towels', 40.00, 45, 'uploads/services/6a6fbf90ce2fb_1785708432.jpg', 1, '2026-08-02 19:30:46'),
(5, 'Haircut & Beard Combo', 'Full haircut with beard trim and styling', 45.00, 60, 'uploads/services/6a6fbf81d78af_1785708417.jpg', 1, '2026-08-02 19:30:46');

-- --------------------------------------------------------

--
-- Table structure for table `site_settings`
--

CREATE TABLE `site_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `site_settings`
--

INSERT INTO `site_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'happy_clients', '5+', '2026-08-02 23:24:44'),
(2, 'years_exp', '8+', '2026-08-02 23:24:44'),
(3, 'rating', '4.9', '2026-08-02 21:54:40'),
(4, 'address', 'Agenda Main Street', '2026-08-02 23:24:45'),
(5, 'phone', '+233 234 567 890', '2026-08-02 23:24:46'),
(6, 'email', 'addaefrederick40@gmail.com', '2026-08-03 01:22:31'),
(7, 'hours_weekday', 'Mon - Fri: 9 AM - 7 PM', '2026-08-02 21:54:40'),
(8, 'hours_saturday', 'Saturday: 9 AM - 5 PM', '2026-08-02 21:54:40'),
(9, 'hours_sunday', 'Sunday: Closed', '2026-08-02 21:54:40'),
(10, 'footer_about', 'Premium grooming experience since 2019', '2026-08-02 21:54:40'),
(31, 'logo', 'uploads/logo/6a6fe15cf145e_1785717084.png', '2026-08-03 00:31:25'),
(53, 'map_embed_url', '', '2026-08-03 21:33:05'),
(54, 'whatsapp_number', '233595360050', '2026-08-03 21:33:05');

-- --------------------------------------------------------

--
-- Table structure for table `waitlist`
--

CREATE TABLE `waitlist` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `client_name` varchar(255) NOT NULL,
  `client_phone` varchar(20) NOT NULL,
  `client_email` varchar(255) DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time NOT NULL,
  `barber_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `status` enum('waiting','notified','booked','expired') DEFAULT 'waiting',
  `created_at` datetime DEFAULT current_timestamp(),
  `notified_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_2fa`
--
ALTER TABLE `admin_2fa`
  ADD PRIMARY KEY (`admin_id`);

--
-- Indexes for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_type` (`activity_type`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `barbers`
--
ALTER TABLE `barbers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_schedule` (`barber_id`,`day_of_week`);

--
-- Indexes for table `bookings`
--
ALTER TABLE `bookings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `confirmation_token` (`confirmation_token`),
  ADD UNIQUE KEY `idempotency_key` (`idempotency_key`),
  ADD KEY `barber_id` (`barber_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `business_hours`
--
ALTER TABLE `business_hours`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `day_of_week` (`day_of_week`);

--
-- Indexes for table `client_notes`
--
ALTER TABLE `client_notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`);

--
-- Indexes for table `gallery`
--
ALTER TABLE `gallery`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `loyalty`
--
ALTER TABLE `loyalty`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `idx_phone` (`phone`);

--
-- Indexes for table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rate_limits_bucket` (`bucket`,`attempted_at`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_settings`
--
ALTER TABLE `site_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `waitlist`
--
ALTER TABLE `waitlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_activity_log`
--
ALTER TABLE `admin_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `barbers`
--
ALTER TABLE `barbers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `bookings`
--
ALTER TABLE `bookings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `business_hours`
--
ALTER TABLE `business_hours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `client_notes`
--
ALTER TABLE `client_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gallery`
--
ALTER TABLE `gallery`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `loyalty`
--
ALTER TABLE `loyalty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `site_settings`
--
ALTER TABLE `site_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;

--
-- AUTO_INCREMENT for table `waitlist`
--
ALTER TABLE `waitlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_2fa`
--
ALTER TABLE `admin_2fa`
  ADD CONSTRAINT `admin_2fa_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `barber_schedules`
--
ALTER TABLE `barber_schedules`
  ADD CONSTRAINT `barber_schedules_ibfk_1` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bookings`
--
ALTER TABLE `bookings`
  ADD CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`barber_id`) REFERENCES `barbers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `waitlist`
--
ALTER TABLE `waitlist`
  ADD CONSTRAINT `waitlist_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
