<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;

/**
 * Qui est à la caisse (l'« opérateur ») et verrouillage par code PIN.
 *
 * - Le poste est ouvert par le gérant (e-mail + mot de passe) : c'est la session.
 * - Dès qu'au moins un membre actif a un code PIN, la caisse se verrouille (après inactivité ou à la demande)
 *   et chacun la déverrouille avec son PIN. Sinon, l'opérateur est simplement le gérant connecté.
 * - L'administration n'est accessible que si l'opérateur est un gérant.
 */
final class Caisse
{
    private const MAX_ECHECS_PIN = 5;
    private const FENETRE = 15 * 60;

    private ?bool $verrouillageActif = null;

    public function __construct(
        private readonly mysqli $con,
        private readonly int $minutesInactivite,
        private readonly ?Journal $journal = null,
    ) {
    }

    public function verrouillageActif(): bool
    {
        return $this->verrouillageActif ??= (bool) $this->con->query(
            'SELECT 1 FROM utilisateurs WHERE actif = 1 AND pin IS NOT NULL LIMIT 1'
        )->fetch_row();
    }

    /** @return array<string, mixed>|null le membre à la caisse, null si verrouillée */
    public function operateur(): ?array
    {
        if (!$this->verrouillageActif()) {
            $id = (int) ($_SESSION['utilisateur_id'] ?? 0);
        } else {
            $id = (int) ($_SESSION['operateur_id'] ?? 0);
            $dernier = (int) ($_SESSION['operateur_actif_le'] ?? 0);
            if ($id > 0 && $this->minutesInactivite > 0 && time() - $dernier > $this->minutesInactivite * 60) {
                $this->verrouiller();
                return null;
            }
        }
        if ($id <= 0) {
            return null;
        }
        $requete = $this->con->prepare('SELECT id, prenom, nom, role, couleur FROM utilisateurs WHERE id = ? AND actif = 1');
        $requete->bind_param('i', $id);
        $requete->execute();
        $membre = $requete->get_result()->fetch_assoc() ?: null;
        if ($membre === null) {
            $this->verrouiller();
        }
        return $membre;
    }

    /** Note une activité (repousse le verrouillage automatique). */
    public function toucher(): void
    {
        if (isset($_SESSION['operateur_id'])) {
            $_SESSION['operateur_actif_le'] = time();
        }
    }

    public function definirOperateur(int $id): void
    {
        $_SESSION['operateur_id'] = $id;
        $_SESSION['operateur_actif_le'] = time();
    }

    public function verrouiller(): void
    {
        unset($_SESSION['operateur_id'], $_SESSION['operateur_actif_le']);
    }

    /** @return array{ok: bool, erreur?: string, operateur?: array<string, mixed>} */
    public function deverrouiller(int $membreId, string $pin): array
    {
        $cle = 'pin:' . $membreId;
        if ($this->echecsRecents($cle) >= self::MAX_ECHECS_PIN) {
            return ['ok' => false, 'erreur' => 'Trop d\'essais. Réessayez dans 15 minutes ou demandez au gérant.'];
        }
        $requete = $this->con->prepare('SELECT id, prenom, nom, role, couleur, pin FROM utilisateurs WHERE id = ? AND actif = 1');
        $requete->bind_param('i', $membreId);
        $requete->execute();
        $membre = $requete->get_result()->fetch_assoc();
        if (!$membre || $membre['pin'] === null || !preg_match('/^\d{4,6}$/', $pin) || !password_verify($pin, (string) $membre['pin'])) {
            $this->noterEchec($cle);
            return ['ok' => false, 'erreur' => 'Code incorrect.'];
        }
        $this->effacerEchecs($cle);
        $this->definirOperateur((int) $membre['id']);
        $this->journal?->ecrire(Journal::DEVERROUILLAGE, ['prenom' => $membre['prenom']], (int) $membre['id']);
        unset($membre['pin']);
        return ['ok' => true, 'operateur' => $membre];
    }

    /** @return list<array<string, mixed>> membres actifs (pour l'écran de verrouillage et le choix du barbier) */
    public function membres(): array
    {
        return $this->con->query(
            "SELECT id, prenom, nom, role, couleur, pin IS NOT NULL AS a_pin FROM utilisateurs
             WHERE actif = 1 ORDER BY role = 'barbier', prenom"
        )->fetch_all(MYSQLI_ASSOC);
    }

    private function echecsRecents(string $cle): int
    {
        $depuis = date('Y-m-d H:i:s', time() - self::FENETRE);
        $requete = $this->con->prepare('SELECT COUNT(*) FROM connexions_echecs WHERE cle = ? AND date_echec >= ?');
        $requete->bind_param('ss', $cle, $depuis);
        $requete->execute();
        return (int) $requete->get_result()->fetch_row()[0];
    }

    private function noterEchec(string $cle): void
    {
        $maintenant = date('Y-m-d H:i:s');
        $requete = $this->con->prepare('INSERT INTO connexions_echecs (cle, date_echec) VALUES (?, ?)');
        $requete->bind_param('ss', $cle, $maintenant);
        $requete->execute();
    }

    private function effacerEchecs(string $cle): void
    {
        $requete = $this->con->prepare('DELETE FROM connexions_echecs WHERE cle = ?');
        $requete->bind_param('s', $cle);
        $requete->execute();
    }
}
