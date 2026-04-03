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

    // Validate price: positive only — not negative, not zero; min ₱1
    $price_trim = trim((string) $price_raw);
    $price = validate_price($price_raw, 1, 999999.99);
    if ($price === false) {
        $valid = false;
        if ($price_trim !== '' && is_numeric($price_trim) && (float) $price_trim < 0) {
            $errorMessage .= "Price cannot be negative.<br>";
        } else {
            $errorMessage .= "Price must be between 1.00 and 999999.99 (positive amount, not zero).<br>";
        }
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

    // Validate sizes: 3–15 inclusive, half sizes OK, no duplicates
    $sizes = [];
    $seen_keys = [];
    foreach ($sizes_raw as $s) {
        $s = trim($s);
        if ($s === '') {
            continue;
        }
        $v = validate_size_add_product($s);
        if ($v === false) {
            $valid = false;
            $errorMessage .= "Each size must be a number between 3 and 15 (e.g. 6, 6.5, 10).<br>";
            continue;
        }
        $key = sprintf('%.1f', $v);
        if (isset($seen_keys[$key])) {
            $valid = false;
            $errorMessage .= "Duplicate size: {$key}. Remove duplicates.<br>";
            continue;
        }
        $seen_keys[$key] = true;
        $sizes[] = (string) $v;
    }
    if (empty($sizes)) {
        $valid = false;
        $errorMessage .= "Enter at least one size between 3 and 15 (comma-separated, e.g. 6, 7, 8).<br>";
    }

    // Validate initial stock: 0 to 1000 (per size)
    if ($stock < 0 || $stock > 1000) {
        $valid = false;
        $errorMessage .= "Initial stock must be between 0 and 1000 per size.<br>";
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
      <input type="number" id="price" name="price" step="0.01" min="1" max="999999.99" required title="Minimum ₱1.00; cannot be negative or zero">
      <small id="price-error" class="field-error" style="display:none;"></small>
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
      <input type="text" id="sizes" name="sizes" placeholder="e.g. 6, 6.5, 7, 8" required autocomplete="off">
      <small id="sizes-error" class="field-error" style="display:none;"></small>
      <small style="display:block;color:#555;font-size:12px;margin-top:4px;">Each size must be between <strong>3</strong> and <strong>15</strong> (half sizes like 6.5 allowed). No duplicate sizes.</small>
      <label for="stock">Initial Stock (per size)</label>
      <input type="number" id="stock" name="stock" min="0" max="1000" step="1" required>
      <small id="stock-error" class="field-error" style="display:none;"></small>
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
    var sizesInput = document.getElementById('sizes');
    var sizesError = document.getElementById('sizes-error');
    var SIZE_MIN = 3;
    var SIZE_MAX = 15;

    function validatePriceField() {
      if (!priceInput || !priceError) return;
      var val = parseFloat(priceInput.value);
      if (!isNaN(val) && val < 0) {
        priceError.style.display = 'block';
        priceError.textContent = 'Price cannot be negative.';
        priceInput.setCustomValidity('Price cannot be negative.');
      } else if (!isNaN(val) && val >= 0 && val < 1) {
        priceError.style.display = 'block';
        priceError.textContent = 'Price must be at least 1.00.';
        priceInput.setCustomValidity('Price must be at least 1.00.');
      } else {
        priceError.style.display = 'none';
        priceError.textContent = '';
        priceInput.setCustomValidity('');
      }
    }

    function validateStockField() {
      if (!stockInput || !stockError) return;
      var val = parseInt(stockInput.value, 10);
      if (isNaN(val) || val < 0 || val > 1000) {
        stockError.style.display = 'block';
        stockError.textContent = 'Initial stock must be between 0 and 1000.';
        stockInput.setCustomValidity('Stock must be between 0 and 1000.');
      } else {
        stockError.style.display = 'none';
        stockError.textContent = '';
        stockInput.setCustomValidity('');
      }
    }

    function parseSizes(str) {
      return str.split(',').map(function (x) { return x.trim(); }).filter(function (x) { return x.length > 0; });
    }

    function validateSizesField() {
      if (!sizesInput || !sizesError) return;
      var parts = parseSizes(sizesInput.value);
      if (parts.length === 0) {
        sizesError.style.display = 'block';
        sizesError.textContent = 'Enter at least one size between ' + SIZE_MIN + ' and ' + SIZE_MAX + '.';
        sizesInput.setCustomValidity(sizesError.textContent);
        return;
      }
      var seen = {};
      for (var i = 0; i < parts.length; i++) {
        var n = parseFloat(parts[i]);
        if (isNaN(n)) {
          sizesError.style.display = 'block';
          sizesError.textContent = 'Invalid number: ' + parts[i];
          sizesInput.setCustomValidity(sizesError.textContent);
          return;
        }
        var half = Math.round(n * 2) / 2;
        if (half < SIZE_MIN || half > SIZE_MAX) {
          sizesError.style.display = 'block';
          sizesError.textContent = 'Each size must be between ' + SIZE_MIN + ' and ' + SIZE_MAX + ' (got ' + half + ').';
          sizesInput.setCustomValidity(sizesError.textContent);
          return;
        }
        var key = half.toFixed(1);
        if (seen[key]) {
          sizesError.style.display = 'block';
          sizesError.textContent = 'Duplicate size: ' + key + '. Remove duplicates.';
          sizesInput.setCustomValidity(sizesError.textContent);
          return;
        }
        seen[key] = true;
      }
      sizesError.style.display = 'none';
      sizesError.textContent = '';
      sizesInput.setCustomValidity('');
    }

    if (priceInput && priceError) {
      priceInput.addEventListener('input', validatePriceField);
      priceInput.addEventListener('blur', validatePriceField);
    }

    if (stockInput && stockError) {
      stockInput.addEventListener('input', validateStockField);
      stockInput.addEventListener('blur', validateStockField);
    }

    if (sizesInput && sizesError) {
      sizesInput.addEventListener('input', validateSizesField);
      sizesInput.addEventListener('blur', validateSizesField);
    }

    var form = document.querySelector('.add-item-form');
    if (form) {
      form.addEventListener('submit', function () {
        validatePriceField();
        validateSizesField();
        validateStockField();
      });
    }
  })();
</script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
