<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 0.4.0: TUDOR's monthly report in the back office (Orders > TUDOR report): figures typed in per
 * month (tudorsync_report_period) and generated Excel files (tudorsync_report_file).
 */
function upgrade_module_0_4_0(Tudorsync $module): bool
{
    return Tudorsync\Prestashop\Report\MonthlyReport::installSchema() && $module->installTabs();
}
