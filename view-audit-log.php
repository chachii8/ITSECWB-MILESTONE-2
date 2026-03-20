<?php
require_once 'includes/db.php';
require_once 'includes/session_init.php';
require_once 'includes/no_cache_headers.php';
require_once 'includes/input_validation.php';

if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php");
    exit();
}

$admin_name = $_SESSION["fullname"];
$admin_role = $_SESSION["role"];

// Check if log_category column exists
$has_category = false;
$r = mysqli_query($conn, "SHOW COLUMNS FROM audit_log LIKE 'log_category'");
if ($r && mysqli_num_rows($r) > 0) {
    $has_category = true;
}
if ($r) mysqli_free_result($r);

// Filters
$filter_role = $_GET['role'] ?? '';
$filter_category = $_GET['category'] ?? '';
$filter_action = trim($_GET['action'] ?? '');
$order = $_GET['order'] ?? 'desc';

$where = [];
$params = [];
$types = '';

if ($filter_role !== '') {
    if ($filter_role === '_none_') {
        $where[] = "(role IS NULL OR role = '')";
    } else {
        $where[] = "role = ?";
        $params[] = $filter_role;
        $types .= 's';
    }
}
if ($filter_category !== '') {
    $where[] = "log_category = ?";
    $params[] = $filter_category;
    $types .= 's';
}
if ($filter_action !== '') {
    $where[] = "action LIKE ?";
    $params[] = '%' . $filter_action . '%';
    $types .= 's';
}

$where_sql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';
$order_sql = validate_order_direction($order);

$select_cols = $has_category
    ? "id, user_id, role, action, log_category, entity_type, entity_id, details, ip_address, created_at"
    : "id, user_id, role, action, entity_type, entity_id, details, ip_address, created_at";

$query = "SELECT {$select_cols} FROM audit_log {$where_sql} ORDER BY created_at {$order_sql} LIMIT 500";
$stmt = null;
if (count($params) > 0) {
    $stmt = mysqli_prepare($conn, $query);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = false;
    }
} else {
    $result = mysqli_query($conn, $query);
}

$logs = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $logs[] = $row;
    }
    if ($stmt) mysqli_stmt_close($stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Audit Log | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/staffstyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .audit-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      align-items: center;
      margin-bottom: 20px;
      padding: 16px;
      background: #f5f5f5;
      border-radius: 8px;
    }
    .audit-filters label { font-weight: 600; margin-right: 6px; }
    .audit-filters select, .audit-filters input {
      padding: 8px 12px;
      border: 1px solid #ccc;
      border-radius: 6px;
      font-size: 14px;
    }
    .audit-filters button {
      padding: 8px 16px;
      background: #333;
      color: white;
      border: none;
      border-radius: 6px;
      cursor: pointer;
    }
    .audit-filters button:hover { background: #555; }
    .audit-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      background: white;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      border-radius: 8px;
      overflow: hidden;
    }
    .audit-table th, .audit-table td {
      padding: 10px 12px;
      text-align: left;
      border-bottom: 1px solid #eee;
    }
    .audit-table th {
      background: #333;
      color: white;
      font-weight: 600;
    }
    .audit-table tr:hover { background: #fafafa; }
    .badge-auth { background: #3498db; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-transaction { background: #27ae60; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .badge-admin { background: #e67e22; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; }
    .role-customer { color: #2980b9; }
    .role-admin { color: #c0392b; font-weight: 600; }
    .role-staff { color: #16a085; }
    .role-none { color: #7f8c8d; font-style: italic; }
    .log-details { max-width: 280px; word-break: break-word; font-size: 12px; }
    .audit-summary { margin-bottom: 16px; color: #666; font-size: 14px; }
  </style>
</head>
<body>

<header class="header">
  <div class="header-container">
    <div class="header-spacer"></div>
    <h1 class="logo">SOLE SOURCE</h1>
    <div class="header-icons">
      <button class="icon-button" title="Create Account" onclick="window.location.href='create-account.php'">
        <i class="fas fa-user-plus"></i>
      </button>
      <div class="user-profile-container">
        <button class="icon-button" onclick="window.location.href='userprofileadmin.php'">
          <i class="fas fa-user"></i>
        </button>
        <div class="profile-hover-info">
          <div class="user-name"><?php echo htmlspecialchars($admin_name); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($admin_role); ?></div>
        </div>
      </div>
    </div>
  </div>
  <nav class="navigation">
    <a href="adminhomepage.php" class="nav-link">DASHBOARD</a>
    <a href="add-item.php" class="nav-link">ADD ITEM</a>
    <a href="edit-item.php" class="nav-link">EDIT ITEM</a>
    <a href="delete-item.php" class="nav-link">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link active">AUDIT LOG</a>
  </nav>
</header>

<div class="staff-container">
  <h2 class="section-title">Audit Log</h2>
  <p class="audit-summary">Authentication, transactions, and administrative actions for Customers, Staff, and Admins. Logs are written to the database, <code>logs/audit.log</code>, and optionally to syslog (local or remote via <code>config/security_config.php</code>).</p>

  <form method="get" action="view-audit-log.php" class="audit-filters">
    <label>Role:</label>
    <select name="role">
      <option value="">All</option>
      <option value="Customer" <?php echo $filter_role === 'Customer' ? 'selected' : ''; ?>>Customer</option>
      <option value="Admin" <?php echo $filter_role === 'Admin' ? 'selected' : ''; ?>>Admin</option>
      <option value="Staff" <?php echo $filter_role === 'Staff' ? 'selected' : ''; ?>>Staff</option>
      <option value="_none_" <?php echo $filter_role === '_none_' ? 'selected' : ''; ?>>(Failed logins)</option>
    </select>
    <?php if ($has_category): ?>
    <label>Category:</label>
    <select name="category">
      <option value="">All</option>
      <option value="AUTH" <?php echo $filter_category === 'AUTH' ? 'selected' : ''; ?>>AUTH (Login/MFA)</option>
      <option value="TRANSACTION" <?php echo $filter_category === 'TRANSACTION' ? 'selected' : ''; ?>>TRANSACTION</option>
      <option value="ADMIN" <?php echo $filter_category === 'ADMIN' ? 'selected' : ''; ?>>ADMIN</option>
    </select>
    <?php endif; ?>
    <label>Action:</label>
    <input type="text" name="action" value="<?php echo htmlspecialchars($filter_action); ?>" placeholder="e.g. LOGIN_SUCCESS" />
    <label>Order:</label>
    <select name="order">
      <option value="desc" <?php echo $order === 'desc' ? 'selected' : ''; ?>>Newest first</option>
      <option value="asc" <?php echo $order === 'asc' ? 'selected' : ''; ?>>Oldest first</option>
    </select>
    <button type="submit"><i class="fas fa-filter"></i> Filter</button>
  </form>

  <div style="overflow-x: auto;">
    <table class="audit-table">
      <thead>
        <tr>
          <th>ID</th>
          <th>User ID</th>
          <th>Role</th>
          <th>Action</th>
          <?php if ($has_category): ?><th>Category</th><?php endif; ?>
          <th>Entity</th>
          <th>Details</th>
          <th>IP</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td><?php echo (int)$log['id']; ?></td>
          <td><?php echo $log['user_id'] !== null ? (int)$log['user_id'] : '—'; ?></td>
          <td class="role-<?php echo strtolower($log['role'] ?? 'none'); ?>"><?php echo htmlspecialchars($log['role'] ?? '—'); ?></td>
          <td><code><?php echo htmlspecialchars($log['action']); ?></code></td>
          <?php if ($has_category): ?>
          <td>
            <?php
            $cat = $log['log_category'] ?? null;
            if ($cat): ?>
              <span class="badge-<?php echo strtolower($cat); ?>"><?php echo htmlspecialchars($cat); ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <?php endif; ?>
          <td><?php echo htmlspecialchars(($log['entity_type'] ?? '') . ($log['entity_id'] ? '#' . $log['entity_id'] : '')); ?></td>
          <td class="log-details"><?php echo htmlspecialchars($log['details'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
          <td><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($logs)): ?>
  <p style="text-align:center; color:#666; padding:24px;">No log entries match your filters.</p>
  <?php endif; ?>
</div>

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
