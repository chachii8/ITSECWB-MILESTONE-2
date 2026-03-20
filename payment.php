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
    $user_id = $_SESSION["user_id"];

    // Validate cart: no item may exceed available stock
    $check_stmt = mysqli_prepare($conn, "
        SELECT c.product_id, c.size, c.quantity, ps.stock
        FROM cart c
        LEFT JOIN product_size ps ON c.product_id = ps.product_id AND c.size = ps.size
        WHERE c.user_id = ?
    ");
    mysqli_stmt_bind_param($check_stmt, "i", $user_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    $stock_ok = true;
    while ($r = mysqli_fetch_assoc($check_result)) {
        if ($r['stock'] === null || (int)$r['quantity'] > (int)$r['stock']) {
            $stock_ok = false;
            break;
        }
    }
    mysqli_stmt_close($check_stmt);
    if (!$stock_ok) {
        $_SESSION['order_error'] = 'One or more items exceed available stock. Please update your cart.';
        header("Location: cart.php");
        exit();
    }

    // Recompute subtotal from cart (always fresh, never trust stale session)
    $stmt_cart = mysqli_prepare($conn, "
        SELECT p.price, c.quantity FROM cart c
        JOIN product p ON c.product_id = p.product_id
        WHERE c.user_id = ?
    ");
    mysqli_stmt_bind_param($stmt_cart, "i", $user_id);
    mysqli_stmt_execute($stmt_cart);
    $cart_result = mysqli_stmt_get_result($stmt_cart);
    $subtotal_php = 0;
    while ($row = mysqli_fetch_assoc($cart_result)) {
        $subtotal_php += (float)$row['price'] * (int)$row['quantity'];
    }
    mysqli_stmt_close($stmt_cart);
    $_SESSION['subtotal_php'] = $subtotal_php;

    $shipping_fee = 250;
    $total_php = $subtotal_php + $shipping_fee;
    $_SESSION['total_php'] = $total_php;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Payment | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/paymentstyles.css">
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
      <a href="homepage.php" class="nav-link">SHOES</a>
      <a href="brands.php" class="nav-link">BRANDS</a>
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Payment Content -->
  <main class="payment-page">
    <form action="process_payment.php" method="post" class="payment-form">
      <?php echo csrf_field(); ?>
      <h2>Payment Details</h2>

      <!-- Payment Methods -->
      <div class="payment-methods">
        <label><input type="radio" name="payment_method" value="credit" checked> Credit Card</label>
        <label><input type="radio" name="payment_method" value="cod"> Cash on Delivery</label>
      </div>

      <!-- Credit Card Fields -->
      <div class="payment-fields" id="credit-fields">
        <input type="text" name="card_name" placeholder="Cardholder Name" required>
        <input type="text" name="card_number" placeholder="Card Number" maxlength="19" required>
        <div class="field-row">
          <input type="text" name="expiry" placeholder="MM/YY" maxlength="5" required>
          <input type="text" name="cvv" placeholder="CVV" maxlength="4" required>
        </div>
      </div>

      <!-- COD Notice -->
      <div class="payment-fields" id="cod-fields" style="display: none;">
        <p class="cod-info">You will pay upon delivery. Please prepare the exact amount.</p>
      </div>

      <button type="submit" class="confirm-btn">Confirm Payment</button>
        <?php
        // Get user address from database
        $user_address = '';
        $stmt_addr = mysqli_prepare($conn, "SELECT address FROM user WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt_addr, "i", $user_id);
        mysqli_stmt_execute($stmt_addr);
        $address_result = mysqli_stmt_get_result($stmt_addr);
        if ($address_result && mysqli_num_rows($address_result) > 0) {
            $row = mysqli_fetch_assoc($address_result);
            $user_address = $row['address'];
        }
        mysqli_stmt_close($stmt_addr);
        ?>
        <input type="hidden" name="total_price" value="<?php echo htmlspecialchars((string)$total_php, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="shipping_address" value="<?php echo htmlspecialchars($user_address ?? '', ENT_QUOTES, 'UTF-8'); ?>">
    </form>

    <!-- Order Summary -->
      <aside class="order-summary">
          <h3>Order Summary</h3>
          <div class="summary-item">
              <span>Subtotal:</span>
              <span><?php echo convertCurrency($_SESSION['subtotal_php'], $conn); ?></span>
          </div>
          <div class="summary-item">
              <span>Shipping:</span>
              <span><?php echo convertCurrency($shipping_fee, $conn); ?></span>
          </div>
          <div class="summary-total">
              <span>Total:</span>
              <span><?php echo convertCurrency($_SESSION['total_php'], $conn); ?></span>
          </div>
      </aside>
  </main>
  <script>
      document.addEventListener("DOMContentLoaded", function () {
          const creditFields = document.getElementById("credit-fields");
          const codFields = document.getElementById("cod-fields");
          const creditInputs = creditFields.querySelectorAll("input");
          const paymentRadios = document.querySelectorAll("input[name='payment_method']");

          function togglePaymentFields() {
              const selectedMethod = document.querySelector("input[name='payment_method']:checked").value;

              if (selectedMethod === "credit") {
                  creditFields.style.display = "block";
                  codFields.style.display = "none";
                  creditInputs.forEach(input => input.setAttribute("required", "required"));
              } else if (selectedMethod === "cod") {
                  creditFields.style.display = "none";
                  codFields.style.display = "block";
                  creditInputs.forEach(input => input.removeAttribute("required"));
              }
          }

          // Initial state
          togglePaymentFields();

          // Listen for payment method change
          paymentRadios.forEach(radio => {
              radio.addEventListener("change", togglePaymentFields);
          });
      });
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
