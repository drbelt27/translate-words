# Extractable components

## SettingsMainComponent
- Source: admin/settings/views/src/components/main-component.jsx
- Category: layout
- Description: Settings body and informational sidebar.
- Extractable props: currentPage.
- Hardcoded: layout classes and tab mapping.
- New product panel use: none; preserve native WooCommerce editor shell.

## SettingsSidebar
- Source: admin/settings/views/src/components/sidebar.jsx
- Category: layout
- Description: Translation totals and supporting resources.
- Extractable props: none currently; data is localized globally.
- Hardcoded: labels, icons, layout and brand asset paths.
- New product panel use: none.

The scoped product panel needs inline field/button primitives, not these full-page layouts. No existing local WooCommerce editor shell is available to extract.
