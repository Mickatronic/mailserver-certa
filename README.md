# Réseau CERTA – gestion des établissements

Application PHP 8.1+ / MySQL 8 (sans framework) pour gérer les établissements,
leurs étudiants et enseignants, avec adresses `prenom.nom@<UAI>.reseaucerta.org`.

## Installation

1. Créer la base : `mysql -u root -p < database/schema.sql`
   (ou importer le fichier dans phpMyAdmin).
2. Adapter `config/config.php` (ou créer `config/config.local.php` qui renvoie
   uniquement les clés à surcharger).
   La connexion applicative doit pouvoir lire et modifier les tables de la base
   `mailserver` sur le même serveur MySQL. Le schéma initial crée cette base et
   les tables Postfix ; pour une installation déjà en service, appliquer une
   fois `database/migration_mailserver.sql`. Le compte SQL de l'application
   doit avoir les droits `SELECT`, `INSERT`, `UPDATE` et `DELETE` sur
   `mailserver.*`.
3. Pointer la racine web (DocumentRoot) sur `public/`.
   Sous WAMP sans virtual host : `'base_url' => '/CERTA-Microsoft/public'`.
4. Créer le super admin :
   `php scripts/create_superadmin.php admin@reseaucerta.org Prénom Nom`
   → affiche un mot de passe temporaire à changer à la première connexion.

## Déploiement avec Dokploy

Créer une application Dokploy de type **Docker Compose** depuis le dépôt et
utiliser `docker-compose.yml` à la racine. Ajouter les variables ci-dessous
dans l'environnement Dokploy avant le premier déploiement. Dans la configuration
de domaine Dokploy, router vers le service `app`, port `80`, et activer HTTPS.
La base de données n'expose pas de port public ; le service PHP y accède via
l'hôte interne `db`.

| Variable | Obligatoire | Valeur / rôle |
|----------|-------------|----------------|
| `DB_PASSWORD` | Oui | Mot de passe fort et unique pour l'utilisateur MariaDB `certa_app`. |
| `DB_ROOT_PASSWORD` | Oui | Mot de passe fort et unique pour l'administrateur MariaDB. |
| `MAIL_DOMAIN` | Non | Domaine des emails UAI, par défaut `reseaucerta.org`. |
| `BASE_URL` | Non | Laisser vide pour une application servie à la racine du domaine. Sinon, saisir le chemin sans slash final, par exemple `/certa`. |
| `UAI_VERIFY_KEY` | Non | Vérification de la clé UAI, `true` par défaut. |
| `IMPORT_MAX_SIZE` | Non | Taille maximale des imports CSV en octets, `2097152` (2 Mio) par défaut. |

Le nom de la base applicative est `reseau_certa` et celui des tables Postfix est
`mailserver` ; les deux sont créés dans MariaDB. Ne pas remplacer `DB_NAME` par
`mailserver` : l'application l'utilise pour ses propres tables. Le volume
`mariadb_data` conserve les données lors des redéploiements. Le script
`database/schema.sql` est exécuté automatiquement uniquement lors de
l'initialisation d'une base vide. Il contient des `DROP TABLE` pour permettre
une installation initiale propre : **ne pas supprimer le volume de données en
production**. Pour une base existante, sauvegarder les données puis appliquer
les migrations documentées ci-dessus manuellement ; les scripts
`docker-entrypoint-initdb.d` ne se rejouent pas sur un volume déjà initialisé.

Après le premier déploiement, créer le super administrateur depuis le terminal
du conteneur `app` avec
`php scripts/create_superadmin.php admin@reseaucerta.org Prénom Nom`.
Ce Compose héberge l'application et MariaDB, pas les services Postfix/Dovecot :
ceux-ci doivent pouvoir joindre la base `mailserver` et lire les tables virtuelles.

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
Chaque établissement est associé à une entrée `mailserver.virtual_domains` nommée
`<uai>.reseaucerta.org` (sans `@`, format attendu par Postfix). Chaque compte
d'établissement est associé à une entrée `mailserver.virtual_users`, avec un
quota de 10 MiB (`10485760` octets). Le mot de passe est synchronisé lors de sa
création, de sa réinitialisation, de son changement ou d'une connexion réussie,
au format Dovecot `{BLF-CRYPT}` (bcrypt). Les utilisateurs déjà présents lors de
la migration sont provisionnés à leur prochaine connexion ou mise à jour de mot
de passe : leur ancien hash applicatif ne permet pas de recalculer le hash
Dovecot sans connaître le mot de passe en clair.
Si `mail_domain` est personnalisé, adapter la variable `@mail_domain` dans la
migration avant de l'exécuter.
Pour une base existante, appliquer une fois `database/migration_email_personnel.sql`.
Appliquer ensuite une fois `database/migration_etablissement_type_classe_bts.sql`
et `database/migration_mailserver.sql` pour ajouter le type d'établissement, les
informations de classe/BTS et les liens Postfix.

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
