<?php
require_once 'includes/db.php';
require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'audit_log.php';
require_once 'includes/input_validation.php';

// Check if the user is logged in and has the correct role
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php");
    exit();
}

$admin_name = $_SESSION["fullname"];
$admin_role = $_SESSION["role"];
$message = "";
$updateStatus = false; // Initialize status to false by default

// Handle stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id']) && isset($_POST['size'])) {
    if (!validate_csrf()) {
        $message = "Security check failed. Please try again.";
    } else {
    $product_id = validate_int_range($_POST['product_id'] ?? 0, 1, 999999);
    $size_raw = $_POST['size'] ?? '';
    $new_stock = validate_stock_integer($_POST['stock'] ?? '', 0, 1000);

    $size = validate_size($size_raw);
    if ($size !== false) $size = (string)$size;

    if ($product_id !== false && $size !== false && $new_stock !== false) {
        // Update product stock in the product_size table
        $stmt_update = mysqli_prepare($conn, "
            UPDATE product_size SET stock = ? WHERE product_id = ? AND size = ?
        ");
        mysqli_stmt_bind_param($stmt_update, "iis", $new_stock, $product_id, $size);
        if (mysqli_stmt_execute($stmt_update)) {
            mysqli_stmt_close($stmt_update);
            log_audit(
                $conn,
                $_SESSION["user_id"] ?? null,
                $_SESSION["role"] ?? null,
                "PRODUCT_STOCK_UPDATE",
                "product",
                $product_id,
                "size={$size}, stock={$new_stock}"
            );
            $updateStatus = true; // Set this to trigger popup for success
        } else {
            $message = "Error updating stock: " . mysqli_error($conn);
            mysqli_stmt_close($stmt_update);
        }
    } else {
        $message = "Invalid product, size, or stock. Use a whole number from 0 to 1000 (no decimals).";
    }
    }
}

// Fetch all products (with brand name and size/stock info)
$productQuery = "SELECT p.product_id, p.name AS product_name, p.image_url, b.name AS brand_name
                 FROM product p
                 JOIN brand b ON p.brand_id = b.brand_id";
$productResult = mysqli_query($conn, $productQuery);

// Fetch sizes and current stock for each product
$sizeQuery = "SELECT ps.product_id, ps.size, ps.stock FROM product_size ps";
$sizeResult = mysqli_query($conn, $sizeQuery);

$sizes = [];
while ($sizeRow = mysqli_fetch_assoc($sizeResult)) {
    $sizes[$sizeRow['product_id']][] = $sizeRow;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Update Stock | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/staffstyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .action-btn {
      background-color: #4CAF50;
      color: white;
      padding: 5px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }
    .action-btn:hover {
      background-color: #45a049;
    }
    .stock-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
      flex-grow: 1;
    }
    .stock-info p {
      font-size: 16px;
      color: #333;
    }
    select, input[type="number"] {
      padding: 8px;
      margin-top: 5px;
      width: 200px;
      font-size: 14px;
      border: 1px solid #ccc;
      border-radius: 5px;
    }

    .update-stock-container {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    .stock-card {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 20px;
      border: 1px solid #ddd;
      border-radius: 5px;
      background-color: #f9f9f9;
      flex-direction: column;
      text-align: center;
    }

    .product-img {
      width: 180px;
      height: 180px;
      object-fit: contain;
      background-color: #fff;
      border: 1px solid #ccc;
      padding: 8px;
      border-radius: 8px;
      display: block;
      margin: 0 auto;
    }
    
    .toast-success {
      position: fixed;
      top: 40px;
      left: 50%;
      transform: translateX(-50%);
      background: #31ce63;
      color: white;
      padding: 18px 38px;
      border-radius: 16px;
      box-shadow: 0 6px 24px #0002;
      font-size: 1.17rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 14px;
      z-index: 2000;
      animation: toastInOut 2.8s;
      opacity: 0;
      pointer-events: none;
    }

    .toast-success.show {
      opacity: 1;
      pointer-events: auto;
    }

    .toast-icon {
      font-size: 1.35em;
      color: #fff;
    }

    @keyframes toastInOut {
      0% { opacity: 0; top: 25px; }
      10% { opacity: 1; top: 40px; }
      90% { opacity: 1; top: 40px; }
      100% { opacity: 0; top: 10px; }
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
    <a href="admin-update-stocks.php" class="nav-link active">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-container">
  <h2 class="section-title">Update Item Stock</h2>

  <!-- Display products with dropdown for size and stock -->
  <div class="update-stock-container">
    <?php if (mysqli_num_rows($productResult) > 0): ?>
      <?php while ($row = mysqli_fetch_assoc($productResult)): ?>
        <div class="stock-card">
          <div class="stock-info">
            <img src="images/<?php echo htmlspecialchars($row['image_url']); ?>" alt="Product Image" class="product-img">
            <p><strong>Product:</strong> <?php echo htmlspecialchars($row['product_name']); ?></p>
            <p><strong>Brand:</strong> <?php echo htmlspecialchars($row['brand_name']); ?></p>

            <!-- Dropdown for sizes -->
            <form action="admin-update-stocks.php" method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="product_id" value="<?php echo (int)$row['product_id']; ?>" />

              <label for="size_<?php echo $row['product_id']; ?>"><strong>Size:</strong></label>
              <select name="size" id="size_<?php echo $row['product_id']; ?>" required>
                <?php foreach ($sizes[$row['product_id']] as $size): ?>
                  <option value="<?php echo $size['size']; ?>"><?php echo $size['size']; ?> (Current Stock: <?php echo $size['stock']; ?>)</option>
                <?php endforeach; ?>
              </select>
                  <br>
              <!-- Input for new stock -->
              <label for="stock_<?php echo $row['product_id']; ?>"><strong> New Stock:</strong></label>
              <input type="number" id="stock_<?php echo $row['product_id']; ?>" name="stock" min="0" max="1000" step="1" inputmode="numeric" pattern="[0-9]*" value="0" required title="Whole number 0–1000">

              <button class="action-btn" type="submit">Update Stock</button>
            </form>
          </div>
        </div>
      <?php endwhile; ?>
    <?php else: ?>
      <p style="text-align:center; color: red;">No products available to update.</p>
    <?php endif; ?>
  </div>
</div>

<script src="js/scripts.js"></script>

<?php if ($updateStatus): ?>
  <!-- Green Toast for Stock Update Success -->
  <div class="toast-success" id="toast-success">
    <span class="toast-icon"><i class="fas fa-check-circle"></i></span>
    Product stock updated successfully!
  </div>

  <script>
    window.addEventListener('DOMContentLoaded', function() {
      var toast = document.getElementById('toast-success');
      if (toast) {
        setTimeout(function() { toast.classList.add('show'); }, 100); // fade in
        setTimeout(function() { toast.classList.remove('show'); }, 2600); // fade out
      }
    });
  </script>
<?php endif; ?>

<script src="js/no-back-cache.js"></script>
</body>
</html>