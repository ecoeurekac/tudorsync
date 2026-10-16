<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.3.0: tudorwatch.com referral saved on the cart and the order, model code of each order line
 * (tudorsync_cart_attribution, tudorsync_order_attribution, tudorsync_order_line), and the
 * referral on the back-office order page.
 */
function upgrade_module_0_3_0(Tudorsync $module): bool
{
    return Tudorsync\Prestashop\Attribution\OrderAttribution::installSchema()
        && $module->registerHook(Tudorsync::HOOKS);
}
