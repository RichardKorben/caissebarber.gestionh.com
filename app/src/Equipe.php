<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use mysqli_sql_exception;

/**
 * Équipe du salon : gérant(s) et barbiers.
 *
 * Le gérant se connecte avec e-mail et mot de passe (admin, à distance). Tout le monde, gérant compris,
 * peut avoir un code PIN pour déverrouiller la caisse (étape 2).
 */
final class Equipe
{
    public const GERANT = 'gerant';
    public const BARBIER = 'barbier';
    public const ROLES = [self::GERANT => 'Gérant', self::BARBIER => 'Barbier'];

    /** Couleurs proposées pour repérer chaque barbier (pas de rouge/bleu : réservés au poteau). */
    public const COULEURS = ['#C9A44C', '#8FBF7F', '#E08A5E', '#B48EDB', '#5FB8B2', '#D97FA6', '#A0A0A0', '#E3D26F'];

    public function __construct(
        private readonly mysqli $con,
        private readonly ?Journal $journal = null,
        private readonly ?int $operateurId = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lister(): array
    {
        return $this->con->query(
            "SELECT id, prenom, nom, email, role, couleur, actif, pin IS NOT NULL AS a_pin
             FROM utilisateurs ORDER BY actif DESC, role = 'barbier', prenom"
        )->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function trouver(int $id): ?array
    {
        $requete = $this->con->prepare(
            'SELECT id, prenom, nom, email, role, couleur, actif, pin IS NOT NULL AS a_pin FROM utilisateurs WHERE id = ?'
        );
        $requete->bind_param('i', $id);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }

    /**
     * Crée (id null) ou modifie un membre. Le PIN est facultatif en modification (vide = inchangé).
     * Un nouveau gérant doit avoir un e-mail et un mot de passe.
     *
     * @param array<string, mixed> $saisie
     * @return array{erreurs: array<string, string>, id: int|null}
     */
    public function enregistrer(array $saisie, ?int $id = null): array
    {
        $existant = $id === null ? null : $this->trouver($id);
        if ($id !== null && $existant === null) {
            return ['erreurs' => ['prenom' => 'Membre introuvable.'], 'id' => null];
        }
        $erreurs = [];

        $prenom = trim((string) ($saisie['prenom'] ?? ''));
        $nom = trim((string) ($saisie['nom'] ?? '')) ?: null;
        if ($prenom === '' || mb_strlen($prenom) > 60) {
            $erreurs['prenom'] = 'Prénom obligatoire (60 caractères maximum).';
        }
        if ($nom !== null && mb_strlen($nom) > 60) {
            $erreurs['nom'] = '60 caractères maximum.';
        }

        $role = (string) ($saisie['role'] ?? self::BARBIER);
        if (!isset(self::ROLES[$role])) {
            $erreurs['role'] = 'Rôle invalide.';
        }
        if ($existant !== null && $existant['role'] === self::GERANT && $role !== self::GERANT && $this->nombreGerantsActifs() <= 1) {
            $erreurs['role'] = 'Il faut au moins un gérant actif.';
        }

        $couleur = (string) ($saisie['couleur'] ?? '');
        if (!in_array($couleur, self::COULEURS, true)) {
            $couleur = self::COULEURS[0];
        }

        $email = null;
        $motDePasse = null;
        if ($role === self::GERANT) {
            $email = mb_strtolower(trim((string) ($saisie['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $erreurs['email'] = 'Un gérant doit avoir un e-mail valide (pour se connecter).';
            }
            $saisieMdp = (string) ($saisie['mot_de_passe'] ?? '');
            $aDejaMdp = $existant !== null && $existant['role'] === self::GERANT;
            if ($saisieMdp !== '' || !$aDejaMdp) {
                if (mb_strlen($saisieMdp) < 10) {
                    $erreurs['mot_de_passe'] = 'Mot de passe : 10 caractères minimum.';
                } else {
                    $motDePasse = password_hash($saisieMdp, PASSWORD_DEFAULT);
                }
            }
        }

        $pin = null;
        $saisiePin = trim((string) ($saisie['pin'] ?? ''));
        if ($saisiePin !== '') {
            if (!preg_match('/^\d{4,6}$/', $saisiePin)) {
                $erreurs['pin'] = 'Code PIN : 4 à 6 chiffres.';
            } elseif (preg_match('/^(\d)\1+$/', $saisiePin) || str_contains('0123456789', $saisiePin) || str_contains('9876543210', $saisiePin)) {
                $erreurs['pin'] = 'Code trop simple (évitez 1111, 1234…).';
            } else {
                $pin = password_hash($saisiePin, PASSWORD_DEFAULT);
            }
        }

        if ($erreurs) {
            return ['erreurs' => $erreurs, 'id' => $id];
        }

        try {
            if ($id === null) {
                $requete = $this->con->prepare(
                    'INSERT INTO utilisateurs (prenom, nom, email, mot_de_passe, pin, role, couleur) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $requete->bind_param('sssssss', $prenom, $nom, $email, $motDePasse, $pin, $role, $couleur);
                $requete->execute();
                $id = (int) $this->con->insert_id;
            } else {
                // Barbier : pas d'e-mail ni de mot de passe (il ne se connecte qu'avec son PIN sur la caisse).
                $requete = $this->con->prepare(
                    'UPDATE utilisateurs SET prenom = ?, nom = ?, role = ?, couleur = ?, email = ?,
                        mot_de_passe = IF(? = \'' . self::GERANT . '\', COALESCE(?, mot_de_passe), NULL),
                        pin = COALESCE(?, pin)
                     WHERE id = ?'
                );
                $requete->bind_param('ssssssssi', $prenom, $nom, $role, $couleur, $email, $role, $motDePasse, $pin, $id);
                $requete->execute();
            }
        } catch (mysqli_sql_exception $erreur) {
            if ($erreur->getCode() === 1062) {
                return ['erreurs' => ['email' => 'Cet e-mail est déjà utilisé par un autre membre.'], 'id' => $id];
            }
            throw $erreur;
        }
        $this->journal?->ecrire(Journal::EQUIPE_MODIFIEE, [
            'membre' => $id, 'prenom' => $prenom, 'role' => $role,
            'action' => $existant === null ? 'ajout' : 'modification',
            'pin_change' => $pin !== null, 'mot_de_passe_change' => $motDePasse !== null,
        ], $this->operateurId);
        return ['erreurs' => [], 'id' => $id];
    }

    /** @return string|null message d'erreur, null si fait */
    public function basculerActif(int $id, int $idConnecte): ?string
    {
        $membre = $this->trouver($id);
        if ($membre === null) {
            return 'Membre introuvable.';
        }
        if ($membre['actif'] && $id === $idConnecte) {
            return 'Vous ne pouvez pas désactiver votre propre compte.';
        }
        if ($membre['actif'] && $membre['role'] === self::GERANT && $this->nombreGerantsActifs() <= 1) {
            return 'Il faut au moins un gérant actif.';
        }
        $requete = $this->con->prepare('UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        $this->journal?->ecrire(Journal::EQUIPE_MODIFIEE, ['membre' => $id, 'prenom' => $membre['prenom'], 'action' => $membre['actif'] ? 'desactivation' : 'activation'], $this->operateurId);
        return null;
    }

    public function supprimerPin(int $id): void
    {
        $requete = $this->con->prepare('UPDATE utilisateurs SET pin = NULL WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        $this->journal?->ecrire(Journal::EQUIPE_MODIFIEE, ['membre' => $id, 'action' => 'pin_supprime'], $this->operateurId);
    }

    private function nombreGerantsActifs(): int
    {
        return (int) $this->con->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'gerant' AND actif = 1")->fetch_row()[0];
    }
}
