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
    $customer_id = $_SESSION["user_id"];
    $selected_currency = isset($_SESSION['selected_currency']) ? $_SESSION['selected_currency'] : 'PHP';

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Orders | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/cartstyles.css" />
  <link rel="stylesheet" href="css/userprofilestyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
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

        <nav class="navigation">
            <a href="homepage.php" class="nav-link">SHOES</a>
            <a href="brands.php" class="nav-link">BRANDS</a>
            <a href="cart.php" class="nav-link">CART</a>
            <a href="orders.php" class="nav-link active">ORDERS</a>
        </nav>
    </header>

    <main class="orders-container">
        <h2 class="orders-title">My Orders</h2>
        <section class="orders-list">
            <?php
            // Fetch orders of the logged-in customer
            if ($customer_id !== null) {
                $stmt_orders = mysqli_prepare($conn, "SELECT * FROM `order` WHERE user_id = ? ORDER BY order_date DESC");
                mysqli_stmt_bind_param($stmt_orders, "i", $customer_id);
                mysqli_stmt_execute($stmt_orders);
                $order_result = mysqli_stmt_get_result($stmt_orders);
            } else {
                echo "<p style='text-align:center;'>User session not found.</p>";
            }

            if (!empty($order_result) && mysqli_num_rows($order_result) > 0) {
                $stmt_details = mysqli_prepare($conn, "
                    SELECT od.quantity, od.product_price, p.name AS product_name, p.image_url, b.name AS brand_name
                    FROM order_details od
                    JOIN product p ON od.product_id = p.product_id
                    JOIN brand b ON p.brand_id = b.brand_id
                    WHERE od.order_id = ?
                ");
                while ($order = mysqli_fetch_assoc($order_result)) {
                    $order_id = $order['order_id'];
                    $order_date = date("F j, Y", strtotime($order['order_date']));
                    $status = $order['order_status'];
                    $total_php = $order['total_price'];
                    $total_converted = convertCurrency($total_php, $conn);

                    echo "<div class='order-card'>";
                    echo "<div class='order-header'>
                          <div><strong>Order #</strong> {$order_id}</div>
                          <div><strong>Status:</strong> <span class='order-status ".strtolower($status)."'>".htmlspecialchars($status)."</span></div>
                        </div>";

                    // Fetch products for each order
                    mysqli_stmt_bind_param($stmt_details, "i", $order_id);
                    mysqli_stmt_execute($stmt_details);
                    $details_result = mysqli_stmt_get_result($stmt_details);

                    while ($item = mysqli_fetch_assoc($details_result)) {
                        echo '<div class="order-details">';
                        echo '<img src="images/' . htmlspecialchars($item['image_url']) . '" alt="' . htmlspecialchars($item['product_name']) . '">';
                        echo '<div>';
                        echo '<h3 class="order-product-name">' . htmlspecialchars($item['product_name']) . '</h3>';
                        echo '<p class="order-brand">Brand: ' . htmlspecialchars($item['brand_name']) . '</p>';
                        echo '<p class="order-info">Qty: ' . $item['quantity'] . '</p>';
                        echo '<p class="order-price">' . convertCurrency($item['product_price'], $conn) . '</p>';
                        echo '</div>';
                        echo '</div>';
                    }


                    echo "<div class='order-footer'>
                          <p><strong>Ordered On:</strong> {$order_date}</p>
                          <p><strong>Total:</strong> {$total_converted}</p>
                        </div>
                        </div>";
                }
                mysqli_stmt_close($stmt_details);
            } else {
                echo "<p style='text-align:center; font-size:1.2rem;'>You have no orders yet.</p>";
            }
            if (isset($stmt_orders)) {
                mysqli_stmt_close($stmt_orders);
            }
            ?>
        </section>
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
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>