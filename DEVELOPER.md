# Guide Développeur — Intégration & Envoi avec `wp_mail()` dans EG Mail

Ce document est destiné aux développeurs de thèmes, d'extensions WordPress et d'applications tierces. Il détaille le fonctionnement interne de l'intercepteur **EG Mail** et répertorie l'ensemble des en-têtes personnalisés (`X-Queue-*`) utilisables pour piloter finement la file d'attente lors de l'appel à la fonction native `wp_mail()`.

---

## 1. Principe de Fonctionnement (Middleware Transparent)

**EG Mail** agit comme un **middleware d'interception** entre vos appels à `wp_mail()` et le serveur d'envoi SMTP :

```
[ Votre Extension / Thème ]
         │
         ▼  wp_mail( $to, $subject, $message, $headers, $attachments )
[ Filtre pre_wp_mail (EG Mail) ]
         │
         ├───► En-tête X-Queue-Bypass ou Priorité 0 ?
         │        └──► OUI : Envoi synchrone immédiat (Bypass direct)
         │
         └──► NON : 1. Extraction et assainissement des destinataires
                    2. Capture du contexte (Content-Type, Charset, From, FromName)
                    3. Spool et déduplication MD5 des pièces jointes
                    4. Éclatement en messages unitaires (table SQL eg_mail_queue)
                    5. Attribution du lot (batch_id) et calcul de programmation (scheduled_at)
                    6. Écriture du snapshot de résilience (queue-snapshot.json)
                    7. Retour immédiat 'true' (déblocage du thread PHP)
```

### Avantages Clés pour le Développeur
- **Zéro refactorisation requise** : Vos appels existants à `wp_mail()` continuent de fonctionner sans modification.
- **Réponse instantanée** : L'utilisateur n'attend plus la réponse du serveur SMTP ; la requête web se termine immédiatement.
- **Éclatement automatique** : Si vous transmettez 150 destinataires dans le paramètre `$to`, EG Mail crée 150 entrées individuelles avec le même identifiant de lot `batch_id`.
- **Déduplication des pièces jointes** : Même si 500 membres reçoivent une pièce jointe de 5 Mo, le fichier n'est stocké qu'une seule fois dans le spool (`uploads/eg-mail-spool/`) grâce à son empreinte MD5.
- **Nettoyage automatique** : Les en-têtes `X-Queue-*` sont purgés avant l'envoi SMTP final ; ils ne sont jamais visibles par le destinataire de l'e-mail.

---

## 2. En-têtes Personnalisés (`X-Queue-*`)

Pour contrôler la priorité, le regroupement ou forcer un envoi immédiat, ajoutez simplement ces en-têtes au paramètre `$headers` de `wp_mail()`.

| En-tête | Valeurs Acceptées | Valeur par Défaut | Description & Cas d'Usage |
| :--- | :--- | :--- | :--- |
| **`X-Queue-Priority`** | `0`, `1`, `10`, `20` | `10` (Normale) | Définit le niveau d'urgence et la planification de l'e-mail. |
| **`X-Queue-Batch`** | Chaîne alphanumérique (slug) | Hash auto `md5(subject + minute)` | Identifiant de campagne/lot affiché dans l'administration pour le suivi, la mise en pause ou l'annulation groupée. |
| **`X-Queue-Bypass`** | `true`, `1` | `false` | Court-circuite la file d'attente : envoi synchrone immédiat via PHPMailer sans passer par la base de données. Équivalent à `X-Queue-Priority: 0`. |

---

## 3. Détail des Niveaux de Priorité (`X-Queue-Priority`)

Les priorités sont indexées sur l'énumération PHP `EGMail\Enums\Queue_Priority` :

### `0` — Bypass Immédiat (`Queue_Priority::BYPASS`)
- **Comportement** : L'e-mail ne rentre pas dans la file SQL. Il est expédié immédiatement de façon synchrone lors de l'exécution de la page.
- **Cas d'usage** :
  - Codes d'authentification à deux facteurs (2FA / OTP).
  - Liens urgents de réinitialisation de mot de passe.
  - Alertes système critiques en temps réel.
- **Syntaxe alternative** : `X-Queue-Bypass: true`.

### `1` — Haute Priorité / Coupe-File (`Queue_Priority::HIGH`)
- **Comportement** : Inséré en file d'attente avec `scheduled_at = NOW()`. Lors du prochain passage du Worker (toutes les minutes), ces messages sont dépilés **en tête absolue**, avant tous les e-mails normaux.
- **Cas d'usage** :
  - Confirmation de paiement ou commande boutique.
  - Notification unitaire importante destinée à un administrateur ou utilisateur.

### `10` — Priorité Normale (`Queue_Priority::NORMAL`)
- **Comportement** : Priorité standard par défaut. Dépilé chronologiquement (FIFO) selon les quotas diurnes et horaires configurés.
- **Cas d'usage** :
  - Notifications générales, alertes automatiques standards, e-mails transactionnels courants.

### `20` — Priorité Basse / Envoi Nocturne (`Queue_Priority::LOW`)
- **Comportement** : Programmé automatiquement pour la prochaine fenêtre nocturne configurée (par défaut : **22h00 à 06h00**).
  - Si l'envoi est déclenché en journée (ex: 14h30), `scheduled_at` est fixé à `22:00:00` le soir même.
  - Si l'envoi a lieu pendant la nuit, il est éligible immédiatement et bénéficie du quota nocturne accéléré.
  - Si la nuit se termine avant que le lot soit entièrement expédié, le coupe-circuit diurne interrompt l'envoi jusqu'à la nuit suivante.
- **Cas d'usage** :
  - Convocations à une Assemblée Générale.
  - Relances de cotisations ou d'adhésions.
  - Newsletters internes et mailings de masse.

---

## 4. Exemples Pratiques de Code

### Exemple 1 : Envoi Critique Immédiat (Bypass 2FA)

Pour un code de connexion ou une réinitialisation de mot de passe, forcez le bypass pour garantir la réception instantanée :

```php
$to      = 'adherent@example.com';
$subject = 'Votre code de vérification';
$message = '<p>Votre code temporaire est : <strong>482910</strong></p>';

$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'X-Queue-Bypass: true', // ou 'X-Queue-Priority: 0'
];

wp_mail( $to, $subject, $message, $headers );
```

---

### Exemple 2 : Campagne de Masse Programmée la Nuit avec Lot Nommé

Pour envoyer des convocations à 300 membres avec une pièce jointe PDF sans saturer le serveur SMTP en journée :

```php
$recipients = [
    'membre1@example.com',
    'membre2@example.com',
    // ... jusqu'à 300 adresses
];

$subject = 'Convocation Assemblée Générale 2026';
$message = '<h1>Convocation AG</h1><p>Veuillez trouver l\'ordre du jour en pièce jointe.</p>';

$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'X-Queue-Priority: 20',                  // Priorité 20 = report automatique à 22h00
    'X-Queue-Batch: ag-2026-convocations',  // Identifiant lisible dans le back-office EG Mail
];

$attachments = [
    WP_CONTENT_DIR . '/uploads/documents/ordre-du-jour-ag-2026.pdf',
];

// wp_mail retourne 'true' en quelques millisecondes !
// Les 300 messages sont enregistrés sous le lot 'ag-2026-convocations'.
// Le PDF est stocké une seule fois dans le spool.
$success = wp_mail( $recipients, $subject, $message, $headers, $attachments );
```

---

### Exemple 3 : Notification Transactionnelle Urgente (Coupe-File)

Pour un e-mail qui doit passer avant les envois de masse en attente :

```php
$headers = [
    'Content-Type: text/html; charset=UTF-8',
    'X-Queue-Priority: 1', // Dépilé dès la minute suivante
    'X-Queue-Batch: transaction-recu',
];

wp_mail( 'tresorier@example.com', 'Nouvelle adhésion validée', $body, $headers );
```

---

### Exemple 4 : Formatage sous Forme de Chaîne de Caractères

EG Mail supporte indifféremment les en-têtes fournis sous forme de tableau PHP ou de chaîne de caractères multiligne :

```php
$headers  = "Content-Type: text/html; charset=UTF-8\r\n";
$headers .= "From: Club d'Échecs <contact@example.com>\r\n";
$headers .= "X-Queue-Priority: 10\r\n";
$headers .= "X-Queue-Batch: relances-avril-2026\r\n";

wp_mail( $to, $subject, $message, $headers );
```

---

## 5. Capture de Contexte & Filtres WordPress Supportés

EG Mail écoute fidèlement les filtres natifs WordPress au moment précis où `wp_mail()` est appelé, et restaure ce contexte lors de l'expédition finale par le Worker :

```php
// Ces filtres sont automatiquement capturés dans les métadonnées de l'e-mail :
add_filter( 'wp_mail_content_type', fn() => 'text/html' );
add_filter( 'wp_mail_charset', fn() => 'UTF-8' );
add_filter( 'wp_mail_from', fn() => 'notifications@mon-club.fr' );
add_filter( 'wp_mail_from_name', fn() => 'Mon Club Sportif' );
```

---

## 6. Surveillance & Bonnes Pratiques

1. **Nommez vos lots (`X-Queue-Batch`)** :
   Donnez des noms explicites (ex: `cotisations-2026`, `tournoi-ronde-3`). Cela permet aux administrateurs de retrouver la campagne en un coup d'œil dans **EG Mail > Campagnes & Lots**, d'observer la barre de progression en temps réel, ou de mettre en pause / annuler le lot en cas d'erreur de contenu.
2. **Ne dupliquez pas les appels unitaires dans une boucle PHP** :
   Préférez transmettre la liste complète des adresses e-mails dans le paramètre `$to` de `wp_mail()`. EG Mail se charge d'éclater les destinataires de manière atomique en base de données, évitant la surcharge de mémoire PHP.
3. **Gestion des Pièces Jointes** :
   Passez les chemins absolus locaux vers les fichiers (ex: `/var/www/html/wp-content/uploads/...`). EG Mail déduplique automatiquement les fichiers identiques via hash MD5 dans le spool sécurisé.

