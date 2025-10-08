-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 08, 2025 at 07:44 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `hours-app`
--

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `user_id`, `name`, `is_active`, `created_at`) VALUES
(1, 2, 'Albert hejn', 1, '2025-10-07 09:37:56'),
(4, 3, 'Jumbo', 1, '2025-10-07 12:21:05'),
(5, 2, 'Vomar', 1, '2025-10-07 18:26:16'),
(9, 3, 'Vomar', 1, '2025-10-08 17:37:06');

-- --------------------------------------------------------

--
-- Table structure for table `project_tasks`
--

CREATE TABLE `project_tasks` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_tasks`
--

INSERT INTO `project_tasks` (`id`, `project_id`, `name`, `is_active`, `created_at`) VALUES
(1, 1, 'Stelling', 1, '2025-10-07 09:37:56'),
(2, 1, 'Kassa\'s', 1, '2025-10-07 09:50:53'),
(4, 4, 'kantoor', 1, '2025-10-07 12:21:26');

-- --------------------------------------------------------

--
-- Table structure for table `time_entries`
--

CREATE TABLE `time_entries` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `task_id` int(11) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `project` varchar(200) NOT NULL,
  `task` varchar(200) NOT NULL,
  `hours` decimal(4,2) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ;

--
-- Dumping data for table `time_entries`
--

INSERT INTO `time_entries` (`id`, `user_id`, `project_id`, `task_id`, `entry_date`, `project`, `task`, `hours`, `note`, `created_at`, `updated_at`) VALUES
(2, 2, NULL, NULL, '2025-10-07', 'Albert heijn', 'stelling', 6.00, 'mooi stelling', '2025-10-07 08:03:13', NULL),
(3, 2, NULL, NULL, '2025-10-07', 'Jumbo', 'banaan', 3.50, NULL, '2025-10-07 08:03:53', NULL),
(4, 2, 1, 2, '2025-10-07', '', '', 5.00, NULL, '2025-10-07 09:51:04', NULL),
(5, 2, 1, 1, '2025-10-07', '', '', 3.00, NULL, '2025-10-07 09:51:16', NULL),
(6, 2, 1, 2, '2025-10-08', '', '', 1.00, NULL, '2025-10-07 09:51:22', NULL),
(7, 2, 1, 1, '2025-10-06', '', '', 4.00, 'goed gewerkt', '2025-10-07 09:51:58', '2025-10-07 19:18:49'),
(8, 3, 1, 2, '2025-10-07', '', '', 5.00, NULL, '2025-10-07 13:14:24', NULL),
(9, 3, 1, 1, '2025-10-07', '', '', 2.00, NULL, '2025-10-07 13:14:32', NULL),
(10, 3, 4, 4, '2025-10-07', '', '', 4.00, NULL, '2025-10-07 18:28:08', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('employee','admin') NOT NULL DEFAULT 'employee',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `role`, `created_at`) VALUES
(1, 'admin@example.com', '$2y$10$fp85zp2hrKFOhkeh.fF6GuXpuseRIHIvHqTPn5NVAIZuwtUOcrdia', 'admin', '2025-10-05 14:45:05'),
(2, 'henk@example.com', '$2y$10$bvDvwR38tkjJ978Jnt9nFuEnFzgY89VqpvujUmwsB1yk7hGxJ/nha', 'employee', '2025-10-07 08:01:44'),
(3, 'vera@example.com', '$2y$10$H3Gk7PkAu0S1mg2edQrNHudc1m3656r3UZnNuz3nwceCo5Q2S/4Z6', 'employee', '2025-10-07 09:58:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_project` (`user_id`,`name`),
  ADD KEY `idx_projects_user` (`user_id`);

--
-- Indexes for table `project_tasks`
--
ALTER TABLE `project_tasks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_task_per_project` (`project_id`,`name`);

--
-- Indexes for table `time_entries`
--
ALTER TABLE `time_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_time_user_date` (`user_id`,`entry_date`),
  ADD KEY `idx_time_user_proj_task` (`user_id`,`project_id`,`task_id`),
  ADD KEY `fk_time_project` (`project_id`),
  ADD KEY `fk_time_task` (`task_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `project_tasks`
--
ALTER TABLE `project_tasks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `time_entries`
--
ALTER TABLE `time_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_tasks`
--
ALTER TABLE `project_tasks`
  ADD CONSTRAINT `fk_task_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `time_entries`
--
ALTER TABLE `time_entries`
  ADD CONSTRAINT `fk_time_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  ADD CONSTRAINT `fk_time_task` FOREIGN KEY (`task_id`) REFERENCES `project_tasks` (`id`),
  ADD CONSTRAINT `fk_time_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
