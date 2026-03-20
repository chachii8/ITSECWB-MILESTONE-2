<?php
require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error($conn));
mysqli_select_db($conn, "sole_source");

require_once 'includes/password_policy.php';
require_once 'includes/captcha.php';
require_once 'file_upload_validation.php';

// Admin/Staff must use admin login
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php"); // Admin/Staff use admin login
    exit();
}
$success_message = "";
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!validate_csrf()) {
        $error_message = "Security check failed. Please try again.";
    } else {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    // Normalize phone: keep digits only
    $phone = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
    $address = trim($_POST['address']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];
    $profile_photo = null;

    $valid = true;

    // Basic full name validation
    if (empty($fullname)) {
        $valid = false;
        $error_message .= "Full name is required.<br>";
    } elseif (mb_strlen($fullname) < 2 || mb_strlen($fullname) > 100) {
        $valid = false;
        $error_message .= "Full name must be between 2 and 100 characters.<br>";
    }

    // Address validation
    if (empty($address)) {
        $valid = false;
        $error_message .= "Address is required.<br>";
    } elseif (mb_strlen($address) < 5 || mb_strlen($address) > 255) {
        $valid = false;
        $error_message .= "Address must be between 5 and 255 characters.<br>";
    }

    // Validate phone: PH mobile only (11 digits starting with 09)
    if (empty($phone)) {
        $valid = false;
        $error_message .= "Phone number is required.<br>";
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $valid = false;
        $error_message .= "Phone number must be a valid PH mobile (11 digits starting with 09).<br>";
    }

    // CAPTCHA: blocks automated creation of admin/staff accounts
    if (!validate_captcha_response()) {
        $valid = false;
        $error_message .= "Security check failed. Please try again.<br>";
    }

    if ($password !== $confirm_password) {
        $valid = false;
        $error_message .= "Passwords do not match.<br>";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $valid = false;
        $error_message .= "Invalid email format.<br>";
    }

    // Role validation: only Admin or Staff allowed
    if (!in_array($role, ['Admin', 'Staff'], true)) {
        $valid = false;
        $error_message .= "Invalid role selected.<br>";
    }

    $stmt = mysqli_prepare($conn, "SELECT email FROM user WHERE email = ?");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    if (mysqli_stmt_num_rows($stmt) > 0) {
        $valid = false;
        $error_message .= "Email already registered.<br>";
    }

    // Optional profile photo upload (not required)
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_validation = validate_uploaded_image_type($_FILES['profile_photo']);
        if (!$upload_validation['valid']) {
            $valid = false;
            $error_message .= ($upload_validation['error'] ?: "Invalid profile photo type.") . "<br>";
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

            if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                $valid = false;
                $error_message .= "Profile photo upload folder is not writable.<br>";
            } else {
                $original_name = $_FILES['profile_photo']['name'] ?? '';
                $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
                $profile_photo = 'profile_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest_path = $upload_dir . '/' . $profile_photo;
                if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'], $dest_path)) {
                    $valid = false;
                    $error_message .= "Error uploading profile photo.<br>";
                }
            }
        }
    }

    // Password policy: strong passwords for admin/staff
    $pwd_errors = [];
    if (!validate_password_policy($password, $pwd_errors)) {
        $valid = false;
        $error_message .= implode("<br>", $pwd_errors);
    }

    // Proceed if all validations pass
    if ($valid) {
        // Hash the password for secure storage
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Hardcode date_joined
        $date_joined = date("Y-m-d");

        // Insert user into the database with the provided role (no phone for admin-created accounts)
        $stmt = mysqli_prepare($conn, "INSERT INTO user (name, email, phone, password, role, date_joined, address, profile_photo) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, "ssssssss", $fullname, $email, $phone, $hashed_password, $role, $date_joined, $address, $profile_photo);

        if (mysqli_stmt_execute($stmt)) {
            $success_message = "<p class='success-message'>Account created successfully for {$role}. The user is now registered!</p>";
        } else {
            $error_message = "<p class='error-message'>Error: " . $conn->error . "</p>";
        }

        mysqli_stmt_close($stmt);
    } else {
        // Wrap accumulated messages in styled container for display
        if (!empty($error_message)) {
            $error_message = "<p class='error-message'>" . $error_message . "</p>";
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account | Sole Source</title>
  <link rel="stylesheet" href="css/style.css" />
  <link rel="stylesheet" href="css/staffstyles.css" />
</head>
<body class="signup-page">

  <!-- Admin Nav Logo/Header -->
  <div class="logo">SOLE SOURCE</div>

  <div class="signup-container">
    <h1>Create Account</h1>
    <h2>Fill out the form below to register a new Admin or Staff account.</h2>

    <!-- Display success or error messages -->
    <?php if (!empty($error_message)): ?>
        <?php echo htmlspecialchars($error_message ?? '', ENT_QUOTES, 'UTF-8'); ?>
    <?php elseif (!empty($success_message)): ?>
        <?php echo htmlspecialchars($success_message ?? '', ENT_QUOTES, 'UTF-8'); ?>
    <?php endif; ?>

    <!-- Form to create new Admin/Staff account -->
    <form class="signup-form" action="" method="post" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <div class="form-group">
        <p>FULL NAME</p>
        <input type="text" name="fullname" placeholder="Name" required maxlength="100" />
      </div>
    
      <div class="form-group">
        <p>EMAIL</p>
        <input type="email" name="email" placeholder="Email" required maxlength="254" />
      </div>

      <div class="form-group">
        <p>PHONE (PH)</p>
        <input
          type="tel"
          name="phone"
          id="phone"
          placeholder="09XXXXXXXXX"
          required
          inputmode="numeric"
          minlength="11"
          maxlength="11"
          pattern="^09[0-9]{9}$"
        />
        <small class="field-error phone-error" style="display:none;"></small>
      </div>
    
      <div class="form-group">
        <p>ADDRESS</p>
        <input type="text" name="address" placeholder="Address" required maxlength="255" />
      </div>

      <div class="form-group">
        <p>PROFILE PHOTO (optional)</p>
        <input type="file" name="profile_photo" accept="image/*">
        <small style="font-size:11px; color:#666;">JPG, JPEG, or PNG only.</small>
      </div>
    
      <div class="form-group">
        <p>PASSWORD</p>
        <input type="password" id="admin_password" name="password" placeholder="Password" required maxlength="72" />
        <small style="font-size:11px; color:#666;"><?php echo get_password_requirements_text(); ?></small>
        <div class="password-strength">
          <div class="password-strength-bar"><span></span></div>
          <small class="password-strength-text"></small>
        </div>
      </div>
    
      <div class="form-group">
        <p>CONFIRM PASSWORD</p>
        <input type="password" name="confirm_password" placeholder="Confirm password" required />
      </div>

      <?php /* CAPTCHA on admin account creation */ echo render_captcha(1); ?>

      <div class="form-group">
        <p>ROLE</p>
        <select name="role" class="account-role-dropdown" required>
          <option value="">Select role</option>
          <option value="Admin">Admin</option>
          <option value="Staff">Staff</option>
        </select>
      </div>
    
      <button type="submit">Create Account</button>
    </form>

    <div class="profile-buttons">
      <a href="adminhomepage.php">
        <button type="button" class="back-btn">Back to Admin Homepage</button>
      </a>
    </div>

  </div>
<script>
  (function () {
    var phoneInput = document.getElementById('phone');
    var phoneError = document.querySelector('.phone-error');
    var form = document.querySelector('.signup-form');
    var pwdInput = document.getElementById('admin_password');
    var bar = document.querySelector('.password-strength-bar span');
    var text = document.querySelector('.password-strength-text');
    var passwordScore = 0;

    function validatePhoneField() {
      if (!phoneInput || !phoneError) return true;
      var raw = phoneInput.value || '';
      var digits = raw.replace(/\D+/g, '');

      if (!digits) {
        phoneError.textContent = 'Phone number is required.';
        phoneError.style.display = 'block';
        return false;
      }
      if (!/^09\d{9}$/.test(digits)) {
        phoneError.textContent = 'Enter a valid PH mobile: 11 digits starting with 09.';
        phoneError.style.display = 'block';
        return false;
      }

      phoneError.textContent = '';
      phoneError.style.display = 'none';
      return true;
    }

    if (phoneInput && phoneError) {
      phoneInput.addEventListener('input', validatePhoneField);
      phoneInput.addEventListener('blur', validatePhoneField);
    }

    if (pwdInput && bar && text) {
      var labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
      var colors = ['#dc2626', '#f97316', '#eab308', '#22c55e', '#16a34a'];

      function getScore(val) {
        var score = 0;
        if (val.length >= 8) score++;
        if (val.length >= 12) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        if (score > 4) score = 4;
        return score;
      }

      pwdInput.addEventListener('input', function () {
        var val = pwdInput.value || '';
        if (!val) {
          bar.style.width = '0%';
          bar.style.backgroundColor = '#d1d5db';
          text.textContent = '';
          passwordScore = 0;
          return;
        }

        var score = getScore(val); // 0–4
        passwordScore = score;
        var percent = (score + 1) * 20;

        bar.style.width = percent + '%';
        bar.style.backgroundColor = colors[score];
        text.textContent = labels[score];
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        var okPhone = validatePhoneField();
        var okPassword = true;

        if (pwdInput && typeof passwordScore === 'number') {
          // Require at least "Fair" (score >= 2); block Very weak/Weak
          if (passwordScore <= 1) {
            okPassword = false;
            if (text) {
              text.textContent = (text.textContent || 'Password is too weak.');
            }
          }
        }

        if (!okPhone || !okPassword) {
          e.preventDefault();
        }
      });
    }
  })();
</script>
</body>
</html>
