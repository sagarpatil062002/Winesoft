-- WineSoft Company Backup
-- ====================================================
-- Company: Garava Hotel
-- CompID: 5
-- Financial Year ID: 3
-- Backup Date: 2026-04-06 12:17:35
-- ====================================================
-- This backup contains ALL data for: Garava Hotel
-- When restored, it will NOT affect other companies
-- Uses INSERT IGNORE for safe restoration
-- ====================================================

SET FOREIGN_KEY_CHECKS=0;

-- Table: tblsaleheader
DROP TABLE IF EXISTS `tblsaleheader_backup_5`;
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
  `CREATED_DATE` timestamp NOT NULL DEFAULT current_timestamp(),
  `CUSTOMER_ID` int(11) DEFAULT NULL,
  PRIMARY KEY (`BILL_NO`,`COMP_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: tblsaledetails
DROP TABLE IF EXISTS `tblsaledetails_backup_5`;
CREATE TABLE `tblsaledetails` (
  `BILL_NO` varchar(20) NOT NULL,
  `ITEM_CODE` varchar(20) NOT NULL,
  `QTY` decimal(10,3) NOT NULL,
  `RATE` decimal(10,3) NOT NULL,
  `AMOUNT` decimal(12,2) DEFAULT NULL,
  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
  `COMP_ID` int(11) NOT NULL,
  `CATEGORY_CODE` varchar(10) DEFAULT NULL,
  `CLASS_CODE_NEW` varchar(10) DEFAULT NULL,
  `SUBCLASS_CODE_NEW` varchar(10) DEFAULT NULL,
  `SIZE_CODE` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`BILL_NO`,`ITEM_CODE`,`COMP_ID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table: tblpurchases
DROP TABLE IF EXISTS `tblpurchases_backup_5`;
CREATE TABLE `tblpurchases` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
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
  `UpdatedAt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `AUTO_TPNO` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`ID`)
) ENGINE=InnoDB AUTO_INCREMENT=154 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `tblpurchases` (`ID`, `DATE`, `SUBCODE`, `VOC_NO`, `INV_NO`, `INV_DATE`, `TAMT`, `TPNO`, `TP_DATE`, `SCHDIS`, `CASHDIS`, `OCTROI`, `FREIGHT`, `STAX_PER`, `STAX_AMT`, `TCS_PER`, `TCS_AMT`, `MISC_CHARG`, `PUR_FLAG`, `CompID`, `CreatedAt`, `UpdatedAt`, `AUTO_TPNO`) VALUES ('149', '2021-04-08', 'AMAR', '1', '', '0000-00-00', '1172.00', '49', '2021-04-07', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', 'T', '5', '2026-04-02 09:49:09', '2026-04-02 09:49:09', 'FL1305-070421/34');
INSERT IGNORE INTO `tblpurchases` (`ID`, `DATE`, `SUBCODE`, `VOC_NO`, `INV_NO`, `INV_DATE`, `TAMT`, `TPNO`, `TP_DATE`, `SCHDIS`, `CASHDIS`, `OCTROI`, `FREIGHT`, `STAX_PER`, `STAX_AMT`, `TCS_PER`, `TCS_AMT`, `MISC_CHARG`, `PUR_FLAG`, `CompID`, `CreatedAt`, `UpdatedAt`, `AUTO_TPNO`) VALUES ('150', '2021-04-08', 'SAIT', '2', '', '0000-00-00', '83905.00', '18', '2021-04-08', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', 'T', '5', '2026-04-02 09:49:10', '2026-04-02 09:49:10', 'FL168-080421/18');
INSERT IGNORE INTO `tblpurchases` (`ID`, `DATE`, `SUBCODE`, `VOC_NO`, `INV_NO`, `INV_DATE`, `TAMT`, `TPNO`, `TP_DATE`, `SCHDIS`, `CASHDIS`, `OCTROI`, `FREIGHT`, `STAX_PER`, `STAX_AMT`, `TCS_PER`, `TCS_AMT`, `MISC_CHARG`, `PUR_FLAG`, `CompID`, `CreatedAt`, `UpdatedAt`, `AUTO_TPNO`) VALUES ('151', '2021-04-08', 'HARE', '3', '', '0000-00-00', '149691.00', '240', '2021-04-08', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', 'T', '5', '2026-04-02 09:49:12', '2026-04-02 09:49:12', 'FL170-080421/218');
INSERT IGNORE INTO `tblpurchases` (`ID`, `DATE`, `SUBCODE`, `VOC_NO`, `INV_NO`, `INV_DATE`, `TAMT`, `TPNO`, `TP_DATE`, `SCHDIS`, `CASHDIS`, `OCTROI`, `FREIGHT`, `STAX_PER`, `STAX_AMT`, `TCS_PER`, `TCS_AMT`, `MISC_CHARG`, `PUR_FLAG`, `CompID`, `CreatedAt`, `UpdatedAt`, `AUTO_TPNO`) VALUES ('152', '2021-04-08', 'ROCK', '4', '', '0000-00-00', '24440.00', '161', '2021-04-08', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', 'T', '5', '2026-04-02 09:49:14', '2026-04-02 09:49:14', 'FL177-080421/151');
INSERT IGNORE INTO `tblpurchases` (`ID`, `DATE`, `SUBCODE`, `VOC_NO`, `INV_NO`, `INV_DATE`, `TAMT`, `TPNO`, `TP_DATE`, `SCHDIS`, `CASHDIS`, `OCTROI`, `FREIGHT`, `STAX_PER`, `STAX_AMT`, `TCS_PER`, `TCS_AMT`, `MISC_CHARG`, `PUR_FLAG`, `CompID`, `CreatedAt`, `UpdatedAt`, `AUTO_TPNO`) VALUES ('153', '2021-04-09', 'AMAR', '5', '', '0000-00-00', '11826.00', '117', '2021-04-09', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', 'T', '5', '2026-04-02 12:49:56', '2026-04-02 12:49:56', 'FL1305-090421/76');

-- Table: tblpurchasedetails
DROP TABLE IF EXISTS `tblpurchasedetails_backup_5`;
CREATE TABLE `tblpurchasedetails` (
  `DetailID` int(11) NOT NULL AUTO_INCREMENT,
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
  `TotBott` int(11) DEFAULT 0,
  `AUTO_TPNO` varchar(50) DEFAULT NULL,
  `CATEGORY_CODE` varchar(10) DEFAULT NULL,
  `CLASS_CODE_NEW` varchar(10) DEFAULT NULL,
  `SUBCLASS_CODE_NEW` varchar(10) DEFAULT NULL,
  `SIZE_CODE` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`DetailID`)
) ENGINE=InnoDB AUTO_INCREMENT=696 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

