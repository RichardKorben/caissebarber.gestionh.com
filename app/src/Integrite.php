<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;

/**
 * Contrôle d'intégrité : relit tous les tickets et tout le journal, recalcule les empreintes et vérifie les
 * signatures. Détecte une modification, une suppression ou une insertion faite directement dans la base
 * (même avec les triggers désactivés).
 */
final class Integrite
{
    public function __construct(private readonly mysqli $con, private readonly Tenant $tenant)
    {
    }

    /**
     * @return array{ok: bool, tickets: int, evenements: int, clotures: int, erreurs: list<string>}
     */
    public function verifier(): array
    {
        $signature = Signature::pour($this->tenant);
        $erreurs = [];
        $nbTickets = 0;

        foreach (['reel' => 'ticket', 'formation' => 'ticket_formation'] as $chaine => $compteur) {
            $precedente = '';
            $attendu = 1;
            $requete = $this->con->prepare('SELECT * FROM tickets WHERE chaine = ? ORDER BY numero');
            $requete->bind_param('s', $chaine);
            $requete->execute();
            foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $ticket) {
                $nbTickets++;
                $nom = ($chaine === 'formation' ? 'Ticket de formation n° ' : 'Ticket n° ') . Ventes::numeroAffiche((int) $ticket['numero']);
                if ((int) $ticket['numero'] !== $attendu) {
                    $erreurs[] = $nom . ' : numérotation rompue (n° ' . Ventes::numeroAffiche($attendu) . ' attendu, ticket manquant ou ajouté).';
                    $attendu = (int) $ticket['numero'];
                }
                if ($ticket['hash_precedent'] !== $precedente) {
                    $erreurs[] = $nom . ' : chaînage rompu avec le ticket précédent.';
                }
                $id = (int) $ticket['id'];
                $canonique = Ventes::canonique(
                    $ticket,
                    $this->lignes('SELECT * FROM ticket_lignes WHERE ticket_id = ? ORDER BY rang', $id),
                    $this->lignes('SELECT * FROM ticket_tva WHERE ticket_id = ? ORDER BY taux DESC', $id),
                    $this->lignes('SELECT * FROM ticket_paiements WHERE ticket_id = ? ORDER BY rang', $id),
                );
                $empreinte = Chaine::empreinte((string) $ticket['hash_precedent'], $canonique);
                if ($empreinte !== $ticket['hash']) {
                    $erreurs[] = $nom . ' : contenu modifié après enregistrement (empreinte différente).';
                }
                if (!$signature->verifier((string) $ticket['hash'], (string) $ticket['signature'])) {
                    $erreurs[] = $nom . ' : signature invalide.';
                }
                $precedente = (string) $ticket['hash'];
                $attendu++;
            }
            $this->verifierCompteur($compteur, $attendu - 1, $precedente, $erreurs);
        }

        $precedente = '';
        $attendu = 1;
        $nbEvenements = 0;
        foreach ($this->con->query('SELECT * FROM journal ORDER BY numero') as $e) {
            $nbEvenements++;
            $nom = 'Événement n° ' . $e['numero'] . ' (' . $e['code'] . ')';
            if ((int) $e['numero'] !== $attendu) {
                $erreurs[] = $nom . ' : numérotation du journal rompue.';
                $attendu = (int) $e['numero'];
            }
            if ($e['hash_precedent'] !== $precedente) {
                $erreurs[] = $nom . ' : chaînage du journal rompu.';
            }
            $canonique = Journal::canonique((int) $e['numero'], (string) $e['date_evenement'], (string) $e['code'],
                $e['operateur_id'] === null ? null : (int) $e['operateur_id'], $e['ticket_id'] === null ? null : (int) $e['ticket_id'],
                (string) $e['details'], (string) $e['version_logiciel']);
            if (Chaine::empreinte((string) $e['hash_precedent'], $canonique) !== $e['hash']) {
                $erreurs[] = $nom . ' : contenu modifié après enregistrement.';
            }
            if (!$signature->verifier((string) $e['hash'], (string) $e['signature'])) {
                $erreurs[] = $nom . ' : signature invalide.';
            }
            $precedente = (string) $e['hash'];
            $attendu++;
        }
        $this->verifierCompteur('journal', $attendu - 1, $precedente, $erreurs);

        // Clôtures : chaînage, signature, et totaux recalculés à partir des tickets.
        $clotures = new Clotures($this->con, $this->tenant, new Journal($this->con, $this->tenant));
        $precedente = '';
        $attendu = 1;
        $nbClotures = 0;
        foreach ($this->con->query('SELECT * FROM clotures ORDER BY numero') as $c) {
            $nbClotures++;
            $nom = 'Clôture n° ' . $c['numero'] . ' (' . $c['type'] . ' ' . $c['periode'] . ')';
            if ((int) $c['numero'] !== $attendu) {
                $erreurs[] = $nom . ' : numérotation des clôtures rompue.';
                $attendu = (int) $c['numero'];
            }
            if ($c['hash_precedent'] !== $precedente) {
                $erreurs[] = $nom . ' : chaînage des clôtures rompu.';
            }
            if (Chaine::empreinte((string) $c['hash_precedent'], Clotures::canonique($c)) !== $c['hash']) {
                $erreurs[] = $nom . ' : contenu modifié après enregistrement.';
            }
            if (!$signature->verifier((string) $c['hash'], (string) $c['signature'])) {
                $erreurs[] = $nom . ' : signature invalide.';
            }
            [$debut, $fin] = match ($c['type']) {
                'jour' => [$c['periode'], Clotures::lendemain((string) $c['periode'])],
                'mois' => [$c['periode'] . '-01', (new \DateTimeImmutable($c['periode'] . '-01'))->modify('first day of next month')->format('Y-m-d')],
                default => [$c['periode'] . '-01-01', ((int) $c['periode'] + 1) . '-01-01'],
            };
            $t = $clotures->totaux($debut, $fin);
            if ($t['net_ttc'] !== (int) $c['net_ttc'] || $t['nb_ventes'] !== (int) $c['nb_ventes'] || $t['grand_total'] !== (int) $c['grand_total']) {
                $erreurs[] = $nom . ' : les tickets de la période ne correspondent plus aux totaux clôturés.';
            }
            $precedente = (string) $c['hash'];
            $attendu++;
        }
        $this->verifierCompteur('cloture', $attendu - 1, $precedente, $erreurs);

        // Archives : le fichier ZIP doit correspondre à l'empreinte signée.
        $archives = new Archives($this->con, $this->tenant, new Journal($this->con, $this->tenant));
        foreach ($this->con->query('SELECT * FROM archives ORDER BY id') as $archive) {
            if (!$archives->verifier($archive)) {
                $erreurs[] = 'Archive ' . $archive['fichier'] . ' : fichier absent ou modifié (empreinte ou signature invalide).';
            }
        }

        return ['ok' => !$erreurs, 'tickets' => $nbTickets, 'evenements' => $nbEvenements, 'clotures' => $nbClotures, 'erreurs' => $erreurs];
    }

    /** Le compteur doit pointer sur le dernier enregistrement (sinon : derniers enregistrements supprimés). */
    private function verifierCompteur(string $nom, int $dernier, string $empreinte, array &$erreurs): void
    {
        $requete = $this->con->prepare('SELECT valeur, dernier_hash FROM compteurs WHERE nom = ?');
        $requete->bind_param('s', $nom);
        $requete->execute();
        $compteur = $requete->get_result()->fetch_assoc();
        if (!$compteur || (int) $compteur['valeur'] !== $dernier || $compteur['dernier_hash'] !== $empreinte) {
            $erreurs[] = 'Compteur « ' . $nom . ' » incohérent : des enregistrements récents ont pu être supprimés.';
        }
    }

    /** @return list<array<string, mixed>> */
    private function lignes(string $sql, int $id): array
    {
        $requete = $this->con->prepare($sql);
        $requete->bind_param('i', $id);
        $requete->execute();
        return $requete->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
