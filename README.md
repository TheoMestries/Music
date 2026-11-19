# SuSZic et Sus20

Le depot contient deux interfaces qui partagent le meme hebergement, mais pas
le meme stockage metier :

- la racine gere des projets audio historiques dans `data/campaigns.json` ;
- `/map/` gere les campagnes JDR canoniques, leurs utilisateurs et leurs droits
  dans `map/data/map.sqlite`.

Toute nouvelle donnee JDR (notes, combats, jets de des, cartes et pions) doit
referencer `campaigns.id` dans SQLite et etre exposee par une API sous
`/map/api/`. Elle ne doit jamais etre ajoutee au JSON des projets audio.

L API de lecture des campagnes JDR est disponible dans
`map/api/campaigns.php`. Elle applique les droits de l utilisateur connecte.
