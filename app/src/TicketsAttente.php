<?php
declare(strict_types=1);

namespace BarberPay;

use InvalidArgumentException;
use mysqli;

/**
 * Tickets mis en attente (bouton pause de la caisse).
 *
 * Ce ne sont pas des ventes : aucune valeur fiscale, pas de numéro. Le contenu envoyé par le navigateur est
 * revérifié et normalisé ici (le navigateur n'est jamais cru sur parole).
 */
final class TicketsAttente
{
    private const MAX_LIGNES = 100;
    private const MAX_EN_ATTENTE = 30;

    public function __construct(private readonly mysqli $con)
    {
    }

    /** @return list<array<string, mixed>> */
    public function lister(): array
    {
        return $this->con->query(
            'SELECT a.id, a.libelle, a.total_ttc, a.nb_articles, a.cree_le, u.prenom AS barbier, u.couleur
             FROM tickets_attente a LEFT JOIN utilisateurs u ON u.id = a.barbier_id
             ORDER BY a.cree_le'
        )->fetch_all(MYSQLI_ASSOC);
    }

    public function nombre(): int
    {
        return (int) $this->con->query('SELECT COUNT(*) FROM tickets_attente')->fetch_row()[0];
    }

    /**
     * @param mixed $contenu ticket envoyé par la caisse
     * @return int id du ticket mis en attente
     */
    public function mettre(mixed $contenu, int $operateurId): int
    {
        if ($this->nombre() >= self::MAX_EN_ATTENTE) {
            throw new InvalidArgumentException('Trop de tickets en attente (' . self::MAX_EN_ATTENTE . ' au maximum).');
        }
        $ticket = $this->normaliser($contenu);
        if (!$ticket['lignes']) {
            throw new InvalidArgumentException('Le ticket est vide.');
        }
        $calcul = Calcul::ticket(
            array_map(static fn (array $l): array => ['prix' => $l['prix'], 'quantite' => $l['quantite'], 'taux' => $l['taux'], 'remise' => $l['remise']], $ticket['lignes']),
            $ticket['remise']
        );
        $barbierId = $ticket['barbierId'];
        $prenom = null;
        if ($barbierId !== null) {
            $requete = $this->con->prepare('SELECT prenom FROM utilisateurs WHERE id = ?');
            $requete->bind_param('i', $barbierId);
            $requete->execute();
            $prenom = $requete->get_result()->fetch_row()[0] ?? null;
        }
        $libelle = ($prenom !== null ? $prenom . ' · ' : 'Ticket de ') . date('H:i');
        $json = json_encode($ticket, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $total = $calcul['total'];
        $nb = $calcul['nb_articles'];
        $requete = $this->con->prepare(
            'INSERT INTO tickets_attente (libelle, contenu, total_ttc, nb_articles, barbier_id, cree_par) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $requete->bind_param('ssiiii', $libelle, $json, $total, $nb, $barbierId, $operateurId);
        $requete->execute();
        return (int) $this->con->insert_id;
    }

    /**
     * Reprend un ticket : le renvoie et le retire de l'attente (en une transaction : deux postes ne peuvent
     * pas reprendre le même ticket).
     *
     * @return array<string, mixed>|null
     */
    public function reprendre(int $id): ?array
    {
        $this->con->begin_transaction();
        $requete = $this->con->prepare('SELECT contenu FROM tickets_attente WHERE id = ? FOR UPDATE');
        $requete->bind_param('i', $id);
        $requete->execute();
        $ligne = $requete->get_result()->fetch_row();
        if (!$ligne) {
            $this->con->rollback();
            return null;
        }
        $suppression = $this->con->prepare('DELETE FROM tickets_attente WHERE id = ?');
        $suppression->bind_param('i', $id);
        $suppression->execute();
        $this->con->commit();
        return json_decode((string) $ligne[0], true, 16, JSON_THROW_ON_ERROR);
    }

    public function supprimer(int $id): bool
    {
        $requete = $this->con->prepare('DELETE FROM tickets_attente WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        return $this->con->affected_rows > 0;
    }

    /**
     * Vérifie la structure et les limites. Les articles du catalogue ne sont pas re-tarifés ici :
     * ils le seront à l'encaissement (étape 3).
     *
     * @return array{barbierId: int|null, remise: array{type:string, valeur:int, motif:string}|null,
     *               lignes: list<array<string, mixed>>}
     */
    public function normaliser(mixed $contenu): array
    {
        if (!is_array($contenu) || !is_array($contenu['lignes'] ?? null)) {
            throw new InvalidArgumentException('Ticket illisible.');
        }
        if (count($contenu['lignes']) > self::MAX_LIGNES) {
            throw new InvalidArgumentException('Trop de lignes sur le ticket.');
        }
        $lignes = [];
        foreach ($contenu['lignes'] as $ligne) {
            if (!is_array($ligne)) {
                throw new InvalidArgumentException('Ligne illisible.');
            }
            $nom = trim((string) ($ligne['nom'] ?? ''));
            if ($nom === '' || mb_strlen($nom) > 80) {
                throw new InvalidArgumentException('Libellé de ligne invalide.');
            }
            $type = $ligne['type'] ?? '';
            if (!in_array($type, [Articles::SERVICE, Articles::PRODUIT], true)) {
                throw new InvalidArgumentException('Type de ligne invalide.');
            }
            $taux = $ligne['taux'] ?? null;
            if (!is_int($taux) || !Tva::valide($taux)) {
                throw new InvalidArgumentException('Taux de TVA invalide.');
            }
            $lignes[] = [
                'articleId' => self::entierOuNull($ligne['articleId'] ?? null),
                'type' => $type,
                'nom' => $nom,
                'prix' => self::entier($ligne['prix'] ?? null, 0, Montant::MAX),
                'taux' => $taux,
                'quantite' => self::entier($ligne['quantite'] ?? null, 1, 999),
                'barbierId' => self::entierOuNull($ligne['barbierId'] ?? null),
                'remise' => self::remise($ligne['remise'] ?? null),
            ];
        }
        return [
            'barbierId' => self::entierOuNull($contenu['barbierId'] ?? null),
            'remise' => self::remise($contenu['remise'] ?? null),
            'lignes' => $lignes,
        ];
    }

    /** @return array{type:string, valeur:int, motif:string}|null */
    private static function remise(mixed $remise): ?array
    {
        if ($remise === null) {
            return null;
        }
        if (!is_array($remise) || !in_array($remise['type'] ?? '', [Calcul::POURCENT, Calcul::EUROS], true)) {
            throw new InvalidArgumentException('Remise illisible.');
        }
        $max = $remise['type'] === Calcul::POURCENT ? 10000 : Montant::MAX * 999;
        return [
            'type' => $remise['type'],
            'valeur' => self::entier($remise['valeur'] ?? null, 1, $max),
            'motif' => mb_substr(trim((string) ($remise['motif'] ?? '')), 0, 60),
        ];
    }

    private static function entier(mixed $valeur, int $min, int $max): int
    {
        if (!is_int($valeur) || $valeur < $min || $valeur > $max) {
            throw new InvalidArgumentException('Valeur hors limites.');
        }
        return $valeur;
    }

    private static function entierOuNull(mixed $valeur): ?int
    {
        return $valeur === null ? null : self::entier($valeur, 1, PHP_INT_MAX);
    }
}
