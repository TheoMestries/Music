# Fiches et modèles

Dans la fenêtre de lecture d’un personnage, **Fiche de personnage** ouvre une
fenêtre indépendante de **Bio & infos**. Le MJ choisit un modèle de la campagne
ou clique sur **Créer un modèle**.

L’éditeur accepte des images (PNG, JPEG, GIF, WebP et AVIF) et des PDF. Les PDF
sont convertis en images dans le navigateur avec PDF.js fourni localement.
Plusieurs fichiers peuvent être ajoutés au même modèle. Limites : 25 Mo par
fichier source, 12 pages, 6 Mo pour les pages converties et 300 zones.

Tracer un rectangle crée une zone. Sélectionner une zone permet de la nommer,
de régler la taille du texte, de la déplacer ou de la supprimer. Le coin
inférieur droit permet de redimensionner la zone. Les flèches déplacent la
sélection ; Maj + flèches changent ses dimensions.

**Enregistrer le modèle** crée un modèle réutilisable dans la campagne.
**Utiliser ce modèle** l’attribue au personnage. Les modèles enregistrés sont
immuables : créer un autre modèle permet de changer la structure sans modifier
les fiches existantes. Changer le modèle d’un personnage remet ses valeurs à
zéro après confirmation.

Les personnes autorisées à modifier le personnage remplissent les zones puis
cliquent sur **Enregistrer la fiche**. Chaque personnage possède ses valeurs,
indépendantes de sa bio, de ses secrets MJ et des autres fiches. Une révision
permet de signaler les sauvegardes concurrentes au lieu de les écraser.

Tests : `php map/tests/character_sheets_test.php` et
`php map/tests/database_migrations_test.php` (extension `pdo_sqlite` requise).

L’import du PDF de deux pages et de l’image AVIF fournis en exemple, le tracé
d’une zone, l’attribution du modèle et la saisie d’une valeur ont également
été vérifiés dans Chrome.
