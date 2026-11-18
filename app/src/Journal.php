<?php
declare(strict_types=1);

namespace BarberPay;

use mysqli;
use Throwable;

/**
 * Journal des événements techniques (JET) : chaîné, signé, en ajout seul.
 */
final class Journal
{
    public const CONNEXION = 'CONNEXION';
    public const DECONNEXION = 'DECONNEXION';
    public const DEVERROUILLAGE = 'DEVERROUILLAGE';
    public const ANNULATION = 'ANNULATION';
    public const ARTICLE_CREE = 'ARTICLE_CREE';
    public const ARTICLE_MODIFIE = 'ARTICLE_MODIFIE';
    public const ARTICLE_ACTIVATION = 'ARTICLE_ACTIVATION';
    public const PARAMETRES_MODIFIES = 'PARAMETRES_MODIFIES';
    public const REGIME_TVA_MODIFIE = 'REGIME_TVA_MODIFIE';
    public const EQUIPE_MODIFIEE = 'EQUIPE_MODIFIEE';
    public const CONTROLE_INTEGRITE = 'CONTROLE_INTEGRITE';
    public const OUVERTURE_CAISSE = 'OUVERTURE_CAISSE';
    public const CLOTURE = 'CLOTURE';
    public const DUPLICATA = 'DUPLICATA';
    public const REGIME_TVA_APPLIQUE = 'REGIME_TVA_APPLIQUE';
    public const ARCHIVE = 'ARCHIVE';
    public const EXPORT = 'EXPORT';
    public const MODE_FORMATION = 'MODE_FORMATION';

    public function __construct(private readonly mysqli $con, private readonly Tenant $tenant)
    {
    }

    /**
     * Écrit un événement.
     *
     * @param bool $dansTransaction true si l'appelant a déjà ouvert une transaction (encaissement, annulation) :
     *                              l'événement est alors validé ou annulé avec elle.
     */
    public function ecrire(string $code, array $details, ?int $operateurId, ?int $ticketId = null, bool $dansTransaction = false): void
    {
        if (!$dansTransaction) {
            $this->con->begin_transaction();
        }
        try {
            $reservation = Chaine::reserver($this->con, 'journal');
            $numero = $reservation['numero'];
            $date = Horloge::date('Y-m-d H:i:s');
            $json = Chaine::json($details);
            $version = VERSION;
            $canonique = self::canonique($numero, $date, $code, $operateurId, $ticketId, $json, $version);
            $empreinte = Chaine::empreinte($reservation['precedente'], $canonique);
            $signature = Signature::pour($this->tenant)->signer($empreinte);
            $precedente = $reservation['precedente'];

            $requete = $this->con->prepare(
                'INSERT INTO journal (numero, date_evenement, code, operateur_id, ticket_id, details, version_logiciel, hash_precedent, hash, signature)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $requete->bind_param('issiisssss', $numero, $date, $code, $operateurId, $ticketId, $json, $version, $precedente, $empreinte, $signature);
            $requete->execute();
            Chaine::avancer($this->con, 'journal', $numero, $empreinte);
            if (!$dansTransaction) {
                $this->con->commit();
            }
        } catch (Throwable $erreur) {
            if (!$dansTransaction) {
                $this->con->rollback();
            }
            throw $erreur;
        }
    }

    /** @return array<string, mixed> */
    public static function canonique(int $numero, string $date, string $code, ?int $operateurId, ?int $ticketId, string $details, string $version): array
    {
        return [
            'numero' => $numero,
            'date' => $date,
            'code' => $code,
            'operateur' => $operateurId,
            'ticket' => $ticketId,
            'details' => $details,
            'version' => $version,
        ];
    }
}
