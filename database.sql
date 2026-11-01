-- =====================================================================
-- THÀNH VIÊN 1: labs (bảng chính - CRUD) + equipment (bảng liên quan)
-- Quan hệ 1-N: một lab có nhiều equipment; equipment.lab_id là FK -> labs.lab_id
-- Chạy trên XAMPP: phpMyAdmin -> tab "Import" -> chọn file này, hoặc dán vào tab SQL.
-- =====================================================================

SET NAMES utf8mb4;   -- bảo đảm tiếng Việt không bị lỗi font khi import

CREATE DATABASE IF NOT EXISTS lab_equipment_m1
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE lab_equipment_m1;

-- Xóa bảng con trước, bảng cha sau (để chạy lại file nhiều lần không lỗi FK)
DROP TABLE IF EXISTS equipment;
DROP TABLE IF EXISTS labs;

-- ---------------------------------------------------------------------
-- Bảng chính: labs
-- ---------------------------------------------------------------------
CREATE TABLE labs (
  lab_id      INT          NOT NULL AUTO_INCREMENT,
  lab_code    VARCHAR(30)  NOT NULL,                 -- mã nghiệp vụ, không trùng
  lab_name    VARCHAR(100) NOT NULL,
  building    VARCHAR(100) NULL,
  room_number VARCHAR(30)  NULL,
  capacity    INT          NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'active', -- active / maintenance / closed
  description TEXT         NULL,
  PRIMARY KEY (lab_id),
  UNIQUE KEY uq_labs_lab_code (lab_code),
  CONSTRAINT chk_labs_status   CHECK (status IN ('active','maintenance','closed')),
  CONSTRAINT chk_labs_capacity CHECK (capacity IS NULL OR capacity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Bảng liên quan: equipment (nhiều thiết bị thuộc một lab)
-- ON DELETE RESTRICT: không cho xóa lab nếu còn thiết bị tham chiếu
-- ---------------------------------------------------------------------
CREATE TABLE equipment (
  equipment_id        INT          NOT NULL AUTO_INCREMENT,
  equipment_code      VARCHAR(30)  NOT NULL,
  equipment_name      VARCHAR(120) NOT NULL,
  lab_id              INT          NOT NULL,
  condition_status    VARCHAR(20)  NOT NULL DEFAULT 'good',      -- good / fair / poor / broken
  availability_status VARCHAR(20)  NOT NULL DEFAULT 'available', -- available / borrowed / maintenance / retired
  PRIMARY KEY (equipment_id),
  UNIQUE KEY uq_equipment_code (equipment_code),
  KEY idx_equipment_lab_id (lab_id),
  CONSTRAINT fk_equipment_lab
    FOREIGN KEY (lab_id) REFERENCES labs (lab_id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_equipment_condition
    CHECK (condition_status IN ('good','fair','poor','broken')),
  CONSTRAINT chk_equipment_availability
    CHECK (availability_status IN ('available','borrowed','maintenance','retired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Dữ liệu mẫu: labs (10 dòng). LAB-C201 và LAB-D101 KHÔNG có thiết bị
-- để chứng minh LEFT JOIN vẫn hiển thị phòng với số thiết bị = 0.
-- ---------------------------------------------------------------------
INSERT INTO labs (lab_code, lab_name, building, room_number, capacity, status, description) VALUES
('LAB-A101', 'Phòng thực hành Mạng máy tính', 'Tòa A', 'A101', 40, 'active',      'Thực hành cấu hình router, switch và đấu nối cáp mạng.'),
('LAB-A102', 'Phòng thực hành Lập trình',     'Tòa A', 'A102', 45, 'active',      'Máy tính cài sẵn IDE cho các học phần lập trình.'),
('LAB-A201', 'Phòng Cơ sở dữ liệu',           'Tòa A', 'A201', 35, 'active',      'Thực hành MySQL, PHP, XAMPP.'),
('LAB-B101', 'Phòng Điện tử cơ bản',          'Tòa B', 'B101', 30, 'active',      'Thực hành mạch điện tử cơ bản và đo kiểm.'),
('LAB-B102', 'Phòng Vi xử lý - IoT',          'Tòa B', 'B102', 25, 'maintenance', 'Đang nâng cấp hệ thống điện và kệ để thiết bị.'),
('LAB-B201', 'Phòng Robot',                   'Tòa B', 'B201', 20, 'active',      'Lắp ráp và lập trình robot.'),
('LAB-C101', 'Phòng Vật lý đại cương',        'Tòa C', 'C101', 50, 'active',      'Thí nghiệm cơ - nhiệt - điện.'),
('LAB-C102', 'Phòng Hóa học',                 'Tòa C', 'C102', 24, 'closed',      'Tạm đóng để kiểm tra an toàn hóa chất.'),
('LAB-C201', 'Phòng Đa phương tiện',          'Tòa C', 'C201', 18, 'active',      'Phòng mới, chưa bàn giao thiết bị.'),
('LAB-D101', 'Phòng Máy chủ (Server)',        'Tòa D', 'D101', 12, 'maintenance', 'Đang chờ lắp đặt tủ rack.');

-- ---------------------------------------------------------------------
-- Dữ liệu mẫu: equipment (14 dòng, thuộc các lab id 1..8)
-- ---------------------------------------------------------------------
INSERT INTO equipment (equipment_code, equipment_name, lab_id, condition_status, availability_status) VALUES
('EQ-0001', 'Router Cisco 2901',          1, 'good',   'available'),
('EQ-0002', 'Switch Cisco 2960',          1, 'good',   'borrowed'),
('EQ-0003', 'Bộ dụng cụ bấm cáp mạng',    1, 'fair',   'available'),
('EQ-0004', 'Máy tính Dell OptiPlex 01',  2, 'good',   'available'),
('EQ-0005', 'Máy tính Dell OptiPlex 02',  2, 'fair',   'available'),
('EQ-0006', 'Máy chiếu Epson EB-X06',     3, 'good',   'available'),
('EQ-0007', 'Máy hiện sóng Rigol DS1054Z',4, 'good',   'borrowed'),
('EQ-0008', 'Nguồn một chiều 30V-5A',     4, 'poor',   'maintenance'),
('EQ-0009', 'Đồng hồ vạn năng Fluke 117', 4, 'good',   'available'),
('EQ-0010', 'Kit Arduino Mega 2560',      5, 'fair',   'maintenance'),
('EQ-0011', 'Cánh tay robot 6 trục',      6, 'good',   'available'),
('EQ-0012', 'Máy đo gia tốc rơi tự do',   7, 'fair',   'available'),
('EQ-0013', 'Cân phân tích điện tử',      8, 'broken', 'retired'),
('EQ-0014', 'Tủ hút khí độc',             8, 'poor',   'maintenance');
