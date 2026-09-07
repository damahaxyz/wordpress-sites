# 13799 Shop Plugin

This plugin owns site-specific functionality that should remain active if the
site theme changes. Add product fields, checkout rules, REST endpoints, scheduled
tasks, integrations, and admin settings here.

The starter plugin provides:

- the `Shop13799\Plugin` namespace;
- the `shop_13799_plugin_loaded` extension hook;
- the `[shop_13799_year]` example shortcode;
- activation version tracking and uninstall cleanup.

Split larger features into dedicated classes under `includes/`, require them from
`13799-plugin.php`, and register them from the main `Plugin` service.
