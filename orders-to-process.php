<?php
            $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
            mysqli_select_db($conn, "sole_source");
            require_once 'includes/session_init.php';
            require_once 'includes/csrf.php';
            require_once 'includes/no_cache_headers.php';
            require_once 'audit_log.php';
require_once 'includes/input_validation.php';

            // Check if the user is logged in and has the correct role
            if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Staff") {
                header("Location: login-admin.php");
                exit();
            }
$staff_name = $_SESSION["fullname"];
$staff_email = $_SESSION["email"];
$staff_role = $_SESSION["role"];


// Function to update order status
function updateOrderStatus($orderId, $status) {
    global $conn;
    // Prepare the query to update the order status
    $query = "UPDATE `order` SET order_status = ? WHERE order_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $status, $orderId);

    // Execute the query
    return $stmt->execute();
}

// Check if form was submitted to update status
if (isset($_POST['update_status'])) {
    if (!validate_csrf()) {
        // Security check failed - do nothing
    } else {
    $status_data = explode('_', (string)$_POST['update_status'], 2);
    $status = $status_data[0] ?? '';
    $orderId = isset($status_data[1]) ? (int)$status_data[1] : 0;

    if (validate_order_status($status) && $orderId >= 1 && can_update_order_status($conn, $orderId)) {
        $statusUpdated = updateOrderStatus($orderId, $status);

        if ($statusUpdated) {
            log_audit(
                $conn,
                $_SESSION["user_id"] ?? null,
                $_SESSION["role"] ?? null,
                "ORDER_STATUS_UPDATE",
                "order",
                $orderId,
                "status={$status}"
            );
            echo "<script>
                document.getElementById('toast-success').style.display = 'block';
                setTimeout(function() { document.getElementById('toast-success').style.display = 'none'; }, 3000);
              </script>";
        } else {
            echo "<script>
                document.getElementById('toast-error').style.display = 'block';
                setTimeout(function() { document.getElementById('toast-error').style.display = 'none'; }, 3000);
              </script>";
        }
    }
    }
}

// Fetch orders to display, including 'Cancelled' orders
$query = "
    SELECT 
        o.order_id, 
        p.name AS product_name, 
        od.quantity, 
        o.order_status,
        p.image_url AS product_image
    FROM `order` o
    JOIN `order_details` od ON o.order_id = od.order_id
    JOIN `product` p ON od.product_id = p.product_id
    WHERE o.order_status IN ('Pending', 'Shipped', 'Delivered', 'Cancelled')  -- Include 'Cancelled' status
"; 

$result = mysqli_query($conn, $query);

// Check if orders were fetched
if ($result) {
    $orders = mysqli_fetch_all($result, MYSQLI_ASSOC);
} else {
    $orders = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Orders | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/staffstyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    /* Toast Styles - Position at the top */
    .toast {
        position: fixed;
        top: 10px; /* Position at the top */
        left: 50%;
        transform: translateX(-50%);
        padding: 10px 20px;
        border-radius: 5px;
        font-size: 16px;
        display: none;
        z-index: 1000;
    }
    .toast-error {
        background-color: #dc3545; /* Red for error */
        color: white;
    }
    .toast-success {
        background-color: #28a745; /* Green for success */
        color: white;
    }
  </style>
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
        <div class="user-name"><?php echo htmlspecialchars($staff_name); ?></div>
        <div class="user-role"><?php echo htmlspecialchars($staff_role); ?></div>
      </div>

      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="navigation">
    <a href="staffhomepage.php" class="nav-link">DASHBOARD</a>
    <a href="orders-to-process.php" class="nav-link active">ORDERS</a>
    <a href="update-stocks.php" class="nav-link">UPDATE STOCK</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-container">
  <h2 class="section-title">Orders to Process</h2>

  <?php if (!empty($orders)): ?>
      <?php foreach ($orders as $order): ?>
      <div class="order-card">
        <div class="order-info">
          <!-- Display the product image -->
          <img src="images/<?php echo isset($order['product_image']) ? htmlspecialchars($order['product_image']) : 'default.jpg'; ?>" alt="<?php echo isset($order['product_name']) ? htmlspecialchars($order['product_name']) : 'Product Image'; ?>" class="order-img">
          <div class="order-details">
            <p><strong>Order #:</strong> <?php echo htmlspecialchars($order['order_id']); ?></p>
            <p><strong>Product:</strong> <?php echo htmlspecialchars($order['product_name']); ?></p>
            <p><strong>Quantity:</strong> <?php echo htmlspecialchars($order['quantity']); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($order['order_status']); ?></p>
          </div>
        </div>
        <div class="card-actions">
          <?php if ($order['order_status'] === 'Delivered' || $order['order_status'] === 'Cancelled'): ?>
            <span style="color:#666;font-style:italic;">No changes allowed</span>
          <?php else: ?>
          <form method="POST" action="">
            <?php echo csrf_field(); ?>
            <?php if ($order['order_status'] === 'Pending'): ?>
            <button type="submit" name="update_status" value="Shipped_<?php echo $order['order_id']; ?>" class="action-btn">Mark as Shipped</button>
            <?php endif; ?>
            <?php if ($order['order_status'] === 'Pending' || $order['order_status'] === 'Shipped'): ?>
            <button type="submit" name="update_status" value="Delivered_<?php echo $order['order_id']; ?>" class="action-btn">Mark as Delivered</button>
            <button type="submit" name="update_status" value="Cancelled_<?php echo $order['order_id']; ?>" class="action-btn" style="background-color: #c0392b;">Cancel</button>
            <?php endif; ?>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
  <?php else: ?>
      <p>No orders to process.</p>
  <?php endif; ?>
</div>

<!-- Toast Notification for Success -->
<div id="toast-success" class="toast toast-success">Status updated successfully!</div>

<!-- Toast Notification for Error -->
<div id="toast-error" class="toast toast-error">Error updating status. Please try again!</div>

<script>
  // Handle alert on successful status update via PHP
  <?php if (isset($_POST['update_status'])): ?>
      document.getElementById('toast-success').style.display = 'block';
      setTimeout(function() { document.getElementById('toast-success').style.display = 'none'; }, 3000);
  <?php endif; ?>
</script>

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>