# Changelog — EG Mail

Toutes les modifications notables apportées au projet **EG Mail** seront consignées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/), et ce projet adhère à [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Added
- Initialisation du projet et de l'environnement de développement sous Git.
- Configuration stricte PHP 8.4 et WordPress 7.1+ (`phpcs.xml`, `phpstan.neon`, `eslint.config.js`).
- Définition du fichier d'exclusion pour le packaging (`.distignore`).
- Point d'entrée principal `eg-mail.php` avec autoloader natif SPL PSR-4 pour le namespace `EGMail\`.
- Script de désinstallation complet et sécurisé `uninstall.php`.
- Documentation initiale du projet (`README.md`, `CHANGELOG.md`, `USING.md`).
