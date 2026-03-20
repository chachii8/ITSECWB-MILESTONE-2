<?php
    require_once 'includes/session_init.php';
    require_once 'includes/session_timeout.php';
    require_once 'includes/csrf.php';
    enforce_session_timeout(true);
    require_once 'includes/db.php';

    if (!isset($_SESSION["user_id"]) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(["status" => "error", "message" => "Unauthorized"]);
        exit();
    }

    if (!isset($_POST['csrf_token']) || !validate_csrf()) {
        echo json_encode(["status" => "error", "message" => "Security check failed"]);
        exit();
    }

    $user_id = $_SESSION["user_id"];
    require_once 'includes/input_validation.php';
    $product_id = validate_int_range($_POST["product_id"] ?? 0, 1, 999999);
    if ($product_id === false) {
        echo json_encode(["status" => "error", "message" => "Invalid product"]);
        exit();
    }

    // Check if already favorited
    $check_query = "SELECT * FROM favorites WHERE user_id = ? AND product_id = ?";
    $stmt = mysqli_prepare($conn, $check_query);
    mysqli_stmt_bind_param($stmt, "ii", $user_id, $product_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if (mysqli_num_rows($result) > 0) {
        // Remove from favorites
        $delete = mysqli_prepare($conn, "DELETE FROM favorites WHERE user_id = ? AND product_id = ?");
        mysqli_stmt_bind_param($delete, "ii", $user_id, $product_id);
        mysqli_stmt_execute($delete);
        echo json_encode(["status" => "removed"]);
    } else {
        // Add to favorites
        $insert = mysqli_prepare($conn, "INSERT INTO favorites (user_id, product_id) VALUES (?, ?)");
        mysqli_stmt_bind_param($insert, "ii", $user_id, $product_id);
        mysqli_stmt_execute($insert);
        echo json_encode(["status" => "added"]);
    }
?>
