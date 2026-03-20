<?php
            require_once 'includes/db.php';
            require_once 'includes/session_init.php';
            require_once 'includes/no_cache_headers.php';

            // Check if the user is logged in and has the correct role
            if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
                header("Location: login-admin.php");
                exit();
            }
  $admin_name = $_SESSION["fullname"];
 $admin_role = $_SESSION["role"];
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/staffstyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- Header -->
<header class="header">
  <div class="header-container">
    <div class="header-spacer"></div>
    <h1 class="logo">SOLE SOURCE</h1>
    <div class="header-icons">
      <!-- Create Account Icon -->
      <button class="icon-button" title="Create Account" onclick="window.location.href='create-account.php'">
        <i class="fas fa-user-plus"></i>
      </button>
      <!-- User Profile Icon -->
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

  <!-- Navigation -->
  <nav class="navigation">
    <a href="adminhomepage.php" class="nav-link active">DASHBOARD</a>
    <a href="add-item.php" class="nav-link">ADD ITEM</a>
    <a href="edit-item.php" class="nav-link">EDIT ITEM</a>
    <a href="delete-item.php" class="nav-link">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-container">
  <h2 class="section-title">Admin Dashboard</h2>
  <div class="card-grid">

    <div class="card">
      <h3>Add Item</h3>
      <p>Add new shoes to the catalog. Set details like name, price, sizes, and stock.</p>
      <div class="card-actions">
        <button onclick="window.location.href='add-item.php'" class="action-btn">➕ Add Item</button>
      </div>
    </div>

    <div class="card">
      <h3>Edit Item</h3>
      <p>Modify information for existing products including prices, description, and sizes.</p>
      <div class="card-actions">
        <button onclick="window.location.href='edit-item.php'" class="action-btn">✏️ Edit Item</button>
      </div>
    </div>

    <div class="card">
      <h3>Delete Item</h3>
      <p>Remove products from the catalog that are discontinued or out of circulation.</p>
      <div class="card-actions">
        <button onclick="window.location.href='delete-item.php'" class="action-btn">🗑️ Delete Item</button>
      </div>
    </div>

    <div class="card">
      <h3>Orders to Process</h3>
      <p>View and update customer orders. Change their statuses to Shipped, Delivered, etc.</p>
      <div class="card-actions">
        <button onclick="window.location.href='admin-orders.php'" class="action-btn">📦 Manage Orders</button>
      </div>
    </div>

    <div class="card">
      <h3>Update Item Stocks</h3>
      <p>Keep product stock levels accurate and updated for reliable inventory tracking.</p>
      <div class="card-actions">
        <button onclick="window.location.href='admin-update-stocks.php'" class="action-btn">📊 Update Stock</button>
      </div>
    </div>

    <div class="card">
      <h3>View All Accounts</h3>
      <p>Review and manage all system accounts, including staff and customers.</p>
      <div class="card-actions">
        <button onclick="window.location.href='view-accounts.php'" class="action-btn">👥 View Accounts</button>
      </div>
    </div>

    <div class="card">
      <h3>Audit Log</h3>
      <p>View authentication, transaction, and administrative logs for Customers, Staff, and Admins.</p>
      <div class="card-actions">
        <button onclick="window.location.href='view-audit-log.php'" class="action-btn">📋 View Audit Log</button>
      </div>
    </div>

  </div>
</div>

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>