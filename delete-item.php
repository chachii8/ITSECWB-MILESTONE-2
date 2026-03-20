<?php
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
mysqli_select_db($conn, "sole_source");
require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'audit_log.php';
require_once 'includes/input_validation.php';

if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php");
    exit();
}

$admin_name = $_SESSION["fullname"];
$admin_role = $_SESSION["role"];

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    if (!validate_csrf()) {
        $errorMessage = "Security check failed. Please try again.";
    } else {
    $product_id = validate_int_range($_POST['product_id'] ?? 0, 1, 999999);
    if ($product_id === false) {
        $errorMessage = "Invalid product.";
    } else {

    // Remove related entries from product_size (for foreign key integrity)
    $stmt_size = mysqli_prepare($conn, "DELETE FROM product_size WHERE product_id = ?");
    mysqli_stmt_bind_param($stmt_size, "i", $product_id);
    mysqli_stmt_execute($stmt_size);
    mysqli_stmt_close($stmt_size);

    // Remove from favorites table if you have one
    $stmt_fav = mysqli_prepare($conn, "DELETE FROM favorites WHERE product_id = ?");
    mysqli_stmt_bind_param($stmt_fav, "i", $product_id);
    mysqli_stmt_execute($stmt_fav);
    mysqli_stmt_close($stmt_fav);

    // Delete from product table
    $stmt_del = mysqli_prepare($conn, "DELETE FROM product WHERE product_id = ?");
    mysqli_stmt_bind_param($stmt_del, "i", $product_id);
    $result = mysqli_stmt_execute($stmt_del);
    mysqli_stmt_close($stmt_del);
    if ($result) {
        log_audit(
            $conn,
            $_SESSION["user_id"] ?? null,
            $_SESSION["role"] ?? null,
            "PRODUCT_DELETE",
            "product",
            $product_id,
            null
        );
        // Use a GET param for a page reload + toast (avoids POST resubmit warning)
        header("Location: delete-item.php?deleted=1");
        exit();
    } else {
        $errorMessage = "Error deleting product: " . mysqli_error($conn);
    }
    }
    }
}

// Fetch all products
$productQuery = "SELECT p.*, b.name AS brand_name
                FROM product p
                JOIN brand b ON p.brand_id = b.brand_id
                ORDER BY p.product_id DESC";
$productResult = mysqli_query($conn, $productQuery);

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delete Item | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/staffstyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .toast-success {
      position: fixed;
      top: 40px;
      left: 50%;
      transform: translateX(-50%);
      background: #31ce63;
      color: #fff;
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
    <a href="delete-item.php" class="nav-link active">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<div class="staff-container">
  <h2 class="section-title">Delete Items</h2>

  <?php if (isset($errorMessage)): ?>
      <p style="color: red; text-align: center;"><?php echo htmlspecialchars($errorMessage ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
  <?php endif; ?>

  <?php if (mysqli_num_rows($productResult) > 0): ?>
    <?php while ($row = mysqli_fetch_assoc($productResult)): ?>
      <div class="stock-card">
        <div class="stock-info">
          <img src="images/<?php echo htmlspecialchars($row['image_url']); ?>" 
               alt="<?php echo htmlspecialchars($row['name']); ?>" 
               class="order-img"
               style="width:90px; height:auto; object-fit:cover; border-radius:8px;">
          <div class="order-details">
            <p><strong>Product:</strong> <?php echo htmlspecialchars($row['name']); ?></p>
            <p><strong>Brand:</strong> <?php echo htmlspecialchars($row['brand_name']); ?></p>
            <p><strong>Color:</strong> <?php echo htmlspecialchars($row['color']); ?></p>
            <p><strong>Price:</strong> ₱<?php echo number_format($row['price'], 2); ?></p>
            <p><strong>Description:</strong> <?php echo htmlspecialchars($row['description']); ?></p>
          </div>
        </div>
        <form method="post" action="delete-item.php" 
              onsubmit="return confirm('Are you sure you want to delete this product?');" 
              style="margin-top: 10px;">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="product_id" value="<?php echo (int)$row['product_id']; ?>" />
          <button type="submit" class="action-btn" style="background-color: #c0392b;">🗑️ Delete</button>
        </form>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
      <p style="text-align:center;">No products available.</p>
  <?php endif; ?>
</div>

<?php if (isset($_GET['deleted'])): ?>
  <div class="toast-success" id="toast-success">
    <span class="toast-icon"><i class="fas fa-check-circle"></i></span>
    Product deleted successfully!
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

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
