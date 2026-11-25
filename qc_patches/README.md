# QC patches pour XC_VM 2.7.2 / 2.7.4 — moteur de diffusion Go

Ce dossier regroupe les corrections et ajouts appliqués à XC_VM (panneau Main + serveurs de diffusion / LB),
l'outil d'installation automatique et le code source Go des programmes de diffusion.
Aucune donnée de serveur n'est incluse : pas de chaînes, d'URL de sources, d'EPG, de base de données,
de liste de proxys, de clés ni d'adresses IP.

## Installation en une ligne (en root, sur le Main)

Télécharger `qc_xcvm_updater.tgz` depuis la page **Releases** de ce dépôt (le paquet contient en plus
les programmes Go compilés et FFmpeg 8.1-dash), puis :

```bash
cd /root && rm -rf qc_xcvm_pkg && tar xzf qc_xcvm_updater.tgz && python3 qc_xcvm_pkg/qc_xcvm_updater.py --restart --all-lbs
```

| Option | Effet |
|---|---|
| `--all-lbs` | Sur le Main : met aussi à jour chaque LB actif (SSH root, clé dédiée installée au 1er passage ; mot de passe demandé une seule fois), un à la fois, arrêt au premier échec |
| `--restart` | Redémarre XC_VM après la mise à jour (coupe les clients quelques secondes) |
| `--dry-run` | Montre ce qui serait fait, sans rien changer |
| `--no-deps` | N'installe pas les programmes Go ni FFmpeg |
| `--rollback <dossier>` | Restaure la sauvegarde créée par une installation |
| `--force` | Autre version de XC_VM que 2.7.2 / 2.7.4 (à vos risques) |

L'outil détecte seul le rôle (Main ou LB), sauvegarde chaque fichier, vérifie la syntaxe PHP et se restaure
tout seul en cas d'erreur. Les fichiers déjà modifiés (ancienne version de ces patches ou modification locale)
sont remplacés, avec sauvegarde.

## Moteur de diffusion Go (installé et démarré sur chaque LB)

| Programme | Port local | Rôle |
|---|---|---|
| `odrelay` | 8147 | Démarrage rapide des chaînes à la demande : le client reçoit l'image tout de suite pendant que FFmpeg démarre (TS et HLS) |
| `livehold` | 8150 | Garde les clients connectés pendant un redémarrage de FFmpeg ou une coupure de source (vidéo « hors ligne » au lieu d'une déconnexion), TS + HLS |
| `ckts` | 8160 | Moteur ClearKey DASH → MPEG-TS (+ 15 relais `mpdproxy-ads` 8115-8129 pour les coupures publicitaires) |
| `mpdproxy` | 8098-8113 | Proxy DASH/ClearKey (manifestes + segments, cache), 16 instances activées par socket systemd |
| FFmpeg 8.1-dash | — | Build FFmpeg utilisé pour les sources DASH/ClearKey (`bin/ffmpeg_bin/8.1-dash`) |

Ce n'est pas le fanout de XC_VM : le fanout reste tel quel (non activé par ces patches).
Les listes `config/odrelay_streams.txt`, `config/livehold_streams.txt` et `/etc/qc_ckts_ids` valent `*`
(toutes les chaînes) ; remplacer `*` par des IDs pour limiter.
Le tableau de bord du Main affiche dans **Service Status** la ligne « Go delivery » avec les serveurs où
le moteur tourne.

Code source dans `go/` : `odrelay` (identique au binaire livré), `livehold` et `mpdproxy`
(versions sources antérieures aux binaires livrés dans le paquet). Le source de `ckts` n'est pas inclus
(binaire seulement, dans le paquet de la Release). Compilation : `cd go/<prog> && CGO_ENABLED=0 go build`.

## Corrections XC_VM

- **Coupures de clients TS/VOD actifs (bug XC_VM 2.7.2 et 2.7.4)** — `Domain/Cluster/ConnectionIngest.php` :
  sur les nodes qui gèrent eux-mêmes le HLS, les check-ins des workers TS (toutes les 300 s) n'allaient que
  dans le cluster bus, alors que `cron:users` lit le store ; au bout de 900 s il croyait le client parti et le
  coupait (sessions coupées à 10, 15, 20 min pile). Les check-ins TS/VOD vont maintenant aussi au store.
  Le HLS n'est pas modifié.
- Connexions fantômes : nettoyage des enregistrements dont le worker est mort (`Core/Cluster/QcGhostReaper.php`)
  et comptage des connexions réellement actives pour la limite de connexions.
- Démarrage rapide à la demande (sonde FFprobe courte, relance de flux), continuité HLS, lecture DASH/ClearKey
  (FFmpeg 8.1-dash, mpdproxy, ckts), options de reconnexion des sources.
- Films et séries : réparation et import (TMDB / TVDB de secours), import par lots.
- EPG : décalage horaire par chaîne.
- Proxys de source : page de gestion + choix « Proxy (liste) » par chaîne (table `source_proxies`, vide).
- Tickets : indicateur de messages non lus.

## Modules et pages ajoutés (Main)

- **Remboursement de crédits revendeurs** (`refundcredit`, table `refund_history`) + prolongation de lignes par l'admin.
- **API d'essais** (`trialapi`, route nginx `/trial_api`) et **API de lignes** (`/line_api`).
- **Import fournisseur** (`providerimport`).
- **API compatible XAccel** (`/xaapi/`), configuration à remplir dans `config/xaccel_compat_api.json`.
- Les modules sont enregistrés comme installés dans `config/modules.php`.

## Contenu

```
qc_patches/
  updater/   qc_xcvm_updater.py, manifest.json, files/ + diffs/ (2.7.2), files_2.7.4/ + diffs_2.7.4/, sql/, systemd/
  go/        odrelay/, livehold/, mpdproxy/ (sources Go)
```
