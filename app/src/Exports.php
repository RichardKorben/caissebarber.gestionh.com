<?php
declare(strict_types=1);

namespace BarberPay;

use DateTimeImmutable;
use InvalidArgumentException;
use mysqli;
use RuntimeException;
use ZipArchive;

/**
 * Exports réglementaires et comptables. Toutes les périodes sont [début inclus, fin exclue), dates « Y-m-d ».
 * Seuls les tickets réels sont exportés (jamais ceux du mode formation).
 */
final class Exports
{
    public function __construct(private readonly mysqli $con, private readonly Tenant $tenant)
    {
    }

    /** Vérifie une période saisie et la renvoie [début, fin exclue]. */
    public static function periode(string $debut, string $finIncluse): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $debut);
        $f = DateTimeImmutable::createFromFormat('!Y-m-d', $finIncluse);
        if ($d === false || $f === false || $d->format('Y-m-d') !== $debut || $f->format('Y-m-d') !== $finIncluse || $f < $d) {
            throw new InvalidArgumentException('Période invalide.');
        }
        if ($d->diff($f)->days > 3700) {
            throw new InvalidArgumentException('Période trop longue (10 ans au maximum).');
        }
        return [$debut, $f->modify('+1 day')->format('Y-m-d')];
    }

    /**
     * Livre des recettes (micro-entrepreneur) : une ligne par ticket, dans l'ordre chronologique.
     *
     * @return list<array{date:string, heure:string, numero:string, nature:string, detail:string, montant:int, reglement:string}>
     */
    public function livreRecettes(string $debut, string $fin): array
    {
        $lignes = [];
        foreach ($this->tickets($debut, $fin) as $t) {
            $types = array_unique(array_column($t['lignes'], 'type'));
            $nature = $t['type'] === 'annulation'
                ? 'Annulation du ticket n° ' . Ventes::numeroAffiche((int) $t['origine'])
                : (count($types) > 1 ? 'Services et produits' : ($types === ['produit'] ? 'Vente de produits' : 'Prestations de services'));
            $detail = implode(', ', array_map(static fn (array $l): string => (abs((int) $l['quantite']) > 1 ? abs((int) $l['quantite']) . ' × ' : '') . $l['libelle'], $t['lignes']));
            $lignes[] = [
                'date' => substr($t['date_ticket'], 8, 2) . '/' . substr($t['date_ticket'], 5, 2) . '/' . substr($t['date_ticket'], 0, 4),
                'heure' => substr($t['date_ticket'], 11, 5),
                'numero' => Ventes::numeroAffiche((int) $t['numero']),
                'nature' => $nature,
                'detail' => $detail,
                'montant' => (int) $t['total_ttc'],
                'reglement' => implode(' + ', array_map(static fn (array $p): string =>
                    (Ventes::MOYENS[$p['moyen']] ?? $p['moyen']) . ' ' . self::nombre((int) $p['montant']), $t['paiements'])) ?: 'Offert',
            ];
        }
        return $lignes;
    }

    /**
     * Récapitulatif pour la déclaration URSSAF : chiffre d'affaires par mois, services et produits séparés.
     *
     * @return array{mois: array<string, array{services:int, produits:int, total:int}>, trimestres: array<int, array{services:int, produits:int, total:int}>, annee: array{services:int, produits:int, total:int}}
     */
    public function urssaf(int $annee): array
    {
        $vide = ['services' => 0, 'produits' => 0, 'total' => 0];
        $mois = [];
        for ($m = 1; $m <= 12; $m++) {
            $mois[sprintf('%04d-%02d', $annee, $m)] = $vide;
        }
        $de = $annee . '-01-01 00:00:00';
        $a = ($annee + 1) . '-01-01 00:00:00';
        $requete = $this->con->prepare(
            "SELECT DATE_FORMAT(t.date_ticket, '%Y-%m') m, l.type, SUM(l.total_ttc) s FROM ticket_lignes l JOIN tickets t ON t.id = l.ticket_id
             WHERE t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ? GROUP BY m, l.type"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $cle = $r['type'] === 'produit' ? 'produits' : 'services';
            $mois[$r['m']][$cle] += (int) $r['s'];
            $mois[$r['m']]['total'] += (int) $r['s'];
        }
        $trimestres = [1 => $vide, 2 => $vide, 3 => $vide, 4 => $vide];
        $total = $vide;
        foreach ($mois as $m => $v) {
            $t = intdiv((int) substr($m, 5, 2) - 1, 3) + 1;
            foreach (['services', 'produits', 'total'] as $k) {
                $trimestres[$t][$k] += $v[$k];
                $total[$k] += $v[$k];
            }
        }
        return ['mois' => $mois, 'trimestres' => $trimestres, 'annee' => $total];
    }

    /**
     * Chiffre d'affaires de l'année civile pour le suivi des seuils de franchise de TVA.
     *
     * @return array{services:int, produits:int, total:int}
     */
    public function chiffreAnnee(int $annee): array
    {
        return $this->urssaf($annee)['annee'];
    }

    /**
     * Journal des ventes pour le comptable : par jour, la TVA par taux et les règlements par moyen.
     *
     * @return list<array{date:string, nature:string, libelle:string, ht:int|null, tva:int|null, ttc:int}>
     */
    public function journalVentes(string $debut, string $fin): array
    {
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $lignes = [];
        $requete = $this->con->prepare(
            "SELECT DATE(t.date_ticket) j, t.regime_tva, v.taux, SUM(v.base_ht) ht, SUM(v.tva) tva, SUM(v.ttc) ttc
             FROM ticket_tva v JOIN tickets t ON t.id = v.ticket_id
             WHERE t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ? GROUP BY j, t.regime_tva, v.taux ORDER BY j, v.taux DESC"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $lignes[] = ['date' => (string) $r['j'], 'nature' => 'Ventes', 'libelle' => 'TVA ' . Tva::libelle((int) $r['taux']),
                'ht' => (int) $r['ht'], 'tva' => (int) $r['tva'], 'ttc' => (int) $r['ttc']];
        }
        $requete = $this->con->prepare(
            "SELECT DATE(t.date_ticket) j, SUM(t.total_ttc) ttc FROM tickets t
             WHERE t.chaine = 'reel' AND t.regime_tva = 'franchise' AND t.date_ticket >= ? AND t.date_ticket < ? GROUP BY j ORDER BY j"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $lignes[] = ['date' => (string) $r['j'], 'nature' => 'Ventes', 'libelle' => 'Franchise en base (art. 293 B du CGI)',
                'ht' => (int) $r['ttc'], 'tva' => 0, 'ttc' => (int) $r['ttc']];
        }
        $requete = $this->con->prepare(
            "SELECT DATE(t.date_ticket) j, p.moyen, SUM(p.montant) m FROM ticket_paiements p JOIN tickets t ON t.id = p.ticket_id
             WHERE t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ? GROUP BY j, p.moyen ORDER BY j, p.moyen"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $lignes[] = ['date' => (string) $r['j'], 'nature' => 'Règlements', 'libelle' => Ventes::MOYENS[$r['moyen']] ?? (string) $r['moyen'],
                'ht' => null, 'tva' => null, 'ttc' => (int) $r['m']];
        }
        usort($lignes, static fn (array $x, array $y): int => [$x['date'], $x['nature']] <=> [$y['date'], $y['nature']]);
        return $lignes;
    }

    /**
     * E-reporting B2C (réforme de la facturation électronique) : totaux journaliers par taux de TVA.
     * Format provisoire : à aligner sur les spécifications DGFiP des flux de transactions B2C avant septembre 2027.
     *
     * @return list<array{date:string, regime:string, taux:int, base_ht:int, tva:int, ttc:int, operations:int}>
     */
    public function eReporting(string $debut, string $fin): array
    {
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $requete = $this->con->prepare(
            "SELECT DATE(t.date_ticket) j, t.regime_tva, COALESCE(l.taux_tva, 0) taux, SUM(l.total_ttc) ttc, COUNT(DISTINCT t.id) n
             FROM tickets t JOIN ticket_lignes l ON l.ticket_id = t.id
             WHERE t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ?
             GROUP BY j, t.regime_tva, taux ORDER BY j, taux DESC"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        $lignes = [];
        foreach ($requete->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $franchise = $r['regime_tva'] === Parametres::FRANCHISE;
            $taux = $franchise ? 0 : (int) $r['taux'];
            $ttc = (int) $r['ttc'];
            $ht = $franchise ? $ttc : Calcul::htDepuisTtc($ttc, $taux);
            $lignes[] = ['date' => (string) $r['j'], 'regime' => $franchise ? 'franchise' : 'assujetti', 'taux' => $taux,
                'base_ht' => $ht, 'tva' => $ttc - $ht, 'ttc' => $ttc, 'operations' => (int) $r['n']];
        }
        return $lignes;
    }

    /**
     * Archive / export pour un contrôle fiscal : toutes les données de la période en CSV, la clé publique et la notice
     * de vérification, dans un fichier ZIP.
     *
     * @return string chemin du fichier ZIP (temporaire, à déplacer ou à supprimer par l'appelant)
     */
    public function zipFiscal(string $debut, string $fin, string $titre): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) {
            throw new InvalidArgumentException('Période invalide.'); // les dates sont ensuite insérées dans le SQL
        }
        $chemin = tempnam(sys_get_temp_dir(), 'barberpay-');
        if ($chemin === false) {
            throw new RuntimeException('Impossible de créer le fichier temporaire.');
        }
        $zip = new ZipArchive();
        if ($zip->open($chemin, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer l\'archive.');
        }
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $ids = "SELECT id FROM tickets WHERE date_ticket >= '$de' AND date_ticket < '$a'"; // dates validées par periode()
        $tables = [
            'tickets.csv' => "SELECT * FROM tickets WHERE date_ticket >= '$de' AND date_ticket < '$a' ORDER BY chaine, numero",
            'ticket_lignes.csv' => "SELECT * FROM ticket_lignes WHERE ticket_id IN ($ids) ORDER BY ticket_id, rang",
            'ticket_tva.csv' => "SELECT * FROM ticket_tva WHERE ticket_id IN ($ids) ORDER BY ticket_id, taux DESC",
            'ticket_paiements.csv' => "SELECT * FROM ticket_paiements WHERE ticket_id IN ($ids) ORDER BY ticket_id, rang",
            'duplicatas.csv' => "SELECT * FROM duplicatas WHERE ticket_id IN ($ids) ORDER BY id",
            'clotures.csv' => "SELECT * FROM clotures WHERE date_cloture >= '$de' AND date_cloture < '$a' ORDER BY numero",
            'journal.csv' => "SELECT * FROM journal WHERE date_evenement >= '$de' AND date_evenement < '$a' ORDER BY numero",
            'caisse_ouvertures.csv' => "SELECT * FROM caisse_ouvertures WHERE jour >= '$debut' AND jour < '$fin' ORDER BY jour",
            'mouvements_stock.csv' => "SELECT * FROM mouvements_stock WHERE cree_le >= '$de' AND cree_le < '$a' ORDER BY id",
        ];
        foreach ($tables as $nom => $sql) {
            $resultat = $this->con->query($sql);
            $champs = array_map(static fn ($f) => $f->name, $resultat->fetch_fields());
            $zip->addFromString($nom, self::csv(array_merge([$champs], $resultat->fetch_all(MYSQLI_NUM)), false));
        }
        $integrite = (new Integrite($this->con, $this->tenant))->verifier();
        $zip->addFromString('cle_publique_ed25519.txt', Signature::pour($this->tenant)->clePublique() . "\n");
        $zip->addFromString('integrite.txt', ($integrite['ok'] ? 'INTÈGRE' : 'ANOMALIES') . ' au ' . Horloge::date('d/m/Y H:i:s')
            . " — {$integrite['tickets']} ticket(s), {$integrite['clotures']} clôture(s), {$integrite['evenements']} événement(s) contrôlés (base complète)\n"
            . implode("\n", array_map(static fn (string $e): string => '- ' . $e, $integrite['erreurs'])) . "\n");
        $zip->addFromString('LISEZMOI.txt', self::notice($titre, $debut, $fin, $this->tenant));
        $zip->close();
        return $chemin;
    }

    /** Écrit un CSV (séparateur « ; », UTF-8 avec BOM pour Excel). */
    public static function csv(array $lignes, bool $bom = true): string
    {
        $flux = fopen('php://temp', 'r+');
        if ($bom) {
            fwrite($flux, "\u{FEFF}");
        }
        foreach ($lignes as $ligne) {
            fputcsv($flux, array_map(static fn ($v) => $v === null ? '' : (string) $v, $ligne), ';', '"', '');
        }
        rewind($flux);
        $contenu = (string) stream_get_contents($flux);
        fclose($flux);
        return $contenu;
    }

    /** 2550 → « 25,50 » (montant pour un tableur, sans symbole). */
    public static function nombre(?int $centimes): string
    {
        return $centimes === null ? '' : number_format($centimes / 100, 2, ',', '');
    }

    /** @return list<array<string, mixed>> tickets réels de la période, complets */
    private function tickets(string $debut, string $fin): array
    {
        $de = $debut . ' 00:00:00';
        $a = $fin . ' 00:00:00';
        $requete = $this->con->prepare(
            "SELECT t.*, (SELECT o.numero FROM tickets o WHERE o.id = t.ticket_origine_id) origine FROM tickets t
             WHERE t.chaine = 'reel' AND t.date_ticket >= ? AND t.date_ticket < ? ORDER BY t.numero"
        );
        $requete->bind_param('ss', $de, $a);
        $requete->execute();
        $tickets = $requete->get_result()->fetch_all(MYSQLI_ASSOC);
        $lire = function (string $sql, int $id): array {
            $r = $this->con->prepare($sql);
            $r->bind_param('i', $id);
            $r->execute();
            return $r->get_result()->fetch_all(MYSQLI_ASSOC);
        };
        foreach ($tickets as &$t) {
            $t['lignes'] = $lire('SELECT * FROM ticket_lignes WHERE ticket_id = ? ORDER BY rang', (int) $t['id']);
            $t['paiements'] = $lire('SELECT * FROM ticket_paiements WHERE ticket_id = ? ORDER BY rang', (int) $t['id']);
        }
        return $tickets;
    }

    private static function notice(string $titre, string $debut, string $fin, Tenant $tenant): string
    {
        $finIncluse = (new DateTimeImmutable($fin))->modify('-1 day')->format('d/m/Y');
        $genere = Horloge::date('d/m/Y à H:i:s');
        return <<<TXT
        BarberPay — {$titre}
        Salon : {$tenant->nomSalon}
        Période : du {$debut} au {$finIncluse} inclus
        Généré le : {$genere}

        CONTENU
          tickets.csv, ticket_lignes.csv, ticket_tva.csv, ticket_paiements.csv : tickets de la période (ventes et
            annulations ; chaine = reel pour les ventes réelles, formation pour le mode formation, sans valeur fiscale)
          clotures.csv : clôtures journalières (Z), mensuelles et annuelles, avec le grand total perpétuel
          journal.csv : journal des événements (connexions, annulations, modifications de prix et de paramètres…)
          duplicatas.csv, caisse_ouvertures.csv, mouvements_stock.csv
          cle_publique_ed25519.txt : clé publique de vérification des signatures (base64)
          integrite.txt : résultat du contrôle d'intégrité de la base complète au moment de l'export

        MONTANTS : en centimes d'euro (2550 = 25,50 €). Taux de TVA en centièmes de % (2000 = 20 %).

        VÉRIFICATION
          Chaque ticket, clôture et événement est chaîné : hash = SHA-256(hash_precedent + "\\n" + JSON canonique),
          où le JSON canonique reprend les champs de l'enregistrement dans un ordre fixe (UTF-8, sans échappement des
          caractères accentués ni des barres obliques). Le premier enregistrement de chaque chaîne a un hash_precedent vide.
          La colonne signature contient la signature Ed25519 (base64) de la valeur hexadécimale du hash, vérifiable avec
          la clé publique fournie. Les numéros se suivent sans interruption au sein de chaque chaîne.
          Les tables sont en ajout seul : une erreur est corrigée par un ticket d'annulation, jamais par une modification.
        TXT;
    }
}
