<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.5.0: TUDOR API tests in the back office (Advanced parameters > TUDOR API tests).
 */
function upgrade_module_0_5_0(Tudorsync $module): bool
{
    return $module->installTabs();
}
