<?php 
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error($conn));
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

// Handle product update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    if (!validate_csrf()) {
        $errorMessage = "Security check failed. Please try again.";
    } else {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $name = sanitize_string($_POST['name'] ?? '', 100);
    $price_raw = $_POST['price'] ?? '';
    $description = sanitize_string($_POST['description'] ?? '', 255);

    // Validate price: 1 to 999999.99
    $price = validate_price($price_raw, 1, 999999.99);
    if ($price === false) {
        $errorMessage = "Price must be between 1 and 999999.99.";
    } elseif ($product_id < 1) {
        $errorMessage = "Invalid product.";
    } else {
        $stmt_update = mysqli_prepare($conn, "
            UPDATE product SET name = ?, price = ?, description = ? WHERE product_id = ?
        ");
        mysqli_stmt_bind_param($stmt_update, "sdsi", $name, $price, $description, $product_id);
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
            header("Location: edit-item.php?updated=1");
            exit();
        } else {
            $errorMessage = "Error updating product: " . mysqli_error($conn);
            mysqli_stmt_close($stmt_update);
        }
    }
    }
}

// Fetch all products for display
$productQuery = "SELECT p.*, c.code AS currency_code FROM product p
                 JOIN currency c ON p.currency_id = c.currency_id";
$productResult = mysqli_query($conn, $productQuery);

// Fetch again for JavaScript
$productResultForJS = mysqli_query($conn, $productQuery);
$productsForJS = [];
while ($row = mysqli_fetch_assoc($productResultForJS)) {
    $productsForJS[] = $row;
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
    border: 1px solid #ccc; m
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
      height: 300px;
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
  <?php while ($product = mysqli_fetch_assoc($productResult)): ?>
  <div class="stock-card">
    <div class="stock-info">
      <img src="images/<?php echo htmlspecialchars($product['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="order-img">
      <div class="product-details">
        <h3><?php echo htmlspecialchars($product['name']); ?></h3>
        <p><strong>Price:</strong> <?php echo htmlspecialchars($product['price']); ?> <?php echo htmlspecialchars($product['currency_code']); ?></p>
        <p><strong>Description:</strong> <?php echo htmlspecialchars($product['description']); ?></p>
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

  function openModal(productId) {
    const modal = document.getElementById("editModal");
    const product = productDetails.find(p => parseInt(p.product_id) === productId);
    if (product) {
      document.getElementById("product_id").value = product.product_id;
      document.getElementById("name").value = product.name;
      document.getElementById("price").value = product.price;
      document.getElementById("description").value = product.description;
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
