# Changelog — EG Mail

Toutes les modifications notables apportées au projet **EG Mail** seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Security
- Correction d'une faille XSS stockée / DOM-based (`src/ts/admin-batch.ts`, `includes/API/Admin_Rest_Controller.php`) lors de l'affichage des noms de pièces jointes dans la modale de prévisualisation : remplacement de `innerHTML` par la création programmatique DOM (`document.createElement`, `textContent`) et assainissement serveur via `sanitize_file_name()`.
- Suppression de l'exposition du mot de passe SMTP en clair dans le DOM (`includes/Admin/Settings_Page.php`) : masquage de la valeur de l'attribut `value`, affichage d'un placeholder masqué `••••••••` et préservation du mot de passe existant en cas de soumission à vide.
- Renforcement du cloisonnement de la sandbox de l'iframe de prévisualisation des e-mails (`includes/Admin/Batch_List_Table.php`) : suppression de `allow-same-origin` pour maintenir le rendu d'e-mails non fiables dans une origine opaque `null`.
- Déclaration formelle et validation typée des paramètres d'URL REST (`batch_id`) sur l'ensemble des routes d'administration (`includes/API/Admin_Rest_Controller.php`) avec `validate_callback` et `sanitize_callback` (`sanitize_key`), et validation de priorité via l'énumération typée `Queue_Priority`.
- Contrôle dynamique et recréation automatique des fichiers de protection du spool (`.htaccess` et `index.php`) lors de l'accès au répertoire (`includes/Queue/Attachment_Spooler.php`).
- Durcissement contre l'exécution de code arbitraire (RCE) dans le dossier de spool (`includes/Queue/Attachment_Spooler.php`, `includes/Core/Activator.php`) : neutralisation des extensions de scripts exécutables (`.bin`) et injection de directives Apache de désactivation de l'interpréteur PHP (`php_flag engine off`).
- Support complet du nettoyage Multisite lors de la désinstallation du plugin (`uninstall.php`) avec itération sur l'ensemble des blogs du réseau (`get_sites()`).

### Fixed
- Correction de l'arrondi supérieur (`ceil`) dans le calcul de réalignement de l'intervalle minimal de WP-Cron (`Worker::ensure_minimum_cron_interval`) garantissant le respect strict des 55 secondes de refroidissement minimales sur la seconde `:00`.

## [1.0.4] - 2026-10-29

### Changed
- Suppression de la barre d'onglets de navigation horizontale (`.nav-tab-wrapper`) sur les écrans d'administration au profit d'une navigation directe par les 3 sous-pages du menu WordPress dédié **EG Mail** (`Admin_Menu::render_batches_page`, `Admin_Menu::render_queue_page`, `Admin_Menu::render_settings_page`).
- Mise à jour de la documentation d'utilisation (`USING.md`).

## [1.0.3] - 2026-10-26

### Fixed
- Régulation stricte du quota par minute glissante (`Queue_Repository::count_sent_in_last_minute`, `Settings::calculate_effective_quota`, `Worker::run`) : déduction des e-mails expédiés au cours des 55 dernières secondes pour empêcher tout envoi de lots rapprochés à moins d'une minute d'intervalle.
- Neutralisation de l'effet de rattrapage en rafale de `WP-Cron` et synchronisation sur la seconde `:00` (`Worker::ensure_minimum_cron_interval`, `Plugin::init`, `Activator::schedule_crons`) pour garantir un alignement exact avec une Crontab système exécutée toutes les minutes (`*/1 * * * *`).

## [1.0.2] - 2026-10-26

### Added
- Support de l'envoi d'e-mail différé dès l'appel à `wp_mail()` via le nouvel en-tête personnalisé `X-Queue-Scheduled-At` (`Interceptor::parse_custom_headers`, `Interceptor::parse_scheduled_at_header`), acceptant les dates explicites (`Y-m-d H:i:s` / ISO 8601), les expressions relatives (`+2 hours`, `tomorrow 09:00`) et les timestamps Unix dans le fuseau horaire WordPress (`wp_timezone()`).
- Documentation complète de l'en-tête `X-Queue-Scheduled-At`, des formats supportés, de l'interaction avec la priorité nocturne et des exemples d'intégration dans `DEVELOPER.md` et `USING.md`.
- Reprogrammation complète des campagnes en attente ou en pause (`Batch_List_Table`, `Admin_Rest_Controller`, `Queue_Repository`) : modale dédiée permettant de modifier la priorité (`Critique`, `Normale`, `Basse / Nuit`) et la date/heure d'envoi (`scheduled_at`) avec raccourcis rapides (*Maintenant*, *Ce soir*, *+24h*).
- Route REST API `POST /eg-mail/v1/batches/{batch_id}/reschedule` avec validation déclarative et réactivation automatique des lots en pause vers le statut `pending`.

### Changed
- Remplacement du bouton d'action unitaire « Forcer le départ » par le bouton universel « Reprogrammer » sur toutes les campagnes non terminées dans la vue **Campagnes & Lots**.
- Affichage systématique de la date de départ prévue (`scheduled_at`) pour toutes les campagnes non terminées dans la colonne « Priorité & Programmation ».

## [1.0.1] - 2026-10-26

### Changed
- Déplacement des interfaces d'administration vers un menu de premier niveau dédié **EG Mail** avec icône Dashicons `dashicons-email-alt2` (remplace l'emplacement sous le menu Outils).
- Harmonisation des scripts de synchronisation de version SemVer (`scripts/version-sync.cjs`) et de packaging autonome (`scripts/package.cjs`).

## [1.0.0] - 2026-10-25

### Added
- Tâche récurrente quotidienne `Cleaner` (`includes/Queue/Cleaner.php`) hookée sur `eg_mail_cron_cleaner` pour la purge automatique des e-mails envoyés selon la durée de rétention (15 jours par défaut).
- Nettoyage automatique des pièces jointes orphelines du disque (`spool`) et resynchronisation atomique du snapshot PRA (`queue-snapshot.json`) lors de chaque cycle de rétention.
- Méthode de purge en base de données `Queue_Repository::purge_sent( int $retention_days )`.
- Script de packaging autonome (`scripts/package.cjs`) générant l'archive ZIP prête pour la production avec le préfixe racine `eg-mail/`.
- Suite complète de tests unitaires PHPUnit pour le nettoyeur `Cleaner` (`tests/Unit/CleanerTest.php`).
- Menu et sous-menus d'administration WordPress sous **Outils** (`tools.php`) : Campagnes / Lots, File d'attente FIFO et Réglages avancés.
- Contrôleur REST API `Admin_Rest_Controller` sécurisé (`/wp-json/eg-mail/v1/`) avec validation déclarative, permissions administrateur et nonce : mise en pause, reprise, annulation, forçage d'envoi immédiat diurne (`force-day`), prévisualisation d'e-mail avec assainissement HTML (`wp_kses_post`), purge de messages en attente et réconciliation de snapshot PRA.
- Interface des Campagnes et Lots `Batch_List_Table` avec barres de progression dynamiques, statuts visuels et modale de prévisualisation accessible (fermeture touche Échap / clic backdrop).
- Interface de navigation et recherche de la file FIFO `Queue_List_Table` dérivée de `WP_List_Table` avec filtres par statut et recherche plein texte.
- Interface des réglages `Settings_Page` regroupant la configuration SMTP, les quotas de débit, la fenêtre nocturne, la politique de rétention et le bandeau d'alerte PRA en cas de divergence de snapshot.
- Script TypeScript Vanilla atomique `src/ts/admin-batch.ts` compilé vers `assets/js/admin-batch.js` gérant les interactions REST asynchrones.
- Feuilles de style SCSS modernes `src/scss/admin-queue.scss` compilées vers `assets/css/admin-queue.css` et `assets/css/admin-queue-rtl.css` avec support RTL automatique.
- Utilitaire de requêtes typé `Request` (`includes/Utils/Request.php`) pour l'assainissement centralisé des superglobales.
- Tests unitaires PHPUnit 11 complets pour le contrôleur REST d'administration (`tests/Unit/AdminRestControllerTest.php`).
- Gestionnaire de réglages et options `Settings` avec quotas différenciés (minute jour/nuit, quota horaire glissant), fenêtre nocturne et calcul de backoff exponentiel.
- Configurateur SMTP `Smtp_Configurator` hooké sur `phpmailer_init` avec persistance `SMTPKeepAlive` lors des cycles d'envoi par lots.
- Worker WP-Cron d'arrière-plan `Worker` exécuté chaque minute avec Mutex anti-concurrence (`eg_mail_worker_lock`), calcul du quota effectif, coupe-circuit temps réel intra-boucle (`eg_mail_aborted_batches`), écoute fine de `wp_mail_failed` et backoff exponentiel (+5min, +20min, +60min).
- Tests unitaires PHPUnit 11 validant le Mutex, la double régulation de quota, le coupe-circuit et la gestion des échecs d'envoi.
- Intercepteur transparent `Interceptor` sur le filtre `pre_wp_mail` : gestion du bypass immédiat, capture dynamique du contexte (`wp_mail_content_type`, `wp_mail_charset`, `wp_mail_from`, `wp_mail_from_name`), éclatement des destinataires multiples et regroupement automatique par lot `batch_id`.
- Moteur d'ordonnancement nocturne calculant dynamiquement `scheduled_at` pour les priorités basses (>= 20) selon la fenêtre nocturne.
- Intégration de sauvegarde et résilience `Backup_Integration` : enregistrement du spool auprès d'EG Backup & Restore via `eg_backup_registered_locations`, écriture atomique du snapshot `queue-snapshot.json` et réconciliation automatique des messages orphelins en pause.
- Orchestrateur central `Plugin` singleton initialisant les services et déclarant l'intervalle WP-Cron d'une minute `eg_mail_every_minute`.
- Tests unitaires PHPUnit couvrant l'interception, le bypass, le parsing d'en-têtes et le snapshot miroir.
- Modèle de données SQL pour la table `{$wpdb->prefix}eg_mail_queue` avec index optimisés pour le dépilement, le quota horaire et l'anti-doublon.
- Énumérations PHP 8.4 typées `Queue_Status` et `Queue_Priority`.
- DTOs immuables `readonly class` : `Queue_Item`, `Context_Meta`, et `Batch_Summary`.
- Gestionnaire d'activation et de migration `Activator` (création table `dbDelta`, dossier de spool et crons).
- Gestionnaire de spool `Attachment_Spooler` avec déduplication par hash MD5, protection d'accès (`.htaccess` / `index.php`) et Garbage Collector de fichiers orphelins.
- Couche d'accès aux données `Queue_Repository` isolée avec Prepared SQL, requêtes de lots, quotas et invalidation du cache objet WordPress (`wp_cache_*`).
- Suite de tests unitaires PHPUnit 11 couvrant les Enums, DTOs et le Spooler.
- Initialisation du projet et de l'environnement de développement sous Git.
- Configuration stricte PHP 8.4 et WordPress 7.1+ (`phpcs.xml`, `phpstan.neon`, `eslint.config.js`).
- Définition du fichier d'exclusion pour le packaging (`.distignore`).
- Point d'entrée principal `eg-mail.php` avec autoloader natif SPL PSR-4 pour le namespace `EGMail\`.
- Script de désinstallation complet et sécurisé `uninstall.php`.
- Documentation initiale du projet (`README.md`, `CHANGELOG.md`, `USING.md`).

