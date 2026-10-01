<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML Porter – Taxonomy Terms with full WPML translation mapping
 *
 * Export strategy:
 *   - Query WPML's icl_translations table directly to discover EVERY
 *     translated term_taxonomy_id for each trid group.
 *   - Each CSV row = one language version of a term.
 *   - Rows in the same trid group are linked by _wpml_term_trid.
 *   - Original-language row has _wpml_term_source_language = '' (blank).
 *   - Translation rows have _wpml_term_source_language = original lang code.
 *
 * Import strategy:
 *   - Two passes:
 *       Pass 1  – insert/update every term, record new term_taxonomy_id.
 *       Pass 2  – set_element_language_details for every row, using a
 *                 trid remapping table (old trid → new trid) so WPML
 *                 properly links translation groups on the destination site.
 *   - The original-language row in each group is processed first (source_lang blank),
 *     which causes WPML to create a new trid; translations then reference it.
 */
class WPML_Porter_Terms {

    // ─────────────────────────────────────────────────────────────────────
    // EXPORT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Stream a Terms CSV to the browser.
     *
     * When WPML is active every language version of every term gets its own row.
     * Without WPML, only the default-language terms are exported (no WPML columns).
     *
     * @param string[] $taxonomies
     * @param bool     $include_acf
     */
    public static function export_terms_csv( array $taxonomies, bool $include_acf = true ): void {
        $filename = 'wpml-porter-terms-' . implode( '-', array_slice( $taxonomies, 0, 3 ) )
                  . '-' . gmdate( 'Ymd-His' ) . '.csv';

        while ( ob_get_level() ) ob_end_clean();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo self::build_terms_csv( $taxonomies, $include_acf );
        exit;
    }

    /**
     * Build the terms CSV content as a string (same logic as export_terms_csv(),
     * just written to an in-memory stream instead of straight to the browser).
     * Reused by the Full Package export.
     *
     * @param  string[] $taxonomies
     * @param  bool     $include_acf
     * @return string
     */
    public static function build_terms_csv( array $taxonomies, bool $include_acf = true ): string {
        global $wpdb, $sitepress;

        $out = fopen( 'php://temp', 'r+' );

        $has_wpml = (bool) $sitepress;

        // ── 1. Collect all term rows (all languages) ──────────────────────

        $entries = []; // array of { term, taxonomy, lang, source_lang, trid, ttid }

        if ( $has_wpml ) {
            // Pull directly from icl_translations so we get EVERY language version,
            // not just what the current active language exposes.
            foreach ( $taxonomies as $tax ) {
                $element_type = 'tax_' . $tax;

                // All icl_translations rows for this taxonomy
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT t.trid,
                            t.element_id   AS ttid,
                            t.language_code,
                            t.source_language_code
                     FROM   {$wpdb->prefix}icl_translations t
                     WHERE  t.element_type = %s",
                    $element_type
                ) );

                foreach ( $rows as $r ) {
                    // term_taxonomy_id → term object
                    $tt_row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s",
                        $r->ttid, $tax
                    ) );
                    if ( ! $tt_row ) continue;

                    $term = get_term( (int) $tt_row->term_id, $tax );
                    if ( ! $term || is_wp_error( $term ) ) continue;

                    $entries[] = [
                        'term'        => $term,
                        'taxonomy'    => $tax,
                        'lang'        => $r->language_code,
                        'source_lang' => (string) ( $r->source_language_code ?? '' ),
                        'trid'        => (string) $r->trid,
                        'ttid'        => (int) $r->ttid,
                    ];
                }
            }

            // Sort: originals (source_lang='') before translations so parents import first
            usort( $entries, function( $a, $b ) {
                // empty source_lang = original, comes first
                $a_orig = $a['source_lang'] === '' ? 0 : 1;
                $b_orig = $b['source_lang'] === '' ? 0 : 1;
                if ( $a_orig !== $b_orig ) return $a_orig - $b_orig;
                // then sort by parent (0 first)
                return $a['term']->parent - $b['term']->parent;
            } );

        } else {
            // No WPML – plain terms, ordered parents-first
            foreach ( $taxonomies as $tax ) {
                $terms = get_terms( [
                    'taxonomy'   => $tax,
                    'hide_empty' => false,
                    'orderby'    => 'parent',
                    'order'      => 'ASC',
                ] );
                if ( is_wp_error( $terms ) ) continue;
                foreach ( $terms as $term ) {
                    $entries[] = [
                        'term'        => $term,
                        'taxonomy'    => $tax,
                        'lang'        => '',
                        'source_lang' => '',
                        'trid'        => '',
                        'ttid'        => $term->term_taxonomy_id,
                    ];
                }
            }
        }

        // ── 2. Collect meta keys ──────────────────────────────────────────

        $all_term_objects = array_column( $entries, 'term' );
        $meta_keys        = self::get_all_term_meta_keys( $all_term_objects );
        if ( $include_acf ) {
            $acf_keys  = self::get_acf_term_field_keys( $taxonomies );
            $meta_keys = array_values( array_unique( array_merge( $meta_keys, $acf_keys ) ) );
        }

        // ── 3. Build parent-slug lookup (term_id → slug, per taxonomy) ────

        $slug_by_tid = []; // "taxonomy:term_id" => slug
        foreach ( $all_term_objects as $t ) {
            $slug_by_tid[ $t->taxonomy . ':' . $t->term_id ] = $t->slug;
        }

        // ── 4. Write CSV header ───────────────────────────────────────────

        $header = [
            'term_id', 'taxonomy', 'slug', 'name', 'description',
            'parent_slug', 'term_order', 'count',
        ];
        foreach ( $meta_keys as $k ) {
            $header[] = 'meta:' . $k;
        }
        if ( $has_wpml ) {
            $header[] = '_wpml_term_language';
            $header[] = '_wpml_term_source_language';
            $header[] = '_wpml_term_trid';
            $header[] = '_wpml_term_ttid';   // term_taxonomy_id (source site, for reference)
        }
        fputcsv( $out, $header );

        // ── 5. Write rows ─────────────────────────────────────────────────

        foreach ( $entries as $entry ) {
            $term = $entry['term'];

            $parent_slug = '';
            if ( $term->parent ) {
                $parent_slug = $slug_by_tid[ $term->taxonomy . ':' . $term->parent ] ?? '';
            }

            $row = [
                $term->term_id,
                $entry['taxonomy'],
                $term->slug,
                $term->name,
                self::relativize_urls_in_string( $term->description ),
                $parent_slug,
                get_term_meta( $term->term_id, 'order', true ) ?: 0,
                $term->count,
            ];

            foreach ( $meta_keys as $key ) {
                $val   = get_term_meta( $term->term_id, $key, true );
                $val   = self::relativize_value( $val );
                $row[] = is_array( $val ) || is_object( $val )
                    ? maybe_serialize( $val )
                    : (string) $val;
            }

            if ( $has_wpml ) {
                $row[] = $entry['lang'];
                $row[] = $entry['source_lang'];
                $row[] = $entry['trid'];
                $row[] = $entry['ttid'];
            }

            fputcsv( $out, $row );
        }

        rewind( $out );
        $csv = stream_get_contents( $out );
        fclose( $out );

        return $csv;
    }

    // ─────────────────────────────────────────────────────────────────────
    // IMPORT
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Import terms from parsed CSV rows.
     *
     * Two-pass algorithm:
     *   Pass 1 – insert/update every term, collect new ttid per row.
     *   Pass 2 – call set_element_language_details using a trid remap table
     *            (old source-site trid → new dest-site trid).
     *
     * @param  array  $rows          Associative rows (header => value)
     * @param  string $default_lang  Fallback when _wpml_term_language column is absent/blank
     * @return array  { counts{inserted,updated,linked,skipped}, errors[], warnings[] }
     */
    public static function import_terms( array $rows, string $default_lang = 'en' ): array {
        global $sitepress;

        $counts   = [ 'inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0 ];
        $errors   = [];
        $warnings = [];

        $has_wpml     = (bool) $sitepress;
        $has_wpml_col = ! empty( $rows[0]['_wpml_term_language'] ?? null )
                        || array_key_exists( '_wpml_term_language', $rows[0] ?? [] );

        // Sort: originals (source_lang blank) before translations, parents before children
        usort( $rows, function( $a, $b ) {
            $a_orig = ( ( $a['_wpml_term_source_language'] ?? '' ) === '' ) ? 0 : 1;
            $b_orig = ( ( $b['_wpml_term_source_language'] ?? '' ) === '' ) ? 0 : 1;
            if ( $a_orig !== $b_orig ) return $a_orig - $b_orig;
            $a_par  = ( $a['parent_slug'] ?? '' ) === '' ? 0 : 1;
            $b_par  = ( $b['parent_slug'] ?? '' ) === '' ? 0 : 1;
            return $a_par - $b_par;
        } );

        // slug_to_id["taxonomy:slug"] = term_id  (within this import session)
        $slug_to_id = [];

        // WPML trid remap: old_trid (string from CSV) → new_trid (int on dest site)
        // Populated during Pass 2 when we call set_element_language_details.
        $trid_remap = [];

        // ── Pass 1: insert / update terms ────────────────────────────────

        // Store per-row data needed for Pass 2
        $pass2_data = [];

        foreach ( $rows as $idx => $row ) {
            $taxonomy = sanitize_key( $row['taxonomy'] ?? '' );
            $slug     = sanitize_title( $row['slug'] ?? '' );
            $name     = sanitize_text_field( $row['name'] ?? '' );

            if ( ! $taxonomy || ! $slug || ! $name ) {
                $errors[] = "Row $idx skipped – missing taxonomy/slug/name (slug='" . ( $row['slug'] ?? '?' ) . "')";
                $counts['skipped']++;
                continue;
            }

            if ( ! taxonomy_exists( $taxonomy ) ) {
                $warnings[] = "Taxonomy not registered on this site – skipped: $taxonomy";
                $counts['skipped']++;
                continue;
            }

            // WPML filters get_term_by()/get_terms() to whatever its "current
            // language" is, for any taxonomy set to a Translatable mode. Rows
            // for many languages are processed back to back here without that
            // ever changing away from the site default, so an existing term
            // that's genuinely in this row's language can be invisible to the
            // lookups below — get_term_by() reports "not found" even though a
            // matching term already exists — which both mis-resolves parents
            // and makes the "insert or update" check below insert a new term
            // instead of reusing the existing one. Switching to this row's own
            // language for the duration of its lookups keeps them consistent
            // with what was actually exported for that language.
            $row_lang = sanitize_text_field( $row['_wpml_term_language'] ?? '' ) ?: $default_lang;
            if ( $has_wpml && $row_lang ) {
                $sitepress->switch_lang( $row_lang, true );
            }

            // Parent resolution
            $parent_id   = 0;
            $parent_slug = trim( $row['parent_slug'] ?? '' );
            if ( $parent_slug !== '' ) {
                $parent_id = $slug_to_id[ $taxonomy . ':' . $parent_slug ] ?? 0;
                if ( ! $parent_id ) {
                    $pt        = get_term_by( 'slug', $parent_slug, $taxonomy );
                    $parent_id = $pt ? $pt->term_id : 0;
                }
                if ( ! $parent_id ) {
                    $warnings[] = "Parent '$parent_slug' not found for '$slug' ($taxonomy) – inserted as root.";
                }
            }

            // Insert or update
            $existing = get_term_by( 'slug', $slug, $taxonomy );

            if ( $existing ) {
                wp_update_term( $existing->term_id, $taxonomy, [
                    'name'        => $name,
                    'description' => wp_kses_post( self::absolutize_urls_in_string( $row['description'] ?? '' ) ),
                    'parent'      => $parent_id,
                ] );
                $term_id = $existing->term_id;
                $counts['updated']++;
            } else {
                $result = wp_insert_term( $name, $taxonomy, [
                    'slug'        => $slug,
                    'description' => wp_kses_post( self::absolutize_urls_in_string( $row['description'] ?? '' ) ),
                    'parent'      => $parent_id,
                ] );
                if ( is_wp_error( $result ) ) {
                    $errors[] = "Insert failed for '$name' ($taxonomy): " . $result->get_error_message();
                    $counts['skipped']++;
                    continue;
                }
                $term_id = (int) $result['term_id'];
                $counts['inserted']++;
            }

            $slug_to_id[ $taxonomy . ':' . $slug ] = $term_id;

            // Term meta / ACF fields
            foreach ( $row as $col => $val ) {
                if ( strpos( $col, 'meta:' ) !== 0 ) continue;
                $meta_key = substr( $col, 5 );
                if ( $meta_key === '' || $val === '' ) continue;
                update_term_meta( $term_id, $meta_key, self::absolutize_value( maybe_unserialize( $val ) ) );
            }

            // Stash for Pass 2
            $term_obj         = get_term( $term_id, $taxonomy );
            $new_ttid         = $term_obj ? (int) $term_obj->term_taxonomy_id : 0;
            $pass2_data[$idx] = [
                'taxonomy'    => $taxonomy,
                'term_id'     => $term_id,
                'new_ttid'    => $new_ttid,
                'lang'        => sanitize_text_field( $row['_wpml_term_language']        ?? $default_lang ),
                'source_lang' => sanitize_text_field( $row['_wpml_term_source_language'] ?? '' ),
                'old_trid'    => sanitize_text_field( $row['_wpml_term_trid']            ?? '' ),
            ];
        }

        if ( $has_wpml ) {
            $sitepress->switch_lang( null, true ); // restore
        }

        // ── Pass 2: WPML language + translation-group linking ─────────────

        if ( $has_wpml && $has_wpml_col ) {

            // Process originals first (source_lang=''), then translations.
            // Originals create the trid on the destination site; translations reference it.
            $originals    = array_filter( $pass2_data, fn( $d ) => $d['source_lang'] === '' );
            $translations = array_filter( $pass2_data, fn( $d ) => $d['source_lang'] !== '' );

            foreach ( array_merge( $originals, $translations ) as $d ) {
                if ( ! $d['new_ttid'] || ! $d['lang'] ) continue;

                $element_type = 'tax_' . $d['taxonomy'];
                $old_trid     = $d['old_trid'];
                $is_original  = $d['source_lang'] === '';

                if ( $is_original ) {
                    // For the original: pass trid=null so WPML assigns (or reuses) a trid.
                    // Then capture the trid it created/found for use by translations.
                    $sitepress->set_element_language_details(
                        $d['new_ttid'],
                        $element_type,
                        null,            // let WPML assign trid
                        $d['lang'],
                        null             // no source language
                    );

                    if ( $old_trid !== '' ) {
                        // Retrieve the trid WPML just assigned
                        $new_trid = $sitepress->get_element_trid( $d['new_ttid'], $element_type );
                        if ( $new_trid ) {
                            $trid_remap[ $old_trid ] = (int) $new_trid;
                        }
                    }
                    $counts['linked']++;

                } else {
                    // Translation row — look up the remapped trid
                    $new_trid = isset( $trid_remap[ $old_trid ] ) ? $trid_remap[ $old_trid ] : null;

                    $sitepress->set_element_language_details(
                        $d['new_ttid'],
                        $element_type,
                        $new_trid,       // mapped trid (or null → WPML assigns a new one)
                        $d['lang'],
                        $d['source_lang']
                    );

                    // If we didn't have a remap yet (e.g. original wasn't in this file),
                    // capture whatever trid was just set so siblings can share it.
                    if ( $new_trid === null && $old_trid !== '' ) {
                        $assigned = $sitepress->get_element_trid( $d['new_ttid'], $element_type );
                        if ( $assigned ) {
                            $trid_remap[ $old_trid ] = (int) $assigned;
                        }
                    }
                    $counts['linked']++;
                }
            }
        }

        return compact( 'counts', 'errors', 'warnings' );
    }

    // ─────────────────────────────────────────────────────────────────────
    // STRUCTURE EXPORT / IMPORT  (CPT + Taxonomy args + ACF groups)
    // ─────────────────────────────────────────────────────────────────────

    public static function export_structure_json( array $post_types, array $taxonomies, bool $include_acf = true ): void {
        $filename = 'wpml-porter-structure-' . gmdate( 'Ymd-His' ) . '.json';

        while ( ob_get_level() ) ob_end_clean();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo wp_json_encode(
            self::get_structure_data( $post_types, $taxonomies, $include_acf ),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    /**
     * Build the structure data array (ACF post type / taxonomy definitions +
     * field groups) for the given post types and taxonomies. Pure data
     * builder — no output, no headers — so it can be reused by both the
     * standalone Structure export and the Full Package export.
     *
     * @param  string[] $post_types
     * @param  string[] $taxonomies
     * @param  bool     $include_acf
     * @return array
     */
    public static function get_structure_data( array $post_types, array $taxonomies, bool $include_acf = true ): array {
        $data = [
            'exported_at' => gmdate( 'c' ),
            'wpml_porter' => WPML_PORTER_VERSION,
            'post_types'  => [],
            'taxonomies'  => [],
            'acf_groups'  => [],
        ];

        global $wp_post_types, $wp_taxonomies;

        $data['acf_post_types'] = [];
        $data['acf_taxonomies'] = [];
        $data['skipped_builtin_post_types'] = [];
        $data['skipped_builtin_taxonomies'] = [];

        foreach ( $post_types as $slug ) {
            // Never register WordPress's own built-in post types (post, page,
            // etc.) as ACF post types. Those are core WordPress content types,
            // not custom structure — ACF has no business managing them, and
            // doing so would clutter ACF → Post Types with entries the site
            // owner never asked for. Content (the actual posts/pages) is
            // still exported/imported normally — only the registration step
            // is skipped.
            if ( self::is_builtin_post_type( $slug ) ) {
                $data['skipped_builtin_post_types'][] = $slug;
                continue;
            }

            // Prefer the ACF-native definition (the exact settings ACF itself
            // stores for this post type, including its ACF "key"). Registering
            // from this on import keeps the type inside ACF's own management —
            // it shows up in ACF → Post Types exactly like any other ACF-defined
            // type, instead of competing with ACF via a separate raw registration.
            $acf_def = self::get_acf_post_type_definition( $slug );

            // If the source site registered this CPT with plain code instead
            // of ACF, there's no native ACF definition to copy. Build an
            // equivalent ACF-format definition from the raw registration args
            // so it still imports as a first-class, ACF-managed post type.
            if ( ! $acf_def && isset( $wp_post_types[ $slug ] ) ) {
                $acf_def = self::build_acf_post_type_definition(
                    $slug,
                    self::extract_post_type_args( $wp_post_types[ $slug ] )
                );
            }

            if ( $acf_def ) {
                $data['acf_post_types'][ $slug ] = $acf_def;
            }
        }

        foreach ( $taxonomies as $slug ) {
            // Same reasoning as above: category, post_tag, and any other
            // WordPress built-in taxonomy are core WordPress structure, not
            // custom ACF configuration. Terms in these taxonomies are still
            // exported/imported normally — only the registration step is
            // skipped.
            if ( self::is_builtin_taxonomy( $slug ) ) {
                $data['skipped_builtin_taxonomies'][] = $slug;
                continue;
            }

            $acf_def = self::get_acf_taxonomy_definition( $slug );

            if ( ! $acf_def && isset( $wp_taxonomies[ $slug ] ) ) {
                $acf_def = self::build_acf_taxonomy_definition(
                    $slug,
                    self::extract_taxonomy_args( $wp_taxonomies[ $slug ] )
                );
            }

            if ( $acf_def ) {
                $data['acf_taxonomies'][ $slug ] = $acf_def;
            }
        }

        if ( $include_acf && function_exists( 'acf_get_field_groups' ) ) {
            foreach ( acf_get_field_groups() as $group ) {
                // Only export field groups that are assigned to one of the
                // selected post types. Exporting ALL groups would write
                // unrelated field JSON files on import.
                if ( ! self::field_group_applies_to_post_types( $group, $post_types ) ) {
                    continue;
                }
                $data['acf_groups'][] = [
                    // ACF's numeric IDs are local database IDs. Keeping them
                    // in an export can make an import update an unrelated
                    // record that happens to have the same ID on the target.
                    // ACF keys, unlike IDs, are portable and are retained.
                    'group'  => self::strip_acf_database_ids( $group ),
                    'fields' => self::strip_acf_database_ids( acf_get_fields( $group['key'] ) ?: [] ),
                ];
            }
        }

        return $data;
    }

    public static function import_structure( array $data ): array {
        $result = [
            'registered_cpts'  => 0,
            'registered_taxes' => 0,
            'acf_imported'     => 0,
            'manual_cpts'      => [],
            'manual_taxes'     => [],
            'skipped_builtin'  => [],
            'errors'           => [],
        ];

        // Belt-and-braces: Local JSON is already disabled permanently for the
        // whole site (see wpml-porter.php), but make sure nothing can write
        // a JSON file during this import even if that filter is ever removed.
        add_filter( 'acf/settings/save_json', '__return_false', 100 );

        // ── Register CPTs — ACF-native only ────────────────────────────────
        //
        // We never call register_post_type() ourselves. Doing so registers the
        // type through a completely separate code path from ACF, and the two
        // compete: ACF's own "Post Types" admin screen (and its internal
        // registration on init) can only account for types it manages itself.
        // A parallel raw registration for the same/related slugs is what was
        // causing already-existing ACF post types to stop showing up in
        // ACF → Post Types after an import.
        //
        // We hand the definition straight to acf_import_post_type(), which is
        // the exact same function ACF's own "Import JSON" admin tool uses.
        // It writes the post type directly into ACF's database storage
        // (an `acf-post-type` post) — no JSON file involved. ACF then owns
        // registration entirely — the imported type appears in
        // ACF → Post Types right alongside your existing ones.
        foreach ( ( $data['acf_post_types'] ?? [] ) as $slug => $definition ) {
            $slug = sanitize_key( $slug );
            if ( ! $slug || empty( $definition['key'] ) ) continue;

            // Defensive guard: never register a WordPress built-in post type
            // (post, page, etc.) into ACF, even if it's present in the data
            // (e.g. an older export made before this safeguard existed).
            if ( self::is_builtin_post_type( $slug ) ) {
                $result['skipped_builtin'][] = $slug;
                continue;
            }

            if ( ! function_exists( 'acf_import_post_type' ) ) {
                $result['errors'][] = "ACF is not active — could not register post type: $slug";
                continue;
            }

            if ( empty( $definition['title'] ) ) {
                $definition['title'] = $definition['plural_label'] ?? $definition['singular_label'] ?? $slug;
            }

            // Normalize 'supports' to the plain list of feature-name strings
            // ACF's post type importer expects (['title','editor',...]).
            // Whatever built $definition — an ACF-native definition copied
            // straight from the source, or our own fallback builder above —
            // this guards against any shape that isn't already that plain
            // list (an associative ['title'=>true,...] array, a list of
            // booleans, etc.), any of which silently registers the post
            // type with no title/editor support and makes both boxes
            // disappear from the post editor.
            $definition['supports'] = self::normalize_acf_supports_list( $definition['supports'] ?? null );

            // Re-importing the same structure (e.g. re-running an import, or a
            // Full Package import repeated against the same site) must UPDATE
            // the existing ACF post type for this slug, never create a second
            // one. The definition's 'key' is whatever the SOURCE site's ACF
            // record happens to use; if THIS destination site already has its
            // own ACF-managed post type for the same slug — under a different
            // key, e.g. from an earlier import — reuse that record's key/ID so
            // acf_import_post_type() updates it in place. Trusting the
            // source's key alone is what let two ACF post-type records end up
            // targeting the same post_type slug, which ACF's Post Types screen
            // then shows as one succeeding and the rest failing to register.
            $existing = self::find_existing_acf_internal_post_type( $slug, 'post_type' );
            if ( $existing ) {
                if ( ! empty( $existing['key'] ) ) $definition['key'] = $existing['key'];
                if ( ! empty( $existing['ID'] ) )  $definition['ID']  = $existing['ID'];
            }

            acf_import_post_type( $definition );
            $result['registered_cpts']++;
        }

        // Post types that weren't ACF-managed on the source site have no safe,
        // ACF-native way to be auto-registered here. We list them so the admin
        // UI can offer a copy-paste register_post_type() snippet instead of
        // silently registering something that could conflict with the site.
        foreach ( ( $data['post_types'] ?? [] ) as $slug => $args ) {
            $result['manual_cpts'][ sanitize_key( $slug ) ] = $args;
        }

        // ── Register Taxonomies — ACF-native only (same reasoning as above) ─
        foreach ( ( $data['acf_taxonomies'] ?? [] ) as $slug => $definition ) {
            $slug = sanitize_key( $slug );
            if ( ! $slug || empty( $definition['key'] ) ) continue;

            // Defensive guard: never register a WordPress built-in taxonomy
            // (category, post_tag, etc.) into ACF, same reasoning as above.
            if ( self::is_builtin_taxonomy( $slug ) ) {
                $result['skipped_builtin'][] = $slug;
                continue;
            }

            if ( ! function_exists( 'acf_import_taxonomy' ) ) {
                $result['errors'][] = "ACF is not active — could not register taxonomy: $slug";
                continue;
            }

            if ( empty( $definition['title'] ) ) {
                $definition['title'] = $definition['plural_label'] ?? $definition['singular_label'] ?? $slug;
            }

            // Same reasoning as the post-type loop above: reuse the
            // destination's own existing key/ID for this taxonomy slug (if
            // any) instead of trusting the source's key, so re-importing
            // updates the existing ACF taxonomy in place rather than creating
            // a second definition that fights the first for registration.
            $existing = self::find_existing_acf_internal_post_type( $slug, 'taxonomy' );
            if ( $existing ) {
                if ( ! empty( $existing['key'] ) ) $definition['key'] = $existing['key'];
                if ( ! empty( $existing['ID'] ) )  $definition['ID']  = $existing['ID'];
            }

            acf_import_taxonomy( $definition );
            $result['registered_taxes']++;
        }

        foreach ( ( $data['taxonomies'] ?? [] ) as $slug => $args ) {
            $result['manual_taxes'][ sanitize_key( $slug ) ] = $args;
        }

        // ── Import ACF Field Groups ────────────────────────────────────────
        //
        // METHOD: acf_import_field_group() — the same function ACF's own
        // "Import JSON" admin tool (Tools → Import) uses. This saves the
        // field group directly to the database as an `acf-field-group` post.
        // No JSON file is written anywhere.
        //
        // WPML's ACF Multilingual integration reads field group settings from
        // this same database record (via ACF's standard get/update field
        // group APIs), so it picks up imported groups without needing a
        // Local JSON file to scan.
        if ( function_exists( 'acf_import_field_group' ) ) {
            foreach ( ( $data['acf_groups'] ?? [] ) as $entry ) {
                $group = $entry['group'] ?? [];
                if ( empty( $group['key'] ) ) continue;

                // Do not trust IDs in files made by older versions either.
                // Match a destination group by its stable ACF key first, then
                // by its title. Supplying its destination ID makes ACF replace
                // it, rather than inserting a second group when sites were
                // originally created with different ACF keys.
                $group = self::strip_acf_database_ids( $group );
                $fields = self::strip_acf_database_ids( $entry['fields'] ?? [] );
                $existing = self::find_existing_acf_field_group( $group );
                if ( $existing && ! empty( $existing['ID'] ) ) {
                    $group['ID'] = (int) $existing['ID'];
                    $fields = self::match_existing_acf_fields(
                        $fields,
                        acf_get_fields( (int) $existing['ID'] ) ?: []
                    );
                }

                $group['fields'] = $fields;
                acf_import_field_group( $group );
                $result['acf_imported']++;
            }
        } elseif ( ! empty( $data['acf_groups'] ) ) {
            $result['errors'][] = 'ACF is not active — could not import field groups.';
        }

        remove_filter( 'acf/settings/save_json', '__return_false', 100 );

        return $result;
    }

    /**
     * True if this post type was registered by WordPress core itself
     * (post, page, attachment, revision, nav_menu_item, etc.) rather than
     * by a theme/plugin. These must never be pushed into ACF's Post Types
     * registry — they're core WordPress content types, not custom structure.
     */
    public static function is_builtin_post_type( string $slug ): bool {
        $obj = get_post_type_object( $slug );
        if ( $obj && ! empty( $obj->_builtin ) ) {
            return true;
        }
        // Safety net for slugs WordPress doesn't mark _builtin=true on but
        // that are still clearly core, not user-defined, structure.
        return in_array( $slug, [ 'post', 'page', 'attachment', 'revision', 'nav_menu_item' ], true );
    }

    /**
     * True if this taxonomy was registered by WordPress core itself
     * (category, post_tag, post_format, nav_menu, link_category) rather
     * than by a theme/plugin. Same reasoning as is_builtin_post_type().
     */
    public static function is_builtin_taxonomy( string $slug ): bool {
        $obj = get_taxonomy( $slug );
        if ( $obj && ! empty( $obj->_builtin ) ) {
            return true;
        }
        return in_array( $slug, [ 'category', 'post_tag', 'post_format', 'nav_menu', 'link_category' ], true );
    }

    private static function field_group_applies_to_post_types( array $group, array $post_types ): bool {
        $location = $group['location'] ?? [];

        // No location rules = global field group = include it
        if ( empty( $location ) ) {
            return true;
        }

        foreach ( $location as $or_group ) {
            foreach ( $or_group as $rule ) {
                if ( ! isset( $rule['param'], $rule['value'] ) || $rule['param'] !== 'post_type' ) {
                    continue;
                }

                $operator = $rule['operator'] ?? '==';
                if ( in_array( $operator, [ '==', '===' ], true ) ) {
                    if ( $rule['value'] === 'all' || in_array( $rule['value'], $post_types, true ) ) {
                        return true;
                    }
                } elseif ( in_array( $operator, [ '!=', '!==' ], true ) ) {
                    // A negative post-type rule applies to every selected type
                    // except its value. Include it whenever at least one
                    // selected type satisfies the rule.
                    foreach ( $post_types as $post_type ) {
                        if ( $post_type !== $rule['value'] ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Remove local WordPress database IDs from an ACF export/import payload.
     * Nested sub-fields are included because repeater, group, flexible-content
     * and clone fields can contain their own field definitions.
     */
    private static function strip_acf_database_ids( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }

        $clean = [];
        foreach ( $value as $key => $item ) {
            if ( $key === 'ID' ) {
                continue;
            }
            $clean[ $key ] = self::strip_acf_database_ids( $item );
        }
        return $clean;
    }

    /**
     * Find an existing field group by portable key, then by title. A title
     * fallback handles sites where the same group was created independently
     * and therefore has a different ACF key.
     */
    private static function find_existing_acf_field_group( array $group ): ?array {
        if ( ! empty( $group['key'] ) && function_exists( 'acf_get_field_group' ) ) {
            $existing = acf_get_field_group( $group['key'] );
            if ( $existing && ( $existing['key'] ?? '' ) === $group['key'] ) {
                return (array) $existing;
            }
        }

        if ( empty( $group['title'] ) ) {
            return null;
        }

        $matches = get_posts( [
            'post_type'      => 'acf-field-group',
            'post_status'    => 'any',
            'title'          => $group['title'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        if ( empty( $matches ) ) {
            return null;
        }

        if ( function_exists( 'acf_get_raw_field_group' ) ) {
            $existing = acf_get_raw_field_group( (int) $matches[0] );
            if ( $existing ) {
                $existing = (array) $existing;
                $existing['ID'] = (int) $matches[0];
                return $existing;
            }
        }

        return [ 'ID' => (int) $matches[0] ];
    }

    /**
     * Tell ACF to update matching destination fields even when the source and
     * target used different field keys. Keys still come from the source so the
     * imported configuration remains faithful to the export.
     */
    private static function match_existing_acf_fields( array $fields, array $existing_fields ): array {
        $by_key  = [];
        $by_name = [];
        foreach ( $existing_fields as $field ) {
            if ( ! empty( $field['key'] ) ) {
                $by_key[ $field['key'] ] = $field;
            }
            if ( ! empty( $field['name'] ) && ! isset( $by_name[ $field['name'] ] ) ) {
                $by_name[ $field['name'] ] = $field;
            }
        }

        foreach ( $fields as &$field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $match = $by_key[ $field['key'] ?? '' ] ?? $by_name[ $field['name'] ?? '' ] ?? null;
            if ( $match && ! empty( $match['ID'] ) ) {
                $field['ID'] = (int) $match['ID'];
            }
            if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
                $field['sub_fields'] = self::match_existing_acf_fields(
                    $field['sub_fields'],
                    $match['sub_fields'] ?? []
                );
            }
        }
        unset( $field );

        return $fields;
    }

    /**
     * Coerce a post type's 'supports' value into the plain list of
     * feature-name strings ACF's importer expects, e.g. ['title','editor',
     * 'thumbnail']. Accepts any of the shapes it might arrive in: already
     * a correct list of strings, an associative ['title' => true, ...]
     * array (what get_all_post_type_supports() returns), or a malformed
     * list of booleans (the bug this whole normalization exists to catch).
     * Falls back to WordPress's own default of ['title', 'editor'] if
     * nothing usable is left, since that's what register_post_type() itself
     * defaults to when 'supports' is omitted.
     */
    private static function normalize_acf_supports_list( $supports ): array {
        if ( ! is_array( $supports ) || empty( $supports ) ) {
            return [ 'title', 'editor' ];
        }

        $is_list = array_keys( $supports ) === range( 0, count( $supports ) - 1 );

        $names = $is_list
            ? array_filter( $supports, 'is_string' )                 // plain list — keep only real feature names
            : array_keys( array_filter( $supports ) );                // associative — the keys ARE the feature names

        $names = array_values( array_unique( array_filter( $names, fn( $n ) => $n !== '' ) ) );

        return $names ?: [ 'title', 'editor' ];
    }

    /**
     * Look up an already-registered ACF internal post type/taxonomy by its
     * WordPress slug ('post_type' => $slug or 'taxonomy' => $slug).
     *
     * ACF's own docs only document acf_get_post_type($id)/acf_get_taxonomy($id)
     * as ID/key lookups ("@param integer|string $id The post ID being
     * queried"), so passing a raw slug isn't guaranteed to resolve — a
     * missed match there was letting re-imports create a second ACF
     * Taxonomies/Post Types record for the same slug instead of updating
     * the existing one. To avoid depending on that undocumented behavior
     * entirely, this queries the underlying 'acf-post-type'/'acf-taxonomy'
     * posts directly via get_posts() (which only ever needs a post_type,
     * never any assumption about slug lookups) and reads each one back
     * through acf_get_raw_post_type()/acf_get_raw_taxonomy() — both
     * explicitly documented as taking "The post ID" — to compare its
     * registered slug against the one we're looking for.
     *
     * @param  string $slug  The post type or taxonomy slug to find.
     * @param  string $kind  'post_type' or 'taxonomy'.
     * @return array|null    The existing ACF definition (with 'key'/'ID'), or null.
     */
    private static function find_existing_acf_internal_post_type( string $slug, string $kind ): ?array {
        $internal_post_type = $kind === 'taxonomy' ? 'acf-taxonomy' : 'acf-post-type';
        $raw_getter          = $kind === 'taxonomy' ? 'acf_get_raw_taxonomy' : 'acf_get_raw_post_type';

        // Fast path: ACF's own single-item getter, in case it does resolve
        // by slug on this ACF version.
        $getter = $kind === 'taxonomy' ? 'acf_get_taxonomy' : 'acf_get_post_type';
        if ( function_exists( $getter ) ) {
            $existing = $getter( $slug );
            if ( $existing ) {
                $existing = (array) $existing;
                if ( ( $existing[ $kind ] ?? null ) === $slug ) {
                    return $existing;
                }
            }
        }

        // Reliable fallback: enumerate the actual DB posts and read each
        // one back by ID, which is unambiguously supported.
        if ( function_exists( $raw_getter ) ) {
            $posts = get_posts( [
                'post_type'      => $internal_post_type,
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ] );

            foreach ( $posts as $post_id ) {
                $raw = $raw_getter( $post_id );
                if ( ! $raw ) continue;
                $raw = (array) $raw;
                if ( ( $raw[ $kind ] ?? null ) === $slug ) {
                    if ( empty( $raw['key'] ) && function_exists( 'get_post_field' ) ) {
                        $raw['key'] = get_post_field( 'post_name', $post_id );
                    }
                    $raw['ID'] = $post_id;
                    return $raw;
                }
            }
        }

        return null;
    }

    /**
     * Return the ACF-native definition for a post type, if it was registered
     * through ACF (via its UI or its own local JSON). Returns null if ACF
     * isn't active or the post type isn't ACF-managed — meaning it was
     * registered by theme/plugin code instead.
     */
    private static function get_acf_post_type_definition( string $slug ): ?array {
        if ( ! function_exists( 'acf_get_post_type' ) ) return null;
        $post_type = acf_get_post_type( $slug );
        if ( ! $post_type ) return null;
        $post_type = (array) $post_type;
        return ! empty( $post_type['key'] ) ? $post_type : null;
    }

    /**
     * Same as above, for taxonomies.
     */
    private static function get_acf_taxonomy_definition( string $slug ): ?array {
        if ( ! function_exists( 'acf_get_taxonomy' ) ) return null;
        $taxonomy = acf_get_taxonomy( $slug );
        if ( ! $taxonomy ) return null;
        $taxonomy = (array) $taxonomy;
        return ! empty( $taxonomy['key'] ) ? $taxonomy : null;
    }

    /**
     * Build an ACF-format "internal post type" definition from plain
     * register_post_type() args, for post types that were never registered
     * through ACF on the source site. ACF fills in any settings we don't
     * provide with its own sane defaults, so only the essentials need to be
     * accurate here. The resulting definition imports exactly like a post
     * type created through ACF's own "Post Types" screen — it appears in
     * ACF → Post Types and is registered by ACF itself, no raw
     * register_post_type() call from this plugin required.
     *
     * A deterministic key (derived from the slug) is used so re-importing
     * the same post type updates the existing ACF definition instead of
     * creating a duplicate.
     */
    private static function build_acf_post_type_definition( string $slug, array $args ): array {
        $labels   = $args['labels'] ?? [];
        $singular = $labels['singular_name'] ?? ( $args['label'] ?? $slug );
        $plural   = $labels['name'] ?? ( $args['label'] ?? $slug );

        $rewrite = $args['rewrite'] ?? true;
        $rewrite_slug = is_array( $rewrite ) ? ( $rewrite['slug'] ?? '' ) : '';

        return [
            'key'                 => 'post_type_' . substr( md5( 'post_type_' . $slug ), 0, 13 ),
            'title'               => $plural,
            'post_type'           => $slug,
            'active'              => true,
            'import_source'       => 'wpml-porter',
            'labels'              => $labels,
            'singular_label'      => $singular,
            'plural_label'        => $plural,
            'description'         => $args['description'] ?? '',
            'public'              => ! empty( $args['public'] ),
            'hierarchical'        => ! empty( $args['hierarchical'] ),
            'publicly_queryable'  => ! empty( $args['publicly_queryable'] ),
            'exclude_from_search' => ! empty( $args['exclude_from_search'] ),
            'show_ui'             => ! empty( $args['show_ui'] ),
            'show_in_menu'        => ! empty( $args['show_in_menu'] ),
            'show_in_admin_bar'   => ! empty( $args['show_in_admin_bar'] ),
            'show_in_nav_menus'   => ! empty( $args['show_in_nav_menus'] ),
            'show_in_rest'        => ! empty( $args['show_in_rest'] ),
            'rest_base'           => $args['rest_base'] ?? '',
            'menu_position'       => $args['menu_position'] ?? '',
            'menu_icon'           => is_string( $args['menu_icon'] ?? null )
                ? [ 'type' => 'dashicons', 'value' => $args['menu_icon'] ]
                : null,
            'capability_type'     => is_array( $args['capability_type'] ?? null )
                ? ( $args['capability_type'][0] ?? 'post' )
                : ( $args['capability_type'] ?? 'post' ),
            // get_all_post_type_supports() returns an associative array keyed
            // by feature slug, e.g. ['title' => true, 'editor' => true, ...].
            // ACF's post type importer expects a plain array of feature-name
            // strings (['title', 'editor', ...]), same as the 'supports' arg
            // to register_post_type(). array_keys() (not array_values()) is
            // what preserves those names — array_values() here was throwing
            // away the feature names and handing ACF a list of booleans
            // instead, which silently registered the post type with no
            // 'title'/'editor' support and made both boxes disappear from
            // the post editor after import.
            'supports'            => array_keys( array_filter( $args['supports'] ?? [] ) ),
            'taxonomies'          => array_values( $args['taxonomies'] ?? [] ),
            'has_archive'         => ! empty( $args['has_archive'] ),
            'rewrite'             => [
                'permalink_rewrite' => $rewrite === false ? '0' : ( $rewrite_slug !== '' ? 'custom' : 'post_type_key' ),
                'permalink_rewrite_custom_slug' => $rewrite_slug,
                'with_front'        => is_array( $rewrite ) ? ! empty( $rewrite['with_front'] ) : true,
                'feeds'             => is_array( $rewrite ) ? ! empty( $rewrite['feeds'] ) : false,
                'pages'             => is_array( $rewrite ) ? ! empty( $rewrite['pages'] ) : true,
            ],
            'query_var'           => ! empty( $args['query_var'] ),
            'can_export'          => ! empty( $args['can_export'] ),
            'delete_with_user'    => ! empty( $args['delete_with_user'] ),
        ];
    }

    /**
     * Same as build_acf_post_type_definition(), for taxonomies.
     */
    private static function build_acf_taxonomy_definition( string $slug, array $args ): array {
        $labels   = $args['labels'] ?? [];
        $singular = $labels['singular_name'] ?? ( $args['label'] ?? $slug );
        $plural   = $labels['name'] ?? ( $args['label'] ?? $slug );

        $rewrite = $args['rewrite'] ?? true;
        $rewrite_slug = is_array( $rewrite ) ? ( $rewrite['slug'] ?? '' ) : '';

        return [
            'key'                => 'taxonomy_' . substr( md5( 'taxonomy_' . $slug ), 0, 13 ),
            'title'              => $plural,
            'taxonomy'           => $slug,
            'object_type'        => array_values( $args['object_type'] ?? [] ),
            'active'             => true,
            'import_source'      => 'wpml-porter',
            'labels'             => $labels,
            'singular_label'     => $singular,
            'plural_label'       => $plural,
            'description'        => $args['description'] ?? '',
            'public'             => ! empty( $args['public'] ),
            'hierarchical'       => ! empty( $args['hierarchical'] ),
            'publicly_queryable' => ! empty( $args['publicly_queryable'] ),
            'show_ui'            => ! empty( $args['show_ui'] ),
            'show_in_menu'       => ! empty( $args['show_in_menu'] ),
            'show_in_nav_menus'  => ! empty( $args['show_in_nav_menus'] ),
            'show_in_rest'       => ! empty( $args['show_in_rest'] ),
            'rest_base'          => $args['rest_base'] ?? '',
            'show_tagcloud'      => ! empty( $args['show_tagcloud'] ),
            'show_in_quick_edit' => ! empty( $args['show_in_quick_edit'] ),
            'show_admin_column'  => ! empty( $args['show_admin_column'] ),
            'rewrite'            => [
                'permalink_rewrite' => $rewrite === false ? '0' : ( $rewrite_slug !== '' ? 'custom' : 'taxonomy_key' ),
                'permalink_rewrite_custom_slug' => $rewrite_slug,
                'hierarchical'      => is_array( $rewrite ) ? ! empty( $rewrite['hierarchical'] ) : false,
                'with_front'        => is_array( $rewrite ) ? ! empty( $rewrite['with_front'] ) : true,
            ],
            'query_var'          => ! empty( $args['query_var'] ),
        ];
    }

    private static function get_all_term_meta_keys( array $terms ): array {
        if ( empty( $terms ) ) return [];
        global $wpdb;
        $ids = implode( ',', array_unique( array_map( fn( $t ) => (int) $t->term_id, $terms ) ) );
        return $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM {$wpdb->termmeta}
             WHERE  term_id IN ({$ids})
               AND  meta_key NOT LIKE '\\_wpml%'
             ORDER BY meta_key"
        );
    }

    private static function get_acf_term_field_keys( array $taxonomies ): array {
        if ( ! function_exists( 'acf_get_field_groups' ) ) return [];
        $keys = [];
        foreach ( acf_get_field_groups() as $group ) {
            $applies = false;
            foreach ( $group['location'] ?? [] as $or_group ) {
                foreach ( $or_group as $rule ) {
                    if ( $rule['param'] === 'taxonomy' && in_array( $rule['value'], $taxonomies, true ) ) {
                        $applies = true; break 2;
                    }
                }
            }
            if ( ! $applies ) continue;
            foreach ( acf_get_fields( $group['key'] ) ?: [] as $field ) {
                if ( ! empty( $field['name'] ) ) $keys[] = $field['name'];
            }
        }
        return $keys;
    }

    /** Make internal URLs portable without touching absolute external URLs. */
    private static function relativize_value( $value ) {
        if ( is_string( $value ) ) {
            if ( is_serialized( $value ) ) {
                return maybe_serialize( self::relativize_value( maybe_unserialize( $value ) ) );
            }
            return self::relativize_urls_in_string( $value );
        }
        if ( is_array( $value ) ) return array_map( [ __CLASS__, 'relativize_value' ], $value );
        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) $value->$key = self::relativize_value( $item );
        }
        return $value;
    }

    private static function absolutize_value( $value ) {
        if ( is_string( $value ) ) return self::absolutize_urls_in_string( $value );
        if ( is_array( $value ) ) return array_map( [ __CLASS__, 'absolutize_value' ], $value );
        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) $value->$key = self::absolutize_value( $item );
        }
        return $value;
    }

    private static function relativize_urls_in_string( string $str ): string {
        if ( $str === '' ) return $str;
        $home_path = rtrim( (string) parse_url( home_url(), PHP_URL_PATH ), '/' );
        $bases = [ rtrim( home_url(), '/' ) => '', rtrim( site_url(), '/' ) => '' ];
        global $sitepress;
        if ( $sitepress ) {
            foreach ( array_keys( $sitepress->get_active_languages() ?: [] ) as $language ) {
                $language_home = rtrim( (string) apply_filters( 'wpml_home_url', home_url(), $language ), '/' );
                if ( $language_home === '' ) continue;
                $language_path = rtrim( (string) parse_url( $language_home, PHP_URL_PATH ), '/' );
                $bases[ $language_home ] = ( $language_path !== $home_path && strpos( $language_path . '/', $home_path . '/' ) === 0 )
                    ? substr( $language_path, strlen( $home_path ) ) : '';
            }
        }
        uksort( $bases, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
        foreach ( $bases as $base => $replacement ) {
            $without_scheme = preg_replace( '#^https?://#i', '', $base );
            if ( $without_scheme !== '' ) $str = preg_replace( '#https?://' . preg_quote( $without_scheme, '#' ) . '#i', $replacement, $str );
        }
        return $str;
    }

    /**
     * See WPML_Porter_Importer::absolutize_urls_in_string() for why the match
     * is anchored to string-start / quote / `(` / `=` / whitespace rather
     * than any non-URL character — the previous broad pattern mistook the
     * '/' in ordinary markup (e.g. a closing tag like `</p>`, or `<br/>`) for
     * a root-relative URL and spliced the site's domain into the tag,
     * corrupting term descriptions and rich-text ACF fields.
     */
    private static function absolutize_urls_in_string( string $str ): string {
        if ( $str === '' ) return $str;
        return preg_replace( '#(?:^|(?<=[\'"\s(=]))/(?![/>\s])#', rtrim( home_url(), '/' ) . '/', $str );
    }

    private static function extract_post_type_args( WP_Post_Type $obj ): array {
        return [
            'label'               => $obj->label,
            'labels'              => (array) $obj->labels,
            'description'         => $obj->description,
            'public'              => $obj->public,
            'publicly_queryable'  => $obj->publicly_queryable,
            'show_ui'             => $obj->show_ui,
            'show_in_menu'        => $obj->show_in_menu,
            'show_in_nav_menus'   => $obj->show_in_nav_menus,
            'show_in_admin_bar'   => $obj->show_in_admin_bar,
            'show_in_rest'        => $obj->show_in_rest,
            'rest_base'           => $obj->rest_base,
            'menu_position'       => $obj->menu_position,
            'menu_icon'           => $obj->menu_icon,
            'capability_type'     => $obj->capability_type,
            'hierarchical'        => $obj->hierarchical,
            'supports'            => get_all_post_type_supports( $obj->name ),
            'taxonomies'          => get_object_taxonomies( $obj->name ),
            'has_archive'         => $obj->has_archive,
            'rewrite'             => $obj->rewrite,
            'query_var'           => $obj->query_var,
            'can_export'          => $obj->can_export,
            'delete_with_user'    => $obj->delete_with_user,
            'exclude_from_search' => $obj->exclude_from_search,
        ];
    }

    private static function extract_taxonomy_args( WP_Taxonomy $obj ): array {
        return [
            'label'              => $obj->label,
            'labels'             => (array) $obj->labels,
            'description'        => $obj->description,
            'public'             => $obj->public,
            'publicly_queryable' => $obj->publicly_queryable,
            'hierarchical'       => $obj->hierarchical,
            'show_ui'            => $obj->show_ui,
            'show_in_menu'       => $obj->show_in_menu,
            'show_in_nav_menus'  => $obj->show_in_nav_menus,
            'show_in_rest'       => $obj->show_in_rest,
            'rest_base'          => $obj->rest_base,
            'show_tagcloud'      => $obj->show_tagcloud,
            'show_in_quick_edit' => $obj->show_in_quick_edit,
            'show_admin_column'  => $obj->show_admin_column,
            'rewrite'            => $obj->rewrite,
            'query_var'          => $obj->query_var,
            'object_type'        => $obj->object_type,
        ];
    }
}
