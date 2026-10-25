# **Document de Conception Technique (TDD) — Plugin WordPress `eg-mail`**

# **Spécifications d'Architecture & Implémentation**

**Nom du plugin :** `eg-mail` (identifiant technique : `eg-mail`, préfixe PHP/SQL : `eg_mail_`, namespace : `EGMail`)  
**Version du document :** 1.2  
**Objectif :** Interception transparente de wp\_mail(), régulation de flux SMTP multi-niveaux (par minute et par heure optionnelle), gestion des priorités avec routage nocturne, et administration visuelle par lots permettant d'annuler ou de purger une campagne en attente ou en cours d'envoi.

# **1\. Vue d'ensemble et Architecture**

Le plugin `eg-mail` agit comme un **Middleware (Intercepteur)** entre WordPress/les plugins métiers (comme le plugin de gestion d'adhérents) et le serveur SMTP. Aucun code appelant wp\_mail() n'a besoin d'être modifié pour bénéficier de la file d'attente.

## **Flux de traitement (Workflow)**

1. **Appel standard :** Un plugin ou WordPress appelle `wp_mail($to, $subject, $message, $headers, $attachments)`.  
2. **Capture du contexte :** `eg-mail` capture l'état des filtres dynamiques éventuels (`wp_mail_content_type`, `wp_mail_from`, `wp_mail_from_name`) et lit les en-têtes personnalisés (`X-Queue-Priority`, `X-Queue-Batch`, `X-Queue-Bypass`).  
3. **Regroupement par lot (`batch_id`) :** Pour permettre l'annulation groupée d'un envoi de masse (ex: 150 mails aux adhérents), chaque message reçoit un identifiant de lot (`batch_id`). S'il n'est pas fourni explicitement via un header, il est généré automatiquement à partir d'une empreinte du sujet et du corps du message (`md5($subject . '|' . $message)` ou par requête HTTP courante). Ainsi, 150 appels successifs à `wp_mail()` dans une boucle `foreach` partageront automatiquement le même `batch_id`.  
4. **Interception (`pre_wp_mail`) :**  
   * Si le mode **Bypass** est détecté (ou si c'est le Worker lui-même qui exécute l'envoi), l'exécution continue vers `PHPMailer`.  
   * Sinon, les destinataires multiples sont éclatés (1 entrée \= 1 destinataire), les pièces jointes temporaires sont copiées dans le spool sécurisé, et le message est inséré en base de données avec calcul de sa date d'éligibilité (`scheduled_at`). `pre_wp_mail` retourne `true`.  
5. **Ordonnancement & Double Régulation (Worker Cron) :** Toutes les minutes, le Worker calcule le **quota disponible** en croisant la limite par minute et la limite par heure (si activée), puis sélectionne les messages éligibles triés par priorité et ancienneté (FIFO).  
6. **Expédition SMTP optimisée :** Le Worker ouvre une connexion SMTP persistante (`SMTPKeepAlive = true`), envoie le lot, vérifie avant chaque envoi que le message n'a pas été annulé entre-temps, capture les erreurs via `wp_mail_failed` pour gérer le *backoff exponentiel*, et nettoie les pièces jointes lorsque tout le lot est terminé.

# **2\. Modèle de Données (Schéma SQL)**

Table : `{$wpdb->prefix}eg_mail_queue`CREATE TABLE {\$wpdb-\>prefix}eg\_mail\_queue (

    id BIGINT UNSIGNED NOT NULL AUTO\_INCREMENT,

    batch\_id CHAR(32) NOT NULL,                    \-- Identifiant de lot/campagne pour annulation groupée

    dedup\_hash CHAR(32) NOT NULL,                  \-- Anti-doublon (destinataire \+ sujet \+ message)

    status VARCHAR(20) NOT NULL DEFAULT 'pending', \-- pending, processing, paused, sent, failed, cancelled

    priority TINYINT UNSIGNED NOT NULL DEFAULT 10, \-- 1: Critique, 5: Haute, 10: Normale, 20: Basse (Nuit)

    recipient VARCHAR(255) NOT NULL,

    subject TEXT NOT NULL,

    message LONGTEXT NOT NULL,

    headers LONGTEXT NULL,

    attachments LONGTEXT NULL,                     \-- JSON array des chemins sécurisés dans le spool

    context\_meta LONGTEXT NULL,                    \-- JSON (content\_type, charset, from\_email, from\_name)

    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,

    max\_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,

    last\_error TEXT NULL,

    created\_at DATETIME NOT NULL,

    scheduled\_at DATETIME NOT NULL,                \-- Gère l'envoi immédiat, le mode nocturne ET le retry backoff

    sent\_at DATETIME NULL,

    PRIMARY KEY (id),

    INDEX idx\_worker\_fetch (status, scheduled\_at, priority, id),

    INDEX idx\_hourly\_quota (status, sent\_at),

    INDEX idx\_batch (batch\_id, status),

    INDEX idx\_dedup (dedup\_hash, status, created\_at)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4\_unicode\_ci;

## **Rôles des colonnes clés :**

* **`batch_id`** : Permet d'identifier tous les e-mails faisant partie d'un même envoi groupé (ex: les 150 convocations ou newsletters générées lors d'une action utilisateur).  
* **`scheduled_at`** : Pilote l'envoi immédiat (`NOW()`), le report sur plage nocturne (ex: `Aujourd'hui à 22:00:00`), et le délai avant nouvelle tentative en cas d'échec SMTP.  
* **`idx_hourly_quota (status, sent_at)`** : Index dédié permettant de compter instantanément (`COUNT(*)`) combien d'e-mails ont été envoyés sur les 60 dernières minutes glissantes pour respecter la limite horaire de l'hébergeur.

# **3\. Régulation de Flux Multi-Niveaux (Minute \+ Heure Optionnelle)**

Pour s'adapter aux contraintes strictes des hébergeurs mutualisés ou dédiés, `eg-mail` propose un double plafond d'envoi :

1. **Quota par minute (Obligatoire) :** Nombre maximum d'e-mails envoyés lors d'une exécution du Worker (toutes les minutes). Peut être différencié Jour / Nuit (ex: `10/min` le jour, `20/min` la nuit).  
2. **Quota par heure glissante (Optionnel \- `0` \= désactivé) :** Nombre maximum d'e-mails autorisés sur les 60 dernières minutes glissantes (ex: `200 mails/heure`).

## **Algorithme de calcul du quota effectif au lancement du Worker**

À chaque exécution (toutes les minutes) :

1. Le Worker détermine le quota minute applicable : `$limit_minute = $is_night ? $quota_min_night : $quota_min_day`.  
2. Si le **quota horaire** (`$limit_hour`) est activé (`> 0`) :  
   * Le Worker exécute :SELECT COUNT(\*) FROM {\$wpdb-\>prefix}eg\_mail\_queue  
       
     WHERE status \= 'sent' AND sent\_at \>= DATE\_SUB(NOW(), INTERVAL 60 MINUTE)  
       
   * Il calcule le reliquat horaire disponible : `$remaining_hour = max(0, $limit_hour - $sent_last_hour)`.  
   * **Marge de sécurité pour urgences (Optionnel) :** Possibilité de réserver 5 % du quota horaire uniquement pour les e-mails de priorité `1` (Critique).  
   * Le nombre de messages à dépiler sur ce cycle est alors :  
     `$effective_limit = min($limit_minute, $remaining_hour)`.  
3. Si `$effective_limit === 0`, le Worker s'arrête immédiatement sans solliciter le serveur SMTP.

# **4\. Gestion des Priorités et Plages Horaires (Mode Nocturne)**

Le système définit 4 niveaux de priorité standardisés, pilotables automatiquement ou via l'en-tête `X-Queue-Priority` :

| Niveau | Code | Usage type | Règle d'ordonnancement (scheduled\_at) |
| :---- | :---- | :---- | :---- |
| **Immédiat (Bypass)** | `0` | Test SMTP admin, urgences absolues | Non mis en file. Envoi synchrone direct. |
| **Critique (Coupe-file)** | `1` | Réinitialisation de mot de passe, 2FA, alerte critique | `scheduled_at = NOW()`. Passe devant toute la file au prochain cycle (max 60s). |
| **Normale (Défaut)** | `10` | Confirmations d'adhésion, messages unitaires | `scheduled_at = NOW()`. Traitement FIFO standard au fil de l'eau. |
| **Basse / Masse (Nocturne)** | `20` | Newsletters, convocations AG, relances cotisations | **Restriction horaire** : expédié uniquement dans la fenêtre nocturne (ex: `22h00 - 06h00`). |

## **Algorithme de la fenêtre nocturne (Priorité \>= 20\)**

Plage configurée dans les réglages du plugin : `night_start = 22:00`, `night_end = 06:00`.

* **À l'insertion (`intercept_wp_mail`) :** Si l'e-mail est de priorité `20` et que l'heure courante est en dehors de la plage nocturne (ex: 14h30), `scheduled_at` est calculé à aujourd'hui `22:00:00`.  
* **Au dépilement (Sécurité de débordement) :** Si un lot nocturne n'a pas fini d'être envoyé à 06h00 du matin (à cause du quota horaire de l'hébergeur), la requête SQL du Worker bloque tous les messages de priorité `>= 20` entre 06h00 et 22h00. Les messages restants reprendront automatiquement la nuit suivante à 22h00.

# **5\. Visualisation, Contrôle et Annulation d'Envois (Gestion de Lots)**

C'est une fonctionnalité critique pour la gestion associative : pouvoir détecter une erreur (faute dans une convocation, mauvaise pièce jointe, oubli) après avoir cliqué sur "Envoyer" et stopper immédiatement l'hémorragie.

## **5.1. Regroupement intelligent par Lot / Campagne (batch\_id)**

Lorsqu'une fonction envoie un message à 150 adhérents (soit via un appel unique avec 150 destinataires, soit via une boucle de 150 appels successifs à wp\_mail() avec le même sujet), eg-mail leur attribue un batch\_id commun :

* Si l'appelant fournit un header `X-Queue-Batch: convocation-ag-2026`, cet identifiant est utilisé.  
* Sinon, `eg-mail` calcule `md5( $subject . '|' . floor(time() / 60) )` ou utilise un identifiant d'exécution PHP statique par sujet, ce qui regroupe nativement les 150 e-mails sous un même lot dans l'interface.

## **5.2. Interface d'Administration (Back-Office `eg-mail`)**

Le menu d'administration propose deux vues complémentaires :

### **Vue 1 : "Vue par Lots / Campagnes" (Idéale pour gérer un envoi de 150 mails)**

Affiche une ligne unique par lot (GROUP BY batch\_id) avec :

* **Sujet du message** et aperçu du contenu (modale de prévisualisation HTML \+ liste des pièces jointes).  
* **Barre de progression en temps réel :** ex. `30 envoyés / 120 en attente / 0 échec (Total : 150)`.  
* **Priorité & Programmation :** ex. `Nocturne (Départ prévu à 22h00)` ou `En cours de diffusion`.  
* **Boutons d'action immédiate sur le lot :**  
  1. **Mettre en pause (`Pause`) :** Passe instantanément les 120 messages `pending` au statut `paused`. Le Worker les ignore. Cela laisse le temps de vérifier le contenu sans rien perdre.  
  2. **Annuler / Supprimer le reste du lot (`Cancel & Purge`) :** Supprime de la file tous les messages du lot qui sont encore au statut `pending` ou `paused` (ex: les 150 mails si la nuit n'a pas commencé, ou les 120 restants si 30 sont déjà partis) et nettoie les pièces jointes associées dans le spool.  
  3. **Forcer l'envoi diurne :** Passe la priorité du lot de `20` (Nuit) à `10` (Normale) et `scheduled_at = NOW()` si l'on décide finalement de ne pas attendre la nuit.

### **Vue 2 : "Journal détaillé de la file (FIFO)" (`WP_List_Table`)**

Permet de voir chaque e-mail individuellement avec filtres par statut (En attente, En pause, Envoyés, Échoués), recherche par adresse e-mail d'adhérent, actions groupées et bouton global **"Vider toute la file d'attente (Purger les messages non envoyés)"**.

## **5.3. Arrêt d'urgence pendant qu'un lot est en cours d'envoi (Coupe-circuit temps réel)**

Que se passe-t-il si l'administrateur clique sur **"Annuler le lot"** à 22h05 alors même que le Worker est en train d'exécuter sa boucle d'envoi de 20 mails ?

* Lorsque l'administrateur clique sur "Annuler le lot", une requête SQL exécute immédiatement :DELETE FROM {\$wpdb-\>prefix}eg\_mail\_queue  
    
  WHERE batch\_id \= %s AND status IN ('pending', 'paused')  
    
  Et enregistre le `batch_id` dans un transient de révocation rapide `eg_mail_aborted_batches`.  
    
* **Vérification intra-boucle dans le Worker :** Au sein même de sa boucle `foreach ($batch_emails as $email)`, juste avant chaque appel SMTP unitaire, le Worker vérifie si le `batch_id` figure dans la liste d'arrêt d'urgence ou si la ligne a été supprimée. Si oui, il interrompt immédiatement la boucle d'envoi à la seconde près.

# **6\. Spécification des Mécanismes Techniques Complémentaires**

## **6.1. Maintien du contexte des filtres WordPress (`context_meta`)**

Capture automatique des filtres `wp_mail_content_type`, `wp_mail_charset`, `wp_mail_from` et `wp_mail_from_name` au moment de l'appel initial à wp\_mail(), stockés en JSON dans context\_meta et restaurés lors de l'envoi réel par le Worker.

## **6.2. Sécurisation du cycle de vie des pièces jointes (Spooler par Hash)**

1. Répertoire `wp-content/uploads/eg-mail-spool/` protégé par `.htaccess` (`Deny from all`) et `index.php`.  
2. À l'interception, chaque fichier attaché est copié sous un nom basé sur son empreinte `md5_file($path) . '_' . basename($path)`. Ainsi, si un mail avec convocation PDF est envoyé à 150 adhérents, **le PDF n'est stocké qu'une seule fois sur le disque** au lieu d'être dupliqué 150 fois.  
3. Lorsqu'un lot est totalement envoyé ou annulé/supprimé, le Garbage Collector supprime le fichier du spool dès qu'aucun message `pending`, `paused` ou `processing` n'y fait plus référence.

## **6.3. Gestion des erreurs, Verrouillage et Retry (Backoff Exponentiel)**

* **Mutex Worker :** Verrouillage via `Transient` / `GET_LOCK` MySQL pour empêcher deux Crons simultanés de dépiler les mêmes messages.  
* **Capture d'erreur fine :** Écoute du hook `wp_mail_failed` pour enregistrer le message exact de `PHPMailer` dans la colonne `last_error`.  
* **Backoff exponentiel :** En cas d'échec SMTP, `attempts` est incrémenté et `scheduled_at` est repoussé (+5 min, \+20 min, \+60 min). Au-delà de `max_attempts`, le statut passe à `failed`.

# **7\. Intégration EG Backup & Restore et Continuité d'Activité (PRA)**

Afin de garantir qu'aucun envoi en attente (ni aucune pièce jointe associée comme les convocations PDF ou attestations) ne soit perdu en cas de plantage serveur ou lors d'une migration, `eg-mail` s'intègre nativement avec l'extension **EG Backup & Restore** via son API de hooks.

## **7.1. Déclaration de l'emplacement via `eg_backup_registered_locations`**

Le répertoire de spool (`wp-content/uploads/eg-mail-spool`) contenant les pièces jointes dédupliquées ainsi que le fichier d'état de secours de la file d'attente est déclaré auprès de **EG Backup & Restore**. Cela permet :

* De l'identifier clairement sous la catégorie « Documents & Données déclarées » avec le badge `[EG Mail]`.  
* De l'exclure automatiquement des sauvegardes génériques de la Médiathèque standard (`uploads/`) pour éviter toute redondance.  
* De pouvoir le cibler individuellement en WP-CLI : `wp eg-backup create --type=media --include-locations=eg_mail_spool`.

### **Implémentation (`includes/Core/Backup_Integration.php`)**

```php
<?php

declare(strict_types=1);

namespace EGMail\Core;

class Backup_Integration {

    public static function init(): void {
        add_filter( 'eg_backup_registered_locations', [ self::class, 'register_spool_location' ] );
    }

    public static function register_spool_location( array $locations ): array {
        $upload_dir = wp_upload_dir();
        $spool_path = $upload_dir['basedir'] . '/eg-mail-spool';

        $locations['eg_mail_spool'] = [
            'id'          => 'eg_mail_spool',
            'label'       => 'File d\'attente E-mails & Pièces jointes en transit (Spool)',
            'plugin_name' => 'EG Mail',
            'path'        => $spool_path,
            'category'    => 'documents',
            'recommended' => true,
            'description' => 'Pièces jointes dédupliquées des e-mails en file d\'attente et snapshot JSON de secours des envois programmés.',
            'exclude'     => [
                'temp',
                '*.tmp',
                '*.lock',
            ],
        ];

        return $locations;
    }
}
```

## **7.2. Persistance Miroir sur Disque (`queue-snapshot.json`) et Restauration Hybride**

Comme indiqué dans la documentation de **EG Backup & Restore**, une restauration de type **Médias & Documents** restaure le dossier `uploads/eg-mail-spool/` sans modifier la base de données SQL. À l'inverse, en cas de crash de la table MySQL, les fichiers du disque sont toujours présents.  
Pour assurer une résilience totale dans les deux scénarios :

1. **Mise à jour d'un Snapshot JSON (`eg-mail-spool/queue-snapshot.json`) :**  
   * À chaque insertion de lot ou changement d'état dans la file d'attente, `eg-mail` maintient un fichier `queue-snapshot.json` dans `wp-content/uploads/eg-mail-spool/` contenant la liste des messages non terminés (`status IN ('pending', 'paused')`) et leurs métadonnées (`batch_id`, `recipient`, `subject`, `message`, `headers`, `attachments`, `context_meta`, `priority`, `scheduled_at`).  
   * Écriture atomique via un fichier temporaire `.tmp` (exclu de la sauvegarde par la règle `'*.tmp'`) puis renommage (`rename()`) pour éviter toute corruption si une sauvegarde se déclenche simultanément.  
2. **Réconciliation automatique après restauration :**  
   * Dans l'interface d'administration d'`eg-mail` (et au démarrage du Worker), si le plugin détecte que `queue-snapshot.json` contient des lots `pending`/`paused` absents de la table SQL `{$wpdb->prefix}eg_mail_queue` (cas d'une restauration disque ou d'une perte de table SQL), une bannière propose à l'administrateur de **Réimporter les lots en attente (en statut `paused` par sécurité)** afin qu'il puisse vérifier et relancer les envois sans perte.  
3. **Auto-guérison de la sécurité du dossier Spool :**  
   * Après une restauration sur un nouveau serveur, `eg-mail` vérifie systématiquement la présence des fichiers `.htaccess` (`Deny from all`) et `index.php` dans `eg-mail-spool/` et les recrée immédiatement s'ils sont absents.

# **8\. Architecture des Fichiers du Plugin `eg-mail`**

eg-mail/
├── eg-mail.php                        # Bootstrap, constantes (EG_MAIL_VERSION), autoloader SPL, hooks
├── uninstall.php                      # Nettoyage propre (table SQL, options, spool, crons)
├── includes/                          # Logique PHP 8.4 (PSR-4 -> Namespace: EGMail\)
│   ├── Core/
│   │   ├── Backup_Integration.php     # Hook 'eg_backup_registered_locations' et snapshot JSON
│   │   ├── Activator.php              # dbDelta (table eg_mail_queue), dossier eg-mail-spool, planification WP-Cron
│   │   ├── Settings.php               # Options (SMTP, Quota/min, Quota/heure optionnel, Plage de nuit)
│   │   └── Smtp_Configurator.php      # Hook 'phpmailer_init' (Host, Port, Auth, KeepAlive)
│   ├── Queue/
│   │   ├── Interceptor.php            # Hook 'pre_wp_mail', calcul batch_id, éclatement destinataires
│   │   ├── Attachment_Spooler.php     # Déduplication par md5_file() et nettoyage orphelins
│   │   ├── Cleaner.php                # Purge quotidienne des anciens messages envoyés
│   │   └── Worker.php                 # Cron 1 min, calcul quota restant, envoi KeepAlive et coupe-circuit
│   ├── Repositories/
│   │   └── Queue_Repository.php       # Requêtes SQL directes et Object Cache ($wpdb, batchs, quotas)
│   ├── Enums/
│   │   ├── Queue_Status.php           # Statuts typés (pending, processing, paused, sent, failed, cancelled)
│   │   └── Queue_Priority.php         # Priorités typées (Bypass, Critical, Normal, Low)
│   ├── DTO/
│   │   ├── Queue_Item.php             # DTO readonly d'un enregistrement d'e-mail
│   │   ├── Context_Meta.php           # DTO readonly du contexte WordPress capturé
│   │   └── Batch_Summary.php          # DTO readonly d'agrégation d'une campagne
│   ├── Admin/
│   │   ├── Admin_Menu.php             # Sous-pages sous "Outils" (Campagnes, File FIFO, Réglages)
│   │   ├── Batch_List_Table.php       # Vue groupée par batch_id (Pause, Annuler/Supprimer le lot, Prévisualiser)
│   │   ├── Queue_List_Table.php       # Vue unitaire FIFO + bouton "Vider la file d'attente"
│   │   └── Settings_Page.php          # Page de configuration des réglages et réconciliation PRA
│   └── API/
│       └── Admin_Rest_Controller.php  # Contrôleur REST pour les actions asynchrones back-office
├── src/                               # Sources frontend (Assets DEV)
│   ├── ts/                            # Sources TypeScript (admin-batch.ts, etc.)
│   ├── scss/                          # Sources SCSS BEM (admin-queue.scss, etc.)
│   └── types/                         # Types TypeScript partagés
└── assets/                            # Assets compilés de production
    ├── js/
    └── css/

# **9\. Récapitulatif des Réglages Administrateur (`eg-mail`)**

1. **Configuration SMTP Forcée :** Activer/Désactiver le forçage SMTP, Hôte, Port, Chiffrement (TLS/SSL/Aucun), Authentification, Mot de passe chiffré, Email et Nom d'expéditeur par défaut.  
2. **Régulation du Flux (Rate Limiting) :**  
   * **Quota par minute (Journée) :** ex. `10` mails / minute.  
   * **Quota par minute (Nuit) :** ex. `25` mails / minute.  
   * **Quota maximum par heure glissante (Optionnel) :** ex. `200` mails / heure (laisser à `0` ou vide pour désactiver).  
3. **Mode Nocturne (Priorités basses \>= 20\) :**  
   * Activer le report nocturne : Oui / Non.  
   * Heure de début (ex: `22:00`) et Heure de fin (ex: `06:00`).  
4. **Rétention et Historique :**  
   * Durée de conservation des e-mails envoyés (`sent`) dans le journal : ex. `15 jours` (permet de vérifier qu'un adhérent a bien reçu sa convocation avant purge automatique).

