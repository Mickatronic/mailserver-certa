# Réseau CERTA – gestion des établissements

Application PHP 8.1+ / MySQL 8 (sans framework) pour gérer les établissements,
leurs étudiants et enseignants, avec adresses `prenom.nom@<UAI>.reseaucerta.org`.

## Installation

1. Créer la base : `mysql -u root -p < database/schema.sql`
   (ou importer le fichier dans phpMyAdmin).
2. Adapter `config/config.php` (ou créer `config/config.local.php` qui renvoie
   uniquement les clés à surcharger).
3. Pointer la racine web (DocumentRoot) sur `public/`.
   Sous WAMP sans virtual host : `'base_url' => '/CERTA-Microsoft/public'`.
4. Créer le super admin :
   `php scripts/create_superadmin.php admin@reseaucerta.org Prénom Nom`
   → affiche un mot de passe temporaire à changer à la première connexion.

## Rôles

| Rôle | Rattachement | Droits |
|------|--------------|--------|
| SUPER_ADMIN | aucun établissement | académies, établissements, nomination des admins, journal global |
| ADMIN (+ TEACHER) | un établissement | CRUD + import des étudiants/enseignants de son établissement, journal local |
| TEACHER / STUDENT | un établissement | « Mon espace » |

À la création, l'adresse de connexion au domaine UAI est générée automatiquement à
partir du prénom et du nom (avec un suffixe en cas d'homonyme). Une adresse email
personnelle distincte peut être renseignée pour les étudiants et enseignants ;
elle est facultative pour les étudiants, obligatoire pour les enseignants et
administrateurs. Les imports CSV
acceptent `email_personnel` (ou l'ancien en-tête `email`) comme adresse de contact.
Pour une base existante, appliquer une fois `database/migration_email_personnel.sql`.
Appliquer ensuite une fois `database/migration_etablissement_type_classe_bts.sql`
pour ajouter le type d'établissement et les informations de classe/BTS.

Les étudiants doivent avoir un nom de classe et une année de passage du BTS
(année sur quatre chiffres, de 1900 à 2200). Ces informations sont disponibles
dans leur fiche et dans les filtres de la liste des utilisateurs. L'import CSV
les prend dans les colonnes `classe` et `annee_bts` ; elles sont obligatoires
pour les lignes d'étudiants et ignorées pour les enseignants.

Les comptes administrateurs ne sont modifiables que par le super admin.

## Pages

- `public/superadmin/` : tableau de bord, établissements (CRUD, activation),
  administrateurs d'un établissement, académies, journal des accès (+ export CSV).
- `public/admin/` : tableau de bord, utilisateurs (CRUD, reset mdp, activation),
  import CSV (simulation, génération des emails, export des identifiants), journal des accès.

## Sécurité

Requêtes préparées PDO, `password_hash`, jetons CSRF, cookies HttpOnly/SameSite,
cloisonnement par établissement, blocage après 5 échecs / 15 min (email ou IP),
mot de passe temporaire à changer à la première connexion, triggers SQL vérifiant
le domaine email, journalisation des connexions et des actions.
