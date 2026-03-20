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
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Confirmation | Sole Source</title>
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
      <a href="homepage.php" class="nav-link active">SHOES</a>
      <a href="brands.php" class="nav-link">BRANDS</a>
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Confirmation Content -->
  <main>
    <div class="confirmation-container">
      <h2>Thank you for your purchase!</h2>
        <?php
            $order_id = isset($_SESSION['last_order_id']) ? $_SESSION['last_order_id'] : null;
            $total = isset($_SESSION['last_order_total_php']) ? $_SESSION['last_order_total_php'] : null;
            $shipping_address = isset($_SESSION['last_order_shipping_address']) ? $_SESSION['last_order_shipping_address'] : null;

            // Optionally clear it after showing once
            unset($_SESSION['last_order_id']);
            unset($_SESSION['last_order_total']);
            unset($_SESSION['last_order_shipping_address']);
        ?>

        <?php if ($order_id && $total): ?>
            <p>Your order <strong>#<?php echo (int)$order_id; ?></strong> has been placed successfully.</p>
            <p>Total Paid: <strong><?php echo convertCurrency($total, $conn); ?></strong></p>
            <p><strong>Shipping Address:</strong> <?php echo htmlspecialchars($shipping_address); ?></p>
        <?php else: ?>
            <p>Your order has been successfully placed. A confirmation email has been sent to your inbox.</p>
        <?php endif; ?>
        <a href="homepage.php" class="return-btn">Return Home</a>
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
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>