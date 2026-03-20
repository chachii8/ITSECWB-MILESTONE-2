<?php
    $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error($conn));
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

    // Get product and order ID from URL
    $product_name = "";
    $success = "";
    $error = "";

    if (!isset($_GET['product_id']) || !isset($_GET['order_id'])) {
        die("Missing product or order ID.");
    }

    $product_id = intval($_GET['product_id']);
    $order_id = intval($_GET['order_id']);

    // Fetch product name
    $stmt_product = mysqli_prepare($conn, "SELECT name FROM product WHERE product_id = ?");
    mysqli_stmt_bind_param($stmt_product, "i", $product_id);
    mysqli_stmt_execute($stmt_product);
    $product_query = mysqli_stmt_get_result($stmt_product);
    if ($product_row = mysqli_fetch_assoc($product_query)) {
        $product_name = $product_row['name'];
    } else {
        $error = "Product not found.";
    }
    mysqli_stmt_close($stmt_product);


    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        if (!validate_csrf()) {
            $error = "Security check failed. Please try again.";
        } else {
        $rating = isset($_POST["rating"]) ? intval($_POST["rating"]) : 0;
        $comment = trim($_POST["comment"]);
        $date = date("Y-m-d");

        if ($rating < 1 || $rating > 5 || empty($comment)) {
            $error = "Please provide a valid rating and comment.";
        } else {
            // Prevent duplicate review per purchase (user_id + order_id + product_id)
            $stmt_check = $conn->prepare("
                SELECT 1 FROM review
                WHERE user_id = ? AND order_id = ? AND product_id = ?
                LIMIT 1
            ");
            $stmt_check->bind_param("iii", $user_id, $order_id, $product_id);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            $already_reviewed = $check_result && $check_result->num_rows > 0;
            $stmt_check->close();

            if ($already_reviewed) {
                $error = "You have already submitted a review for this purchase.";
            } else {
                $stmt = $conn->prepare("CALL SubmitReview(?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiiss", $user_id, $order_id, $product_id, $rating, $comment, $date);

                if ($stmt->execute()) {
                    $success = "Thank you! Your review has been submitted.";
                } else {
                    $error = "Error submitting review: " . $stmt->error;
                }

                $stmt->close();
            }
        }
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>Review Product | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css">
  <link rel="stylesheet" href="css/userprofilestyles.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    .review-container {
      max-width: 600px;
      margin: 40px auto;
      background: #fff;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    }
    .review-container h2 {
      margin-bottom: 10px;
    }
    .stars input[type="radio"] {
      display: none;
    }
    .stars label {
      font-size: 24px;
      color: #ccc;
      cursor: pointer;
    }
    .stars input:checked ~ label {
      color: gold;
    }
    .stars label:hover,
    .stars label:hover ~ label {
      color: gold;
    }
    textarea {
      width: 100%;
      padding: 10px;
      resize: vertical;
      border: 1px solid #ccc;
      border-radius: 4px;
      font-size: 15px;
      margin-bottom: 15px;
    }
    button[type="submit"] {
      background-color: #000;
      color: #fff;
      padding: 12px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
    }
    button[type="submit"]:hover {
      background-color: #333;
    }
    .message {
      color: green;
      text-align: center;
    }
    .error {
      color: red;
      text-align: center;
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
      <a href="cart.php" class="nav-link">CART</a>
      <a href="orders.php" class="nav-link">ORDERS</a>
    </nav>
  </header>

  <!-- Review Form -->
  <div class="review-container">
    <h2>Review Product</h2>
    <p><strong>Product:</strong> <?php echo htmlspecialchars($product_name); ?></p>

    <?php if ($success): ?>
      <p class="message"><?php echo htmlspecialchars($success ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif ($error): ?>
      <p class="error"><?php echo htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="post" action="">
      <?php echo csrf_field(); ?>
      <label for="rating">Rating:</label>
      <div class="stars">
        <input type="radio" name="rating" value="5" id="star5"><label for="star5">★</label>
        <input type="radio" name="rating" value="4" id="star4"><label for="star4">★</label>
        <input type="radio" name="rating" value="3" id="star3"><label for="star3">★</label>
        <input type="radio" name="rating" value="2" id="star2"><label for="star2">★</label>
        <input type="radio" name="rating" value="1" id="star1"><label for="star1">★</label>
      </div>

      <label for="comment">Your Review:</label>
      <textarea name="comment" id="comment" rows="5" placeholder="Share your experience..."></textarea>

      <button type="submit">Submit Review</button>
    </form>
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
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>