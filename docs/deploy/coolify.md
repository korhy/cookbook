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
4. `league:oauth2-server:generate-keypair --skip-if-exists` — même principe, dans le volume
   `config/oauth` : la paire RSA qui signe les jetons d'accès MCP. **Sauf si `OAUTH_PASSPHRASE` est
   vide : dans ce cas l'entrypoint ne génère rien et le dit**, plutôt que d'écrire une clé privée
   non chiffrée sur un volume persistant (voir ci-dessous). L'entrypoint restreint ensuite les deux
   `.pem` à `0660`, parce que rien d'autre ne le fait : le bundle construit sa `CryptKey` avec
   `$keyPermissionsCheck = false`, et `Filesystem::dumpFile` laisse la paire en `0664`.
5. `cache:clear` + `cache:warmup` (env `prod`).
6. `doctrine:migrations:migrate --no-interaction --allow-no-migration`, suivi d'un
   `migrations:status` imprimé dans les logs.

Chaque étape est débrayable par variable : `APP_WAIT_FOR_DATABASE=0`,
`APP_FIX_PERMISSIONS=0`, `APP_GENERATE_JWT_KEYS=0`, `APP_GENERATE_OAUTH_KEYS=0`,
`APP_CLEAR_CACHE=0`, `APP_RUN_MIGRATIONS=0`.

> **Diagnostiquer un échec de connexion.** L'attente borne le boot : après 30 tentatives, le
> conteneur s'arrête en affichant **l'erreur Postgres réelle**, qui distingue un hôte injoignable
> (`could not translate host name`) d'une authentification refusée
> (`password authentication failed`) ou d'un droit manquant. Pour obtenir un shell sur un conteneur
> qui ne démarre pas, mettre `APP_WAIT_FOR_DATABASE=0` : l'application monte malgré tout, et la
> commande se rejoue à la main depuis le terminal Coolify.

**Un premier déploiement ne demande donc aucune commande à taper sur le VPS.**

> **Conséquence à concevoir** : toute migration s'exécute sans surveillance, juste après le merge,
> à chaque (re)démarrage de conteneur. Elle doit être non interactive et rétro-compatible. Un
> `DROP COLUMN` livré dans la même release que le code qui cesse de s'en servir casse les requêtes
> en vol — le scinder sur deux releases.

## Stockage persistant

Un conteneur est reconstruit à chaque déploiement : sans volume, ces quatre chemins sont effacés.
Montés dans Coolify (**Configuration → Persistent Storage → Add → Volume Mount**) :

| Chemin dans le conteneur | Contenu |
|---|---|
| `/app/public/images/recipes_thumbnails` | Miniatures de recettes déposées via EasyAdmin (VichUploader) |
| `/app/config/jwt` | La paire de clés Lexik générée au premier boot |
| `/app/var/log` | **La piste d'audit des écritures MCP** (`mcp_audit.log`) |
| `/app/config/oauth` | **La paire de clés du serveur d'autorisation OAuth 2.1**, générée au premier boot |

> **Le troisième volume n'est pas optionnel.** `App\Service\Mcp\McpWriteGuard` écrit chaque
> tentative d'écriture MCP, autorisée ou refusée, dans `var/log/mcp_audit.log`. Sans volume, le
> fichier est effacé à chaque redéploiement. L'entrypoint `chown` `var/` récursivement, donc un
> volume neuf appartenant à `root` est repris sans intervention.

> **Le quatrième non plus, et c'est celui dont l'oubli se voit le moins.** `config/oauth` contient
> la clé privée RSA qui signe les jetons d'accès et de rafraîchissement MCP. L'entrypoint la génère
> au premier boot avec `--skip-if-exists` : **sans volume, chaque (re)démarrage de conteneur en
> génère une nouvelle et invalide tous les jetons déjà émis**, et chaque client MCP doit refaire le
> flux OAuth complet. Contrairement à une miniature manquante, rien ne l'annonce : le serveur
> répond normalement, il rejette simplement tout jeton antérieur au dernier redémarrage. C'est la
> raison pour laquelle l'étape 4 du boot n'existait pas avant que ce volume soit documenté.
>
> Elle est **délibérément séparée de `config/jwt`** : régénérer celle-ci déconnecte les clients
> MCP, régénérer celle de Lexik déconnecte Radiant. Deux rayons d'action distincts, donc deux
> volumes distincts — aucune des deux opérations ne force l'autre.

À faire sur chacun des quatre :

- Ajouter une sauvegarde planifiée dans **Backups → Storage backups → Add**.
- Laisser *Delete Unused Volumes* désactivé dans le nettoyage.
- Ne pas renommer un Storage après coup : Coolify crée un volume vide sous le nouveau nom et les
  données restent dans l'ancien.

`var/cache` et `var/sessions` ne sont **pas** montés et sont reconstruits à chaque boot. Trois
conséquences assumées :

- les sessions d'administration sont perdues au redéploiement (il faut se reconnecter) ;
- les sessions HTTP MCP (`%kernel.cache_dir%/mcp-sessions/cookbook`, TTL 1 h) aussi — les clients
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
| `OAUTH_PASSPHRASE` | passphrase de la paire de clés OAuth — **à renseigner avant le premier boot**, voir ci-dessous |
| `OAUTH_ENCRYPTION_KEY` | `openssl rand -hex 32` — chiffre les codes d'autorisation et les jetons de rafraîchissement remis au client |
| `MCP_RESOURCE_URI` | *optionnel* : `.env` porte déjà la valeur de production. À ne renseigner que pour la surcharger (domaine Coolify avant bascule DNS) |
| `MAILER_DSN` | SMTP réel, ou `null://null` |
| `MESSENGER_TRANSPORT_DSN` | `doctrine://default?auto_setup=0` |
| `MCP_WRITE_TOKEN` | vide désactive les deux outils d'écriture MCP — c'est le défaut |
| `MCP_IMPORT_ALLOWED_HOSTS` | allowlist d'hôtes pour `recipe_import_from_url`, vide = aucun import |

> **`TRUSTED_PROXIES` n'est pas optionnel.** Sans lui, Symfony ne voit que l'adresse privée du
> proxy en HTTP simple, et `App\Serializer\RecipeNormalizer` construit les URL absolues des
> miniatures en `http://` : contenu mixte et images cassées côté Radiant. Le serveur
> d'autorisation OAuth en dépend lui aussi — voir [la section dédiée](#trusted_proxies-et-le-serveur-dautorisation).

### `OAUTH_PASSPHRASE` : l'ordre compte, et le rattrapage coûte cher

> **Renseigner `OAUTH_PASSPHRASE` AVANT le premier démarrage du volume `config/oauth`.** La
> passphrase est scellée dans la clé privée au moment de sa génération, par `openssl_pkey_export()`.
> Ce n'est pas un mot de passe que l'application vérifie au démarrage : c'est le chiffrement du
> fichier lui-même.

**L'entrypoint refuse de générer la paire tant que la variable est vide**, et l'écrit dans les
logs en nommant la variable manquante. C'est une garantie plus forte qu'une procédure de
rattrapage : le mauvais état n'est jamais créé. Il *saute* l'étape, il ne fait pas échouer le
boot — l'API entière, Radiant compris, n'a pas à tomber pour un sous-système que rien ne consomme
avant la phase 5. Seuls les endpoints `/oauth2/*` sont alors hors service, et le premier
redémarrage après avoir renseigné la variable crée la bonne paire, sans rien à réparer.

Reste deux cas que ce garde-fou ne couvre pas, et c'est le second qui est piégeux :

- **Variable renseignée, puis modifiée.** Échec franc et immédiat : la clé existante a été
  chiffrée avec l'ancienne valeur, `--skip-if-exists` ne la remplace pas, et toute signature de
  jeton lève `LogicException: Unable to read key from file`. Visible tout de suite.
- **Une clé non chiffrée existe déjà sur le volume** — née avant que ce garde-fou existe, ou
  générée à la main. **Aucune erreur** : OpenSSL ignore simplement une passphrase fournie pour une
  clé non chiffrée. Le serveur fonctionne, la variable est renseignée, et la clé privée reste en
  clair — la protection que la variable semble apporter n'existe pas. L'entrypoint le **signale
  désormais à chaque boot** (`WARNING: config/oauth/private.pem is NOT encrypted`), parce qu'il ne
  peut pas le corriger seul : régénérer est une décision. Vérification à la main :
  `head -2 config/oauth/private.pem` doit contenir `ENCRYPTED`.

Dans ces deux cas, le seul rattrapage est de régénérer la paire, ce qui **invalide tous les jetons
déjà émis** (chaque client MCP refait le flux OAuth). Depuis le terminal Coolify, la variable étant
d'abord corrigée :

```bash
php bin/console league:oauth2-server:generate-keypair --overwrite --no-interaction --env=prod
```

`--skip-if-exists` (ce que fait l'entrypoint) ne suffirait pas : il laisse justement la clé en
place. `--overwrite` est la seule option qui la remplace, et elle n'est jamais dans l'entrypoint —
une régénération de clés est une décision, pas un effet de bord d'un redémarrage.

### CORS : rien à élargir, et pourquoi

`config/packages/nelmio_cors.yaml` ne couvre que `^/api/`. **`/.well-known/*` et `/oauth2/*`
n'envoient donc aucun en-tête CORS, et c'est correct** : aucun de leurs appelants n'est un
navigateur soumis à la politique d'origine.

| Appelé par | Mécanisme | CORS ? |
|---|---|---|
| `GET\|POST /oauth2/authorize` | une **navigation** de premier niveau : le client ouvre le navigateur à cette URL, puis la redirection revient sur `http://localhost:3118/callback` | Non — CORS ne s'applique pas aux navigations, seulement aux requêtes émises par un script |
| `POST /oauth2/token` | le client MCP, c'est-à-dire **Claude Code : un processus Node sur la machine de l'opérateur** | Non — pas de navigateur, pas d'en-tête `Origin`, pas de préflight |
| `GET /.well-known/*` | le même client, avant de détenir le moindre jeton | Non, même raison |

**Ce qui changerait la réponse** : enregistrer un client **exécuté dans un navigateur**. Un tel
client aurait pour `redirect_uri` une origine web en `https://`, pas l'URI de bouclage
`http://localhost:3118/callback` qu'une page servie depuis une origine ne peut pas utiliser — donc
cela passe forcément par un `league:oauth2-server:create-client` délibéré, et c'est le déclencheur
pour relire cette section. Le correctif serait alors une **entrée `paths` supplémentaire** dans
`nelmio_cors.yaml` pour `^/(\.well-known/|oauth2/token)`, avec son propre `allow_origin` — et
**surtout pas un élargissement de `CORS_ALLOW_ORIGIN`**, qui appartient à Radiant et à `/api/`.
`/oauth2/authorize` continuerait de n'avoir besoin de rien.

Un point à garder pour la phase 5, pendant qu'on y est : `/api/` *est* couvert par nelmio, mais
`expose_headers` ne liste que `Link`. Le jour où un défi `WWW-Authenticate` est renvoyé par
`/api/v1/mcp`, un appelant navigateur ne pourra pas le lire sans l'ajouter à cette liste. Sans
objet aujourd'hui — le client est un CLI — mais inutile de le redécouvrir.

### `TRUSTED_PROXIES` et le serveur d'autorisation

La valeur en place, `private_ranges`, est **correcte et suffisante** : le proxy Coolify est un
conteneur du même réseau Docker, donc son adresse tombe dans une plage privée, et l'application
n'est joignable qu'à travers lui. Rien à changer pour l'OAuth — mais la variable porte désormais
plus de poids, parce que le serveur d'autorisation émet des **URL absolues et des redirections** :
un `/oauth2/authorize` sans session rebondit vers le formulaire de connexion admin puis revient, et
Symfony construit ces URL à partir de la requête. Mal configurée, la variable produit une
redirection en `http://` ou sur l'hôte interne — en clair, un flux OAuth qui ne revient jamais.
`trusted_headers` inclut `x-forwarded-host` et `x-forwarded-proto` pour exactement cette raison
(`config/packages/framework.yaml`).

> **Ce qu'il ne faut PAS aller chercher de ce côté.** `MCP_RESOURCE_URI` — dont découlent le membre
> `resource` du document RFC 9728, l'`issuer` RFC 8414 et la revendication `aud` des jetons — est
> une **configuration explicite**, lue dans l'environnement et jamais déduite de la requête. Elle
> est donc juste même si `TRUSTED_PROXIES` est faux. C'est précisément pour cela qu'une mauvaise
> configuration du proxy se manifeste par une **redirection** cassée et non par un document de
> découverte erroné : commencer par les métadonnées ferait perdre le diagnostic.

> L'onglet *Environment Variables* propose une **« Developer view »** pour tout coller d'un bloc au
> format `CLE=valeur`. Ne pas entourer les valeurs de guillemets, Coolify les prendrait au pied de
> la lettre. Vérifier après enregistrement que `DATABASE_URL` tient sur **une seule ligne** : un
> retour à la ligne la couperait en deux variables inutilisables.

**Ordre à respecter :** renseigner les variables, monter les quatre volumes et activer « Connect To
Predefined Network » **avant** le premier déploiement. Sinon le conteneur démarre, ne joint pas la
base, et le boot échoue au bout des 30 tentatives. `OAUTH_PASSPHRASE` est celle qui ne se rattrape
pas d'un simple ajout après coup : la clé est générée au premier boot avec la valeur qui existe à
cet instant.

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
9. **La paire de clés OAuth est née chiffrée dans son volume.** Dans les logs du conteneur,
   l'entrypoint imprime `Ensuring the OAuth keypair exists` suivi de `[OK] Done!` au premier boot.
   S'il imprime à la place `NOT generating the OAuth keypair: OAUTH_PASSPHRASE is empty.`, la
   variable manque : la renseigner et redémarrer suffit, rien n'a été écrit.
   Puis, depuis le terminal Coolify :
   ```bash
   ls -l config/oauth && head -2 config/oauth/private.pem
   ```
   Attendu : deux `.pem` en `0660` appartenant à `www-data`, et un en-tête contenant `ENCRYPTED`.
   Son absence veut dire que `OAUTH_PASSPHRASE` était vide au premier boot — voir le rattrapage
   plus haut.
10. Après un **Redeploy**, revérifier 4, 6 et 8 : les clés JWT et les miniatures doivent survivre.
    Une image manquante signifie un volume mal monté. Pour `config/oauth`, le contrôle est que
    l'entrypoint imprime cette fois **`Your key files already exist, they won't be overridden.`**
    au lieu de `[OK] Done!` : c'est la preuve que le volume est bien monté et qu'aucun jeton n'a
    été invalidé. Un second `[OK] Done!` signifie l'inverse.

## Recréer la ressource (disaster recovery)

1. Vérifier que `ghcr.io/korhy/cookbook/frankenphp:latest` existe (job `build-images` de `ci.yml`).
2. Créer la ressource Postgres, relever l'URL interne, activer les sauvegardes.
3. Restaurer la dernière sauvegarde **avant** le premier démarrage de l'application : l'entrypoint
   jouerait sinon les migrations sur une base vide, et la restauration entrerait en conflit avec
   `doctrine_migration_versions`.
4. Créer la ressource Application (Docker Image), activer *Connect To Predefined Network*.
5. Monter les quatre volumes persistants, y replacer les miniatures.
6. Renseigner toutes les variables d'environnement — `OAUTH_PASSPHRASE` comprise, **avant** le
   premier démarrage, sans quoi la clé OAuth naît non chiffrée.
7. Déployer, puis parcourir les vérifications ci-dessus. Les clients MCP devront de toute façon
   refaire leur flux OAuth : la paire de clés restaurée n'est pas celle qui a signé leurs jetons,
   et les tables `oauth2_*` viennent de la sauvegarde Postgres.
8. DNS : enregistrement `A` chez Cloudflare vers l'IP du VPS, mode SSL/TLS **Full**, puis domaine
   ajouté côté Coolify pour l'émission du certificat.

## Hors périmètre

- **Mode worker FrankenPHP** : demanderait `dunglas/frankenphp-symfony` et un audit de l'état
  partagé entre requêtes. L'image sert en `php_server`, un process par requête.
- **`opcache.preload`** : `config/preload.php` existe mais aucune directive ne l'active.
- **Sauvegardes applicatives et logs centralisés** : non couverts. Les sauvegardes planifiées de la
  ressource Postgres sont le seul filet, et les logs se lisent dans le panneau Coolify.
