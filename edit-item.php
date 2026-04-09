<?php 
require_once 'includes/db.php';
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
$errorMessage = '';

// Handle product update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    if (!validate_csrf()) {
        $errorMessage = "Security check failed. Please try again.";
    } else {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $name = sanitize_string($_POST['name'] ?? '', 100);
    $price_raw = $_POST['price'] ?? '';
    $description = sanitize_string($_POST['description'] ?? '', 255);
    $color = sanitize_string($_POST['color'] ?? '', 50);
    $add_sizes_raw = $_POST['add_sizes'] ?? '';
    $add_sizes_stock_raw = $_POST['add_sizes_stock'] ?? 0;

    // Validate price: 1 to 999999.99
    $price = validate_price($price_raw, 1, 999999.99);
    if ($price === false) {
        $errorMessage = "Price must be between 1 and 999999.99.";
    } elseif ($product_id < 1) {
        $errorMessage = "Invalid product.";
    } else {
        $add_sizes_ok = true;
        $new_sizes = [];
        $seen_new = [];
        if (trim((string) $add_sizes_raw) !== '') {
            $add_sizes_stock = (int) $add_sizes_stock_raw;
            if ($add_sizes_stock < 0 || $add_sizes_stock > 1000) {
                $errorMessage = "Initial stock for new sizes must be between 0 and 1000.";
                $add_sizes_ok = false;
            } else {
                $parts = explode(',', str_replace(' ', '', $add_sizes_raw));
                foreach ($parts as $s) {
                    $s = trim($s);
                    if ($s === '') {
                        continue;
                    }
                    $v = validate_size_add_product($s);
                    if ($v === false) {
                        $errorMessage = "Each new size must be a number between 3 and 15 (e.g. 6, 6.5).";
                        $add_sizes_ok = false;
                        break;
                    }
                    $key = sprintf('%.1f', $v);
                    if (isset($seen_new[$key])) {
                        $errorMessage = "Duplicate new size: {$key}.";
                        $add_sizes_ok = false;
                        break;
                    }
                    $seen_new[$key] = true;
                    $new_sizes[] = (string) $v;
                }
                if ($add_sizes_ok && empty($new_sizes)) {
                    $errorMessage = "Enter valid comma-separated sizes or leave \"Add sizes\" empty.";
                    $add_sizes_ok = false;
                }
            }
        }

        if ($add_sizes_ok && $errorMessage === '') {
            foreach ($new_sizes as $ns) {
                $chk = mysqli_prepare($conn, "SELECT 1 FROM product_size WHERE product_id = ? AND size = ? LIMIT 1");
                mysqli_stmt_bind_param($chk, "is", $product_id, $ns);
                mysqli_stmt_execute($chk);
                mysqli_stmt_store_result($chk);
                if (mysqli_stmt_num_rows($chk) > 0) {
                    mysqli_stmt_close($chk);
                    $errorMessage = "Size {$ns} already exists for this product. Use Update Stock to change quantity.";
                    $add_sizes_ok = false;
                    break;
                }
                mysqli_stmt_close($chk);
            }
        }

        if ($add_sizes_ok && $errorMessage === '') {
        $stmt_update = mysqli_prepare($conn, "
            UPDATE product SET name = ?, price = ?, description = ?, color = ? WHERE product_id = ?
        ");
        mysqli_stmt_bind_param($stmt_update, "sdssi", $name, $price, $description, $color, $product_id);
        if (mysqli_stmt_execute($stmt_update)) {
            mysqli_stmt_close($stmt_update);
            log_audit(
                $conn,
                $_SESSION["user_id"] ?? null,
                $_SESSION["role"] ?? null,
                "PRODUCT_UPDATE",
                "product",
                $product_id,
                "name={$name}, price={$price}"
            );

            if (!empty($new_sizes)) {
                $add_sizes_stock = (int) $add_sizes_stock_raw;
                $stmt_ins = mysqli_prepare($conn, "INSERT INTO product_size (product_id, size, stock) VALUES (?, ?, ?)");
                foreach ($new_sizes as $ns) {
                    mysqli_stmt_bind_param($stmt_ins, "isi", $product_id, $ns, $add_sizes_stock);
                    if (!mysqli_stmt_execute($stmt_ins)) {
                        $errorMessage = "Error adding size {$ns}: " . mysqli_error($conn);
                        break;
                    }
                }
                mysqli_stmt_close($stmt_ins);
            }

            if ($errorMessage === '') {
                header("Location: edit-item.php?updated=1");
                exit();
            }
        } else {
            $errorMessage = "Error updating product: " . mysqli_error($conn);
            mysqli_stmt_close($stmt_update);
        }
        }
    }
    }
}

// Fetch all products for display (brand name for labels + edit snapshot)
$productQuery = "SELECT p.*, c.code AS currency_code, COALESCE(b.name, '—') AS brand_name
                 FROM product p
                 JOIN currency c ON p.currency_id = c.currency_id
                 LEFT JOIN brand b ON p.brand_id = b.brand_id
                 ORDER BY p.name ASC";
$productResult = mysqli_query($conn, $productQuery);

// Fetch again for JavaScript
$productResultForJS = mysqli_query($conn, $productQuery);
$productsForJS = [];
while ($row = mysqli_fetch_assoc($productResultForJS)) {
    $productsForJS[] = $row;
}

$sizesByProduct = [];
$szq = mysqli_query($conn, "SELECT product_id, size, stock FROM product_size ORDER BY product_id, size");
if ($szq) {
    while ($r = mysqli_fetch_assoc($szq)) {
        $sizesByProduct[$r['product_id']][] = $r;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Item | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/staffstyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>

  .stock-card { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    border: 1px solid #ccc;
    margin: 20px 0; 
    padding: 15px; 
    border-radius: 8px; 
    background-color: #f9f9f9;
   }
  .order-img { 
      width: 120px; 
      height: 120px; 
      object-fit: cover; 
      border-radius: 5px; 
    }
  .product-details { 
      flex-grow: 1; 
      padding-left: 15px; 
    }
  .product-details h3 { 
      font-size: 18px; 
      font-weight: bold; 
    }
  .product-details p {
      font-size: 14px; 
      color: #555; 
      margin-bottom: 8px;
    }
  .edit-btn { 
      background-color: #f65353ff; 
      color: white; padding: 10px 15px; 
      cursor: pointer; 
      border: none; 
      font-size: 1rem; 
      border-radius: 5px; 
      transition: background-color 0.3s;
     }
  .edit-btn:hover { 
      background-color: #ff0000ff; 
    }
  .modal { 
      display: none; 
      position: fixed; 
      top: 0; 
      left: 0; 
      width: 100%; 
      height: 100%; 
      background-color: rgba(0, 0, 0, 0.5); 
      justify-content: center; 
      align-items: center; 
    }

  .modal-content { 
      background-color: white; 
      padding: 20px; 
      border-radius: 5px;
      max-height: 90vh;
      overflow-y: auto;
      width: 800px; 
      position: relative;
     }

  .modal-content input{ 
      width: 100%; 
      padding: 8px; 
      margin-bottom: 10px; 
      border-radius: 5px; 
      border: 1px solid #ccc;
    }

  .modal-content textarea { 
      width: 100%; 
      padding: 8px; 
      margin-bottom: 10px; 
      border-radius: 5px; 
      border: 1px solid #ccc; 
    }

  .close-btn { 
      position: absolute; 
      top: 5px; 
      right: 5px; 
      font-size: 20px; 
      cursor: pointer; 
    }
  .modal-content button { 
      padding: 10px 15px;
      background-color: #3498db;
      color: white; 
      border: none;
      border-radius: 5px; 
      cursor: pointer;
     }

  .modal-content button:hover {
     background-color: #2980b9;
     }

  .success-popup {
      display: none; 
      position: fixed; 
      top: 50%; 
      left: 50%; 
      transform: translate(-50%, -50%); 
      background-color: green; 
      color: white; 
      padding: 20px; 
      border-radius: 5px; 
      font-size: 1.2rem;
    }

  .order-img {
      width: 120px;
      height: 120px;
      object-fit: contain; /* or use cover if you want edge-to-edge fill */
      border-radius: 5px;
      background-color: #fff; /* Optional: consistent background */
      border: 1px solid #ccc;
      padding: 5px; /* Prevents image from touching border if it has transparency */
    }

  .stock-info {
      display: flex;
      align-items: center;
      gap: 15px;
    }

  .edit-current-snapshot {
      background: #f4f8fb;
      border: 1px solid #c5d4e0;
      border-radius: 8px;
      padding: 14px 16px;
      margin-bottom: 18px;
      font-size: 14px;
      color: #222;
    }
  .edit-current-snapshot .edit-snapshot-title {
      font-size: 15px;
      font-weight: 700;
      margin: 0 0 6px 0;
      color: #1a3a52;
    }
  .edit-current-snapshot .edit-snapshot-hint {
      font-size: 12px;
      color: #555;
      margin: 0 0 12px 0;
      line-height: 1.4;
    }
  .edit-snapshot-grid {
      display: flex;
      gap: 16px;
      align-items: flex-start;
      flex-wrap: wrap;
    }
  .edit-snapshot-img {
      width: 100px;
      height: 100px;
      object-fit: contain;
      background: #fff;
      border: 1px solid #ccc;
      border-radius: 6px;
      padding: 4px;
      flex-shrink: 0;
    }
  .edit-snapshot-details {
      flex: 1;
      min-width: 220px;
    }
  .edit-snapshot-details p {
      margin: 0 0 8px 0;
      line-height: 1.45;
    }
  .edit-snapshot-details strong {
      display: inline-block;
      min-width: 7.5rem;
      color: #333;
      font-weight: 600;
    }
  .edit-snapshot-desc {
      max-height: 100px;
      overflow-y: auto;
      white-space: pre-wrap;
      word-break: break-word;
      background: #fff;
      border: 1px solid #dde6ee;
      border-radius: 4px;
      padding: 8px 10px;
      margin-top: 4px;
      font-size: 13px;
    }
  .edit-form-section-title {
      font-size: 14px;
      font-weight: 600;
      margin: 16px 0 10px 0;
      color: #1a3a52;
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
    <a href="edit-item.php" class="nav-link active">EDIT ITEM</a>
    <a href="delete-item.php" class="nav-link">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-container">
  <h2 class="section-title">Edit Items</h2>
  <?php if (!empty($errorMessage)): ?>
    <p style="color:#b00020;text-align:center;font-weight:bold;margin-bottom:1rem;"><?php echo htmlspecialchars($errorMessage); ?></p>
  <?php endif; ?>
  <?php while ($product = mysqli_fetch_assoc($productResult)): ?>
  <div class="stock-card">
    <div class="stock-info">
      <img src="images/<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="order-img">
      <div class="product-details">
        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
        <p><strong>Product ID:</strong> <?php echo (int) $product['product_id']; ?></p>
        <p><strong>Brand:</strong> <?php echo htmlspecialchars($product['brand_name'] ?? ''); ?></p>
        <p><strong>Color:</strong> <?php echo htmlspecialchars($product['color'] ?? ''); ?></p>
        <p><strong>Price:</strong> <?php echo htmlspecialchars($product['price']); ?> <?php echo htmlspecialchars($product['currency_code']); ?></p>
        <p><strong>Description:</strong> <?php echo htmlspecialchars($product['description']); ?></p>
        <?php
        $ps = $sizesByProduct[$product['product_id']] ?? [];
        if (!empty($ps)) {
            $bits = array_map(function ($r) {
                return $r['size'] . ' (stock ' . (int) $r['stock'] . ')';
            }, $ps);
            echo '<p><strong>Sizes:</strong> ' . htmlspecialchars(implode(', ', $bits)) . '</p>';
        } else {
            echo '<p style="color:#666;"><strong>Sizes:</strong> none — add sizes in Edit, or use <a href="admin-update-stocks.php">Update Stock</a> after sizes exist.</p>';
        }
        ?>
        <button class="edit-btn" onclick="openModal(<?php echo $product['product_id']; ?>)">Edit</button>
      </div>
    </div>
  </div>
  <?php endwhile; ?>
</div>

<!-- Modal -->
<div id="editModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeModal()">×</span>
    <h3>Edit Product</h3>
    <div id="editCurrentSnapshot" class="edit-current-snapshot" aria-live="polite">
      <p class="edit-snapshot-title">Current information on file</p>
      <p class="edit-snapshot-hint">Reference only — shows what is saved now. Edit fields below, then save.</p>
      <div class="edit-snapshot-grid">
        <img id="snapshotImg" class="edit-snapshot-img" src="" alt="Product image">
        <div class="edit-snapshot-details">
          <p><strong>Product ID</strong> <span id="snapProductId"></span></p>
          <p><strong>Brand</strong> <span id="snapBrand"></span></p>
          <p><strong>Name</strong> <span id="snapName"></span></p>
          <p><strong>Color</strong> <span id="snapColor"></span></p>
          <p><strong>Price</strong> <span id="snapPrice"></span> <span id="snapCurrency"></span></p>
          <p><strong>Description</strong></p>
          <div id="snapDescription" class="edit-snapshot-desc"></div>
          <p style="margin-top:10px;"><strong>Sizes &amp; stock</strong> <span id="snapSizes"></span></p>
          <p style="margin-top:8px;font-size:12px;color:#555;"><strong>Image file</strong> <span id="snapImageFile"></span></p>
        </div>
      </div>
    </div>
    <p class="edit-form-section-title">Edit values</p>
    <form method="POST">
      <?php echo csrf_field(); ?>
      <input type="hidden" id="product_id" name="product_id">
      <label for="name">Product Name</label>
      <input type="text" id="name" name="name" required>
      <label for="price">Price</label>
      <input type="number" id="price" name="price" step="0.01" min="1" required>
      <small id="edit-price-error" class="field-error" style="display:none;">Price must be at least 1.</small>
      <label for="description">Description</label>
      <textarea id="description" name="description" required></textarea>
      <label for="color">Color</label>
      <input type="text" id="color" name="color" maxlength="50" placeholder="e.g. Black, White">
      <label for="add_sizes">Add sizes (optional)</label>
      <input type="text" id="add_sizes" name="add_sizes" placeholder="Comma-separated, e.g. 7, 8, 8.5">
      <label for="add_sizes_stock">Initial stock for new sizes (each)</label>
      <input type="number" id="add_sizes_stock" name="add_sizes_stock" min="0" max="1000" step="1" value="0">
      <p style="font-size:13px;color:#666;margin-top:6px;">To change stock for existing sizes, use <a href="admin-update-stocks.php">Update Stock</a>.</p>
      <button type="submit">Save Changes</button>
    </form>
  </div>
</div>

<!-- Success Popup -->
<div id="successMessage" class="success-popup">
  <p>Item Edited Successfully</p>
</div>

<script>
  const productDetails = <?php echo json_encode($productsForJS); ?>;
  const sizesByProduct = <?php echo json_encode($sizesByProduct); ?>;

  function openModal(productId) {
    const modal = document.getElementById("editModal");
    const product = productDetails.find(p => parseInt(p.product_id) === productId);
    if (product) {
      document.getElementById("product_id").value = product.product_id;
      document.getElementById("name").value = product.name;
      document.getElementById("price").value = product.price;
      document.getElementById("description").value = product.description;
      document.getElementById("color").value = product.color || "";
      document.getElementById("add_sizes").value = "";
      document.getElementById("add_sizes_stock").value = "0";

      var sizes = sizesByProduct[product.product_id] || [];
      var sizesText = sizes.length
        ? sizes.map(function (r) { return r.size + " (stock " + r.stock + ")"; }).join(", ")
        : "None — use “Add sizes” below to create size rows.";

      var imgEl = document.getElementById("snapshotImg");
      if (imgEl) {
        if (product.image_url) {
          imgEl.src = "images/" + product.image_url;
          imgEl.alt = product.name || "Product";
          imgEl.style.display = "";
        } else {
          imgEl.removeAttribute("src");
          imgEl.alt = "";
          imgEl.style.display = "none";
        }
      }

      function setText(id, text) {
        var n = document.getElementById(id);
        if (n) n.textContent = text != null ? String(text) : "";
      }
      setText("snapProductId", product.product_id);
      setText("snapBrand", product.brand_name || "—");
      setText("snapName", product.name || "");
      setText("snapColor", product.color || "—");
      setText("snapPrice", product.price != null ? product.price : "");
      setText("snapCurrency", product.currency_code || "");
      var descEl = document.getElementById("snapDescription");
      if (descEl) {
        descEl.textContent = product.description || "—";
      }
      setText("snapSizes", sizesText);
      setText("snapImageFile", product.image_url || "—");

      modal.style.display = "flex";
    }
  }

  function closeModal() {
    document.getElementById("editModal").style.display = "none";
  }

  window.onclick = function(event) {
    if (event.target == document.getElementById("editModal")) {
      closeModal();
    }
  };

  // Inline validation for price in edit modal
  (function () {
    var priceInput = document.getElementById('price');
    var priceError = document.getElementById('edit-price-error');
    if (!priceInput || !priceError) return;

    function validatePriceField() {
      var val = parseFloat(priceInput.value);
      if (!isNaN(val) && val < 1) {
        priceError.style.display = 'block';
        priceInput.setCustomValidity('Price must be at least 1.');
      } else {
        priceError.style.display = 'none';
        priceInput.setCustomValidity('');
      }
    }

    priceInput.addEventListener('input', validatePriceField);
    priceInput.addEventListener('blur', validatePriceField);
  })();

  <?php if (isset($_GET['updated'])): ?>
    setTimeout(function() {
      document.getElementById('successMessage').style.display = 'block';
      setTimeout(function() {
        document.getElementById('successMessage').style.display = 'none';
      }, 2000);
    }, 500);
  <?php endif; ?>
</script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
