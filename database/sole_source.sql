-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 12, 2026 at 03:30 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Aiven / managed MySQL: allow CREATE TABLE before ALTER ADD PRIMARY KEY (phpMyAdmin dump style)
SET SESSION sql_require_primary_key = 0;

--
-- Database: `sole_source`
--

DELIMITER $$
--
-- Procedures
--
DROP PROCEDURE IF EXISTS `add_to_cart`$$
DROP PROCEDURE IF EXISTS `DeleteUserAndOrders`$$
DROP PROCEDURE IF EXISTS `place_order`$$
DROP PROCEDURE IF EXISTS `SubmitReview`$$

CREATE PROCEDURE `add_to_cart` (IN `p_user_id` INT, IN `p_product_id` INT, IN `p_size` VARCHAR(10), IN `p_quantity` INT)   BEGIN
    DECLARE existing_id INT;

    SELECT cart_id INTO existing_id
    FROM cart
    WHERE user_id = p_user_id AND product_id = p_product_id AND size = p_size;

    IF existing_id IS NOT NULL THEN
        -- Update the existing quantity
        UPDATE cart
        SET quantity = quantity + p_quantity
        WHERE cart_id = existing_id;
    ELSE
        -- Insert new entry
        INSERT INTO cart (user_id, product_id, size, quantity)
        VALUES (p_user_id, p_product_id, p_size, p_quantity);
    END IF;
END$$

CREATE PROCEDURE `DeleteUserAndOrders` (IN `p_user_id` INT)   BEGIN
    -- Delete all orders associated with the user
    DELETE FROM order_details WHERE order_id IN (SELECT order_id FROM `order` WHERE user_id = p_user_id);
    DELETE FROM `order` WHERE user_id = p_user_id;

    -- Delete the user
    DELETE FROM user WHERE user_id = p_user_id;
END$$

CREATE PROCEDURE `place_order` (IN `p_user_id` INT, IN `p_total_price` DECIMAL(7,2), IN `p_shipping_address` VARCHAR(255))   BEGIN
    DECLARE last_order_id INT;
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_product_id INT;
    DECLARE v_quantity INT;
    DECLARE v_size VARCHAR(10);

    -- Cursor for iterating over the user's cart
    DECLARE cart_cursor CURSOR FOR
        SELECT product_id, quantity, size FROM cart WHERE user_id = p_user_id;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

    START TRANSACTION;

    -- Step 1: Insert the order
    INSERT INTO `order` (
        user_id,
        total_price,
        order_status,
        order_date,
        shipping_address
    )
    VALUES (
        p_user_id,
        p_total_price,
        'Pending',
        CURDATE(),
        p_shipping_address
    );

    SET last_order_id = LAST_INSERT_ID();

    -- Step 2: Insert into order_details
    INSERT INTO order_details (
        order_id,
        product_id,
        quantity,
        product_price
    )
    SELECT
        last_order_id,
        product_id,
        quantity,
        (
            SELECT price FROM product WHERE product.product_id = cart.product_id
        ) AS product_price
    FROM cart
    WHERE user_id = p_user_id;

    -- Step 3: Open cursor to deduct stock
    OPEN cart_cursor;

    read_loop: LOOP
        FETCH cart_cursor INTO v_product_id, v_quantity, v_size;

        IF done THEN
            LEAVE read_loop;
        END IF;

        -- Step 4: Check if enough stock exists
        IF EXISTS (
            SELECT 1 FROM product_size
            WHERE product_id = v_product_id AND size = v_size AND stock >= v_quantity
        ) THEN
            -- Step 5: Deduct stock
            UPDATE product_size
            SET stock = stock - v_quantity
            WHERE product_id = v_product_id AND size = v_size;
        ELSE
            -- Step 6: Rollback if insufficient stock
            ROLLBACK;
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Insufficient stock for one or more items.';
        END IF;
    END LOOP;

    CLOSE cart_cursor;

    -- Step 7: Clear cart
    DELETE FROM cart WHERE user_id = p_user_id;

    COMMIT;
END$$

CREATE PROCEDURE `SubmitReview` (IN `p_user_id` INT, IN `p_order_id` INT, IN `p_product_id` INT, IN `p_rating` INT, IN `p_review` TEXT, IN `p_date` DATE)   BEGIN
    DECLARE review_count INT;

    SELECT COUNT(*) INTO review_count
    FROM review
    WHERE user_id = p_user_id AND order_id = p_order_id AND product_id = p_product_id;

    IF review_count > 0 THEN
        -- Review already exists, update it
        UPDATE review
        SET rating = p_rating,
            review = p_review,
            date_submitted = p_date
        WHERE user_id = p_user_id AND order_id = p_order_id AND product_id = p_product_id;
    ELSE
        -- Insert new review
        INSERT INTO review (user_id, order_id, product_id, rating, review, date_submitted)
        VALUES (p_user_id, p_order_id, p_product_id, p_rating, p_review, p_date);
    END IF;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admin_access_tokens`
--

CREATE TABLE `admin_access_tokens` (
  `id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`id`, `user_id`, `role`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`) VALUES
(1, 8, 'Customer', 'LOGIN_SUCCESS', 'user', 8, 'email=mike_malone@gmail.com', '::1', '2026-02-12 09:10:56'),
(2, 28, 'Customer', 'LOGIN_SUCCESS', 'user', 28, 'email=stephen.curry30@gmail.com', '::1', '2026-02-12 09:28:16'),
(3, 15, 'Admin', 'MFA_ENABLED', 'user', 15, NULL, '::1', '2026-02-12 09:29:56'),
(4, 15, 'Admin', 'PRODUCT_UPDATE', 'product', 200, 'name=Nike Zoom Vomero 5 SE, price=8895.00', '::1', '2026-02-12 09:34:57'),
(5, 15, 'Admin', 'PRODUCT_STOCK_UPDATE', 'product', 200, 'size=9.0, stock=100', '::1', '2026-02-12 09:35:12'),
(6, 15, 'Admin', 'PRODUCT_UPDATE', 'product', 200, 'name=Nike Zoom Vomero 5 SE, price=8895.00', '::1', '2026-02-12 09:39:05'),
(7, NULL, NULL, 'LOGIN_INVALID_ADMIN', 'user', NULL, 'email=Bronny_james@gmail.com', '::1', '2026-02-12 09:49:03'),
(8, NULL, NULL, 'LOGIN_INVALID', 'user', NULL, 'email=Luka_doncic@gmail.com', '::1', '2026-02-12 09:50:05'),
(9, NULL, NULL, 'LOGIN_INVALID', 'user', NULL, 'email=Luka_doncic@gmail.com', '::1', '2026-02-12 09:50:16'),
(10, NULL, NULL, 'LOGIN_LOCKOUT_SET', 'user', NULL, 'email=Luka_doncic@gmail.com, level=1, duration=1 hour', '::1', '2026-02-12 09:50:25'),
(11, NULL, NULL, 'LOGIN_INVALID_ADMIN', 'user', NULL, 'email=Bronny_james@gmail.com', '::1', '2026-02-12 09:52:38'),
(12, NULL, NULL, 'LOGIN_INVALID_ADMIN', 'user', NULL, 'email=Bronny_james@gmail.com', '::1', '2026-02-12 09:52:51'),
(13, NULL, NULL, 'LOGIN_INVALID_ADMIN', 'user', NULL, 'email=Bronny_james@gmail.com', '::1', '2026-02-12 09:53:00'),
(14, NULL, NULL, 'LOGIN_NO_ACCOUNT', 'user', NULL, 'email=Franz_wagner@gmail.com', '::1', '2026-02-12 09:53:54'),
(15, NULL, NULL, 'LOGIN_NO_ACCOUNT', 'user', NULL, 'email=Anthony_davis@gmail.com', '::1', '2026-02-12 09:54:05'),
(16, NULL, NULL, 'LOGIN_INVALID', 'user', NULL, 'email=stephen.curry30@gmail.com', '::1', '2026-02-12 09:54:28'),
(17, NULL, NULL, 'LOGIN_INVALID', 'user', NULL, 'email=stephen.curry30@gmail.com', '::1', '2026-02-12 09:54:37'),
(18, NULL, NULL, 'LOGIN_LOCKOUT_SET', 'user', NULL, 'email=stephen.curry30@gmail.com, level=1, duration=1 hour', '::1', '2026-02-12 09:54:46'),
(19, 15, 'Admin', 'LOGIN_SUCCESS_MFA', 'user', 15, NULL, '::1', '2026-02-12 09:55:35'),
(20, 8, 'Customer', 'LOGIN_SUCCESS', 'user', 8, 'email=mike_malone@gmail.com', '::1', '2026-02-12 13:00:18'),
(21, 8, 'Customer', 'LOGIN_SUCCESS', 'user', 8, 'email=mike_malone@gmail.com', '::1', '2026-02-12 13:04:05'),
(22, 8, 'Customer', 'LOGIN_SUCCESS', 'user', 8, 'email=mike_malone@gmail.com', '::1', '2026-02-12 13:18:51'),
(23, 30, 'Customer', 'LOGIN_SUCCESS', 'user', 30, 'email=genggeng@yahoo.com', '::1', '2026-02-12 13:26:58'),
(24, 15, 'Admin', 'LOGIN_SUCCESS_MFA', 'user', 15, NULL, '::1', '2026-02-12 13:35:23'),
(25, 15, 'Admin', 'PRODUCT_UPDATE', 'product', 200, 'name=Nike Zoom Vomero 5 SE, price=8896', '::1', '2026-02-12 14:17:21'),
(26, 15, 'Admin', 'USER_DELETE', 'user', 31, NULL, '::1', '2026-02-12 14:25:15'),
(27, 32, 'Customer', 'LOGIN_SUCCESS', 'user', 32, 'email=jayson.tatum0@gmail.com', '::1', '2026-02-12 14:28:44');

-- --------------------------------------------------------

--
-- Table structure for table `brand`
--

CREATE TABLE `brand` (
  `brand_id` int(11) NOT NULL,
  `name` enum('Nike','Adidas','On Cloud','Asics','New Balance') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `brand`
--

INSERT INTO `brand` (`brand_id`, `name`) VALUES
(100, 'Nike'),
(101, 'Adidas'),
(102, 'On Cloud'),
(103, 'Asics'),
(104, 'New Balance');

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `cart_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` decimal(3,1) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cart`
--

INSERT INTO `cart` (`cart_id`, `user_id`, `product_id`, `size`, `quantity`) VALUES
(907, 7, 202, 6.0, 1);

-- --------------------------------------------------------

--
-- Table structure for table `currency`
--

CREATE TABLE `currency` (
  `currency_id` int(11) NOT NULL,
  `code` enum('PHP','USD','KRW') DEFAULT NULL,
  `conversion_rate` decimal(5,3) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `currency`
--

INSERT INTO `currency` (`currency_id`, `code`, `conversion_rate`) VALUES
(300, 'PHP', 1.000),
(301, 'USD', 55.000),
(302, 'KRW', 0.041);

-- --------------------------------------------------------

--
-- Table structure for table `favorites`
--

CREATE TABLE `favorites` (
  `favorite_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `date_added` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `favorites`
--

INSERT INTO `favorites` (`favorite_id`, `user_id`, `product_id`, `date_added`) VALUES
(800, 7, 202, '2025-07-27 15:39:36');

-- --------------------------------------------------------

--
-- Table structure for table `login_security`
--

CREATE TABLE `login_security` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `lockout_level` int(11) NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `last_attempt` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_security`
--

INSERT INTO `login_security` (`id`, `email`, `ip_address`, `failed_count`, `lockout_level`, `lockout_until`, `last_attempt`, `updated_at`) VALUES
(1, 'mike_malone@gmail.com', '::1', 0, 0, NULL, '2026-02-12 21:18:51', '2026-02-12 21:18:51'),
(2, 'stephen.curry30@gmail.com', '::1', 0, 1, '2026-02-12 18:54:46', '2026-02-12 17:54:46', '2026-02-12 17:54:46'),
(3, 'Main_admin@gmail.com', '::1', 0, 0, NULL, '2026-02-12 21:35:17', '2026-02-12 21:35:17'),
(5, 'Luka_doncic@gmail.com', '::1', 0, 1, '2026-02-12 18:50:25', '2026-02-12 17:50:25', '2026-02-12 17:50:25'),
(8, 'Bronny_james@gmail.com', '::1', 0, 1, '2026-02-12 18:53:00', '2026-02-12 17:53:00', '2026-02-12 17:53:00'),
(11, 'Franz_wagner@gmail.com', '::1', 1, 0, NULL, '2026-02-12 17:53:54', '2026-02-12 17:53:54'),
(12, 'Anthony_davis@gmail.com', '::1', 1, 0, NULL, '2026-02-12 17:54:05', '2026-02-12 17:54:05'),
(21, 'genggeng@yahoo.com', '::1', 0, 0, NULL, '2026-02-12 21:26:58', '2026-02-12 21:26:58'),
(23, 'jayson.tatum0@gmail.com', '::1', 0, 0, NULL, '2026-02-12 22:28:44', '2026-02-12 22:28:44');

-- --------------------------------------------------------

--
-- Table structure for table `order`
--

CREATE TABLE `order` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_price` decimal(7,2) DEFAULT NULL,
  `order_status` enum('Pending','Shipped','Delivered','Cancelled') DEFAULT NULL,
  `order_date` date DEFAULT NULL,
  `shipping_address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order`
--

INSERT INTO `order` (`order_id`, `user_id`, `total_price`, `order_status`, `order_date`, `shipping_address`) VALUES
(600, 7, 3145.00, 'Delivered', '2025-07-24', '567 GHI st. Philippines'),
(601, 8, 7479.85, 'Shipped', '2025-07-24', '501 Telabastagan CSFP'),
(602, 7, 6480.00, 'Shipped', '2025-07-25', '567 GHI st. Philippines'),
(603, 7, 3250.00, 'Shipped', '2025-07-27', '567 GHI st. Philippines'),
(608, 8, 9145.00, 'Delivered', '2025-07-28', '501 Telabastagan CSFP'),
(609, 12, 10200.00, 'Shipped', '2025-07-28', '77 Dallas Texas'),
(610, 12, 9145.00, 'Shipped', '2025-07-28', '77 Dallas Texas'),
(611, 8, 8145.00, 'Shipped', '2025-07-28', '501 Telabastagan CSFP');

--
-- Triggers `order`
--
DELIMITER $$
CREATE TRIGGER `after_order_insert` AFTER INSERT ON `order` FOR EACH ROW BEGIN
    INSERT INTO order_log (order_id, user_id, total_price)
    VALUES (NEW.order_id, NEW.user_id, NEW.total_price);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `order_details`
--

CREATE TABLE `order_details` (
  `order_details_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `product_price` decimal(7,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_details`
--

INSERT INTO `order_details` (`order_details_id`, `order_id`, `product_id`, `quantity`, `product_price`) VALUES
(700, 600, 202, 1, 2895.00),
(701, 601, 212, 1, 7229.85),
(702, 602, 206, 1, 6230.00),
(703, 603, 205, 1, 3000.00),
(708, 608, 200, 1, 8895.00),
(709, 609, 227, 1, 9950.00),
(710, 610, 200, 1, 8895.00),
(711, 611, 201, 1, 7895.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_log`
--

CREATE TABLE `order_log` (
  `log_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `log_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_log`
--

INSERT INTO `order_log` (`log_id`, `order_id`, `user_id`, `total_price`, `log_time`) VALUES
(1, 603, 7, 3250.00, '2025-07-27 14:44:36'),
(6, 608, 8, 9145.00, '2025-07-27 18:22:22'),
(7, 609, 12, 10200.00, '2025-07-28 12:43:29'),
(8, 610, 12, 9145.00, '2025-07-28 12:54:30'),
(9, 611, 8, 8145.00, '2025-07-28 12:55:02');

-- --------------------------------------------------------

--
-- Table structure for table `price_change_log`
--

CREATE TABLE `price_change_log` (
  `log_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `old_price` decimal(10,2) NOT NULL,
  `new_price` decimal(10,2) NOT NULL,
  `change_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `price_change_log`
--

INSERT INTO `price_change_log` (`log_id`, `product_id`, `product_name`, `old_price`, `new_price`, `change_time`) VALUES
(6, 201, 'Nike Pegasus 41 LV8', 7895.00, 7995.00, '2025-07-28 08:26:52'),
(7, 201, 'Nike Pegasus 41 LV8', 7995.00, 7895.00, '2025-07-28 08:27:16'),
(8, 200, 'Nike Zoom Vomero 5 VE', 8895.00, 9895.00, '2025-07-28 12:42:19'),
(9, 200, 'Nike Zoom Vomero 5 SE', 9895.00, 8895.00, '2025-07-28 12:42:29'),
(10, 203, 'Adizero Boston 13', 8000.00, 9000.00, '2025-07-28 12:52:41'),
(11, 203, 'Adizero Boston 25', 9000.00, 8000.00, '2025-07-28 12:52:59'),
(12, 200, 'Nike Zoom Vomero 5 SE', 8895.00, 8896.00, '2026-02-12 14:17:21');

-- --------------------------------------------------------

--
-- Table structure for table `product`
--

CREATE TABLE `product` (
  `product_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `price` decimal(7,2) DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `currency_id` int(11) NOT NULL DEFAULT 1,
  `image_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product`
--

INSERT INTO `product` (`product_id`, `name`, `brand_id`, `price`, `color`, `description`, `currency_id`, `image_url`) VALUES
(200, 'Nike Zoom Vomero 5 SE', 100, 8896.00, 'White', 'The Nike Zoom Vomero 5 SE is designed for comfort and style, featuring textiles, synthetic leather, and plastic accents in a layered design, making it your go-to running shoe.', 300, 'vomero.jpg'),
(201, 'Nike Pegasus 41 LV8', 100, 7895.00, 'University Gold, Soft Yellow, Black', 'The Pegasus 41 LV8 delivers responsive cushioning with dual Air Zoom units and a ReactX foam midsole for lighter, energizing rides, with engineered mesh for breathability.', 300, 'pegasus.jpg'),
(202, 'Nike Revolution 7', 100, 2895.00, 'Black', 'The Revolution 7 offers soft cushioning and excellent support for a smooth ride, combining style and comfort for everyday runners.', 300, 'revolution.jpg'),
(203, 'Adizero Boston 13', 101, 8000.00, 'Semi Flash Aqua, Zero Metallic, Lucid Lemon', 'The Adidas Adizero Boston 13 is engineered for speed with lightweight materials and responsive cushioning, designed for runners looking to enhance their performance.', 300, 'adizero.jpg'),
(204, 'Ultraboost 5 H.Koumori', 101, 10000.00, 'Beige, Grey One, Silver Pebble', 'The Ultraboost 5 offers unparalleled comfort and energy return, with a sock-like fit and a BOOST midsole for maximum cushioning on every step.', 300, 'ultraboost.jpg'),
(205, 'Runfalcon 5', 101, 3000.00, 'Core Black, Cloud White, Yellow', 'The Adidas Runfalcon 5 combines durability with lightweight design, offering breathable mesh and cushioned comfort for all-day wear.', 300, 'runfalcon.jpg'),
(206, 'Cloud 5', 102, 6230.00, 'Midnight, White', 'The On Cloud 5 provides an improved fit and comfort for runners, with a responsive midsole and signature cloud-tech cushioning.', 300, 'cloud5.jpg'),
(207, 'Cloud 6', 102, 8590.00, 'White', 'The On Cloud 6 features an enhanced fit for superior comfort and support, making it ideal for long-distance runners seeking lightweight performance.', 300, 'cloud6.jpg'),
(208, 'Cloud X 4', 102, 8590.00, 'Ivory, Salmon', 'The On Cloud X 4 is designed for agility and speed, featuring a flexible upper and responsive cushioning for athletes on the move.', 300, 'cloudx.jpg'),
(209, 'Gel-Nimbus 26', 103, 10490.00, 'Black, Cool Matcha', 'The Asics GEL-Nimbus 26 offers soft cushioning with FF BLAST™ PLUS ECO for lighter, eco-friendly support and a breathable upper for ultimate comfort.', 300, 'gelnimbus.jpg'),
(210, 'Magic Speed 4', 103, 10790.00, 'Huddle Yellow, Metropolis', 'The Asics Magic Speed 4 is designed for racing with carbon plate propulsion and energetic FF TURBO™ cushioning to maximize speed and performance.', 300, 'magicspeed.jpg'),
(211, 'Novablast 5', 103, 9090.00, 'White, Piedmont Grey', 'The Asics Novablast 5 provides energized cushioning with FF BLAST™ MAX for smoother landings and quicker toe-offs, designed for fast-paced runners.', 300, 'novablast.jpg'),
(212, 'FuelCell SuperComp SD-X', 104, 7229.85, 'Dragonfly, Black', 'The New Balance FuelCell SuperComp SD-X is a high-performance sprinting shoe with a carbon fiber plate for propulsion and exceptional energy return.', 300, 'fuelcell.jpg'),
(213, 'Fresh Foam X 1080v14', 104, 9319.89, 'Black, Black Metallic, Phantom', 'The Fresh Foam X 1080v14 combines reliable comfort and smooth transitions, with Fresh Foam X cushioning and a breathable upper for all-day performance.', 300, 'freshfoam.jpg'),
(227, 'Nike Lebron XX1 (21)', 100, 9950.00, 'Black & White', 'LEBRON 21!', 300, 'leb21.jpg'),
(229, 'New Balance 530 ', 104, 5500.00, 'Silver Metallic', 'nb 530!', 300, 'nb530.png'),
(230, 'Adidas Gazelle', 101, 4500.00, 'Off-White-Black-Gum', 'GAZELLE!', 300, 'adgaz.png');

--
-- Triggers `product`
--
DELIMITER $$
CREATE TRIGGER `after_price_update` AFTER UPDATE ON `product` FOR EACH ROW BEGIN
    -- Check if the price has changed
    IF OLD.price != NEW.price THEN
        -- Log the price change into the price_change_log table
        INSERT INTO `price_change_log` (product_id, product_name, old_price, new_price)
        VALUES (OLD.product_id, OLD.name, OLD.price, NEW.price);
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_product_delete` AFTER DELETE ON `product` FOR EACH ROW BEGIN
    -- Disable foreign key checks temporarily
    SET FOREIGN_KEY_CHECKS = 0;

    -- Insert the deleted product_id and product_name into the log table
    INSERT INTO `product_deletion_log` (product_id, product_name)
    VALUES (OLD.product_id, OLD.name);

    -- Re-enable foreign key checks
    SET FOREIGN_KEY_CHECKS = 1;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `product_deletion_log`
--

CREATE TABLE `product_deletion_log` (
  `log_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `deletion_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_deletion_log`
--

INSERT INTO `product_deletion_log` (`log_id`, `product_id`, `product_name`, `deletion_time`) VALUES
(1, 219, 'Nike Lebron XX1 (21)', '2025-07-27 14:54:55'),
(2, 221, 'New Balance 530 ', '2025-07-27 15:46:17'),
(3, 214, 'Tektrel', '2025-07-27 17:15:49'),
(4, 222, 'New Balance 530 ', '2025-07-27 17:16:43'),
(5, 220, 'Adidas Gazelle', '2025-07-27 17:21:13'),
(6, 223, 'New Balance 530 ', '2025-07-27 18:18:03'),
(7, 225, 'Nike Lebron XX1 (21)', '2025-07-27 18:22:57'),
(8, 224, 'New Balance 530 ', '2025-07-28 08:26:26'),
(9, 226, 'New Balance 530 ', '2025-07-28 08:40:15'),
(10, 228, 'Adidas Gazelle', '2025-07-28 12:47:10');

-- --------------------------------------------------------

--
-- Table structure for table `product_size`
--

CREATE TABLE `product_size` (
  `product_size_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `size` decimal(3,1) NOT NULL,
  `stock` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_size`
--

INSERT INTO `product_size` (`product_size_id`, `product_id`, `size`, `stock`) VALUES
(400, 200, 5.0, 9),
(401, 200, 6.0, 10),
(402, 200, 7.0, 10),
(403, 200, 8.0, 10),
(404, 200, 9.0, 100),
(405, 201, 5.0, 10),
(406, 201, 6.0, 10),
(407, 201, 7.0, 9),
(408, 201, 8.0, 10),
(409, 201, 9.0, 10),
(410, 202, 5.0, 10),
(411, 202, 6.0, 10),
(412, 202, 7.0, 10),
(413, 202, 8.0, 10),
(414, 202, 9.0, 9),
(415, 203, 5.0, 10),
(416, 203, 6.0, 9),
(417, 203, 7.0, 10),
(418, 203, 8.0, 10),
(419, 203, 9.0, 10),
(420, 204, 5.0, 10),
(421, 204, 6.0, 10),
(422, 204, 7.0, 10),
(423, 204, 8.0, 10),
(424, 204, 9.0, 10),
(425, 205, 5.0, 10),
(426, 205, 6.0, 9),
(427, 205, 7.0, 10),
(428, 205, 8.0, 10),
(429, 205, 9.0, 9),
(430, 206, 5.0, 10),
(431, 206, 6.0, 10),
(432, 206, 7.0, 9),
(433, 206, 8.0, 10),
(434, 206, 9.0, 10),
(435, 207, 5.0, 10),
(436, 207, 6.0, 10),
(437, 207, 7.0, 10),
(438, 207, 8.0, 10),
(439, 207, 9.0, 10),
(440, 208, 5.0, 10),
(441, 208, 6.0, 10),
(442, 208, 7.0, 10),
(443, 208, 8.0, 10),
(444, 208, 9.0, 10),
(445, 209, 5.0, 10),
(446, 209, 6.0, 10),
(447, 209, 7.0, 10),
(448, 209, 8.0, 10),
(449, 209, 9.0, 10),
(450, 210, 5.0, 10),
(451, 210, 6.0, 10),
(452, 210, 7.0, 10),
(453, 210, 8.0, 10),
(454, 210, 9.0, 10),
(455, 211, 5.0, 10),
(456, 211, 6.0, 10),
(457, 211, 7.0, 10),
(458, 211, 8.0, 10),
(459, 211, 9.0, 10),
(460, 212, 5.0, 0),
(461, 212, 6.0, 10),
(462, 212, 7.0, 10),
(463, 212, 8.0, 10),
(464, 212, 9.0, 9),
(465, 213, 5.0, 10),
(466, 213, 6.0, 10),
(467, 213, 7.0, 10),
(468, 213, 8.0, 10),
(469, 213, 9.0, 10),
(533, 229, 7.0, 10),
(534, 229, 8.0, 10),
(535, 229, 9.0, 10),
(536, 229, 10.0, 10),
(537, 230, 6.0, 10),
(538, 230, 7.0, 10),
(539, 230, 8.0, 10),
(540, 230, 9.0, 10),
(541, 230, 10.0, 10);

--
-- Triggers `product_size`
--
DELIMITER $$
CREATE TRIGGER `after_product_stock_decrease` AFTER UPDATE ON `product_size` FOR EACH ROW BEGIN
    IF OLD.stock > NEW.stock THEN
        INSERT INTO `product_stock_log` (product_id, stock_change)
        VALUES (NEW.product_id, OLD.stock - NEW.stock);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `product_stock_log`
--

CREATE TABLE `product_stock_log` (
  `log_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock_change` int(11) NOT NULL,
  `change_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_stock_log`
--

INSERT INTO `product_stock_log` (`log_id`, `product_id`, `stock_change`, `change_time`) VALUES
(1, 202, 1, '2025-07-27 14:50:24'),
(2, 220, 1, '2025-07-27 17:08:55'),
(3, 205, 1, '2025-07-27 17:44:33'),
(4, 200, 90, '2025-07-27 18:13:19'),
(5, 203, 1, '2025-07-27 18:15:34'),
(6, 200, 1, '2025-07-27 18:21:44'),
(7, 200, 1, '2025-07-27 18:22:22'),
(8, 201, 5, '2025-07-27 18:24:18'),
(9, 227, 1, '2025-07-28 12:43:29'),
(10, 200, 5, '2025-07-28 12:45:24'),
(11, 201, 10, '2025-07-28 12:46:02'),
(12, 200, 5, '2025-07-28 12:53:46'),
(13, 200, 1, '2025-07-28 12:54:30'),
(14, 201, 1, '2025-07-28 12:55:02');

-- --------------------------------------------------------

--
-- Table structure for table `review`
--

CREATE TABLE `review` (
  `review_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `rating` int(11) DEFAULT NULL,
  `review` varchar(255) DEFAULT NULL,
  `date_submitted` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `review`
--

INSERT INTO `review` (`review_id`, `user_id`, `order_id`, `product_id`, `rating`, `review`, `date_submitted`) VALUES
(501, 8, 608, 200, 3, 'good', '2026-02-12');

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('Admin','Staff','Customer') DEFAULT NULL,
  `date_joined` date DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `login_token` varchar(255) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`user_id`, `name`, `email`, `phone`, `password`, `role`, `date_joined`, `address`, `login_token`, `token_expires_at`, `profile_photo`) VALUES
(6, 'Angela Gellaco', 'staff_SOS01@gmail.com', NULL, '$2y$10$Ukje1VvD4EReEvtaMAyp1OupiEZLsiNbsqhcLh/aOaT0uxkk4Xa3S', 'Staff', '2025-07-16', '987 DEF st. Philippines', NULL, NULL, NULL),
(7, 'Luis Marasigan', 'John_pork@gmail.com', NULL, '$2y$10$d1vF7OECR1NAyn2Z.DK7kOuShDXMQiDB56ysy0y77zrP/WgqCNJ7O', 'Customer', '2025-07-16', '567 GHI st. Philippines', NULL, NULL, NULL),
(8, 'Mike Malone', 'mike_malone@gmail.com', NULL, '$2y$10$HHqWr/D6Ndt8RpR7Y/T3h.yK6Ix59TuFHwT7FZWH2X6FcUCDAXura', 'Customer', '2025-07-23', '501 Telabastagan CSFP', 'f84c63acc6c32ca49ccac7461ef212e77c602e24196cac1a41ea7e8b9570df96', '2026-02-26 17:10:56', 'profile_8_42dda14d320e6f13.jpeg'),
(9, 'Kyrie Irving', 'Kyrie_Irving@gmail.com', NULL, '$2y$10$LaHeHrNIZbBpNBrMyVDBc.vdTu66YUjqsmQ1C8lQQU8vLixZe.FbC', 'Admin', '2025-07-23', '301 Telabastagan CSFP', NULL, NULL, NULL),
(10, 'Lebron James', 'lebron_james@gmail.com', NULL, '$2y$10$FCq9Dijg3X5eD/VnZn4A7.Vy7X7UmtSrW5d2MHYJO3Zb3TsWAbqCe', 'Staff', '2025-07-23', '23 Lebron Street', NULL, NULL, NULL),
(12, 'Luka Doncic', 'Luka_doncic@gmail.com', NULL, '$2y$10$f4apa3WWa6MsUEmAwA02VeC8quGZSgo9IiABVmtGcTgDgOIuGxmlq', 'Customer', '2025-07-27', '77 Dallas Texas', NULL, NULL, NULL),
(13, 'Bronny James', 'Bronny_james@gmail.com', NULL, '$2y$10$efSL9thKPmKnQ8i9ZK9fhOfFvI7sj9wAA6lL95eGJ/9KUZTcQ/P1e', 'Admin', '2025-07-27', '09 Los Angeles ', NULL, NULL, NULL),
(15, 'Main Admin', 'Main_admin@gmail.com', NULL, '$2y$10$3VZaT4XtFp.k40H2iVElF.BH8XIocdZsXXFE2Cb5bZOW7KjdWZLDu', 'Admin', '2025-07-27', '20 Taft Avenue', 'eb644923581348055373bb8a65311871a998ed73b1257ffcc415b19742d23c5b', '2026-02-26 17:29:37', 'profile_15_612ae7aa4b5c5b11.jpg'),
(27, 'Customer20', 'Customer20@gmail.com', NULL, '$2y$10$hBMzBDBHrOvaGc2EXYuL1e14ZqTe5p3.xn2cxD5DfFIzRk4Ek5AsW', 'Customer', '2025-07-28', '75 Customer Street', NULL, NULL, NULL),
(28, 'Stephen Curry', 'stephen.curry30@gmail.com', '09123456789', '$2y$10$d0sUD6HZvdJHNMYRltV3tOJ6zzkq0qpbSzEEAZkebboO3WOylLG4y', 'Customer', '2026-02-12', '123 Oracle Arena Drive, Barangay Greenhills, San Juan City, Metro Manila, 1502', '45420bcfe842e9ce856f3ae02590ee0963f935ae8b52f00ad0620cb54dd686bc', '2026-02-26 17:28:16', NULL),
(29, 'Giannis Antetokounmpo', 'giannis.ante@example.com', NULL, '$2y$10$hVy72z8CTixo91mE5o2BbucK4JTyvXKteJ7bw9o98OqQJIQ5uyGma', 'Staff', '2026-02-12', '34 Deer District Rd, Milwaukee, WI', NULL, NULL, NULL),
(30, 'Nelle Chu', 'genggeng@yahoo.com', '09143215763', '$2y$10$f5TA/K00hO85Bqq9.XZHoe4v3ryUX.q9rmAIY0yqlMrQqTsstT0k6', 'Customer', '2026-02-12', '22 Fronce Malabon City', '9d5ca4f2a86ca99520c7f456f0c856d6fca31551edeeb453cf6321191f54fb1a', '2026-02-26 21:26:58', 'profile_6a742a1f57a7ee98.jpeg'),
(32, 'Jayson Tatum', 'jayson.tatum0@gmail.com', '09134567890', '$2y$10$A3fECYauSJpQNd4TmxIri.31ZR/nbpRW3HEFHAMayxOpBL8MpGMOi', 'Customer', '2026-02-12', '0 Celtics Way, Boston, MA', '5e119ffddb92cbfaa47b7b06be892579fe62e5962f7dca8a9a056366a8701ab1', '2026-02-26 22:28:44', 'profile_5b1a4e004bad627c.jpg');

--
-- Triggers `user`
--
DELIMITER $$
CREATE TRIGGER `LogDeletedUser` BEFORE DELETE ON `user` FOR EACH ROW BEGIN
    INSERT INTO user_deletion_log (user_id, name, role, deleted_at)
    VALUES (OLD.user_id, OLD.name, OLD.role, NOW());
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `after_user_registration` AFTER INSERT ON `user` FOR EACH ROW BEGIN
    INSERT INTO `user_registration_log` (`user_id`)
    VALUES (NEW.user_id);
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_deletion_log`
--

CREATE TABLE `user_deletion_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_deletion_log`
--

INSERT INTO `user_deletion_log` (`log_id`, `user_id`, `name`, `role`, `deleted_at`) VALUES
(1, 20, 'Testing', 'Customer', '2025-07-27 18:04:34'),
(2, 18, 'StaffTest', 'Staff', '2025-07-27 18:05:31'),
(3, 21, 'TestAdmin', 'Admin', '2025-07-27 18:08:14'),
(4, 23, 'Anthony Davis', 'Customer', '2025-07-27 18:23:21'),
(5, 22, 'TestAdmin', 'Admin', '2025-07-27 18:23:35'),
(6, 24, 'CustomerTest', 'Customer', '2025-07-28 12:56:59'),
(7, 25, 'AdminTest', 'Admin', '2025-07-28 12:57:13'),
(8, 26, 'TestStaff', 'Staff', '2025-07-28 12:57:21'),
(9, 31, 'Kevin Durant', 'Admin', '2026-02-12 14:25:15');

-- --------------------------------------------------------

--
-- Table structure for table `user_mfa`
--

CREATE TABLE `user_mfa` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `mfa_secret` varchar(255) NOT NULL,
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `backup_codes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_mfa`
--

INSERT INTO `user_mfa` (`id`, `user_id`, `mfa_secret`, `mfa_enabled`, `backup_codes`, `created_at`, `updated_at`) VALUES
(1, 15, 'ZAJ5UFUBG4PCPEY4A6MVCF26SXTS7FGJ', 1, NULL, '2026-02-12 09:29:56', '2026-02-12 09:29:56');

-- --------------------------------------------------------

--
-- Table structure for table `user_registration_log`
--

CREATE TABLE `user_registration_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `registration_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_registration_log`
--

INSERT INTO `user_registration_log` (`log_id`, `user_id`, `registration_time`) VALUES
(1, 13, '2025-07-27 14:46:42'),
(2, 14, '2025-07-27 14:48:03'),
(3, 15, '2025-07-27 17:29:27'),
(4, 16, '2025-07-27 17:49:43'),
(5, 17, '2025-07-27 17:52:33'),
(6, 18, '2025-07-27 17:59:28'),
(7, 19, '2025-07-27 18:00:40'),
(8, 20, '2025-07-27 18:01:58'),
(9, 21, '2025-07-27 18:08:06'),
(10, 22, '2025-07-27 18:14:48'),
(11, 23, '2025-07-27 18:15:15'),
(12, 24, '2025-07-28 12:38:35'),
(13, 25, '2025-07-28 12:39:36'),
(14, 26, '2025-07-28 12:40:17'),
(15, 27, '2025-07-28 12:58:07'),
(16, 28, '2026-02-12 09:28:05'),
(17, 29, '2026-02-12 09:56:16'),
(18, 30, '2026-02-12 13:26:48'),
(19, 31, '2026-02-12 14:24:58'),
(20, 32, '2026-02-12 14:28:36');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_access_tokens`
--
ALTER TABLE `admin_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `brand`
--
ALTER TABLE `brand`
  ADD PRIMARY KEY (`brand_id`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`cart_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `currency`
--
ALTER TABLE `currency`
  ADD PRIMARY KEY (`currency_id`);

--
-- Indexes for table `favorites`
--
ALTER TABLE `favorites`
  ADD PRIMARY KEY (`favorite_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `login_security`
--
ALTER TABLE `login_security`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_email_ip` (`email`,`ip_address`),
  ADD KEY `idx_lockout_until` (`lockout_until`);

--
-- Indexes for table `order`
--
ALTER TABLE `order`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_details`
--
ALTER TABLE `order_details`
  ADD PRIMARY KEY (`order_details_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `order_log`
--
ALTER TABLE `order_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `price_change_log`
--
ALTER TABLE `price_change_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `product`
--
ALTER TABLE `product`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `brand_id` (`brand_id`),
  ADD KEY `currency_id` (`currency_id`);

--
-- Indexes for table `product_deletion_log`
--
ALTER TABLE `product_deletion_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `product_size`
--
ALTER TABLE `product_size`
  ADD PRIMARY KEY (`product_size_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `product_stock_log`
--
ALTER TABLE `product_stock_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `review`
--
ALTER TABLE `review`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `user_deletion_log`
--
ALTER TABLE `user_deletion_log`
  ADD PRIMARY KEY (`log_id`);

--
-- Indexes for table `user_mfa`
--
ALTER TABLE `user_mfa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `user_registration_log`
--
ALTER TABLE `user_registration_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_access_tokens`
--
ALTER TABLE `admin_access_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `brand`
--
ALTER TABLE `brand`
  MODIFY `brand_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=105;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `cart_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=917;

--
-- AUTO_INCREMENT for table `currency`
--
ALTER TABLE `currency`
  MODIFY `currency_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=303;

--
-- AUTO_INCREMENT for table `favorites`
--
ALTER TABLE `favorites`
  MODIFY `favorite_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=801;

--
-- AUTO_INCREMENT for table `login_security`
--
ALTER TABLE `login_security`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `order`
--
ALTER TABLE `order`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=612;

--
-- AUTO_INCREMENT for table `order_details`
--
ALTER TABLE `order_details`
  MODIFY `order_details_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=712;

--
-- AUTO_INCREMENT for table `order_log`
--
ALTER TABLE `order_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `price_change_log`
--
ALTER TABLE `price_change_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `product`
--
ALTER TABLE `product`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=231;

--
-- AUTO_INCREMENT for table `product_deletion_log`
--
ALTER TABLE `product_deletion_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `product_size`
--
ALTER TABLE `product_size`
  MODIFY `product_size_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=542;

--
-- AUTO_INCREMENT for table `product_stock_log`
--
ALTER TABLE `product_stock_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `review`
--
ALTER TABLE `review`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=502;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `user_deletion_log`
--
ALTER TABLE `user_deletion_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `user_mfa`
--
ALTER TABLE `user_mfa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_registration_log`
--
ALTER TABLE `user_registration_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`),
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `favorites`
--
ALTER TABLE `favorites`
  ADD CONSTRAINT `favorite_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `favorite_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);

--
-- Constraints for table `order`
--
ALTER TABLE `order`
  ADD CONSTRAINT `order_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `order_details`
--
ALTER TABLE `order_details`
  ADD CONSTRAINT `order_details_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`),
  ADD CONSTRAINT `order_details_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);

--
-- Constraints for table `order_log`
--
ALTER TABLE `order_log`
  ADD CONSTRAINT `order_log_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `order` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `price_change_log`
--
ALTER TABLE `price_change_log`
  ADD CONSTRAINT `price_change_log_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `product`
--
ALTER TABLE `product`
  ADD CONSTRAINT `product_ibfk_1` FOREIGN KEY (`brand_id`) REFERENCES `brand` (`brand_id`),
  ADD CONSTRAINT `product_ibfk_2` FOREIGN KEY (`currency_id`) REFERENCES `currency` (`currency_id`);

--
-- Constraints for table `product_size`
--
ALTER TABLE `product_size`
  ADD CONSTRAINT `product_size_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `product` (`product_id`);

--
-- Constraints for table `user_mfa`
--
ALTER TABLE `user_mfa`
  ADD CONSTRAINT `user_mfa_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
