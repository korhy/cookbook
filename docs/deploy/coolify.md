# Déploiement Coolify

Cookbook tourne en production sur **Coolify** (VPS) : une ressource **Application** qui tire une
image Docker depuis GHCR, et une ressource **PostgreSQL** gérée par Coolify, dans le même projet et
le même environnement. Voir
[deployment.md](../../.claude/rules/technical/deployment.md) pour la chaîne CI qui produit l'image.

## Architecture

- **Serveur applicatif** : FrankenPHP (PHP 8.4 + Caddy), image construite depuis
  `docker/frankenphp/Dockerfile.prod`. Ni nginx ni php-fpm, un seul process.
- **Image** : construite et poussée par `ci.yml` (job `build-images`) vers
  `ghcr.io/korhy/cookbook/frankenphp`, taguée `latest` et `<sha>`. Coolify **tire** cette image ;
  il ne la construit pas.
- **Base de données** : PostgreSQL 16, ressource Coolify distincte de l'application, reliée par
  « Connect To Predefined Network ».
- **Domaine** : `api.clementboudinel.fr`, proxié par **Cloudflare** devant le VPS. Mode SSL/TLS
  Cloudflare : **Full** — pas *Flexible* (boucle de redirection avec Coolify), pas *Full strict*
  (exigerait un certificat valide à l'origine avant que Let's Encrypt ait tourné).
- **Radiant** consomme l'**URL publique**, pas un nom de conteneur interne, bien que les deux
  applications vivent sur le même VPS. C'est délibéré : les clients MCP configurés ailleurs
  appellent `https://api.clementboudinel.fr/api/v1/mcp`, et `allowed_hosts` dans
  `config/packages/mcp.yaml` est verrouillé sur ce nom d'hôte.

## Accès au package GHCR

Si le package est privé, authentifier le VPS une fois :

```bash
docker login ghcr.io -u korhy
# coller un PAT classique GitHub, scope read:packages, comme mot de passe
```

Coolify tire ensuite l'image sans configuration supplémentaire.

## Base de données

1. **+ New Resource → Database → PostgreSQL** (version 16).
2. L'URL de connexion **interne** (icône œil) a la forme :
   ```
   postgres://<user>:<password>@<nom-du-conteneur>:5432/<db>
   ```
   Le `<nom-du-conteneur>` est le nom d'hôte interne à utiliser dans `DATABASE_URL` — **pas**
   `database` comme en local.
3. Sauvegardes planifiées activées dans l'onglet dédié de la ressource. C'est le seul filet de
   sécurité : il n'y a pas de sauvegarde applicative.

> **Piège mot de passe** : si le mot de passe généré contient `@ : / ? # % $`, la DSN
> `DATABASE_URL` est coupée au mauvais endroit et Symfony renvoie un `SQLSTATE` incompréhensible.
> Préférer un mot de passe alphanumérique, ou encoder les caractères spéciaux. Le `$` est
> particulièrement piégeux : Coolify interprète `${...}` dans les valeurs de variables.

## Application

1. **+ New Resource → Application**, Build Pack : **Docker Image**.
2. Image : `ghcr.io/korhy/cookbook/frankenphp:latest`.
3. Port exposé : **`8080`** (défini par `docker/frankenphp/Caddyfile.prod`). Healthcheck :
   `/healthz` répond `OK` — route Caddy, donc insensible à l'allowlist `Host` du serveur MCP.
4. Domaine : `https://api.clementboudinel.fr`, ajouté via *Manage Domains*.
5. **Réseau.** Coolify isole chaque ressource sur son propre réseau Docker : activer « Connect To
   Predefined Network » (onglet **Configuration → Advanced**, visible seulement une fois la
   ressource créée) pour rattacher l'application au réseau de la base, avec comme hôte le nom de
   conteneur Postgres.

## La séquence de boot

`docker/frankenphp/entrypoint.prod.sh` s'exécute à **chaque** démarrage de conteneur, avant
d'exposer `:8080` :

1. Création des répertoires et correction des droits sur `var/`, les miniatures et `config/jwt`
   (les volumes sont montés par-dessus ceux de l'image, leur propriétaire vient de l'hôte).
2. `doctrine:database:create --if-not-exists`, retenté 30 fois à 2 s d'intervalle — sert aussi
   d'attente que le serveur de base accepte les connexions. Borné : une `DATABASE_URL` erronée fait
   échouer le boot.
3. `lexik:jwt:generate-keypair --skip-if-exists` — la paire de clés naît au premier boot dans le
   volume et survit ensuite. Sans elle, tout `POST /api/login_check` échoue.
4. `cache:clear` + `cache:warmup` (env `prod`).
5. `doctrine:migrations:migrate --no-interaction --allow-no-migration`, suivi d'un
   `migrations:status` imprimé dans les logs.

Chaque étape est débrayable par variable : `APP_FIX_PERMISSIONS=0`, `APP_GENERATE_JWT_KEYS=0`,
`APP_CLEAR_CACHE=0`, `APP_RUN_MIGRATIONS=0`.

**Un premier déploiement ne demande donc aucune commande à taper sur le VPS.**

> **Conséquence à concevoir** : toute migration s'exécute sans surveillance, juste après le merge,
> à chaque (re)démarrage de conteneur. Elle doit être non interactive et rétro-compatible. Un
> `DROP COLUMN` livré dans la même release que le code qui cesse de s'en servir casse les requêtes
> en vol — le scinder sur deux releases.

## Stockage persistant

Un conteneur est reconstruit à chaque déploiement : sans volume, ces deux chemins sont effacés.
Montés dans Coolify (**Application → Storages → Volume Mount**) :

| Chemin dans le conteneur | Contenu |
|---|---|
| `/app/public/images/recipes_thumbnails` | Miniatures de recettes déposées via EasyAdmin (VichUploader) |
| `/app/config/jwt` | La paire de clés Lexik générée au premier boot |

`var/` n'est **pas** monté : cache, logs et sessions sont reconstruits à chaque boot. Trois
conséquences assumées :

- les sessions d'administration sont perdues au redéploiement (il faut se reconnecter) ;
- les sessions HTTP MCP (`%kernel.cache_dir%/mcp-sessions`, TTL 1 h) aussi — les clients
  rouvrent une session ;
- les compteurs des trois limiteurs MCP (`mcp_write`, `mcp_import`, `mcp_write_auth`) repartent de
  zéro, le pool `cache.rate_limiter` vivant dans `var/cache`.

> **Ne jamais déposer un fichier à la main dans le dossier des miniatures** (terminal, `docker cp`,
> navigateur de fichiers Coolify) pour remplacer un upload manquant. VichUploader stocke en base le
> **nom exact** du fichier (`Recipe::$thumbnail`) ; un fichier posé sous un autre nom est invisible
> pour l'application et répond 404, même si le dossier n'est pas vide. Toujours passer par
> EasyAdmin, qui garde le nom de fichier synchronisé avec la ligne en base.

## Variables d'environnement

Toutes sont des variables **d'exécution** : l'image est déjà construite, aucune n'est nécessaire au
build. Laisser la case **« Build Variable? »** décochée. Noms uniquement — les valeurs sont des
secrets et ne figurent pas dans ce dépôt.

| Variable | Valeur / source |
|---|---|
| `APP_ENV` | `prod` |
| `APP_DEBUG` | `0` |
| `APP_SECRET` | secret applicatif Symfony (`openssl rand -hex 16`) |
| `DATABASE_URL` | URL interne Postgres + `?serverVersion=16&charset=utf8`, **sur une seule ligne** |
| `DEFAULT_URI` | `https://api.clementboudinel.fr`, sans barre oblique finale |
| `TRUSTED_PROXIES` | `private_ranges` — **obligatoire**, voir ci-dessous |
| `CORS_ALLOW_ORIGIN` | regex autorisant l'origine de Radiant (`origin_regex: true`) |
| `JWT_PASSPHRASE` | passphrase de la paire de clés Lexik |
| `MAILER_DSN` | SMTP réel, ou `null://null` |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` |
| `MCP_WRITE_TOKEN` | vide désactive les deux outils d'écriture MCP — c'est le défaut |
| `MCP_IMPORT_ALLOWED_HOSTS` | allowlist d'hôtes pour `recipe_import_from_url`, vide = aucun import |

> **`TRUSTED_PROXIES` n'est pas optionnel.** Sans lui, Symfony ne voit que l'adresse privée du
> proxy en HTTP simple, et `App\Serializer\RecipeNormalizer` construit les URL absolues des
> miniatures en `http://` : contenu mixte et images cassées côté Radiant.

> L'onglet *Environment Variables* propose une **« Developer view »** pour tout coller d'un bloc au
> format `CLE=valeur`. Ne pas entourer les valeurs de guillemets, Coolify les prendrait au pied de
> la lettre. Vérifier après enregistrement que `DATABASE_URL` tient sur **une seule ligne** : un
> retour à la ligne la couperait en deux variables inutilisables.

**Ordre à respecter :** renseigner les variables et activer « Connect To Predefined Network »
**avant** le premier déploiement. Sinon le conteneur démarre, ne joint pas la base, et le boot
échoue au bout des 30 tentatives.

## Mettre à jour

Un push sur `main` dont la CI est verte reconstruit et republie `latest`, mais **Coolify ne le sait
pas** : GHCR ne le notifie pas. Le déploiement reste donc un clic **Redeploy** sur l'application,
qui tire la nouvelle image et redémarre le conteneur.

Pour automatiser, ajouter une étape finale au job `build-images` qui appelle l'URL de webhook de
l'application (onglet *Webhooks* de la ressource Coolify), avec le token d'API en en-tête.

## Vérifications après déploiement

1. `https://api.clementboudinel.fr/healthz` renvoie `OK`.
2. **Les migrations sont réellement passées** : dans les logs du conteneur, le
   `doctrine:migrations:status` imprimé par l'entrypoint doit afficher `New: 0`. Un déploiement
   vert ne le prouve pas — c'est exactement la panne silencieuse rencontrée côté Radiant.
3. `GET /api/v1/recipes` sans jeton renvoie `401`.
4. `POST /api/login_check` avec les identifiants Radiant renvoie un jeton, et ce jeton ouvre
   `GET /api/v1/recipes` (valide la génération des clés JWT dans le volume).
5. `/login` s'affiche **avec son CSS** — contrôle de `assets:install` + `asset-map:compile` dans
   l'image —, le compte admin fonctionne et `/admin` liste les recettes.
6. Une recette avec miniature affiche son image, et l'URL sérialisée commence par **`https://`**
   (contrôle de `TRUSTED_PROXIES`).
7. `/api/v1/mcp` répond à `tools/list`, et `recipe_search` renvoie des recettes publiées ; les
   brouillons restent invisibles.
8. La mini-app Cookbook de Radiant affiche ses recettes, miniatures comprises.
9. Après un **Redeploy**, revérifier 4, 6 et 8 : les clés JWT et les miniatures doivent survivre.
   Une image manquante signifie un volume mal monté.

## Recréer la ressource (disaster recovery)

1. Vérifier que `ghcr.io/korhy/cookbook/frankenphp:latest` existe (job `build-images` de `ci.yml`).
2. Créer la ressource Postgres, relever l'URL interne, activer les sauvegardes.
3. Restaurer la dernière sauvegarde **avant** le premier démarrage de l'application : l'entrypoint
   jouerait sinon les migrations sur une base vide, et la restauration entrerait en conflit avec
   `doctrine_migration_versions`.
4. Créer la ressource Application (Docker Image), activer *Connect To Predefined Network*.
5. Monter les deux volumes persistants, y replacer les miniatures.
6. Renseigner toutes les variables d'environnement.
7. Déployer, puis parcourir les vérifications ci-dessus.
8. DNS : enregistrement `A` chez Cloudflare vers l'IP du VPS, mode SSL/TLS **Full**, puis domaine
   ajouté côté Coolify pour l'émission du certificat.

## Hors périmètre

- **Mode worker FrankenPHP** : demanderait `dunglas/frankenphp-symfony` et un audit de l'état
  partagé entre requêtes. L'image sert en `php_server`, un process par requête.
- **`opcache.preload`** : `config/preload.php` existe mais aucune directive ne l'active.
- **Sauvegardes applicatives et logs centralisés** : non couverts. Les sauvegardes planifiées de la
  ressource Postgres sont le seul filet, et les logs se lisent dans le panneau Coolify.
