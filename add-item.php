<?php
require_once 'includes/db.php';
require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'audit_log.php';
require_once 'file_upload_validation.php';
require_once 'includes/input_validation.php';

if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php");
    exit();
}

// Handle the form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf()) {
        $errorMessage = "Security check failed. Please try again.";
    } else {
    $product_name = sanitize_string($_POST['name'] ?? '', 100);
    $brand_id = (int)($_POST['brand_id'] ?? 0);
    $price_raw = $_POST['price'] ?? '';
    $color = sanitize_string($_POST['color'] ?? '', 50);
    $description = sanitize_string($_POST['description'] ?? '', 255);
    $currency_id = (int)($_POST['currency_id'] ?? 0);
    $sizes_raw = explode(',', str_replace(' ', '', $_POST['sizes'] ?? ''));
    $stock = (int)($_POST['stock'] ?? 0);

    $errorMessage = "";
    $valid = true;

    // Validate price: 1 to 999999.99
    $price = validate_price($price_raw, 1, 999999.99);
    if ($price === false) {
        $valid = false;
        $errorMessage .= "Price must be between 1 and 999999.99.<br>";
    }

    // Validate brand_id and currency_id exist
    if ($brand_id < 1) {
        $valid = false;
        $errorMessage .= "Invalid brand.<br>";
    }
    if ($currency_id < 1) {
        $valid = false;
        $errorMessage .= "Invalid currency.<br>";
    }

    // Validate sizes (e.g. 6, 6.5, 7)
    $sizes = [];
    foreach ($sizes_raw as $s) {
        $s = trim($s);
        if ($s !== '') {
            $v = validate_size($s);
            if ($v !== false) $sizes[] = (string)$v;
        }
    }
    if (empty($sizes)) {
        $valid = false;
        $errorMessage .= "At least one valid size (e.g. 6, 6.5, 7) is required.<br>";
    }

    // Validate initial stock: 0 to 1000 (per size)
    if ($stock < 0 || $stock > 1000) {
        $valid = false;
        $errorMessage .= "Initial stock cannot be negative.<br>";
    }

    if ($valid) {
        // Handle the image upload with content-based file type detection
        $image_name = $_FILES['image']['name'];
        $image_tmp_name = $_FILES['image']['tmp_name'];
        $image_new_name = $image_name;
        $image_dest = 'images/' . $image_new_name;

        $upload_validation = validate_uploaded_image_type($_FILES['image']);
        if ($upload_validation['valid']) {
            if (move_uploaded_file($image_tmp_name, $image_dest)) {
                $stmt_product = mysqli_prepare($conn, "
                    INSERT INTO product (name, brand_id, price, color, description, currency_id, image_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                mysqli_stmt_bind_param(
                    $stmt_product,
                    "sidssis",
                    $product_name,
                    $brand_id,
                    $price,
                    $color,
                    $description,
                    $currency_id,
                    $image_new_name
                );
                if (mysqli_stmt_execute($stmt_product)) {
                    $product_id = mysqli_insert_id($conn);
                    mysqli_stmt_close($stmt_product);

                    $stmt_size = mysqli_prepare($conn, "
                        INSERT INTO product_size (product_id, size, stock)
                        VALUES (?, ?, ?)
                    ");
                    foreach ($sizes as $size) {
                        mysqli_stmt_bind_param($stmt_size, "isi", $product_id, $size, $stock);
                        mysqli_stmt_execute($stmt_size);
                    }
                    mysqli_stmt_close($stmt_size);
                    log_audit(
                        $conn,
                        $_SESSION["user_id"] ?? null,
                        $_SESSION["role"] ?? null,
                        "PRODUCT_CREATE",
                        "product",
                        $product_id,
                        "name={$product_name}, brand_id={$brand_id}, price={$price}"
                    );
                    // Redirect with success toast param
                    header("Location: add-item.php?added=1");
                    exit();
                } else {
                    $errorMessage = "Database error: " . mysqli_error($conn);
                    mysqli_stmt_close($stmt_product);
                }
            } else {
                $errorMessage = "Error uploading image.";
            }
        } else {
            $errorMessage = $upload_validation['error'] ?: "Invalid file type. Only JPG, JPEG, PNG are allowed.";
        }
    }
    }
}

// Fetch brand list from the database
$brandQuery = "SELECT * FROM brand";
$brandResult = mysqli_query($conn, $brandQuery);
// Fetch currency list from the database for dropdown
$currencyQuery = "SELECT * FROM currency";
$currencyResult = mysqli_query($conn, $currencyQuery);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Add New Item | Sole Source</title>
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
      animation: toastInOut 2.7s;
      opacity: 0;
      pointer-events: none;
    }
    .toast-success.show { opacity: 1; pointer-events: auto; }
    .toast-icon { font-size: 1.35em; color: #fff; }
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
          <div class="user-name"><?php echo htmlspecialchars($_SESSION["fullname"]); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($_SESSION["role"]); ?></div>
        </div>
      </div>
    </div>
  </div>
  <nav class="navigation">
    <a href="adminhomepage.php" class="nav-link">DASHBOARD</a>
    <a href="add-item.php" class="nav-link active">ADD ITEM</a>
    <a href="edit-item.php" class="nav-link">EDIT ITEM</a>
    <a href="delete-item.php" class="nav-link">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<div class="staff-container">
  <h2 class="section-title">Add New Item</h2>
  <?php if (isset($errorMessage)): ?>
    <div style="color: red; text-align: center; margin-bottom: 14px;">
      <?php echo htmlspecialchars($errorMessage ?? '', ENT_QUOTES, 'UTF-8'); ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form action="add-item.php" method="post" enctype="multipart/form-data" class="add-item-form">
      <?php echo csrf_field(); ?>
      <label for="name">Product Name</label>
      <input type="text" id="name" name="name" required>
      <label for="brand">Brand</label>
      <select id="brand" name="brand_id" required>
        <option value="">Select a brand</option>
        <?php
        while ($brand = mysqli_fetch_assoc($brandResult)) {
            echo "<option value='" . (int)$brand['brand_id'] . "'>" . htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8') . "</option>";
        }
        ?>
      </select>
      <label for="price">Price (₱)</label>
      <input type="number" id="price" name="price" step="0.01" min="1" required>
      <small id="price-error" class="field-error" style="display:none;">Price must be at least 1.</small>
      <label for="color">Color</label>
      <input type="text" id="color" name="color" required>
      <label for="description">Description</label>
      <textarea id="description" name="description" rows="4" required></textarea>
      <label for="currency">Currency</label>
      <select id="currency" name="currency_id" required>
        <option value="">Select currency</option>
        <?php
        mysqli_data_seek($currencyResult, 0);
        while ($currency = mysqli_fetch_assoc($currencyResult)) {
            echo "<option value='" . $currency['currency_id'] . "'>" . htmlspecialchars($currency['code']) . "</option>";
        }
        ?>
      </select>
      <label for="sizes">Available Sizes (comma-separated)</label>
      <input type="text" id="sizes" name="sizes" placeholder="e.g. 6, 7, 8, 9, 10" required>
      <label for="stock">Initial Stock (per size)</label>
      <input type="number" id="stock" name="stock" required>
      <small id="stock-error" class="field-error" style="display:none;">Initial stock cannot be negative.</small>
      <label for="image">Upload Image</label>
      <input type="file" id="image" name="image" accept="image/*" required>
      <button type="submit" class="action-btn">➕ Add Product</button>
    </form>
  </div>
</div>

<?php if (isset($_GET['added'])): ?>
  <div class="toast-success" id="toast-success">
    <span class="toast-icon"><i class="fas fa-check-circle"></i></span>
    Item added successfully!
  </div>
  <script>
    window.addEventListener('DOMContentLoaded', function() {
      var toast = document.getElementById('toast-success');
      if (toast) {
        setTimeout(function() { toast.classList.add('show'); }, 100);
        setTimeout(function() { toast.classList.remove('show'); }, 2500);
      }
    });
  </script>
<?php endif; ?>

<script src="js/scripts.js"></script>
<script>
  (function () {
    var priceInput = document.getElementById('price');
    var priceError = document.getElementById('price-error');
    var stockInput = document.getElementById('stock');
    var stockError = document.getElementById('stock-error');

    function validatePriceField() {
      if (!priceInput || !priceError) return;
      var val = parseFloat(priceInput.value);
      if (!isNaN(val) && val < 1) {
        priceError.style.display = 'block';
        priceInput.setCustomValidity('Price must be at least 1.');
      } else {
        priceError.style.display = 'none';
        priceInput.setCustomValidity('');
      }
    }

    function validateStockField() {
      if (!stockInput || !stockError) return;
      var val = parseInt(stockInput.value, 10);
      if (!isNaN(val) && (val < 0 || val > 1000)) {
        stockError.style.display = 'block';
        stockInput.setCustomValidity('Initial stock cannot be negative.');
      } else {
        stockError.style.display = 'none';
        stockInput.setCustomValidity('');
      }
    }

    if (priceInput && priceError) {
      priceInput.addEventListener('input', validatePriceField);
      priceInput.addEventListener('blur', validatePriceField);
    }

    if (stockInput && stockError) {
      stockInput.addEventListener('input', validateStockField);
      stockInput.addEventListener('blur', validateStockField);
    }
  })();
</script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
