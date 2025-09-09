-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 09, 2025 at 06:58 PM
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
-- Database: `winesoft`
--

-- --------------------------------------------------------

--
-- Table structure for table `license_types`
--

CREATE TABLE `license_types` (
  `id` int(11) NOT NULL,
  `license_code` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblbalcrdf`
--

CREATE TABLE `tblbalcrdf` (
  `ID` int(11) NOT NULL,
  `BCDATE` datetime NOT NULL,
  `BCAMOUNT` decimal(15,2) NOT NULL,
  `CompID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblbreakages`
--

CREATE TABLE `tblbreakages` (
  `BRK_No` bigint(20) NOT NULL,
  `BRK_Date` datetime(3) DEFAULT NULL,
  `Code` char(20) DEFAULT NULL,
  `Item_Desc` varchar(45) DEFAULT NULL,
  `Rate` decimal(18,2) DEFAULT NULL,
  `BRK_Qty` decimal(18,0) DEFAULT NULL,
  `Amount` decimal(18,2) DEFAULT NULL,
  `CompID` int(11) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `Created_At` timestamp NOT NULL DEFAULT current_timestamp(),
  `Updated_At` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblclass`
--

CREATE TABLE `tblclass` (
  `SRNO` decimal(18,0) NOT NULL,
  `SGROUP` varchar(1) DEFAULT NULL,
  `DESC` varchar(20) DEFAULT NULL,
  `LIQ_FLAG` varchar(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblcompany`
--

CREATE TABLE `tblcompany` (
  `CompID` int(11) NOT NULL,
  `COMP_NAME` varchar(50) NOT NULL,
  `CF_LINE` varchar(15) DEFAULT NULL,
  `CS_LINE` varchar(35) DEFAULT NULL,
  `FIN_YEAR` int(15) NOT NULL,
  `COMP_ADDR` varchar(100) DEFAULT NULL,
  `COMP_FLNO` varchar(12) DEFAULT NULL,
  `license_type_id` int(11) DEFAULT NULL,
  `License_Type` varchar(20) DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT current_timestamp(),
  `UPDATED_AT` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblcustomerprices`
--

CREATE TABLE `tblcustomerprices` (
  `CustPID` bigint(20) NOT NULL,
  `LCode` int(11) DEFAULT NULL,
  `Code` varchar(20) DEFAULT NULL,
  `WPrice` decimal(18,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblcustomersales`
--

CREATE TABLE `tblcustomersales` (
  `SaleID` bigint(20) NOT NULL,
  `BillNo` int(11) NOT NULL,
  `BillDate` date NOT NULL,
  `LCode` int(11) NOT NULL,
  `ItemCode` varchar(20) NOT NULL,
  `ItemName` varchar(255) NOT NULL,
  `ItemSize` varchar(50) DEFAULT NULL,
  `Rate` decimal(18,3) NOT NULL DEFAULT 0.000,
  `Quantity` int(11) NOT NULL DEFAULT 1,
  `Amount` decimal(18,3) NOT NULL DEFAULT 0.000,
  `CreatedDate` datetime DEFAULT current_timestamp(),
  `CompID` int(11) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldailystock_1`
--

CREATE TABLE `tbldailystock_1` (
  `DailyStockID` int(11) NOT NULL,
  `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `ITEM_CODE` varchar(20) NOT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `DAY_01_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_01_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_01_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_01_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_02_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_02_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_02_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_02_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_03_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_03_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_03_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_03_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_04_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_04_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_04_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_04_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_05_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_05_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_05_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_05_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_06_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_06_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_06_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_06_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_07_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_07_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_07_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_07_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_08_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_08_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_08_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_08_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_09_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_09_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_09_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_09_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_10_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_10_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_10_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_10_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_11_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_11_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_11_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_11_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_12_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_12_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_12_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_12_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_13_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_13_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_13_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_13_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_14_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_14_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_14_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_14_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_15_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_15_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_15_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_15_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_16_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_16_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_16_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_16_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_17_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_17_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_17_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_17_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_18_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_18_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_18_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_18_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_19_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_19_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_19_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_19_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_20_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_20_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_20_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_20_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_21_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_21_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_21_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_21_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_22_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_22_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_22_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_22_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_23_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_23_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_23_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_23_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_24_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_24_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_24_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_24_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_25_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_25_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_25_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_25_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_26_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_26_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_26_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_26_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_27_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_27_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_27_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_27_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_28_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_28_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_28_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_28_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_29_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_29_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_29_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_29_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_30_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_30_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_30_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_30_CLOSING` decimal(10,3) DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldailystock_2`
--

CREATE TABLE `tbldailystock_2` (
  `DailyStockID` int(11) NOT NULL,
  `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `ITEM_CODE` varchar(20) NOT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `DAY_01_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_01_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_01_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_01_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_02_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_02_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_02_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_02_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_03_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_03_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_03_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_03_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_04_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_04_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_04_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_04_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_05_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_05_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_05_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_05_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_06_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_06_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_06_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_06_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_07_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_07_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_07_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_07_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_08_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_08_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_08_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_08_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_09_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_09_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_09_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_09_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_10_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_10_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_10_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_10_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_11_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_11_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_11_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_11_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_12_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_12_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_12_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_12_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_13_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_13_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_13_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_13_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_14_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_14_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_14_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_14_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_15_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_15_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_15_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_15_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_16_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_16_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_16_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_16_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_17_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_17_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_17_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_17_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_18_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_18_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_18_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_18_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_19_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_19_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_19_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_19_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_20_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_20_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_20_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_20_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_21_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_21_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_21_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_21_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_22_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_22_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_22_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_22_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_23_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_23_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_23_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_23_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_24_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_24_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_24_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_24_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_25_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_25_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_25_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_25_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_26_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_26_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_26_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_26_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_27_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_27_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_27_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_27_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_28_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_28_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_28_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_28_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_29_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_29_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_29_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_29_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_30_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_30_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_30_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_30_CLOSING` decimal(10,3) DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldailystock_3`
--

CREATE TABLE `tbldailystock_3` (
  `DailyStockID` int(11) NOT NULL,
  `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `ITEM_CODE` varchar(20) NOT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `DAY_01_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_01_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_01_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_01_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_02_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_02_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_02_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_02_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_03_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_03_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_03_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_03_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_04_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_04_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_04_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_04_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_05_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_05_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_05_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_05_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_06_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_06_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_06_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_06_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_07_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_07_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_07_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_07_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_08_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_08_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_08_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_08_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_09_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_09_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_09_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_09_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_10_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_10_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_10_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_10_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_11_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_11_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_11_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_11_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_12_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_12_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_12_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_12_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_13_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_13_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_13_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_13_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_14_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_14_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_14_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_14_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_15_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_15_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_15_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_15_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_16_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_16_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_16_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_16_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_17_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_17_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_17_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_17_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_18_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_18_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_18_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_18_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_19_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_19_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_19_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_19_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_20_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_20_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_20_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_20_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_21_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_21_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_21_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_21_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_22_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_22_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_22_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_22_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_23_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_23_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_23_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_23_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_24_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_24_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_24_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_24_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_25_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_25_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_25_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_25_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_26_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_26_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_26_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_26_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_27_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_27_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_27_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_27_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_28_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_28_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_28_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_28_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_29_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_29_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_29_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_29_CLOSING` decimal(10,3) DEFAULT 0.000,
  `DAY_30_OPEN` decimal(10,3) DEFAULT 0.000,
  `DAY_30_PURCHASE` decimal(10,3) DEFAULT 0.000,
  `DAY_30_SALES` decimal(10,3) DEFAULT 0.000,
  `DAY_30_CLOSING` decimal(10,3) DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldailystock_base`
--

CREATE TABLE `tbldailystock_base` (
  `DailyStockID` int(11) NOT NULL,
  `STK_DATE` date NOT NULL,
  `FIN_YEAR` year(4) NOT NULL,
  `ITEM_CODE` varchar(20) NOT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `OPENING_QTY` decimal(10,3) DEFAULT 0.000,
  `PURCHASE_QTY` decimal(10,3) DEFAULT 0.000,
  `SALES_QTY` decimal(10,3) DEFAULT 0.000,
  `ADJUSTMENT_QTY` decimal(10,3) DEFAULT 0.000,
  `CLOSING_QTY` decimal(10,3) DEFAULT 0.000,
  `STOCK_TYPE` varchar(10) DEFAULT 'REGULAR',
  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbldrydays`
--

CREATE TABLE `tbldrydays` (
  `id` int(11) NOT NULL,
  `DDATE` datetime DEFAULT NULL,
  `DDESC` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblexpenses`
--

CREATE TABLE `tblexpenses` (
  `VNO` bigint(20) NOT NULL,
  `VDATE` datetime DEFAULT NULL,
  `PARTI` varchar(50) DEFAULT NULL,
  `AMOUNT` decimal(18,2) DEFAULT NULL,
  `DRCR` char(1) DEFAULT NULL,
  `NARR` varchar(100) DEFAULT NULL,
  `MODE` char(1) DEFAULT NULL,
  `REF_AC` int(11) DEFAULT NULL,
  `REF_SAC` int(11) DEFAULT NULL,
  `INV_NO` varchar(15) DEFAULT NULL,
  `LIQ_FLAG` char(1) DEFAULT NULL,
  `CHEQ_NO` varchar(20) DEFAULT NULL,
  `CHEQ_DT` date DEFAULT NULL,
  `MAIN_BK` char(2) DEFAULT NULL,
  `COMP_ID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblfinyear`
--

CREATE TABLE `tblfinyear` (
  `ID` int(11) NOT NULL,
  `START_DATE` datetime DEFAULT NULL,
  `END_DATE` datetime DEFAULT NULL,
  `ACTIVE` tinyint(1) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblgheads`
--

CREATE TABLE `tblgheads` (
  `GCODE` int(11) NOT NULL,
  `GHEAD` varchar(30) DEFAULT NULL,
  `LEVELNO` int(11) DEFAULT NULL,
  `PARENTID` int(11) DEFAULT NULL,
  `SERIAL_NO` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblitemmaster`
--

CREATE TABLE `tblitemmaster` (
  `CODE` varchar(20) NOT NULL,
  `Print_Name` varchar(10) DEFAULT NULL,
  `DETAILS` varchar(30) DEFAULT NULL,
  `DETAILS2` varchar(30) DEFAULT NULL,
  `BOTTLES` decimal(18,0) DEFAULT NULL,
  `CLASS` varchar(1) DEFAULT NULL,
  `SUB_CLASS` varchar(1) DEFAULT NULL,
  `ITEM_GROUP` varchar(1) DEFAULT NULL,
  `PPRICE` decimal(18,3) DEFAULT NULL COMMENT 'Purchase Price',
  `BPRICE` decimal(18,3) DEFAULT NULL COMMENT 'Base Price',
  `RPRICE` decimal(18,3) DEFAULT NULL COMMENT 'Retail Price',
  `MPRICE` decimal(18,0) DEFAULT NULL COMMENT 'MRP PRICE',
  `OB` decimal(18,3) DEFAULT NULL,
  `TRCPT` decimal(18,3) DEFAULT NULL,
  `TISSU` decimal(18,3) DEFAULT NULL,
  `CC` decimal(18,0) DEFAULT NULL,
  `BARCODE` varchar(15) DEFAULT NULL,
  `GOB` decimal(18,3) DEFAULT NULL,
  `GTRCPT` decimal(18,3) DEFAULT NULL,
  `GTISSU` decimal(18,3) DEFAULT NULL,
  `REORDER` decimal(18,0) DEFAULT NULL,
  `GREORDER` decimal(18,0) DEFAULT NULL,
  `PM_IMFL` decimal(18,0) DEFAULT NULL,
  `PM_BEER` decimal(18,0) DEFAULT NULL,
  `LIQ_FLAG` varchar(1) DEFAULT NULL,
  `SELECTED` tinyint(1) DEFAULT NULL,
  `SERIAL_NO` decimal(18,0) DEFAULT NULL,
  `SEQ_NO` decimal(18,0) DEFAULT NULL,
  `REF_CODE` varchar(4) DEFAULT NULL,
  `OB2` decimal(18,0) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblitem_stock`
--

CREATE TABLE `tblitem_stock` (
  `StockID` int(11) NOT NULL,
  `ITEM_CODE` varchar(20) NOT NULL,
  `FIN_YEAR` year(4) NOT NULL,
  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `OPENING_STOCK1` int(10) DEFAULT 0,
  `CURRENT_STOCK1` int(10) DEFAULT 0,
  `OPENING_STOCK2` decimal(10,3) DEFAULT 0.000,
  `CURRENT_STOCK2` decimal(10,3) DEFAULT 0.000,
  `OPENING_STOCK3` decimal(10,3) DEFAULT 0.000,
  `CURRENT_STOCK3` decimal(10,3) DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbllheads`
--

CREATE TABLE `tbllheads` (
  `LCODE` int(11) NOT NULL,
  `LHEAD` varchar(30) DEFAULT NULL,
  `GCODE` int(11) DEFAULT NULL,
  `OP_BAL` double DEFAULT NULL,
  `DRCR` varchar(2) DEFAULT NULL,
  `REF_CODE` varchar(7) DEFAULT NULL,
  `SERIAL_NO` double DEFAULT NULL,
  `CompID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblpermit`
--

CREATE TABLE `tblpermit` (
  `BILL_NO` decimal(18,0) DEFAULT NULL,
  `CODE` varchar(5) DEFAULT NULL,
  `DETAILS` varchar(30) DEFAULT NULL,
  `P_NO` varchar(15) DEFAULT NULL,
  `P_ISSDT` datetime(3) DEFAULT NULL,
  `P_EXP_DT` datetime(3) DEFAULT NULL,
  `PLACE_ISS` varchar(8) DEFAULT NULL,
  `G1` int(11) DEFAULT NULL,
  `G2` int(11) DEFAULT NULL,
  `G3` int(11) DEFAULT NULL,
  `G4` int(11) DEFAULT NULL,
  `G5` int(11) DEFAULT NULL,
  `G6` int(11) DEFAULT NULL,
  `G7` int(11) DEFAULT NULL,
  `G8` int(11) DEFAULT NULL,
  `G9` int(11) DEFAULT NULL,
  `GA` int(11) DEFAULT NULL,
  `GB` int(11) DEFAULT NULL,
  `GC` int(11) DEFAULT NULL,
  `GD` int(11) DEFAULT NULL,
  `GE` int(11) DEFAULT NULL,
  `GF` int(11) DEFAULT NULL,
  `GG` int(11) DEFAULT NULL,
  `GH` int(11) DEFAULT NULL,
  `GI` int(11) DEFAULT NULL,
  `P_STAT` varchar(1) DEFAULT NULL,
  `BTYPE` varchar(1) DEFAULT NULL,
  `LIQ_FLAG` varchar(1) DEFAULT NULL,
  `PRMT_FLAG` tinyint(1) NOT NULL,
  `PERMIT_TYPE` enum('ONE_YEAR','LIFETIME') DEFAULT 'ONE_YEAR',
  `ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblpurchasedetails`
--

CREATE TABLE `tblpurchasedetails` (
  `DetailID` int(11) NOT NULL,
  `PurchaseID` int(11) NOT NULL,
  `ItemCode` varchar(20) NOT NULL,
  `ItemName` varchar(255) NOT NULL,
  `Size` varchar(50) DEFAULT NULL,
  `Cases` decimal(10,2) DEFAULT 0.00,
  `Bottles` int(11) DEFAULT 0,
  `FreeCases` decimal(10,2) DEFAULT 0.00,
  `FreeBottles` int(11) DEFAULT 0,
  `CaseRate` decimal(12,3) DEFAULT 0.000,
  `MRP` decimal(10,2) DEFAULT 0.00,
  `Amount` decimal(12,2) DEFAULT 0.00,
  `BottlesPerCase` int(11) DEFAULT 12,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `BatchNo` varchar(50) DEFAULT NULL,
  `AutoBatch` varchar(50) DEFAULT NULL,
  `MfgMonth` varchar(20) DEFAULT NULL,
  `BL` decimal(10,2) DEFAULT 0.00,
  `VV` decimal(5,2) DEFAULT 0.00,
  `TotBott` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblpurchases`
--

CREATE TABLE `tblpurchases` (
  `ID` int(11) NOT NULL,
  `DATE` date NOT NULL,
  `SUBCODE` varchar(20) NOT NULL,
  `VOC_NO` int(11) NOT NULL,
  `INV_NO` varchar(50) DEFAULT NULL,
  `INV_DATE` date DEFAULT NULL,
  `TAMT` decimal(12,2) DEFAULT 0.00,
  `TPNO` varchar(50) DEFAULT NULL,
  `TP_DATE` date DEFAULT NULL,
  `SCHDIS` decimal(10,2) DEFAULT 0.00,
  `CASHDIS` decimal(10,2) DEFAULT 0.00,
  `OCTROI` decimal(10,2) DEFAULT 0.00,
  `FREIGHT` decimal(10,2) DEFAULT 0.00,
  `STAX_PER` decimal(5,2) DEFAULT 0.00,
  `STAX_AMT` decimal(10,2) DEFAULT 0.00,
  `TCS_PER` decimal(5,2) DEFAULT 0.00,
  `TCS_AMT` decimal(10,2) DEFAULT 0.00,
  `MISC_CHARG` decimal(10,2) DEFAULT 0.00,
  `PUR_FLAG` char(1) DEFAULT 'F',
  `CompID` int(11) NOT NULL,
  `CreatedAt` timestamp NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblsaledetails`
--

CREATE TABLE `tblsaledetails` (
  `BILL_NO` varchar(20) NOT NULL,
  `ITEM_CODE` varchar(20) NOT NULL,
  `QTY` decimal(10,3) NOT NULL,
  `RATE` decimal(10,3) NOT NULL,
  `AMOUNT` decimal(12,2) DEFAULT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `COMP_ID` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblsaleheader`
--

CREATE TABLE `tblsaleheader` (
  `BILL_NO` varchar(20) NOT NULL,
  `BILL_DATE` date NOT NULL,
  `CUST_CODE` varchar(20) DEFAULT NULL,
  `TOTAL_AMOUNT` decimal(12,2) DEFAULT 0.00,
  `DISCOUNT` decimal(10,2) DEFAULT 0.00,
  `NET_AMOUNT` decimal(12,2) DEFAULT 0.00,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `COMP_ID` int(11) NOT NULL,
  `CREATED_BY` int(11) DEFAULT NULL,
  `CREATED_DATE` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblsubclass`
--

CREATE TABLE `tblsubclass` (
  `ITEM_GROUP` varchar(3) NOT NULL,
  `CLASS` varchar(1) DEFAULT NULL,
  `OB` decimal(18,3) DEFAULT NULL,
  `RCPT` decimal(18,3) DEFAULT NULL,
  `SALE` decimal(18,3) DEFAULT NULL,
  `CLBAL` decimal(18,3) DEFAULT NULL,
  `DESC` varchar(20) DEFAULT NULL,
  `SRNO` decimal(18,0) DEFAULT NULL,
  `CC` decimal(18,0) DEFAULT NULL,
  `C_SPACE` decimal(18,0) DEFAULT NULL,
  `LIQ_FLAG` varchar(1) NOT NULL,
  `BOTTLE_PER_CASE` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tblsupplier`
--

CREATE TABLE `tblsupplier` (
  `CODE` varchar(7) DEFAULT NULL,
  `OUT_LIMIT` varchar(1) DEFAULT NULL,
  `DETAILS` varchar(30) DEFAULT NULL,
  `OBDR` decimal(18,2) DEFAULT NULL,
  `OBCR` decimal(18,2) DEFAULT NULL,
  `LBDR` decimal(18,2) DEFAULT NULL,
  `LBCR` decimal(18,2) DEFAULT NULL,
  `LEVEL` decimal(18,2) DEFAULT NULL,
  `ADDR1` varchar(40) DEFAULT NULL,
  `ADDR2` varchar(40) DEFAULT NULL,
  `PINCODE` varchar(10) DEFAULT NULL,
  `LIQ_FLAG` varchar(1) DEFAULT NULL,
  `SALES_TAX` varchar(30) DEFAULT NULL,
  `OCT_PERC` decimal(18,2) DEFAULT NULL,
  `CD_PERC` decimal(18,2) DEFAULT NULL,
  `STAX_PERC` decimal(18,2) DEFAULT NULL,
  `TCS_PERC` decimal(18,2) DEFAULT NULL,
  `SURC_PERC` decimal(18,2) DEFAULT NULL,
  `EC_PERC` decimal(18,2) DEFAULT NULL,
  `MISC_CHARG` decimal(18,2) DEFAULT NULL,
  `MODE` varchar(15) DEFAULT NULL,
  `WSTax_Perc` decimal(18,2) DEFAULT NULL,
  `MBSTax_Perc` decimal(18,2) DEFAULT NULL,
  `SBSTax_Perc` decimal(18,2) DEFAULT NULL,
  `CLSTax_Perc` decimal(18,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tbl_cash_memo_prints`
--

CREATE TABLE `tbl_cash_memo_prints` (
  `id` int(11) NOT NULL,
  `bill_no` varchar(50) NOT NULL,
  `comp_id` int(11) NOT NULL,
  `print_date` datetime NOT NULL,
  `printed_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `company_id` int(11) DEFAULT NULL,
  `is_admin` tinyint(1) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tblbalcrdf`
--
ALTER TABLE `tblbalcrdf`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `tblbreakages`
--
ALTER TABLE `tblbreakages`
  ADD PRIMARY KEY (`BRK_No`);

--
-- Indexes for table `tblclass`
--
ALTER TABLE `tblclass`
  ADD PRIMARY KEY (`SRNO`);

--
-- Indexes for table `tblcompany`
--
ALTER TABLE `tblcompany`
  ADD PRIMARY KEY (`CompID`);

--
-- Indexes for table `tblcustomersales`
--
ALTER TABLE `tblcustomersales`
  ADD PRIMARY KEY (`SaleID`);

--
-- Indexes for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_1` (`STK_MONTH`,`ITEM_CODE`),
  ADD KEY `ITEM_CODE_1` (`ITEM_CODE`);

--
-- Indexes for table `tbldailystock_2`
--
ALTER TABLE `tbldailystock_2`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_2` (`STK_MONTH`,`ITEM_CODE`),
  ADD KEY `ITEM_CODE_2` (`ITEM_CODE`);

--
-- Indexes for table `tbldailystock_3`
--
ALTER TABLE `tbldailystock_3`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_3` (`STK_MONTH`,`ITEM_CODE`),
  ADD KEY `ITEM_CODE_3` (`ITEM_CODE`);

--
-- Indexes for table `tblexpenses`
--
ALTER TABLE `tblexpenses`
  ADD PRIMARY KEY (`VNO`);

--
-- Indexes for table `tblgheads`
--
ALTER TABLE `tblgheads`
  ADD PRIMARY KEY (`GCODE`);

--
-- Indexes for table `tblitemmaster`
--
ALTER TABLE `tblitemmaster`
  ADD PRIMARY KEY (`CODE`),
  ADD KEY `CLASS` (`CLASS`),
  ADD KEY `tblitemmaster_ibfk_2` (`ITEM_GROUP`,`LIQ_FLAG`);

--
-- Indexes for table `tblitem_stock`
--
ALTER TABLE `tblitem_stock`
  ADD PRIMARY KEY (`StockID`);

--
-- Indexes for table `tbllheads`
--
ALTER TABLE `tbllheads`
  ADD PRIMARY KEY (`LCODE`);

--
-- Indexes for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  ADD PRIMARY KEY (`DetailID`),
  ADD KEY `fk_purchasedetails_purchaseid` (`PurchaseID`);

--
-- Indexes for table `tblpurchases`
--
ALTER TABLE `tblpurchases`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `tblsaledetails`
--
ALTER TABLE `tblsaledetails`
  ADD PRIMARY KEY (`BILL_NO`);

--
-- Indexes for table `tblsaleheader`
--
ALTER TABLE `tblsaleheader`
  ADD PRIMARY KEY (`BILL_NO`);

--
-- Indexes for table `tbl_cash_memo_prints`
--
ALTER TABLE `tbl_cash_memo_prints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bill_no` (`bill_no`),
  ADD KEY `idx_print_date` (`print_date`),
  ADD KEY `idx_comp_id` (`comp_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tblbalcrdf`
--
ALTER TABLE `tblbalcrdf`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblbreakages`
--
ALTER TABLE `tblbreakages`
  MODIFY `BRK_No` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbldailystock_2`
--
ALTER TABLE `tbldailystock_2`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbldailystock_3`
--
ALTER TABLE `tbldailystock_3`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblitem_stock`
--
ALTER TABLE `tblitem_stock`
  MODIFY `StockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  MODIFY `DetailID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tblpurchases`
--
ALTER TABLE `tblpurchases`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbl_cash_memo_prints`
--
ALTER TABLE `tbl_cash_memo_prints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  ADD CONSTRAINT `fk_purchasedetails_purchaseid` FOREIGN KEY (`PurchaseID`) REFERENCES `tblpurchases` (`ID`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
