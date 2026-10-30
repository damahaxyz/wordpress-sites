# Trovesia Channel

First-party channel links and WooCommerce order attribution for Trovesia.

## Included

- Store-admin channel management and a global attribution-window setting.
- Explicit login-email, username, and password fields. Store administrators
  must set a new account's initial password (at least six characters), then
  share it with the manager. No password setup email is sent. They can also
  reset an existing manager's password; leaving it blank preserves the password.
- Editing a channel pre-fills its assigned manager's login email and username.
  Passwords are never pre-filled because WordPress stores only password hashes.
- Creating and editing a channel use one admin modal, so channel details and
  the optional manager account can be saved together without leaving the list.
- A dedicated `trovesia_channel_manager` role and private frontend portal.
- A responsive, branded sign-in experience and channel dashboard with metric
  cards, period filters, campaign tools, status badges, and mobile tables.
- Traffic, unique visitors, paid orders and conversion rate share one compact
  card. Revenue and refunds have separate cards; refunds include fully refunded
  orders within the selected order-creation period.
- Store administrators configure a per-channel commission ratio from 0 to 1
  (up to four decimals), defaulting to 0.2 (20%). This stores the configuration;
  it does not perform commission calculation or payouts.
- Tracking links include a Copy button with success feedback and a manual-copy
  fallback when the browser clipboard API is unavailable.
- The portal separates overview/orders from tracking-link creation and the link
  library in two tabs. Attributed orders are paginated at 20 rows per page while
  preserving the selected reporting period.
- Invalid portal credentials return to the branded channel sign-in card with a
  generic error instead of exposing WordPress's system login screen.
- A Reset password dialog next to Sign out verifies the current password and
  matching new passwords (at least six characters). It keeps the current browser
  signed in, revokes other sessions, checks CSRF and channel membership, and
  limits incorrect current-password attempts to five per 15 minutes.
- Channel-managed links for the homepage, Shop, public product categories, and
  public catalog-visible products.
- Only paused links can be deleted, after a confirmation dialog. Deletion is
  authenticated POST with a per-link nonce and an atomic ownership/status check.
  Deleted links are hidden, cannot be reactivated, and keep their tokens reserved
  and historical visit/order statistics intact.
- Short `https://trovesia.com/s/{token}/` links with six alphanumeric characters
  and database-enforced uniqueness that record a visit, set first-party attribution,
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
