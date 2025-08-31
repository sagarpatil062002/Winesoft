-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 31, 2025 at 07:47 PM
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
  `OPENING_STOCK1` decimal(10,3) DEFAULT 0.000,
  `CURRENT_STOCK1` decimal(10,3) DEFAULT 0.000
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
-- Indexes for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_1` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
  ADD KEY `ITEM_CODE_1` (`ITEM_CODE`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
