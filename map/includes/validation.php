<?php
declare(strict_types=1);

function mapNormalizeUsername(string $username): string
{
    return strtolower(trim($username));
}

function mapValidatePositiveInt(mixed $value, string $label = 'Identifiant'): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }

    if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value)) {
        return (int) $value;
    }

    throw new DomainException($label . ' invalide.');
}

function mapValidateUsername(string $username): string
{
    $trimmed = trim($username);

    if (!preg_match('/\A[a-zA-Z0-9_-]{3,40}\z/', $trimmed)) {
        throw new DomainException('Le nom d utilisateur doit contenir entre 3 et 40 caracteres, avec lettres, chiffres, tirets ou underscores uniquement.');
    }

    return $trimmed;
}

function mapValidateDisplayName(string $displayName): string
{
    $trimmed = trim($displayName);

    if ($trimmed === '' || !preg_match('/\A.{1,80}\z/u', $trimmed)) {
        throw new DomainException('Le nom affiche doit contenir entre 1 et 80 caracteres.');
    }

    return $trimmed;
}

function mapValidatePasswordInput(string $password): string
{
    if ($password === '') {
        throw new DomainException('Le mot de passe est requis.');
    }

    return $password;
}

function mapValidatePasswordStrength(string $password): string
{
    if (strlen($password) < 8) {
        throw new DomainException('Le mot de passe doit contenir au moins 8 caracteres.');
    }

    return $password;
}

function mapValidateInitialPassword(string $password, string $confirmation): string
{
    mapValidatePasswordStrength($password);

    if ($password !== $confirmation) {
        throw new DomainException('La confirmation du mot de passe ne correspond pas.');
    }

    return $password;
}

function mapValidateRole(string $role): string
{
    $allowedRoles = ['admin', 'gm', 'player'];

    if (!in_array($role, $allowedRoles, true)) {
        throw new DomainException('Role utilisateur invalide.');
    }

    return $role;
}

function mapValidateCampaignMemberRole(string $role): string
{
    $allowedRoles = ['gm', 'player'];

    if (!in_array($role, $allowedRoles, true)) {
        throw new DomainException('Role de membre de campagne invalide.');
    }

    return $role;
}

function mapValidateCampaignName(string $name): string
{
    $trimmed = trim($name);

    if ($trimmed === '' || !preg_match('/\A.{1,120}\z/u', $trimmed)) {
        throw new DomainException('Le nom de campagne doit contenir entre 1 et 120 caracteres.');
    }

    return $trimmed;
}

function mapValidateCampaignDescription(?string $description): ?string
{
    $description ??= '';
    $trimmed = trim($description);

    if ($trimmed === '') {
        return null;
    }

    if (mb_strlen($trimmed, 'UTF-8') > 5000) {
        throw new DomainException('La description de campagne ne peut pas depasser 5000 caracteres.');
    }

    return $trimmed;
}
