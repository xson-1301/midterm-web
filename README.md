# Quản lý Phòng Thực Hành (Lab Management)

> **Bài thi giữa kỳ – Lập trình Web**
> Thành viên 1: CRUD bảng `labs` + bảng liên quan `equipment`

## Mô tả

Ứng dụng web quản lý phòng thực hành (labs) với đầy đủ chức năng CRUD, xây dựng bằng **PHP thuần** + **PDO** + **MySQL**, chạy trên **XAMPP**.

Mỗi phòng thực hành có thể chứa nhiều thiết bị — quan hệ **một–nhiều** (1-N) giữa `labs` và `equipment`.

## Tính năng

| Chức năng | Mô tả |
|-----------|-------|
| **Thêm phòng** | Nhập mã phòng, tên, tòa nhà, số phòng, sức chứa, trạng thái, mô tả |
| **Danh sách** | Hiển thị tất cả phòng kèm số thiết bị đang có |
| **Tìm kiếm** | Tìm theo mã phòng, tên phòng hoặc tòa nhà |
| **Sửa phòng** | Chỉnh sửa thông tin phòng, kiểm tra trùng mã |
| **Xóa phòng** | Xóa phòng (chỉ cho phép khi phòng không còn thiết bị) |
| **Thống kê** | 3 câu truy vấn SQL: INNER JOIN, LEFT JOIN + COUNT, GROUP BY + HAVING |

## Công nghệ

- **Backend:** PHP 8+ (strict_types, PDO, prepared statements)
- **Database:** MySQL / MariaDB (InnoDB, CHECK constraints, Foreign Key)
- **Frontend:** HTML5 + CSS thuần (responsive grid, không dùng framework)
- **Server:** XAMPP (Apache + MySQL)

## Cấu trúc thư mục

```
midterm-web/
├── dashboard.php    # Trang chính – CRUD cho bảng labs
├── database.sql     # Script tạo DB, bảng và dữ liệu mẫu
├── stats.sql        # 3 câu truy vấn thống kê (JOIN, GROUP BY, HAVING)
└── README.md        # File này
```

## Cài đặt & Chạy

### 1. Yêu cầu

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL)
- PHP 8.0 trở lên

### 2. Import database

1. Mở **XAMPP Control Panel**, bật **Apache** và **MySQL**.
2. Truy cập **phpMyAdmin**: [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
3. Vào tab **Import** → chọn file `database.sql` → nhấn **Import**.
   - Hoặc mở tab **SQL**, dán nội dung file `database.sql`, nhấn **Go**.

> Script sẽ tự tạo database `lab_equipment_m1`, 2 bảng (`labs`, `equipment`) và 24 dòng dữ liệu mẫu (10 phòng + 14 thiết bị).

### 3. Chạy ứng dụng

1. Copy thư mục `midterm-web` vào `C:\xampp\htdocs\`.
2. Truy cập trình duyệt: [http://localhost/midterm-web/dashboard.php](http://localhost/midterm-web/dashboard.php)

## Cơ sở dữ liệu

### Sơ đồ quan hệ

```
┌─────────────────────┐        ┌──────────────────────────┐
│        labs          │        │       equipment          │
├─────────────────────┤        ├──────────────────────────┤
│ lab_id (PK, AI)     │◄──────┐│ equipment_id (PK, AI)    │
│ lab_code (UNIQUE)   │       ││ equipment_code (UNIQUE)  │
│ lab_name            │       ││ equipment_name           │
│ building            │       └┤ lab_id (FK)              │
│ room_number         │        │ condition_status         │
│ capacity            │        │ availability_status      │
│ status              │        └──────────────────────────┘
│ description         │         ON DELETE RESTRICT
└─────────────────────┘         ON UPDATE CASCADE
        1                  ◄──────────  N
```

### Trạng thái phòng (`labs.status`)

| Giá trị | Ý nghĩa |
|---------|---------|
| `active` | Đang hoạt động |
| `maintenance` | Đang bảo trì |
| `closed` | Đã đóng |

### Trạng thái thiết bị (`equipment`)

| Cột | Giá trị hợp lệ |
|-----|----------------|
| `condition_status` | `good`, `fair`, `poor`, `broken` |
| `availability_status` | `available`, `borrowed`, `maintenance`, `retired` |

## Dữ liệu mẫu

- **10 phòng** thuộc 4 tòa nhà (A, B, C, D)
- **14 thiết bị** phân bổ trong 8 phòng
- 2 phòng (`LAB-C201`, `LAB-D101`) **không có thiết bị** → dùng để minh họa LEFT JOIN

## Các câu truy vấn thống kê (`stats.sql`)

| # | Loại | Mục đích | Kết quả |
|---|------|----------|---------|
| Q1 | `INNER JOIN` | Liệt kê phòng cùng thiết bị của nó | 14 dòng (chỉ phòng có thiết bị) |
| Q2 | `LEFT JOIN` + `COUNT` + `GROUP BY` | Đếm thiết bị của **tất cả** phòng | 10 dòng (phòng trống hiện 0) |
| Q3 | `GROUP BY` + `HAVING` + `AVG`/`SUM` | Thống kê theo trạng thái, lọc AVG(capacity) ≥ 20 | 2 dòng (active, closed) |

## Bảo mật

- ✅ **Chống SQL Injection**: PDO prepared statements cho mọi truy vấn
- ✅ **Chống XSS**: `htmlspecialchars()` cho mọi output ra HTML
- ✅ **CSRF Token**: Mỗi form POST đính kèm token, server kiểm tra `hash_equals()`
- ✅ **Xóa bằng POST**: Không dùng GET để thay đổi dữ liệu
- ✅ **Validate phía server**: Không chỉ dựa vào HTML5 — kiểm tra lại hoàn toàn ở PHP
- ✅ **Whitelist status**: Chỉ chấp nhận giá trị trong danh sách cho phép

## Xử lý lỗi

- Validate từng field, hiển thị lỗi cụ thể trên field tương ứng
- Giữ lại dữ liệu đã nhập khi form có lỗi (không mất dữ liệu)
- Flash message thành công/thất bại sau mỗi thao tác
- Kiểm tra khóa ngoại 2 lớp khi xóa (ứng dụng + database)
- Xử lý race condition trùng mã phòng (catch MySQL error 1062)
- Confirm JavaScript trước khi xóa

