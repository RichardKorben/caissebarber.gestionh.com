<?php
declare(strict_types=1);

namespace BarberPay;

use RuntimeException;

/** Le catalogue a changé entre l'affichage et l'encaissement (prix différent, article supprimé) : HTTP 409. */
final class ConflitCatalogue extends RuntimeException
{
}
