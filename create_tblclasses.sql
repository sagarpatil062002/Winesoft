-- Create tblclasses table if it doesn't exist
CREATE TABLE IF NOT EXISTS tblclasses (
    SRNO VARCHAR(10) PRIMARY KEY,
    `DESC` VARCHAR(100) NOT NULL,
    LIC_TYPE VARCHAR(20) NOT NULL,
    INDEX idx_lic_type (LIC_TYPE)
);

-- Insert class data for DEFAULT license type
INSERT INTO tblclasses (SRNO, `DESC`, LIC_TYPE) VALUES
('CLS001', 'Spirit IMFL', 'DEFAULT'),
('CLS002', 'Spirit Imported', 'DEFAULT'),
('CLS003', 'Spirit MML', 'DEFAULT'),
('CLS004', 'Indian Wine', 'DEFAULT'),
('CLS005', 'Wine Imported', 'DEFAULT'),
('CLS006', 'Wine MML', 'DEFAULT'),
('CLS007', 'Fermented Beer', 'DEFAULT'),
('CLS008', 'Mild Beer', 'DEFAULT'),
('CLS009', 'Country Liquor', 'DEFAULT')
ON DUPLICATE KEY UPDATE `DESC` = VALUES(`DESC`), LIC_TYPE = VALUES(LIC_TYPE);