<?php

declare(strict_types=1);

namespace Tudorsync\Core\Domain;

/**
 * TUDOR issues separate credentials and endpoints for each of these two environments.
 * Every store must finish testing in Staging before TUDOR coordinates Production activation.
 *
 * See doc/Primeros pasos del programa de comercio electrónico de TUDOR...pdf, "Entornos técnicos".
 */
enum Environment: string
{
    case Staging = 'staging';
    case Production = 'production';
}
