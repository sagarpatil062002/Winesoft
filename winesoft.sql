-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 30, 2025 at 05:19 PM
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

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `AddCompanyStockColumns` (IN `comp_id` INT)   BEGIN
    SET @col1 = CONCAT('OPENING_STOCK', comp_id);
    SET @col2 = CONCAT('CURRENT_STOCK', comp_id);
    
    SET @sql1 = CONCAT('ALTER TABLE tblitem_stock ADD COLUMN ', @col1, ' DECIMAL(10,3) DEFAULT 0.000');
    SET @sql2 = CONCAT('ALTER TABLE tblitem_stock ADD COLUMN ', @col2, ' DECIMAL(10,3) DEFAULT 0.000');
    
    PREPARE stmt1 FROM @sql1;
    EXECUTE stmt1;
    DEALLOCATE PREPARE stmt1;
    
    PREPARE stmt2 FROM @sql2;
    EXECUTE stmt2;
    DEALLOCATE PREPARE stmt2;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `CreateCompanyDailyStockTable` (IN `comp_id` INT)   BEGIN
    SET @table_name = CONCAT('tbldailystock_', comp_id);
    
    SET @sql = CONCAT('CREATE TABLE IF NOT EXISTS ', @table_name, ' (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_DATE` date NOT NULL,
        `FIN_YEAR` year(4) NOT NULL,
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT \"F\",
        `OPENING_QTY` decimal(10,3) DEFAULT 0.000,
        `PURCHASE_QTY` decimal(10,3) DEFAULT 0.000,
        `SALES_QTY` decimal(10,3) DEFAULT 0.000,
        `ADJUSTMENT_QTY` decimal(10,3) DEFAULT 0.000,
        `CLOSING_QTY` decimal(10,3) DEFAULT 0.000,
        `STOCK_TYPE` varchar(10) DEFAULT \"REGULAR\",
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_', comp_id, '` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
        KEY `ITEM_CODE_', comp_id, '` (`ITEM_CODE`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `GetYesterdayClosingForOpening` (IN `comp_id` INT, IN `item_code` VARCHAR(20), IN `stk_date` DATE, IN `fin_year` YEAR, IN `liq_flag` CHAR(1))   BEGIN
    SET @table_name = CONCAT('tbldailystock_', comp_id);
    SET @yesterday = DATE_SUB(stk_date, INTERVAL 1 DAY);
    
    SET @sql = CONCAT('SELECT CLOSING_QTY FROM ', @table_name, ' 
                      WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ? 
                      ORDER BY STK_DATE DESC LIMIT 1');
    
    PREPARE stmt FROM @sql;
    EXECUTE stmt USING @yesterday, item_code, fin_year, liq_flag;
    DEALLOCATE PREPARE stmt;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `UpdateDailyStock` (IN `comp_id` INT, IN `stk_date` DATE, IN `fin_year` YEAR, IN `item_code` VARCHAR(20), IN `liq_flag` CHAR(1), IN `opening_qty` DECIMAL(10,3), IN `closing_qty` DECIMAL(10,3))   BEGIN
    SET @table_name = CONCAT('tbldailystock_', comp_id);
    
    -- Check if record exists
    SET @check_sql = CONCAT('SELECT COUNT(*) as count FROM ', @table_name, ' 
                           WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ?');
    
    PREPARE check_stmt FROM @check_sql;
    EXECUTE check_stmt USING stk_date, item_code, fin_year, liq_flag;
    DEALLOCATE PREPARE check_stmt;
    
    IF @count > 0 THEN
        -- Update existing record
        SET @update_sql = CONCAT('UPDATE ', @table_name, ' 
                                SET OPENING_QTY = ?, CLOSING_QTY = ?, LAST_UPDATED = CURRENT_TIMESTAMP 
                                WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ?');
        
        PREPARE update_stmt FROM @update_sql;
        EXECUTE update_stmt USING opening_qty, closing_qty, stk_date, item_code, fin_year, liq_flag;
        DEALLOCATE PREPARE update_stmt;
    ELSE
        -- Insert new record
        SET @insert_sql = CONCAT('INSERT INTO ', @table_name, ' 
                                (STK_DATE, FIN_YEAR, ITEM_CODE, LIQ_FLAG, OPENING_QTY, CLOSING_QTY) 
                                VALUES (?, ?, ?, ?, ?, ?)');
        
        PREPARE insert_stmt FROM @insert_sql;
        EXECUTE insert_stmt USING stk_date, fin_year, item_code, liq_flag, opening_qty, closing_qty;
        DEALLOCATE PREPARE insert_stmt;
    END IF;
END$$

DELIMITER ;

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

--
-- Dumping data for table `tblclass`
--

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
  `CREATED_AT` timestamp NOT NULL DEFAULT current_timestamp(),
  `UPDATED_AT` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblcompany`
--


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

--
-- Dumping data for table `tblcustomerprices`
--


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

--
-- Dumping data for table `tblcustomersales`
--


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

--
-- Dumping data for table `tbldailystock_1`
--


-- --------------------------------------------------------

--
-- Table structure for table `tbldailystock_2`
--

CREATE TABLE `tbldailystock_2` (
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

--
-- Dumping data for table `tbldailystock_2`
--


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

--
-- Dumping data for table `tbldrydays`
--


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

--
-- Dumping data for table `tblfinyear`
--


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

--
-- Dumping data for table `tblgheads`
--


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

--
-- Dumping data for table `tblitemmaster`
--


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
  `CURRENT_STOCK1` decimal(10,3) DEFAULT 0.000,
  `OPENING_STOCK2` decimal(10,3) DEFAULT 0.000,
  `CURRENT_STOCK2` decimal(10,3) DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tblitem_stock`
--


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

--
-- Dumping data for table `tbllheads`
--


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

--
-- Dumping data for table `tblpurchasedetails`
--


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

--
-- Dumping data for table `tblpurchases`
--


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

--
-- Dumping data for table `tblsubclass`
--


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

--
-- Dumping data for table `tblsupplier`
--

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
-- Dumping data for table `users`
--

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tblclass`
--
ALTER TABLE `tblclass`
  ADD PRIMARY KEY (`SRNO`),
  ADD KEY `idx_sgroup` (`SGROUP`);

--
-- Indexes for table `tblcompany`
--
ALTER TABLE `tblcompany`
  ADD PRIMARY KEY (`CompID`),
  ADD KEY `FK_tblCompany_tblFinYear` (`FIN_YEAR`);

--
-- Indexes for table `tblcustomerprices`
--
ALTER TABLE `tblcustomerprices`
  ADD PRIMARY KEY (`CustPID`),
  ADD KEY `FK_tblCustomerPrices_tbllheads` (`LCode`);

--
-- Indexes for table `tblcustomersales`
--
ALTER TABLE `tblcustomersales`
  ADD PRIMARY KEY (`SaleID`),
  ADD KEY `FK_tblcustomersales_tbllheads` (`LCode`),
  ADD KEY `FK_tblcustomersales_tblitemmaster` (`ItemCode`),
  ADD KEY `FK_tblcustomersales_tblcompany` (`CompID`),
  ADD KEY `fk_tblcustomersales_userid` (`UserID`);

--
-- Indexes for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_1` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
  ADD KEY `ITEM_CODE_1` (`ITEM_CODE`);

--
-- Indexes for table `tbldailystock_2`
--
ALTER TABLE `tbldailystock_2`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock_2` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
  ADD KEY `ITEM_CODE_2` (`ITEM_CODE`);

--
-- Indexes for table `tbldailystock_base`
--
ALTER TABLE `tbldailystock_base`
  ADD PRIMARY KEY (`DailyStockID`),
  ADD UNIQUE KEY `unique_daily_stock` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
  ADD KEY `ITEM_CODE` (`ITEM_CODE`);

--
-- Indexes for table `tbldrydays`
--
ALTER TABLE `tbldrydays`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tblfinyear`
--
ALTER TABLE `tblfinyear`
  ADD PRIMARY KEY (`ID`);

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
  ADD PRIMARY KEY (`StockID`),
  ADD UNIQUE KEY `unique_stock` (`ITEM_CODE`,`FIN_YEAR`),
  ADD KEY `ITEM_CODE` (`ITEM_CODE`);

--
-- Indexes for table `tbllheads`
--
ALTER TABLE `tbllheads`
  ADD PRIMARY KEY (`LCODE`),
  ADD KEY `fk_tbllheads_tblgheads` (`GCODE`),
  ADD KEY `FK_tbllheads_tblcompany` (`CompID`);

--
-- Indexes for table `tblpermit`
--
ALTER TABLE `tblpermit`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  ADD PRIMARY KEY (`DetailID`),
  ADD KEY `PurchaseID` (`PurchaseID`);

--
-- Indexes for table `tblpurchases`
--
ALTER TABLE `tblpurchases`
  ADD PRIMARY KEY (`ID`),
  ADD UNIQUE KEY `unique_voc` (`CompID`,`VOC_NO`);

--
-- Indexes for table `tblsaledetails`
--
ALTER TABLE `tblsaledetails`
  ADD PRIMARY KEY (`BILL_NO`,`ITEM_CODE`,`LIQ_FLAG`,`COMP_ID`),
  ADD KEY `BILL_NO` (`BILL_NO`,`LIQ_FLAG`,`COMP_ID`),
  ADD KEY `ITEM_CODE` (`ITEM_CODE`),
  ADD KEY `COMP_ID` (`COMP_ID`);

--
-- Indexes for table `tblsaleheader`
--
ALTER TABLE `tblsaleheader`
  ADD PRIMARY KEY (`BILL_NO`,`LIQ_FLAG`,`COMP_ID`),
  ADD KEY `COMP_ID` (`COMP_ID`);

--
-- Indexes for table `tblsubclass`
--
ALTER TABLE `tblsubclass`
  ADD PRIMARY KEY (`ITEM_GROUP`,`LIQ_FLAG`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `company_id` (`company_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tblcompany`
--
ALTER TABLE `tblcompany`
  MODIFY `CompID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tblcustomerprices`
--
ALTER TABLE `tblcustomerprices`
  MODIFY `CustPID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=466;

--
-- AUTO_INCREMENT for table `tblcustomersales`
--
ALTER TABLE `tblcustomersales`
  MODIFY `SaleID` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `tbldailystock_1`
--
ALTER TABLE `tbldailystock_1`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1409;

--
-- AUTO_INCREMENT for table `tbldailystock_2`
--
ALTER TABLE `tbldailystock_2`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1409;

--
-- AUTO_INCREMENT for table `tbldailystock_base`
--
ALTER TABLE `tbldailystock_base`
  MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tbldrydays`
--
ALTER TABLE `tbldrydays`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `tblfinyear`
--
ALTER TABLE `tblfinyear`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `tblgheads`
--
ALTER TABLE `tblgheads`
  MODIFY `GCODE` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `tblitem_stock`
--
ALTER TABLE `tblitem_stock`
  MODIFY `StockID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1409;

--
-- AUTO_INCREMENT for table `tbllheads`
--
ALTER TABLE `tbllheads`
  MODIFY `LCODE` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=164;

--
-- AUTO_INCREMENT for table `tblpermit`
--
ALTER TABLE `tblpermit`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  MODIFY `DetailID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `tblpurchases`
--
ALTER TABLE `tblpurchases`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tblcompany`
--
ALTER TABLE `tblcompany`
  ADD CONSTRAINT `FK_tblCompany_tblFinYear` FOREIGN KEY (`FIN_YEAR`) REFERENCES `tblfinyear` (`ID`);

--
-- Constraints for table `tblcustomerprices`
--
ALTER TABLE `tblcustomerprices`
  ADD CONSTRAINT `FK_tblCustomerPrices_tbllheads` FOREIGN KEY (`LCode`) REFERENCES `tbllheads` (`LCODE`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tblcustomersales`
--
ALTER TABLE `tblcustomersales`
  ADD CONSTRAINT `FK_tblcustomersales_tblcompany` FOREIGN KEY (`CompID`) REFERENCES `tblcompany` (`CompID`),
  ADD CONSTRAINT `FK_tblcustomersales_tbllheads` FOREIGN KEY (`LCode`) REFERENCES `tbllheads` (`LCODE`),
  ADD CONSTRAINT `fk_tblcustomersales_userid` FOREIGN KEY (`UserID`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `tblitemmaster`
--
ALTER TABLE `tblitemmaster`
  ADD CONSTRAINT `tblitemmaster_ibfk_1` FOREIGN KEY (`CLASS`) REFERENCES `tblclass` (`SGROUP`),
  ADD CONSTRAINT `tblitemmaster_ibfk_2` FOREIGN KEY (`ITEM_GROUP`,`LIQ_FLAG`) REFERENCES `tblsubclass` (`ITEM_GROUP`, `LIQ_FLAG`);

--
-- Constraints for table `tbllheads`
--
ALTER TABLE `tbllheads`
  ADD CONSTRAINT `FK_tbllheads_tblcompany` FOREIGN KEY (`CompID`) REFERENCES `tblcompany` (`CompID`),
  ADD CONSTRAINT `fk_tbllheads_tblgheads` FOREIGN KEY (`GCODE`) REFERENCES `tblgheads` (`GCODE`) ON UPDATE CASCADE;

--
-- Constraints for table `tblpurchasedetails`
--
ALTER TABLE `tblpurchasedetails`
  ADD CONSTRAINT `tblpurchasedetails_ibfk_1` FOREIGN KEY (`PurchaseID`) REFERENCES `tblpurchases` (`ID`) ON DELETE CASCADE;

--
-- Constraints for table `tblsaledetails`
--
ALTER TABLE `tblsaledetails`
  ADD CONSTRAINT `tblsaledetails_ibfk_1` FOREIGN KEY (`BILL_NO`,`LIQ_FLAG`,`COMP_ID`) REFERENCES `tblsaleheader` (`BILL_NO`, `LIQ_FLAG`, `COMP_ID`),
  ADD CONSTRAINT `tblsaledetails_ibfk_2` FOREIGN KEY (`ITEM_CODE`) REFERENCES `tblitemmaster` (`CODE`),
  ADD CONSTRAINT `tblsaledetails_ibfk_3` FOREIGN KEY (`COMP_ID`) REFERENCES `tblcompany` (`CompID`);

--
-- Constraints for table `tblsaleheader`
--
ALTER TABLE `tblsaleheader`
  ADD CONSTRAINT `tblsaleheader_ibfk_1` FOREIGN KEY (`COMP_ID`) REFERENCES `tblcompany` (`CompID`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `tblcompany` (`CompID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
