<?php
    require_once 'includes/db.php';
    include 'currency_util.php';
    require_once 'includes/session_init.php';
    require_once 'includes/csrf.php';
    require_once 'includes/no_cache_headers.php';

    // Check if the user is logged in and has the correct role
    if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Customer") {
        header("Location: login.php"); // Redirect to login page if not a customer
        exit();
    }
    $customer_name = $_SESSION["fullname"];
    $customer_email = $_SESSION["email"];
    $customer_role = $_SESSION["role"];

    if (!isset($_GET['product_id']) || !ctype_digit($_GET['product_id'])) {
        die('<p style="padding:20px;">Invalid product ID.</p>');
    }
    $product_id = (int)$_GET['product_id'];

    // Fetch product
    $sql = "SELECT p.product_id,
            p.name AS product_name,
            p.price,
            p.color,
            p.description,
            p.image_url,
            p.currency_id,
            b.name AS brand_name
            FROM product p
            JOIN brand b ON p.brand_id = b.brand_id
            WHERE p.product_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (!$result || mysqli_num_rows($result) === 0) {
        die('<p style="padding:20px;">Product not found.</p>');
    }
    $product = mysqli_fetch_assoc($result);
    mysqli_free_result($result);
    mysqli_stmt_close($stmt);

    // Fetch sizes
    $size_sql = "SELECT size, stock
                 FROM product_size
                 WHERE product_id = ?
                 ORDER BY size";
    $size_stmt = mysqli_prepare($conn, $size_sql);
    mysqli_stmt_bind_param($size_stmt, 'i', $product_id);
    mysqli_stmt_execute($size_stmt);
    $size_result = mysqli_stmt_get_result($size_stmt);
    $sizes = [];
    while ($row = mysqli_fetch_assoc($size_result)) {
        $sizes[] = $row; // ['size'=>5.0, 'stock'=>10]
    }
    mysqli_free_result($size_result);
    mysqli_stmt_close($size_stmt);

    // Helper: clean size display (drop .0)
    function format_size($s) {
        return (fmod($s, 1.0) == 0.0) ? (int)$s : $s;
    }
    // Check if the product is in favorites
    $fav_sql = "SELECT 1 FROM favorites WHERE user_id = (SELECT user_id FROM user WHERE email = ?) AND product_id = ?";
    $fav_stmt = mysqli_prepare($conn, $fav_sql);
    mysqli_stmt_bind_param($fav_stmt, 'si', $customer_email, $product_id);
    mysqli_stmt_execute($fav_stmt);
    $fav_result = mysqli_stmt_get_result($fav_stmt);
    $is_favorited = mysqli_num_rows($fav_result) > 0;
    mysqli_stmt_close($fav_stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Product Details | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/productstyles.css" />
  <link rel="stylesheet" href="css/userprofilestyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
  <!-- Header -->
  <header class="header">
    <div class="header-container">
      <div class="header-spacer"></div>
      <h1 class="logo">SOLE SOURCE</h1>
      <div class="header-icons">
          <select class="currency-select" title="Choose currency" onchange="changeCurrency(this.value)">
              <option value="PHP" <?php echo (isset($_SESSION['currency']) ? $_SESSION['currency'] : 'PHP') === 'PHP' ? 'selected' : ''; ?>>🇵🇭 PHP</option>
              <option value="USD" <?php echo (isset($_SESSION['currency']) ? $_SESSION['currency'] : '') === 'USD' ? 'selected' : ''; ?>>🇺🇸 USD</option>
              <option value="KRW" <?php echo (isset($_SESSION['currency']) ? $_SESSION['currency'] : '') === 'KRW' ? 'selected' : ''; ?>>🇰🇷 KRW</option>
          </select>
        <div class="user-profile-container">
          <button class="icon-button" onclick="window.location.href='userprofilec.php'">
            <i class="fas fa-user"></i>
          </button>
          <div class="profile-hover-info">
        <div class="user-name"><?php echo htmlspecialchars($customer_name); ?></div>
        <div class="user-role"><?php echo htmlspecialchars($customer_role); ?></div>
      </div>

        </div>
      </div>
    </div>

    <!-- Navigation -->
    <nav class="navigation">
      <a href="homepage.php" class="nav-link">SHOES</a>
      <a href="brands.php" class="nav-link">BRANDS</a>
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Product Details -->
  <main class="product-page">
      <div class="product-image-section">
          <img
                  src="images/<?php echo htmlspecialchars($product['image_url']); ?>"
                  alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                  class="product-image-medium">
      </div>
      <div class="product-info-section">
          <h2 class="product-title"><?php echo htmlspecialchars($product['product_name']); ?></h2>
          <p class="product-price">
              <?php echo convertCurrency($product['price'], $conn); ?>
          </p>
          <p class="product-description">
              <?php echo nl2br(htmlspecialchars($product['description'])); ?>
          </p>
          <p><strong>Brand:</strong> <?php echo htmlspecialchars($product['brand_name']); ?></p>
          <p><strong>Color:</strong> <?php echo htmlspecialchars($product['color']); ?></p>

          <div class="product-sizes">
              <label>Available Sizes:</label>
              <form method="POST" action="cart-add.php" class="size-options">
                  <?php echo csrf_field(); ?>
                  <?php if (empty($sizes)): ?>
                      <p>Out of stock.</p>
                  <?php else: ?>
                      <?php foreach ($sizes as $srow): ?>
                          <?php
                          $s = format_size((float)$srow['size']);
                          $disabled = ($srow['stock'] <= 0) ? 'disabled' : '';
                          ?>
                          <label class="size-btn-wrapper">
                              <input type="radio" name="size" value="<?php echo htmlspecialchars($srow['size']); ?>" <?php echo $disabled; ?> required>
                              <span class="size-btn <?php echo $disabled ? 'disabled' : ''; ?>">
                                US <?php echo htmlspecialchars($s); ?>
                                  <?php if ($srow['stock'] <= 0): ?> (OOS)<?php endif; ?>
                              </span>
                          </label>
                      <?php endforeach; ?>
                  <?php endif; ?>

                  <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                  <input type="hidden" name="quantity" value="1">
                  <div class="product-actions">
                      <button type="submit" class="add-to-cart-btn" <?php echo empty($sizes) ? 'disabled' : ''; ?>>
                          Add to Cart
                      </button>
                      <button type="button" class="fav-btn" title="Add to Favorites">
                          <i class="<?php echo $is_favorited ? 'fas' : 'far'; ?> fa-heart" style="<?php echo $is_favorited ? 'color:red;' : ''; ?>"></i>
                      </button>
                  </div>
              </form>
          </div>
      </div>
  </main>
  <script>
      function changeCurrency(code) {
          const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
          fetch('set_currency.php', {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: 'currency=' + encodeURIComponent(code) + '&csrf_token=' + encodeURIComponent(token)
          })
              .then(res => res.text())
              .then(response => {
                  if (response === 'success') {
                      location.reload(); // Refresh page to apply new currency
                  } else {
                      alert('Invalid currency selected');
                  }
              });
      }
      document.querySelectorAll('.heart-button, .fav-btn').forEach(button => {
          button.addEventListener('click', function () {
              const productId = this.closest('.product-card, form').querySelector('input[name="product_id"]').value;
              const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
              fetch('add_to_favorites.php', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                  body: 'product_id=' + encodeURIComponent(productId) + '&csrf_token=' + encodeURIComponent(token)
              })
                  .then(response => response.json())
                  .then(data => {
                      if (data.status === 'added') {
                          this.querySelector('i').classList.replace('far', 'fas'); // empty to solid heart
                      } else if (data.status === 'removed') {
                          this.querySelector('i').classList.replace('fas', 'far'); // solid to empty
                      }
                  });
          });
      });
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>