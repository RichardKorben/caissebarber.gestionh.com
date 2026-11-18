<?php
declare(strict_types=1);

namespace BarberPay;

use DateTimeImmutable;
use InvalidArgumentException;
use mysqli;
use Throwable;

/**
 * Caisse du jour et clôtures fiscales.
 *
 * - Ouverture : fond de caisse du jour (facultatif, une fois par jour).
 * - Clôture journalière (Z) : totaux du jour, comptage des espèces, écart. Après le Z, la journée est fermée :
 *   plus aucune vente ni annulation ce jour-là.
 * - Clôtures mensuelle et annuelle (année civile) : automatiques au changement de mois / d'année.
 * - Rattrapage : une journée avec des ventes qui n'a pas été clôturée l'est automatiquement le lendemain.
 * Chaque clôture porte le grand total perpétuel, est chaînée et signée (compteur « cloture »).
 */
final class Clotures
{
    public const JOUR = 'jour';
    public const MOIS = 'mois';
    public const ANNEE = 'annee';

    public function __construct(
        private readonly mysqli $con,
        private readonly Tenant $tenant,
        private readonly Journal $journal,
    ) {
    }

    // ── Caisse du jour ──────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function ouverture(string $jour): ?array
    {
        $requete = $this->con->prepare('SELECT * FROM caisse_ouvertures WHERE jour = ?');
        $requete->bind_param('s', $jour);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }

    public function ouvrir(int $fond, ?int $operateurId): void
    {
        $jour = Horloge::date('Y-m-d');
        if ($fond < 0 || $fond > 10_000_000) {
            throw new InvalidArgumentException('Fond de caisse invalide.');
        }
        if ($this->journeeCloturee($jour)) {
            throw new InvalidArgumentException('La journée est déjà clôturée.');
        }
        if ($this->ouverture($jour) !== null) {
            throw new InvalidArgumentException('La caisse est déjà ouverte aujourd\'hui.');
        }
        $this->con->begin_transaction();
        try {
            $requete = $this->con->prepare('INSERT INTO caisse_ouvertures (jour, fond, operateur_id) VALUES (?, ?, ?)');
            $requete->bind_param('sii', $jour, $fond, $operateurId);
            $requete->execute();
            $this->journal->ecrire(Journal::OUVERTURE_CAISSE, ['jour' => $jour, 'fond' => $fond], $operateurId, null, true);
            $this->con->commit();
        } catch (Throwable $e) {
            $this->con->rollback();
            throw str_contains($e->getMessage(), 'uq_caisse_ouvertures_jour')
                ? new InvalidArgumentException('La caisse est déjà ouverte aujourd\'hui.') : $e;
        }
    }

    public function journeeCloturee(string $jour): bool
    {
        $requete = $this->con->prepare("SELECT 1 FROM clotures WHERE type = 'jour' AND periode = ?");
        $requete->bind_param('s', $jour);
        $requete->execute();
        return (bool) $requete->get_result()->fetch_row();
    }

    /** Espèces qui devraient être dans le tiroir : fond + espèces encaissées (nettes des remboursements). */
    public function especesAttendues(string $jour): int
    {
        $ouverture = $this->ouverture($jour);
        $totaux = $this->totaux($jour, self::lendemain($jour));
        return (int) ($ouverture['fond'] ?? 0) + (int) ($totaux['detail']['par_moyen']['especes'] ?? 0);
    }

    // ── Totaux ──────────────────────────────────────────────────────────

    /**
     * Totaux des tickets réels entre $debut (inclus) et $fin (exclus), dates « Y-m-d ».
     *
     * @return array{nb_ventes:int, nb_annulations:int, ventes_ttc:int, annulations_ttc:int, net_ttc:int, net_ht:int,
     *               net_tva:int, premier_ticket:int|null, dernier_ticket:int|null, grand_total:int, grand_total_absolu:int,
     *               detail: array<string, mixed>}
     */
    public function totaux(string $debut, string $fin): array
    {
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $lire = function (string $sql, string ...$valeurs): array {
            $requete = $this->con->prepare($sql);
            $requete->bind_param(str_repeat('s', count($valeurs)), ...$valeurs);
            $requete->execute();
            return $requete->get_result()->fetch_all(MYSQLI_ASSOC);
        };
        $filtre = "t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ?";

        $r = ['nb_ventes' => 0, 'nb_annulations' => 0, 'ventes_ttc' => 0, 'annulations_ttc' => 0, 'net_ttc' => 0, 'net_ht' => 0, 'net_tva' => 0,
            'premier_ticket' => null, 'dernier_ticket' => null];
        foreach ($lire("SELECT t.type, COUNT(*) n, SUM(t.total_ttc) ttc, SUM(t.total_ht) ht, SUM(t.total_tva) tva, MIN(t.numero) premier, MAX(t.numero) dernier
                        FROM tickets t WHERE $filtre GROUP BY t.type", $de, $a) as $ligne) {
            $vente = $ligne['type'] === 'vente';
            $r[$vente ? 'nb_ventes' : 'nb_annulations'] = (int) $ligne['n'];
            $r[$vente ? 'ventes_ttc' : 'annulations_ttc'] = (int) $ligne['ttc'];
            $r['net_ttc'] += (int) $ligne['ttc'];
            $r['net_ht'] += (int) $ligne['ht'];
            $r['net_tva'] += (int) $ligne['tva'];
            $r['premier_ticket'] = $r['premier_ticket'] === null ? (int) $ligne['premier'] : min($r['premier_ticket'], (int) $ligne['premier']);
            $r['dernier_ticket'] = $r['dernier_ticket'] === null ? (int) $ligne['dernier'] : max($r['dernier_ticket'], (int) $ligne['dernier']);
        }

        $detail = ['par_taux' => [], 'par_moyen' => [], 'par_type' => ['service' => 0, 'produit' => 0], 'par_barbier' => [], 'remises' => 0];
        foreach ($lire("SELECT v.taux, SUM(v.base_ht) ht, SUM(v.tva) tva, SUM(v.ttc) ttc FROM ticket_tva v JOIN tickets t ON t.id = v.ticket_id
                        WHERE $filtre GROUP BY v.taux ORDER BY v.taux DESC", $de, $a) as $ligne) {
            $detail['par_taux'][] = ['taux' => (int) $ligne['taux'], 'ht' => (int) $ligne['ht'], 'tva' => (int) $ligne['tva'], 'ttc' => (int) $ligne['ttc']];
        }
        foreach ($lire("SELECT p.moyen, SUM(p.montant) m FROM ticket_paiements p JOIN tickets t ON t.id = p.ticket_id
                        WHERE $filtre GROUP BY p.moyen ORDER BY p.moyen", $de, $a) as $ligne) {
            $detail['par_moyen'][$ligne['moyen']] = (int) $ligne['m'];
        }
        foreach ($lire("SELECT l.type, SUM(l.total_ttc) m FROM ticket_lignes l JOIN tickets t ON t.id = l.ticket_id
                        WHERE $filtre GROUP BY l.type", $de, $a) as $ligne) {
            $detail['par_type'][$ligne['type']] = (int) $ligne['m'];
        }
        foreach ($lire("SELECT l.barbier_id, u.prenom, SUM(l.total_ttc) m FROM ticket_lignes l JOIN tickets t ON t.id = l.ticket_id
                        LEFT JOIN utilisateurs u ON u.id = l.barbier_id
                        WHERE $filtre GROUP BY l.barbier_id, u.prenom ORDER BY l.barbier_id", $de, $a) as $ligne) {
            $detail['par_barbier'][] = ['id' => $ligne['barbier_id'] === null ? null : (int) $ligne['barbier_id'], 'prenom' => $ligne['prenom'], 'ttc' => (int) $ligne['m']];
        }
        $remises = $lire("SELECT COALESCE(SUM(t.remise_ticket), 0) + COALESCE((SELECT SUM(l.remise_ligne) FROM ticket_lignes l JOIN tickets t ON t.id = l.ticket_id WHERE $filtre), 0) r
                          FROM tickets t WHERE $filtre", $de, $a, $de, $a);
        $detail['remises'] = (int) ($remises[0]['r'] ?? 0);

        $gt = $lire("SELECT COALESCE(SUM(total_ttc), 0) gt, COALESCE(SUM(ABS(total_ttc)), 0) gta FROM tickets WHERE chaine = 'reel' AND date_ticket < ?", $a);
        $r['grand_total'] = (int) $gt[0]['gt'];
        $r['grand_total_absolu'] = (int) $gt[0]['gta'];
        $r['detail'] = $detail;
        return $r;
    }

    // ── Clôtures ────────────────────────────────────────────────────────

    /**
     * Clôture journalière (Z).
     *
     * @param int|null $especesComptees comptage du tiroir (null pour une clôture automatique)
     * @return array<string, mixed> la clôture enregistrée
     */
    public function cloturerJour(string $jour, ?int $operateurId, bool $automatique = false, ?int $especesComptees = null): array
    {
        self::verifierDate($jour);
        if ($jour > Horloge::date('Y-m-d')) {
            throw new InvalidArgumentException('On ne peut pas clôturer une journée future.');
        }
        if ($especesComptees !== null && ($especesComptees < 0 || $especesComptees > 100_000_000)) {
            throw new InvalidArgumentException('Comptage des espèces invalide.');
        }
        $ouverture = $this->ouverture($jour);
        $caisse = null;
        if (!$automatique) {
            $totaux = $this->totaux($jour, self::lendemain($jour));
            $attendues = (int) ($ouverture['fond'] ?? 0) + (int) ($totaux['detail']['par_moyen']['especes'] ?? 0);
            $caisse = [
                'fond' => $ouverture !== null ? (int) $ouverture['fond'] : null,
                'especes_attendues' => $attendues,
                'especes_comptees' => $especesComptees,
                'ecart' => $especesComptees === null ? null : $especesComptees - $attendues,
            ];
        }
        return $this->enregistrer(self::JOUR, $jour, $jour, self::lendemain($jour), $operateurId, $automatique, $caisse);
    }

    /** @return array<string, mixed> */
    public function cloturerMois(string $mois, ?int $operateurId, bool $automatique = true): array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) {
            throw new InvalidArgumentException('Mois invalide.');
        }
        if ($mois >= Horloge::date('Y-m')) {
            throw new InvalidArgumentException('Le mois n\'est pas terminé.');
        }
        $debut = $mois . '-01';
        $fin = (new DateTimeImmutable($debut))->modify('first day of next month')->format('Y-m-d');
        $this->verifierJoursClotures($debut, $fin);
        return $this->enregistrer(self::MOIS, $mois, $debut, $fin, $operateurId, $automatique, null);
    }

    /** @return array<string, mixed> */
    public function cloturerAnnee(string $annee, ?int $operateurId, bool $automatique = true): array
    {
        if (!preg_match('/^\d{4}$/', $annee)) {
            throw new InvalidArgumentException('Année invalide.');
        }
        if ($annee >= Horloge::date('Y')) {
            throw new InvalidArgumentException('L\'année n\'est pas terminée.');
        }
        return $this->enregistrer(self::ANNEE, $annee, $annee . '-01-01', ((int) $annee + 1) . '-01-01', $operateurId, $automatique, null);
    }

    /**
     * Clôture automatiquement ce qui aurait dû l'être : journées passées avec des tickets, mois et années terminés.
     *
     * @return list<string> clôtures créées (« jour 2026-11-17 »…)
     */
    public function rattraper(): array
    {
        $faites = [];
        $aujourdhui = Horloge::date('Y-m-d') . ' 00:00:00';
        $requete = $this->con->prepare(
            "SELECT DISTINCT DATE(date_ticket) d FROM tickets
             WHERE chaine = 'reel' AND date_ticket < ?
               AND DATE_FORMAT(date_ticket, '%Y-%m-%d') NOT IN (SELECT periode FROM clotures WHERE type = 'jour')
             ORDER BY d"
        );
        $requete->bind_param('s', $aujourdhui);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all() as [$jour]) {
            $this->cloturerJour((string) $jour, null, true);
            $faites[] = 'jour ' . $jour;
        }
        $moisCourant = Horloge::date('Y-m');
        $requete = $this->con->prepare(
            "SELECT DISTINCT LEFT(periode, 7) m FROM clotures WHERE type = 'jour' AND LEFT(periode, 7) < ?
               AND LEFT(periode, 7) NOT IN (SELECT periode FROM clotures WHERE type = 'mois') ORDER BY m"
        );
        $requete->bind_param('s', $moisCourant);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all() as [$mois]) {
            $this->cloturerMois((string) $mois, null, true);
            $faites[] = 'mois ' . $mois;
        }
        $anneeCourante = Horloge::date('Y');
        $requete = $this->con->prepare(
            "SELECT DISTINCT LEFT(periode, 4) a FROM clotures WHERE type = 'mois' AND LEFT(periode, 4) < ?
               AND LEFT(periode, 4) NOT IN (SELECT periode FROM clotures WHERE type = 'annee') ORDER BY a"
        );
        $requete->bind_param('s', $anneeCourante);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all() as [$annee]) {
            $this->cloturerAnnee((string) $annee, null, true);
            $faites[] = 'année ' . $annee;
        }
        // Un changement de régime de TVA en attente s'applique dès que la journée ne contient plus de ventes non clôturées.
        $this->appliquerRegimeEnAttente(null, false);
        return $faites;
    }

    /** @return array<string, mixed>|null */
    public function charger(int $id): ?array
    {
        $requete = $this->con->prepare('SELECT * FROM clotures WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function lister(int $limite = 60): array
    {
        $requete = $this->con->prepare('SELECT id, numero, type, periode, date_cloture, automatique, net_ttc, nb_ventes, nb_annulations, ecart FROM clotures ORDER BY numero DESC LIMIT ?');
        $requete->bind_param('i', $limite);
        $requete->execute();
        return $requete->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed> */
    public static function canonique(array $c): array
    {
        $cles = ['numero', 'type', 'periode', 'date_cloture', 'automatique', 'operateur_id', 'regime_tva', 'premier_ticket', 'dernier_ticket',
            'nb_ventes', 'nb_annulations', 'ventes_ttc', 'annulations_ttc', 'net_ttc', 'net_ht', 'net_tva', 'grand_total', 'grand_total_absolu',
            'fond', 'especes_attendues', 'especes_comptees', 'ecart', 'detail', 'version_logiciel'];
        return array_combine($cles, array_map(static fn (string $k) => $c[$k] ?? null, $cles));
    }

    public static function lendemain(string $jour): string
    {
        return (new DateTimeImmutable($jour))->modify('+1 day')->format('Y-m-d');
    }

    // ── Interne ─────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function enregistrer(string $type, string $periode, string $debut, string $fin, ?int $operateurId, bool $automatique, ?array $caisse): array
    {
        $this->con->begin_transaction();
        try {
            $reservation = Chaine::reserver($this->con, 'cloture'); // verrouille aussi les clôtures concurrentes
            $existe = $this->con->prepare('SELECT 1 FROM clotures WHERE type = ? AND periode = ?');
            $existe->bind_param('ss', $type, $periode);
            $existe->execute();
            if ($existe->get_result()->fetch_row()) {
                throw new InvalidArgumentException('Cette période est déjà clôturée.');
            }
            // Les ventes en cours ne doivent pas se glisser pendant le calcul du Z : on verrouille le compteur des tickets.
            Chaine::reserver($this->con, 'ticket');

            $totaux = $this->totaux($debut, $fin);
            $parametres = (new Parametres($this->con, $this->tenant))->charger();
            $c = [
                'numero' => $reservation['numero'],
                'type' => $type,
                'periode' => $periode,
                'date_cloture' => Horloge::date('Y-m-d H:i:s'),
                'automatique' => $automatique ? 1 : 0,
                'operateur_id' => $operateurId,
                'regime_tva' => (string) $parametres['regime_tva'],
            ] + array_diff_key($totaux, ['detail' => true]) + [
                'fond' => $caisse['fond'] ?? null,
                'especes_attendues' => $caisse['especes_attendues'] ?? null,
                'especes_comptees' => $caisse['especes_comptees'] ?? null,
                'ecart' => $caisse['ecart'] ?? null,
                'detail' => Chaine::json($totaux['detail']),
                'version_logiciel' => VERSION,
            ];
            $empreinte = Chaine::empreinte($reservation['precedente'], self::canonique($c));
            $signature = Signature::pour($this->tenant)->signer($empreinte);
            $precedente = $reservation['precedente'];

            $requete = $this->con->prepare(
                'INSERT INTO clotures (numero, type, periode, date_cloture, automatique, operateur_id, regime_tva, premier_ticket, dernier_ticket,
                    nb_ventes, nb_annulations, ventes_ttc, annulations_ttc, net_ttc, net_ht, net_tva, grand_total, grand_total_absolu,
                    fond, especes_attendues, especes_comptees, ecart, detail, version_logiciel, hash_precedent, hash, signature)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $requete->bind_param(
                'isssiisiiiiiiiiiiiiiiisssss',
                $c['numero'], $c['type'], $c['periode'], $c['date_cloture'], $c['automatique'], $c['operateur_id'], $c['regime_tva'],
                $c['premier_ticket'], $c['dernier_ticket'], $c['nb_ventes'], $c['nb_annulations'], $c['ventes_ttc'], $c['annulations_ttc'],
                $c['net_ttc'], $c['net_ht'], $c['net_tva'], $c['grand_total'], $c['grand_total_absolu'], $c['fond'], $c['especes_attendues'],
                $c['especes_comptees'], $c['ecart'], $c['detail'], $c['version_logiciel'], $precedente, $empreinte, $signature
            );
            $requete->execute();
            $id = (int) $this->con->insert_id;
            Chaine::avancer($this->con, 'cloture', $c['numero'], $empreinte);
            $this->journal->ecrire(Journal::CLOTURE, [
                'type' => $type, 'periode' => $periode, 'numero' => $c['numero'], 'net_ttc' => $c['net_ttc'],
                'automatique' => $automatique, 'ecart' => $c['ecart'],
            ], $operateurId, null, true);
            if ($type === self::JOUR) {
                $this->appliquerRegimeEnAttente($operateurId, true);
            }
            $this->con->commit();
        } catch (Throwable $e) {
            $this->con->rollback();
            throw $e;
        }
        return $this->charger($id);
    }

    /**
     * Applique un changement de régime de TVA demandé en cours de journée, une fois la journée clôturée
     * (ou s'il n'y a aucune vente aujourd'hui).
     */
    private function appliquerRegimeEnAttente(?int $operateurId, bool $dansTransaction): void
    {
        $p = $this->con->query('SELECT regime_tva, regime_tva_a_venir FROM parametres WHERE id = 1')->fetch_assoc();
        if (!$p || $p['regime_tva_a_venir'] === null) {
            return;
        }
        $aujourdhui = Horloge::date('Y-m-d');
        if (!$this->journeeCloturee($aujourdhui) && $this->ventesDuJour($aujourdhui) > 0 && !$dansTransaction) {
            return;
        }
        $nouveau = (string) $p['regime_tva_a_venir'];
        if (!$dansTransaction) {
            $this->con->begin_transaction();
        }
        $requete = $this->con->prepare('UPDATE parametres SET regime_tva = ?, regime_tva_a_venir = NULL WHERE id = 1');
        $requete->bind_param('s', $nouveau);
        $requete->execute();
        $this->journal->ecrire(Journal::REGIME_TVA_APPLIQUE, ['avant' => $p['regime_tva'], 'apres' => $nouveau], $operateurId, null, true);
        if (!$dansTransaction) {
            $this->con->commit();
        }
    }

    private function ventesDuJour(string $jour): int
    {
        $de = $jour . ' 00:00:00';
        $a = self::lendemain($jour) . ' 00:00:00';
        $requete = $this->con->prepare("SELECT COUNT(*) FROM tickets WHERE chaine = 'reel' AND date_ticket >= ? AND date_ticket < ?");
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        return (int) $requete->get_result()->fetch_row()[0];
    }

    /** Toutes les journées avec des tickets doivent être clôturées avant le mois. */
    private function verifierJoursClotures(string $debut, string $fin): void
    {
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $requete = $this->con->prepare(
            "SELECT COUNT(DISTINCT DATE(date_ticket)) FROM tickets WHERE chaine = 'reel' AND date_ticket >= ? AND date_ticket < ?
               AND DATE_FORMAT(date_ticket, '%Y-%m-%d') NOT IN (SELECT periode FROM clotures WHERE type = 'jour')"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        if ((int) $requete->get_result()->fetch_row()[0] > 0) {
            throw new InvalidArgumentException('Des journées de cette période ne sont pas clôturées.');
        }
    }

    private static function verifierDate(string $jour): void
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $jour);
        if ($d === false || $d->format('Y-m-d') !== $jour) {
            throw new InvalidArgumentException('Date invalide.');
        }
    }
}
