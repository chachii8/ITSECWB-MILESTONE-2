<?php
    $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
    mysqli_select_db($conn, "sole_source");
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Brands | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/brandstyles.css" /> 
  <link rel="stylesheet" href="css/userprofilestyles.css"> 
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
</head>
<body>
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

    <nav class="navigation">
      <a href="homepage.php" class="nav-link">SHOES</a>
      <a href="brands.php" class="nav-link active">BRANDS</a>
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <main class="product-main">
      <?php
      // Fetch all brands
      $brandQuery = "SELECT * FROM brand";
      $brandResult = mysqli_query($conn, $brandQuery);

      while ($brand = mysqli_fetch_assoc($brandResult)):
          $brandId = $brand['brand_id'];
          $brandName = $brand['name'];

          // Brand Description (you can map this better)
          $descriptions = [
              'Nike' => 'Nike, founded in 1964, is an American multinational corporation that designs, develops, manufactures, and markets and sells clothes, footwear, accessories, and equipment globally. Well known for its "Just Do It" slogan and trademark Swoosh design, creates famous models such as Air Force, Air Max, Jordan, React, Presto, Cortez, and Zoom, as well as breakthrough technologies such as Flyknit, Dri-Fit, and Air.',
              'Adidas' => 'Adidas Originals is adidas\'s legacy line. The brand focuses in lifestyle sneakers and streetwear gear that flawlessly combines fashion and function.',
              'On Cloud' => 'On was founded in the Swiss Alps in 2010 with the goal of revolutionizing the jogging experience – running on clouds.',
              'Asics' => 'Since its beginnings in 1949, ASICS has been committed to developing the world\'s youth via sports in order to benefit society.',
              'New Balance' => 'New Balance made its international debut in 1906. Originally known for arch support, it grew into a global sneaker icon.'
          ];
          $brandDesc = $descriptions[$brandName] ?? '';

          echo "<section class='brand-section'>
            <h2 class='product-title'>{$brandName}</h2>
            <p class='brand-description'>{$brandDesc}</p>
            <div class='product-grid'>";

          // Fetch products for this brand
          $productQuery = "SELECT * FROM product WHERE brand_id = ?";
          $stmt = $conn->prepare($productQuery);
          $stmt->bind_param("i", $brandId);
          $stmt->execute();
          $products = $stmt->get_result();

          while ($product = $products->fetch_assoc()):
              $price = convertCurrency($product['price'], $conn);
              $image = 'images/' . $product['image_url'];
              $name = $product['name'];

              $isFavorited = in_array($product['product_id'], $favorited_products);

              echo "<div class='product-card'>
                <div class='product-image'>
                  <img src='{$image}' alt='{$name}'>
                    <input type='hidden' name='product_id' value='{$product['product_id']}'>
                    <button class='heart-button' title='Add to Favorites'>
                      <i class='" . ($isFavorited ? "fas favorited" : "far") . " fa-heart'></i>
                    </button>
                </div>
                <div class='product-info'>
                  <div class='product-brand'>{$brandName}</div>
                  <h3 class='product-name'>
                    <a href='product.php?product_id={$product['product_id']}'>" . htmlspecialchars($product['name']) . "</a>
                  </h3>
                  <div class='product-price'>{$price}</div>
                </div>
              </div>";
          endwhile;

          echo "</div></section>";
      endwhile;
      ?>
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
      document.querySelectorAll('.heart-button').forEach(button => {
          button.addEventListener('click', function () {
              const productId = this.closest('.product-card, form').querySelector('input[name="product_id"]').value;
              const icon = this.querySelector('i');

              const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
              fetch('add_to_favorites.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body: 'product_id=' + encodeURIComponent(productId) + '&csrf_token=' + encodeURIComponent(token)
              })
                  .then(response => response.json())
                  .then(data => {
                      if (data.status === 'added') {
                          icon.classList.replace('far', 'fas');
                          icon.classList.add('favorited');
                      } else if (data.status === 'removed') {
                          icon.classList.replace('fas', 'far');
                          icon.classList.remove('favorited');
                      }
                  });
          });
      });
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>
