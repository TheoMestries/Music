# Pages du plateau

Le bouton **Pages**, dans le coin supérieur droit du plateau, est réservé aux MJ.
Il ouvre le gestionnaire des pages de la campagne.

- **Créer une page** : donner un nom et, facultativement, importer une image de
  carte (PNG, JPEG, GIF, WebP ou AVIF ; source de 25 Mo maximum). L’image est
  adaptée à 2400 pixels maximum et enregistrée avec sa vignette.
- **Cliquer sur une vignette ou son nom** : changer uniquement la page
  consultée par le MJ connecté.
- **Glisser le repère jaune « Joueurs » sur une page** : changer la page commune
  aux joueurs sans déplacer les MJ. Le repère jaune suit leur page. Au clavier,
  sélectionner ce repère puis utiliser les flèches gauche et droite.
- **Supprimer** : supprimer une page après confirmation. La dernière page ne
  peut pas être supprimée. Si la page des joueurs est supprimée, la première
  page restante est sélectionnée. Un MJ consultant la page supprimée revient
  lui aussi sur une page restante.

Les vues MJ sont enregistrées par utilisateur et campagne. Les joueurs ne
reçoivent ni la liste ni les images des autres pages. La synchronisation utilise
la même requête en attente que les présentations de documents, sans ajouter
de délai fixe ou une seconde connexion permanente.

Une campagne reçoit une première page vide lors de sa première ouverture.
Les pages stockent actuellement une carte de fond ; les jetons, dessins et
autres outils du plateau restent des fonctionnalités distinctes.

## Réglages propres à chaque page

La barre d’outils en bas du plateau est réservée aux MJ. Sa poignée permet de
la déplacer. Chaque icône ouvre un groupe de paramètres ; la roue dentée et
le bouton **Paramètres** d’une vignette regroupent tous les réglages :

- image de fond (import, remplacement et retrait) et recadrage ;
- dimensions en pixels ou en cases, taille d’une case (base de 70 pixels) ;
- grille carrée, hexagonale verticale/horizontale, isométrique ou dimétrique ;
- couleur, opacité, décalages X/Y et alignement sur la grille ;
- couleurs du plateau et du fond, détection de la couleur dominante, affichage
  des parties de l’image dépassant les limites de la page ;
- échelle, unités et mesure diagonale euclidienne, Manhattan, Chebyshev ou
  Pathfinder (alternance 1/2) ;
- nom et dossier de la page.

Les modifications sont prévisualisées uniquement sur la vue locale du MJ.
**Enregistrer les paramètres** les conserve et les synchronise avec les
personnes consultant cette page. Annuler restaure les paramètres enregistrés.
Les révisions évitent d’écraser les modifications concurrentes d’un autre MJ.
L’éclairage dynamique est réservé à une étape ultérieure.

Le bouton **Mesurer**, disponible sur le plateau, active une règle locale :
tracer un segment affiche la distance selon l’échelle et le type de grille.
Le mode de distance continue utilise les longueurs réelles plutôt que le
comptage des cellules. Les mesures ne déplacent pas les joueurs.

Tests : `php map/tests/board_pages_test.php`,
`php map/tests/presentations_test.php` et
`php map/tests/database_migrations_test.php` (extension `pdo_sqlite` requise).
Pour les paramètres : `php map/tests/board_settings_test.php` et
`node map/tests/board_measurements_test.cjs`.
