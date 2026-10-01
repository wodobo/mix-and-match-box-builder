=== Mix & Match Box Builder by Wodobo Labs ===
Contributors: wodobolabs
Tags: woocommerce, mix and match, box builder, subscriptions, product configuration
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.8

A flexible customer-facing box builder that augments WooCommerce Mix and Match Products without replacing its commerce engine.

== Description ==

Mix & Match Box Builder is built by Wodobo Labs, a Wodobo initiative.

The plugin is designed specifically to AUGMENT WooCommerce Mix and Match Products. It is not a replacement for that extension and does not reimplement its core commerce rules.

WooCommerce Mix and Match Products remains the source of truth for:
* Container minimums and maximums
* Allowed child products
* Per-item/container pricing
* Validation
* Inventory behavior
* Cart and order parent/child structure
* Native "Not sold separately" behavior

Mix & Match Box Builder adds a more configurable customer-facing presentation layer, including responsive item grids, a live box summary, mobile drawer/status controls, subscription-plan presentation, design defaults, per-product design overrides, and optional content regions.

When WooCommerce Subscriptions / All Products for Subscriptions is present, the builder presents the native purchase plans rather than maintaining a separate subscription-pricing system.

The plugin keeps the native Mix & Match form mounted as a safe fallback. The native form is only visually hidden after the custom adapter initializes successfully. If the custom interface cannot initialize, the native Mix & Match interface remains available.

Theme integration is intentionally optional. The builder can inherit useful CSS variables from compatible themes (including Nectarblocks where available), but its core layout and design controls do not require Nectarblocks or a theme builder.

Tested baseline:
* WooCommerce 11.1.x
* WooCommerce Mix and Match Products 2.9.x
* WooCommerce Subscriptions 9.2.x
* Nectarblocks 3.3.x (optional integration only)

== Relationship to WooCommerce Mix and Match Products ==

This plugin should be thought of as a presentation and interaction layer on top of WooCommerce Mix and Match Products.

It intentionally does NOT modify the Mix and Match plugin, replace its product type, maintain a parallel pricing engine, or duplicate native commerce safeguards when the official extension already provides them.

That separation is deliberate: WooCommerce Mix and Match Products owns the transaction logic; Mix & Match Box Builder owns the enhanced storefront experience.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate WooCommerce Mix and Match Products.
3. Upload and activate Mix & Match Box Builder by Wodobo Labs.
4. Create/configure a native Mix & Match product using the official WooCommerce extension.
5. Visit WooCommerce > Box Builder to review compatibility status and global design defaults.
6. Optional: override design/content settings for an individual Mix & Match product under Product data > Box Builder Design and Box Builder Content.
7. Optional: use the "Mix & Match Box Builder" block for explicit placement. Automatic enhancement works without the block.

== Optional note inside Your Box ==

Edit a native Mix & Match product and open Product data > Box Builder Content > Below purchase options (inside Your Box). Enter an optional short section title and formatted message. Set the title to standard small text or a highlighted badge. Badge style offers square or pill corners plus the standard WordPress background/text color pickers. Leave either color blank to use the theme-aware default.

The note appears after the purchase options / frequency selector, before Number of boxes or Add My Box. It is shown for both purchase types and in both the desktop summary and mobile View Box drawer. An empty note is omitted entirely. No billing, plan selection, or pricing values are changed.

== Support and project information ==

Developed by Wodobo Labs, a Wodobo initiative.

More details: https://wodobolabs.com/

Help and support: https://wodobo.com/contact/

== Compatibility philosophy ==

The plugin uses capability checks rather than relying only on exact version numbers. New major releases of dependencies should be tested on staging before production use.

The internal plugin directory, block name, and existing PPBB setting keys are intentionally retained for upgrade continuity, so existing installations can update without losing settings or breaking previously inserted blocks.


== GitHub updates ==

Updates are offered via WordPress's native plugin-update interface from public GitHub Releases for https://github.com/wodobo/mix-and-match-box-builder.

Only a published, non-prerelease GitHub Release with a matching, attached WordPress-installable ZIP will be offered. For example, release v1.0.8 must contain the binary asset mix-and-match-box-builder-by-wodobo-labs-1.0.8.zip; GitHub's automatically generated "Source code" archives are never used as the update package. The ZIP must contain the unchanged picky-plate-box-builder/ plugin directory.

Version 1.0.5 introduced the GitHub updater. Install 1.0.5 or later once via Plugins > Add New > Upload Plugin > Replace current with uploaded. For later releases, publish both the updated source and the ZIP on GitHub, then use Dashboard > Updates > Check Again and click Update now. Automatic background updates are not enabled by this integration. If the GitHub API is unavailable, the installed plugin continues to run normally.

== Changelog ==

= 1.0.8 =
* Fix subscription display pricing by listening to the official Mix & Match/APFS calculated-price event.
* Keep one-time pricing anchored to the native Mix & Match container total when switching purchase options.
* Add a display-only fallback for native inherited percentage-discount plans if Woo exposes a zero parent/container amount before its calculated-price event is available.
* No changes to cart, checkout, renewal, inventory, or payment calculations.

= 1.0.7 =
* Removes custom one-time/subscription price reconstruction from the storefront builder. The builder now mirrors the native current amounts generated by WooCommerce Mix & Match Products and WooCommerce Subscriptions/APFS.
* Selecting Subscribe no longer inflates the displayed one-time price by reversing the subscription discount. The Box total and mobile total mirror the currently selected native purchase amount when Woo provides it.
* Subscription frequency labels remain presentation-only; no changes to checkout, renewal, payment, subscription-plan, Mix & Match validation, or inventory logic.


= 1.0.6 =
* Fixes subscription frequency labels in the custom box summary so dynamic Mix & Match containers no longer show the parent container's $0.00 base price in delivery-frequency text. Labels now use the native subscription plan period/interval data (for example, Every week, Every 2 weeks, Every month) while WooCommerce Subscriptions remains responsible for all actual pricing and billing.
* Corrects the Subscribe & Save percentage label so the discount displays with a single percent sign.
* No changes to subscription pricing calculations, renewal schedules, checkout, Mix & Match validation, or payment logic.


= 1.0.5 =
* Adds a GitHub Releases update checker using WordPress's native Update URI API; no separate updater plugin or credentials required for the public repository.
* Offers only the matching published release ZIP and preserves the existing plugin directory/settings. No changes to box selection, prices, cart, checkout, subscription plans, or renewals.


= 1.0.4 =
* Restores one natural page scroll across both desktop columns. Your Box remains sticky, but when its contents exceed the visible viewport, normal page scrolling reveals the lower content. No internal sidebar scrollbar and no wheel-event interception. Mobile drawer behavior is unchanged.
* Retains the narrowly scoped Cart/Checkout Block CSS-description cleanup from 1.0.3. No changes to WooCommerce products, subscription plans, prices, payments, or box rules.

= 1.0.3 =
* Keeps the desktop Your Box card and its attached sidebar content sticky but independently scrollable when taller than the visible window. The protein list continues to scroll with the main page; mobile drawer behavior is unchanged.
* Removes an identifiable Nectarblocks CSS leak from native Mix & Match container descriptions in WooCommerce Cart/Checkout Block Store API responses only when the CSS signature actually occurs. Preserves the native Edit selections link, any real stored short description, selection data, totals, subscriptions and payment data. Does not modify product descriptions or other cart items.
* Built directly on 1.0.2. No value calculation, extra marketing copy, or subscription-plan changes.


= 1.0.2 =
* Adds an optional product-specific, server-rendered content template inside Your Box between purchase options and Number of boxes / Add My Box, shown for both one-time and subscription choices on desktop and mobile.
* Adds a rich-text message and an optional small section title with standard-text or highlighted-badge appearance, square/pill shape, and native WordPress color pickers for badge background and text.
* Based directly on 1.0.0. Does not include the 1.0.1 default-plan/label changes and does not alter native Mix & Match, subscription pricing, payment, or renewal behavior.

= 1.0.0 =
* First production release for the tested WooCommerce / official Mix and Match Products integration.
* Includes all functionality and presentation improvements validated during the beta21 staging cycle, including theme-aware Cart Block Edit Selections buttons with white default text.
* No commerce logic changes from 0.1.0-beta21; official WooCommerce Mix and Match Products and WooCommerce Subscriptions remain responsible for pricing, validation, ordering, and renewals.

== 0.1.0-beta21 ==
* Uses white text on the native Mix & Match Edit Selections button by default, while retaining the existing theme-aware button background and hover behavior. Sites can override --ppbb-cart-edit-fg to select a different text color. No purchase or cart behavior changes.

== 0.1.0-beta20 ==
* Corrects the WooCommerce Cart Block edit-action selector based on inspected storefront markup (`.wc-block-cart-item__edit-link` inside an `.is-mnm-container` row); previous betas targeted a class that does not exist on that anchor. Retains theme-aware styling, hides only extraneous line breaks inside the link, and leaves native edit URLs and commerce logic unchanged.

== 0.1.0-beta19 ==
* Fixes Edit Selections styling in Cart Block pages that lack classic WooCommerce cart body markers or bypass is_cart(). Loads a small action-only stylesheet on public pages with selectors restricted to the native Mix & Match edit links. Does not alter links, cart logic, pricing, or subscriptions.

== 0.1.0-beta18 ==
* Styles the native Edit selections action in both the WooCommerce Cart Block and classic cart. The previous styling covered classic cart only. Keeps URLs, functionality, checkout, pricing and subscriptions untouched.

== 0.1.0-beta17 ==
* Applies a cart-only theme-aware style to the official Mix & Match "Edit selections" link. The native link, edit workflow, pricing, quantities, and subscription logic are unchanged. Uses theme color variables where available and neutral fallbacks otherwise.

== 0.1.0-beta16 ==
* Respect WooCommerce’s native Sold individually setting on the Mix & Match parent product by omitting the Number of boxes control on desktop and in the mobile drawer. WooCommerce retains quantity enforcement.

== 0.1.0-beta15 ==
* Centers the quantity numeral in the product Details dialog.
* Restores desktop sticky behavior for the full Your Box + sidebar-content stack.
* Hardens product-title-size and tile-spacing overrides against aggressive theme CSS.
* Clarifies that per-product Automatic/Custom design choices override site-wide defaults.
* Slightly narrows protein quantity controls.

== Version 0.1.0-beta2 ==

Initial staging beta.


== 0.1.0-beta2 ==
* Uses Nectarblocks custom palette variables such as --accentPrimary when available.
* Compacts product cards with a 4:3 image ratio and softer selected state.
* Improves sticky summary behavior at narrower desktop widths.
* Uses the official Mix & Match/APFS recalculation event so subscription choices reflect the current configured box price.
* Normalizes modal typography while continuing to inherit the site font family.
* Adds an optional setting to hide the native Woo/Nectarblocks product gallery + title/price intro.

* beta3: Fix Nectarblocks compact-page mode so it does not hide the builder.

* beta4: Moves mobile overlays to the document body to avoid footer/theme stacking conflicts, and collapses multiple subscription plans into one Subscribe choice with a delivery-frequency selector.

* beta5: Documents and detects Mix & Match 2.9+ native Not Sold Separately support instead of duplicating box-only logic, and makes the mobile View Box status card more visually distinct with inset margins, rounded-card corners, and a stronger floating shadow.

* beta6: Moves core design controls into the plugin itself with global defaults under WooCommerce > Box Builder and per-product overrides in Product data > Box Builder Design. Adds configurable selection-slot columns, product image shape/aspect ratio, product grid columns, and mobile floating-bar prominence. Nectarblocks remains optional.
* beta7: Fixes non-circle image shapes by overriding theme pill radii, makes square/landscape imagery truly full bleed, makes Prominent mobile status match the expanded drawer footprint more closely, and replaces rigid tablet/mobile grid counts with width-aware responsive stepping (3 → 2, 4 → 3 → 2, 5 → 4 → 3 → 2).
* beta8: Makes image shape controls theme-proof by separating the visible media frame from the clickable button overlay, preventing theme button radii/masks from turning square and landscape images into pills.

* beta9: Refines Circle image mode into a borderless, centered presentation; strengthens quantity-button hover feedback; and adds an optional High Contrast mobile floating-box style with a dark reversed surface and light text.
* beta10: Adds global and per-product protein-title sizing with px/em/rem/vw/vh units, configurable tile spacing, and a standard WordPress color picker for the High Contrast mobile floating box.
* beta11: Adds per-product rich text above and below the complete box builder, with an optional accessible accordion for either region.
* beta12: Fixes title-size and tile-gap overrides against aggressive theme CSS, recenters quantity outputs, narrows protein quantity pills, resets mobile drawer scroll/top spacing, adds editable/hideable builder heading text, upgrades content editors to full WordPress rich-text controls, and splits lower content into main-column and sidebar-column regions for better SEO/content layout.

= 0.1.0-beta13 =
* Keeps desktop sidebar content attached to the sticky Your Box panel.
* Reorganizes per-product design overrides into paired two-column groups.
* Places High Contrast color controls beside the mobile floating-box setting.
* Narrows protein quantity controls for a less crowded tile layout.

= 0.1.0-beta14 =
* Rebrands the plugin as Mix & Match Box Builder by Wodobo Labs.
* Adds Wodobo Labs/Wodobo project and support information.
* Documents the plugin explicitly as an augmentation layer for WooCommerce Mix and Match Products, with native Mix & Match remaining the commerce source of truth.
* Keeps legacy internal identifiers during beta to preserve upgrade continuity and existing settings.
