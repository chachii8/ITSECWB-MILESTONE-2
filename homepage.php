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

    // Handle brand filter
    $selected_brands = isset($_GET['brands']) ? $_GET['brands'] : [];

    // Default sorting option - whitelist prevents SQL injection from $_GET['sort']
    $allowed_sorts = [
        'price_low_high' => 'p.price ASC',
        'price_high_low' => 'p.price DESC',
        'brand_a_z' => 'b.name ASC',
    ];
    $sort_option = isset($_GET['sort']) ? $_GET['sort'] : 'price_low_high';
    $order_by = $allowed_sorts[$sort_option] ?? 'p.product_id DESC';

    // Build the SQL query for the selected brands filter (prepared)
    $allowed_brands = ['Nike', 'Adidas', 'On Cloud', 'Asics', 'New Balance'];
    $selected_brands = array_values(array_intersect((array)$selected_brands, $allowed_brands));
    $brand_filter_sql = "";
    $brand_params = [];
    $brand_param_types = "";
    if (!empty($selected_brands)) {
        $placeholders = implode(',', array_fill(0, count($selected_brands), '?'));
        $brand_filter_sql = " AND b.name IN ($placeholders)";
        $brand_params = $selected_brands;
        $brand_param_types = str_repeat('s', count($selected_brands));
    }

    // Fetch all products from the database, including stock information and applying brand filter
    $product_query = "SELECT 
                        p.product_id, 
                        p.name, 
                        p.price, 
                        p.description, 
                        p.image_url, 
                        b.name AS brand, 
                        SUM(ps.stock) AS total_stock
                      FROM product p
                      JOIN brand b ON p.brand_id = b.brand_id
                      JOIN product_size ps ON p.product_id = ps.product_id
                      WHERE 1=1" . $brand_filter_sql . "
                      GROUP BY p.product_id
                      ORDER BY $order_by";
    $stmt_products = mysqli_prepare($conn, $product_query);
    if (!empty($brand_params)) {
        $bind_params = [$brand_param_types];
        foreach ($brand_params as $idx => $value) {
            $bind_params[] = &$brand_params[$idx];
        }
        call_user_func_array([$stmt_products, 'bind_param'], $bind_params);
    }
    mysqli_stmt_execute($stmt_products);
    $product_result = mysqli_stmt_get_result($stmt_products);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Home | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/userprofilestyles.css">
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
          <button class="icon-button" id="profileBtn" onclick="window.location.href='userprofilec.php'">
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
      <a href="homepage.php" class="nav-link active">SHOES</a>
      <a href="brands.php" class="nav-link">BRANDS</a>
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Main Content -->
  <div class="main-content">
    <!-- Brand Sidebar -->
    <aside class="brand-sidebar">
      <div class="sidebar-section">
        <button class="sidebar-title">
          Brands <span class="arrow">▼</span>
        </button>
        <div class="brand-filters" id="brandFilterSection">
          <form method="GET" action="homepage.php">
            <label class="brand-filter">
              <input type="checkbox" name="brands[]" value="Nike" <?php echo in_array('Nike', $selected_brands) ? 'checked' : ''; ?>> Nike
            </label>
            <label class="brand-filter">
              <input type="checkbox" name="brands[]" value="Adidas" <?php echo in_array('Adidas', $selected_brands) ? 'checked' : ''; ?>> Adidas
            </label>
            <label class="brand-filter">
              <input type="checkbox" name="brands[]" value="On Cloud" <?php echo in_array('On', $selected_brands) ? 'checked' : ''; ?>> On
            </label>
            <label class="brand-filter">
              <input type="checkbox" name="brands[]" value="Asics" <?php echo in_array('Asics', $selected_brands) ? 'checked' : ''; ?>> Asics
            </label>
            <label class="brand-filter">
              <input type="checkbox" name="brands[]" value="New Balance" <?php echo in_array('New Balance', $selected_brands) ? 'checked' : ''; ?>> New Balance
            </label>
            <button type="submit" class="filter-btn">Filter</button>
          </form>
        </div>
      </div>      
    </aside>

    <!-- Sorting and Product Grid -->
    <main class="product-main">
      <div class="product-header">
        <h2 class="product-title">Shoes</h2>
        <form method="GET" action="homepage.php">
          <select name="sort" onchange="this.form.submit()">
            <option value="price_low_high" <?php echo $sort_option == 'price_low_high' ? 'selected' : ''; ?>>Price: Low to High</option>
            <option value="price_high_low" <?php echo $sort_option == 'price_high_low' ? 'selected' : ''; ?>>Price: High to Low</option>
            <option value="brand_a_z" <?php echo $sort_option == 'brand_a_z' ? 'selected' : ''; ?>>Brand A-Z</option>
          </select>
        </form>
      </div>

      <div class="product-grid">
        <!-- Fetch products dynamically from the database -->
        <?php
            $favorited_products = [];
            if (isset($_SESSION['user_id'])) {
                $uid = $_SESSION['user_id'];
                $stmt_fav = mysqli_prepare($conn, "SELECT product_id FROM favorites WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt_fav, "i", $uid);
                mysqli_stmt_execute($stmt_fav);
                $fav_result = mysqli_stmt_get_result($stmt_fav);
                while ($row = mysqli_fetch_assoc($fav_result)) {
                    $favorited_products[] = $row['product_id'];
                }
                mysqli_stmt_close($stmt_fav);
            }
            while ($product = mysqli_fetch_assoc($product_result)):
        ?>
          <div class="product-card">
              <div class="product-image">
                  <img src="images/<?php echo htmlspecialchars($product['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?>" />
                  <input type="hidden" name="product_id" value="<?php echo (int)$product['product_id']; ?>">
                  <button class="heart-button" title="Add to Favorites">
                      <i class="<?php echo in_array($product['product_id'], $favorited_products) ? 'fas' : 'far'; ?> fa-heart"></i>
                  </button>
              </div>
            <div class="product-info">
              <div class="product-brand"><?php echo htmlspecialchars($product['brand'], ENT_QUOTES, 'UTF-8'); ?></div>
              <h3 class="product-name">
                <a href="product.php?product_id=<?php echo (int)$product['product_id']; ?>"><?php echo htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8'); ?></a>
              </h3>
                <div class="product-price">
                    <?php echo convertCurrency($product['price'], $conn); ?>
                </div>
              <?php if ($product['total_stock'] == 0): ?>
                <div class="product-status">Not Available</div>
              <?php else: ?>
                  <div class="product-status">In Stock: <?php echo (int)$product['total_stock']; ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
        <?php if (isset($stmt_products)) { mysqli_stmt_close($stmt_products); } ?>
      </div>
    </main>
  </div>
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
