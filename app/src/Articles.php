<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use mysqli_sql_exception;

/**
 * Catalogue : services et produits.
 *
 * Un article n'est jamais supprimé, seulement désactivé : il pourra figurer sur des tickets
 * (les lignes de ticket copient le libellé et le prix au moment de la vente, étape 3).
 */
final class Articles
{
    public const SERVICE = 'service';
    public const PRODUIT = 'produit';
    public const TYPES = [self::SERVICE => 'Services', self::PRODUIT => 'Produits'];

    public function __construct(
        private readonly mysqli $con,
        private readonly ?Journal $journal = null,
        private readonly ?int $operateurId = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function lister(string $type, bool $avecInactifs = true): array
    {
        $sql = 'SELECT * FROM articles WHERE type = ?' . ($avecInactifs ? '' : ' AND actif = 1') . ' ORDER BY actif DESC, ordre, nom';
        $requete = $this->con->prepare($sql);
        $requete->bind_param('s', $type);
        $requete->execute();
        return $requete->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function trouver(int $id): ?array
    {
        $requete = $this->con->prepare('SELECT * FROM articles WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        return $requete->get_result()->fetch_assoc() ?: null;
    }

    /** @return array<string, int> nombre d'articles actifs par type */
    public function compter(): array
    {
        $nombres = [self::SERVICE => 0, self::PRODUIT => 0];
        foreach ($this->con->query('SELECT type, COUNT(*) n FROM articles WHERE actif = 1 GROUP BY type') as $ligne) {
            $nombres[$ligne['type']] = (int) $ligne['n'];
        }
        return $nombres;
    }

    /**
     * Crée (id null) ou modifie un article.
     *
     * @param array<string, mixed> $saisie
     * @return array{erreurs: array<string, string>, id: int|null}
     */
    public function enregistrer(string $type, array $saisie, ?int $id = null): array
    {
        if (!isset(self::TYPES[$type])) {
            return ['erreurs' => ['type' => 'Type inconnu.'], 'id' => null];
        }
        $erreurs = [];

        $nom = trim((string) ($saisie['nom'] ?? ''));
        if ($nom === '') {
            $erreurs['nom'] = 'Le nom est obligatoire.';
        } elseif (mb_strlen($nom) > 80) {
            $erreurs['nom'] = '80 caractères maximum.';
        }

        $prix = Montant::analyser((string) ($saisie['prix'] ?? ''));
        if ($prix === null) {
            $erreurs['prix'] = 'Prix invalide (exemple : 25 ou 25,50).';
        }

        $taux = (int) ($saisie['taux_tva'] ?? Tva::DEFAUT);
        if (!Tva::valide($taux)) {
            $erreurs['taux_tva'] = 'Taux de TVA invalide.';
        }

        $duree = null;
        $stock = null;
        $alerte = null;
        $codeBarre = null;
        if ($type === self::SERVICE) {
            $saisieDuree = trim((string) ($saisie['duree_minutes'] ?? ''));
            if ($saisieDuree !== '') {
                if (!ctype_digit($saisieDuree) || (int) $saisieDuree < 1 || (int) $saisieDuree > 600) {
                    $erreurs['duree_minutes'] = 'Durée en minutes, de 1 à 600.';
                } else {
                    $duree = (int) $saisieDuree;
                }
            }
        } else {
            $saisieStock = trim((string) ($saisie['stock'] ?? ''));
            if ($saisieStock !== '') {
                if (!preg_match('/^-?\d{1,6}$/', $saisieStock)) {
                    $erreurs['stock'] = 'Stock : un nombre entier.';
                } else {
                    $stock = (int) $saisieStock;
                }
            }
            $saisieAlerte = trim((string) ($saisie['stock_alerte'] ?? ''));
            if ($saisieAlerte !== '') {
                if (!ctype_digit($saisieAlerte) || (int) $saisieAlerte > 99999) {
                    $erreurs['stock_alerte'] = 'Seuil d\'alerte : un nombre entier positif.';
                } else {
                    $alerte = (int) $saisieAlerte;
                }
            }
            $saisieCode = preg_replace('/\s+/', '', (string) ($saisie['code_barre'] ?? ''));
            if ($saisieCode !== '') {
                if (!preg_match('/^[0-9A-Za-z\-]{4,64}$/', $saisieCode)) {
                    $erreurs['code_barre'] = 'Code-barres invalide.';
                } else {
                    $codeBarre = $saisieCode;
                }
            }
        }

        if ($erreurs) {
            return ['erreurs' => $erreurs, 'id' => $id];
        }

        $avant = $id !== null ? $this->trouver($id) : null;
        $this->con->begin_transaction();
        try {
            if ($id === null) {
                $suivant = $this->con->prepare('SELECT COALESCE(MAX(ordre), 0) + 1 FROM articles WHERE type = ?');
                $suivant->bind_param('s', $type);
                $suivant->execute();
                $ordre = (int) $suivant->get_result()->fetch_row()[0];
                $requete = $this->con->prepare(
                    'INSERT INTO articles (type, nom, prix_ttc, taux_tva, duree_minutes, stock, stock_alerte, code_barre, ordre)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $requete->bind_param('ssiiiiisi', $type, $nom, $prix, $taux, $duree, $stock, $alerte, $codeBarre, $ordre);
                $requete->execute();
                $id = (int) $this->con->insert_id;
                $this->journal?->ecrire(Journal::ARTICLE_CREE, ['article' => $id, 'type' => $type, 'nom' => $nom, 'prix' => $prix, 'taux_tva' => $taux], $this->operateurId, null, true);
            } else {
                $requete = $this->con->prepare(
                    'UPDATE articles SET nom = ?, prix_ttc = ?, taux_tva = ?, duree_minutes = ?, stock = ?, stock_alerte = ?, code_barre = ?
                     WHERE id = ? AND type = ?'
                );
                $requete->bind_param('siiiiisis', $nom, $prix, $taux, $duree, $stock, $alerte, $codeBarre, $id, $type);
                $requete->execute();
                // Journal : seulement ce qui compte fiscalement (nom, prix, TVA) et le stock corrigé à la main.
                $changements = [];
                foreach (['nom' => $nom, 'prix_ttc' => $prix, 'taux_tva' => $taux, 'stock' => $stock] as $champ => $valeur) {
                    if ($avant !== null && $avant[$champ] !== $valeur) {
                        $changements[$champ] = ['avant' => $avant[$champ], 'apres' => $valeur];
                    }
                }
                if ($changements) {
                    $this->journal?->ecrire(Journal::ARTICLE_MODIFIE, ['article' => $id, 'nom' => $nom, 'changements' => $changements], $this->operateurId, null, true);
                }
            }
            $this->con->commit();
        } catch (mysqli_sql_exception $erreur) {
            $this->con->rollback();
            if ($erreur->getCode() === 1062) { // doublon : code-barres déjà utilisé
                return ['erreurs' => ['code_barre' => 'Ce code-barres est déjà utilisé par un autre produit.'], 'id' => $id];
            }
            throw $erreur;
        }
        return ['erreurs' => [], 'id' => $id];
    }

    public function basculerActif(int $id): void
    {
        $article = $this->trouver($id);
        if ($article === null) {
            return;
        }
        $this->con->begin_transaction();
        $requete = $this->con->prepare('UPDATE articles SET actif = 1 - actif WHERE id = ?');
        $requete->bind_param('i', $id);
        $requete->execute();
        $this->journal?->ecrire(Journal::ARTICLE_ACTIVATION, ['article' => $id, 'nom' => $article['nom'], 'actif' => !$article['actif']], $this->operateurId, null, true);
        $this->con->commit();
    }

    /** Monte (-1) ou descend (+1) un article d'un cran parmi les articles actifs du même type. */
    public function deplacer(int $id, int $sens): void
    {
        $article = $this->trouver($id);
        if ($article === null || !$article['actif']) {
            return;
        }
        $liste = $this->lister((string) $article['type'], false);
        $ids = array_map(static fn (array $a): int => (int) $a['id'], $liste);
        $position = array_search($id, $ids, true);
        $cible = $position === false ? false : $position + ($sens < 0 ? -1 : 1);
        if ($cible === false || $cible < 0 || $cible >= count($ids)) {
            return;
        }
        [$ids[$position], $ids[$cible]] = [$ids[$cible], $ids[$position]];

        // On renumérote toute la liste : l'ordre reste propre (1, 2, 3…) même après des ajouts.
        $this->con->begin_transaction();
        $requete = $this->con->prepare('UPDATE articles SET ordre = ? WHERE id = ?');
        foreach ($ids as $rang => $idArticle) {
            $ordre = $rang + 1;
            $requete->bind_param('ii', $ordre, $idArticle);
            $requete->execute();
        }
        $this->con->commit();
    }
}
