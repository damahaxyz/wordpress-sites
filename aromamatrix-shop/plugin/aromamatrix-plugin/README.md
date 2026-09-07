# AROMAMATRIX Plugin

Site-specific WordPress plugin for AROMAMATRIX business functionality.

The plugin manages protected WooCommerce factory-model and product-note fields
for internal use. Administrators can edit both values on the product screen and
see them beside the SKU in the Products list. Factory models can also be found
with the standard Products search box. Both fields remain hidden from customers.

It also supplies customer sign-in and registration in the storefront. Customers
register with an email address and a country-code-selected WhatsApp number, and
can sign in with their username, email address, or WhatsApp number. When
WooCommerce's **Allow customers to place orders without an account** setting is
disabled, guest add-to-cart actions are blocked server-side and the theme opens
a sign-in / create-account dialog. Registration follows WooCommerce's **Allow
customers to create an account on the My account page** setting.

Add future features such as inquiry workflows, integrations, APIs, and scheduled
jobs here so they continue working when the theme changes.

## WP Media Folder API

Authenticated users with the WordPress `upload_files` capability can create an
exactly named top-level WP Media Folder folder, then place one or more media
attachments into it through:

```text
POST /wp-json/aromamatrix/v1/media-folders
```

Send `name` (and optionally `parent`). The response returns the exact folder
ID and `created: true`; an exact case-insensitive existing folder is returned
with `created: false` so retries do not create duplicates.

Attachments are assigned through:

```text
POST /wp-json/aromamatrix/v1/media-folders/assign
```

Send a `folder_id` and `attachment_ids` array. The endpoint uses WP Media
Folder's native attachment-folder action, so it preserves the plugin's optional
physical-folder handling. Importers should keep each brand's folder ID in their
configuration and call this endpoint immediately after uploading media, instead
of performing server or database lookups.
