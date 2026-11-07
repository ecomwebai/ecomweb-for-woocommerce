=== ecomweb for WooCommerce ===
Contributors: ecomweb
Tags: woocommerce, migration, products, import, ecomweb
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy your WooCommerce products into an ecomweb store: names, prices, variations, images, categories and tags.

== Description ==

ecomweb for WooCommerce copies your WooCommerce products into a store on [ecomweb](https://ecomweb.app). You sign in with your ecomweb account, choose one store, and the plugin sends your products in small batches in the background. Products arrive in ecomweb as drafts, so you can review them before they go live.

* Sign in with ecomweb: no password is typed into WordPress. You approve the sign-in on ecomweb.
* Copy all products not copied yet, or pick products on the WooCommerce Products list and use the **Copy to ecomweb** bulk action.
* Simple and variable products, with prices, stock, SKUs, weights, images, categories, tags and attributes.
* Copying again is safe. Products already copied are skipped, unless you tick **Overwrite products already copied** and confirm.
* A progress view and a list of products that need attention after each copy.
* English and Vietnamese.

Nothing is sent to ecomweb until you click **Sign in with ecomweb**.

== Installation ==

1. Install and activate WooCommerce 8.0 or later.
2. Install and activate ecomweb for WooCommerce.
3. Go to WooCommerce → ecomweb and click **Sign in with ecomweb**.
4. Approve the sign-in on ecomweb, then choose the store to copy to.
5. Click **Copy all products not copied yet**.

== Frequently Asked Questions ==

= Where do I edit my products after copying? =

In your ecomweb dashboard. Open the store, then Products. The plugin page links to each copied product. The copy is one-way: later edits in WooCommerce are not sent unless you copy again with **Overwrite products already copied**.

= What isn't copied? =

Orders, customers, reviews, coupons, pages and blog posts. Grouped and external (affiliate) products are not supported and are listed as such. Scheduled sale dates are not copied, and a product on sale is copied at its current sale price; the plugin lists these so you can recreate the sales as promotions in ecomweb.

= Does my site need to be public? =

For the smoothest copy, yes: ecomweb downloads your product images from your site. If ecomweb can't reach your site (a private or local site, or one behind a login), the plugin uploads the images itself instead. This is slower, and images larger than 10 MB are skipped. The plugin checks this for you, and **Recheck images** repeats the check.

= How do I revoke the plugin's access? =

Click **Disconnect** on the plugin page, or delete the plugin. Either revokes the sign-in. You can also open ecomweb, go to Profile → Sessions and sign out the device named "WooCommerce – your site address".

= What happens if I move or clone my site? =

If the site address changes, the plugin signs out without contacting ecomweb, so a clone can't use the original site's sign-in. Sign in again on the new site. The plugin keeps its own record of the site, so reconnecting the same site doesn't duplicate its products.

= What does uninstalling remove? =

The saved sign-in, the selected store and the copy state. It keeps the site's ID and the small marker on each product that remembers its ecomweb product, so reinstalling later doesn't create duplicates. Copied products stay in ecomweb.

= Can anyone with a WooCommerce login copy products? =

Anyone who can manage WooCommerce on the site can use the plugin as the ecomweb account that signed in. Only store owners and admins on ecomweb can copy.

== External services ==

This plugin connects to the ecomweb service to copy your products. The API is at `https://apibase.ecomweb.app` and the dashboard is at `https://ecomweb.app`. Both are run by ecomweb, the publisher of this plugin. Nothing is sent until the merchant clicks **Sign in with ecomweb**.

What is sent, and when:

* On sign-in: the device name "WooCommerce – <your site host>", so you can recognise it in Profile → Sessions.
* On choosing a store: the site's random ID (UUID), the site URL, the WooCommerce currency, the weight unit and the plugin version.
* On copy: for each selected product, its core fields (name, description, prices, stock, SKU, weight, status), image URLs or image files, and its category, tag and attribute names.
* On disconnect or uninstall: the sign-in token, so ecomweb can revoke it.
* Every request also carries the site address in the User-Agent header (`ecomweb-for-woocommerce/<version>; <site URL>`), as WordPress core's own requests do.

The ecomweb server also fetches `/wp-json/ecomweb/v1/probe` on your site to check that it can reach it. That endpoint returns only the site's random ID.

The sign-in token is stored encrypted in your database and is sent only to the ecomweb API.

ecomweb Terms of Service: https://ecomweb.app/pages/terms-of-service
ecomweb Privacy Policy: https://ecomweb.app/pages/privacy-policy

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
