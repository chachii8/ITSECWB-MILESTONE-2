<?php
    $conn = mysqli_connect("localhost", "root", "") or die("Unable to connect!" . mysqli_connect_error());
    mysqli_select_db($conn, "sole_source");
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

    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['user_id'])) {
        if (!validate_csrf()) {
            header("Location: homepage.php?error=security_check_failed");
            exit();
        }
        $user_id = $_SESSION['user_id'];
        $product_id = validate_int_range($_POST['product_id'] ?? 0, 1, 999999);
        $size_raw = $_POST['size'] ?? '';
        $quantity = validate_int_range($_POST['quantity'] ?? 1, 1, 999);
        if ($quantity === false) $quantity = 1;

        if ($product_id === false) {
            header("Location: homepage.php?error=invalid_product");
            exit();
        }
        $size = validate_size($size_raw);
        if ($size === false) {
            header("Location: homepage.php?error=invalid_size");
            exit();
        }
        $size = (string)$size;

        // Check available stock before adding
        $stock_stmt = $conn->prepare("SELECT stock FROM product_size WHERE product_id = ? AND size = ?");
        $stock_stmt->bind_param("is", $product_id, $size);
        $stock_stmt->execute();
        $stock_row = $stock_stmt->get_result()->fetch_assoc();
        $stock_stmt->close();
        $available = $stock_row ? (int)$stock_row['stock'] : 0;

        // Get current cart quantity for this item (if any)
        $cart_qty = 0;
        $cart_check = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
        $cart_check->bind_param("iis", $user_id, $product_id, $size);
        $cart_check->execute();
        $cart_row = $cart_check->get_result()->fetch_assoc();
        $cart_check->close();
        if ($cart_row) $cart_qty = (int)$cart_row['quantity'];

        if ($quantity + $cart_qty > $available) {
            header("Location: homepage.php?error=insufficient_stock&available=" . $available);
            exit();
        }

        $stmt = $conn->prepare("CALL add_to_cart(?, ?, ?, ?)");
        $stmt->bind_param("iisi", $user_id, $product_id, $size, $quantity);

        if ($stmt->execute()) {
            header("Location: cart.php?success=1");
        } else {
            header("Location: homepage.php?error=cart_add_failed");
        }
        $stmt->close();
        $conn->close();
    } else {
        header("Location: login.php");
    }
?>




