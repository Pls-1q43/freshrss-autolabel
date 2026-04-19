# FreshRSS AutoLabel

[English](../README.md) | [中文](./README.zh-CN.md) | [Français](./README.fr.md)

`AutoLabel` est une extension FreshRSS de type `system` qui applique automatiquement des **tags FreshRSS déjà existants** aux articles selon leur contenu. Deux approches sont prises en charge :

- classification par LLM
- classification zéro-shot par similarité d’embeddings

Le modèle de permission est hybride :

- les administrateurs gèrent les profils de modèle
- les utilisateurs créent leurs propres règles AutoLabel à partir des profils autorisés

## Fonctionnalités

- Profils de modèle gérés par l’administrateur
- Règles AutoLabel gérées par utilisateur
- Classification LLM avec OpenAI, Anthropic, Gemini et Ollama
- Classification par embeddings avec OpenAI, Gemini et Ollama
- Plusieurs AutoLabels par utilisateur
- Plusieurs tags cibles par AutoLabel
- File asynchrone pour les nouveaux articles et les tâches de rétro-remplissage
- Fenêtres de concurrence par profil si PHP `curl_multi` est disponible
- Traductions d’interface en anglais, chinois simplifié et français

## Architecture

- Type d’extension : `system`
- Côté administrateur :
  - création des profils de modèle
  - configuration du provider, du modèle, du mode, de la fenêtre de concurrence et des paramètres par défaut
- Côté utilisateur :
  - création de règles AutoLabel depuis les profils activés
  - sélection d’un ou plusieurs tags FreshRSS existants
  - configuration du prompt ou des ancres d’embedding et de l’instruction
- Côté file :
  - les nouveaux articles sont mis en file à l’insertion
  - la maintenance utilisateur FreshRSS consomme la file
  - un worker dédié peut aussi être planifié séparément

## Providers pris en charge

| Provider | LLM | Embeddings |
| --- | --- | --- |
| OpenAI | Oui | Oui |
| Anthropic | Oui | Non |
| Gemini | Oui | Oui |
| Ollama | Oui | Oui |

Notes :

- Les profils Anthropic sont limités au mode LLM.
- Les fenêtres de concurrence nécessitent `curl_multi` côté PHP.
- Si la concurrence n’est pas disponible, l’interface l’indique explicitement.

## Installation

1. Copiez ce dépôt dans le dossier `extensions/` de FreshRSS.
2. Le nom du dossier déployé doit être :

```text
xExtension-AutoLabel
```

3. Activez `AutoLabel` depuis la page des extensions FreshRSS.
4. Ouvrez le tableau de bord `AutoLabel`.

## Configuration

### Configuration administrateur

- mode : LLM ou embedding
- provider
- nom du modèle
- Base URL
- clé API
- délai d’expiration
- longueur maximale du contenu
- taille de la fenêtre de concurrence
- dimensions d’embedding
- `num_ctx` pour embedding
- instruction par défaut

Le champ `batch_size` signifie **taille de fenêtre de concurrence**. Une valeur de `5` signifie qu’AutoLabel essaie de lancer jusqu’à cinq requêtes d’articles en parallèle pour un même profil, puis attend la fin de cette fenêtre avant de lancer la suivante.

### Configuration utilisateur

- nom de la règle
- tags cibles
- profil de modèle
- mode
- prompt ou ancres d’embedding
- seuil de similarité
- instruction

## File et worker

AutoLabel utilise une file asynchrone pour les nouveaux articles et le rétro-remplissage.

- Consommation automatique :
  - via le hook `FreshrssUserMaintenance`
- Consommation manuelle :
  - depuis le tableau de bord AutoLabel
- Consommation indépendante :
  - via l’URL du worker dédié pour les administrateurs

Si la file grossit continuellement, vérifiez d’abord :

- que la maintenance FreshRSS s’exécute réellement
- que la concurrence est disponible
- que le débit du provider n’est pas inférieur au débit d’entrée

## Modèle de permission

- Les utilisateurs non connectés ne peuvent pas accéder au tableau de bord AutoLabel.
- Les administrateurs voient :
  - la gestion des profils de modèle
  - l’URL du worker de file
  - toutes les zones de configuration partagées
- Les utilisateurs connectés non administrateurs voient :
  - leurs propres règles AutoLabel
  - les zones d’essai, de rétro-remplissage, de file et de diagnostics
- Les utilisateurs connectés non administrateurs ne voient pas :
  - la gestion des profils administrateur
  - les URLs de worker contenant un token

## Dépannage

- Débit de file faible :
  - vérifier que la maintenance FreshRSS atteint bien l’extension
- Pas de concurrence visible :
  - vérifier la présence de `curl_multi`
- Délais Ollama sur les embeddings :
  - vérifier `content_max_chars`, `timeout_seconds`, `embedding_num_ctx` et les logs Ollama
- Tags non appliqués :
  - vérifier que les tags cibles existent déjà dans FreshRSS
