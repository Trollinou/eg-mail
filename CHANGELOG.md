# Changelog — EG Mail

Toutes les modifications notables apportées au projet **EG Mail** seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Added
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

