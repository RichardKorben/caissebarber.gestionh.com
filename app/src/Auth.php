<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;

/**
 * Connexion du gérant (e-mail + mot de passe).
 *
 * Mots de passe hachés avec password_hash(). Blocage après trop d'échecs, compté dans la base du barber
 * (table connexions_echecs) : 5 échecs pour un même e-mail ou 10 pour une même adresse IP en 15 minutes.
 */
final class Auth
{
    private const MAX_ECHECS_EMAIL = 5;
    private const MAX_ECHECS_IP = 10;
    private const FENETRE = 15 * 60;

    public function __construct(private readonly mysqli $con)
    {
    }

    /**
     * @return array{ok: bool, erreur?: string}
     */
    public function connecter(string $email, string $motDePasse, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $motDePasse === '') {
            return ['ok' => false, 'erreur' => 'Saisissez votre e-mail et votre mot de passe.'];
        }

        $cleEmail = 'email:' . hash('sha256', $email);
        $cleIp = 'ip:' . hash('sha256', $ip);
        $this->purgerEchecs();
        $echecsEmail = $this->compterEchecs($cleEmail);
        $echecsIp = $this->compterEchecs($cleIp);
        if ($echecsEmail >= self::MAX_ECHECS_EMAIL || $echecsIp >= self::MAX_ECHECS_IP) {
            return ['ok' => false, 'erreur' => 'Trop d\'essais. Réessayez dans 15 minutes.'];
        }

        $requete = $this->con->prepare(
            "SELECT id, mot_de_passe FROM utilisateurs
             WHERE email = ? AND role = 'gerant' AND actif = 1 AND mot_de_passe IS NOT NULL LIMIT 1"
        );
        $requete->bind_param('s', $email);
        $requete->execute();
        $compte = $requete->get_result()->fetch_assoc();

        // Toujours exécuter password_verify, même sans compte, pour ne pas révéler par le temps de réponse
        // si l'e-mail existe.
        // (empreinte factice d'un mot de passe aléatoire jetable)
        $empreinte = $compte['mot_de_passe'] ?? '$2y$10$wlN6cUU.qvNlGFZ9uuDwo.biBWgrTL5po3dLhXikyW6RhyhJANIVW';
        $valide = password_verify($motDePasse, (string) $empreinte) && $compte !== null;

        if (!$valide) {
            $this->noterEchec($cleEmail);
            $this->noterEchec($cleIp);
            return ['ok' => false, 'erreur' => 'E-mail ou mot de passe incorrect.'];
        }

        if (password_needs_rehash((string) $compte['mot_de_passe'], PASSWORD_DEFAULT)) {
            $nouveau = password_hash($motDePasse, PASSWORD_DEFAULT);
            $maj = $this->con->prepare('UPDATE utilisateurs SET mot_de_passe = ? WHERE id = ?');
            $id = (int) $compte['id'];
            $maj->bind_param('si', $nouveau, $id);
            $maj->execute();
        }

        $this->effacerEchecs($cleEmail);
        Session::renouveler();
        $_SESSION['utilisateur_id'] = (int) $compte['id'];
        // Le gérant vient de prouver son identité : la caisse s'ouvre à son nom.
        $_SESSION['operateur_id'] = (int) $compte['id'];
        $_SESSION['operateur_actif_le'] = time();
        return ['ok' => true];
    }

    /** @return array<string, mixed>|null */
    public function utilisateur(): ?array
    {
        $id = (int) ($_SESSION['utilisateur_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $requete = $this->con->prepare(
            'SELECT id, prenom, nom, email, role FROM utilisateurs WHERE id = ? AND actif = 1 LIMIT 1'
        );
        $requete->bind_param('i', $id);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }

    public static function deconnecter(): void
    {
        Session::detruire();
    }

    private function compterEchecs(string $cle): int
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

    private function purgerEchecs(): void
    {
        $avant = date('Y-m-d H:i:s', time() - 24 * 3600);
        $requete = $this->con->prepare('DELETE FROM connexions_echecs WHERE date_echec < ?');
        $requete->bind_param('s', $avant);
        $requete->execute();
    }
}
