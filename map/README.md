# Atlas JDR - Commit 2

## Objectif

`/map/` heberge un outil de cartes JDR autonome, separe de l outil musical a la racine du depot.

Ce deuxieme commit ajoute :

- l administration des utilisateurs ;
- la creation et la gestion des campagnes ;
- la gestion des membres de campagne ;
- les permissions globales et par campagne ;
- l archivage des campagnes ;
- la preparation de l acces a la future table virtuelle.

## Prerequis

- PHP 8.2 recommande
- `PDO` et `pdo_sqlite`
- `json`
- un serveur PHP/Apache capable d executer le dossier `map/`

## Donnees et permissions

Le dossier [`map/data/`](./data/) doit rester accessible en ecriture par PHP.

La base SQLite `map.sqlite` est :

- creee automatiquement si elle n existe pas ;
- migree de facon non destructive depuis le schema du commit 1 ;
- ignoree par Git via le fichier `.gitignore` a la racine du depot.

Le fichier [`map/data/.htaccess`](./data/.htaccess) bloque l acces HTTP direct au dossier sous Apache.

## Installation

1. Verifier que `pdo_sqlite` est actif.
2. Verifier que PHP peut ecrire dans `map/data/`.
3. Si aucun utilisateur n existe, ouvrir `/map/setup.php`.
4. Creer le premier administrateur.
5. Se connecter via `/map/login.php`.

## Authentification et securite

Le module `map/` utilise :

- un cookie de session dedie `jdr_map_session` ;
- `HttpOnly` ;
- `SameSite=Lax` ;
- `Secure` automatiquement sous HTTPS ;
- `session.use_strict_mode` quand disponible ;
- des verifications CSRF sur tous les formulaires de modification ;
- une verification serveur des roles pour chaque page et chaque action.

Un utilisateur desactive :

- ne peut plus se connecter ;
- perd son acces a la session en cours lors de la verification suivante ;
- n apparait plus dans les choix standards d ajout de membres.

## Roles globaux

- `admin` : administration complete du module
- `gm` : maitre du jeu
- `player` : joueur

Les administrateurs peuvent :

- lister tous les comptes ;
- creer des utilisateurs ;
- modifier le nom affiche et le role global ;
- activer ou desactiver un compte ;
- reinitialiser un mot de passe ;
- gerer toutes les campagnes.

Protections importantes :

- un non-admin ne peut pas acceder a `/map/admin/` ;
- un administrateur ne peut pas desactiver son propre compte ;
- le dernier administrateur actif ne peut pas etre retrograde ni desactive.

## Roles de campagne

Une campagne comporte :

- un proprietaire principal ;
- zero ou plusieurs MJ secondaires (`gm`) ;
- zero ou plusieurs joueurs (`player`).

Regles appliquees :

- le proprietaire principal n est jamais duplique dans `campaign_members` ;
- un joueur global ne peut pas devenir MJ secondaire ;
- un MJ secondaire peut consulter la campagne mais ne peut pas modifier les membres ni transferer la propriete ;
- seuls le proprietaire principal et les administrateurs peuvent modifier la campagne et sa liste de membres.

## Utilisateurs

Pages disponibles :

- [`map/admin/users.php`](./admin/users.php)
- [`map/admin/user-create.php`](./admin/user-create.php)
- [`map/admin/user-edit.php`](./admin/user-edit.php)
- [`map/admin/user-password.php`](./admin/user-password.php)

Contraintes :

- nom d utilisateur entre 3 et 40 caracteres ;
- lettres, chiffres, tirets et underscores uniquement ;
- unicite insensible a la casse ;
- mot de passe de 8 caracteres minimum ;
- confirmation obligatoire a la creation et lors d une reinitialisation.

## Campagnes

Pages disponibles :

- [`map/index.php`](./index.php) : accueil protege et campagnes accessibles
- [`map/campaign-create.php`](./campaign-create.php)
- [`map/campaign.php`](./campaign.php)

Fonctionnalites actuelles :

- creation de campagne par un admin ou un MJ ;
- transfert de proprietaire vers un `gm` ou `admin` actif ;
- edition du nom et de la description ;
- archivage et restauration ;
- affichage des campagnes accessibles selon les permissions ;
- affichage distinct des archives pour les admins et MJ.

## Membres de campagne

Le proprietaire principal et les administrateurs peuvent :

- ajouter un membre ;
- choisir son role `gm` ou `player` ;
- changer ce role ;
- retirer un membre.

Le retrait d un membre supprime immediatement son acces a la campagne.

## API

- [`map/api/session.php`](./api/session.php) retourne uniquement les informations publiques de la session courante.

## Limitations actuelles

Ce commit ne developpe pas encore :

- les images de cartes ;
- le canvas ;
- les pions et leurs deplacements ;
- le brouillard de guerre ;
- les dessins et mesures ;
- la synchronisation entre joueurs ;
- l initiative ;
- les des ;
- le chat ;
- la table virtuelle en elle-meme.
