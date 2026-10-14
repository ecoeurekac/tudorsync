<?php

declare(strict_types=1);

namespace Tudorsync\Core\Sync;

/**
 * The connector returned no watch at all. Nothing was sent: a batch zeroes everything it doesn't
 * carry, so an empty one would take the retailer's whole catalog off TUDOR. An empty catalog is
 * almost never "everything sold out" but a fault (products without a model code, a store read
 * that fails, an import that breaks the products), so a person has to look at it.
 *
 * Extends NothingPassedReviewException so modules that already catch that one catch this one too
 * (getReceived() is 0, getExclusions() is empty). To show a specific message, catch this one first.
 */
final class EmptyCatalogException extends NothingPassedReviewException
{
    public function __construct()
    {
        parent::__construct(0, []);

        $this->message = 'El conector ha devuelto un catálogo vacío: no se envía nada a TUDOR para no retirar '
            . 'todo el catálogo. Revisa que los productos TUDOR tengan el código de modelo y que la lectura '
            . 'de la tienda funcione';
    }
}
