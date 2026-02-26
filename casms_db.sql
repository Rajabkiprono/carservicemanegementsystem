-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 26, 2026 at 06:37 AM
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
-- Database: `casms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `mechanic_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `status` enum('assigned','in_progress','completed','cancelled') DEFAULT 'assigned',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `book_service`
--

CREATE TABLE `book_service` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `service_type` varchar(100) NOT NULL,
  `appointment_date` date NOT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_id` int(11) DEFAULT NULL,
  `payment_status` enum('pending','verified','completed','failed') DEFAULT 'pending',
  `mechanic_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `book_service`
--
DELIMITER $$
CREATE TRIGGER `after_service_booking` AFTER INSERT ON `book_service` FOR EACH ROW BEGIN
    DECLARE vehicle_info VARCHAR(255);
    
    SELECT CONCAT(brand, ' ', model, ' (', license_plate, ')') INTO vehicle_info
    FROM vehicles WHERE id = NEW.vehicle_id;
    
    INSERT INTO notifications (user_id, type, title, message, data, created_at)
    VALUES (
        NEW.user_id,
        'info',
        'Service Booked',
        CONCAT('Your ', NEW.service_type, ' service for ', vehicle_info, ' has been booked for ', DATE_FORMAT(NEW.appointment_date, '%M %d, %Y'), '.'),
        JSON_OBJECT('booking_id', NEW.id, 'vehicle_id', NEW.vehicle_id, 'service_type', NEW.service_type, 'appointment_date', NEW.appointment_date),
        NOW()
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `emergency_services`
--

CREATE TABLE `emergency_services` (
  `id` int(11) NOT NULL,
  `request_id` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `location` text NOT NULL,
  `coordinates` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `vehicle_images` text DEFAULT NULL,
  `urgency_level` enum('high','medium','low') DEFAULT 'high',
  `status` enum('pending','dispatched','arrived','completed','cancelled') DEFAULT 'pending',
  `estimated_arrival` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `emergency_services`
--

INSERT INTO `emergency_services` (`id`, `request_id`, `user_id`, `vehicle_id`, `service_type`, `contact_number`, `location`, `coordinates`, `description`, `vehicle_images`, `urgency_level`, `status`, `estimated_arrival`, `created_at`, `updated_at`) VALUES
(1, 'EMG-699B4F46E9193', 9, 1, 'Brake Failure', '01224556', 'Eldoret', NULL, '', NULL, 'high', 'cancelled', '2026-02-22 22:17:34', '2026-02-22 18:47:34', '2026-02-22 19:11:29'),
(2, 'EMG-699B667BEAE71', 10, 2, 'Towing', '01224556', '-0.1498642984745451, 35.962194956258045', '-0.1498642984745451,35.962194956258045', '', '[\"uploads\\/emergency\\/699b667bea638_1771791995.png\"]', 'high', 'cancelled', '2026-02-22 23:56:35', '2026-02-22 20:26:35', '2026-02-22 21:39:11'),
(3, 'EMG-699BF188C64AB', 8, 4, 'Accident', '01224556', '-0.16937, 35.96644', '-0.16937,35.96644', '', NULL, 'high', 'cancelled', '2026-02-23 09:49:52', '2026-02-23 06:19:52', '2026-02-23 06:32:04'),
(4, 'EMG-699BF70AC0EBB', 8, 4, 'Accident', '01224556', '-0.16610902701769895, 35.96495290697587', '-0.16610902701769895,35.96495290697587', '', NULL, 'high', 'pending', '2026-02-23 10:13:22', '2026-02-23 06:43:22', NULL),
(5, 'EMG-699BFEA88C6F1', 14, 6, 'Towing', '01224556', '-0.16611795049470432, 35.9649527555705', '-0.16611795049470432,35.9649527555705', '', NULL, 'high', 'pending', '2026-02-23 10:45:52', '2026-02-23 07:15:52', NULL);

--
-- Triggers `emergency_services`
--
DELIMITER $$
CREATE TRIGGER `after_emergency_booking` AFTER INSERT ON `emergency_services` FOR EACH ROW BEGIN
    DECLARE vehicle_info VARCHAR(255);
    
    SELECT CONCAT(brand, ' ', model, ' (', license_plate, ')') INTO vehicle_info
    FROM vehicles WHERE id = NEW.vehicle_id;
    
    INSERT INTO notifications (user_id, type, title, message, data, created_at)
    VALUES (
        NEW.user_id,
        'emergency',
        '? Emergency Service Requested',
        CONCAT('Emergency ', NEW.service_type, ' requested for ', vehicle_info, ' at ', NEW.location, '. Help is on the way!'),
        JSON_OBJECT('emergency_id', NEW.id, 'request_id', NEW.request_id, 'vehicle_id', NEW.vehicle_id, 'service_type', NEW.service_type),
        NOW()
    );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `finance_users`
--

CREATE TABLE `finance_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','finance_officer') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `finance_users`
--

INSERT INTO `finance_users` (`id`, `username`, `password`, `full_name`, `role`, `created_at`) VALUES
(3, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', '2026-02-23 05:18:19'),
(4, 'finance', '$2y$10$K8z0Cv1k7vY9X8Z5tW4xUeFnLqWxYzAbCdEfGhIjKlMnOpQrStUvWx', 'Finance Officer', 'finance_officer', '2026-02-23 05:18:19');

-- --------------------------------------------------------

--
-- Table structure for table `financial_transactions`
--

CREATE TABLE `financial_transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `transaction_type` enum('service_payment','spare_part','deposit','refund') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `status` enum('completed','pending','failed') DEFAULT 'completed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `transaction_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `paid_date` date DEFAULT NULL,
  `status` enum('paid','unpaid','overdue') DEFAULT 'unpaid'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `success` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('emergency','info','success','warning') DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `title`, `message`, `data`, `is_read`, `created_at`) VALUES
(1, 9, 'emergency', 'Emergency Service Requested', 'Emergency service requested for toyota CIVIC (ABC 353F)', '{\"request_id\":\"EMG-699B4F46E9193\",\"vehicle_id\":\"1\",\"service_type\":\"Brake Failure\",\"location\":\"Eldoret\"}', 1, '2026-02-22 18:47:34'),
(2, 9, 'info', 'Emergency Request Cancelled', 'Your emergency request has been cancelled', NULL, 1, '2026-02-22 19:11:29'),
(3, 10, 'success', 'New Vehicle Added', 'You have successfully added toyota CIVIC (ABC 353F) to your garage.', '{\"vehicle_id\": 2, \"brand\": \"toyota\", \"model\": \"CIVIC\", \"license_plate\": \"ABC 353F\"}', 0, '2026-02-22 19:51:01'),
(4, 10, 'emergency', '🚨 Emergency Service Requested', 'Emergency Towing requested for toyota CIVIC (ABC 353F) at -0.1498642984745451, 35.962194956258045. Help is on the way!', '{\"emergency_id\": 2, \"request_id\": \"EMG-699B667BEAE71\", \"vehicle_id\": 2, \"service_type\": \"Towing\"}', 0, '2026-02-22 20:26:35'),
(5, 10, 'emergency', '???? Emergency Service Requested', 'Emergency Towing requested for toyota CIVIC (ABC 353F)', '{\"emergency_id\":\"2\",\"request_id\":\"EMG-699B667BEAE71\",\"vehicle_id\":\"2\",\"service_type\":\"Towing\",\"location\":\"-0.1498642984745451, 35.962194956258045\",\"coordinates\":\"-0.1498642984745451,35.962194956258045\",\"has_images\":true}', 0, '2026-02-22 20:26:35'),
(7, 10, 'success', 'Item Added to Cart', 'Water Pump (x1) added to your cart', '{\"part_id\":26,\"quantity\":1,\"cart_total\":1}', 0, '2026-02-22 21:42:23'),
(8, 10, 'success', 'Item Added to Cart', 'Alternator (x1) added to your cart', '{\"part_id\":17,\"quantity\":1,\"cart_total\":2}', 0, '2026-02-22 21:51:59'),
(9, 10, 'success', 'Item Added to Cart', 'Brake Fluid (1L) (x1) added to your cart', '{\"part_id\":8,\"quantity\":1,\"cart_total\":3}', 0, '2026-02-22 21:52:06'),
(10, 8, 'success', 'New Vehicle Added', 'You have successfully added toyota CIVIC (ABC 353F) to your garage.', '{\"vehicle_id\": 3, \"brand\": \"toyota\", \"model\": \"CIVIC\", \"license_plate\": \"ABC 353F\"}', 0, '2026-02-23 04:59:35'),
(11, 8, 'success', 'New Vehicle Added', 'You have successfully added Honda Civic (ABC 353D) to your garage.', '{\"vehicle_id\": 4, \"brand\": \"Honda\", \"model\": \"Civic\", \"license_plate\": \"ABC 353D\"}', 1, '2026-02-23 06:16:09'),
(12, 8, 'success', 'Item Added to Cart', 'Air Filter (x1) added to your cart', '{\"part_id\":3,\"quantity\":1,\"cart_total\":1}', 0, '2026-02-23 06:17:08'),
(13, 8, 'success', 'Item Added to Cart', 'Brake Caliper (x1) added to your cart', '{\"part_id\":9,\"quantity\":1,\"cart_total\":2}', 0, '2026-02-23 06:17:15'),
(14, 8, 'emergency', '🚨 Emergency Service Requested', 'Emergency Accident requested for Honda Civic (ABC 353D) at -0.16937, 35.96644. Help is on the way!', '{\"emergency_id\": 3, \"request_id\": \"EMG-699BF188C64AB\", \"vehicle_id\": 4, \"service_type\": \"Accident\"}', 0, '2026-02-23 06:19:52'),
(15, 8, 'emergency', '???? Emergency Service Requested', 'Emergency Accident requested for Honda Civic (ABC 353D)', '{\"emergency_id\":\"3\",\"request_id\":\"EMG-699BF188C64AB\",\"vehicle_id\":\"4\",\"service_type\":\"Accident\",\"location\":\"-0.16937, 35.96644\",\"coordinates\":\"-0.16937,35.96644\",\"has_images\":false}', 0, '2026-02-23 06:19:52'),
(16, 12, 'emergency', '???? New Emergency Request', 'Emergency request from Rajab Kiprono for Accident at -0.16937, 35.96644', '{\"emergency_id\":\"3\",\"user_id\":8,\"user_name\":\"Rajab Kiprono\",\"request_id\":\"EMG-699BF188C64AB\"}', 0, '2026-02-23 06:19:52'),
(17, 8, 'warning', 'Emergency Request Cancelled', 'Your emergency request #EMG-699BF188C64AB has been cancelled', '{\"emergency_id\":\"3\",\"request_id\":\"EMG-699BF188C64AB\"}', 0, '2026-02-23 06:32:04'),
(18, 8, 'emergency', '🚨 Emergency Service Requested', 'Emergency Accident requested for Honda Civic (ABC 353D) at -0.16610902701769895, 35.96495290697587. Help is on the way!', '{\"emergency_id\": 4, \"request_id\": \"EMG-699BF70AC0EBB\", \"vehicle_id\": 4, \"service_type\": \"Accident\"}', 1, '2026-02-23 06:43:22'),
(19, 8, 'emergency', '???? Emergency Service Requested', 'Emergency Accident requested for Honda Civic (ABC 353D)', '{\"emergency_id\":\"4\",\"request_id\":\"EMG-699BF70AC0EBB\",\"vehicle_id\":\"4\",\"service_type\":\"Accident\",\"location\":\"-0.16610902701769895, 35.96495290697587\",\"coordinates\":\"-0.16610902701769895,35.96495290697587\",\"has_images\":false}', 0, '2026-02-23 06:43:22'),
(20, 12, 'emergency', '???? New Emergency Request', 'Emergency request from Rajab Kiprono for Accident at -0.16610902701769895, 35.96495290697587', '{\"emergency_id\":\"4\",\"user_id\":8,\"user_name\":\"Rajab Kiprono\",\"request_id\":\"EMG-699BF70AC0EBB\"}', 0, '2026-02-23 06:43:22'),
(21, 16, 'success', 'New Vehicle Added', 'You have successfully added toyota premio (ABC 353D) to your garage.', '{\"vehicle_id\": 5, \"brand\": \"toyota\", \"model\": \"premio\", \"license_plate\": \"ABC 353D\"}', 0, '2026-02-23 06:46:52'),
(22, 14, 'success', 'New Vehicle Added', 'You have successfully added toyota CIVIC (ABC 353F) to your garage.', '{\"vehicle_id\": 6, \"brand\": \"toyota\", \"model\": \"CIVIC\", \"license_plate\": \"ABC 353F\"}', 0, '2026-02-23 06:57:09'),
(23, 14, 'success', 'Item Added to Cart', 'Air Filter (x1) added to your cart', '{\"part_id\":3,\"quantity\":1,\"cart_total\":1}', 0, '2026-02-23 07:00:08'),
(24, 14, 'success', 'Item Added to Cart', 'Alternator (x1) added to your cart', '{\"part_id\":17,\"quantity\":1,\"cart_total\":2}', 0, '2026-02-23 07:00:17'),
(25, 14, 'success', 'Item Added to Cart', 'Brake Fluid (1L) (x1) added to your cart', '{\"part_id\":8,\"quantity\":1,\"cart_total\":1}', 0, '2026-02-23 07:14:20'),
(26, 14, 'emergency', '🚨 Emergency Service Requested', 'Emergency Towing requested for toyota CIVIC (ABC 353F) at -0.16611795049470432, 35.9649527555705. Help is on the way!', '{\"emergency_id\": 5, \"request_id\": \"EMG-699BFEA88C6F1\", \"vehicle_id\": 6, \"service_type\": \"Towing\"}', 0, '2026-02-23 07:15:52'),
(27, 14, 'emergency', '???? Emergency Service Requested', 'Emergency Towing requested for toyota CIVIC (ABC 353F)', '{\"emergency_id\":\"5\",\"request_id\":\"EMG-699BFEA88C6F1\",\"vehicle_id\":\"6\",\"service_type\":\"Towing\",\"location\":\"-0.16611795049470432, 35.9649527555705\",\"coordinates\":\"-0.16611795049470432,35.9649527555705\",\"has_images\":false}', 0, '2026-02-23 07:15:52'),
(28, 12, 'emergency', '???? New Emergency Request', 'Emergency request from Rajab Kiprono for Towing at -0.16611795049470432, 35.9649527555705', '{\"emergency_id\":\"5\",\"user_id\":14,\"user_name\":\"Rajab Kiprono\",\"request_id\":\"EMG-699BFEA88C6F1\"}', 0, '2026-02-23 07:15:52');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`items`)),
  `subtotal` decimal(10,2) NOT NULL,
  `tax` decimal(10,2) NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `status` enum('pending','processing','completed','cancelled') DEFAULT 'pending',
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` enum('pending','paid','failed') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('mpesa','card','bank','cash') NOT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `status` enum('pending','verified','completed','failed','refunded') DEFAULT 'pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiry` datetime NOT NULL,
  `created_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `service_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `duration` int(11) DEFAULT 60,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `description`, `category`, `price`, `duration`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Oil Change', 'Engine oil change with filter replacement', 'Oil & Fluids', 3500.00, 45, 1, '2026-02-22 21:23:09', NULL),
(2, 'Synthetic Oil Change', 'Full synthetic oil change for premium engines', 'Oil & Fluids', 5500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(3, 'Transmission Fluid Change', 'Automatic transmission fluid flush and refill', 'Oil & Fluids', 6500.00, 90, 1, '2026-02-22 21:23:09', NULL),
(4, 'Coolant Flush', 'Complete cooling system flush and refill', 'Oil & Fluids', 4500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(5, 'Brake Fluid Flush', 'Brake system fluid replacement', 'Oil & Fluids', 3500.00, 45, 1, '2026-02-22 21:23:09', NULL),
(6, 'Power Steering Fluid', 'Power steering fluid change', 'Oil & Fluids', 2500.00, 30, 1, '2026-02-22 21:23:09', NULL),
(7, 'Differential Oil Change', 'Front/rear differential oil service', 'Oil & Fluids', 5500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(8, 'Brake Pad Replacement', 'Front or rear brake pad replacement', 'Brake Service', 6500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(9, 'Brake Rotor Replacement', 'Brake disc rotor replacement (pair)', 'Brake Service', 12000.00, 90, 1, '2026-02-22 21:23:09', NULL),
(10, 'Complete Brake Service', 'Full brake inspection and service', 'Brake Service', 8500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(11, 'Brake Caliper Repair', 'Brake caliper rebuild or replacement', 'Brake Service', 9500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(12, 'Parking Brake Adjustment', 'Handbrake cable adjustment', 'Brake Service', 2500.00, 30, 1, '2026-02-22 21:23:09', NULL),
(13, 'Engine Tune-Up', 'Complete engine tune-up including spark plugs', 'Engine Service', 8500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(14, 'Timing Belt Replacement', 'Timing belt and tensioner replacement', 'Engine Service', 15000.00, 240, 1, '2026-02-22 21:23:09', NULL),
(15, 'Water Pump Replacement', 'Coolant pump replacement', 'Engine Service', 8500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(16, 'Engine Diagnostic', 'Computer diagnostic scan', 'Engine Service', 3500.00, 45, 1, '2026-02-22 21:23:09', NULL),
(17, 'Check Engine Light', 'Diagnostic and repair', 'Engine Service', 4500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(18, 'Valve Adjustment', 'Engine valve clearance adjustment', 'Engine Service', 9500.00, 180, 1, '2026-02-22 21:23:09', NULL),
(19, 'Battery Replacement', 'Car battery testing and replacement', 'Electrical', 2500.00, 30, 1, '2026-02-22 21:23:09', NULL),
(20, 'Alternator Repair', 'Alternator diagnostic and repair', 'Electrical', 7500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(21, 'Starter Replacement', 'Starter motor replacement', 'Electrical', 8500.00, 90, 1, '2026-02-22 21:23:09', NULL),
(22, 'AC Recharge', 'Air conditioning system recharge', 'Electrical', 6500.00, 60, 1, '2026-02-22 21:23:09', NULL),
(23, 'Lighting Repair', 'Headlight/taillight repair', 'Electrical', 3500.00, 45, 1, '2026-02-22 21:23:09', NULL),
(24, 'Wheel Alignment', 'Computerized wheel alignment', 'Suspension', 3500.00, 45, 1, '2026-02-22 21:23:09', NULL),
(25, 'Tire Rotation', 'Tire rotation and balance', 'Suspension', 2500.00, 30, 1, '2026-02-22 21:23:09', NULL),
(26, 'Shock Absorber Replacement', 'Shock/strut replacement (pair)', 'Suspension', 12000.00, 120, 1, '2026-02-22 21:23:09', NULL),
(27, 'Wheel Bearing Replacement', 'Wheel bearing replacement', 'Suspension', 8500.00, 90, 1, '2026-02-22 21:23:09', NULL),
(28, 'Power Steering Repair', 'Power steering system repair', 'Suspension', 9500.00, 120, 1, '2026-02-22 21:23:09', NULL),
(29, 'Full Vehicle Inspection', 'Comprehensive vehicle check', 'General', 4500.00, 90, 1, '2026-02-22 21:23:09', NULL),
(30, 'Pre-Purchase Inspection', 'Used car inspection before buying', 'General', 6500.00, 120, 1, '2026-02-22 21:23:09', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `shopping_cart`
--

CREATE TABLE `shopping_cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spareparts`
--

CREATE TABLE `spareparts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spare_parts`
--

CREATE TABLE `spare_parts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `spare_parts`
--

INSERT INTO `spare_parts` (`id`, `name`, `description`, `category`, `price`, `stock`, `created_at`) VALUES
(1, 'Engine Oil Filter', 'High-quality oil filter for all vehicles', 'Engine', 850.00, 45, '2026-02-22 21:12:31'),
(2, 'Spark Plug Set (4 pcs)', 'Iridium spark plugs for better performance', 'Engine', 3200.00, 30, '2026-02-22 21:12:31'),
(3, 'Air Filter', 'Premium air filter for engine protection', 'Engine', 1250.00, 38, '2026-02-22 21:12:31'),
(4, 'Fuel Pump', 'Electric fuel pump assembly', 'Engine', 8500.00, 12, '2026-02-22 21:12:31'),
(5, 'Timing Belt', 'Reinforced timing belt with tensioner', 'Engine', 4500.00, 15, '2026-02-22 21:12:31'),
(6, 'Brake Pads (Front)', 'Ceramic brake pads with wear indicator', 'Brake', 2800.00, 25, '2026-02-22 21:12:31'),
(7, 'Brake Disc (Pair)', 'Ventilated brake discs', 'Brake', 6500.00, 18, '2026-02-22 21:12:31'),
(8, 'Brake Fluid (1L)', 'DOT 4 brake fluid', 'Brake', 650.00, 60, '2026-02-22 21:12:31'),
(9, 'Brake Caliper', 'Rebuilt brake caliper', 'Brake', 4200.00, 8, '2026-02-22 21:12:31'),
(10, 'Handbrake Cable', 'Stainless steel handbrake cable', 'Brake', 1800.00, 22, '2026-02-22 21:12:31'),
(11, 'Shock Absorber (Front)', 'Gas-filled shock absorber', 'Suspension', 5500.00, 14, '2026-02-22 21:12:31'),
(12, 'Coil Spring', 'Heavy-duty coil spring', 'Suspension', 3800.00, 16, '2026-02-22 21:12:31'),
(13, 'Control Arm', 'Lower control arm with ball joint', 'Suspension', 7200.00, 9, '2026-02-22 21:12:31'),
(14, 'Stabilizer Link', 'Front stabilizer link kit', 'Suspension', 1650.00, 28, '2026-02-22 21:12:31'),
(15, 'Strut Mount', 'Front strut mounting', 'Suspension', 2100.00, 20, '2026-02-22 21:12:31'),
(16, 'Car Battery (55Ah)', 'Maintenance-free car battery', 'Electrical', 12500.00, 22, '2026-02-22 21:12:31'),
(17, 'Alternator', 'Reconditioned alternator', 'Electrical', 18500.00, 7, '2026-02-22 21:12:31'),
(18, 'Starter Motor', 'High-torque starter motor', 'Electrical', 15800.00, 5, '2026-02-22 21:12:31'),
(19, 'Headlight Bulb (H7)', 'LED headlight bulb pair', 'Electrical', 2200.00, 35, '2026-02-22 21:12:31'),
(20, 'Tail Light Assembly', 'Complete tail light unit', 'Electrical', 4800.00, 12, '2026-02-22 21:12:31'),
(21, 'Clutch Kit', 'Complete clutch set with bearing', 'Transmission', 18500.00, 8, '2026-02-22 21:12:31'),
(22, 'Gearbox Oil (4L)', 'Synthetic transmission fluid', 'Transmission', 4200.00, 25, '2026-02-22 21:12:31'),
(23, 'CV Axle', 'Drive shaft assembly', 'Transmission', 9500.00, 10, '2026-02-22 21:12:31'),
(24, 'Clutch Cable', 'Adjustable clutch cable', 'Transmission', 1350.00, 18, '2026-02-22 21:12:31'),
(25, 'Radiator', 'Aluminum radiator', 'Cooling', 12500.00, 6, '2026-02-22 21:12:31'),
(26, 'Water Pump', 'Coolant pump assembly', 'Cooling', 5800.00, 13, '2026-02-22 21:12:31'),
(27, 'Thermostat', 'Wax-type thermostat', 'Cooling', 950.00, 42, '2026-02-22 21:12:31'),
(28, 'Coolant (5L)', 'Concentrated coolant', 'Cooling', 1850.00, 30, '2026-02-22 21:12:31'),
(29, 'Oxygen Sensor', 'Lambda sensor', 'Exhaust', 4200.00, 16, '2026-02-22 21:12:31'),
(30, 'Exhaust Muffler', 'Universal muffler', 'Exhaust', 6800.00, 9, '2026-02-22 21:12:31');

-- --------------------------------------------------------

--
-- Table structure for table `spare_part_orders`
--

CREATE TABLE `spare_part_orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `shipping_address` text NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `email` varchar(255) NOT NULL,
  `payment_method` enum('mpesa','card','cash_on_delivery') DEFAULT 'mpesa',
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `order_status` enum('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
  `transaction_id` varchar(100) DEFAULT NULL,
  `mpesa_code` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `spare_part_order_items`
--

CREATE TABLE `spare_part_order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `part_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price_per_unit` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `role` enum('admin','finance','mechanic','user') DEFAULT 'user',
  `email_verified` tinyint(1) DEFAULT 0,
  `google_id` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `phone`, `address`, `location`, `profile_picture`, `password`, `created_at`, `updated_at`, `last_login`, `is_active`, `role`, `email_verified`, `google_id`, `avatar`) VALUES
(1, 'admin', 'admin1@admin.com', NULL, NULL, NULL, NULL, '123456', '2026-02-22 14:51:43', '2026-02-22 14:51:43', NULL, 1, 'user', 0, NULL, NULL),
(2, 'admin', 'admin@admin.com', NULL, NULL, NULL, NULL, '123456', '2026-02-22 14:51:43', '2026-02-22 14:51:43', NULL, 1, 'user', 0, NULL, NULL),
(6, 'admin', 'admin1@gmail.com', NULL, NULL, NULL, NULL, '123456', '2026-02-22 14:58:09', '2026-02-22 14:58:09', NULL, 1, 'user', 0, NULL, NULL),
(8, 'Rajab Kiprono', 'ramathankipchumba@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$z94xpCu3XmOWcv42DYVbieGdCnVfoUbYroGjRNYB4228p38SKfdMm', '2026-02-22 15:03:58', '2026-02-22 15:03:58', NULL, 1, 'user', 0, NULL, NULL),
(9, 'Rajab Kiprono', 'ramathankipchumba2@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$Rlz23sXodOi0fBygwuMIIu4j0vYbMLIh2zOfkWgCqWdaH1YqDuo9S', '2026-02-22 15:04:34', '2026-02-22 15:04:34', NULL, 1, 'user', 0, NULL, NULL),
(10, 'Rajab Kiprono', 'ramathankipchumba62@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$cxu5uN1h/aAiTaoiHBzU6eZUZ/0UX5Rq/bEZw/3w1d7OL3sE0WOeq', '2026-02-22 19:31:46', '2026-02-22 19:31:46', NULL, 1, 'user', 0, NULL, NULL),
(11, 'Finance', 'finance@gmail.com', NULL, NULL, NULL, NULL, 'finance@gmail.com', '2026-02-22 22:20:44', '2026-02-22 22:20:44', NULL, 1, 'finance', 0, NULL, NULL),
(12, 'Admin', 'admin@gmail.com', NULL, NULL, NULL, NULL, 'admin@gmail.com', '2026-02-22 22:20:44', '2026-02-22 22:20:44', NULL, 1, 'admin', 0, NULL, NULL),
(13, 'Mechanic', 'mechanic@admin.com', NULL, NULL, NULL, NULL, 'mechanic@admin.com', '2026-02-22 22:20:44', '2026-02-22 22:20:44', NULL, 1, 'mechanic', 0, NULL, NULL),
(14, 'Rajab Kiprono', 'admin@company.com', NULL, NULL, NULL, NULL, '$2y$10$baAo6mOGVkLhPzOHRvtPUuohRq0D4xZLj4ZaWwg6jaj6YdZ4oRa9y', '2026-02-23 04:20:37', '2026-02-23 04:20:37', NULL, 1, 'user', 0, NULL, NULL),
(15, 'Rajab Kiprono', 'ramathankipchumbaa@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$gDLVx09FzLCK.5uBMpxomO6FrTg1Buv4IA1qvo762kFy1IwJXhtf.', '2026-02-23 04:52:37', '2026-02-23 04:52:37', NULL, 1, 'user', 0, NULL, NULL),
(16, 'wertyu', '123ff@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$W22ENw.HqUx9JEGvLjKjhuuAD5KoxR6PPLMfPmdlReg4r8E7kbJGK', '2026-02-23 06:46:06', '2026-02-23 06:46:06', NULL, 1, 'user', 0, NULL, NULL),
(17, 'Rajab Kiprono', 'ramathankipchumba6@gmail.com', NULL, NULL, NULL, NULL, '$2y$10$U5UD9SBlhV7gWDM5Zos3CeDuxVPjGI5UtEJsY85Gc2JwRzkYwBgQ.', '2026-02-26 05:25:47', '2026-02-26 05:25:47', NULL, 1, 'user', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `session_token` varchar(255) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` datetime DEFAULT current_timestamp(),
  `last_activity` datetime DEFAULT current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `brand` varchar(100) NOT NULL,
  `model` varchar(100) NOT NULL,
  `year` int(11) NOT NULL,
  `license_plate` varchar(20) NOT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `fuel_type` enum('Petrol','Diesel','Electric','Hybrid','CNG') DEFAULT 'Petrol',
  `transmission` enum('Manual','Automatic','CVT','DCT') DEFAULT 'Manual',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `user_id`, `brand`, `model`, `year`, `license_plate`, `vin`, `color`, `fuel_type`, `transmission`, `created_at`, `updated_at`) VALUES
(1, 9, 'toyota', 'CIVIC', 2002, 'ABC 353F', NULL, NULL, 'Petrol', 'Manual', '2026-02-22 18:14:27', NULL),
(2, 10, 'toyota', 'CIVIC', 2002, 'ABC 353F', NULL, NULL, 'Petrol', 'Manual', '2026-02-22 19:51:01', NULL),
(3, 8, 'toyota', 'CIVIC', 2002, 'ABC 353F', NULL, NULL, 'Petrol', 'Manual', '2026-02-23 04:59:35', NULL),
(4, 8, 'Honda', 'Civic', 2008, 'ABC 353D', NULL, NULL, 'Petrol', 'Manual', '2026-02-23 06:16:09', NULL),
(5, 16, 'toyota', 'premio', 2006, 'ABC 353D', NULL, NULL, 'Petrol', 'Manual', '2026-02-23 06:46:52', NULL),
(6, 14, 'toyota', 'CIVIC', 2007, 'ABC 353F', NULL, NULL, 'Petrol', 'Manual', '2026-02-23 06:57:09', NULL);

--
-- Triggers `vehicles`
--
DELIMITER $$
CREATE TRIGGER `after_vehicle_insert` AFTER INSERT ON `vehicles` FOR EACH ROW BEGIN
    INSERT INTO notifications (user_id, type, title, message, data, created_at)
    VALUES (
        NEW.user_id,
        'success',
        'New Vehicle Added',
        CONCAT('You have successfully added ', NEW.brand, ' ', NEW.model, ' (', NEW.license_plate, ') to your garage.'),
        JSON_OBJECT('vehicle_id', NEW.id, 'brand', NEW.brand, 'model', NEW.model, 'license_plate', NEW.license_plate),
        NOW()
    );
END
$$
DELIMITER ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_activity` (`user_id`,`created_at`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `mechanic_id` (`mechanic_id`),
  ADD KEY `status` (`status`),
  ADD KEY `assigned_by` (`assigned_by`);

--
-- Indexes for table `book_service`
--
ALTER TABLE `book_service`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_appointment_date` (`appointment_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `service_id` (`service_id`),
  ADD KEY `fk_payment` (`payment_id`);

--
-- Indexes for table `emergency_services`
--
ALTER TABLE `emergency_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `request_id` (`request_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_vehicle_id` (`vehicle_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_request_id` (`request_id`);

--
-- Indexes for table `finance_users`
--
ALTER TABLE `finance_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `transaction_id` (`transaction_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email_ip` (`email`,`ip_address`),
  ADD KEY `idx_time` (`attempt_time`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_order_number` (`order_number`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `transaction_id` (`transaction_id`),
  ADD KEY `booking_id` (`booking_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `status` (`status`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_price` (`price`);

--
-- Indexes for table `shopping_cart`
--
ALTER TABLE `shopping_cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cart_item` (`user_id`,`part_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `part_id` (`part_id`);

--
-- Indexes for table `spareparts`
--
ALTER TABLE `spareparts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spare_parts`
--
ALTER TABLE `spare_parts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spare_part_orders`
--
ALTER TABLE `spare_part_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_order_number` (`order_number`),
  ADD KEY `idx_payment_status` (`payment_status`),
  ADD KEY `idx_order_status` (`order_status`);

--
-- Indexes for table `spare_part_order_items`
--
ALTER TABLE `spare_part_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_part_id` (`part_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `session_token_2` (`session_token`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_license_plate_user` (`license_plate`,`user_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_license_plate` (`license_plate`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `book_service`
--
ALTER TABLE `book_service`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `emergency_services`
--
ALTER TABLE `emergency_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `finance_users`
--
ALTER TABLE `finance_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `shopping_cart`
--
ALTER TABLE `shopping_cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spareparts`
--
ALTER TABLE `spareparts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spare_parts`
--
ALTER TABLE `spare_parts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `spare_part_orders`
--
ALTER TABLE `spare_part_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `spare_part_order_items`
--
ALTER TABLE `spare_part_order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD CONSTRAINT `activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `book_service` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_2` FOREIGN KEY (`mechanic_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `book_service`
--
ALTER TABLE `book_service`
  ADD CONSTRAINT `book_service_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `book_service_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `book_service_ibfk_3` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `emergency_services`
--
ALTER TABLE `emergency_services`
  ADD CONSTRAINT `emergency_services_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `emergency_services_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `financial_transactions`
--
ALTER TABLE `financial_transactions`
  ADD CONSTRAINT `financial_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`transaction_id`) REFERENCES `financial_transactions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`email`) REFERENCES `users` (`email`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `book_service` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `shopping_cart`
--
ALTER TABLE `shopping_cart`
  ADD CONSTRAINT `shopping_cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `shopping_cart_ibfk_2` FOREIGN KEY (`part_id`) REFERENCES `spare_parts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `spare_part_orders`
--
ALTER TABLE `spare_part_orders`
  ADD CONSTRAINT `spare_part_orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `spare_part_order_items`
--
ALTER TABLE `spare_part_order_items`
  ADD CONSTRAINT `spare_part_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `spare_part_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `spare_part_order_items_ibfk_2` FOREIGN KEY (`part_id`) REFERENCES `spare_parts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
