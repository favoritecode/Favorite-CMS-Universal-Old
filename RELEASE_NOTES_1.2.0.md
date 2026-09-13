# Favorite CMS Universal v1.2.0

Favorite CMS Universal v1.2.0 is a professional UI and dark-mode release built on the verified v1.1.0 release package. It preserves every existing feature while modernizing the shared core interface.

## Highlights

- Complete dark mode for the CMS admin, installer flow, installation-success screen, and bundled default website theme.
- Automatic operating-system theme detection and persistent light/dark preferences.
- Professional shared styling for admin cards, tables, forms, notices, navigation, typography, spacing, focus states, and hover states.
- Responsive core admin navigation for desktop, tablet, and mobile.
- Accessible theme toggles, keyboard focus indicators, semantic pressed state, and reduced-motion support.

## Bug fixes

- Admin pages no longer depend on a desktop-width sidebar and remain navigable on narrow displays.
- Tables, forms, notices, selected rows, filters, and secondary buttons now retain readable contrast in dark mode.
- Saved theme preferences are applied before page paint, preventing light/dark flashing during navigation.
- Theme toggle text, icon, and `aria-pressed` state now stay synchronized.
- Users without a saved preference now get a safe fallback based on their operating-system setting.

## Upgrade and compatibility

- No database migration is required.
- No feature, route, permission, plugin interface, theme capability, or content workflow was removed.
- Existing user content and settings remain compatible.

## Verification

- Source release inspected: `Favorite-CMS-Universal-v1.1.0.zip`.
- Verified source SHA-256: `b2fd53e92d54785e9f9d4ce3d0251a821fe7a778066354e0a4a5c29aad144578`.
- PHP syntax and automated PHPUnit checks were run against the updated codebase.
