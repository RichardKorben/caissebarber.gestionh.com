<?php
/**
 * BarberPay — téléchargement des exports (gérant). Chaque export est inscrit au journal des événements.
 *   ?quoi=livre|journal|ereporting|ereporting-json|fiscal&debut=AAAA-MM-JJ&fin=AAAA-MM-JJ (fin incluse)
 *   ?quoi=archive&id=<clôture>
 */
declare(strict_types=1);

use BarberPay\Archives;
use BarberPay\Exports;
use BarberPay\Flash;
use BarberPay\Journal;

require __DIR__ . '/../app/admin.php';

$quoi = (string) ($_GET['quoi'] ?? '');
$exports = new Exports($con, $tenant);
$prefixe = 'barberpay-' . $tenant->sousDomaine . '-';

$envoyer = static function (string $nom, string $type, string $contenu): never {
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($contenu));
    header('Cache-Control: private, no-store');
    echo $contenu;
    exit;
};

try {
    if ($quoi === 'archive') {
        $archives = new Archives($con, $tenant, $journal);
        $archive = $archives->pour((int) ($_GET['id'] ?? 0), (int) $utilisateur['id']);
        $chemin = $archives->fichierVerifie($archive);
        $journal->ecrire(Journal::EXPORT, ['export' => 'archive', 'fichier' => $archive['fichier']], (int) $utilisateur['id']);
        $envoyer($prefixe . $archive['fichier'], 'application/zip', (string) file_get_contents($chemin));
    }

    $saisieFin = (string) ($_GET['fin'] ?? '');
    [$debut, $fin] = Exports::periode((string) ($_GET['debut'] ?? ''), $saisieFin);
    $suffixe = $debut . '_' . $saisieFin;
    $journal->ecrire(Journal::EXPORT, ['export' => $quoi, 'du' => $debut, 'au' => $saisieFin], (int) $utilisateur['id']);

    switch ($quoi) {
        case 'livre':
            $lignes = [['Date', 'Heure', 'N° ticket', 'Nature', 'Détail', 'Montant TTC (€)', 'Règlement']];
            foreach ($exports->livreRecettes($debut, $fin) as $l) {
                $lignes[] = [$l['date'], $l['heure'], $l['numero'], $l['nature'], $l['detail'], Exports::nombre($l['montant']), $l['reglement']];
            }
            $envoyer($prefixe . 'livre-recettes-' . $suffixe . '.csv', 'text/csv; charset=UTF-8', Exports::csv($lignes));

        case 'journal':
            $lignes = [['Date', 'Nature', 'Libellé', 'HT (€)', 'TVA (€)', 'TTC (€)']];
            foreach ($exports->journalVentes($debut, $fin) as $l) {
                $lignes[] = [$l['date'], $l['nature'], $l['libelle'], Exports::nombre($l['ht']), Exports::nombre($l['tva']), Exports::nombre($l['ttc'])];
            }
            $envoyer($prefixe . 'journal-ventes-' . $suffixe . '.csv', 'text/csv; charset=UTF-8', Exports::csv($lignes));

        case 'ereporting':
            $lignes = [['Date', 'Régime', 'Taux TVA (%)', 'Base HT (€)', 'TVA (€)', 'TTC (€)', 'Nombre de tickets']];
            foreach ($exports->eReporting($debut, $fin) as $l) {
                $lignes[] = [$l['date'], $l['regime'], number_format($l['taux'] / 100, 2, ',', ''), Exports::nombre($l['base_ht']), Exports::nombre($l['tva']), Exports::nombre($l['ttc']), $l['operations']];
            }
            $envoyer($prefixe . 'e-reporting-' . $suffixe . '.csv', 'text/csv; charset=UTF-8', Exports::csv($lignes));

        case 'ereporting-json':
            $p = (new BarberPay\Parametres($con, $tenant))->charger();
            $json = json_encode([
                'format' => 'barberpay-e-reporting-b2c/provisoire',
                'avertissement' => 'Format provisoire, à aligner sur les spécifications DGFiP des flux de transactions B2C.',
                'emetteur' => ['siret' => $p['siret'], 'denomination' => $p['raison_sociale'] ?: $p['nom_salon'], 'tva_intracommunautaire' => $p['tva_intracom']],
                'periode' => ['debut' => $debut, 'fin_incluse' => $saisieFin],
                'montants' => 'centimes d\'euro ; taux en centièmes de pour cent',
                'totaux_journaliers' => $exports->eReporting($debut, $fin),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $envoyer($prefixe . 'e-reporting-' . $suffixe . '.json', 'application/json; charset=UTF-8', (string) $json);

        case 'fiscal':
            $chemin = $exports->zipFiscal($debut, $fin, 'Export pour contrôle fiscal');
            $contenu = (string) file_get_contents($chemin);
            @unlink($chemin);
            $envoyer($prefixe . 'controle-fiscal-' . $suffixe . '.zip', 'application/zip', $contenu);

        default:
            throw new InvalidArgumentException('Export inconnu.');
    }
} catch (InvalidArgumentException | RuntimeException $erreur) {
    Flash::erreur($erreur->getMessage());
    admin_rediriger($quoi === 'archive' ? '/admin/conformite.php' : '/admin/exports.php');
}
