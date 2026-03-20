<?php
// Database connection
require_once 'includes/db.php';

require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'file_upload_validation.php';

// Check if the user is logged in and has the correct role (Staff)
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Staff") {
    header("Location: login-admin.php");
    exit();
}

// Get user info from session
$user_id = $_SESSION["user_id"];
$name = $_SESSION["fullname"];
$email = $_SESSION["email"];
$role = $_SESSION["role"];

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
        @chmod($base_images_dir, 0777);
        @chmod($upload_dir, 0777);

        if (!is_dir($upload_dir)) {
            $photo_error = "Upload directory could not be created. Check folder permissions.";
        } elseif (!is_writable($upload_dir)) {
            $photo_error = "Upload directory is not writable. Please check permissions.";
        } else {
            $original_name = $_FILES['profile_photo']['name'] ?? '';
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            $new_name = 'profile_' . $user_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest_path = $upload_dir . '/' . $new_name;

            if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest_path)) {
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
                $photo_error = "Error uploading image. Please try again.";
            }
        }
    }
    }
}

$profile_photo_safe = $profile_photo ? basename($profile_photo) : null;
$profile_photo_path = $profile_photo_safe ? ('images/profile_photos/' . $profile_photo_safe) : null;
$profile_photo_exists = $profile_photo_path && is_file(__DIR__ . '/' . $profile_photo_path);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Staff Profile | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/staffstyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<!-- Header -->
<header class="header">
  <div class="header-container">
    <div class="header-spacer"></div>
    <h1 class="logo">SOLE SOURCE</h1>
    <div class="header-icons">
      <!-- User Profile Icon -->
      <div class="user-profile-container">
        <button class="icon-button">
          <i class="fas fa-user"></i>
        </button>
        <div class="profile-hover-info">
          <div class="user-name"><?php echo htmlspecialchars($name); ?></div>
          <div class="user-role"><?php echo htmlspecialchars($role); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="navigation">
    <a href="staffhomepage.php" class="nav-link">DASHBOARD</a>
    <a href="orders-to-process.php" class="nav-link">ORDERS</a>
    <a href="update-stocks.php" class="nav-link">UPDATE STOCK</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-profile-page">
  <div class="staff-profile-card">
    <h2 class="profile-heading">My Profile</h2>

    <div class="staff-profile-info">
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
            <?php echo strtoupper(substr($name, 0, 1)); ?>
          </div>
        <?php endif; ?>
      </div>

      <form action="userprofilestaff.php" method="post" enctype="multipart/form-data" style="margin-bottom: 14px;">
        <?php echo csrf_field(); ?>
        <?php if (!empty($photo_error)): ?>
          <p style="color: #dc2626; font-size: 12px; margin: 6px 0;"><?php echo htmlspecialchars($photo_error); ?></p>
        <?php elseif (!empty($photo_success)): ?>
          <p style="color: #16a34a; font-size: 12px; margin: 6px 0;"><?php echo htmlspecialchars($photo_success); ?></p>
        <?php endif; ?>
        <input type="file" name="profile_photo" accept="image/*" required style="font-size: 12px;">
        <button type="submit" name="upload_profile_photo" class="logout-btn" style="margin-top: 8px; width: 100%;">Upload Photo</button>
      </form>

      <p><strong>Name:</strong> <?php echo htmlspecialchars($name); ?></p>
      <p><strong>Email:</strong> <?php echo htmlspecialchars($email); ?></p>
      <p><strong>Role:</strong> <?php echo htmlspecialchars($role); ?></p>
    </div>

    <div class="profile-buttons">
      <a href="logout.php"><button class="logout-btn">Logout</button></a>
    </div>
  </div>
</div>

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
