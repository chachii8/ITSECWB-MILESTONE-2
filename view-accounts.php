<?php
$conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_error());
mysqli_select_db($conn, "sole_source");
require_once 'includes/session_init.php';
require_once 'includes/csrf.php';
require_once 'includes/no_cache_headers.php';
require_once 'audit_log.php';
require_once 'includes/input_validation.php';

// Check if the user is logged in and has the correct role
if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Admin") {
    header("Location: login-admin.php");
    exit();
}

$admin_name = $_SESSION["fullname"];
$admin_role = $_SESSION["role"];

// Delete user account along with their orders
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    if (!validate_csrf()) {
        $message = "Security check failed. Please try again.";
    } else {
    $user_id = validate_int_range($_POST['delete_user_id'] ?? 0, 1, 999999);

    if ($user_id !== false) {
        $stmt = $conn->prepare("CALL DeleteUserAndOrders(?)");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            log_audit(
                $conn,
                $_SESSION["user_id"] ?? null,
                $_SESSION["role"] ?? null,
                "USER_DELETE",
                "user",
                $user_id,
                null
            );
            $message = "User & their orders (if any) is deleted successfully!";
        } else {
            $message = "Error deleting user and orders.";
        }
        $stmt->close();
    }
    }
}

// Query to fetch all user accounts
$query = "SELECT * FROM `user`";  // Assuming your table for user accounts is named 'users'
$result = mysqli_query($conn, $query); // Execute the query to get the accounts
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>View Accounts | Sole Source</title>
  <link rel="stylesheet" href="css/homestyles.css" />
  <link rel="stylesheet" href="css/staffstyles.css" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>

    .popup-success {
        position: fixed;
        top: 20px;
        left: 50%;
        transform: translateX(-50%);
        background-color: #28a745;
        color: white;
        padding: 15px 30px;
        border-radius: 10px;
        font-size: 1.2em;
        font-weight: bold;
        display: none;
        z-index: 1000;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    .popup-success.show {
        display: block;
    }
    
    .delete-btn {
        font-size: 10px;
        background-color: #c0392b;
        color: white;
        padding: 10px 10px;
        border: none;
        cursor: pointer;
        border-radius: 5px;
    }

    .delete-btn:hover {
        background-color: #e74c3c;
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
      <button class="icon-button" title="Create Account" onclick="window.location.href='create-account.php'">
        <i class="fas fa-user-plus"></i>
      </button>
      <div class="user-profile-container">
        <button class="icon-button" onclick="window.location.href='userprofileadmin.php'">
          <i class="fas fa-user"></i>
        </button>
        <div class="profile-hover-info">
         <div class="user-name"><?php echo htmlspecialchars($admin_name); ?></div>
         <div class="user-role"><?php echo htmlspecialchars($admin_role); ?></div>
       </div>
      </div>
    </div>
  </div>

  <!-- Navigation -->
  <nav class="navigation">
    <a href="adminhomepage.php" class="nav-link">DASHBOARD</a>
    <a href="add-item.php" class="nav-link">ADD ITEM</a>
    <a href="edit-item.php" class="nav-link">EDIT ITEM</a>
    <a href="delete-item.php" class="nav-link">DELETE ITEM</a>
    <a href="admin-orders.php" class="nav-link">ORDERS</a>
    <a href="admin-update-stocks.php" class="nav-link">UPDATE STOCK</a>
    <a href="view-accounts.php" class="nav-link active">VIEW ACCOUNTS</a>
    <a href="view-audit-log.php" class="nav-link">AUDIT LOG</a>
  </nav>
</header>

<!-- Main Content -->
<div class="staff-container">
  <h2 class="section-title">All Registered Accounts</h2>

  <div class="account-grid">
    <?php
    // Display each account dynamically
    if (mysqli_num_rows($result) > 0) {
        while ($account = mysqli_fetch_assoc($result)) {
            echo '<div class="account-card">';
            echo '<p><strong>Name:</strong> ' . htmlspecialchars($account['name']) . '</p>';
            echo '<p><strong>Email:</strong> ' . htmlspecialchars($account['email']) . '</p>';
            echo '<p><strong>Role:</strong> ' . htmlspecialchars($account['role']) . '</p>';
            // Add Delete button
            echo '<form method="POST" action="view-accounts.php">' . csrf_field() . '
                    <input type="hidden" name="delete_user_id" value="' . (int)$account['user_id'] . '" />
                    <button type="submit" class="delete-btn">DELETE</button>
                  </form>';
            echo '</div>';
        }
    } else {
        echo '<p>No accounts found.</p>';
    }
    ?>
  </div>
</div>

<!-- Success Popup -->
<?php if (isset($message)): ?>
    <div class="popup-success show">
        <?php echo htmlspecialchars($message ?? '', ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <script>
        // Automatically hide the popup after 3 seconds
        setTimeout(function() {
            document.querySelector('.popup-success').classList.remove('show');
        }, 3000);
    </script>
<?php endif; ?>

<script src="js/scripts.js"></script>
<script src="js/no-back-cache.js"></script>
</body>
</html>
