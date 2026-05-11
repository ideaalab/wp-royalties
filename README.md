# WP Royalties

A specialized financial plugin to automate royalties and commission tracking for collaborators based on WooCommerce sales. It bridges the gap between e-commerce orders and partner payouts.

| | |
|---|---|
| **Slug** | `wp-royalties` |
| **Version** | 3.2.8 |
| **Author** | IDEAA Lab — Michael Di Desidero |
| **Requires WP** | 5.8+ |
| **Requires PHP** | 7.4+ |
| **Text Domain** | `ial-royalties` |
| **License** | GPL-2.0-or-later |

## Features

### 1. Royalty Rules Engine

- **User-Product Associations (`ial_user_prod_assoc`):** Define rules linking a **WooCommerce Product** to a **WordPress User** (Collaborator).
- **Calculation Methods:**
  - **Fixed Amount:** A flat currency amount per unit sold.
  - **Percentage:** A percentage commission calculated on **Gross Price** or **Net Sales** (post-tax).

### 2. Automated Tracking

- **Trigger:** Hooks into `woocommerce_order_status_changed`.
- **Logic:** When an order reaches a specific status (configurable, default: `Completed`), the system scans for applicable Royalty Rules.
- **Idempotency:** Includes safeguards to ensure commissions are generated only once per order, even if the status changes multiple times.
- **Recording:** Generates a **Royalty Record (`ial_royalty_record`)** linking the Order, Product, and Collaborator.

### 3. Payout Management

- **Status Tracking:** Records are marked as **Paid** or **Unpaid**.
- **Admin Badge:** A notification badge on the admin menu shows the current count of Unpaid records.
- **Bulk Actions:** Admins can bulk select records to:
  - Mark as Paid.
  - Mark as Unpaid.
  - Add internal notes.
- **Excel Export:** Built-in tool to export filtered reports to `.xls` for accounting purposes.

### 4. Notification System

- **Transactional Emails:** Automatically emails both Admin and Collaborator when:
  - A new royalty is earned (Order Completed).
  - A payout is processed (Marked as Paid).
- **Customizable Templates:** Email subjects and bodies can be customized in **Royalties > Settings** using dynamic placeholders (e.g., `{amount}`, `{product_name}`).

### 5. Collaborator Dashboard

- **Frontend Access:** A shortcode `[ial_collaborator_dashboard]` provides partners with a transparent view of their earnings.
- **WooCommerce Integration:** Adds a **"Royalties"** tab to the WooCommerce "My Account" area for collaborators.
- **Statistics:** Displays total earned, pending payouts, and historical data tables.

## Installation

### Option A — Upload the release ZIP

1. Download the latest `wp-royalties-vX.Y.Z.zip` from the [Releases](https://github.com/ideaalab/wp-royalties/releases) page.
2. In WordPress: **Plugins → Add New → Upload Plugin**, pick the ZIP, install, activate.

### Option B — Clone into `wp-content/plugins/`

```bash
cd wp-content/plugins/
git clone https://github.com/ideaalab/wp-royalties.git
```

Then activate **WP Royalties** from the Plugins screen.

### Configuration

Go to **Royalties → Settings**. Select the order status that triggers the royalty calculation (usually *Completed*).

## User Guide

1. **Define a Rule:** Go to *Royalties → Add New Association*. Select the Collaborator, the Product, and define the rate (e.g., 10%).
2. **Process Orders:** Manage WooCommerce orders as usual. When an order matches the trigger status, a Royalty Record is automatically created.
3. **Payouts:**
   - Go to *Royalties → Royalty Records*.
   - Filter by *Unpaid*.
   - Select the records you have paid externally.
   - Use the **Mark as Paid** Bulk Action. This updates the status and notifies the collaborator.

## Updates

This plugin ships with [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) wired to this GitHub repository. New releases tagged here appear in the regular **WordPress → Updates** screen — no manual reinstall needed.

> **One-off:** the *first* version installed must be installed manually (Option A or B above). After that, every future tag pushed to this repo will be picked up automatically.

## Technical Notes

- **Caching:** Implements smart caching for the *Unpaid* admin badge to prevent DB stress on every page load.
- **Logging:** Uses `WC_Logger` for debugging automation logic (logs visible in **WooCommerce → Status → Logs**).
- **Security:** Capability checks (`edit_others_posts`) and nonces for all manual actions and exports.

## Changelog

### 3.2.8

- **Fix:** `IdeaaLab_Royalty_Emails` is now a singleton; bulk actions no longer instantiate a second copy that double-registered listeners on `ial_royalty_record_created` and `ial_royalty_paid_status_changed`.
- **Fix:** the *Mark as Paid* / *Mark as Paid & Add Note* bulk actions now fire `do_action('ial_royalty_paid_status_changed', $post_id)` like the meta-box save does — third-party listeners receive bulk events consistently, and the action only fires on real 0→1 transitions to avoid spam.
- **Fix:** the *Unpaid* admin filter now also matches records that have no `paid` meta at all (legacy / pre-3.x records), matching the menu badge count.
- **Fix:** initialize `$collab_name` in the Excel export so rows with unresolvable collaborators export as `—` instead of producing a PHP notice and an empty cell.
- **Cleanup:** remove dead JS validation block for the non-existent `ial_email_campaign` CPT.
- **Header:** rewrite the `Description:` field to clearly state that the plugin automates royalty calculation and payouts for WooCommerce product collaborators.

### 3.2.7

- **Security:** added missing `current_user_can('edit_post')` check in the meta-box `save_post` handler.
- **Security:** the *Send Test Email* button no longer silently overwrites the saved template — it now sends only the current editor content without persisting it. Click *Save Changes* explicitly to keep edits.
- **Security:** the manual *Resend / Send Paid Notification* AJAX endpoint now requires `edit_others_posts` (was `edit_posts`).
- **Hardening:** correct `wp_unslash()` ordering in the test-email AJAX handler (was `stripslashes` after sanitization).

### 3.2.6

- Initial public release on GitHub.
- Bundled GitHub-based auto-updater (plugin-update-checker v5.6).

## License

GPL-2.0-or-later. See plugin header for details.
