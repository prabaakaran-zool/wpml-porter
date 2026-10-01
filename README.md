# wpml-porter

WPML Porter — a WordPress plugin for migrating Custom Post Type content between sites (e.g. staging → production, or between separate installs) with full WPML multilingual support.

It handles exporting and importing, in one package, everything a custom post type depends on:

Posts — all core fields, across every WPML language, as CSV
Taxonomies & terms — including ACF-managed taxonomies
Post type & taxonomy structure — ACF field groups and registrations, so the destination site doesn't need them pre-built
Featured images — bundled as actual files and restored into the destination media library
WPML translation links — posts are correctly linked as translations of each other on import, not just duplicated per language

Key design points:

Full Package export/import bundles all of the above into one .zip, applied in the right order automatically
Ajax batch processing so large imports (thousands of rows) don't hit server gateway/timeout limits, with self-adaptive batch sizing on failure
Cross-domain portability — media and URLs are stored as relative paths on export and resolved/re-absolutized correctly on import, regardless of subdirectory installs or differing Home/Site URLs
Re-import safe — re-running an import updates existing posts/taxonomies/post types rather than creating duplicates
Page builder aware — detects and converts Elementor content appropriately rather than letting builder-specific meta pollute the destination site

In short: it's a migration/sync tool for moving a specific CPT (and everything attached to it) from one WordPress+WPML site to another, reliably and repeatably.
