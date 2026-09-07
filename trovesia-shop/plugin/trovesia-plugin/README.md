# Trovesia Shop Plugin

This plugin owns site-specific functionality that should remain active if the
site theme changes. Add product fields, checkout rules, REST endpoints, scheduled
tasks, integrations, and admin settings here.

The starter plugin provides:

- the `Trovesia\Plugin` namespace;
- the `trovesia_plugin_loaded` extension hook;
- the `[trovesia_year]` example shortcode;
- activation version tracking and uninstall cleanup.

Split larger features into dedicated classes under `includes/`, require them from
`trovesia-plugin.php`, and register them from the main `Plugin` service.
