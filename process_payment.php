<?php
require_once 'includes/db.php';
    require_once 'includes/session_init.php';
    require_once 'includes/csrf.php';
    require_once 'includes/no_cache_headers.php';
    require_once 'audit_log.php';
    require_once 'includes/input_validation.php';

    // Check if the user is logged in and has the correct role
    if (!isset($_SESSION["role"]) || $_SESSION["role"] != "Customer") {
        header("Location: login.php"); // Redirect to login page if not a customer
        exit();
    }

    $user_id = $_SESSION['user_id'];
    if (!validate_csrf()) {
        $_SESSION['order_error'] = 'Security check failed. Please try again.';
        header("Location: cart.php");
        exit();
    }
    $shipping_address = isset($_POST['shipping_address']) ? sanitize_string($_POST['shipping_address'], 255) : null;

    // Recompute total from cart (never trust POST total_price)
    $stmt_cart = $conn->prepare("
        SELECT p.price, c.quantity FROM cart c
        JOIN product p ON c.product_id = p.product_id
        WHERE c.user_id = ?
    ");
    $stmt_cart->bind_param("i", $user_id);
    $stmt_cart->execute();
    $cart_result = $stmt_cart->get_result();
    $subtotal = 0.00;
    while ($row = $cart_result->fetch_assoc()) {
        $subtotal += (float)$row['price'] * (int)$row['quantity'];
    }
    $stmt_cart->close();
    $shipping_fee = 250;
    $total_price = round($subtotal + $shipping_fee, 2);

    // Validate: cart must have items and shipping address required
    if ($total_price <= $shipping_fee) {
        $_SESSION['order_error'] = 'Your cart is empty. Add items before checkout.';
        header("Location: cart.php");
        exit();
    }
    if (empty($shipping_address)) {
        $_SESSION['order_error'] = 'Shipping address is required.';
        header("Location: cart.php");
        exit();
    }

    // Prepare and call the stored procedure, handling stock errors gracefully
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $stmt = $conn->prepare("CALL place_order(?, ?, ?)");
        $stmt->bind_param("ids", $user_id, $total_price, $shipping_address);

        $stmt->execute();

        // Get the last inserted order ID
        $stmt_last = $conn->prepare("SELECT MAX(order_id) AS last_order_id FROM `order` WHERE user_id = ?");
        $stmt_last->bind_param("i", $user_id);
        $stmt_last->execute();
        $result = $stmt_last->get_result();
        $row = $result->fetch_assoc();
        $last_order_id = $row['last_order_id'];
        $stmt_last->close();

        // Log transaction (Customer order placement)
        log_audit($conn, $user_id, 'Customer', 'ORDER_PLACE', 'order', $last_order_id, "total={$total_price}", 'TRANSACTION');

        // Store important info in session for confirmation page
        $_SESSION['last_order_id'] = $last_order_id;
        $_SESSION['last_order_total_php'] = $total_price;
        $_SESSION['last_order_shipping_address'] = $shipping_address;

        // Redirect
        header("Location: confirmation.php");
        exit();
    } catch (mysqli_sql_exception $e) {
        // Handle custom "Insufficient stock" signal from stored procedure
        if (strpos($e->getMessage(), 'Insufficient stock for one or more items') !== false) {
            $_SESSION['order_error'] = 'Insufficient stock for one or more items in your cart. Please update quantities or remove out-of-stock items.';
            header("Location: cart.php");
            exit();
        }

        // For other DB errors, rethrow so they can be logged/diagnosed
        throw $e;
    } finally {
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
        $conn->close();
    }
?>

