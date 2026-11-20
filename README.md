# SuSZic et Sus20

Le depot contient deux interfaces qui partagent le meme hebergement, mais pas
le meme stockage metier :

- la racine gère les comptes SuSZic et leurs projets audio dans
  `data/users.json` et `data/music-projects.json` ;
- `/map/` gere les campagnes JDR canoniques, leurs utilisateurs et leurs droits
  dans `map/data/map.sqlite`.

Toute nouvelle donnee JDR (notes, combats, jets de des, cartes et pions) doit
referencer `campaigns.id` dans SQLite et etre exposee par une API sous
`/map/api/`. Elle ne doit jamais etre ajoutee au JSON des projets audio.

L API de lecture des campagnes JDR est disponible dans
`map/api/campaigns.php`. Elle applique les droits de l utilisateur connecte.

## Comptes et diffusion SuSZic

L’inscription SuSZic est accessible depuis la page de connexion. Chaque compte
gère ses projets depuis « Changer de projet audio ». « Transférer » déplace
un projet, ses dossiers, ses playlists, ses images et ses musiques vers un
compte SuSZic existant, identifié par son identifiant de connexion.

Au premier chargement de la bibliothèque, SuSZic importe une fois les projets
de `data/campaigns.json` dans le compte historique. Il cherche d’abord un compte
marqué `"role": "admin"`, puis un ancien compte nommé `admin`, puis le premier
compte historique sans champ `role`. Conservez
`data/users.json` et les fichiers téléversés lors de la mise à jour. Sauvegardez
aussi `data/music-projects.json` après l’import pour conserver les transferts et
les suppressions.

« Ma salle » ouvre le lien d’écoute propre au compte. Les auditeurs peuvent
l’utiliser sans compte. Chaque diffuseur peut activer son direct depuis un
projet audio. Les liens partagés avant la mise en place des salles doivent
être remplacés par le lien de la salle du diffuseur.

Le dossier `data/` et les dossiers de téléversement doivent être accessibles en
écriture à PHP. Sous Apache, les fichiers `.htaccess` bloquent les accès HTTP
directs à ces dossiers. Pour un autre serveur, appliquez la même restriction.
