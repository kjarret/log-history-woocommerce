# Log History for WooCommerce Products

Plugin WordPress qui affiche l'historique **Simple History** directement sur la page de modification d'un produit WooCommerce dans le back-office.

## Dépendances

| Plugin | Version minimale |
|--------|-----------------|
| [Simple History](https://wordpress.org/plugins/simple-history/) | 4.x |
| WooCommerce | 7.x |

L'add-on payant [Simple History WooCommerce](https://simple-history.com/add-ons/woocommerce/) est **optionnel** : s'il est actif, ses logs (`WooCommerceLogger`) sont automatiquement fusionnés avec ceux du logger standard.

## Fonctionnalités

- Metabox **« Historique des modifications »** sur la fiche produit (zone `normal / low`)
- Affiche : icône d'événement, avatar + nom de l'utilisateur, date complète + date relative, message interpolé, adresse IP
- Pagination côté serveur (20 entrées par page) avec bouton **Charger plus** en AJAX
- Détection automatique de l'add-on WooCommerce (fusion des deux sources de logs)
- Niveaux de log colorés (`error`, `warning`, `info`, …)

## Structure

```
log-history-woocommerce/
├── log-history-woocommerce.php          # Point d'entrée, headers plugin
├── includes/
│   └── class-product-log-metabox.php   # Logique metabox + AJAX
├── assets/
│   ├── css/admin.css                   # Styles back-office
│   └── js/admin.js                     # Bouton "Charger plus"
└── readme.md
```

## Installation

1. Copier le dossier dans `wp-content/plugins/`
2. Activer le plugin dans **Extensions → Extensions installées**
3. Ouvrir la fiche d'un produit WooCommerce : la metabox apparaît en bas de la colonne principale

## Hooks disponibles

Aucun hook personnalisé n'est exposé pour l'instant. Les hooks natifs de Simple History (`simple_history/log/do_log`, etc.) s'appliquent normalement.
