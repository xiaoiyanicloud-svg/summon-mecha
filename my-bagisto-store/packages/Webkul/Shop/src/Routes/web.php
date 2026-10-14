<?php

/**
 * Store front routes.
 */
require 'store-front-routes.php';

/**
 * Customer routes. All routes related to customer
 * in storefront will be placed here.
 */
require 'customer-routes.php';

/**
 * Checkout routes. All routes related to checkout like
 * cart, coupons, etc will be placed here.
 */
require 'checkout-routes.php';

/**
 * Developer portal routes (v3.4 步骤 9) —— 独立门户，与前台/后台互不干扰。
 */
require 'dev.php';
