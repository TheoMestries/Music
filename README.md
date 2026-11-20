# SuSZic et Sus20

Le depot contient deux interfaces qui partagent le meme hebergement, mais pas
le meme stockage metier :

- la racine gère les projets audio dans `data/music-projects.json` ;
- `/map/` gere les campagnes JDR canoniques, leurs utilisateurs et leurs droits
  dans `map/data/map.sqlite`.

Toute nouvelle donnee JDR (notes, combats, jets de des, cartes et pions) doit
referencer `campaigns.id` dans SQLite et etre exposee par une API sous
`/map/api/`. Elle ne doit jamais etre ajoutee au JSON des projets audio.

L API de lecture des campagnes JDR est disponible dans
`map/api/campaigns.php`. Elle applique les droits de l utilisateur connecte.

## Comptes et diffusion SuSZic

La page d’accueil ouvre Sus20. L’inscription, la connexion et la déconnexion
sont partagées avec SuSZic. Le lien « SuSZic » dans le menu de Sus20 ouvre la
bibliothèque du compte connecté.
Chaque compte gère ses projets depuis « Changer de projet audio ».
« Transférer » déplace un projet complet vers un compte Sus20 actif.

Au premier chargement, la bibliothèque de l’ancien administrateur est rattachée
à l’administrateur Sus20 du même identifiant, ou au premier administrateur
Sus20 actif. Cette opération importe aussi `data/campaigns.json` si l’import
historique n’a jamais été effectué. Les autres anciens comptes peuvent
récupérer leurs projets et leur lien de diffusion depuis « Récupérer mes
anciens projets SuSZic », en saisissant leurs anciens identifiants depuis
leur session Sus20.

Conservez `data/users.json`, `data/music-projects.json`, `map/data/map.sqlite`
et les fichiers téléversés lors de la mise à jour. L’ancien fichier des comptes
sert à vérifier les demandes de récupération.

### Mise à jour depuis une version qui suivait les données SuSZic dans Git

Sauvegardez `data/campaigns.json` s’il existe et `uploads/audio/` hors du
dépôt avant le pull. Git peut retirer de cette copie de travail les fichiers
qui étaient précédemment suivis. Après le pull, remettez depuis la sauvegarde
les fichiers manquants aux mêmes emplacements. Les fichiers de protection
`.htaccess` restent suivis.

« Ma salle » ouvre le lien d’écoute propre au compte. Les auditeurs peuvent
l’utiliser sans compte. Chaque diffuseur peut activer son direct depuis un
projet audio. Les liens partagés avant la mise en place des salles doivent
être remplacés par le lien de la salle du diffuseur.

Le dossier `data/` et les dossiers de téléversement doivent être accessibles en
écriture à PHP. Sous Apache, les fichiers `.htaccess` bloquent les accès HTTP
directs à ces dossiers. Pour un autre serveur, appliquez la même restriction.
