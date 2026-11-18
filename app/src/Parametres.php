<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;

/** Paramètres du salon (table parametres, une seule ligne). */
final class Parametres
{
    public const FRANCHISE = 'franchise';
    public const ASSUJETTI = 'assujetti';
    /** Choix proposés pour le verrouillage automatique de la caisse (0 = jamais). */
    public const DELAIS_VERROUILLAGE = [0, 2, 5, 10, 15, 30, 60];

    public function __construct(private readonly mysqli $con, private readonly Tenant $tenant)
    {
    }

    /** @return array<string, mixed> */
    public function charger(): array
    {
        $ligne = $this->con->query('SELECT * FROM parametres WHERE id = 1')->fetch_assoc();
        if ($ligne) {
            return $ligne;
        }
        // Premier accès : ligne créée avec le nom du salon du registre et les valeurs par défaut.
        $nom = $this->tenant->nomSalon;
        $requete = $this->con->prepare('INSERT IGNORE INTO parametres (id, nom_salon) VALUES (1, ?)');
        $requete->bind_param('s', $nom);
        $requete->execute();
        return $this->con->query('SELECT * FROM parametres WHERE id = 1')->fetch_assoc();
    }

    /**
     * Valide et enregistre le formulaire « Salon ».
     *
     * @param array<string, mixed> $saisie
     * @return array<string, string> erreurs par champ (vide si enregistré)
     */
    public function enregistrer(array $saisie, mysqli $commune): array
    {
        $texte = static fn (string $cle, int $max): ?string =>
            ($v = trim((string) ($saisie[$cle] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $v = [
            'nom_salon' => $texte('nom_salon', 120),
            'raison_sociale' => $texte('raison_sociale', 160),
            'adresse' => $texte('adresse', 190),
            'code_postal' => $texte('code_postal', 10),
            'ville' => $texte('ville', 100),
            'telephone' => $texte('telephone', 30),
            'siret' => ($s = preg_replace('/\s+/', '', (string) ($saisie['siret'] ?? ''))) === '' ? null : $s,
            'tva_intracom' => ($t = strtoupper((string) preg_replace('/\s+/', '', (string) ($saisie['tva_intracom'] ?? '')))) === '' ? null : $t,
            'regime_tva' => (string) ($saisie['regime_tva'] ?? ''),
            'mention_ticket' => $texte('mention_ticket', 255),
            'animation_poteau' => empty($saisie['animation_poteau']) ? 0 : 1,
            'verrouillage_minutes' => (int) ($saisie['verrouillage_minutes'] ?? 10),
        ];

        $erreurs = [];
        if ($v['nom_salon'] === null) {
            $erreurs['nom_salon'] = 'Le nom du salon est obligatoire.';
        }
        if ($v['code_postal'] !== null && !preg_match('/^\d{5}$/', $v['code_postal'])) {
            $erreurs['code_postal'] = 'Code postal : 5 chiffres.';
        }
        if ($v['siret'] !== null && !self::siretValide($v['siret'])) {
            $erreurs['siret'] = 'SIRET invalide : 14 chiffres (vérifiez la saisie).';
        }
        if ($v['tva_intracom'] !== null && !preg_match('/^FR[0-9A-Z]{2}\d{9}$/', $v['tva_intracom'])) {
            $erreurs['tva_intracom'] = 'Numéro de TVA : FR suivi de 11 caractères.';
        }
        if (!in_array($v['verrouillage_minutes'], self::DELAIS_VERROUILLAGE, true)) {
            $erreurs['verrouillage_minutes'] = 'Délai invalide.';
        }
        if (!in_array($v['regime_tva'], [self::FRANCHISE, self::ASSUJETTI], true)) {
            $erreurs['regime_tva'] = 'Choisissez le régime de TVA.';
        }

        $seuils = [];
        foreach (['seuil_services', 'seuil_services_majore', 'seuil_ventes', 'seuil_ventes_majore'] as $cle) {
            $montant = Montant::analyser((string) ($saisie[$cle] ?? ''));
            if ($montant === null || $montant === 0) {
                $erreurs[$cle] = 'Montant invalide.';
            }
            $seuils[$cle] = (int) $montant;
        }
        if (!isset($erreurs['seuil_services']) && !isset($erreurs['seuil_services_majore']) && $seuils['seuil_services_majore'] < $seuils['seuil_services']) {
            $erreurs['seuil_services_majore'] = 'Le seuil majoré doit être supérieur ou égal au seuil.';
        }
        if (!isset($erreurs['seuil_ventes']) && !isset($erreurs['seuil_ventes_majore']) && $seuils['seuil_ventes_majore'] < $seuils['seuil_ventes']) {
            $erreurs['seuil_ventes_majore'] = 'Le seuil majoré doit être supérieur ou égal au seuil.';
        }
        if ($erreurs) {
            return $erreurs;
        }

        $this->charger();
        // TODO étape 3 : tracer le changement de régime dans le journal des événements et ne l'appliquer
        // qu'après la clôture du jour.
        $requete = $this->con->prepare(
            'UPDATE parametres SET nom_salon = ?, raison_sociale = ?, adresse = ?, code_postal = ?, ville = ?, telephone = ?,
                siret = ?, tva_intracom = ?, regime_tva = ?, seuil_services = ?, seuil_services_majore = ?,
                seuil_ventes = ?, seuil_ventes_majore = ?, mention_ticket = ?, animation_poteau = ?, verrouillage_minutes = ?
             WHERE id = 1'
        );
        $requete->bind_param(
            'sssssssssiiiisii',
            $v['nom_salon'], $v['raison_sociale'], $v['adresse'], $v['code_postal'], $v['ville'], $v['telephone'],
            $v['siret'], $v['tva_intracom'], $v['regime_tva'],
            $seuils['seuil_services'], $seuils['seuil_services_majore'], $seuils['seuil_ventes'], $seuils['seuil_ventes_majore'],
            $v['mention_ticket'], $v['animation_poteau'], $v['verrouillage_minutes']
        );
        $requete->execute();

        // Le nom affiché sur la page de connexion vient du registre commun : on le garde synchronisé.
        $maj = $commune->prepare('UPDATE barberpay_comptes SET nom_salon = ? WHERE id = ?');
        $id = $this->tenant->id;
        $maj->bind_param('si', $v['nom_salon'], $id);
        $maj->execute();
        return [];
    }

    /** SIRET : 14 chiffres et clé de Luhn (sauf La Poste, SIREN 356000000, qui a sa propre règle). */
    public static function siretValide(string $siret): bool
    {
        if (!preg_match('/^\d{14}$/', $siret)) {
            return false;
        }
        if (str_starts_with($siret, '356000000')) {
            return array_sum(str_split($siret)) % 5 === 0;
        }
        $somme = 0;
        foreach (str_split(strrev($siret)) as $rang => $chiffre) {
            $n = (int) $chiffre * ($rang % 2 === 1 ? 2 : 1);
            $somme += $n > 9 ? $n - 9 : $n;
        }
        return $somme % 10 === 0;
    }
}
