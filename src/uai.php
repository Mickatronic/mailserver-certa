<?php
declare(strict_types=1);

/**
 * Code UAI (Unité Administrative Immatriculée, ex-RNE) : 7 chiffres + 1 lettre-clé.
 *  - les 3 premiers chiffres = département (059 = Nord)
 *  - la lettre = (nombre formé par les 7 chiffres) mod 23, dans l'alphabet
 *    privé de I, O et Q.
 */
const UAI_ALPHABET = 'ABCDEFGHJKLMNPRSTUVWXYZ';

function uai_normaliser(string $uai): string
{
    return strtoupper(preg_replace('/\s+/', '', $uai));
}

function uai_format_valide(string $uai): bool
{
    return (bool)preg_match('/^[0-9]{7}[A-Z]$/', $uai);
}

function uai_cle_attendue(string $uai): string
{
    return UAI_ALPHABET[((int)substr($uai, 0, 7)) % 23];
}

/** Retourne null si valide, sinon le message d'erreur. */
function uai_erreur(string $uai): ?string
{
    if (!uai_format_valide($uai)) {
        return 'Le code UAI doit contenir 7 chiffres suivis d\'une lettre (ex : 0592222X).';
    }
    if (config('uai_verifier_cle') && $uai[7] !== uai_cle_attendue($uai)) {
        return sprintf(
            'Lettre-clé UAI incorrecte : pour %s la clé attendue est « %s ».',
            substr($uai, 0, 7),
            uai_cle_attendue($uai)
        );
    }
    return null;
}

/** Domaine mail d'un établissement : 0592222x.reseaucerta.org */
function uai_domaine(string $uai): string
{
    return strtolower($uai) . '.' . config('mail_domain');
}
