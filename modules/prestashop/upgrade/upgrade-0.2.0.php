<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.2.0: tudorwatch.com attribution cookie (views/js/utm-capture.js), loaded from the
 * actionFrontControllerSetMedia hook.
 */
function upgrade_module_0_2_0(Tudorsync $module): bool
{
    return $module->registerHook('actionFrontControllerSetMedia');
}
