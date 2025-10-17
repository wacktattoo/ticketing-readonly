-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Počítač: db.dw317.endora.cz
-- Vytvořeno: Pát 17. říj 2025, 15:37
-- Verze serveru: 10.5.29-MariaDB-ubu2004-log
-- Verze PHP: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Databáze: `ticketing`
--

-- --------------------------------------------------------

--
-- Struktura tabulky `admin_user`
--

CREATE TABLE `admin_user` (
  `id` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `events`
--

CREATE TABLE `events` (
  `id` char(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(190) NOT NULL,
  `description` mediumtext DEFAULT NULL,
  `venue_name` varchar(255) DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `timezone` varchar(64) NOT NULL DEFAULT 'Europe/Prague',
  `cover_image_url` varchar(512) DEFAULT NULL,
  `status` enum('draft','on_sale','sold_out','archived') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ends_at` datetime DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `map_embed_url` text DEFAULT NULL,
  `organizer_name` varchar(190) DEFAULT NULL,
  `organizer_email` varchar(190) DEFAULT NULL,
  `organizer_phone` varchar(50) DEFAULT NULL,
  `organizer_website` varchar(255) DEFAULT NULL,
  `organizer_facebook` varchar(255) DEFAULT NULL,
  `seating_mode` enum('ga','seatmap') NOT NULL DEFAULT 'ga',
  `selling_mode` enum('seats','ga','mixed') NOT NULL DEFAULT 'mixed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `event_images`
--

CREATE TABLE `event_images` (
  `id` int(11) NOT NULL,
  `event_id` char(36) NOT NULL,
  `url` varchar(512) NOT NULL,
  `is_cover` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `event_seatmaps`
--

CREATE TABLE `event_seatmaps` (
  `event_id` char(36) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `schema_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`schema_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `event_ticket_types`
--

CREATE TABLE `event_ticket_types` (
  `id` int(10) UNSIGNED NOT NULL,
  `event_id` char(36) NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(100) NOT NULL,
  `prices_json` longtext NOT NULL CHECK (json_valid(`prices_json`)),
  `color` varchar(16) DEFAULT NULL,
  `capacity` int(11) NOT NULL,
  `sold` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `event_ticket_type_holds`
--

CREATE TABLE `event_ticket_type_holds` (
  `id` int(11) NOT NULL,
  `event_id` char(36) NOT NULL,
  `type_id` int(11) NOT NULL,
  `sid` varchar(128) NOT NULL,
  `qty` int(11) NOT NULL,
  `hold_until` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `orders`
--

CREATE TABLE `orders` (
  `id` char(36) NOT NULL,
  `event_id` char(36) NOT NULL,
  `email` varchar(190) NOT NULL,
  `buyer_name` varchar(190) DEFAULT NULL,
  `total_cents` int(11) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK',
  `status` enum('pending','paid','failed','refunded','cancelled') NOT NULL,
  `channel` enum('online','boxoffice') NOT NULL DEFAULT 'online',
  `payment_ref` varchar(190) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `order_items`
--

CREATE TABLE `order_items` (
  `order_id` char(36) NOT NULL,
  `seat_id` varchar(100) NOT NULL,
  `price_cents` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `price_tiers`
--

CREATE TABLE `price_tiers` (
  `id` int(11) NOT NULL,
  `event_id` char(36) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_cents` int(11) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `seats_runtime`
--

CREATE TABLE `seats_runtime` (
  `event_id` char(36) NOT NULL,
  `seat_id` varchar(100) NOT NULL,
  `price_tier_id` int(11) DEFAULT NULL,
  `state` enum('free','held','sold') NOT NULL DEFAULT 'free',
  `hold_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabulky `tickets`
--

CREATE TABLE `tickets` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `event_id` char(36) NOT NULL,
  `seat_id` varchar(100) DEFAULT NULL,
  `qr_payload` varchar(255) NOT NULL,
  `checked_in_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexy pro exportované tabulky
--

--
-- Indexy pro tabulku `admin_user`
--
ALTER TABLE `admin_user`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexy pro tabulku `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

--
-- Indexy pro tabulku `event_images`
--
ALTER TABLE `event_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_eimg_event` (`event_id`);

--
-- Indexy pro tabulku `event_seatmaps`
--
ALTER TABLE `event_seatmaps`
  ADD PRIMARY KEY (`event_id`,`version`);

--
-- Indexy pro tabulku `event_ticket_types`
--
ALTER TABLE `event_ticket_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_event_code` (`event_id`,`code`),
  ADD KEY `idx_event` (`event_id`);

--
-- Indexy pro tabulku `event_ticket_type_holds`
--
ALTER TABLE `event_ticket_type_holds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_type` (`event_id`,`type_id`),
  ADD KEY `idx_expiry` (`hold_until`),
  ADD KEY `idx_sid` (`sid`);

--
-- Indexy pro tabulku `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_o_event` (`event_id`);

--
-- Indexy pro tabulku `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_id`,`seat_id`);

--
-- Indexy pro tabulku `price_tiers`
--
ALTER TABLE `price_tiers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pt_event` (`event_id`);

--
-- Indexy pro tabulku `seats_runtime`
--
ALTER TABLE `seats_runtime`
  ADD PRIMARY KEY (`event_id`,`seat_id`),
  ADD KEY `fk_sr_tier` (`price_tier_id`);

--
-- Indexy pro tabulku `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_payload` (`qr_payload`),
  ADD KEY `fk_t_order` (`order_id`),
  ADD KEY `fk_t_event` (`event_id`);

--
-- AUTO_INCREMENT pro tabulky
--

--
-- AUTO_INCREMENT pro tabulku `admin_user`
--
ALTER TABLE `admin_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pro tabulku `event_images`
--
ALTER TABLE `event_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pro tabulku `event_ticket_types`
--
ALTER TABLE `event_ticket_types`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pro tabulku `event_ticket_type_holds`
--
ALTER TABLE `event_ticket_type_holds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pro tabulku `price_tiers`
--
ALTER TABLE `price_tiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Omezení pro exportované tabulky
--

--
-- Omezení pro tabulku `event_images`
--
ALTER TABLE `event_images`
  ADD CONSTRAINT `fk_eimg_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Omezení pro tabulku `event_seatmaps`
--
ALTER TABLE `event_seatmaps`
  ADD CONSTRAINT `fk_sm_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Omezení pro tabulku `event_ticket_types`
--
ALTER TABLE `event_ticket_types`
  ADD CONSTRAINT `fk_event_ticket_types_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Omezení pro tabulku `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_o_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Omezení pro tabulku `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;

--
-- Omezení pro tabulku `price_tiers`
--
ALTER TABLE `price_tiers`
  ADD CONSTRAINT `fk_pt_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE;

--
-- Omezení pro tabulku `seats_runtime`
--
ALTER TABLE `seats_runtime`
  ADD CONSTRAINT `fk_sr_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sr_tier` FOREIGN KEY (`price_tier_id`) REFERENCES `price_tiers` (`id`) ON DELETE SET NULL;

--
-- Omezení pro tabulku `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `fk_t_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_t_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
