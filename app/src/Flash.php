<?php
declare(strict_types=1);

namespace BarberPay;

/** Messages affichés une fois après une redirection (« Service enregistré. »). */
final class Flash
{
    public static function succes(string $message): void
    {
        $_SESSION['flash'][] = ['succes', $message];
    }

    public static function erreur(string $message): void
    {
        $_SESSION['flash'][] = ['erreur', $message];
    }

    /** @return list<array{0:string,1:string}> */
    public static function lire(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}
