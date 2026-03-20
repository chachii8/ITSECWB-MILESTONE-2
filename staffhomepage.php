<?php
            $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
            mysqli_select_db($conn, "sole_source");
            require_once 'includes/session_init.php';
            require_once 'includes/no_cache_headers.php';

            // Check if the user is logged in and has the correct role
            if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Staff") {
                header("Location: login-admin.php");
                exit();
            }
            $staff_name = $_SESSION["fullname"];
$staff_email = $_SESSION["email"];
$staff_role = $_SESSION["role"];

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Staff Dashboard | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/staffstyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

  <!-- Header -->
  <header class="header">
    <div class="header-container">
      <div class="header-spacer"></div>
      <h1 class="logo">SOLE SOURCE</h1>
      <div class="header-icons">
        <div class="user-profile-container">
          <button class="icon-button" onclick="window.location.href='userprofilestaff.php'">
            <i class="fas fa-user"></i>
          </button>
          <div class="profile-hover-info">
          <div class="profile-hover-info">
        <div class="user-name"><?php echo htmlspecialchars($staff_name); ?></div>
        <div class="user-role"><?php echo htmlspecialchars($staff_role); ?></div>
      </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="navigation">
      <a href="staffhomepage.php" class="nav-link active">DASHBOARD</a>
      <a href="orders-to-process.php" class="nav-link">ORDERS</a>
      <a href="update-stocks.php" class="nav-link">UPDATE STOCK</a>
    </nav>
  </header>  

  <!-- Main Content -->
  <div class="staff-container">
    <h2 class="section-title">Staff Dashboard</h2>
    <div class="card-grid">
      <!-- Orders to Process Card -->
      <div class="card">
        <h3>Orders to Process</h3>
        <p>View all customer orders that are pending shipment. You can update order statuses to reflect progress.</p>
        <div class="card-actions">
          <button onclick="window.location.href='orders-to-process.php'" class="action-btn">📦 Manage Orders</button>
        </div>
      </div>

      <!-- Update Stock Card -->
      <div class="card">
        <h3>Update Item Stocks</h3>
        <p>Keep inventory accurate by updating product stock levels. Set quantities for items running low or newly restocked.</p>
        <div class="card-actions">
          <button onclick="window.location.href='update-stocks.php'" class="action-btn">📊 Update Stock</button>
        </div>
      </div>
    </div>
  </div>  

  <script src="js/scripts.js"></script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>