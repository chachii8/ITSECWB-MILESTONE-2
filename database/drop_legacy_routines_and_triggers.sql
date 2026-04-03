-- Run once on localhost if you upgraded PHP code but still have old MySQL routines/triggers
-- (avoids duplicate order_log / stock_log rows). Not needed for fresh imports of current sole_source.sql.

DROP PROCEDURE IF EXISTS `add_to_cart`;
DROP PROCEDURE IF EXISTS `DeleteUserAndOrders`;
DROP PROCEDURE IF EXISTS `place_order`;
DROP PROCEDURE IF EXISTS `SubmitReview`;

DROP TRIGGER IF EXISTS `after_order_insert`;
DROP TRIGGER IF EXISTS `after_price_update`;
DROP TRIGGER IF EXISTS `after_product_delete`;
DROP TRIGGER IF EXISTS `after_product_stock_decrease`;
DROP TRIGGER IF EXISTS `LogDeletedUser`;
DROP TRIGGER IF EXISTS `after_user_registration`;
