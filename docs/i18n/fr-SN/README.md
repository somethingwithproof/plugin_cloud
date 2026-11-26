<!-- Source: README.md; SHA256: 7c621642e5f1a46bac66e4bc744f70aebb014c610d22de034088845fdc170405 -->

<!-- translations:start -->
[English](../../../README.md) | [简体中文](../zh-CN/README.md) | [हिन्दी](../hi-IN/README.md) | [Español (España)](../es-ES/README.md) | [Español (Latinoamérica)](../es-419/README.md) | [العربية (السعودية)](../ar-SA/README.md) | [Afrikaans](../af-ZA/README.md) | [አማርኛ](../am-ET/README.md) | [العربية (مصر)](../ar-EG/README.md) | [العربية (المغرب)](../ar-MA/README.md) | [বাংলা](../bn-BD/README.md) | [Bosanski](../bs-BA/README.md) | [Deutsch](../de-DE/README.md) | [فارسی](../fa-IR/README.md) | [Filipino](../fil-PH/README.md) | [Français](../fr-FR/README.md) | [Français (Maroc)](../fr-MA/README.md) | [Français (Sénégal)](README.md) | [Hausa](../ha-NG/README.md) | [עברית](../he-IL/README.md) | [Hrvatski](../hr-HR/README.md) | [Bahasa Indonesia](../id-ID/README.md) | [Igbo](../ig-NG/README.md) | [Italiano](../it-IT/README.md) | [日本語](../ja-JP/README.md) | [한국어](../ko-KR/README.md) | [Malagasy](../mg-MG/README.md) | [मराठी](../mr-IN/README.md) | [Bahasa Melayu](../ms-MY/README.md) | [Nederlands](../nl-NL/README.md) | [Polski](../pl-PL/README.md) | [Português (Brasil)](../pt-BR/README.md) | [Русский](../ru-RU/README.md) | [Kinyarwanda](../rw-RW/README.md) | [Soomaali](../so-SO/README.md) | [Српски](../sr-RS/README.md) | [Svenska](../sv-SE/README.md) | [Kiswahili](../sw-KE/README.md) | [தமிழ்](../ta-IN/README.md) | [తెలుగు](../te-IN/README.md) | [ไทย](../th-TH/README.md) | [Türkçe](../tr-TR/README.md) | [Українська](../uk-UA/README.md) | [اردو](../ur-PK/README.md) | [Tiếng Việt](../vi-VN/README.md) | [isiXhosa](../xh-ZA/README.md) | [Yorùbá](../yo-NG/README.md) | [繁體中文](../zh-TW/README.md) | [isiZulu](../zu-ZA/README.md)
<!-- translations:end -->

# plugin_cloud

Un plugin Cacti 1.2 qui intègre les ressources et les métriques des fournisseurs de cloud public dans Cacti sous forme de périphériques, de sources de données et de graphiques ordinaires. Les charges de travail cloud se retrouvent ainsi à côté du réseau qui les transporte, sous le contrôle des permissions de Cacti, et Thold ainsi que Maint peuvent générer des alertes ou les suspendre. Un seul plugin couvre l'ensemble des fournisseurs, avec un pilote par fournisseur : DigitalOcean, AWS, Google Cloud et Azure en premier lieu.

> **Statut : jalon 3 (DigitalOcean et objets Cacti), en cours de révision.** Ce jalon ajoute le pilote DigitalOcean, les modèles Cacti, un périphérique de portée par compte avec une requête de données par type de ressource, l'entrée du serveur de scripts, ainsi qu'une estimation des requêtes sur la page Nouveaux graphiques et la page de santé. Les premières vérifications sur un compte DigitalOcean réel sont consignées dans [faits sur les fournisseurs](../../research/provider-facts.md). Ne l'exécutez pas en production.

## Rôles d'accès

| Domaine | Accès |
| --- | --- |
| Cloud View | Pages de supervision existantes. |
| Cloud Operator | Supervision, plus la page Opérations et les acquittements personnels de triage. |
| Cloud Admin | Accès Opérateur, plus la configuration existante et les actions d'administration. |

Après avoir copié les fichiers de mise à niveau, ouvrez la page Plugins en tant qu'administrateur pour enregistrer le nouveau domaine. Attribuez explicitement le domaine Operator sous **Configuration > Utilisateurs** ou aux groupes d'utilisateurs. Les mises à niveau conservent les identifiants et les autorisations existants des domaines View et Admin. L'accès Admin hérite de l'accès Operator, et l'accès Operator hérite de l'accès View à chaque requête ; cela n'ajoute pas d'autorisations enregistrées aux utilisateurs ni aux groupes. Les paramètres du plugin exigent également un accès Cloud Admin en plus de la permission Paramètres de Cacti.

Les permissions sur les périphériques Cacti limitent toujours les lignes et les actions disponibles pour chaque utilisateur ; les objets non mappés nécessitent un administrateur. Les opérateurs peuvent vérifier l'identifiant stocké pour un compte dont ils peuvent accéder au périphérique Cacti. Les identifiants, la configuration, le mappage, la suppression et les autres modifications administratives restent dans les pages d'administration. Les acquittements ne durent que pendant la session en cours de l'utilisateur, pendant une heure au maximum et uniquement tant que la même observation reste fraîche. Toute observation nouvelle ou modifiée les invalide. Ils ne marquent jamais une mesure en échec comme saine et ne suppriment jamais les alertes.

## Pourquoi

Chaque fournisseur de cloud dispose de sa propre console de supervision. Utilisées en parallèle avec Cacti, elles laissent trois lacunes :

- **Contrôle d'accès séparé.** Montrer des métriques cloud à un utilisateur Cacti implique de lui fournir un accès au cloud.
- **Absence de contexte réseau.** Les liens sur site sont tracés dans Cacti ; les charges de travail qui s'appuient dessus sont tracées dans la console de chaque fournisseur.
- **Alertes séparées.** Thold et Maint s'appliquent aux sources de données et aux périphériques Cacti, si bien que les alarmes des fournisseurs doivent être configurées et suspendues séparément, une fois par fournisseur.

Le plugin lit l'API de supervision de chaque fournisseur sur un poller Cacti, met en cache les derniers points de données et les fournit au serveur de scripts de Cacti. Le poller de Cacti écrit les fichiers RRD, de sorte que la génération de graphiques, les permissions, Thold, Maint et boost fonctionnent comme pour toute autre donnée Cacti.

## Engagements de conception

- **Lecture seule.** Chaque pilote ne peut construire que les opérations fournisseur figurant sur sa liste d'autorisation ; le plugin ne modifie jamais une ressource cloud. ([ADR 0002](../../decisions/0002-read-only-with-operation-allowlist.md))
- **PHP uniquement, sans SDK.** La signature et l'échange de jetons sont implémentés en PHP avec les extensions que Cacti requiert déjà. Aucune dépendance d'exécution Composer. ([ADR 0003](../../decisions/0003-php-only-without-sdks.md))
- **Le coût avant la collecte.** Le plugin lit uniquement les métriques disposant d'une source de données Cacti, affiche le nombre de requêtes attendu et le coût au tarif catalogue avant la création des graphiques, et applique un plafond mensuel. ([ADR 0005](../../decisions/0005-collect-only-what-is-graphed.md))
- **Aucun identifiant en clair dans la base de données.** Les identifiants proviennent d'un coffre-fort chiffré protégé par une clé stockée dans un fichier hors de la base de données, d'un fichier d'identifiants ou de l'identité d'instance du fournisseur. ([ADR 0007](../../decisions/0007-credentials-outside-the-database-in-the-clear.md))

## Portée et statut

| Phase | Portée | Jalons | Statut |
|---|---|---|---|
| 0, recherche | Confirmation des faits fournisseurs, catalogues de métriques, modèle de coût et de débit, vecteurs de test de signature, prototype de transport | [M1](https://github.com/somethingwithproof/plugin_cloud/milestone/1) | Terminé, sauf les vérifications nécessitant un compte fournisseur réel |
| 1, noyau et DigitalOcean | Comptes, coffre-fort d'identifiants, transport, collecteur, cache d'observations, objets Cacti, page de santé, Droplets et équilibreurs de charge DigitalOcean | [M2](https://github.com/somethingwithproof/plugin_cloud/milestone/2), [M3](https://github.com/somethingwithproof/plugin_cloud/milestone/3), [M4](https://github.com/somethingwithproof/plugin_cloud/milestone/4) | M2 terminé ([#81](https://github.com/somethingwithproof/plugin_cloud/pull/81)) ; pilote, modèles et périphériques de portée M3 fusionnés ; estimations de requêtes, permissions et contrôles de performance en révision ; M4 non démarré |
| 2, AWS | Signature Version 4, `AssumeRole`, rôles d'instance, CloudWatch `GetMetricData`, inventaire régional | [M5](https://github.com/somethingwithproof/plugin_cloud/milestone/5) | Non démarré |
| 3, Google Cloud | JWT de compte de service, serveur de métadonnées, Cloud Monitoring `timeSeries.list` | [M6](https://github.com/somethingwithproof/plugin_cloud/milestone/6) | Non démarré |
| 4, Azure | Identifiants client Entra, identité managée, Resource Graph, `metrics:getBatch` | [M7](https://github.com/somethingwithproof/plugin_cloud/milestone/7) | Non démarré |
| 5, collecteurs distants | Comptes sur les collecteurs de données distants Cacti | [M8](https://github.com/somethingwithproof/plugin_cloud/milestone/8) | Non démarré |

Hors périmètre : la modification des ressources cloud, la création ou la réplication d'alarmes fournisseur, les rapports de facturation, les journaux et traces, ainsi qu'un moteur d'alerte propre au plugin. Les autres fournisseurs (Hetzner Cloud, Akamai Cloud, Oracle Cloud et d'autres) font l'objet de tickets de recherche.

## Ce que fournit le jalon 2

- **Une installation sécurisée.** Sur PHP inférieur à 8.2, ou sans ext-openssl, l'installation et l'activation sont refusées avec un message, et le reste de Cacti continue de fonctionner.
- **Cycle de vie.** Installation, mise à niveau et désinstallation via la page Plugins de Cacti. Les mises à niveau sont ordonnées et résistent aux interruptions. La désinstallation conserve les tables du plugin, sauf si vous décochez *Conserver les tables du plugin lors de la désinstallation*.
- **Domaines distincts.** *Cloud Admin* ouvre **Comptes Cloud** ; *Cloud Operator* ouvre **Opérations Cloud** ; *Cloud View* ouvre **Santé Cloud**. Admin hérite d'Operator, et Operator hérite de View. Ces trois pages se trouvent dans le menu Gestion.
- **Comptes et identifiants.** Ajout, modification, vérification et suppression de comptes. Un identifiant est soit chiffré dans la base de données à l'aide d'un fichier clé conservé à l'extérieur (AES-256-GCM), soit lu depuis un fichier sur le poller. Les identifiants sont en écriture seule dans l'interface et sont masqués dans les journaux.
- **Le collecteur.** Il démarre à chaque exécution du poller, à raison d'une instance par poller, dans la limite d'un budget de temps. Il prend un bail par compte, exécute la découverte selon son propre intervalle et lit les métriques uniquement pour les sources de données actives propres au plugin.
- **Santé.** État par compte de la collecte, de la découverte, des ressources et de la fraîcheur, ainsi que des avertissements en cas de clé de coffre-fort inutilisable, de fichiers d'identifiants non sécurisés ou d'intervalles que le poller ne peut pas respecter.

## Installation et configuration

Ces étapes installent le code du jalon 2 pour évaluation. Elles nécessitent Cacti 1.2.31 ou version ultérieure sur PHP 8.2 ou version ultérieure.

1. Copiez le dépôt vers `plugins/cloud` dans votre répertoire Cacti. Le répertoire doit obligatoirement se nommer `cloud`.
2. Créez la clé du coffre-fort en dehors de la racine web. Placez-la dans un répertoire où seuls root et l'utilisateur Cacti peuvent écrire, et rendez le fichier lisible uniquement par l'utilisateur qui exécute le serveur web et le poller. Le plugin ne crée ni n'écrit jamais ce fichier :

   ```sh
   sudo install -d -m 0750 -o root -g cacti /etc/cacti
   head -c 32 /dev/urandom | sudo tee /etc/cacti/cloud-vault.key > /dev/null
   sudo chown cacti:cacti /etc/cacti/cloud-vault.key
   sudo chmod 0600 /etc/cacti/cloud-vault.key
   ```

   Remplacez `cacti` par l'utilisateur qui exécute votre serveur web et votre poller. Le fichier contient 32 octets bruts ou 64 caractères hexadécimaux. Sauvegardez-le avec la base de données ; sans lui, les identifiants enregistrés devront être saisis de nouveau.
3. Dans Cacti, ouvrez **Configuration > Plugins**, puis installez et activez *Cloud*.
4. Sous **Configuration > Paramètres > Cloud**, définissez *Fichier clé du coffre-fort* si vous avez utilisé un autre chemin. Le *Budget de temps du collecteur* (240 secondes par défaut) doit rester inférieur à l'intervalle du poller.
5. Accordez *Cloud Admin*, *Cloud Operator* ou *Cloud View* aux utilisateurs et aux groupes sous **Configuration > Utilisateurs**. L'installation accorde Cloud Admin et Cloud View à l'administrateur ; Cloud Admin inclut l'accès Operator, l'administrateur n'a donc besoin d'aucune attribution Operator distincte.
6. Ajoutez des comptes sous **Gestion > Comptes Cloud**, puis vérifiez l'absence d'avertissements dans **Gestion > Santé Cloud**.

## Cibles de compatibilité

| Composant | Cible |
|---|---|
| Cacti | 1.2.31 ou version ultérieure, sans correctif du noyau |
| PHP | 8.2 ou version ultérieure ; `setup.php` reste analysable sur 8.0 afin qu'un environnement d'exécution non pris en charge reçoive un refus au lieu d'une interface Cacti défaillante |
| Extensions PHP | Aucune au-delà de celles requises par Cacti 1.2.31 |
| Thold, Maint | Aucune modification requise |

## Conception

- [docs/performance.md](../../performance.md) présente les mesures du collecteur et de la page de santé, avec le matériel et les paramètres utilisés.
- [docs/PLAN.md](../../PLAN.md) est le plan complet : architecture, faits relatifs aux fournisseurs et à Cacti sur lesquels il repose (avec sources), contrat de pilote, calcul des coûts, limites de sécurité, tests et risques.
- [docs/decisions/](../../decisions) regroupe les dossiers de décision d'architecture pour les choix déjà arrêtés.
- [docs/REQUIREMENTS.md](../../REQUIREMENTS.md) associe chaque identifiant d'exigence à son ticket.

```mermaid
flowchart LR
    API[API de supervision des fournisseurs] -->|HTTPS, lecture seule| C[Collecteur sur le poller Cacti du compte]
    C --> O[(Cache des observations)]
    O --> SS[Sources de données du serveur de scripts]
    SS --> RRD[(Fichiers RRD Cacti)]
    SS -->|poller_output| T[Thold et Maint]
    RRD --> G[Graphiques et permissions Cacti]
```

## Suivi du travail

- **Jalons** : ils suivent les jalons du plan. Chacun dispose d'un ticket épique listant ses sous-tickets et les preuves de validation.
- **Tickets** : ils sont typés par étiquette : `type: requirement`, `type: research`, `type: decision`, `type: epic`. Les tickets d'exigence énoncent des exigences numérotées et testables (`REQ-AREA-NNN`) ainsi que des critères d'acceptation.
- **Étiquettes** : elles portent également `area:`, `provider:`, `phase:`, `priority:` et `status:`. Voir [CONTRIBUTING.md](../../../CONTRIBUTING.md#labels).
- Le [tableau du projet](https://github.com/users/somethingwithproof/projects/7) affiche tous les tickets répartis par jalon.

## Développement

L'intégration continue (CI) vérifie la syntaxe des points d'entrée sur PHP 8.0, exécute les tests unitaires et d'intégration ainsi que PHPStan niveau 9, et installe le plugin sur PHP 8.2 à 8.5 avec Cacti 1.2.31 et la branche 1.2.x. Elle vérifie également que PHP 8.1 est refusé sans compromettre Cacti. Les tests utilisent Pest issu du harnais de plugin du noyau Cacti. Ce choix est provisoire jusqu'à ce que le ticket [#12](https://github.com/somethingwithproof/plugin_cloud/issues/12) soit tranché. [.github/workflows/plugin-ci-workflow.yml](../../../.github/workflows/plugin-ci-workflow.yml) énumère chaque étape. Exécutez `sh tests/lint-workflows.sh` et `sh locales/build_gettext.sh --check` localement avant de pousser.

## Contribution

Lisez [CONTRIBUTING.md](../../../CONTRIBUTING.md). Les commits nécessitent une signature DCO. Les discussions de conception ont lieu sur les tickets ; les décisions ouvertes portent l'étiquette `type: decision`.

## Sécurité

Signalez les vulnérabilités de manière privée ; consultez [SECURITY.md](../../../SECURITY.md).

## Licence

GNU General Public License v2.0, la même licence que Cacti et ses plugins. Voir [LICENSE](../../../LICENSE).
