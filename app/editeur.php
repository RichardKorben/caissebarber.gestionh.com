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
    'raison_sociale' => 'À COMPLÉTER',
    'adresse' => 'À COMPLÉTER',
    'code_postal' => 'À COMPLÉTER',
    'ville' => 'À COMPLÉTER',
    'siren' => 'À COMPLÉTER',
    'representant' => 'À COMPLÉTER', // nom et qualité du signataire
    'logiciel' => 'BarberPay',
    'version_majeure' => '0', // l'attestation couvre une version majeure (0.x) ; à renouveler à chaque version majeure
];
