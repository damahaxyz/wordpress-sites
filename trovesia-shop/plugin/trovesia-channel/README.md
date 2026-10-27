# Trovesia Channel

First-party channel links and WooCommerce order attribution for Trovesia.

## Included

- Store-admin channel management and a global attribution-window setting.
- Explicit login-email, username, and password fields. Store administrators
  can set a new account's initial password or reset an existing manager's
  password; leaving the field blank preserves an existing password.
- Editing a channel pre-fills its assigned manager's login email and username.
  Passwords are never pre-filled because WordPress stores only password hashes.
- Creating and editing a channel use one admin modal, so channel details and
  the optional manager account can be saved together without leaving the list.
- A dedicated `trovesia_channel_manager` role and private frontend portal.
- A responsive, branded sign-in experience and channel dashboard with metric
  cards, period filters, campaign tools, status badges, and mobile tables.
- Invalid portal credentials return to the branded channel sign-in card with a
  generic error instead of exposing WordPress's system login screen.
- Channel-managed links for the homepage, Shop, public product categories, and
  public catalog-visible products.
- Opaque `/go/{token}` links that record a visit, set first-party attribution,
  and redirect to a clean canonical destination.
- HPOS-compatible order metadata plus a reporting projection that follows
  order status changes and refunds.
- Order-list channel column and an audited manual assignment control.

The channel portal deliberately does not expose the attribution-window value.
WooCommerce's native order-attribution metadata remains untouched.

## Activation

Activation creates the plugin tables, the Channel Manager role, rewrite rules,
and a published page at `/channel-portal/` containing:

```text
[trovesia_channel_portal]
```

The plugin defaults to a 30-day last-valid-channel model. Direct traffic does
not overwrite a valid channel visit. Store administrators can change the
window at **Channels > Settings**.

## Consent integration

By default valid channel-link visits are recorded. A consent-management plugin
can suppress storage by returning `false` from:

```php
add_filter('trovesia_channel_can_track', '__return_false');
```

Use a conditional callback in production so tracking starts only when the
store's consent policy permits it.

## Data removal

Uninstall preserves channel, visit, and order-attribution data. To explicitly
remove all plugin tables and options during uninstall, define:

```php
define('TROVESIA_CHANNEL_REMOVE_DATA', true);
```
