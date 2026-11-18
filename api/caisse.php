<?php
/**
 * BarberPay — actions de la caisse (JSON).
 *
 *   GET  ?action=etat                 opérateur, nombre de tickets en attente
 *   POST ?action=deverrouiller        {membreId, pin}
 *   POST ?action=verrouiller
 *   POST ?action=activite             repousse le verrouillage automatique
 *   GET  ?action=attente_liste
 *   POST ?action=attente_mettre       {ticket}
 *   POST ?action=attente_reprendre    {id}
 *   POST ?action=attente_supprimer    {id}
 */
declare(strict_types=1);

use BarberPay\TicketsAttente;

require __DIR__ . '/../app/api.php';

$action = (string) ($_GET['action'] ?? '');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$attente = new TicketsAttente($con);

$exigerPost = static function () use ($methode): void {
    if ($methode !== 'POST') {
        api_erreur('Méthode non autorisée.', 405);
    }
};

// Le déverrouillage et l'état sont possibles caisse verrouillée ; tout le reste exige un opérateur.
if ($action === 'deverrouiller') {
    $exigerPost();
    $corps = api_corps();
    $resultat = $caisse->deverrouiller((int) ($corps['membreId'] ?? 0), (string) ($corps['pin'] ?? ''));
    api_reponse($resultat, $resultat['ok'] ? 200 : 403);
}
if ($action === 'etat') {
    api_reponse(['ok' => true, 'operateur' => $caisse->operateur(), 'verrouillage' => $caisse->verrouillageActif(), 'attente' => $attente->nombre()]);
}

$operateur = $caisse->operateur();
if ($operateur === null) {
    api_erreur('Caisse verrouillée.', 423);
}

try {
    switch ($action) {
        case 'verrouiller':
            $exigerPost();
            $caisse->verrouiller();
            api_reponse(['ok' => true]);

        case 'activite':
            $exigerPost();
            $caisse->toucher();
            api_reponse(['ok' => true]);

        case 'attente_liste':
            api_reponse(['ok' => true, 'tickets' => $attente->lister()]);

        case 'attente_mettre':
            $exigerPost();
            $caisse->toucher();
            $id = $attente->mettre(api_corps()['ticket'] ?? null, (int) $operateur['id']);
            api_reponse(['ok' => true, 'id' => $id, 'attente' => $attente->nombre()]);

        case 'attente_reprendre':
            $exigerPost();
            $caisse->toucher();
            $ticket = $attente->reprendre((int) (api_corps()['id'] ?? 0));
            if ($ticket === null) {
                api_erreur('Ce ticket n\'est plus en attente (déjà repris sur un autre poste ?).', 404);
            }
            api_reponse(['ok' => true, 'ticket' => $ticket, 'attente' => $attente->nombre()]);

        case 'attente_supprimer':
            $exigerPost();
            $caisse->toucher();
            $attente->supprimer((int) (api_corps()['id'] ?? 0));
            api_reponse(['ok' => true, 'attente' => $attente->nombre()]);

        default:
            api_erreur('Action inconnue.', 404);
    }
} catch (InvalidArgumentException $erreur) {
    api_erreur($erreur->getMessage());
}
