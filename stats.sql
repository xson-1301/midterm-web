-- =====================================================================
-- stats.sql - Thành viên 1: labs + equipment
-- Chạy trong phpMyAdmin (tab SQL) sau khi đã import database.sql
-- =====================================================================
USE lab_equipment_m1;

-- ---------------------------------------------------------------------
-- Q1 (INNER JOIN)
-- Question: List each lab together with the equipment assigned to it.
-- Mục đích : xem phòng nào đang có thiết bị gì.
-- Kết quả  : 14 dòng (mỗi thiết bị một dòng, kèm mã/tên phòng).
--            Phòng LAB-C201, LAB-D101 KHÔNG xuất hiện vì chưa có thiết bị.
-- Giải thích: INNER JOIN chỉ giữ các cặp lab-equipment khớp
--            equipment.lab_id = labs.lab_id.
-- ---------------------------------------------------------------------
SELECT l.lab_code, l.lab_name, e.equipment_code, e.equipment_name
FROM labs l
INNER JOIN equipment e ON e.lab_id = l.lab_id
ORDER BY l.lab_code, e.equipment_code;

-- ---------------------------------------------------------------------
-- Q2 (LEFT JOIN + COUNT(cột) + GROUP BY)
-- Question: Count the equipment in every lab, including labs with none.
-- Mục đích : thống kê số thiết bị của TẤT CẢ phòng.
-- Kết quả  : 10 dòng; LAB-C201 và LAB-D101 có equipment_count = 0.
-- Giải thích: LEFT JOIN giữ mọi phòng; COUNT(e.equipment_id) chỉ đếm giá trị
--            khác NULL nên phòng không có thiết bị ra 0 (nếu dùng COUNT(*)
--            sẽ ra 1 - sai).
-- ---------------------------------------------------------------------
SELECT l.lab_id, l.lab_name, COUNT(e.equipment_id) AS equipment_count
FROM labs l
LEFT JOIN equipment e ON e.lab_id = l.lab_id
GROUP BY l.lab_id, l.lab_name
ORDER BY l.lab_id;

-- ---------------------------------------------------------------------
-- Q3 (GROUP BY + COUNT/AVG/SUM + HAVING)
-- Question: Summarize labs by status and keep only the statuses whose
--           average capacity is at least 20 seats.
-- Mục đích : so sánh quy mô phòng theo trạng thái.
-- Kết quả  : 2 dòng: active (7 phòng, TB ~34, tổng 238)
--                    closed (1 phòng, TB 24, tổng 24)
--            'maintenance' (TB 18.5) bị loại bởi HAVING.
-- Giải thích: GROUP BY gom theo status; COUNT/AVG/SUM tính cho từng nhóm;
--            HAVING lọc SAU khi gom nhóm (WHERE không dùng được với AVG).
-- ---------------------------------------------------------------------
SELECT status,
       COUNT(lab_id)  AS lab_count,
       AVG(capacity)  AS avg_capacity,
       SUM(capacity)  AS total_capacity
FROM labs
GROUP BY status
HAVING AVG(capacity) >= 20
ORDER BY status;
