<?php
/** BarberPay — outils d'affichage partagés par l'admin et les écrans du poste (icônes, champs de formulaire). */
declare(strict_types=1);

use function BarberPay\e;

/** Icônes en trait (style Lucide), décoratives. */
function icone(string $nom, int $taille = 24): string
{
    $traces = [
        'ciseaux' => '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
        'flacon' => '<path d="M10 2h4"/><path d="M10 2v4l-2 3v12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V9l-2-3V2"/><path d="M8 13h8"/>',
        'catalogue' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'equipe' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'salon' => '<path d="M3 9 4.5 4h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9h18"/><path d="M10 20v-5h4v5"/>',
        'caisse' => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8"/><path d="M8 11h.01M12 11h.01M16 11h.01M8 15h.01M12 15h.01M16 15h.01M8 19h.01M12 19h.01M16 19h.01"/>',
        'sortie' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'haut' => '<path d="m18 15-6-6-6 6"/>',
        'bas' => '<path d="m6 9 6 6 6-6"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'crayon' => '<path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>',
        'horloge' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'retour' => '<path d="m15 18-6-6 6-6"/>',
        'historique' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'recette' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'admin' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'cadenas' => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'imprimante' => '<path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'export' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
        'bouclier' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'oeil' => '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg class="icone" width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($traces[$nom] ?? '') . '</svg>';
}

/** Champ de formulaire avec libellé, aide et message d'erreur. */
function champ(string $nom, string $libelle, string $valeur, array $erreurs, array $options = []): string
{
    $type = $options['type'] ?? 'text';
    $id = 'champ-' . $nom;
    $aide = isset($options['aide']) ? '<p class="aide" id="' . $id . '-aide">' . e($options['aide']) . '</p>' : '';
    $erreur = isset($erreurs[$nom]) ? '<p class="erreur-champ" id="' . $id . '-erreur">' . e($erreurs[$nom]) . '</p>' : '';
    $decrit = trim((isset($options['aide']) ? $id . '-aide ' : '') . (isset($erreurs[$nom]) ? $id . '-erreur' : ''));
    $attributs = '';
    foreach (['inputmode', 'autocomplete', 'maxlength', 'placeholder', 'pattern'] as $attribut) {
        if (isset($options[$attribut])) {
            $attributs .= ' ' . $attribut . '="' . e((string) $options[$attribut]) . '"';
        }
    }
    if (!empty($options['requis'])) {
        $attributs .= ' required';
    }
    if ($decrit !== '') {
        $attributs .= ' aria-describedby="' . $decrit . '"';
    }
    if (isset($erreurs[$nom])) {
        $attributs .= ' aria-invalid="true"';
    }
    $suffixe = isset($options['suffixe']) ? '<span class="suffixe">' . e($options['suffixe']) . '</span>' : '';
    return '<div class="champ' . (isset($erreurs[$nom]) ? ' champ-en-erreur' : '') . '">'
        . '<label for="' . $id . '">' . e($libelle) . (!empty($options['requis']) ? ' <span class="requis" aria-hidden="true">*</span>' : '') . '</label>'
        . '<div class="saisie">' . '<input id="' . $id . '" name="' . e($nom) . '" type="' . e($type) . '" value="' . e($valeur) . '"' . $attributs . '>' . $suffixe . '</div>'
        . $aide . $erreur . '</div>';
}
