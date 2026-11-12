# ADR-0001 — Le stockage Doctrine des préférences de tableaux

- **Statut** : Accepted — 2026-11-12
- **Décideurs** : jul6art
- **Origine** : audit Fable 5.2, T20 (lot 5, premier lot du chantier de remontée)

## Contexte

Le bundle porte déjà l'écran et le contrat des préférences de tableaux : le contrôleur
`DatatablePreferenceController` (lire, enregistrer, effacer l'arrangement d'un tableau pour le compte
connecté) et l'interface `Preference\DatatablePreferenceStoreInterface` (`read`, `write`, `delete`). Il
ne porte pas leur **stockage** : chaque application l'écrit elle-même, en trois classes recopiées du
squelette — `DatatablePreferenceStore`, `DatatablePreferenceRepository` et l'entité
`DatatablePreference` — puis aliase l'interface sur son magasin dans `config/services.yaml`.

Mesure du 2026-11-12 (`superp/docs/analyse/audit_fable_5_2_t20_copies.md`) :

| Classe | Copies | Variantes | Référence | Autres variantes (écart en lignes) |
|---|---|---|---|---|
| `DatatablePreferenceStore` | 9 | 4 | cegeta, cereezer, devingame, devinthehood, tradebot, wtg | devinlive 53, superp 71, squelette 4 |
| `DatatablePreference` | 9 | 5 | cereezer, devingame, devinthehood, tradebot, wtg | cegeta 24, devinlive 33, superp 59, squelette 3 |
| `DatatablePreferenceRepository` | 8 | 4 | cereezer, devingame, devinthehood, tradebot, wtg | cegeta, devinlive, superp |

devinthecode n'a pas de back-office Twig : il n'a ni ces classes ni la fonction.

### Les écarts, un par un

| Où | Écart | Classement |
|---|---|---|
| superp | espaces de noms `App\Core\…`, `App\Modules\User\Entity\User` | propre à l'application : son entité concrète reste dans son espace de noms |
| superp | colonne `datatable_key` nommée par la stratégie de nommage au lieu de `name:` | sans effet (même nom de colonne) ; l'entité de base le nomme explicitement |
| superp, devinlive | enregistrement par `$repository->save()` / `flush()` au lieu de l'`EntityManager` | dérive de style, même comportement : abandonnée |
| devinlive | lecture en une expression ternaire, classe non `final` | dérive de style : abandonnée |
| squelette | `type: 'text'` au lieu de `Types::TEXT`, `null ===` au lieu de `instanceof` | retard sur les applications : abandonné |
| **cegeta** | colonne `organization` (nullable), attribut `#[TenantScoped]`, `TenantOwned` ; unicité sur (compte, **organisation**, clé) ; le dépôt cherche aussi par organisation | **besoin du produit** (une préférence par compte et par espace) : gardé, par un point d'extension |

## Décision

### 1. Le bundle livre le stockage, sous `Preference/Doctrine/`

- **`AbstractDatatablePreference`** — `#[ORM\MappedSuperclass]` : identifiant et horodatage (traits de
  core-bundle, déjà requis), `owner` (`ManyToOne`, `onDelete: CASCADE`), `datatableKey` (colonne
  `datatable_key`, 64 caractères, `NotBlank`, `Length`), `payload` (`TEXT`, `NotBlank`), le constructeur
  `(owner, key, payload)`, les accesseurs et `setPayload()`. Mêmes colonnes, mêmes types que les copies.
- **Le compte** : `owner` vise l'interface `UserInterface` de Symfony, que le bundle fait résoudre vers
  la classe de compte de l'application (`doctrine.orm.resolve_target_entities`, posé par l'extension
  depuis `datatable.preferences.storage.user_class`). Le `User` des applications ne change pas.
- **`DoctrineDatatablePreferenceStore`** implémente `DatatablePreferenceStoreInterface` : cherche par
  compte et clé, crée par `new <entity_class>(compte, clé, json)`, met à jour, efface ; un compte qui
  n'est pas de la classe configurée lit `null` et n'écrit rien — comme les copies.
- **Point d'extension** `DatatablePreferenceScopeInterface` : des critères de recherche en plus du
  compte et de la clé (cegeta : l'organisation du compte). Les services qui l'implémentent sont
  collectés par tag ; sans eux, compte et clé suffisent.

### 2. Configuration, désactivée par défaut

```yaml
datatable:
    preferences:
        storage:
            entity_class: App\Entity\DatatablePreference   # active le magasin Doctrine
            user_class: App\Entity\User
```

Sans `entity_class`, rien ne change : une application garde son propre magasin. Avec, le bundle aliase
l'interface sur son magasin. `doctrine/orm` et `doctrine/doctrine-bundle` passent en `suggest` ; activer
la clé sans eux échoue à la compilation avec un message qui le dit.

### 3. Ce qui reste dans chaque application

L'entité concrète, réduite à ce qui lui est propre : `#[ORM\Entity]`, `#[ORM\Table(name:
'datatable_preference')]` et sa contrainte d'unicité (les noms ne changent pas : le schéma non plus) —
plus, chez cegeta, l'organisation, son index, `#[TenantScoped]` et un service de portée. Le magasin, le
dépôt et l'alias de `services.yaml` disparaissent.

## Tests exigés (aucune modification sans eux)

- **Bundle** : tests unitaires du magasin (création, mise à jour, effacement, compte d'une autre classe,
  critères de portée) et tests fonctionnels sur un noyau de test avec Doctrine et SQLite (le contrôleur
  de bout en bout : `GET`, `PUT`, `DELETE`, deux comptes, deux clés, une portée) ; dépendances hautes et
  basses ; PHPStan, Rector, style.
- **Chaque application** : `doctrine:schema:validate` et `doctrine:migrations:diff` vides ; porte
  complète (ses tests `DatatablePreferencesTest` existants couvrent l'enregistrement de bout en bout) ;
  **avant et après, dans Chrome** : sur un tableau, masquer une colonne et changer la taille de page,
  recharger (l'arrangement revient), réinitialiser (les défauts reviennent), avec les statuts des appels
  relevés. cegeta en plus : le même compte dans deux espaces garde deux arrangements.
- **Squelette** : magasin et dépôt retirés de `modes/backoffice/overlay-user`, entité concrète réduite,
  configuration ajoutée ; les cinq modes générés, leur porte jouée ; le parcours Chrome sur un backoffice
  généré.

## Conséquences

- datatable-bundle 3.3.0 (version mineure, badge). Les applications montent leur plancher.
- Déploiement des démonstrations d'abord (superp, cereezer, tradebot, wtg, devinthehood), puis, une fois
  vérifiées, des applications à vrais utilisateurs (devingame, cegeta, devinlive).
- Une application qui a besoin d'un autre stockage garde le sien : l'interface ne change pas.
