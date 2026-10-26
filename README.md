# EG Mail — Régulation SMTP & Gestion de Lots pour WordPress

[![PHP](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-7.1%2B-blue.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPL%20v2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**EG Mail** est une extension WordPress conçue pour agir comme un middleware d'interception transparent de la fonction native `wp_mail()`. Elle assure la régulation de flux SMTP multi-niveaux, l'ordonnancement par priorités (avec routage nocturne) et offre une interface d'administration par lots permettant d'interrompre ou d'annuler instantanément des campagnes en cours de diffusion.

---

## Fonctionnalités Principales

1. **Interception Transparente (`wp_mail`)** :
   - Fonctionne sans modification du code des plugins tiers appelants.
   - Capture de l'ensemble du contexte WordPress (`content_type`, `charset`, `from_email`, `from_name`).
   - Mode bypass immédiat pour les messages critiques (2FA, réinitialisation de mot de passe).

2. **Régulation de Flux Multi-Niveaux (Rate Limiting)** :
   - Quota par minute différencié Jour / Nuit.
   - Quota glissant optionnel par heure (sur les 60 dernières minutes).
   - Marge de sécurité dédiée aux e-mails critiques.

3. **Ordonnancement & Plage Nocturne** :
   - Gestion de 4 priorités (`Bypass`, `Critique`, `Normale`, `Basse/Nuit`).
   - Report automatique des envois de masse sur la fenêtre nocturne configurée (ex: 22h00 – 06h00).
   - Coupe-circuit de débordement au lever du jour si les quotas horaires bloquent la file.

4. **Gestion de Campagnes & Annulation en Direct (`batch_id`)** :
   - Regroupement automatique des envois massifs par campagne.
   - Boutons d'action immédiate : Pause, Reprise, Reprogrammation (date/heure et priorité), Annuler et purger.
   - Coupe-circuit intra-boucle dans le Worker pour stopper net un lot à la seconde près.

5. **Déduplication des Pièces Jointes & Résilience (PRA)** :
   - Spool sécurisé avec déduplication par empreinte MD5.
   - Snapshot miroir JSON sur disque (`queue-snapshot.json`) pour restauration automatique.
   - Déclaration native auprès de l'extension **EG Backup & Restore**.

---

## Prérequis Techniques

- **PHP** : `8.4` minimum (`declare(strict_types=1);` requis).
- **WordPress** : `7.1` minimum.
- **Node.js** : `24 LTS` (uniquement pour l'outillage de build et les tests frontend).
- **Composer** : `2.x` (outillage d'assurance qualité en développement).

---

## Commandes de Développement & Assurance Qualité (QA)

Toute modification doit être validée sans aucune erreur ni avertissement :

```bash
# 1. Analyse statique stricte (PHPStan Level 8)
composer phpstan

# 2. Correction automatique du style de code
composer phpcbf

# 3. Validation des standards de code WordPress (WPCS)
composer phpcs

# 4. Vérification du typage TypeScript
npm run typecheck

# 5. Linting JavaScript / TypeScript (ESLint 9)
npm run lint

# 6. Tests unitaires (PHPUnit 11)
composer test

# 7. Compilation des assets frontend
npm run build

# 8. Synchronisation et montée de version SemVer (ex: 1.1.0)
npm run version:sync -- 1.1.0

# 9. Création de l'archive ZIP autonome de production (avec validation QA intégrée)
npm run package
```

---

## Documentation

- **[USING.md](USING.md)** : Guide utilisateur pour les administrateurs (interface back-office, réglages de quotas, plage nocturne, suivi des campagnes et plan de reprise d'activité).
- **[DEVELOPER.md](DEVELOPER.md)** : Guide complet pour les développeurs (headers `X-Queue-*`, niveaux de priorité, bypass immédiat, capture de contexte et exemples de code).
- **[CHANGELOG.md](CHANGELOG.md)** : Historique des versions et évolutions.

---

## Licence

Ce projet est distribué sous licence GNU General Public License v2 ou supérieure.

