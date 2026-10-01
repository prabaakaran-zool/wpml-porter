<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML Porter – Full Package Export/Import
 *
 * Bundles everything related to a single CPT into one .zip:
 *   - manifest.json    what's inside, which post type, when it was made
 *   - structure.json   ACF post type definition, related ACF taxonomy
 *                       definitions, and every ACF field group attached
 *                       to the post type (same format as the standalone
 *                       Structure export)
 *   - terms.csv         every term (all WPML languages) for every taxonomy
 *                       attached to the post type (same format as the
 *                       standalone Taxonomy export)
 *   - posts.csv         every post (all WPML languages), core fields +
 *                       taxonomy terms + custom fields (same format as
 *                       the standalone Post export)
 *
 * Import runs the three pieces in the order they must be applied:
 *   1. structure.json  → WPML_Porter_Terms::import_structure()   (sync)
 *   2. terms.csv        → WPML_Porter_Terms::import_terms()       (sync)
 *   3. posts.csv         → WPML_Porter_Importer session            (async/batched,
 *      reuses the exact same progress-bar UI as a normal CSV post import)
 *
 * Posts are imported last and asynchronously because a CPT can have
 * thousands of posts — the other two steps are typically small and fast
 * enough to run inline within a single request.
 */
class WPML_Porter_Package {

    /**
     * Build a full package .zip for one post type and stream it to the browser.
     *
     * @param string   $post_type
     * @param string[] $meta_keys    Specific post meta keys to export. Empty = all.
     * @param bool     $include_acf  Include ACF field groups / ACF-managed term fields.
     */
    public static function export( string $post_type, array $meta_keys = [], bool $include_acf = true ): void {
        if ( ! post_type_exists( $post_type ) ) {
            wp_die( 'Invalid post type.' );
        }

        $taxonomies = array_values( array_filter( get_object_taxonomies( $post_type ), 'taxonomy_exists' ) );

        // ── 1. Structure (ACF post type + related ACF taxonomies + field groups)
        $structure = WPML_Porter_Terms::get_structure_data( [ $post_type ], $taxonomies, $include_acf );

        // ── 2. Terms (only if the CPT actually has taxonomies with terms)
        $terms_csv = ! empty( $taxonomies ) ? WPML_Porter_Terms::build_terms_csv( $taxonomies, $include_acf ) : '';

        // ── 3. Posts
        $posts_csv = ( new WPML_Porter_Exporter( $post_type, [ 'meta_keys' => $meta_keys ] ) )->build_csv();

        // ── Manifest
        $manifest = [
            'wpml_porter'  => WPML_PORTER_VERSION,
            'exported_at'  => gmdate( 'c' ),
            'post_type'    => $post_type,
            'taxonomies'   => $taxonomies,
            'has_structure'=> ! empty( $structure['acf_post_types'] ) || ! empty( $structure['acf_taxonomies'] ) || ! empty( $structure['acf_groups'] ),
            'has_terms'    => trim( $terms_csv ) !== '',
            'has_posts'    => trim( $posts_csv ) !== '',
            'skipped_builtin_post_types' => $structure['skipped_builtin_post_types'] ?? [],
            'skipped_builtin_taxonomies' => $structure['skipped_builtin_taxonomies'] ?? [],
        ];

        // ── Zip it up
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( 'The PHP ZipArchive extension is required to build a Full Package export. Please ask your host to enable it, or use the separate Export / Taxonomy tabs instead.' );
        }

        $tmp_zip = wp_tempnam( 'wpml-porter-package' );

        $zip = new ZipArchive();
        if ( $zip->open( $tmp_zip, ZipArchive::OVERWRITE ) !== true ) {
            @unlink( $tmp_zip );
            wp_die( 'Could not create the package zip file.' );
        }

        $zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
        $zip->addFromString( 'structure.json', wp_json_encode( $structure, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
        if ( $manifest['has_terms'] ) {
            $zip->addFromString( 'terms.csv', $terms_csv );
        }
        if ( $manifest['has_posts'] ) {
            $zip->addFromString( 'posts.csv', $posts_csv );
        }
        $zip->close();

        $filename = 'wpml-porter-package-' . $post_type . '-' . gmdate( 'Ymd-His' ) . '.zip';

        while ( ob_get_level() ) ob_end_clean();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $tmp_zip ) );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        readfile( $tmp_zip );
        @unlink( $tmp_zip );
        exit;
    }

    /**
     * Extract an uploaded .zip, run the structure + terms import synchronously,
     * and (if the package contains posts) start a post-import session identical
     * to a normal CSV post import — ready to hand off to the existing
     * batch/progress UI.
     *
     * @param  string $zip_path      Path to the uploaded .zip file.
     * @param  string $default_lang  Source language for the posts import.
     * @return array {
     *     ok            bool
     *     errors        string[]
     *     manifest      array|null
     *     structure     array|null   result from WPML_Porter_Terms::import_structure()
     *     terms         array|null   result from WPML_Porter_Terms::import_terms()
     *     session       array|null   { session_id, total_rows, post_type } — present only if posts.csv existed
     * }
     */
    public static function import( string $zip_path, string $default_lang = 'en' ): array {
        $result = [
            'ok'        => false,
            'errors'    => [],
            'manifest'  => null,
            'structure' => null,
            'terms'     => null,
            'session'   => null,
        ];

        if ( ! class_exists( 'ZipArchive' ) ) {
            $result['errors'][] = 'The PHP ZipArchive extension is required to import a Full Package. Please ask your host to enable it.';
            return $result;
        }

        if ( ! file_exists( $zip_path ) ) {
            $result['errors'][] = 'Uploaded file not found.';
            return $result;
        }

        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            $result['errors'][] = 'Could not open the uploaded file as a zip archive.';
            return $result;
        }

        $manifest_raw = $zip->getFromName( 'manifest.json' );
        if ( $manifest_raw === false ) {
            $zip->close();
            $result['errors'][] = 'This doesn\'t look like a WPML Porter package — manifest.json is missing.';
            return $result;
        }

        $manifest = json_decode( $manifest_raw, true );
        if ( ! is_array( $manifest ) || empty( $manifest['post_type'] ) ) {
            $zip->close();
            $result['errors'][] = 'Invalid manifest.json in package.';
            return $result;
        }
        $result['manifest'] = $manifest;

        // Extract to a private temp directory (needed so posts.csv can be
        // handed to WPML_Porter_Importer::prepare_session(), which reads
        // from a file path).
        $extract_dir = trailingslashit( get_temp_dir() ) . 'wpml-porter-pkg-' . wp_generate_password( 12, false );
        if ( ! wp_mkdir_p( $extract_dir ) ) {
            $zip->close();
            $result['errors'][] = 'Could not create a temporary extraction directory.';
            return $result;
        }

        $zip->extractTo( $extract_dir );
        $zip->close();

        // ── 1. Structure (synchronous — post types / taxonomies / field groups) ─
        $structure_file = $extract_dir . '/structure.json';
        if ( file_exists( $structure_file ) ) {
            $structure_data = json_decode( file_get_contents( $structure_file ), true );
            if ( is_array( $structure_data ) ) {
                $result['structure'] = WPML_Porter_Terms::import_structure( $structure_data );
            } else {
                $result['errors'][] = 'structure.json in the package could not be parsed.';
            }
        }

        // ── 2. Terms (synchronous) ────────────────────────────────────────
        $terms_file = $extract_dir . '/terms.csv';
        if ( file_exists( $terms_file ) ) {
            $rows = self::parse_csv_file( $terms_file );
            if ( $rows !== null ) {
                $result['terms'] = WPML_Porter_Terms::import_terms( $rows, $default_lang );
            } else {
                $result['errors'][] = 'terms.csv in the package could not be parsed.';
            }
        }

        // ── 3. Posts (async — hand off to the existing batch importer) ────
        $posts_file = $extract_dir . '/posts.csv';
        if ( file_exists( $posts_file ) ) {
            $session = WPML_Porter_Importer::prepare_session( $posts_file );
            if ( ! empty( $session['session_id'] ) ) {
                $result['session'] = [
                    'session_id' => $session['session_id'],
                    'total_rows' => $session['total_rows'],
                    'post_type'  => $manifest['post_type'],
                ];
            } elseif ( ! empty( $session['errors'] ) ) {
                $result['errors'] = array_merge( $result['errors'], $session['errors'] );
            }
        }

        // Clean up the extracted files — structure/terms are already applied,
        // and posts.csv's rows now live in the session transient, not on disk.
        self::rrmdir( $extract_dir );

        $result['ok'] = empty( $result['errors'] );
        return $result;
    }

    /**
     * Parse a CSV file into an array of associative rows (header => value).
     *
     * @return array[]|null  null on unreadable/empty file
     */
    private static function parse_csv_file( string $path ): ?array {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) return null;

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return null;
        }
        $header = array_map( 'trim', $header );

        $rows = [];
        while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
            $row = [];
            foreach ( $header as $i => $key ) {
                $row[ $key ] = $raw[ $i ] ?? '';
            }
            $rows[] = $row;
        }
        fclose( $handle );

        return $rows;
    }

    /**
     * Recursively delete a directory (used to clean up the temp extraction folder).
     */
    private static function rrmdir( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        $items = scandir( $dir );
        foreach ( $items as $item ) {
            if ( $item === '.' || $item === '..' ) continue;
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) ) {
                self::rrmdir( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }
}
