<?php
require_once 'includes/db.php';
    include 'currency_util.php';
    require_once 'includes/input_validation.php';
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

    // Get user_id
    $user_q = mysqli_prepare($conn, "SELECT user_id FROM user WHERE email = ?");
    mysqli_stmt_bind_param($user_q, 's', $customer_email);
    mysqli_stmt_execute($user_q);
    $user_result = mysqli_stmt_get_result($user_q);
    $user = mysqli_fetch_assoc($user_result);
    $user_id = isset($user['user_id']) ? $user['user_id'] : null;

    // Update quantity or size
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product_id'])) {
        if (!validate_csrf()) {
            echo json_encode(['status' => 'error', 'message' => 'Security check failed. Please refresh and try again.']);
            exit();
        }
        $product_id = validate_int_range($_POST['update_product_id'] ?? 0, 1, 999999);
        $new_quantity = validate_int_range($_POST['quantity'] ?? 1, 1, 999);
        $new_size = $_POST['size'] ?? '';
        $original_size = $_POST['original_size'] ?? '';

        if ($product_id === false || $new_quantity === false) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid quantity (1-999).']);
            exit();
        }
        if (validate_size($new_size) === false || validate_size($original_size) === false) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid size.']);
            exit();
        }

        // Check stock for new size (when size changes) or same size (when qty changes)
        $check_size = ($new_size === $original_size) ? $original_size : $new_size;
        $stock_stmt = mysqli_prepare($conn, "SELECT stock FROM product_size WHERE product_id = ? AND size = ?");
        mysqli_stmt_bind_param($stock_stmt, "is", $product_id, $check_size);
        mysqli_stmt_execute($stock_stmt);
        $stock_result = mysqli_stmt_get_result($stock_stmt);
        $stock_row = mysqli_fetch_assoc($stock_result);
        mysqli_stmt_close($stock_stmt);
        $available = $stock_row ? (int)$stock_row['stock'] : 0;

        if ($new_size !== $original_size) {
            $existing_qty = 0;
            $exist_stmt = mysqli_prepare($conn, "SELECT quantity FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
            mysqli_stmt_bind_param($exist_stmt, "iis", $user_id, $product_id, $new_size);
            mysqli_stmt_execute($exist_stmt);
            $exist_res = mysqli_stmt_get_result($exist_stmt);
            if ($ex = mysqli_fetch_assoc($exist_res)) $existing_qty = (int)$ex['quantity'];
            mysqli_stmt_close($exist_stmt);
            if ($new_quantity + $existing_qty > $available) {
                echo json_encode(['status' => 'error', 'message' => 'Only ' . $available . ' in stock for this size.']);
                exit();
            }
        } else {
            if ($new_quantity > $available) {
                echo json_encode(['status' => 'error', 'message' => 'Only ' . $available . ' in stock.']);
                exit();
            }
        }

        $stmt = null;
        if ($new_size === $original_size) {
            // Only quantity changed
            $stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ? AND size = ?");
            mysqli_stmt_bind_param($stmt, 'iiis', $new_quantity, $user_id, $product_id, $original_size);
        } else {
            // Check if item with new size already exists
            $check_stmt = mysqli_prepare($conn, "SELECT cart_id, quantity FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
            mysqli_stmt_bind_param($check_stmt, 'iis', $user_id, $product_id, $new_size);
            mysqli_stmt_execute($check_stmt);
            $existing_result = mysqli_stmt_get_result($check_stmt);
            $existing_row = mysqli_fetch_assoc($existing_result);

            if ($existing_row) {
                // New size already exists → merge quantities
                $merged_qty = $existing_row['quantity'] + $new_quantity;
                $update_stmt = mysqli_prepare($conn, "UPDATE cart SET quantity = ? WHERE cart_id = ?");
                mysqli_stmt_bind_param($update_stmt, 'ii', $merged_qty, $existing_row['cart_id']);
                mysqli_stmt_execute($update_stmt);
            } else {
                // New size does not exist → insert new entry
                $insert_stmt = mysqli_prepare($conn, "INSERT INTO cart (user_id, product_id, size, quantity) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($insert_stmt, 'iidi', $user_id, $product_id, $new_size, $new_quantity);
                mysqli_stmt_execute($insert_stmt);
            }

            // Remove old item (after handling merge/insert)
            $delete_stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
            mysqli_stmt_bind_param($delete_stmt, 'iis', $user_id, $product_id, $original_size);
            mysqli_stmt_execute($delete_stmt);
        }

        if ($stmt) {
            mysqli_stmt_execute($stmt);
        }

        // Recalculate subtotal (PHP for session/orders, converted for display)
        $subtotal_query = mysqli_prepare($conn, "
            SELECT p.price, c.quantity
            FROM cart c
            JOIN product p ON c.product_id = p.product_id
            WHERE c.user_id = ?
        ");
        mysqli_stmt_bind_param($subtotal_query, 'i', $user_id);
        mysqli_stmt_execute($subtotal_query);
        $result = mysqli_stmt_get_result($subtotal_query);

        $subtotal_php = 0;
        $new_subtotal = 0;
        while ($row = mysqli_fetch_assoc($result)) {
            $price_php = (float)$row['price'];
            $qty = (int)$row['quantity'];
            $subtotal_php += $price_php * $qty;
            $new_subtotal += getConvertedPrice($price_php, $conn) * $qty;
        }
        $_SESSION['subtotal_php'] = $subtotal_php > 0 ? $subtotal_php : 0.00;

        echo json_encode([
            'status' => 'success',
            'new_subtotal' => number_format($new_subtotal, 2)
        ]);
        exit();
    }

    // Handle item removal
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_product_id'])) {
        if (!validate_csrf()) {
            header("Location: cart.php");
            exit();
        }
        $remove_product_id = validate_int_range($_POST['remove_product_id'] ?? 0, 1, 999999);
        $remove_size = $_POST['remove_size'] ?? '';
        if ($remove_product_id === false || validate_size($remove_size) === false) {
            header("Location: cart.php");
            exit();
        }

        $delete_stmt = mysqli_prepare($conn, "DELETE FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
        mysqli_stmt_bind_param($delete_stmt, 'iis', $user_id, $remove_product_id, $remove_size);
        mysqli_stmt_execute($delete_stmt);

        // Redirect to prevent form resubmission
        header("Location: cart.php");
        exit();
    }

    $cart_items = [];
    $subtotal = 0.00;

    if ($user_id) {
        $sql = "SELECT 
                    c.product_id,
                    c.size,
                    c.quantity,
                    p.name,
                    p.price,
                    p.image_url AS image,
                    b.name AS brand,
                    p.currency_id
                FROM cart c
                JOIN product p ON c.product_id = p.product_id
                JOIN brand b ON p.brand_id = b.brand_id
                WHERE c.user_id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'i', $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        while ($row = mysqli_fetch_assoc($result)) {
            $price_php = $row['price']; // Keep the raw PHP price
            $row['converted_price'] = getConvertedPrice($price_php, $conn); // For display only

            // Get available sizes and stock for this product
            $size_query = mysqli_prepare($conn, "SELECT size, stock FROM product_size WHERE product_id = ?");
            mysqli_stmt_bind_param($size_query, 'i', $row['product_id']);
            mysqli_stmt_execute($size_query);
            $size_result = mysqli_stmt_get_result($size_query);

            $available_sizes = [];
            $stock_for_size = 0;
            while ($size_row = mysqli_fetch_assoc($size_result)) {
                $available_sizes[] = $size_row['size'];
                if ((string)$size_row['size'] === (string)$row['size']) {
                    $stock_for_size = (int)$size_row['stock'];
                }
            }
            $row['available_sizes'] = $available_sizes;
            $row['stock'] = $stock_for_size;
            $row['overstock'] = ($row['quantity'] > $stock_for_size);

            $cart_items[] = $row;
            $subtotal += $price_php * $row['quantity']; // Always in PHP

            $_SESSION["subtotal_php"] = $subtotal > 0 ? $subtotal : 0.00;
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Cart | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/cartstyles.css" />
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
      <a href="homepage.php" class="nav-link">SHOES</a>
      <a href="brands.php" class="nav-link">BRANDS</a>
      <a href="cart.php" class="nav-link active">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Cart Section -->
  <main class="cart-container">
    <h2 class="cart-title">My Cart</h2>

    <?php if (!empty($_SESSION['order_error'])): ?>
      <div class="cart-error-banner">
        <?php echo htmlspecialchars($_SESSION['order_error'], ENT_QUOTES, 'UTF-8'); ?>
      </div>
      <?php unset($_SESSION['order_error']); ?>
    <?php endif; ?>

    <div class="cart-content">
      <section class="cart-items">
        <?php foreach ($cart_items as $item): ?>
        <div class="cart-item">
          <img src="images/<?php echo htmlspecialchars($item["image"], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($item["name"], ENT_QUOTES, 'UTF-8'); ?>" class="cart-item-img">
          <div class="cart-item-info">
            <h3 class="cart-item-name"><?php echo htmlspecialchars($item["name"], ENT_QUOTES, 'UTF-8'); ?></h3>
            <p class="cart-item-brand"><?php echo htmlspecialchars($item["brand"], ENT_QUOTES, 'UTF-8'); ?></p>
              <p class="cart-item-price">
                  <?php echo getCurrencySymbol(); ?><?php echo number_format($item['converted_price'], 2); ?>
              </p>
              <div class="cart-item-size">
                  <form class="cart-update-form" method="post">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="update_product_id" value="<?php echo (int)$item['product_id']; ?>">
                      <input type="hidden" name="original_size" value="<?php echo htmlspecialchars($item['size'], ENT_QUOTES, 'UTF-8'); ?>">
                      <div class = "form-group">
                          <label for="size-select">Size:</label>
                          <select name="size" class="size-select">
                              <?php foreach ($item['available_sizes'] as $size): ?>
                                  <option value="<?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $size == $item["size"] ? "selected" : ""; ?>>
                                      <?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>
                                  </option>
                              <?php endforeach; ?>
                          </select>
                      </div>
                      <div class = "form-group">
                        <label>Qty:</label>
                        <input type="number" name="quantity" min="1" max="<?php echo max(1, (int)($item['stock'] ?? 999)); ?>" value="<?php echo $item["quantity"]; ?>" class="quantity-input">
                        <?php if (!empty($item['overstock'])): ?><small style="color:#c0392b;">Only <?php echo (int)$item['stock']; ?> in stock</small><?php endif; ?>
                        <small class="quantity-error" style="display:none;"></small>
                      </div>
                  </form>
              </div>
            <div class="cart-item-controls">
                <button class="remove-btn" type="button"
                        onclick="openRemoveModal(<?php echo (int)$item['product_id']; ?>, '<?php echo htmlspecialchars($item['size'], ENT_QUOTES, 'UTF-8'); ?>')">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </section>

      <?php
        $has_overstock = false;
        foreach ($cart_items as $it) { if (!empty($it['overstock'])) { $has_overstock = true; break; } }
      ?>
      <aside class="cart-summary">
        <h3>Order Summary</h3>
          <?php if ($has_overstock): ?>
              <p class="cart-error-msg" style="color:#c0392b;font-weight:bold;">Some items exceed available stock. Please update quantities.</p>
              <button type="button" class="checkout-btn" disabled style="opacity:0.6;cursor:not-allowed;">Proceed to Checkout</button>
          <?php elseif (!empty($cart_items)): ?>
              <p><strong>Subtotal:</strong> <span id="subtotal"><?php echo convertCurrency($_SESSION['subtotal_php'], $conn); ?></span></p>
              <a href="payment.php" class="checkout-btn">Proceed to Checkout</a>
          <?php else: ?>
              <p>Your cart is empty.</p>
          <?php endif; ?>
      </aside>
    </div>
  </main>
  <!-- Modal -->
  <div id="removeModal" class="modal-overlay" style="display: none;">
      <div class="modal-box">
          <h3>Remove Item</h3>
          <p>Are you sure you want to remove this item from your cart?</p>
          <form id="removeForm" method="POST">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="remove_product_id" id="remove_product_id">
              <input type="hidden" name="remove_size" id="remove_size">
              <div class="modal-buttons">
                  <button type="button" onclick="closeRemoveModal()" class="cancel-btn">Cancel</button>
                  <button type="submit" class="confirm-btn">Remove</button>
              </div>
          </form>
      </div>
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
      const checkoutBtn = document.querySelector('.checkout-btn');

      function setCheckoutDisabled(disabled) {
          if (!checkoutBtn) return;
          if (disabled) {
              checkoutBtn.classList.add('disabled');
              checkoutBtn.setAttribute('aria-disabled', 'true');
          } else {
              checkoutBtn.classList.remove('disabled');
              checkoutBtn.removeAttribute('aria-disabled');
          }
      }

      function recomputeCheckoutDisabled() {
          const qtyInputs = document.querySelectorAll('.quantity-input');
          let invalid = false;
          qtyInputs.forEach(input => {
              const val = parseInt(input.value, 10);
              if (isNaN(val) || val < 1) {
                  invalid = true;
              }
          });
          setCheckoutDisabled(invalid);
      }
      document.querySelectorAll('.cart-update-form').forEach(form => {
          const sizeSelect = form.querySelector('.size-select');
          const qtyInput = form.querySelector('.quantity-input');
          const qtyError = form.querySelector('.quantity-error');

          const updateCart = () => {
              const formData = new FormData(form);

              fetch('cart.php', {
                  method: 'POST',
                  body: formData
              })
                  .then(res => res.json())
                  .then(data => {
                      if (data.status === 'success') {
                          const currencySymbol = "<?php echo getCurrencySymbol(); ?>";
                          document.getElementById('subtotal').textContent = currencySymbol + data.new_subtotal;
                          if (qtyError) {
                              qtyError.style.display = 'none';
                              qtyError.textContent = '';
                          }
                          recomputeCheckoutDisabled();
                      } else if (data.status === 'error') {
                          if (qtyError) {
                              qtyError.textContent = data.message || 'Quantity must be at least 1.';
                              qtyError.style.display = 'block';
                          }
                          recomputeCheckoutDisabled();
                      } else {
                          console.error('Update failed', data);
                      }
                  });
          };

          sizeSelect.addEventListener('change', updateCart);
          qtyInput.addEventListener('change', updateCart);
          qtyInput.addEventListener('input', recomputeCheckoutDisabled);
      });
      // Initial state on page load
      recomputeCheckoutDisabled();
      function openRemoveModal(productId, size) {
          document.getElementById('remove_product_id').value = productId;
          document.getElementById('remove_size').value = size;
          document.getElementById('removeModal').style.display = 'flex';
      }
      function closeRemoveModal() {
          document.getElementById('removeModal').style.display = 'none';
      }
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>