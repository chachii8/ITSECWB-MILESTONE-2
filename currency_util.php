<?php
function convertCurrency($price_in_php, $conn) {
    $selectedCurrency = isset($_SESSION['currency']) ? $_SESSION['currency'] : 'PHP';

    // Prepare and execute query
    $stmt = $conn->prepare("SELECT conversion_rate FROM currency WHERE code = ?");
    $stmt->bind_param("s", $selectedCurrency);
    $stmt->execute();
    $result = $stmt->get_result();

    // Default to 1 (PHP) if not found
    $rate = 1;
    if ($row = $result->fetch_assoc()) {
        $rate = $row['conversion_rate'];
    }

    $stmt->close();

    // Convert the price
    $converted = $price_in_php / $rate;

    // Format the result with symbol
    switch ($selectedCurrency) {
        case 'USD':
            return '$' . number_format($converted, 2);
        case 'KRW':
            return '₩' . number_format($converted, 0);
        default:
            return '₱' . number_format($price_in_php, 2);
    }
}

function getConvertedPrice($price_php, $conn) {
    $session_currency = isset($_SESSION['currency']) ? $_SESSION['currency'] : 'PHP';

    $rate_sql = "SELECT conversion_rate FROM currency WHERE code = ?";
    $rate_stmt = mysqli_prepare($conn, $rate_sql);
    mysqli_stmt_bind_param($rate_stmt, 's', $session_currency);
    mysqli_stmt_execute($rate_stmt);
    $rate_result = mysqli_stmt_get_result($rate_stmt);
    $rate_row = mysqli_fetch_assoc($rate_result);

    $rate = isset($rate_row['conversion_rate']) ? $rate_row['conversion_rate'] : 1.0;

    return round($price_php / $rate, 2);
}

function getCurrencySymbol() {
    $symbols = [
        'PHP' => '₱',
        'USD' => '$',
        'KRW' => '₩'
    ];
    $currency = isset($_SESSION['currency']) ? $_SESSION['currency'] : 'PHP';
    return isset($symbols[$currency]) ? $symbols[$currency] : '₱';
}
?>
