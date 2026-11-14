# Admin Menu Organizer

Reorder, rename and hide WordPress admin menu items per user role — declutter the dashboard for editors, shop managers and clients without touching code. Great for client sites where the full admin menu is overwhelming.

## Features

- **Reorder** — give each top-level item an order number; lower numbers appear first.
- **Rename** — give any menu or submenu item a clearer label (leave empty to keep the original).
- **Hide per role** — tick roles to hide an item from them; administrators always see everything, so you can never lock yourself out.
- **Submenu support** — hide or rename submenu items too (stored as `parent|child` rules).
- **Safe by design** — rules are validated against the menu items that actually exist, so a bad save can never inject phantom entries; hiding only removes items from the menu, it does not revoke capabilities.
- **Developer filter** — `wamo_rules` lets code adjust the rules before they are applied.

## Installation

1. Upload the `wp-admin-menu-organizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Go to **Settings → Menu Organizer** and arrange your menu.

## Uninstall

Deleting the plugin removes the `wamo_rules` option; the admin menu returns to its default order immediately.

## License

MIT — see [LICENSE](LICENSE).
