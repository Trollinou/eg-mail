# Changelog — EG Mail

Toutes les modifications notables apportées au projet **EG Mail** seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Added
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

