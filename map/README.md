# Sus20 - Fondation

## Objectif

`/map/` héberge la table de jeu virtuelle Sus20, séparée de l’outil musical à la racine du dépôt.

Ce deuxieme commit ajoute :

- l administration des utilisateurs ;
- la creation et la gestion des campagnes ;
- la gestion des membres de campagne ;
- les permissions globales et par campagne ;
- l archivage des campagnes ;
- la preparation de l acces a la future table virtuelle.

Le module de notes ajoute ensuite :

- des notes personnelles visibles uniquement par leur auteur ;
- des notes reservees aux MJ de la campagne ;
- des notes partagees avec tous les membres ;
- des dossiers, des tags, une recherche et des filtres ;
- une sauvegarde automatique avec detection des conflits de revision.

Le moteur de des ajoute :

- les expressions `1d20+5`, `4d6kh3` et les combinaisons de plusieurs des ;
- les modes avantage et desavantage sur le premier `1d20` ;
- une conversion uniforme par rejet, sans biais de modulo ;
- un resultat produit et enregistre exclusivement cote serveur ;
- un historique commun a tous les membres de la campagne ;
- la provenance ANU quantique ou le secours cryptographique pour chaque lancer.

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

Elle est la source canonique de toutes les donnees JDR. Les futurs modules
(notes, combats, des, cartes et pions) devront posseder une cle etrangere vers
`campaigns.id`. Le fichier `data/campaigns.json` a la racine reste reserve a
l ancien outil musical.

## Migrations SQLite

Les migrations sont stockees dans [`map/migrations/`](./migrations/) et suivent
le format `NNN_nom.php`. Chaque migration :

- possede une version entiere unique et un nom stable ;
- est executee dans une transaction ;
- est enregistree dans la table `schema_migrations` ;
- n est jamais rejouee apres son application.

Pour modifier le schema, ajouter un nouveau fichier numerote sans modifier une
migration deja livree. La connexion SQLite active aussi les cles etrangeres,
un delai d attente en cas de verrou et le mode WAL pour mieux supporter les
lectures simultanees.

Les migrations sont appliquees automatiquement a la premiere connexion. Elles
peuvent aussi etre lancees explicitement depuis la racine du depot :

```powershell
php map/bin/migrate.php
```

Si le PHP CLI de MAMP ne charge pas les extensions alors que leurs DLL sont
installees, elles peuvent etre activees pour la commande :

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 map/bin/migrate.php
```

Le test autonome `php map/tests/database_migrations_test.php` controle une base
vierge, un ancien schema avec donnees et une copie de la base locale actuelle.

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
- [`map/api/campaigns.php`](./api/campaigns.php) retourne les campagnes SQLite accessibles ou une campagne precise avec `?id=...`.
- [`map/api/notes.php`](./api/notes.php) liste et modifie les notes accessibles, avec controle CSRF et permissions cote serveur.
- [`map/api/dice.php`](./api/dice.php) genere les lancers cote serveur et retourne l historique de la campagne.

## Notes de campagne

La page [`map/notes.php`](./notes.php) est accessible depuis la fiche d une
campagne. Les visibilites sont appliquees par l API :

- `personal` : auteur uniquement ;
- `gm` : administrateurs, proprietaire et MJ secondaires ;
- `shared` : tous les membres de la campagne.

Une note partagee est modifiable par son auteur ou un MJ. Seul son auteur peut
changer sa visibilite. La colonne `revision` empeche une sauvegarde automatique
ancienne d ecraser silencieusement une modification plus recente.

## Des et hasard quantique

La page [`map/dice.php`](./dice.php) est accessible depuis la fiche de campagne.
Le navigateur transmet uniquement l expression et le mode demandes. Le serveur
parse l expression, obtient les valeurs aleatoires, calcule le total et insere
le resultat detaille dans `dice_rolls`.

La source principale utilise l API moderne ANU Quantum Numbers. Un
administrateur peut ouvrir `Administration > Reglages des`, coller sa cle et
utiliser `Tester et enregistrer`. La cle n est jamais exposee aux joueurs ni
renvoyee au navigateur apres l enregistrement ; elle reste dans la base SQLite
protegee.

Les variables d environnement restent disponibles pour un deploiement serveur
et ont priorite sur le reglage SQLite :

- `ATLAS_DICE_RANDOM_SOURCE` : `anu` par defaut, ou `system` ;
- `ATLAS_ANU_QRNG_URL` : endpoint HTTPS ANU (par defaut `https://api.quantumnumbers.anu.edu.au`) ;
- `ATLAS_ANU_QRNG_API_KEY` : cle du service moderne ;
- `ATLAS_ANU_QRNG_API_KEY_HEADER` : `x-api-key` par defaut ;
- `ATLAS_ANU_QRNG_TIMEOUT_MS` : delai de 500 a 5000 ms (`4000` par defaut).
- `ATLAS_CURL_CA_BUNDLE` : certificat racine PEM si PHP ne connait pas celui du systeme.

Sous MAMP, Atlas detecte automatiquement `MAMP/certs/cacert.pem` afin de
conserver la verification TLS sans devoir la desactiver.

Sans cle, Atlas passe immediatement au secours cryptographique sans appeler
l ancien endpoint en cours de retrait. Si ANU refuse la cle, atteint son quota
ou ne repond pas, le lancer partiel est abandonne et recommence integralement
avec `random_int()`. La source effectivement utilisee et la raison du secours
sont conservees dans le journal.

Documentation officielle :
https://quantumnumbers.anu.edu.au/documentation

## Limitations actuelles

Ce commit ne developpe pas encore :

- les images de cartes ;
- le canvas ;
- les pions et leurs deplacements ;
- le brouillard de guerre ;
- les dessins et mesures ;
- la synchronisation entre joueurs ;
- l initiative ;
- le chat ;
- la table virtuelle en elle-meme.
