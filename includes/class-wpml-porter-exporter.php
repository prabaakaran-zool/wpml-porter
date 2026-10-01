<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML Porter Exporter
 *
 * Exports any CPT (all languages) to a CSV file.
 * Columns: core fields + all taxonomy terms + all custom fields + WPML meta.
 */
class WPML_Porter_Exporter {

    /** @var string */
    private $post_type;

    /** @var string[] Taxonomy slugs attached to the CPT */
    private $taxonomies = [];

    /** @var string[] Custom field keys to export (empty = all) */
    private $meta_keys = [];

    /** @var string[] Core meta keys injected by WPML – skip these in raw meta */
    private $wpml_internal_meta = [
        '_wpml_media_duplicate',
        '_wpml_media_featured',
        'wpml_language',
    ];

    /**
     * @param string $post_type
     * @param array  $options {
     *     Optional.
     *     @type string[] $meta_keys     Specific meta keys to export. Default: all.
     *     @type string[] $taxonomies    Override taxonomy list. Default: auto-detected.
     * }
     */
    public function __construct( string $post_type, array $options = [] ) {
        $this->post_type  = sanitize_key( $post_type );
        $this->taxonomies = $options['taxonomies'] ?? get_object_taxonomies( $this->post_type );
        $this->meta_keys  = $options['meta_keys']  ?? [];
    }

    /**
     * Stream a CSV to the browser (triggers download).
     */
    public function stream_csv() {
        $filename = 'wpml-porter-export-' . $this->post_type . '-' . gmdate( 'Ymd-His' ) . '.csv';

        // Disable output buffering so large exports don't time out.
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo $this->build_csv();
        exit;
    }

    /**
     * Build the CSV content as a string (same logic as stream_csv(), just
     * written to an in-memory stream instead of straight to the browser).
     * Reused by the Full Package export.
     *
     * @return string
     */
    public function build_csv(): string {
        global $sitepress;

        $out = fopen( 'php://temp', 'r+' );

        // ── Collect all posts in every language ──────────────────────────
        $all_languages = $this->get_all_language_codes();
        $all_posts     = [];

        // Temporarily switch WPML to each language to fetch posts.
        foreach ( $all_languages as $lang ) {
            if ( $sitepress ) {
                $sitepress->switch_lang( $lang, true );
            }

            $posts = get_posts( [
                'post_type'      => $this->post_type,
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                'posts_per_page' => -1,
                'suppress_filters' => false,
            ] );

            foreach ( $posts as $post ) {
                $all_posts[ $post->ID ] = [ 'post' => $post, 'lang' => $lang ];
            }
        }

        if ( $sitepress ) {
            $sitepress->switch_lang( null, true ); // restore
        }

        // ── Discover all custom field keys ───────────────────────────────
        $meta_keys = $this->resolve_meta_keys( array_keys( $all_posts ) );

        // ── Build CSV header ─────────────────────────────────────────────
        $core_columns = [
            'ID',
            'post_title',
            'post_content',
            'post_excerpt',
            'post_status',
            'post_date',
            'post_author',
            'menu_order',
            'post_parent',
        ];

        $tax_columns  = array_map( fn( $t ) => 'tax:' . $t, $this->taxonomies );
        $meta_columns = array_map( fn( $k ) => 'meta:' . $k, $meta_keys );

        $wpml_columns = [
            '_wpml_import_language_code',
            '_wpml_import_source_language_code',
            '_wpml_import_translation_group',
        ];

        $header = array_merge( $core_columns, $tax_columns, $meta_columns, $wpml_columns );
        fputcsv( $out, $header );

        // ── Write rows ───────────────────────────────────────────────────
        foreach ( $all_posts as $post_id => $entry ) {
            /** @var WP_Post $post */
            $post = $entry['post'];
            $lang = $entry['lang'];

            // WPML translation details
            $trid            = $sitepress ? $sitepress->get_element_trid( $post_id, 'post_' . $this->post_type ) : '';
            $lang_details    = $sitepress ? $sitepress->get_element_language_details( $post_id, 'post_' . $this->post_type ) : null;
            $source_lang     = $lang_details->source_language_code ?? '';

            // The importer matches an existing post to update (instead of
            // inserting a duplicate) by translation_group + language — see
            // WPML_Porter_Importer::find_existing_post(). Without WPML active
            // (or for a post WPML hasn't assigned a trid to yet) $trid above
            // is empty, which left every re-imported row with a blank
            // translation group; find_existing_post() treats an empty group
            // as "no match" and process_row() then always inserts a brand
            // new post, so simply re-running an import (even on the very same
            // site) duplicated every single post instead of updating it.
            // Falling back to this post's own ID gives every row a stable,
            // non-empty identifier that round-trips correctly on re-import.
            if ( ! $trid ) {
                $trid = $post_id;
            }

            // Core
            $row = [
                $post->ID,
                $post->post_title,
                $this->relativize_urls_in_string( $post->post_content ),
                $this->relativize_urls_in_string( $post->post_excerpt ),
                $post->post_status,
                $post->post_date,
                $post->post_author,
                $post->menu_order,
                $post->post_parent,
            ];

            // Taxonomies (pipe-separated term slugs)
            foreach ( $this->taxonomies as $taxonomy ) {
                $terms = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'slugs' ] );
                $row[] = is_array( $terms ) ? implode( '|', $terms ) : '';
            }

            // Custom fields
            foreach ( $meta_keys as $key ) {
                $value = get_post_meta( $post_id, $key, true );

                // The featured image is stored as a raw attachment ID, which
                // is meaningless once imported into a different site/database
                // (that numeric ID may not exist, or may belong to a totally
                // different file there). Export the attachment's own relative
                // uploads path instead, so the importer can look the file up
                // by path on the destination and re-link the correct local
                // attachment (see WPML_Porter_Importer::import_featured_image()).
                if ( $key === '_thumbnail_id' && $value ) {
                    $attachment_url = wp_get_attachment_url( (int) $value );
                    $row[] = $attachment_url ? $this->relativize_urls_in_string( $attachment_url ) : '';
                    continue;
                }

                // Strip this site's own domain from any uploads/media URLs
                // buried in the value (plain string or nested array, e.g. an
                // ACF image field) so the export is portable across domains/
                // environments — only the relative /wp-content/uploads/... path
                // is stored.
                $value = $this->relativize_value( $value );

                // Serialize arrays/objects so they round-trip through CSV
                $row[] = is_array( $value ) || is_object( $value )
                    ? maybe_serialize( $value )
                    : $value;
            }

            // WPML meta
            $row[] = $lang;
            $row[] = $source_lang;
            $row[] = (string) $trid;

            fputcsv( $out, $row );
        }

        rewind( $out );
        $csv = stream_get_contents( $out );
        fclose( $out );

        return $csv;
    }

    // ────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Recursively strip this site's own domain from any URLs found inside a
     * meta value (string, or an array/object such as an ACF image field or
     * gallery). Leaves external/third-party URLs untouched.
     *
     * @param  mixed $value
     * @return mixed
     */
    private function relativize_value( $value ) {
        if ( is_string( $value ) ) {
            // A serialized value contains byte lengths. Replacing a URL in the
            // serialized string directly would leave those lengths stale and
            // make maybe_unserialize() fail on the destination site.
            if ( is_serialized( $value ) ) {
                $unserialized = maybe_unserialize( $value );
                return maybe_serialize( $this->relativize_value( $unserialized ) );
            }
            return $this->relativize_urls_in_string( $value );
        }
        if ( is_array( $value ) ) {
            return array_map( [ $this, 'relativize_value' ], $value );
        }
        if ( is_object( $value ) ) {
            foreach ( $value as $k => $v ) {
                $value->$k = $this->relativize_value( $v );
            }
        }
        return $value;
    }

    /**
     * Replace every occurrence of this site's absolute base URL (http or
     * https) inside a string with '', leaving the root-relative path behind
     * — e.g. "https://example.com/wp-content/uploads/2026/06/x.jpg" becomes
     * "/wp-content/uploads/2026/06/x.jpg". Safe to run on plain text/HTML
     * content (post_content, post_excerpt) as well as single-URL field
     * values; matching is scheme-agnostic so http/https mismatches between
     * how the URL was saved and the site's current scheme don't block it.
     *
     * @param  string $str
     * @return string
     */
    private function relativize_urls_in_string( string $str ): string {
        if ( $str === '' ) return $str;

        $home_path = rtrim( (string) parse_url( home_url(), PHP_URL_PATH ), '/' );
        $bases     = [ rtrim( home_url(), '/' ) => '' ];

        // site_url() can differ from home_url() on subdirectory installs.
        $bases[ rtrim( site_url(), '/' ) ] = '';

        // WPML may use a distinct domain or path per language. Those domains
        // are still internal, so make them portable too, while retaining a
        // language path (for example /fr) when one is configured.
        global $sitepress;
        if ( $sitepress ) {
            foreach ( array_keys( $sitepress->get_active_languages() ?: [] ) as $language ) {
                $language_home = rtrim( (string) apply_filters( 'wpml_home_url', home_url(), $language ), '/' );
                if ( $language_home === '' ) continue;
                $language_path = rtrim( (string) parse_url( $language_home, PHP_URL_PATH ), '/' );
                $replacement    = '';
                if ( $language_path !== $home_path && strpos( $language_path . '/', $home_path . '/' ) === 0 ) {
                    $replacement = substr( $language_path, strlen( $home_path ) );
                }
                $bases[ $language_home ] = $replacement;
            }
        }

        // Longest first prevents a site base from consuming a more-specific
        // language URL before its language segment can be preserved.
        uksort( $bases, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
        foreach ( $bases as $base => $replacement ) {
            $base_noscheme = preg_replace( '#^https?://#i', '', $base );
            if ( $base_noscheme !== '' ) {
                $str = preg_replace( '#https?://' . preg_quote( $base_noscheme, '#' ) . '#i', $replacement, $str );
            }
        }

        return $str;
    }

    /**
     * Return active WPML language codes, or just the default language.
     *
     * @return string[]
     */
    private function get_all_language_codes(): array {
        global $sitepress;
        if ( $sitepress ) {
            $languages = $sitepress->get_active_languages();
            return array_keys( $languages );
        }
        return [ get_locale() ];
    }

    /**
     * Get sorted list of meta keys for the given post IDs.
     *
     * @param  int[]    $post_ids
     * @return string[]
     */
    private function resolve_meta_keys( array $post_ids ): array {
        if ( ! empty( $this->meta_keys ) ) {
            return $this->meta_keys;
        }

        if ( empty( $post_ids ) ) {
            return [];
        }

        global $wpdb;
        $ids_placeholder = implode( ',', array_map( 'intval', $post_ids ) );

        $keys = $wpdb->get_col(
            "SELECT DISTINCT meta_key
             FROM {$wpdb->postmeta}
             WHERE post_id IN ({$ids_placeholder})
               AND meta_key NOT LIKE '\_%wpml%'
               AND meta_key NOT LIKE '\_wpml%'
             ORDER BY meta_key"
        );

        // Remove known internal / noisy keys
        return array_filter( $keys, function( $key ) {
            foreach ( $this->wpml_internal_meta as $pattern ) {
                if ( $key === $pattern ) {
                    return false;
                }
            }
            // Skip hidden keys that start with _  only if they look like internal WP keys
            // (keep plugin-registered _my_field style keys)
            return true;
        } );
    }
}
