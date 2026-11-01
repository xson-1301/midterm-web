<?php
/**
 * dashboard.php - CRUD cho bảng labs (Thành viên 1)
 * Bảng liên quan: equipment (equipment.lab_id -> labs.lab_id, quan hệ 1-N)
 *
 * Công nghệ: PHP + PDO + prepared statements, try/catch, htmlspecialchars,
 * validate phía server, xóa bằng POST + CSRF token, xử lý lỗi khóa ngoại.
 */
declare(strict_types=1);
session_start();

/* ----------------------------- 1. CẤU HÌNH ----------------------------- */
// XAMPP mặc định: user root, mật khẩu rỗng
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'lab_equipment_m1';
const DB_USER = 'root';
const DB_PASS = '';

// Danh sách trạng thái hợp lệ (whitelist) - khớp với CHECK trong database.sql
const STATUS_LABELS = [
    'active'      => 'Đang hoạt động',
    'maintenance' => 'Đang bảo trì',
    'closed'      => 'Đã đóng',
];

/* ----------------------------- 2. HÀM TIỆN ÍCH ------------------------- */
// Luôn dùng e() khi in dữ liệu ra HTML để chống XSS
function e(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Đếm số ký tự UTF-8 (không phụ thuộc extension mbstring)
function utf8Len(string $s): int
{
    return (int)preg_match_all('/./us', $s);
}

function flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function redirect(string $q = ''): never
{
    header('Location: dashboard.php' . ($q !== '' ? '?q=' . urlencode($q) : ''));
    exit;
}

/**
 * Validate dữ liệu form. Trả về [dữ liệu đã làm sạch, mảng lỗi].
 * Không chỉ dựa vào "required" của HTML - kiểm tra lại hoàn toàn ở PHP.
 */
function validateLab(array $in): array
{
    $d = [
        'lab_code'    => trim((string)($in['lab_code'] ?? '')),
        'lab_name'    => trim((string)($in['lab_name'] ?? '')),
        'building'    => trim((string)($in['building'] ?? '')),
        'room_number' => trim((string)($in['room_number'] ?? '')),
        'capacity'    => trim((string)($in['capacity'] ?? '')),
        'status'      => trim((string)($in['status'] ?? '')),
        'description' => trim((string)($in['description'] ?? '')),
    ];
    $err = [];

    if ($d['lab_code'] === '') {
        $err['lab_code'] = 'Mã phòng không được để trống.';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{2,30}$/', $d['lab_code'])) {
        $err['lab_code'] = 'Mã phòng gồm 2-30 ký tự: chữ, số, dấu - hoặc _ (không có khoảng trắng).';
    }

    if ($d['lab_name'] === '') {
        $err['lab_name'] = 'Tên phòng không được để trống.';
    } elseif (utf8Len($d['lab_name']) > 100) {
        $err['lab_name'] = 'Tên phòng tối đa 100 ký tự.';
    }

    if (utf8Len($d['building']) > 100) {
        $err['building'] = 'Tòa nhà tối đa 100 ký tự.';
    }
    if (utf8Len($d['room_number']) > 30) {
        $err['room_number'] = 'Số phòng tối đa 30 ký tự.';
    }

    if ($d['capacity'] === '') {
        $err['capacity'] = 'Sức chứa không được để trống.';
    } elseif (filter_var($d['capacity'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]) === false) {
        $err['capacity'] = 'Sức chứa phải là số nguyên từ 1 đến 1000.';
    }

    if (!array_key_exists($d['status'], STATUS_LABELS)) {
        $err['status'] = 'Trạng thái không hợp lệ.';
    }

    if (utf8Len($d['description']) > 2000) {
        $err['description'] = 'Mô tả tối đa 2000 ký tự.';
    }

    return [$d, $err];
}

/* ----------------------------- 3. KẾT NỐI PDO -------------------------- */
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $ex) {
    http_response_code(500);
    exit('Không kết nối được database. Hãy bật MySQL trong XAMPP và import database.sql.');
}

// CSRF token đơn giản cho các form POST
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ----------------------------- 4. XỬ LÝ POST --------------------------- */
$q       = trim((string)($_GET['q'] ?? ''));
$errors  = [];
$form    = ['lab_id' => 0, 'lab_code' => '', 'lab_name' => '', 'building' => '',
            'room_number' => '', 'capacity' => '', 'status' => 'active', 'description' => ''];
$editing = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $token  = (string)($_POST['csrf'] ?? '');

    if (!hash_equals($_SESSION['csrf'], $token)) {
        flash('error', 'Phiên làm việc không hợp lệ. Vui lòng thử lại.');
        redirect();
    }

    try {
        /* ---------- CREATE ---------- */
        if ($action === 'create') {
            [$data, $errors] = validateLab($_POST);
            if (!$errors) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM labs WHERE lab_code = ?');
                $st->execute([$data['lab_code']]);
                if ((int)$st->fetchColumn() > 0) {
                    $errors['lab_code'] = 'Mã phòng đã tồn tại.';
                }
            }
            if (!$errors) {
                $st = $pdo->prepare(
                    'INSERT INTO labs (lab_code, lab_name, building, room_number, capacity, status, description)
                     VALUES (:code, :name, :building, :room, :cap, :status, :descr)'
                );
                $st->execute([
                    ':code'     => $data['lab_code'],
                    ':name'     => $data['lab_name'],
                    ':building' => $data['building'] !== '' ? $data['building'] : null,
                    ':room'     => $data['room_number'] !== '' ? $data['room_number'] : null,
                    ':cap'      => (int)$data['capacity'],
                    ':status'   => $data['status'],
                    ':descr'    => $data['description'] !== '' ? $data['description'] : null,
                ]);
                flash('ok', 'Đã thêm phòng "' . $data['lab_name'] . '".');
                redirect($q);
            }
            $form = array_merge($form, $data);   // giữ lại dữ liệu đã nhập để sửa
        }

        /* ---------- UPDATE ---------- */
        elseif ($action === 'update') {
            $id = (int)($_POST['lab_id'] ?? 0);

            // Kiểm tra ID tồn tại
            $st = $pdo->prepare('SELECT lab_id FROM labs WHERE lab_id = ?');
            $st->execute([$id]);
            if (!$st->fetch()) {
                flash('error', 'Không tìm thấy phòng cần sửa (ID không tồn tại).');
                redirect($q);
            }

            [$data, $errors] = validateLab($_POST);
            if (!$errors) {
                // Mã phòng không được trùng với phòng KHÁC
                $st = $pdo->prepare('SELECT COUNT(*) FROM labs WHERE lab_code = ? AND lab_id <> ?');
                $st->execute([$data['lab_code'], $id]);
                if ((int)$st->fetchColumn() > 0) {
                    $errors['lab_code'] = 'Mã phòng đã được phòng khác sử dụng.';
                }
            }
            if (!$errors) {
                $st = $pdo->prepare(
                    'UPDATE labs SET lab_code = :code, lab_name = :name, building = :building,
                            room_number = :room, capacity = :cap, status = :status, description = :descr
                     WHERE lab_id = :id'
                );
                $st->execute([
                    ':code'     => $data['lab_code'],
                    ':name'     => $data['lab_name'],
                    ':building' => $data['building'] !== '' ? $data['building'] : null,
                    ':room'     => $data['room_number'] !== '' ? $data['room_number'] : null,
                    ':cap'      => (int)$data['capacity'],
                    ':status'   => $data['status'],
                    ':descr'    => $data['description'] !== '' ? $data['description'] : null,
                    ':id'       => $id,
                ]);
                flash('ok', 'Đã cập nhật phòng "' . $data['lab_name'] . '".');
                redirect($q);
            }
            $form    = array_merge($form, $data, ['lab_id' => $id]);
            $editing = true;
        }

        /* ---------- DELETE ---------- */
        elseif ($action === 'delete') {
            $id = (int)($_POST['lab_id'] ?? 0);

            $st = $pdo->prepare('SELECT lab_name FROM labs WHERE lab_id = ?');
            $st->execute([$id]);
            $lab = $st->fetch();
            if (!$lab) {
                flash('error', 'Không tìm thấy phòng cần xóa.');
                redirect($q);
            }

            // Kiểm tra trước: phòng còn thiết bị thì không xóa
            $st = $pdo->prepare('SELECT COUNT(*) FROM equipment WHERE lab_id = ?');
            $st->execute([$id]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                flash('error', 'Không thể xóa "' . $lab['lab_name'] . '" vì phòng còn ' . $n
                    . ' thiết bị. Hãy chuyển hoặc xóa thiết bị trước.');
                redirect($q);
            }

            try {
                $st = $pdo->prepare('DELETE FROM labs WHERE lab_id = ?');
                $st->execute([$id]);
                flash('ok', 'Đã xóa phòng "' . $lab['lab_name'] . '".');
            } catch (PDOException $ex) {
                // Lớp bảo vệ thứ hai: nếu DB vẫn từ chối vì khóa ngoại (SQLSTATE 23000)
                if ($ex->getCode() === '23000') {
                    flash('error', 'Không thể xóa: phòng đang được tham chiếu bởi dữ liệu khác.');
                } else {
                    throw $ex;
                }
            }
            redirect($q);
        }
    } catch (PDOException $ex) {
        // Trùng UNIQUE do hai người thêm cùng lúc (mã lỗi MySQL 1062)
        if (($ex->errorInfo[1] ?? 0) === 1062) {
            $errors['lab_code'] = 'Mã phòng đã tồn tại.';
        } else {
            error_log($ex->getMessage());
            flash('error', 'Lỗi cơ sở dữ liệu, thao tác chưa được thực hiện.');
            redirect($q);
        }
    }
}

/* ----------------------------- 5. XỬ LÝ GET (mở form sửa) -------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $st = $pdo->prepare('SELECT * FROM labs WHERE lab_id = ?');
        $st->execute([(int)$_GET['edit']]);
        $row = $st->fetch();
        if ($row) {
            $form    = array_merge($form, array_map(fn($v) => $v ?? '', $row));
            $editing = true;
        } else {
            flash('error', 'Không tìm thấy phòng cần sửa (ID không tồn tại).');
            redirect($q);
        }
    } catch (PDOException $ex) {
        error_log($ex->getMessage());
        flash('error', 'Lỗi cơ sở dữ liệu khi tải phòng.');
        redirect($q);
    }
}

/* ----------------------------- 6. READ (danh sách + tìm kiếm) ---------- */
$labs = [];
try {
    $sql = 'SELECT l.lab_id, l.lab_code, l.lab_name, l.building, l.room_number, l.capacity, l.status,
                   (SELECT COUNT(e.equipment_id) FROM equipment e WHERE e.lab_id = l.lab_id) AS equipment_count
            FROM labs l';
    $params = [];
    if ($q !== '') {
        // Escape ký tự đặc biệt của LIKE để tìm đúng nghĩa đen
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $sql .= ' WHERE l.lab_code LIKE ? OR l.lab_name LIKE ? OR l.building LIKE ?';
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY l.lab_id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $labs = $st->fetchAll();
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    flash('error', 'Không đọc được danh sách phòng.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quản lý phòng thực hành</title>
<style>
  :root { --bg:#f5f7fa; --card:#fff; --text:#1f2933; --muted:#667085; --line:#d9dee5;
          --primary:#1d5fa8; --danger:#b42318; --ok:#067647; }
  * { box-sizing: border-box; }
  body { margin:0; font-family: system-ui, "Segoe UI", Arial, sans-serif; background:var(--bg); color:var(--text); }
  main { max-width:1100px; margin:0 auto; padding:24px 16px 48px; }
  h1 { font-size:1.5rem; margin:0 0 4px; }
  h2 { font-size:1.1rem; margin:0 0 12px; }
  .sub { color:var(--muted); margin:0 0 20px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:18px; margin-bottom:20px; }
  .msg { padding:10px 14px; border-radius:6px; margin-bottom:16px; border:1px solid; }
  .msg.ok { color:var(--ok); border-color:#abefc6; background:#ecfdf3; }
  .msg.error { color:var(--danger); border-color:#fecdca; background:#fef3f2; }
  form.grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:14px; }
  .full { grid-column:1 / -1; }
  label { display:block; font-weight:600; font-size:.9rem; margin-bottom:4px; }
  input, select, textarea { width:100%; padding:8px 10px; border:1px solid var(--line); border-radius:6px; font:inherit; background:#fff; }
  input:focus, select:focus, textarea:focus, button:focus-visible, a:focus-visible { outline:2px solid var(--primary); outline-offset:1px; }
  .field-error { color:var(--danger); font-size:.85rem; margin-top:3px; }
  .has-error input, .has-error select, .has-error textarea { border-color:var(--danger); }
  .btn { display:inline-block; padding:8px 14px; border:1px solid var(--primary); background:var(--primary); color:#fff;
         border-radius:6px; font:inherit; cursor:pointer; text-decoration:none; }
  .btn.secondary { background:#fff; color:var(--primary); }
  .btn.danger { background:#fff; color:var(--danger); border-color:var(--danger); }
  .btn.small { padding:4px 10px; font-size:.85rem; }
  .toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
  .toolbar input { max-width:320px; }
  .table-wrap { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; min-width:760px; }
  th, td { padding:9px 10px; border-bottom:1px solid var(--line); text-align:left; vertical-align:middle; }
  th { background:#eef2f6; font-size:.85rem; }
  td.num, th.num { text-align:right; }
  .badge { padding:2px 8px; border-radius:999px; font-size:.8rem; border:1px solid var(--line); white-space:nowrap; }
  .badge.active { color:var(--ok); border-color:#abefc6; background:#ecfdf3; }
  .badge.maintenance { color:#93370d; border-color:#fedf89; background:#fffaeb; }
  .badge.closed { color:var(--danger); border-color:#fecdca; background:#fef3f2; }
  .actions { display:flex; gap:6px; }
  .actions form { margin:0; }
  .empty { text-align:center; color:var(--muted); padding:24px; }
</style>
</head>
<body>
<main>
  <h1>Quản lý phòng thực hành</h1>
  <p class="sub">Bảng chính: labs &middot; Bảng liên quan: equipment (một phòng có nhiều thiết bị)</p>

  <?php if ($flash): ?>
    <div class="msg <?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <!-- ===== FORM THÊM / SỬA ===== -->
  <section class="card" id="form-card">
    <h2><?= $editing ? 'Sửa phòng #' . e($form['lab_id']) : 'Thêm phòng mới' ?></h2>
    <form method="post" action="dashboard.php<?= $q !== '' ? '?q=' . e(urlencode($q)) : '' ?>" class="grid" novalidate>
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
      <?php if ($editing): ?><input type="hidden" name="lab_id" value="<?= e($form['lab_id']) ?>"><?php endif; ?>

      <div class="<?= isset($errors['lab_code']) ? 'has-error' : '' ?>">
        <label for="lab_code">Mã phòng *</label>
        <input id="lab_code" name="lab_code" maxlength="30" value="<?= e($form['lab_code']) ?>" required>
        <?php if (isset($errors['lab_code'])): ?><div class="field-error"><?= e($errors['lab_code']) ?></div><?php endif; ?>
      </div>

      <div class="<?= isset($errors['lab_name']) ? 'has-error' : '' ?>">
        <label for="lab_name">Tên phòng *</label>
        <input id="lab_name" name="lab_name" maxlength="100" value="<?= e($form['lab_name']) ?>" required>
        <?php if (isset($errors['lab_name'])): ?><div class="field-error"><?= e($errors['lab_name']) ?></div><?php endif; ?>
      </div>

      <div class="<?= isset($errors['building']) ? 'has-error' : '' ?>">
        <label for="building">Tòa nhà</label>
        <input id="building" name="building" maxlength="100" value="<?= e($form['building']) ?>">
        <?php if (isset($errors['building'])): ?><div class="field-error"><?= e($errors['building']) ?></div><?php endif; ?>
      </div>

      <div class="<?= isset($errors['room_number']) ? 'has-error' : '' ?>">
        <label for="room_number">Số phòng</label>
        <input id="room_number" name="room_number" maxlength="30" value="<?= e($form['room_number']) ?>">
        <?php if (isset($errors['room_number'])): ?><div class="field-error"><?= e($errors['room_number']) ?></div><?php endif; ?>
      </div>

      <div class="<?= isset($errors['capacity']) ? 'has-error' : '' ?>">
        <label for="capacity">Sức chứa *</label>
        <input id="capacity" name="capacity" type="number" min="1" max="1000" value="<?= e($form['capacity']) ?>" required>
        <?php if (isset($errors['capacity'])): ?><div class="field-error"><?= e($errors['capacity']) ?></div><?php endif; ?>
      </div>

      <div class="<?= isset($errors['status']) ? 'has-error' : '' ?>">
        <label for="status">Trạng thái *</label>
        <select id="status" name="status">
          <?php foreach (STATUS_LABELS as $val => $label): ?>
            <option value="<?= e($val) ?>" <?= $form['status'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['status'])): ?><div class="field-error"><?= e($errors['status']) ?></div><?php endif; ?>
      </div>

      <div class="full <?= isset($errors['description']) ? 'has-error' : '' ?>">
        <label for="description">Mô tả</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"><?= e($form['description']) ?></textarea>
        <?php if (isset($errors['description'])): ?><div class="field-error"><?= e($errors['description']) ?></div><?php endif; ?>
      </div>

      <div class="full actions">
        <button type="submit" class="btn"><?= $editing ? 'Lưu thay đổi' : 'Thêm phòng' ?></button>
        <?php if ($editing): ?><a class="btn secondary" href="dashboard.php<?= $q !== '' ? '?q=' . e(urlencode($q)) : '' ?>">Hủy</a><?php endif; ?>
      </div>
    </form>
  </section>

  <!-- ===== DANH SÁCH + TÌM KIẾM ===== -->
  <section class="card">
    <h2>Danh sách phòng (<?= count($labs) ?>)</h2>
    <form method="get" action="dashboard.php" class="toolbar">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Tìm theo mã, tên phòng hoặc tòa nhà" aria-label="Tìm kiếm">
      <button type="submit" class="btn secondary">Tìm</button>
      <?php if ($q !== ''): ?><a class="btn secondary" href="dashboard.php">Xóa lọc</a><?php endif; ?>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID</th><th>Mã phòng</th><th>Tên phòng</th><th>Tòa</th><th>Phòng</th>
            <th class="num">Sức chứa</th><th>Trạng thái</th><th class="num">Số thiết bị</th><th>Thao tác</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$labs): ?>
          <tr><td colspan="9" class="empty">Không có phòng nào<?= $q !== '' ? ' khớp với từ khóa.' : '. Hãy thêm phòng đầu tiên ở form phía trên.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($labs as $lab): ?>
          <tr>
            <td><?= e($lab['lab_id']) ?></td>
            <td><?= e($lab['lab_code']) ?></td>
            <td><?= e($lab['lab_name']) ?></td>
            <td><?= e($lab['building']) ?></td>
            <td><?= e($lab['room_number']) ?></td>
            <td class="num"><?= e($lab['capacity']) ?></td>
            <td><span class="badge <?= e($lab['status']) ?>"><?= e(STATUS_LABELS[$lab['status']] ?? $lab['status']) ?></span></td>
            <td class="num"><?= e($lab['equipment_count']) ?></td>
            <td>
              <div class="actions">
                <a class="btn secondary small" href="dashboard.php?edit=<?= e($lab['lab_id']) ?><?= $q !== '' ? '&amp;q=' . e(urlencode($q)) : '' ?>#form-card">Sửa</a>
                <form method="post" action="dashboard.php<?= $q !== '' ? '?q=' . e(urlencode($q)) : '' ?>"
                      onsubmit="return confirm('Xóa phòng &quot;<?= e(addslashes($lab['lab_name'])) ?>&quot;? Thao tác không thể hoàn tác.');">
                  <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="lab_id" value="<?= e($lab['lab_id']) ?>">
                  <button type="submit" class="btn danger small">Xóa</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
