# Directives Agent & Architecture Standardisée (AGENTS.md)

## 1. PROFIL DU PROJET (À COMPLÉTER PAR DÉPÔT)
> **Instructions pour l'Agent** : Ce document utilise des placeholders (ex: `[SLUG]`). Avant toute réponse ou génération de code, tu dois les remplacer par les valeurs définies dans le tableau ci-dessous. La langue de travail, des commentaires, de la documentation et des chaînes utilisateur est exclusivement le **Français**.

| Placeholder | Variable | **VALEUR POUR CE PROJET (À remplir)** | Exemple (`DAME` / `ROI` / `DAME-PWA`) |
| :--- | :--- | :--- | :--- |
| `[NOM_PLUGIN]` | Nom du Projet | `EG Mail` | `DAME`, `ROI`, `DAME-PWA`, `EG Media Manager`, `EG Backup` |
| `[TYPE_PROJET]`| Typologie | `WP_PLUGIN` | `WP_PLUGIN` \| `WP_HYBRID_PWA` \| `STANDALONE_LIB` |
| `[SLUG]` | Slug / Textdomain | `eg-mail` | `dame`, `roi`, `dame-pwa`, `eg-media`, `eg-backup` |
| `[SLUG_MAJ]` | Préfixe Constantes | `EG_MAIL` | `DAME`, `ROI`, `DAME_PWA`, `EG_MEDIA`, `EG_BACKUP` |
| `[PREFIX]` | Préfixe PHP & JS | `eg_mail_` | `dame_`, `roi_`, `dame_pwa_`, `eg_media_`, `eg_backup_` |
| `[NAMESPACE]` | Namespace PSR-4 | `EGMail\` | `DAME\`, `ROI\`, `DAME_PWA\`, `EG_MEDIA\` |
| `[DB_SLUG]` | Suffixe Table SQL | `eg_mail_` | `{$wpdb->prefix}dame_`, `{$wpdb->prefix}roi_` |
| `[VENDOR_PROD]`| Composer en Prod | `NON` | `OUI` (embarqué dans le ZIP via `--no-dev -o`) \| `NON` |

### Versions Cibles & Stack Technique
| Outil | Version Requise | Impact strict sur le code |
| :--- | :--- | :--- |
| **WordPress** | **7.1+** | Utiliser les API natives modernes : **Interactivity API** (`viewScriptModule`), **Block Bindings API**, **Template Registration API** (`register_block_template`), **HTML API** (`WP_HTML_Tag_Processor` / `WP_HTML_Processor`), **Options API** (`autoload` explicite), **Transients**, et **Validation REST déclarative**. |
| **PHP** | **8.4** | **`declare(strict_types=1);` OBLIGATOIRE en ligne 1**. Autoloader natif SPL obligatoire (+ `vendor/autoload.php` si `[VENDOR_PROD] == OUI`). Constructor Property Promotion, Backed Enums, `readonly class` (DTO), typage strict des retours et paramètres. **Interdiction formelle des types nullables implicites** (toujours écrire `?Type $param = null`). Syntaxe courte des tableaux `[]` obligatoire. |
| **TypeScript** | **6.0+** | **Mode Strict obligatoire** (`strict: true`, `noImplicitAny: true`). ES2022+ Vanilla. **Proscription stricte de jQuery**. Typage fort de tous les blocs, stores, événements et globales (`src/types/`). |
| **React** | **18.3.1** | Alignement strict sur la version React et `@types/react` supportés par WordPress 7.1. |
| **PWA** | **Vue 3 + Vite 8** | `<script setup lang="ts">`, Ionic Vue (`@ionic/vue` v9), Pinia (`src/stores/`), TanStack Query v5 (`@tanstack/vue-query` + persistance offline), TanStack Table (`@tanstack/vue-table`). |
| **Node.js** | **24 LTS** | **DEV ONLY**. Sert uniquement à compiler les assets, exécuter les tests et packager le livrable. Tous les scripts résident dans `scripts/`. |
| **Styles** | **SCSS (Dart Sass)**| Préprocesseur obligatoire (`@use` / `@forward`, interdiction de `@import`) + convention **BEM** à faible spécificité + génération RTL automatique (`rtlcss`). |
| **Livrable** | **Zip Autonome** | Généré par `npm run package` (`scripts/package.cjs`). Ne contient ni `node_modules/`, ni `src/`, ni `tests/`, ni `scripts/`. Si `[VENDOR_PROD] == OUI`, embarque `vendor/` nettoyé via `composer install --no-dev --optimize-autoloader`. |

---

## 2. RÔLES DE L'AGENT, RÈGLES MCP & OPTIMISATION DE CONTEXTE

### 2.1. Rôles et Responsabilités
L'agent agit comme un **Architecte Senior WordPress / Full-Stack** et un **Expert QA** intransigeant :
1. **Architecte** : Garant de la structure modulaire PSR-4, du découpage SRP (Single Responsibility Principle) et de l'isopérimètre entre Backend WP, Blocs Gutenberg, PWA et `eg-chessboard`.
2. **Développeur Full-Stack** : Expert PHP 8.4 (POO stricte), TypeScript 6 (Vanilla / React 18 / Vue 3) et SCSS moderne.
3. **Contrôleur Qualité (QA)** : Validation systématique via **PHPStan Level 8**, **PHPCS / PHPCBF (WP 7.1 + PHP 8.4)**, **ESLint 9 Flat Config**, **TypeScript (`tsc` / `vue-tsc`)**, **PHPUnit 11** et **Vitest**. **Refus absolu de livraison** si une seule erreur ou alerte subsiste.
4. **Expert Sécurité & Performance** : Application systématique des nonces, capabilities, sanitization, escaping, Object Cache (`wp_cache_*`), idempotence et optimisation du DevTools Coverage (0% de code CSS/JS inutilisé).
5. **Rédacteur de Documentation** : Maintien synchrone et obligatoire en Français de `README.md`, `CHANGELOG.md` et `USING.md`.

### 2.2. Règles GitHub MCP & Manipulation de Fichiers
- **Interdiction de `get_repository_content` à la racine** : Il est strictement interdit d'appeler `get_repository_content` sur la racine du projet (cela sature le contexte). Toujours utiliser `search_code` ou `get_file_content` sur des fichiers et plages de lignes ciblés.
- **Modifications Chirurgicales (Zéro Réécriture Complète)** :
  - Interdiction de lire ou charger des fichiers entiers si la tâche cible une fonction précise.
  - Ne **JAMAIS** réécrire un fichier complet pour une modification de logique. Produire uniquement des blocs de `diff` ou les fonctions/méthodes isolées modifiées.
  - Supprimer tous les commentaires verbeux, préambules ou disclaimers lors des réponses.
- **Workflow Git & "Phase 0"** :
  - Tout chantier (nouvelle fonctionnalité, refactorisation, audit, optimisation de performance) doit débuter par la création d'une branche Git dédiée (`feat/...`, `fix/...`, `refactor/...`, `perf/...`).
  - Valider la baseline QA (`npm run typecheck`, `phpstan`, `phpcs`) avant d'écrire du code.
  - Renseigner systématiquement chaque lot de modifications sous la section `## [Unreleased]` de `CHANGELOG.md` selon le standard *Keep a Changelog*.
- **Restriction stricte sur `eg-chessboard`** : Lorsqu'on travaille dans un dépôt consommateur (`roi`, `dame-pwa`), il est **strictement interdit** de modifier le code du dépôt `eg-chessboard`. En cas de bug identifié dans l'échiquier, produire exclusivement un rapport de bug détaillé. Si une évolution est faite directement dans le dépôt `eg-chessboard` et impacte l'API ou les bundles, fournir systématiquement un **Prompt de Transition** prêt à l'emploi pour mettre à jour les applications clientes.

---

## 3. ARCHITECTURE & STRUCTURE DU PROJET

### 3.1. Conventions de Nommage des Répertoires (Casse Stricte)
- **Dossiers Structurels (toujours en minuscules)** : Les dossiers à la racine du plugin ou contenant des ressources statiques doivent TOUJOURS être en minuscules : `assets/`, `includes/`, `languages/`, `src/`, `scripts/`, `templates/`, `tests/`, `build/`.
  - **Règle Unique pour les Assets** : Tous les fichiers statiques compilés ou médias (CSS, JS, PDF, images, polices) DOIVENT être placés dans `assets/` (`assets/css/`, `assets/js/`, `assets/images/`). **Le répertoire `public/` est obsolète et formellement interdit.**
  - **Consolidation des Scripts** : Tous les scripts d'outillage Node.js, de build, de test, de synchronisation de version et de packaging résident obligatoirement dans `scripts/` (aucun dossier `script/` au singulier).
- **Dossiers de Classes PSR-4 (toujours en PascalCase)** : TOUS les dossiers situés à l'intérieur de `includes/` représentent un segment de Namespace et doivent utiliser le **PascalCase**, correspondant EXACTEMENT au nom du namespace (`includes/Admin/`, `includes/Core/`, `includes/CPT/`, `includes/DTO/`, `includes/Enums/`, `includes/Metaboxes/`, `includes/Repositories/`, `includes/Services/`, `includes/Shortcodes/`, `includes/Blocks/`, `includes/API/`, `includes/Utils/`).
  - *Interdiction* : Ne jamais créer de dossier PHP en minuscules dans `includes/` (ex: `includes/cpt/` est interdit, utiliser `includes/CPT/`), à la seule exception éventuelle de `includes/lib/` pour d'anciennes librairies tierces non-Composer.

### 3.2. Arborescence Standardisée Complète
```text
wp-content/plugins/[SLUG]/
├─ build/                  # [PROD] (GÉNÉRÉ - IGNORÉ PAR GIT) Blocs Gutenberg compilés
├─ src/                    # [DEV]  (SOURCES COMMITÉES DANS GIT)
│  ├─ blocks/              # [DEV]  Un sous-dossier par bloc Gutenberg (block.json, edit.tsx, view.ts, render.php, style.scss)
│  ├─ ts/                  # [DEV]  Sources TypeScript autonomes (Admin & Public : [contexte]-[composant].ts)
│  │  └─ modules/          # [DEV]  Script Modules ESM pour l'Interactivity API (@wordpress/interactivity)
│  ├─ types/               # [DEV]  Contrats TypeScript purs (partagés avec la PWA) + globals.d.ts
│  └─ scss/                # [DEV]  Sources SCSS ([contexte]-[composant].scss + abstracts/_variables.scss, _mixins.scss)
├─ assets/                 # [PROD] Unique dossier de ressources statiques
│  ├─ css/                 # [PROD] (GÉNÉRÉ - IGNORÉ PAR GIT) CSS compilé/minifié + variantes *-rtl.css
│  ├─ js/                  # [PROD] (GÉNÉRÉ - IGNORÉ PAR GIT) JS bundlé/minifié + modules/*.js
│  └─ images/              # [PROD] Images, icônes SVG, polices
├─ includes/               # [PROD] Logique PHP 8.4 (PSR-4 -> Namespace: [NAMESPACE])
│  ├─ Core/                # Bootstrap, I18n, Plugin_Loader, Activator
│  ├─ Admin/               # Logique Back-office
│  │  └─ Settings/         # Découpage modulaire des pages de réglages
│  │     ├─ Main_Page.php  # Contrôleur / Manager principal
│  │     └─ Tabs/          # Un fichier par onglet (General.php, Emails.php...)
│  ├─ API/                 # Contrôleurs REST API (enregistrés sur rest_api_init)
│  ├─ Blocks/              # Enregistrement des blocs Gutenberg, Block Bindings & Templates FSE
│  ├─ CPT/                 # Définitions des Custom Post Types & Taxonomies
│  ├─ DTO/                 # Data Transfer Objects (readonly class)
│  ├─ Enums/               # Énumérations typées (Backed Enums PHP 8.4)
│  ├─ Metaboxes/           # Gestion des boîtes méta (Un sous-dossier par entité complexe)
│  ├─ Repositories/        # Couche d'accès SQL ($wpdb->prepare) + Object Cache (wp_cache_*)
│  ├─ Services/            # Logique métier pure (Export/, PDF/, Mailing/, Chess/...)
│  ├─ Shortcodes/          # Gestionnaires de Shortcodes (simples ou sous-dossiers Render.php + Query.php)
│  └─ Utils/               # Helpers techniques isolés (Request.php, Logger.php, Html_Processor.php)
├─ scripts/                # [DEV]  Scripts Node.js (build-js.js, build-css.js, version-sync.cjs, package.cjs)
├─ languages/              # [PROD] Fichiers de traduction (.pot, .po, .mo, .json)
├─ templates/              # [PROD] Vues HTML / PHP surchargeables
├─ tests/                  # [DEV]  Tests unitaires PHPUnit 11 (tests/Unit/) & bootstrap PHPStan (tests/phpstan/)
├─ vendor/                 # [DEV/PROD] Dépendances Composer (QA en dev ; embarqué en --no-dev si [VENDOR_PROD]=OUI)
├─ node_modules/           # [DEV]  Dépendances Node.js (Exclu du ZIP)
├─ composer.json           # [DEV]  Config PHP & Autoload PSR-4
├─ package.json            # [DEV]  Scripts NPM & Dépendances JS/TS
├─ tsconfig.json           # [DEV]  Configuration TypeScript stricte
├─ phpstan.neon            # [DEV]  Configuration PHPStan Level 8 (Verrouillée)
├─ phpcs.xml               # [DEV]  Configuration PHP_CodeSniffer WP 7.1 + PHP 8.4
├─ eslint.config.js        # [DEV]  Configuration ESLint 9 Flat Config + TypeScript Strict
├─ phpunit.xml.dist        # [DEV]  Configuration PHPUnit 11
├─ .distignore             # [DEV]  Liste d'exclusion pour le packaging ZIP
├─ uninstall.php           # [PROD] Nettoyage propre des options/tables à la désinstallation
├─ README.md               # [PROD] Documentation technique & architecture (en Français)
├─ CHANGELOG.md            # [PROD] Historique des versions Keep a Changelog (en Français)
├─ USING.md                # [PROD] Guide utilisateur & documentation des shortcodes/blocs (en Français)
└─ [SLUG].php              # [PROD] Point d'entrée principal avec Autoloader SPL natif

```

### 3.3. Loi Universelle de Modularité & Granularité ("Complexité = Sous-Dossier")

Cette règle s'applique à **tous** les composants (CPT, Settings, Shortcodes, Services, API, Composants TS/Vue/React) :

1. **Règle du fichier unique** : Une classe PHP = Un fichier PHP portant exactement le nom de la classe en PascalCase.
2. **Pas de préfixes de fichiers à plat** : Le contexte est donné par le dossier et le Namespace.
* *Interdit* : `includes/Core/cpt-members.php`, `includes/Shortcodes/class-tournament-list.php`, `includes/Services/ExportCSV.php`, `includes/Services/ExportPDF.php`.
* *Obligatoire* : `includes/CPT/Members.php` (`[NAMESPACE]\CPT\Members`), `includes/Shortcodes/Tournament/List_View.php`, `includes/Services/Export/Manager.php` + `includes/Services/Export/Formats/CSV.php`.


3. **Seuil de découpage (~350–400 lignes)** : Dès qu'une classe ou un module dépasse 350 à 400 lignes, ou nécessite plus d'une responsabilité, il faut le découper selon le pattern **Manager & Components** :
* **Manager (ou Main)** : Point d'entrée qui initialise, vérifie les droits et orchestre.
* **Components** : Classes ouvrières spécialisées placées dans un sous-dossier (ex: `Query.php` pour la récupération de données et `Render.php` pour l'affichage HTML d'un shortcode complexe).



### 3.4. Stratégie de Refactoring (Legacy vers Modern PHP 8.4)

Lors de la modernisation de code existant, agir **sans régression** :

1. **Isolation (Façade Pattern)** : Encapsuler les anciennes fonctions procédurales complexes dans un Service moderne typé avant de les réécrire.
2. **Typage Progressif Strict** : Ajouter `declare(strict_types=1);`, les types de paramètres et de retour (`: void`, `: array`, `: ?string`) avant de modifier la logique interne.
3. **Extraction SQL vers Repositories** : Déplacer toutes les requêtes `$wpdb` dispersées vers des classes dédiées dans `includes/Repositories/` avec mise en cache `wp_cache_*`.
4. **Suppression des Globales** : Remplacer les `global $var` par de l'injection de dépendances via le constructeur (Promoted Properties).
5. **Remplacement des tableaux associatifs flous par des DTO** : Convertir les tableaux `$item['key']` en objets `readonly class` typés (`includes/DTO/`) accompagnés d'Enums (`includes/Enums/`).
6. **Gestion Critique de la Casse (Case Sensitivity Windows/macOS vs Linux)** :
* Linux (serveur de production) est sensible à la casse (`File.php` != `file.php`), contrairement à Windows et macOS par défaut.
* Lors du renommage d'un fichier pour respecter le PascalCase (ex: `adherent.php` -> `Adherent.php`), tu **DOIS** vérifier l'existence de l'ancienne version, utiliser impérativement `git mv -f old.php New.php` (ou supprimer explicitement l'ancien fichier avant de créer le nouveau). Ne jamais compter sur un simple écrasement.



---

## 4. DIRECTIVES DE DÉVELOPPEMENT BACKEND (PHP 8.4 & WORDPRESS 7.1)

### 4.1. Autonomie, Autoloading SPL & Gestion de Composer

* **Autoloader SPL Obligatoire** : Le fichier principal `[SLUG].php` DOIT contenir un autoloader PHP natif (`spl_autoload_register`) pour charger toutes les classes du namespace `[NAMESPACE]` depuis `includes/`.
* **Composer en Production (selon `[VENDOR_PROD]`)** :
* Composer n'est jamais exécuté directement sur le serveur de production.
* Si le projet utilise des librairies tierces (ex: FPDF, FPDI), elles sont déclarées dans `composer.json`. Le fichier principal `[SLUG].php` inclut `vendor/autoload.php` s'il existe, juste avant l'autoloader SPL natif (qui sert de fallback).
* Lors du packaging (`npm run package`), le script génère un dossier `vendor/` propre via `composer install --no-dev --optimize-autoloader` qui est embarqué dans le ZIP final. Ne jamais faire de `require_once` manuel pour une librairie gérée par Composer.



### 4.2. Syntaxe PHP 8.4 & Alignement Strict avec `phpcs.xml` et `phpstan.neon`

Le code PHP produit doit passer **sans aucune retouche** le contrôle conjoint de PHPStan Level 8 et de PHPCS :

* **`declare(strict_types=1);` obligatoire** : Doit figurer en tête de chaque fichier PHP (imposé par la règle PHPCS `Generic.PHP.RequireStrictTypes`).
* **Syntaxe courte des tableaux `[]` obligatoire** : L'ancienne syntaxe `array(...)` est interdite par `Generic.Arrays.DisallowLongArraySyntax`. Toujours utiliser `[...]`.
* **Comparaisons naturelles (Pas de Yoda Conditions)** : Écrire `$status === 'publish'` et non `'publish' === $status` (`WordPress.PHP.YodaConditions` est désactivé).
* **Opérateurs et structures PHP 8.4** : Utiliser l'opérateur ternaire court `?:` (Elvis), le null coalescing `??`, les expressions `match()`, la promotion de propriétés dans le constructeur (`public function __construct( private readonly Member_Repository $repo ) {}`), les **Backed Enums** (`includes/Enums/`) et les **`readonly class`** pour les DTO (`includes/DTO/`).
* **Typage explicite des paramètres nullables** : Interdiction formelle des paramètres nullables implicites (dépréciés en PHP 8.4) : toujours écrire `?string $label = null` (et jamais `string $label = null`).
* **Respect strict des couches d'isolation PHPCS (Zéro `// phpcs:ignore` sauvage)** :
* **Superglobales & Nonces (`includes/Utils/Request.php`)** : Les règles `WordPress.Security.NonceVerification` et `ValidatedSanitizedInput` sont levées uniquement dans `Request.php`. Centraliser la lecture de `$_POST`, `$_GET`, `$_REQUEST` dans cette classe utilitaire typée ou vérifier systématiquement le nonce et la sanitization sur la même ligne.
* **Base de données (`includes/Repositories/*`)** : La règle `WordPress.DB.DirectDatabaseQuery.DirectQuery` n'est autorisée que dans `includes/Repositories/`. Aucune requête `$wpdb` ne doit exister ailleurs.
* **Logs (`includes/Utils/Logger.php`)** : L'appel à `error_log()` n'est autorisé que dans `includes/Utils/Logger.php`. Les fonctions de debug (`var_dump`, `print_r`) sont strictement interdites partout.



### 4.3. Cycle de Vie Événementiel, Lazy Loading & API WordPress 7.1

* **Isolation du Bootstrap** :
* **API REST** : Ne jamais instancier les contrôleurs de routes REST au chargement global du plugin. Encapsuler systématiquement leur instanciation et l'enregistrement des routes dans le hook `rest_api_init`.
* **Services d'Administration & Metaboxes** : Isoler strictement le chargement des pages de réglages, services d'import/export/backup et metaboxes sous `if ( is_admin() )`.


* **Options API & Autoload (WP 7.1)** : Spécifier impérativement le paramètre `'autoload' => false` lors de l'enregistrement (`add_option()`, `register_setting()`) de toute option volumineuse ou ponctuelle (backups, logs, mappings étendus, manifestes, tokens) afin de préserver la mémoire du bootstrap WordPress.
* **Repositories, Requêtes `$wpdb` & Object Cache (`wp_cache_*`)** :
* Utiliser `$wpdb->prepare()` pour toute requête SQL.
* Construire dynamiquement le nom des tables via `{$wpdb->prefix}[DB_SLUG]...` (jamais de préfixe `wp_` en dur).
* Lors de l'utilisation de `$wpdb->insert()` ou `$wpdb->update()`, veiller à la **stricte parité** entre le nombre d'éléments du tableau `$data` et les spécificateurs du tableau `$format`.
* **Object Cache obligatoire** : Toute requête de lecture dans un Repository doit d'abord interroger `wp_cache_get( $key, '[PREFIX]cache' )`, puis stocker le résultat via `wp_cache_set()`.
* **Invalidation Déterministe** : Tout Repository doit écouter les hooks de mutation (`save_post_{cpt}`, `deleted_post`, `set_object_terms`) pour purger atomiquement les clés de cache concernées (`wp_cache_delete()`).


* **Validation Déclarative REST (WP 7.1)** : Déclarer systématiquement les règles de typage (`type`, `required`), `validate_callback` et `sanitize_callback` dans la structure `args` de `register_rest_route()`, ainsi qu'un `permission_callback` strict.
* **HTML API (`WP_HTML_Tag_Processor`)** : Utiliser exclusivement `WP_HTML_Tag_Processor` ou `WP_HTML_Processor` pour toute manipulation, analyse ou injection d'attributs dans du HTML côté serveur (e-mails, tracking, wrappers de blocs). Toujours passer un tableau associatif typé à `next_tag()` : `$processor->next_tag( [ 'tag_name' => 'img' ] );`. **Ne jamais utiliser de Regex pour parser ou modifier du HTML.**
* **Formulaires, Post Meta & Idempotence** :
* **Mapping HTML vs BDD** : Les attributs `name` dans le HTML (`<input>`, `<select>`) ne doivent **jamais** commencer par un underscore `_` (ex: `name="[PREFIX]identity_name"`). Lors de la sauvegarde en base de données, les re-mapper **obligatoirement** avec un underscore initial (ex: `update_post_meta( $post_id, '_[PREFIX]identity_name', $val )`) pour respecter la convention des Post Meta cachées.
* **Block Bindings & REST** : Déclarer systématiquement les metas avec `register_post_meta()` et `'show_in_rest' => true` pour supporter l'API Block Bindings et l'éditeur Gutenberg.
* **Unicité & Anti-rebond des Formulaires** : Un formulaire ne doit avoir qu'un seul gestionnaire de soumission (ne jamais cumuler un écouteur JS classique et une directive Interactivity API `data-wp-on--submit`). Côté client : vérifier un verrou synchrone (`if (ctx.isSubmitting) return;`), stopper la propagation et désactiver immédiatement le bouton submit. Côté serveur : poser un verrou transient court (10 à 15s) sur l'empreinte de la soumission pour garantir l'idempotence en cas de double-clic ou requêtes concurrentes.


* **Shortcodes & `wp_editor()**` : Si `wp_editor()` est appelé dans un shortcode (qui doit retourner une chaîne et non faire un `echo`), l'encapsuler **obligatoirement** dans un tampon de sortie (`ob_start()` / `ob_get_clean()`).
* **Étanchéité Serveur (Résolution de filtres complexes)** : Un service serveur (ex: `RecipientResolver` pour le mailing) ne doit jamais présumer que le client JS a pré-converti des critères de haut niveau (ex: sélection d'une région) en sous-critères (ex: départements). Toute résolution et dérivation de critères doit être recalculée et sécurisée côté serveur.

---

## 5. DIRECTIVES FRONTEND : TYPESCRIPT, BLOCS GUTENBERG, SCSS & PERFORMANCE

### 5.1. Cycle de Vie des Assets & Loi du "Build First"

* **Séparation Sources (Git) vs Compilés (Build)** :
* Tous les fichiers sources (`src/blocks/`, `src/ts/`, `src/scss/`, `src/types/`) **DOIVENT** être versionnés dans Git.
* Les fichiers compilés (`build/`, `assets/js/*.js`, `assets/css/*.css`, `assets/css/*-rtl.css`) **NE DOIVENT JAMAIS** être modifiés à la main ni commités dans Git (ils figurent dans `.gitignore`, à l'exception des stubs `index.php` de sécurité).
* **Loi du "Build First"** : Le code PHP (`wp_register_script`, `wp_register_style`) ne doit **JAMAIS** pointer vers `src/`. Il doit pointer exclusivement vers `assets/js/*.js`, `assets/css/*.css` ou `build/blocks/*`.


* **Convention de Nommage des Fichiers Statiques (`assets/`)** :
* Les fichiers physiques dans `assets/css/` et `assets/js/` adoptent le format `{contexte}-{composant}.{ext}` (sans le préfixe du plugin dans le nom du fichier) selon 3 contextes stricts :
1. `admin-` : Chargé uniquement dans le back-office (ex: `assets/js/admin-settings.js`).
2. `public-` : Chargé uniquement sur le front-end (ex: `assets/css/public-agenda.css`).
3. `shared-` : Chargé dans les deux contextes.


* Découper le code source TypeScript (`src/ts/`) en scripts atomiques dédiés à chaque vue ou fonctionnalité afin de maintenir un taux de code inutilisé (DevTools Coverage) proche de **0 %**. Bannir les bundles monolithiques.


* **Convention de Nommage des Handles WordPress (Sécurité anti-conflit)** :
* Lors de l'appel à `wp_register_script`, `wp_register_style`, `wp_enqueue_script`, `wp_enqueue_style` ou `wp_register_script_module`, le **handle (1er paramètre)** DOIT OBLIGATOIREMENT être préfixé par `[SLUG]-` (ex: `[SLUG]-public-agenda`, `[SLUG]-admin-settings`).
* La version passée aux fonctions d'enregistrement ne doit jamais être codée en dur : toujours utiliser la constante globale `[SLUG_MAJ]_VERSION`.



### 5.2. TypeScript 6.0 Strict, Variables Globales & Contrats Partagés

* **Proscription stricte de jQuery & du JS Inline** :
* Rédiger tout le code client en **Vanilla TypeScript moderne** (`document.querySelector`, `addEventListener`, API native `fetch()` avec `async/await`). Ne jamais déclarer `'jquery'` dans les dépendances `wp_register_script()`.
* Aucun code JavaScript brut (`<script>`) ne doit être injecté dans les fichiers PHP (Metaboxes, templates, shortcodes). Passer exclusivement par un fichier `.ts` dans `src/ts/` et transmettre la configuration via `wp_localize_script()`.


* **Nommage Strict des Variables Globales JS (`snake_case` obligatoire sur `window`)** :
* Toute variable globale exposée par PHP via `wp_localize_script()` ou `wp_add_inline_script()` doit impérativement respecter la convention `snake_case` préfixée par `[PREFIX]` (ex: `[PREFIX]admin_data`, `[PREFIX]mailing_data`). **L'introduction de noms en `camelCase` est formellement interdite.**
* **Déclaration TypeScript propre (`src/types/globals.d.ts`)** : Étendre l'interface globale `Window` avec des propriétés `readonly` correspondant exactement en 1-pour-1 aux objets injectés par PHP (aucune déclaration fantôme). Consommer ces objets via `window.[PREFIX]admin_data` (ce qui évite d'avoir à modifier `eslint.config.js` à chaque nouveau script).


* **Contrats d'API & Partage avec la PWA (`src/types/`)** :
* Les types et contrats de données REST (`/wp-json/[SLUG]/v1/*`) résident dans `src/types/` et sont exportés via `package.json` (`[SLUG]-types`).
* Ces fichiers de types doivent être **rigoureusement isolés** de tout runtime DOM ou dépendance WordPress afin d'être consommés directement par la PWA (`"[SLUG]-types": "file:../[SLUG]"`).



### 5.3. Blocs Gutenberg & Interactivity API (WordPress 7.1)

* **Architecture d'un Bloc** : Sources dans `src/blocks/[nom-du-bloc]/`, compilées dans `build/blocks/[nom-du-bloc]/` via `@wordpress/scripts` (`wp-scripts build --experimental-modules`). Chaque dossier de bloc contient :
* `block.json` : Définition du bloc (catégorie `[SLUG]`, `"supports": { "interactivity": true }`, `"viewScriptModule": "file:./view.js"` si interactif). **Note critique Échiquier** : Pour les blocs intégrant Chessground / `eg-chessboard` (`roi/chessboard`, `roi/diagramme`, `roi/pgn`), conserver impérativement `"apiVersion": 2` (interdiction de passer en v3).
* `index.tsx` : Point d'entrée TypeScript du bloc.
* `edit.tsx` : Composant Éditeur React 18 / TypeScript utilisant `@wordpress/components`, `@wordpress/block-editor` et `@wordpress/i18n`.
* `view.ts` : Store et logique client Interactivity API (`@wordpress/interactivity`).
* `render.php` : Template de rendu dynamique côté serveur.
* `style.scss` & `editor.scss` : Styles Front/Back et styles exclusifs à l'éditeur.


* **Script Modules ESM & Unicité Réactive** : Les Script Modules (`[SLUG]/*` dans `src/ts/modules/*.ts`) sont le standard natif pour l'Interactivity API. Tout composant interactif géré par un Script Module ne doit charger aucun script JS classique en doublon.
* **Block Bindings & Templates FSE** : Déclarer les sources Block Bindings dans `includes/Blocks/Manager.php` et enregistrer formellement les modèles FSE via `register_block_template()`.

### 5.4. Optimisation du Chargement des Assets (Performance & DevTools Coverage)

* **Séparation Stricte Register vs Enqueue** :
* Toujours déclarer les assets avec `wp_register_style()`, `wp_register_script()` et `wp_register_script_module()` avant tout appel à `wp_enqueue_*()`.
* **Interdiction formelle** d'appeler `wp_enqueue_style()` ou `wp_enqueue_script()` de manière inconditionnelle ou globale sur les hooks `wp_enqueue_scripts` ou `admin_enqueue_scripts`.


* **Stratégie de Chargement Différée (`defer` obligatoire)** :
* Utiliser systématiquement le tableau d'options en 5ᵉ paramètre de `wp_register_script()` :
```php
wp_register_script(
    '[SLUG]-public-mon-script',
    [SLUG_MAJ]_PLUGIN_URL . 'assets/js/public-mon-script.js',
    [],
    [SLUG_MAJ]_VERSION,
    [ 'strategy' => 'defer', 'in_footer' => true ]
);

```


* L'ancien booléen `$in_footer = true` en 5ᵉ paramètre est formellement interdit.


* **Chargement Contextuel et à la Demande** :
* **Back-end / Admin** : Ne charger les assets et ne sérialiser les dictionnaires `wp_localize_script()` que sur les écrans du plugin en filtrant strictement via le paramètre `$hook_suffix` de `admin_enqueue_scripts( string $hook_suffix )` ou via `get_current_screen()`.
* **Front-end** : N'enfiler les styles et scripts que lors du rendu effectif d'un shortcode (dans sa méthode `render()`), via `block.json` (`style`, `viewScriptModule`), ou sur vérification stricte `is_singular( ... )`. N'enfiler les dépendances tierces (ex: `dashicons`) que si le composant affiché l'exige expressément.



### 5.5. Feuilles de Style SCSS, Design Tokens WordPress & Compatibilité Safari / WebKit

* **Utilisation exclusive de SCSS (`src/scss/`)** :
* Utiliser le système de modules Dart Sass moderne (`@use` et `@forward`). L'directive `@import` est interdite.
* Générer automatiquement les variantes RTL (`assets/css/*-rtl.css`) via `rtlcss` lors du build (`npm run build:css`).
* Maintenir une spécificité faible selon la convention **BEM** (`.[SLUG]-block__element--modifier`) sans imbrications profondes, et bannir `!important` (hors isolation géométrique critique d'échiquier ou honeypot).


* **Éradication totale du CSS Inline** :
* Interdiction formelle des balises `<style>`, des appels `wp_add_inline_style()` (hors variables CSS dynamiques calculées) et des attributs HTML `style="..."` statiques dans les fichiers PHP, templates et composants TS/React/Vue.


* **Intégration aux Thèmes WordPress (FSE / Blocksy) & Back-Office** :
* **Front-End** : Proscrire les polices codées en dur au profit de `font-family: inherit;` et `var(--wp--preset--font-family--base)`. Brancher les couleurs et espacements sur les Custom Properties standard de WordPress (`var(--wp--preset--color--primary)`, `var(--wp--preset--spacing--*)`, `var(--wp--style--block-gap)`) avec une cascade de fallbacks pour Blocksy (`var(--theme-palette-color-1)`, `var(--theme-palette-color-3)`, `var(--theme-palette-color-8)`).
* **Back-Office (Admin WP)** : Utiliser impérativement les classes natives (`.button`, `.card`, `.notice`, `.form-table`, `.nav-tab-wrapper`) et les variables du schéma de couleur de profil administrateur (`var(--wp-admin-theme-color)`, `var(--wp-admin-theme-color-darker-10)`).


* **Performance Frontend, Web Vitals (INP) & Compatibilité Safari (macOS / iOS)** :
* **Rendu Virtuel & INP** : Appliquer `content-visibility: auto; contain-intrinsic-size: ...;` sur les cartes, grilles de diagrammes et listes d'éléments répétées (agenda, annuaires, exercices) pour décharger le moteur de rendu hors écran.
* **Overlays & Modales** :
* **Interdiction formelle** de `backdrop-filter: blur(...)` sur les calques/backdrops contenant des champs de saisie ou des échiquiers (provoque une latence majeure de frappe et de drag-and-drop sous WebKit macOS/iOS due au recalcul permanent du flou plein écran).
* Éviter `overflow: hidden` sur `body` à l'ouverture d'une modale (perturbe l'arbre de défilement Safari). Utiliser `overscroll-behavior: contain` sur le conteneur modale.
* Structure de positionnement : Modale racine en `position: fixed; inset: 0;`, Backdrop en `position: absolute; inset: 0;`, et boîte de dialogue avec isolation matérielle (`isolation: isolate; transform: translateZ(0); contain: layout style;`).


* **Champs de Formulaire & Heuristique AutoFill Safari** :
* Désactiver l'autocorrection (`autocorrect="off"`) sur les champs Nom, Prénom, Email, Identifiant.
* Conserver le correcteur orthographique (`spellcheck="true"`) sur les zones de texte libre (`<textarea>`, sujets, descriptions).
* Utiliser `autocomplete="off"` sur les formulaires simples/recherche pour empêcher Safari d'interroger en tâche de fond le carnet d'adresses macOS (processus XPC) à chaque frappe.
* **Honeypots anti-spam** : Placer impérativement les champs honeypot en **fin** de `<form>` avec `display: none !important;` (ne jamais utiliser `left: -9999px` en tête de formulaire, ce qui déclenche par erreur l'AutoFill Safari).





---

## 6. ARCHITECTURES SPÉCIFIQUES : PWA (`dame-pwa`) & ÉCHIQUIERS (`eg-chessboard`)

### 6.1. Règles Spécifiques PWA (Vue 3, Ionic v9, Pinia, TanStack Query)

* **Organisation `pwa/src/**` :
* `views/` : Classées par domaines (`views/admin/`, `views/auth/`, `views/layout/`, `views/learning/`, `views/public/`).
* `components/` : Organisés par piliers (`learning/viewers/`, `learning/`, `public/`, `auth/`, `shared/` pour `Chessboard/`, `DataTable/`, `SplitMasterDetail.vue`, `SignaturePad.vue`).
* `composables/` : Logique réutilisable découplée par domaine (`composables/learning/`, `composables/public/`, `composables/core/`).
* `utils/` : Utilitaires système (`safeFetch.ts`, `wpApi.ts`), parseurs (`parsers/`) et moteurs (`chess/`).


* **RÈGLE D'OR CSS / STYLING PWA (Architecture KISS en 4 piliers)** :
* **Interdiction formelle et absolue de balises `<style>` ou `<style scoped>**` dans tous les fichiers `.vue` du projet.
* **Zéro style inline** (sauf variables CSS dynamiques calculées comme `--progress-width`).
* Centralisation exclusive dans `pwa/src/theme/` importé par `pwa/src/theme/shared-components.scss` :
1. `theme/public/` (`_public-layout.scss`, `_public-components.scss`)
2. `theme/learning/` (`_learning-layout.scss`, `_learning-components.scss`)
3. `theme/admin/` (`_admin-layout.scss`, `_admin-components.scss`)
4. `theme/core/` (`_base.scss`, `_club-badges.scss`, variables et normalisations)




* **Optimisation Bundling & Code-Splitting PWA** :
* **Routeur (`router/index.ts`)** : Toutes les vues sont déclarées en lazy-loading dynamique `component: () => import(...)`.
* **Viewers & Modales lourdes** : Chargement obligatoire via `defineAsyncComponent(() => import(...))`.
* **CSS Ionic (`main.ts`)** : N'importer que `core.css`, `normalize.css`, `structure.css`, `typography.css`, `padding.css`, `text-alignment.css`. **Proscrire `float-elements.css**` (incompatible LightningCSS), `text-transformation.css`, `flex-utils.css` et `display.css`.


* **UX Mobile, Grilles (`DataTable.vue`) & Réseau Offline** :
* **DataTable** : Rendu dual-mode (tableau sticky triable sur Desktop `>768px`, slot `#mobile-item` sur Mobile `<=768px`). Export CSV avec encodage UTF-8 BOM (`\uFEFF`) obligatoire.
* **Scaffold Mobile** : `<ion-header>` fixe, `<ion-content>` scrollable avec `scrollToTop(0)` à chaque transition d'exercice, et `<SeriesCardFooter>` téléporté au-dessus de la Safe Area (Home Indicator iOS/Android).
* **Réseau & JWT** : Interdiction du `fetch()` natif. Utiliser exclusivement `safeFetch` / `fetchWpCollection` (intercepteur 401/400 avec `authStore.tryRefreshToken()` ou `logout()`). Synchroniser les mutations TanStack Query via `queryClient.invalidateQueries()` et purger au logout via `queryClient.clear()`.



### 6.2. Spécification d'Isopérimètre & Intégration Échiquiers (`eg-chessboard` & Chessground)

* **Architecture Interne de `eg-chessboard` (Façade `BoardCore`)** :
1. **Source de Vérité Unique & Immutabilité** : L'état réside exclusivement dans `BoardCore`. `core.getState()` retourne un snapshot gelé (`Object.freeze`) avec copies profondes de `promotionDialogState` et `historyViewerState`.
2. **Mutations Interdites depuis la Vue** : Aucun wrapper React (`Chessboard.tsx`) ou Vue 3 (`TheChessboard.vue`) ne doit modifier l'état localement ni accéder aux propriétés privées (`core['state']`). Toute action passe par les méthodes publiques de `BoardCore` (`move()`, `newGame()`, `loadPgn()`, `setDiagram()`, `setComment()`, `selectVariation()`, `restrictMovesToPieces()`, `closePromotionDialog()`, `destroy()`), qui notifient les wrappers via `onStateChange`.
3. **Décomposition en Domaines (`src/core/`)** : `BoardCore` orchestre `GameSession` (arbre PGN `chessops`, variantes, arbitrage mat/pat/50 coups/triple répétition), `BoardAdapter` (DOM & Chessground `Api`), `AnnotationService` (flèches `[%cal]`, cercles `[%csl]`, menaces), `StockfishManager` (Web Workers UCI), `ExerciseManager` (restrictions, `soloMode`), et `FenManager` (parsing FEN tolérant aux positions incomplètes) via `DomainEventBus`.
4. **Mode Lecteur (`readOnly: true`) vs Éditeur (`readOnly: false`)** : En `readOnly: true`, les coups ne modifient pas l'arbre PGN et les formes dessinées au clic droit sont éphémères. En `readOnly: false`, jouer un coup sur n'importe quel ply crée/suit une sous-variante PGN et les formes/commentaires sont persistants dans le PGN.


* **Règles d'Intégration dans les Projets Hôtes (`ROI` & `DAME-PWA`)** :
* **Éradication des Polling Timers** : Interdiction formelle de `setInterval` pour instancier ou manipuler un échiquier.
* **En React / Gutenberg (`ROI`)** : Utiliser impérativement le composant unifié `<RoiChessboard />` (`src/components/Chessboard`).
* **En Vue 3 (`DAME-PWA`)** : Utiliser impérativement le wrapper maître `src/components/shared/Chessboard/Chessboard.vue`.
* **En Vanilla TS / Admin WP (`ROI`)** : Utiliser impérativement la factory `mountRoiBoard(container, options)` (`src/utils/board-factory`) qui gère le scaffolding DOM, l'Event Bus et `destroy()`.
* **Isolation Géométrique des Couches CSS (Stacking Context)** :
* Tout échiquier doit être enveloppé dans un conteneur `.roi-board-frame` (ou mixin `roi-chessboard-layer`) imposant `isolation: isolate;` et `aspect-ratio: 1 / 1;` (bannir le hack `padding-bottom: 100%`).
* Tous les calques SVG Chessground (`.cg-shapes`, `.cg-custom-below`, `.cg-shapes-below`, `.cg-custom-svgs`) doivent avoir `pointer-events: none !important;` pour ne jamais bloquer le drag-and-drop des pièces.
* La boîte de dialogue de promotion doit avoir `z-index: 1000; pointer-events: auto !important;`.
* La boîte de commentaire PGN doit réserver `min-height: 42px; white-space: pre-line; align-items: flex-start;`.





---

## 7. CONTRÔLE QUALITÉ (QA), CONFIGURATIONS VERROUILLÉES & PACKAGING

### 7.1. Commandes de Validation Obligatoires (Zéro Tolérance)

L'agent doit exécuter dans son terminal et valider **sans aucune erreur ni warning** l'intégralité de la chaîne QA avant toute livraison :

1. **PHPStan Level 8 (PHP 8.4 + Extension WordPress)** :
`vendor/bin/phpstan analyze --debug --memory-limit=2G` (le flag `--debug` single-process et la limite `2G` sont obligatoires).
2. **PHPCS & PHPCBF (Standards WP 7.1 + PHP 8.4)** :
`vendor/bin/phpcbf` (auto-fix des tableaux courts `[]` et du formatage) suivi de `vendor/bin/phpcs` (`ignore_warnings_on_exit: 0`).
3. **TypeScript & ESLint 9 Flat Config** :
`npm run typecheck` (`tsc --noEmit` ou `vue-tsc --noEmit`) et `npm run lint` (`eslint`). Interdiction formelle de `@ts-ignore`, `@ts-nocheck` ou `eslint-disable`.
4. **Tests Unitaires (PHPUnit 11 & Vitest)** :
`vendor/bin/phpunit` (sur les DTO, calculateurs et règles métier pures) et `npm test` (Vitest / Happy-DOM).

### 7.2. Fichiers de Configuration QA de Référence (Verrouillés)

#### 1. `phpstan.neon` (Level 8 + Extension WordPress)

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 8
    phpVersion: 80400 # Force l'analyse pour PHP 8.4

    treatPhpDocTypesAsCertain: false
    checkUninitializedProperties: true
    checkBenevolentUnionTypes: true
    checkExplicitMixedMissingReturn: true
    checkFunctionNameCase: true
    checkInternalClassCaseSensitivity: true
    checkMissingCallableSignature: true
    checkTooWideReturnTypesInProtectedAndPublicMethods: true
    reportUnmatchedIgnoredErrors: true

    paths:
        - [SLUG].php
        - uninstall.php
        - includes/

    excludePaths:
        - includes/lib/* (?)

    bootstrapFiles:
        - tests/phpstan/bootstrap.php

```

#### 2. `phpcs.xml` (WordPress 7.1 + PHP 8.4 + PSR-4 + `strict_types=1`)

```xml
<?xml version="1.0"?>
<ruleset xmlns:xsi="[http://www.w3.org/2001/XMLSchema-instance](http://www.w3.org/2001/XMLSchema-instance)" name="Modern_WP_PHP84_Standards" xsi:noNamespaceSchemaLocation="vendor/squizlabs/php_codesniffer/phpcs.xsd">
    <description>Standard de code WordPress 7.1+ moderne (PHP 8.4 + PHPStan Level 8 + PSR-4).</description>

    <arg name="colors"/>
    <arg value="sp"/>
    <arg name="extensions" value="php"/>
    <arg name="parallel" value="8"/>
    <arg name="cache" value=".phpcs.cache"/>
    <config name="ignore_warnings_on_exit" value="0"/>

    <config name="minimum_wp_version" value="7.1"/>
    <config name="testVersion" value="8.4-"/>

    <file>[SLUG].php</file>
    <file>uninstall.php</file>
    <file>includes</file>

    <exclude-pattern>/vendor/*</exclude-pattern>
    <exclude-pattern>/node_modules/*</exclude-pattern>
    <exclude-pattern>/build/*</exclude-pattern>
    <exclude-pattern>/dist/*</exclude-pattern>
    <exclude-pattern>/assets/*</exclude-pattern>
    <exclude-pattern>/tests/*</exclude-pattern>
    <exclude-pattern>/includes/lib/*</exclude-pattern>
    <exclude-pattern>*/index.php</exclude-pattern>

    <rule ref="WordPress-Core">
        <exclude name="WordPress.Files.FileName"/>
        <exclude name="WordPress.PHP.YodaConditions"/>
    </rule>

    <rule ref="WordPress-Extra">
        <exclude name="Universal.Operators.DisallowShortTernary"/>
        <exclude name="Universal.Arrays.DisallowShortArraySyntax"/>
    </rule>

    <rule ref="PHPCompatibilityWP"/>
    <rule ref="Generic.PHP.RequireStrictTypes"/>
    <rule ref="Generic.Arrays.DisallowLongArraySyntax"/>

    <rule ref="WordPress.WP.I18n">
        <properties>
            <property name="text_domain" type="array">
                <element value="[SLUG]"/>
            </property>
        </properties>
    </rule>

    <rule ref="WordPress.PHP.DevelopmentFunctions.error_log_error_log">
        <exclude-pattern>includes/Utils/Logger.php</exclude-pattern>
    </rule>

    <rule ref="WordPress.DB.DirectDatabaseQuery.DirectQuery">
        <exclude-pattern>includes/Repositories/*</exclude-pattern>
    </rule>

    <rule ref="WordPress.Security.NonceVerification">
        <exclude-pattern>includes/Utils/Request.php</exclude-pattern>
    </rule>
    <rule ref="WordPress.Security.ValidatedSanitizedInput">
        <exclude-pattern>includes/Utils/Request.php</exclude-pattern>
    </rule>

    <rule ref="PHPCompatibility.Variables.ForbiddenThisUseContexts">
        <exclude-pattern>includes/Enums/*</exclude-pattern>
    </rule>
</ruleset>

```

#### 3. `eslint.config.js` (ESLint 9 Flat Config + TypeScript Strict + WordPress)

```javascript
const js = require( '@eslint/js' );
const tseslint = require( 'typescript-eslint' );
const globals = require( 'globals' );

module.exports = tseslint.config(
	{
		ignores: [
			'node_modules/**',
			'vendor/**',
			'build/**',
			'dist/**',
			'assets/js/**',
			'assets/css/**',
			'scripts/**',
			'jules-scratch/**',
		],
	},
	js.configs.recommended,
	...tseslint.configs.strict,
	...tseslint.configs.stylistic,
	{
		files: [ 'src/**/*.{ts,tsx,js}' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'module',
			globals: {
				...globals.browser,
				wp: 'readonly',
				ajaxurl: 'readonly',
			},
		},
		rules: {
			'eqeqeq': [ 'error', 'smart' ],
			'curly': [ 'error', 'all' ],
			'prefer-const': 'error',
			'no-var': 'error',
			'no-shadow': 'off',
			'@typescript-eslint/no-shadow': 'error',
			'@typescript-eslint/no-explicit-any': 'error',
			'@typescript-eslint/ban-ts-comment': [
				'error',
				{
					'ts-expect-error': 'allow-with-description',
					'ts-ignore': true,
					'ts-nocheck': true,
				},
			],
			'@typescript-eslint/consistent-type-imports': [
				'error',
				{ prefer: 'type-imports' },
			],
			'@typescript-eslint/no-unused-vars': [
				'error',
				{
					argsIgnorePattern: '^_',
					varsIgnorePattern: '^_',
					caughtErrorsIgnorePattern: '^_',
				},
			],
			'no-console': [ 'warn', { allow: [ 'warn', 'error' ] } ],
			'camelcase': 'off',
			'no-alert': 'off',
		},
	}
);

```

### 7.3. Synchronisation des Versions, Packaging (`.distignore`) & Documentation

* **Synchronisation des Versions (`scripts/version-sync.cjs`)** :
Le numéro de version doit être strictement identique dans : l'en-tête de `[SLUG].php`, la constante `[SLUG_MAJ]_VERSION`, `package.json`, `block.json` (tous les blocs), `README.md` et `CHANGELOG.md`. Utiliser `npm run version-sync <x.y.z>`.
* **Pipeline de Build & Packaging (`scripts/package.cjs` & `.distignore`)** :
* Les scripts de bundling (`scripts/build-js.js`, `scripts/build-css.js`) compilent les points d'entrée en parallèle asynchrone (`Promise.all` avec `esbuild` et `sass` + `rtlcss`).
* La commande `npm run package` DOIT valider la suite QA complète (`typecheck`, `lint`, `test`, `phpstan`, `phpcs`) avant de compiler en production et de générer l'archive ZIP.
* Le fichier `.distignore` exclut systématiquement : `.git`, `.gitignore`, `.editorconfig`, `eslint.config.js`, `phpstan.neon`, `phpcs.xml`, `.phpcs.cache`, `phpunit.xml.dist`, `.phpunit.cache`, `tsconfig.json`, `package.json`, `package-lock.json`, `composer.json`, `composer.lock`, `node_modules`, `src`, `scripts`, `tests` (et `vendor` uniquement si `[VENDOR_PROD] == NON`).


* **Triptyque Documentaire Obligatoire (en Français)** :
Aucune fonctionnalité n'est considérée comme "Finie" sans la mise à jour de :
1. `README.md` : Présentation, prérequis (WP 7.1, PHP 8.4), installation, architecture et commandes QA.
2. `CHANGELOG.md` : Historique au format *Keep a Changelog* (`Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`) et adhère à la spécification [Semantic Versioning].
3. `USING.md` : Guide utilisateur final et webmaster (liste exhaustive des shortcodes et attributs, blocs Gutenberg, réglages du Back-office et tutoriels métier).



---

## 8. MODÈLES D'IMPLÉMENTATION DE RÉFÉRENCE (PHP 8.4 STRICT)

### 8.1. Point d'entrée & Autoloader SPL (`[SLUG].php`)

```php
<?php
declare(strict_types=1);

// 1. Chargement conditionnel de Composer (si librairies tierces embarquées en prod)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

// 2. Autoloader SPL natif PSR-4 pour le namespace du plugin
spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = '[NAMESPACE]';
		$base_dir = plugin_dir_path( __FILE__ ) . 'includes/';

		$len = strlen( $prefix );
		if ( strncmp( $prefix, $class, $len ) !== 0 ) {
			return;
		}

		$relative_class = substr( $class, $len );
		$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require $file;
		}
	}
);

```

### 8.2. Enum Typé & DTO Readonly (`includes/Enums/` & `includes/DTO/`)

```php
<?php
declare(strict_types=1);

namespace DAME\Enums;

enum Member_Status: string {
	case ACTIVE   = 'active';
	case PENDING  = 'pending';
	case ARCHIVED = 'archived';

	public function label(): string {
		return match ( $this ) {
			self::ACTIVE   => 'Actif',
			self::PENDING  => 'En attente',
			self::ARCHIVED => 'Archivé',
		};
	}
}

```

```php
<?php
declare(strict_types=1);

namespace DAME\DTO;

use DAME\Enums\Member_Status;

readonly class Member_Profile {
	public function __construct(
		public int $id,
		public string $first_name,
		public string $last_name,
		public ?string $email = null,
		public Member_Status $status = Member_Status::ACTIVE,
	) {}

	/**
	 * @param object{ID: int|string, first_name: string, last_name: string, email?: string|null, status?: string} $row Ligne SQL.
	 */
	public static function from_db_row( object $row ): self {
		return new self(
			id: (int) $row->ID,
			first_name: $row->first_name,
			last_name: $row->last_name,
			email: ! empty( $row->email ) ? $row->email : null,
			status: Member_Status::tryFrom( $row->status ?? '' ) ?? Member_Status::ACTIVE,
		);
	}
}

```

### 8.3. Pattern "Manager & Components" (`includes/Services/Export/Manager.php`)

```php
<?php
declare(strict_types=1);

namespace DAME\Services\Export;

use DAME\Services\Export\Formats\CSV;
use DAME\Services\Export\Formats\PDF;

final class Manager {
	/**
	 * @param string               $format Format cible ('csv' | 'pdf').
	 * @param array<int, object>   $data   Données typées à exporter.
	 */
	public function export( string $format, array $data ): ?string {
		$exporter = match ( $format ) {
			'csv'   => new CSV(),
			'pdf'   => new PDF(),
			default => null,
		};

		return $exporter ? $exporter->generate( $data ) : null;
	}
}

```

