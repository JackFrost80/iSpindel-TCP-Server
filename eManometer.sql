-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Erstellungszeit: 04. Mai 2020 um 18:33
-- Server-Version: 10.4.10-MariaDB-log
-- PHP-Version: 7.3.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `iSpindel`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `iGauge`
--

CREATE TABLE `iGauge` (
  `Index_` bigint(20) NOT NULL,
  `Timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `Name` varchar(64) COLLATE ascii_bin NOT NULL,
  `ID` int(11) NOT NULL,
  `Pressure` double NOT NULL,
  `Temperature` double NOT NULL,
  `Change_value` double DEFAULT NULL,
  `ResetFlag` tinyint(1) DEFAULT NULL,
  `First_value` tinyint(1) NOT NULL DEFAULT 0,
  `Carbondioxid` double NOT NULL DEFAULT 0,
  `UserToken` varchar(64) COLLATE ascii_bin DEFAULT NULL,
  `Interval` int(11) DEFAULT NULL,
  `RSSI` int(11) DEFAULT NULL,
  `Recipe` varchar(64) COLLATE ascii_bin DEFAULT 'Muenchner Dunkel',
  `Aktuell` int(1) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=ascii COLLATE=ascii_bin COMMENT='iSpindle Data';

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `iGauge`
--
ALTER TABLE `iGauge`
  ADD PRIMARY KEY (`Index_`) USING BTREE,
  ADD UNIQUE KEY `Index_` (`Index_`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `iGauge`
--
ALTER TABLE `iGauge`
  MODIFY `Index_` bigint(20) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
