<?php
/**
 * Replaces MySQL stored procedures and triggers on hosts that disallow them (e.g. InfinityFree).
 */

/**
 * add_to_cart equivalent.
 */
function sql_add_to_cart(mysqli $conn, int $user_id, int $product_id, string $size, int $quantity): bool {
    $stmt = $conn->prepare("SELECT cart_id FROM cart WHERE user_id = ? AND product_id = ? AND size = ? LIMIT 1");
    $stmt->bind_param("iis", $user_id, $product_id, $size);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $cart_id = (int) $row['cart_id'];
        $upd = $conn->prepare("UPDATE cart SET quantity = quantity + ? WHERE cart_id = ?");
        $upd->bind_param("ii", $quantity, $cart_id);
        $ok = $upd->execute();
        $upd->close();
        return $ok;
    }

    $ins = $conn->prepare("INSERT INTO cart (user_id, product_id, size, quantity) VALUES (?, ?, ?, ?)");
    $ins->bind_param("iisi", $user_id, $product_id, $size, $quantity);
    $ok = $ins->execute();
    $ins->close();
    return $ok;
}

/**
 * place_order equivalent. Returns new order_id.
 *
 * @throws mysqli_sql_exception When stock is insufficient (message contains expected substring).
 */
function sql_place_order(mysqli $conn, int $user_id, float $total_price, string $shipping_address): int {
    $conn->begin_transaction();
    $rolled_back = false;
    try {
        $stmt = $conn->prepare("
            INSERT INTO `order` (user_id, total_price, order_status, order_date, shipping_address)
            VALUES (?, ?, 'Pending', CURDATE(), ?)
        ");
        $stmt->bind_param("ids", $user_id, $total_price, $shipping_address);
        $stmt->execute();
        $last_order_id = (int) $conn->insert_id;
        $stmt->close();

        $logOrder = $conn->prepare(
            "INSERT INTO order_log (order_id, user_id, total_price) VALUES (?, ?, ?)"
        );
        $logOrder->bind_param("iid", $last_order_id, $user_id, $total_price);
        $logOrder->execute();
        $logOrder->close();

        $stmt = $conn->prepare("
            INSERT INTO order_details (order_id, product_id, quantity, product_price)
            SELECT ?, c.product_id, c.quantity,
                (SELECT p.price FROM product p WHERE p.product_id = c.product_id)
            FROM cart c WHERE c.user_id = ?
        ");
        $stmt->bind_param("ii", $last_order_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT product_id, quantity, size FROM cart WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $cart_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($cart_items as $item) {
            $pid = (int) $item['product_id'];
            $qty = (int) $item['quantity'];
            $sz = (string) $item['size'];

            $chk = $conn->prepare(
                "SELECT 1 FROM product_size WHERE product_id = ? AND size = ? AND stock >= ? LIMIT 1"
            );
            $chk->bind_param("isi", $pid, $sz, $qty);
            $chk->execute();
            if ($chk->get_result()->num_rows === 0) {
                $chk->close();
                $conn->rollback();
                $rolled_back = true;
                throw new mysqli_sql_exception('Insufficient stock for one or more items.', 45000);
            }
            $chk->close();

            $upd = $conn->prepare("UPDATE product_size SET stock = stock - ? WHERE product_id = ? AND size = ?");
            $upd->bind_param("iis", $qty, $pid, $sz);
            $upd->execute();
            $upd->close();
            sql_log_product_stock_decrease($conn, $pid, $qty);
        }

        $del = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $del->bind_param("i", $user_id);
        $del->execute();
        $del->close();

        $conn->commit();
        return $last_order_id;
    } catch (Throwable $e) {
        if (!$rolled_back) {
            @$conn->rollback();
        }
        throw $e;
    }
}

/**
 * DeleteUserAndOrders equivalent (extends original with cart/favorites/review cleanup for FK safety).
 */
function sql_delete_user_and_orders(mysqli $conn, int $user_id): bool {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "DELETE od FROM order_details od INNER JOIN `order` o ON od.order_id = o.order_id WHERE o.user_id = ?"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM `order` WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM favorites WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM review WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM user_registration_log WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $info = $conn->prepare("SELECT name, `role` FROM `user` WHERE user_id = ? LIMIT 1");
        $info->bind_param("i", $user_id);
        $info->execute();
        $urow = $info->get_result()->fetch_assoc();
        $info->close();
        if ($urow) {
            $ud = $conn->prepare(
                "INSERT INTO user_deletion_log (user_id, name, role, deleted_at) VALUES (?, ?, ?, NOW())"
            );
            $nm = (string) $urow['name'];
            $rl = (string) $urow['role'];
            $ud->bind_param("iss", $user_id, $nm, $rl);
            $ud->execute();
            $ud->close();
        }

        $stmt = $conn->prepare("DELETE FROM `user` WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        @$conn->rollback();
        return false;
    }
}

/** Trigger: after_user_registration */
function sql_log_user_registration(mysqli $conn, int $user_id): void {
    $s = $conn->prepare("INSERT INTO user_registration_log (user_id) VALUES (?)");
    if (!$s) {
        return;
    }
    $s->bind_param("i", $user_id);
    @$s->execute();
    $s->close();
}

/** Trigger: after_price_update */
function sql_log_price_change(
    mysqli $conn,
    int $product_id,
    string $product_name,
    float $old_price,
    float $new_price
): void {
    if (abs($old_price - $new_price) < 0.0001) {
        return;
    }
    $s = $conn->prepare(
        "INSERT INTO price_change_log (product_id, product_name, old_price, new_price) VALUES (?, ?, ?, ?)"
    );
    if (!$s) {
        return;
    }
    $s->bind_param("isdd", $product_id, $product_name, $old_price, $new_price);
    @$s->execute();
    $s->close();
}

/** Trigger: after_product_delete (call before removing product row) */
function sql_log_product_deletion(mysqli $conn, int $product_id, string $product_name): void {
    $s = $conn->prepare("INSERT INTO product_deletion_log (product_id, product_name) VALUES (?, ?)");
    if (!$s) {
        return;
    }
    $s->bind_param("is", $product_id, $product_name);
    @$s->execute();
    $s->close();
}

/** Trigger: after_product_stock_decrease */
function sql_log_product_stock_decrease(mysqli $conn, int $product_id, int $decrease_amount): void {
    if ($decrease_amount <= 0) {
        return;
    }
    $s = $conn->prepare("INSERT INTO product_stock_log (product_id, stock_change) VALUES (?, ?)");
    if (!$s) {
        return;
    }
    $s->bind_param("ii", $product_id, $decrease_amount);
    @$s->execute();
    $s->close();
}
