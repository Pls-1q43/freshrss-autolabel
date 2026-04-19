# FreshRSS AutoLabel

[English](../README.md) | [中文](./README.zh-CN.md) | [Français](./README.fr.md)

FreshRSS AutoLabel permet d’appliquer automatiquement des tags aux articles FreshRSS au moyen d’une classification par LLM ou d’un appariement zéro-shot par embeddings.

Auteur : [Pls](https://1q43.blog)  
Projet, documentation d’usage et mises à jour : [github.com/Pls-1q43/freshrss-autolabel](https://github.com/Pls-1q43/freshrss-autolabel)

## Vue d’ensemble

AutoLabel suit un modèle de permission mixte :

- les administrateurs publient des profils de modèle approuvés
- les utilisateurs créent leurs propres règles AutoLabel à partir de ces profils

Fonctionnalités principales :

- profils de modèle gérés par l’administrateur
- règles AutoLabel gérées par utilisateur
- mode LLM et mode embeddings
- plusieurs tags FreshRSS existants par règle
- file asynchrone pour les nouveaux articles et le rétro-remplissage
- fenêtres de concurrence si PHP fournit `curl_multi`

## Providers pris en charge

| Provider | LLM | Embeddings |
| --- | --- | --- |
| OpenAI | Oui | Oui |
| Anthropic | Oui | Non |
| Gemini | Oui | Oui |
| Ollama | Oui | Oui |

Notes :

- Anthropic est limité au mode LLM
- les tags cibles pour les embeddings doivent déjà exister dans FreshRSS
- la concurrence de file dépend de PHP `curl_multi`

## Configuration Ollama Embeddings recommandée

Pour une classification zéro-shot via Ollama, un très bon point de départ est :

- modèle : `qwen3-embedding:0.6b`
- longueur maximale du contenu : `1500`
- `Embedding num_ctx` : `2000`
- instruction : rédigée en anglais
- seuil de similarité : `0.65`

Cette combinaison est particulièrement adaptée à une classification locale légère par embeddings.

## Installation

### Option 1 : télécharger la release

1. Téléchargez la dernière version depuis [GitHub Releases](https://github.com/Pls-1q43/freshrss-autolabel/releases).
2. Décompressez-la dans le dossier `extensions/` de FreshRSS.
3. Vérifiez que le nom du dossier final est :

```text
xExtension-AutoLabel
```

4. Activez `AutoLabel` dans FreshRSS.

### Option 2 : cloner le dépôt

```bash
cd /path/to/FreshRSS/extensions
git clone https://github.com/Pls-1q43/freshrss-autolabel.git xExtension-AutoLabel
```

Puis activez l’extension depuis FreshRSS.

## Modèle de configuration

### Côté administrateur

- provider
- nom du modèle
- mode (`LLM` / `Embedding`)
- Base URL
- clé API
- délai d’expiration
- longueur maximale du contenu
- taille de fenêtre de concurrence (`batch_size`)
- dimensions d’embedding
- `Embedding num_ctx`
- instruction par défaut

`batch_size` signifie **taille de fenêtre de concurrence**, et non nombre de traitements strictement sériels.

### Côté utilisateur

- nom de la règle
- tags cibles
- profil choisi
- mode de la règle
- prompt
- ancres d’embedding
- seuil de similarité
- instruction

## Traitement de la file

La file asynchrone AutoLabel sert principalement à :

- traiter les nouveaux articles
- traiter les tâches de rétro-remplissage

La consommation de la file peut se faire via :

- `FreshrssUserMaintenance`
- le traitement manuel depuis le tableau de bord
- un worker de file séparé planifié par l’administrateur

## Permissions

- les utilisateurs non connectés ne peuvent pas accéder à AutoLabel
- les administrateurs voient la gestion des profils et l’URL du worker
- les utilisateurs connectés non administrateurs gèrent leurs propres règles, files, diagnostics et rétro-remplissages
- les utilisateurs non administrateurs ne voient pas la gestion des profils administrateur

## Dépannage

- la file grossit :
  - vérifier que la maintenance FreshRSS s’exécute réellement
- aucune concurrence visible :
  - vérifier que `curl_multi` est bien disponible en PHP
- délais Ollama sur les embeddings :
  - vérifier `content_max_chars`, `timeout_seconds`, `embedding_num_ctx` et les logs Ollama
- tags non appliqués :
  - vérifier que les tags cibles existent déjà dans FreshRSS

## Licence

Ce projet est distribué sous **GNU GPL 3.0**.  
Voir [LICENSE](../LICENSE).
