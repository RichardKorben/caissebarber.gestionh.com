<?php
declare(strict_types=1);

namespace BarberPay;

use InvalidArgumentException;
use mysqli;
use Throwable;

/**
 * Encaissement et annulation des tickets (module fiscal).
 *
 * Le serveur ne fait pas confiance au navigateur : il relit le prix, le nom et la TVA de chaque article du
 * catalogue, refait tous les calculs, vérifie les paiements, puis enregistre le ticket numéroté, chaîné et
 * signé en une seule transaction.
 */
final class Ventes
{
    public const MOYENS = ['cb' => 'Carte bancaire', 'especes' => 'Espèces', 'cheque' => 'Chèque', 'autre' => 'Autre'];
    public const REEL = 'reel';
    /** Mode formation : numérotation et chaîne à part, exclu de tous les totaux, clôtures et exports. */
    public const FORMATION = 'formation';

    public function __construct(
        private readonly mysqli $con,
        private readonly Tenant $tenant,
        private readonly Journal $journal,
    ) {
    }

    /**
     * @param mixed $saisie ticket composé dans la caisse
     * @param mixed $paiementsSaisis liste de {moyen, montant, recu?}
     * @param int|null $totalAffiche total vu par le caissier (contrôle : refus si le serveur trouve autre chose)
     * @return array{id:int, numero:int, jeton:string, total:int, rendu:int, deja:bool}
     */
    public function encaisser(mixed $saisie, mixed $paiementsSaisis, ?int $totalAffiche, ?string $cle, int $operateurId, bool $formation = false): array
    {
        if ($cle !== null) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cle)) {
                throw new InvalidArgumentException('Clé d\'encaissement invalide.');
            }
            $deja = $this->parCle($cle);
            if ($deja !== null) {
                return $deja + ['deja' => true];
            }
        }

        $ticket = (new TicketsAttente($this->con))->normaliser($saisie);
        if (!$ticket['lignes']) {
            throw new InvalidArgumentException('Le ticket est vide.');
        }
        $membres = $this->membresActifs();
        $parametres = (new Parametres($this->con, $this->tenant))->charger();
        $franchise = $parametres['regime_tva'] === Parametres::FRANCHISE;

        // Re-tarification depuis le catalogue (le navigateur peut avoir une version ancienne).
        $articles = new Articles($this->con);
        foreach ($ticket['lignes'] as &$ligne) {
            if ($ligne['articleId'] !== null) {
                $article = $articles->trouver($ligne['articleId']);
                if ($article === null) {
                    throw new ConflitCatalogue('Un article du ticket n\'existe plus. Rechargez la page.');
                }
                $ligne['type'] = (string) $article['type'];
                $ligne['nom'] = (string) $article['nom'];
                $ligne['prix'] = (int) $article['prix_ttc'];
                $ligne['taux'] = (int) $article['taux_tva'];
            }
            if ($ligne['barbierId'] !== null && !isset($membres[$ligne['barbierId']])) {
                $ligne['barbierId'] = null;
            }
        }
        unset($ligne);
        $barbierTicket = $ticket['barbierId'] !== null && isset($membres[$ticket['barbierId']]) ? $ticket['barbierId'] : null;

        $calcul = Calcul::ticket(
            array_map(static fn (array $l): array => ['prix' => $l['prix'], 'quantite' => $l['quantite'], 'taux' => $l['taux'], 'remise' => $l['remise']], $ticket['lignes']),
            $ticket['remise']
        );
        if ($totalAffiche !== null && $totalAffiche !== $calcul['total']) {
            throw new ConflitCatalogue('Les prix ont changé depuis l\'ouverture de la caisse (total recalculé : '
                . Montant::format($calcul['total']) . '). Rechargez la page et vérifiez le ticket.');
        }
        $paiements = self::validerPaiements($paiementsSaisis, $calcul['total']);

        // Lignes telles qu'elles seront écrites (et signées).
        $lignes = [];
        foreach ($ticket['lignes'] as $rang => $l) {
            $r = $calcul['lignes'][$rang];
            $lignes[] = [
                'rang' => $rang + 1,
                'article_id' => $l['articleId'],
                'type' => $l['type'],
                'libelle' => $l['nom'],
                'quantite' => $l['quantite'],
                'prix_unitaire' => $l['prix'],
                'remise_ligne' => $r['remise'],
                'remise_type' => $l['remise']['type'] ?? null,
                'remise_valeur' => $l['remise']['valeur'] ?? null,
                'remise_motif' => self::motif($l['remise']['motif'] ?? null),
                'part_remise_ticket' => $r['part_remise_ticket'],
                'total_ttc' => $r['final'],
                'taux_tva' => $l['taux'],
                'barbier_id' => $l['barbierId'] ?? $barbierTicket,
            ];
        }
        $tva = $franchise ? [] : array_map(static fn (array $t): array => [
            'taux' => $t['taux'], 'base_ht' => $t['ht'], 'tva' => $t['tva'], 'ttc' => $t['ttc'],
        ], $calcul['tva']);

        $entete = [
            'chaine' => $formation ? self::FORMATION : self::REEL,
            'numero' => 0, // fixé dans la transaction
            'type' => 'vente',
            'ticket_origine_id' => null,
            'date_ticket' => '',
            'operateur_id' => $operateurId,
            'barbier_id' => $barbierTicket,
            'regime_tva' => $franchise ? Parametres::FRANCHISE : Parametres::ASSUJETTI,
            'nb_articles' => $calcul['nb_articles'],
            'sous_total' => $calcul['sous_total'],
            'remise_ticket' => $calcul['remise_ticket'],
            'remise_type' => $ticket['remise']['type'] ?? null,
            'remise_valeur' => $ticket['remise']['valeur'] ?? null,
            'remise_motif' => self::motif($ticket['remise']['motif'] ?? null),
            'total_ttc' => $calcul['total'],
            'total_ht' => $franchise ? $calcul['total'] : $calcul['total_ht'],
            'total_tva' => $franchise ? 0 : $calcul['total_tva'],
            'version_logiciel' => VERSION,
            'entete' => self::entete($parametres),
            'jeton_public' => bin2hex(random_bytes(16)),
            'cle_idempotence' => $cle,
        ];

        $this->con->begin_transaction();
        try {
            $id = $this->enregistrer($entete, $lignes, $tva, $paiements);
            if (!$formation) { // le mode formation ne touche pas au stock
                $this->mouvementsStock($lignes, 'vente', $id, $operateurId);
            }
            $this->con->commit();
        } catch (Throwable $erreur) {
            $this->con->rollback();
            if ($cle !== null && ($deja = $this->parCle($cle)) !== null) { // même requête arrivée deux fois en même temps
                return $deja + ['deja' => true];
            }
            throw $erreur;
        }
        return $this->resume($id) + ['deja' => false];
    }

    /**
     * Annule un ticket en créant un ticket d'annulation (montants et quantités négatifs, remboursement par les
     * mêmes moyens de paiement). Le ticket d'origine n'est pas touché.
     *
     * @return array{id:int, numero:int, jeton:string, total:int, rendu:int}
     */
    public function annuler(int $ticketId, int $operateurId, string $motif): array
    {
        $motif = trim($motif);
        if ($motif === '' || mb_strlen($motif) > 120) {
            throw new InvalidArgumentException('Indiquez le motif de l\'annulation (120 caractères au maximum).');
        }
        $origine = $this->charger($ticketId);
        if ($origine === null || $origine['chaine'] !== self::REEL) {
            throw new InvalidArgumentException('Ticket introuvable.');
        }
        if ($origine['type'] !== 'vente') {
            throw new InvalidArgumentException('Un ticket d\'annulation ne peut pas être annulé.');
        }
        if ($origine['annule_par'] !== null) {
            throw new InvalidArgumentException('Ce ticket a déjà été annulé (ticket n° ' . self::numeroAffiche($origine['annule_par']['numero']) . ').');
        }

        $entete = [
            'chaine' => self::REEL,
            'numero' => 0,
            'type' => 'annulation',
            'ticket_origine_id' => $ticketId,
            'date_ticket' => '',
            'operateur_id' => $operateurId,
            'barbier_id' => $origine['barbier_id'],
            'regime_tva' => $origine['regime_tva'],
            'nb_articles' => -$origine['nb_articles'],
            'sous_total' => -$origine['sous_total'],
            'remise_ticket' => -$origine['remise_ticket'],
            'remise_type' => $origine['remise_type'],
            'remise_valeur' => $origine['remise_valeur'],
            'remise_motif' => $origine['remise_motif'],
            'total_ttc' => -$origine['total_ttc'],
            'total_ht' => -$origine['total_ht'],
            'total_tva' => -$origine['total_tva'],
            'version_logiciel' => VERSION,
            'entete' => self::entete((new Parametres($this->con, $this->tenant))->charger()),
            'jeton_public' => bin2hex(random_bytes(16)),
            'cle_idempotence' => null,
        ];
        $lignes = array_map(static fn (array $l): array => [
            'rang' => $l['rang'],
            'article_id' => $l['article_id'],
            'type' => $l['type'],
            'libelle' => $l['libelle'],
            'quantite' => -$l['quantite'],
            'prix_unitaire' => $l['prix_unitaire'],
            'remise_ligne' => -$l['remise_ligne'],
            'remise_type' => $l['remise_type'],
            'remise_valeur' => $l['remise_valeur'],
            'remise_motif' => $l['remise_motif'],
            'part_remise_ticket' => -$l['part_remise_ticket'],
            'total_ttc' => -$l['total_ttc'],
            'taux_tva' => $l['taux_tva'],
            'barbier_id' => $l['barbier_id'],
        ], $origine['lignes']);
        $tva = array_map(static fn (array $t): array => [
            'taux' => $t['taux'], 'base_ht' => -$t['base_ht'], 'tva' => -$t['tva'], 'ttc' => -$t['ttc'],
        ], $origine['tva']);
        $paiements = array_map(static fn (array $p): array => [
            'rang' => $p['rang'], 'moyen' => $p['moyen'], 'montant' => -$p['montant'], 'recu' => null, 'rendu' => 0,
        ], $origine['paiements']);

        $this->con->begin_transaction();
        try {
            $id = $this->enregistrer($entete, $lignes, $tva, $paiements);
            $this->mouvementsStock($lignes, 'annulation', $id, $operateurId);
            $this->journal->ecrire(Journal::ANNULATION, [
                'ticket_annule' => $origine['numero'],
                'ticket_annulation' => $this->numeroPour($id),
                'montant' => $origine['total_ttc'],
                'motif' => $motif,
            ], $operateurId, $id, true);
            $this->con->commit();
        } catch (Throwable $erreur) {
            $this->con->rollback();
            if (str_contains($erreur->getMessage(), 'uq_tickets_origine')) {
                throw new InvalidArgumentException('Ce ticket vient d\'être annulé sur un autre poste.');
            }
            throw $erreur;
        }
        return $this->resume($id);
    }

    /**
     * Réimpression d'un ticket : numérotée (1er, 2e duplicata…), conservée et inscrite au journal.
     *
     * @return array{numero:int, jeton:string}
     */
    public function duplicata(int $ticketId, ?int $operateurId): array
    {
        $ticket = $this->charger($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException('Ticket introuvable.');
        }
        $this->con->begin_transaction();
        try {
            $requete = $this->con->prepare('SELECT COALESCE(MAX(numero), 0) + 1 FROM duplicatas WHERE ticket_id = ? FOR UPDATE');
            $requete->bind_param('i', $ticketId);
            $requete->execute();
            $numero = (int) $requete->get_result()->fetch_row()[0];
            $requete = $this->con->prepare('INSERT INTO duplicatas (ticket_id, numero, operateur_id) VALUES (?, ?, ?)');
            $requete->bind_param('iii', $ticketId, $numero, $operateurId);
            $requete->execute();
            $this->journal->ecrire(Journal::DUPLICATA, ['ticket' => $ticket['numero'], 'duplicata' => $numero], $operateurId, $ticketId, true);
            $this->con->commit();
        } catch (Throwable $e) {
            $this->con->rollback();
            throw $e;
        }
        return ['numero' => $numero, 'jeton' => (string) $ticket['jeton_public']];
    }

    /** @return array<string, mixed>|null ticket complet (en-tête, lignes, tva, paiements, annule_par, origine) */
    public function charger(int $id): ?array
    {
        $requete = $this->con->prepare('SELECT * FROM tickets WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        $ticket = $requete->get_result()->fetch_assoc();
        return $ticket ? $this->completer($ticket) : null;
    }

    /** @return array<string, mixed>|null */
    public function chargerParJeton(string $jeton): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $jeton)) {
            return null;
        }
        $requete = $this->con->prepare('SELECT * FROM tickets WHERE jeton_public = ?');
        $requete->bind_param('s', $jeton);
        $requete->execute();
        $ticket = $requete->get_result()->fetch_assoc();
        return $ticket ? $this->completer($ticket) : null;
    }

    public static function numeroAffiche(int $numero): string
    {
        return str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    /**
     * JSON canonique d'un ticket : mêmes champs, même ordre, à l'enregistrement et au contrôle.
     *
     * @return array<string, mixed>
     */
    public static function canonique(array $entete, array $lignes, array $tva, array $paiements): array
    {
        $prendre = static fn (array $source, array $cles): array => array_map(static fn (string $c) => $source[$c] ?? null, array_combine($cles, $cles));
        return [
            'ticket' => $prendre($entete, ['chaine', 'numero', 'type', 'ticket_origine_id', 'date_ticket', 'operateur_id', 'barbier_id',
                'regime_tva', 'nb_articles', 'sous_total', 'remise_ticket', 'remise_type', 'remise_valeur', 'remise_motif',
                'total_ttc', 'total_ht', 'total_tva', 'version_logiciel', 'entete', 'jeton_public', 'cle_idempotence']),
            'lignes' => array_map(static fn (array $l): array => $prendre($l, ['rang', 'article_id', 'type', 'libelle', 'quantite',
                'prix_unitaire', 'remise_ligne', 'remise_type', 'remise_valeur', 'remise_motif', 'part_remise_ticket', 'total_ttc',
                'taux_tva', 'barbier_id']), $lignes),
            'tva' => array_map(static fn (array $t): array => $prendre($t, ['taux', 'base_ht', 'tva', 'ttc']), $tva),
            'paiements' => array_map(static fn (array $p): array => $prendre($p, ['rang', 'moyen', 'montant', 'recu', 'rendu']), $paiements),
        ];
    }

    /**
     * @return list<array{rang:int, moyen:string, montant:int, recu:int|null, rendu:int}>
     */
    public static function validerPaiements(mixed $saisis, int $total): array
    {
        if (!is_array($saisis) || !array_is_list($saisis) || count($saisis) > 10) {
            throw new InvalidArgumentException('Paiements illisibles.');
        }
        if ($total === 0) {
            if ($saisis) {
                throw new InvalidArgumentException('Ticket à 0 € : aucun paiement à enregistrer.');
            }
            return [];
        }
        $paiements = [];
        $somme = 0;
        foreach ($saisis as $rang => $p) {
            $moyen = is_array($p) ? ($p['moyen'] ?? null) : null;
            $montant = is_array($p) ? ($p['montant'] ?? null) : null;
            if (!is_string($moyen) || !isset(self::MOYENS[$moyen])) {
                throw new InvalidArgumentException('Moyen de paiement inconnu.');
            }
            if (!is_int($montant) || $montant <= 0 || $montant > Calcul::TOTAL_MAX) {
                throw new InvalidArgumentException('Montant de paiement invalide.');
            }
            $recu = null;
            $rendu = 0;
            if ($moyen === 'especes' && isset($p['recu'])) {
                $recu = $p['recu'];
                if (!is_int($recu) || $recu < $montant || $recu > $montant + 100000) {
                    throw new InvalidArgumentException('Somme reçue en espèces invalide.');
                }
                $rendu = $recu - $montant;
            }
            $paiements[] = ['rang' => $rang + 1, 'moyen' => $moyen, 'montant' => $montant, 'recu' => $recu, 'rendu' => $rendu];
            $somme += $montant;
        }
        if ($somme !== $total) {
            throw new InvalidArgumentException('Les paiements (' . Montant::format($somme) . ') ne correspondent pas au total (' . Montant::format($total) . ').');
        }
        return $paiements;
    }

    // ── Interne ──────────────────────────────────────────────────────────

    /** Numérote, chaîne, signe et écrit le ticket. À appeler dans une transaction. */
    private function enregistrer(array $entete, array $lignes, array $tva, array $paiements): int
    {
        $compteur = $entete['chaine'] === self::FORMATION ? 'ticket_formation' : 'ticket';
        $reservation = Chaine::reserver($this->con, $compteur);
        $entete['numero'] = $reservation['numero'];
        $entete['date_ticket'] = Horloge::date('Y-m-d H:i:s');
        // Après le Z du jour, la journée est fermée (contrôlé sous le verrou du compteur : pas de course avec le Z).
        $jour = substr($entete['date_ticket'], 0, 10);
        $z = $this->con->prepare("SELECT 1 FROM clotures WHERE type = 'jour' AND periode = ?");
        $z->bind_param('s', $jour);
        $z->execute();
        $journeeFermee = (bool) $z->get_result()->fetch_row(); // toujours lire le résultat (sinon la connexion se bloque)
        if ($entete['chaine'] === self::REEL && $journeeFermee) {
            throw new InvalidArgumentException('La journée est clôturée (Z fait) : plus de vente ni d\'annulation aujourd\'hui. Elles reprendront demain.');
        }
        $empreinte = Chaine::empreinte($reservation['precedente'], self::canonique($entete, $lignes, $tva, $paiements));
        $signature = Signature::pour($this->tenant)->signer($empreinte);
        $e = $entete;
        $precedente = $reservation['precedente'];

        $requete = $this->con->prepare(
            'INSERT INTO tickets (chaine, numero, type, ticket_origine_id, date_ticket, operateur_id, barbier_id, regime_tva, nb_articles,
                sous_total, remise_ticket, remise_type, remise_valeur, remise_motif, total_ttc, total_ht, total_tva, version_logiciel,
                entete, jeton_public, cle_idempotence, hash_precedent, hash, signature)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $requete->bind_param(
            'sisisiisiiisisiiisssssss',
            $e['chaine'], $e['numero'], $e['type'], $e['ticket_origine_id'], $e['date_ticket'], $e['operateur_id'], $e['barbier_id'],
            $e['regime_tva'], $e['nb_articles'], $e['sous_total'], $e['remise_ticket'], $e['remise_type'], $e['remise_valeur'],
            $e['remise_motif'], $e['total_ttc'], $e['total_ht'], $e['total_tva'], $e['version_logiciel'], $e['entete'], $e['jeton_public'],
            $e['cle_idempotence'], $precedente, $empreinte, $signature
        );
        $requete->execute();
        $id = (int) $this->con->insert_id;

        $requete = $this->con->prepare(
            'INSERT INTO ticket_lignes (ticket_id, rang, article_id, type, libelle, quantite, prix_unitaire, remise_ligne, remise_type,
                remise_valeur, remise_motif, part_remise_ticket, total_ttc, taux_tva, barbier_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lignes as $l) {
            $requete->bind_param(
                'iiissiiisisiiii',
                $id, $l['rang'], $l['article_id'], $l['type'], $l['libelle'], $l['quantite'], $l['prix_unitaire'], $l['remise_ligne'],
                $l['remise_type'], $l['remise_valeur'], $l['remise_motif'], $l['part_remise_ticket'], $l['total_ttc'], $l['taux_tva'], $l['barbier_id']
            );
            $requete->execute();
        }
        $requete = $this->con->prepare('INSERT INTO ticket_tva (ticket_id, taux, base_ht, tva, ttc) VALUES (?, ?, ?, ?, ?)');
        foreach ($tva as $t) {
            $requete->bind_param('iiiii', $id, $t['taux'], $t['base_ht'], $t['tva'], $t['ttc']);
            $requete->execute();
        }
        $requete = $this->con->prepare('INSERT INTO ticket_paiements (ticket_id, rang, moyen, montant, recu, rendu) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($paiements as $p) {
            $requete->bind_param('iisiii', $id, $p['rang'], $p['moyen'], $p['montant'], $p['recu'], $p['rendu']);
            $requete->execute();
        }
        Chaine::avancer($this->con, $compteur, $entete['numero'], $empreinte);
        return $id;
    }

    /** Stock des produits suivis : sortie à la vente, retour à l'annulation. */
    private function mouvementsStock(array $lignes, string $motif, int $ticketId, int $operateurId): void
    {
        $maj = $this->con->prepare("UPDATE articles SET stock = stock - ? WHERE id = ? AND type = 'produit' AND stock IS NOT NULL");
        $mouvement = $this->con->prepare('INSERT INTO mouvements_stock (article_id, quantite, motif, ticket_id, operateur_id) VALUES (?, ?, ?, ?, ?)');
        foreach ($lignes as $l) {
            if ($l['type'] !== Articles::PRODUIT || $l['article_id'] === null) {
                continue;
            }
            $quantite = (int) $l['quantite']; // négative pour une annulation : le stock remonte
            $articleId = (int) $l['article_id'];
            $maj->bind_param('ii', $quantite, $articleId);
            $maj->execute();
            if ($this->con->affected_rows > 0) {
                $sortie = -$quantite;
                $mouvement->bind_param('iisii', $articleId, $sortie, $motif, $ticketId, $operateurId);
                $mouvement->execute();
            }
        }
    }

    /** @return array<string, mixed> */
    private function completer(array $ticket): array
    {
        $id = (int) $ticket['id'];
        $lire = function (string $sql) use ($id): array {
            $requete = $this->con->prepare($sql);
            $requete->bind_param('i', $id);
            $requete->execute();
            return $requete->get_result()->fetch_all(MYSQLI_ASSOC);
        };
        $ticket['lignes'] = $lire('SELECT * FROM ticket_lignes WHERE ticket_id = ? ORDER BY rang');
        $ticket['tva'] = $lire('SELECT * FROM ticket_tva WHERE ticket_id = ? ORDER BY taux DESC');
        $ticket['paiements'] = $lire('SELECT * FROM ticket_paiements WHERE ticket_id = ? ORDER BY rang');
        $annulation = $lire('SELECT id, numero, date_ticket FROM tickets WHERE ticket_origine_id = ?');
        $ticket['annule_par'] = $annulation[0] ?? null;
        $ticket['origine'] = null;
        if ($ticket['ticket_origine_id'] !== null) {
            $requete = $this->con->prepare('SELECT id, numero, date_ticket FROM tickets WHERE id = ?');
            $origine = (int) $ticket['ticket_origine_id'];
            $requete->bind_param('i', $origine);
            $requete->execute();
            $ticket['origine'] = $requete->get_result()->fetch_assoc() ?: null;
        }
        return $ticket;
    }

    /** @return array{id:int, numero:int, jeton:string, total:int, rendu:int} */
    private function resume(int $id): array
    {
        $ticket = $this->charger($id);
        return [
            'id' => $id,
            'numero' => (int) $ticket['numero'],
            'jeton' => (string) $ticket['jeton_public'],
            'total' => (int) $ticket['total_ttc'],
            'rendu' => array_sum(array_column($ticket['paiements'], 'rendu')),
        ];
    }

    /** @return array{id:int, numero:int, jeton:string, total:int, rendu:int}|null */
    private function parCle(string $cle): ?array
    {
        $requete = $this->con->prepare('SELECT id FROM tickets WHERE cle_idempotence = ?');
        $requete->bind_param('s', $cle);
        $requete->execute();
        $id = $requete->get_result()->fetch_row()[0] ?? null;
        return $id === null ? null : $this->resume((int) $id);
    }

    private function numeroPour(int $id): int
    {
        $requete = $this->con->prepare('SELECT numero FROM tickets WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        return (int) $requete->get_result()->fetch_row()[0];
    }

    /** @return array<int, true> */
    private function membresActifs(): array
    {
        $ids = [];
        foreach ($this->con->query('SELECT id FROM utilisateurs WHERE actif = 1') as $ligne) {
            $ids[(int) $ligne['id']] = true;
        }
        return $ids;
    }

    /** Identité du salon recopiée dans le ticket (les mentions obligatoires restent celles du jour de la vente). */
    private static function entete(array $p): string
    {
        $champs = ['nom_salon', 'raison_sociale', 'adresse', 'code_postal', 'ville', 'telephone', 'siret', 'tva_intracom', 'mention_ticket'];
        return Chaine::json(array_combine($champs, array_map(static fn (string $c) => $p[$c] ?? null, $champs)));
    }

    private static function motif(?string $motif): ?string
    {
        $motif = $motif === null ? '' : trim($motif);
        return $motif === '' ? null : $motif;
    }
}
