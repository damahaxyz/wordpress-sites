# AROMAMATRIX Theme

Code-first English WooCommerce catalogue theme for `www.13799.com`.

## Responsibilities

- Site layout and visual design
- WordPress template hierarchy
- Block editor design tokens through `theme.json`
- WooCommerce presentation and theme support
- Responsive product-led landing page
- Official AROMAMATRIX logo and monochrome industrial design system

## Content setup

The landing page reads the four largest non-empty WooCommerce product
categories and the eight newest published products automatically. Until
products exist, it displays an English setup message to logged-in catalogue
editors and branded category placeholders to visitors.

For the strongest catalogue presentation, each product should have:

- An English product title and short description
- A portrait or square featured image on a neutral background
- A product category
- Optional price, gallery, attributes, and variations

Assign a menu to **Appearance → Menus → Primary menu** if the default English
navigation should be replaced.

Business logic should live in `aromamatrix-plugin`, not in this theme.
