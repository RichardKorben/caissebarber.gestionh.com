<?php
/**
 * BarberPay — identité de l'éditeur du logiciel, reprise sur l'attestation individuelle remise à chaque barber
 * (art. 286 I 3° bis du CGI ; modèle d'attestation publié au BOFiP, BOI-LETTRE-000242).
 *
 * À COMPLÉTER avec les informations réelles de l'entreprise éditrice avant toute remise d'attestation :
 * tant qu'un champ contient « À COMPLÉTER », l'attestation s'affiche avec un avertissement et ne peut pas être imprimée.
 */
declare(strict_types=1);

return [
    // Annuaire des entreprises (recherche-entreprises.api.gouv.fr), consulté le 18/11/2026 : SAS active, APE 58.29C.
    'raison_sociale' => 'KDSOFT (SAS)',
    'adresse' => '8 rue de la Limagne',
    'code_postal' => '63360',
    'ville' => 'Saint-Beauzire',
    'siren' => '910 135 466',
    'representant' => 'Loic PAPON, président', // nom et qualité du signataire
    'logiciel' => 'BarberPay',
    'version_majeure' => '0', // l'attestation couvre une version majeure (0.x) ; à renouveler à chaque version majeure
];
