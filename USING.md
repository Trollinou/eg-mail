# Guide d'Utilisation — EG Mail

Ce document décrit le fonctionnement et la configuration de l'extension **EG Mail** pour les administrateurs et développeurs WordPress.

---

## 1. Accès au Back-Office

Les interfaces d'EG Mail sont accessibles dans l'administration WordPress via le menu **Outils** :
- **Outils > EG Mail — Campagnes** : Suivi des envois groupés, barres de progression et arrêt d'urgence.
- **Outils > EG Mail — File d'attente** : Exploration détaillée de la file FIFO message par message.
- **Outils > EG Mail — Réglages** : Configuration des quotas, paramètres SMTP et fenêtre nocturne.

---

## 2. Configuration des Quotas & Régulation

### Quota par Minute
- **Quota Journée (défaut : 10 mails/min)** : Limite appliquée à chaque cycle du Worker (toutes les minutes) en journée.
- **Quota Nuit (défaut : 25 mails/min)** : Limite autorisée pendant la plage nocturne pour accélérer l'expédition des campagnes lourdes.

### Quota Horaire Glissant (Optionnel)
- Nombre maximal d'e-mails autorisés sur les 60 dernières minutes glissantes (ex: `200`).
- Si la valeur est laissée à `0` ou vide, seul le quota par minute est actif.

### Fenêtre Nocturne
- Heure de début (ex: `22:00`) et heure de fin (ex: `06:00`).
- Les messages de priorité 20 (Basse / Masse) sont automatiquement programmés pour débuter à `22:00`.
- Si le quota horaire n'a pas permis d'écouler tout le lot avant 06h00, l'envoi est mis en pause de sécurité et reprendra automatiquement à 22h00 la nuit suivante.

---

## 3. Gestion et Annulation d'un Lot en Direct

Dans l'onglet **Campagnes & Lots** :
1. **Mettre en Pause (`Pause`)** : Les messages en attente passent au statut `paused`. Le Worker les ignore temporairement.
2. **Reprendre (`Reprendre`)** : Les messages reviennent au statut `pending` et rejoignent la file active.
3. **Annuler et Purger (`Annuler le lot`)** : Les messages en attente sont supprimés immédiatement. Si le Worker était en train d'expédier ce lot, son coupe-circuit intra-boucle stoppe immédiatement l'envoi à la seconde près.
4. **Forcer l'envoi diurne** : Passe la priorité du lot de 20 (Nuit) à 10 (Normale) et déclenche l'envoi dès le prochain cycle du Worker en journée.

---

## 4. Intégration Développeur (Headers Personnalisés)

Les développeurs peuvent piloter le comportement de la file d'attente en ajoutant des en-têtes lors de l'appel à `wp_mail()` :

```php
$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'X-Queue-Priority: 1',                     // 1 = Critique (coupe-file), 10 = Normale, 20 = Nuit, 0 = Bypass synchrone
    'X-Queue-Batch: convocation-ag-2026',      // Identifiant explicite de lot pour l'administration
];

wp_mail( $recipients, $subject, $message, $headers, $attachments );
```

