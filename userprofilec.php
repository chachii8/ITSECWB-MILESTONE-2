<?php
    // Database connection
    $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
    mysqli_select_db($conn, "sole_source");
    include 'currency_util.php';
    require_once 'includes/session_init.php';
    require_once 'includes/csrf.php';
    require_once 'includes/no_cache_headers.php';
    require_once 'file_upload_validation.php';

    // Check if the user is logged in and has the correct role
    if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Customer") {
        header("Location: login.php"); // Redirect to login page if not a customer
        exit();
    }

    // Get user ID from session for further queries
    $user_id = $_SESSION["user_id"];

    $photo_error = "";
    $photo_success = "";
    $profile_photo = null;

    // Fetch current profile photo filename (if any)
    $photo_stmt = mysqli_prepare($conn, "SELECT profile_photo FROM user WHERE user_id = ?");
    mysqli_stmt_bind_param($photo_stmt, "i", $user_id);
    mysqli_stmt_execute($photo_stmt);
    mysqli_stmt_bind_result($photo_stmt, $profile_photo_db);
    if (mysqli_stmt_fetch($photo_stmt)) {
        $profile_photo = $profile_photo_db ?: null;
    }
    mysqli_stmt_close($photo_stmt);

    // Handle profile photo upload
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_profile_photo'])) {
        if (!validate_csrf()) {
            $photo_error = "Security check failed. Please try again.";
        } else {
        $upload_validation = validate_uploaded_image_type($_FILES['profile_photo'] ?? []);
        if (!$upload_validation['valid']) {
            $photo_error = $upload_validation['error'] ?: "Invalid file type. Only JPG, JPEG, PNG are allowed.";
        } else {
            $base_images_dir = __DIR__ . '/images';
            $upload_dir = $base_images_dir . '/profile_photos';

            if (!is_dir($base_images_dir)) {
                @mkdir($base_images_dir, 0755, true);
            }
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            // Attempt to make writable across environments (may be ignored on Windows)
            @chmod($base_images_dir, 0777);
            @chmod($upload_dir, 0777);

            if (!is_dir($upload_dir)) {
                $photo_error = "Upload directory could not be created. Check folder permissions.";
            } elseif (!is_writable($upload_dir)) {
                $photo_error = "Upload directory is not writable. Please check permissions.";
            }

            $original_name = $_FILES['profile_photo']['name'] ?? '';
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $new_name = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest_path = $upload_dir . '/' . $new_name;

            if (empty($photo_error) && move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest_path)) {
                // Remove old photo if it exists
                if (!empty($profile_photo)) {
                    $old_path = $upload_dir . '/' . basename($profile_photo);
                    if (is_file($old_path)) {
                        unlink($old_path);
                    }
                }

                $stmt = mysqli_prepare($conn, "UPDATE user SET profile_photo = ? WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt, "si", $new_name, $user_id);
                if (mysqli_stmt_execute($stmt)) {
                    $photo_success = "Profile photo updated successfully.";
                    $profile_photo = $new_name;
                } else {
                    $photo_error = "Failed to save profile photo. Please try again.";
                }
                mysqli_stmt_close($stmt);
            } else {
                if (empty($photo_error)) {
                    $photo_error = "Error uploading image. Please try again.";
                }
            }
        }
        }
    }

    // Check if customer has MFA enabled (optional 2FA)
    $mfa_stmt = mysqli_prepare($conn, "SELECT mfa_enabled FROM user_mfa WHERE user_id = ?");
    mysqli_stmt_bind_param($mfa_stmt, "i", $user_id);
    mysqli_stmt_execute($mfa_stmt);
    mysqli_stmt_bind_result($mfa_stmt, $mfa_enabled);
    $has_mfa = mysqli_stmt_fetch($mfa_stmt);
    mysqli_stmt_close($mfa_stmt);
    $mfa_enabled = $has_mfa && $mfa_enabled;

    $profile_photo_safe = $profile_photo ? basename($profile_photo) : null;
    $profile_photo_path = $profile_photo_safe ? ('images/profile_photos/' . $profile_photo_safe) : null;
    $profile_photo_exists = $profile_photo_path && is_file(__DIR__ . '/' . $profile_photo_path);

    // Check if the user has placed any orders
    $stmt_orders = mysqli_prepare($conn, "SELECT * FROM `order` WHERE `user_id` = ?");
    mysqli_stmt_bind_param($stmt_orders, "i", $user_id);
    mysqli_stmt_execute($stmt_orders);
    $order_result = mysqli_stmt_get_result($stmt_orders);

    // Check if the user has written any reviews
    $stmt_reviews = mysqli_prepare($conn, "
      SELECT r.review, r.rating, r.date_submitted, p.name AS product_name, p.image_url
      FROM review r
      JOIN product p ON r.product_id = p.product_id
      WHERE r.user_id = ?
      ORDER BY r.date_submitted DESC
    ");
    mysqli_stmt_bind_param($stmt_reviews, "i", $user_id);
    mysqli_stmt_execute($stmt_reviews);
    $review_result = mysqli_stmt_get_result($stmt_reviews);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>User Profile | Sole Source</title>
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
            <div class="user-name"><?php echo htmlspecialchars($_SESSION["fullname"] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="user-role"><?php echo htmlspecialchars($_SESSION["role"] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
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

  <!-- Main Profile Page -->
  <div class="user-profile-page">
    <!-- Left Profile Sidebar -->
    <aside class="profile-sidebar">
      <div class="profile-info">
        <div style="margin-bottom: 16px; text-align: center;">
          <?php if ($profile_photo_exists): ?>
            <img
              src="<?php echo htmlspecialchars($profile_photo_path); ?>"
              alt="Profile Photo"
              style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 2px solid #eee;"
            />
            <div style="margin-top: 6px;">
              <a href="<?php echo htmlspecialchars($profile_photo_path); ?>" download style="font-size: 12px;">Download photo</a>
            </div>
          <?php else: ?>
            <div style="width: 110px; height: 110px; border-radius: 50%; background: #f3f4f6; display: inline-flex; align-items: center; justify-content: center; color: #6b7280; font-weight: 600;">
              <?php echo htmlspecialchars(strtoupper(substr($_SESSION["fullname"] ?? '', 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
            </div>
          <?php endif; ?>
        </div>

        <form action="userprofilec.php" method="post" enctype="multipart/form-data" style="margin-bottom: 14px;">
          <?php echo csrf_field(); ?>
          <?php if (!empty($photo_error)): ?>
            <p style="color: #dc2626; font-size: 12px; margin: 6px 0;"><?php echo htmlspecialchars($photo_error); ?></p>
          <?php elseif (!empty($photo_success)): ?>
            <p style="color: #16a34a; font-size: 12px; margin: 6px 0;"><?php echo htmlspecialchars($photo_success); ?></p>
          <?php endif; ?>
          <input type="file" name="profile_photo" accept="image/*" required style="font-size: 12px;">
          <button type="submit" name="upload_profile_photo" class="logout-btn" style="margin-top: 8px; width: 100%;">Upload Photo</button>
        </form>

        <p><strong>Name:</strong> <?php echo htmlspecialchars($_SESSION["fullname"] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Role:</strong> <?php echo htmlspecialchars($_SESSION["role"] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Email:</strong> <?php echo htmlspecialchars($_SESSION["email"] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>Address:</strong> <?php echo htmlspecialchars($_SESSION["address"] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>

        <!-- Security: Two-Factor Authentication -->
        <div class="profile-security" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee;">
          <p><strong>Two-Factor Authentication</strong></p>
          <?php if ($mfa_enabled): ?>
            <p style="color: green; font-size: 14px;"><i class="fas fa-shield-alt"></i> Enabled</p>
          <?php else: ?>
            <a href="mfa-enable.php"><button type="button" class="logout-btn" style="margin-top: 5px;">Enable 2FA</button></a>
          <?php endif; ?>
        </div>

        <div class="profile-buttons">
          <a href="logout.php"><button class="logout-btn">Logout</button></a>
        </div>
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="profile-main">
      <h2>My Profile</h2>

      <?php
      if (isset($_GET['mfa']) && $_GET['mfa'] === 'success') {
          echo '<p style="color: green; margin-bottom: 15px;"><i class="fas fa-check-circle"></i> Two-Factor Authentication has been enabled.</p>';
      } elseif (isset($_GET['mfa']) && $_GET['mfa'] === 'already_enabled') {
          echo '<p style="color: #666; margin-bottom: 15px;">Two-Factor Authentication is already enabled for your account.</p>';
      }
      ?>

      <!-- Display My Orders Only If There Are Orders -->
        <?php
        // Currency
        $selected_currency = isset($_SESSION['currency']) ? $_SESSION['currency'] : 'PHP';
        $conversion_rates = [
            'PHP' => 1,
            'USD' => 0.018, // Replace with real rates
            'KRW' => 23.5   // Replace with real rates
        ];

        // Query orders with product details
        $stmt_order_list = mysqli_prepare($conn, "
            SELECT o.order_id, o.order_status, o.order_date, p.product_id, p.name AS product_name, p.image_url, p.price, od.quantity, p.currency_id
            FROM `order` o
            JOIN order_details od ON o.order_id = od.order_id
            JOIN product p ON od.product_id = p.product_id
            WHERE o.user_id = ?
            ORDER BY o.order_date DESC
        ");
        mysqli_stmt_bind_param($stmt_order_list, "i", $user_id);
        mysqli_stmt_execute($stmt_order_list);
        $order_result = mysqli_stmt_get_result($stmt_order_list);
        ?>

        <?php if (mysqli_num_rows($order_result) > 0): ?>
            <div class="profile-section">
                <h3>My Orders</h3>
                <?php
                    $stmt_review_check = mysqli_prepare($conn, "
                        SELECT 1 FROM review
                        WHERE user_id = ? AND product_id = ?
                        LIMIT 1
                    ");
                ?>
                <?php while ($order = mysqli_fetch_assoc($order_result)): ?>
                    <div class="order-item">
                        <img src="images/<?php echo htmlspecialchars($order['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($order['product_name'], ENT_QUOTES, 'UTF-8'); ?>" class="order-image" />
                        <div class="order-details">
                            <p><strong>Order ID:</strong> <?php echo (int)$order['order_id']; ?></p>
                            <p><strong>Product:</strong> <?php echo htmlspecialchars($order['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Status:</strong> <?php echo htmlspecialchars($order['order_status'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php
                                $product_id = (int)$order['product_id'];
                                mysqli_stmt_bind_param($stmt_review_check, "ii", $user_id, $product_id);
                                mysqli_stmt_execute($stmt_review_check);
                                $review_result = mysqli_stmt_get_result($stmt_review_check);
                                $has_reviewed = mysqli_num_rows($review_result) > 0;
                                if (!$has_reviewed && $order['order_status'] === 'Delivered') {
                                    echo "<a href='reviews.php?product_id={$order['product_id']}&order_id={$order['order_id']}'>Rate this Product</a>";
                                } elseif ($has_reviewed) {
                                    echo "<span>Already Reviewed</span>";
                                }
                            ?>
                        </div>
                    </div>
                <?php endwhile; ?>
                <?php mysqli_stmt_close($stmt_review_check); ?>
            </div>
        <?php else: ?>
            <p>You haven't placed any orders yet.</p>
        <?php endif; ?>
        <?php if (isset($stmt_order_list)) { mysqli_stmt_close($stmt_order_list); } ?>

        <!-- Display Product Reviews Only If There Are Reviews -->
      <?php if (mysqli_num_rows($review_result) > 0): ?>
        <div class="profile-section">
          <h3>Product Reviews & Ratings</h3>
          <?php while ($review = mysqli_fetch_assoc($review_result)): ?>
              <div class="review-item">
                  <img src="images/<?php echo htmlspecialchars($review['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($review['product_name'], ENT_QUOTES, 'UTF-8'); ?>" class="review-image">
                  <div class="review-details">
                      <p class="review-product-name"><?php echo htmlspecialchars($review['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                      <div class="review-stars">
                          <?php
                          $fullStars = floor($review['rating']);
                          $halfStar = ($review['rating'] - $fullStars) >= 0.5;
                          for ($i = 0; $i < $fullStars; $i++) echo '<i class="fas fa-star filled"></i>';
                          if ($halfStar) echo '<i class="fas fa-star-half-alt filled"></i>';
                          for ($i = $fullStars + $halfStar; $i < 5; $i++) echo '<i class="far fa-star"></i>';
                          ?>
                      </div>
                      <p class="review-comment"><?php echo nl2br(htmlspecialchars($review['review'], ENT_QUOTES, 'UTF-8')); ?></p>
                  </div>
              </div>
          <?php endwhile; ?>
        </div>
      <?php else: ?>
        <p>You haven't written any reviews yet.</p>
      <?php endif; ?>
        <!-- Display Favorite Products Only If There Are Favorites -->
        <?php
        // Get user's favorites
        $stmt_favorites = mysqli_prepare($conn, "
            SELECT f.product_id, p.name AS product_name, p.image_url, p.price, p.currency_id
            FROM favorites f
            JOIN product p ON f.product_id = p.product_id
            WHERE f.user_id = ?
        ");
        mysqli_stmt_bind_param($stmt_favorites, "i", $user_id);
        mysqli_stmt_execute($stmt_favorites);
        $favorites_result = mysqli_stmt_get_result($stmt_favorites);
        ?>

        <?php if (mysqli_num_rows($favorites_result) > 0): ?>
            <div class="profile-section">
                <h3>My Favorites</h3>
                <?php while ($fav = mysqli_fetch_assoc($favorites_result)): ?>
                    <div class="favorite-item">
                        <img src="images/<?php echo htmlspecialchars($fav['image_url'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($fav['product_name'], ENT_QUOTES, 'UTF-8'); ?>" class="order-image" />
                        <div class="order-details">
                            <p><strong>Product:</strong> <?php echo htmlspecialchars($fav['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <p>You haven't added any favorites yet.</p>
        <?php endif; ?>
        <?php if (isset($stmt_favorites)) { mysqli_stmt_close($stmt_favorites); } ?>
        <?php if (isset($stmt_orders)) { mysqli_stmt_close($stmt_orders); } ?>
        <?php if (isset($stmt_reviews)) { mysqli_stmt_close($stmt_reviews); } ?>
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
  </script>
  <script src="js/no-back-cache.js"></script>
</body>
</html>
