-- phpMyAdmin SQL Dump
-- version 5.1.1deb5ubuntu1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: May 24, 2024 at 02:39 PM
-- Server version: 10.6.16-MariaDB-0ubuntu0.22.04.1
-- PHP Version: 8.1.2-1ubuntu2.17

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `party_kitty`
--

-- --------------------------------------------------------

--
-- Table structure for table `partykitty_data`
--

CREATE TABLE `partykitty_data` (
  `name` varchar(20) NOT NULL,
  `currencySet` varchar(10) DEFAULT NULL,
  `amount` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `partySize` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `splitRatio` tinyint(3) UNSIGNED NOT NULL DEFAULT 33,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '{}',
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_view` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `partykitty_ratelimit`
--

CREATE TABLE `partykitty_ratelimit` (
  `ip` varchar(15) NOT NULL,
  `action` enum('create','update') NOT NULL,
  `kitty` varchar(20) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `partykitty_data`
--
ALTER TABLE `partykitty_data`
  ADD PRIMARY KEY (`name`),
  ADD UNIQUE KEY `idx_name` (`name`);

--
-- Indexes for table `partykitty_ratelimit`
--
ALTER TABLE `partykitty_ratelimit`
  ADD UNIQUE KEY `ip_2` (`ip`,`action`,`kitty`),
  ADD KEY `ip` (`ip`),
  ADD KEY `fk_kittyname` (`kitty`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `partykitty_ratelimit`
--
ALTER TABLE `partykitty_ratelimit`
  ADD CONSTRAINT `fk_kittyname` FOREIGN KEY (`kitty`) REFERENCES `partykitty_data` (`name`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
