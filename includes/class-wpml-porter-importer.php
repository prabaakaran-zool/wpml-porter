<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML Porter Importer
 *
 * Supports two modes:
 *  - process_batch()  → called repeatedly via Ajax, streamed from disk in small batches
 *  - import_from_file() → legacy single-request mode (kept for CLI / WP-CLI use)
 */
class WPML_Porter_Importer {

    const BATCH_SIZE      = 20;
    const LINK_BATCH_SIZE = 100; // translation groups linked per Ajax call — see process_batch()'s linking phase
    const SESSION_TTL     = 3600; // 1 hour transient lifetime
    const SESSION_KEY_PFX = 'wpml_porter_session_';

    /** @var string */
    private $post_type;

    /** @var string */
    private $default_language;

    /** @var array */
    private $translation_map = [];

    /**
     * @var string[] translation_group => language_code of the row that the
     * CSV itself marked as the source (blank _wpml_import_source_language_code).
     * Authoritative for link_translations() — see process_row().
     */
    private $group_source_lang = [];

    /** @var int[] */
    public $counts = [
        'inserted' => 0,
        'updated'  => 0,
        'linked'   => 0,
        'skipped'  => 0,
    ];

    /** @var string[] */
    public $errors = [];

    /** @var string[] */
    public $warnings = [];

    /** @var int[]  Post IDs imported in this session that carry Elementor data — used to trigger CSS regeneration once the whole import finishes. */
    public $elementor_post_ids = [];

    public function __construct( string $post_type, array $options = [] ) {
        $this->post_type        = sanitize_key( $post_type );
        $this->default_language = $options['default_language'] ?? 'en';
    }

    // ────────────────────────────────────────────────────────────────────
    // BATCH MODE  (Ajax-driven)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Prepare a new import session from an uploaded CSV file.
     *
     * The CSV is copied to a stable, private session directory and read from
     * disk on every batch — NOT loaded into memory / stored in the session
     * transient. A row of real-world content (e.g. a page built with
     * Elementor) can easily be several hundred KB to a few MB; holding every
     * row of a multi-thousand-row import in one PHP array and re-serializing
     * that whole array into wp_options on every single Ajax batch call is
     * what caused large imports to blow past PHP's memory/time limits (or a
     * host's proxy timeout) partway through and return a truncated, invalid
     * JSON response to the browser. Reading the file from disk in small
     * per-batch slices keeps every request's memory footprint proportional
     * to one batch, not the whole import.
     *
     * @param  string $file_path
     * @return array { session_id, total_rows, errors[] }
     */
    public static function prepare_session( string $file_path ): array {

        if ( ! file_exists( $file_path ) ) {
            return [ 'session_id' => null, 'total_rows' => 0, 'errors' => [ 'File not found.' ] ];
        }

        $session_id = wp_generate_uuid4();
        $session_dir = self::get_sessions_dir();
        if ( ! $session_dir ) {
            return [ 'session_id' => null, 'total_rows' => 0, 'errors' => [ 'Could not create a session storage directory.' ] ];
        }

        // Opportunistic cleanup of abandoned sessions from previous imports.
        self::prune_stale_session_files( $session_dir );

        $stable_path = $session_dir . '/' . $session_id . '.csv';
        if ( ! copy( $file_path, $stable_path ) ) {
            return [ 'session_id' => null, 'total_rows' => 0, 'errors' => [ 'Could not copy CSV into the session storage directory.' ] ];
        }

        $handle = fopen( $stable_path, 'r' );
        if ( ! $handle ) {
            return [ 'session_id' => null, 'total_rows' => 0, 'errors' => [ 'Cannot open file.' ] ];
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            @unlink( $stable_path );
            return [ 'session_id' => null, 'total_rows' => 0, 'errors' => [ 'Empty or unreadable CSV.' ] ];
        }
        $header = array_map( 'trim', $header );

        // One pass to count real (non-blank-title) rows for the progress bar,
        // and to record the byte offset where the data actually starts
        // (right after the header line — accounts for any BOM/line-ending quirks).
        $data_start_offset = ftell( $handle );

        $total = 0;
        while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
            $row = [];
            foreach ( $header as $i => $key ) {
                $row[ $key ] = $raw[ $i ] ?? '';
            }
            if ( ! empty( trim( $row['post_title'] ?? '' ) ) ) {
                $total++;
            }
        }
        fclose( $handle );

        $session = [
            'file'        => $stable_path,
            'header'      => $header,
            'byte_offset' => $data_start_offset, // where to fseek() for the next batch
            'offset'      => 0,                  // rows processed so far (for the progress bar)
            'total'       => $total,
            'counts'      => [ 'inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0 ],
            'errors'      => [],
            'warnings'    => [],
            'trans_map'   => [],   // accumulated translation map
            'group_source_lang' => [], // accumulated per-group source language (see process_row())
        ];

        set_transient( self::SESSION_KEY_PFX . $session_id, $session, self::SESSION_TTL );

        return [ 'session_id' => $session_id, 'total_rows' => $total, 'errors' => [] ];
    }

    /**
     * Directory where in-progress import CSVs are kept (private, outside the
     * webroot's directly-browsable expectations — protected further with a
     * .htaccess deny-all and an empty index.php below).
     */
    private static function get_sessions_dir(): ?string {
        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'wpml-porter-sessions';

        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return null;
        }

        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
        }
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }

        return $dir;
    }

    /**
     * Delete session CSV files whose transient has already expired/vanished
     * (e.g. an import that was started and then abandoned). Runs once at the
     * start of each new import so stale files don't accumulate indefinitely.
     */
    private static function prune_stale_session_files( string $dir ): void {
        $files = glob( $dir . '/*.csv' );
        if ( ! $files ) return;

        foreach ( $files as $file ) {
            $session_id = basename( $file, '.csv' );
            if ( ! get_transient( self::SESSION_KEY_PFX . $session_id ) ) {
                @unlink( $file );
            }
        }
    }

    /**
     * Process one batch from a session.
     *
     * @param  string $session_id
     * @param  string $post_type
     * @param  string $default_language
     * @return array {
     *     processed   int   rows processed this batch,
     *     offset      int   new offset,
     *     total       int   total rows,
     *     done        bool  true when all rows have been processed AND translations linked,
     *     phase       string 'rows' while CSV rows are still being imported,
     *                        'linking' once row import is finished and WPML
     *                        translation links are being established (also
     *                        batched — see the linking-phase block below),
     *     link_offset int   (phase 'linking' only) translation groups linked so far,
     *     link_total  int   (phase 'linking' only) total translation groups to link,
     *     counts      array cumulative counts,
     *     errors      array all accumulated errors,
     *     warnings    array all accumulated warnings,
     * }
     */
    public static function process_batch( string $session_id, string $post_type, string $default_language, ?int $row_batch_size = null, ?int $link_batch_size = null ): array {

        $key     = self::SESSION_KEY_PFX . $session_id;
        $session = get_transient( $key );

        if ( ! $session ) {
            return self::fatal_result( 'Session expired or not found.' );
        }

        // Batch sizes are normally the class defaults, but the Ajax caller
        // can request smaller ones — see handle_ajax_batch(): on a host
        // whose memory/time/proxy limits are tighter than even one default
        // batch costs, retrying at the same size just fails identically
        // every time, so the browser progressively asks for less work per
        // request until it fits.
        $row_batch_size  = max( 2, min( self::BATCH_SIZE, $row_batch_size ?? self::BATCH_SIZE ) );
        $link_batch_size = max( 5, min( self::LINK_BATCH_SIZE, $link_batch_size ?? self::LINK_BATCH_SIZE ) );

        // Drop temporary mapping data written by the short-lived parent-link
        // experiment. Keeping thousands of those entries in the transient can
        // make large Ajax imports fail before the final WPML link step.
        unset( $session['source_post_map'], $session['pending_parents'] );

        $importer                     = new self( $post_type, [ 'default_language' => $default_language ] );
        $importer->translation_map    = $session['trans_map'];
        $importer->group_source_lang  = $session['group_source_lang'] ?? [];
        $importer->counts             = $session['counts'];
        $importer->errors             = $session['errors'];
        $importer->warnings           = $session['warnings'];
        $importer->elementor_post_ids = $session['elementor_post_ids'] ?? [];

        // ── Linking phase ──────────────────────────────────────────────
        // All CSV rows have already been imported (see below). What's left
        // is establishing WPML translation links, which for a large import
        // can mean thousands of translation groups, each needing several DB
        // writes (set_element_language_details() x2, get_element_trid(),
        // mark_translation_complete()). Doing that in a single request used
        // to be exactly the kind of unbounded, all-at-once work that row
        // batching (see prepare_session() above) was built to avoid — on a
        // large import it would hit the same timeout/gateway-kill every
        // single retry (a deterministic failure, not a transient one), so
        // the "Import stopped after repeated failures" message would show
        // even though every row had in fact imported successfully. So this
        // gets the same small-slice-per-Ajax-call treatment as row import.
        if ( ! empty( $session['rows_done'] ) ) {
            $groups      = $session['link_groups'] ?? [];
            $link_offset = $session['link_offset'] ?? 0;
            $slice       = array_slice( $groups, $link_offset, $link_batch_size );

            $importer->link_translations_slice( $slice );
            $link_offset += count( $slice );
            $linking_done = $link_offset >= count( $groups );

            if ( $linking_done ) {
                // Whole import finished — regenerate Elementor CSS once, if needed.
                self::regenerate_elementor_css( $importer->elementor_post_ids );
                delete_transient( $key );
            } else {
                $session['link_offset'] = $link_offset;
                $session['counts']      = $importer->counts;
                $session['errors']      = $importer->errors;
                $session['warnings']    = $importer->warnings;
                set_transient( $key, $session, self::SESSION_TTL );
            }

            return [
                'processed'     => 0,
                'offset'        => $session['total'],
                'total'         => $session['total'],
                'done'          => $linking_done,
                'phase'         => 'linking',
                'link_offset'   => $link_offset,
                'link_total'    => count( $groups ),
                'counts'        => $importer->counts,
                'errors'        => $importer->errors,
                'warnings'      => $importer->warnings,
                'has_elementor' => ! empty( $importer->elementor_post_ids ),
            ];
        }

        // ── Row-import phase ───────────────────────────────────────────
        if ( empty( $session['file'] ) || ! file_exists( $session['file'] ) ) {
            return self::fatal_result( 'Session expired or not found.' );
        }

        // Read exactly BATCH_SIZE rows starting from where the last batch left off.
        $handle = fopen( $session['file'], 'r' );
        $batch_count = 0;

        if ( $handle ) {
            fseek( $handle, $session['byte_offset'] );

            while ( $batch_count < $row_batch_size && ( $raw = fgetcsv( $handle ) ) !== false ) {
                $row = [];
                foreach ( $session['header'] as $i => $key_name ) {
                    $row[ $key_name ] = $raw[ $i ] ?? '';
                }

                // Rows with no title are skipped at export time too, but guard
                // here as well in case of a hand-edited CSV.
                if ( empty( trim( $row['post_title'] ?? '' ) ) ) {
                    continue;
                }

                $importer->process_row( $row );
                $batch_count++;
            }

            $new_byte_offset = ftell( $handle );
            $eof             = feof( $handle );
            fclose( $handle );
        } else {
            $importer->errors[] = 'Could not reopen session file for this batch.';
            $new_byte_offset = $session['byte_offset'];
            $eof             = true;
        }

        $new_offset   = $session['offset'] + $batch_count;
        $rows_all_done = $eof || $new_offset >= $session['total'];

        // Persist updated session (small bookkeeping only — never the row data)
        $session['byte_offset']        = $new_byte_offset;
        $session['offset']             = $new_offset;
        $session['counts']             = $importer->counts;
        $session['errors']             = $importer->errors;
        $session['warnings']           = $importer->warnings;
        $session['trans_map']          = $importer->translation_map;
        $session['group_source_lang']  = $importer->group_source_lang;
        $session['elementor_post_ids'] = $importer->elementor_post_ids;

        $done = false;

        if ( $rows_all_done ) {
            // Row import is finished — the CSV itself is no longer needed.
            @unlink( $session['file'] );

            global $sitepress;

            $session['rows_done']   = true;
            $session['link_groups'] = array_keys( $importer->translation_map );
            $session['link_offset'] = 0;

            // Nothing to link (WPML inactive, or a single-language import) —
            // the whole session is done right here. Checked once, up front,
            // so a missing WPML doesn't add its warning again on every
            // subsequent linking batch.
            if ( empty( $session['link_groups'] ) || ! $sitepress ) {
                if ( ! $sitepress && ! empty( $session['link_groups'] ) ) {
                    $importer->warnings[] = 'WPML is not active – translations were not linked.';
                    $session['warnings']  = $importer->warnings;
                }
                self::regenerate_elementor_css( $importer->elementor_post_ids );
                delete_transient( $key );
                $done = true;
            }
        }

        if ( ! $done ) {
            set_transient( $key, $session, self::SESSION_TTL );
        }

        return [
            'processed'     => $batch_count,
            'offset'        => $new_offset,
            'total'         => $session['total'],
            'done'          => $done,
            'phase'         => 'rows',
            'counts'        => $importer->counts,
            'errors'        => $importer->errors,
            'warnings'      => $importer->warnings,
            'has_elementor' => ! empty( $importer->elementor_post_ids ),
        ];
    }

    /**
     * Build a batch result for a fatal, un-retryable condition (session
     * expired/vanished, or its backing file disappeared mid-import).
     *
     * Must carry the same keys as a normal process_batch() result — the
     * progress UI's JS (see WPML_Porter_Admin::render_progress_ui()) reads
     * d.counts, d.offset, d.warnings, etc. unconditionally on every response.
     * Returning a bare ['done' => true, 'errors' => [...]] left those
     * undefined, which threw inside the JS success handler (e.g.
     * setStats(undefined)); the thrown error was then caught by the generic
     * network-error handler, which retried the "batch" several times and
     * only then showed a generic "stopped after repeated failures" message
     * instead of immediately surfacing the real, un-retryable reason.
     *
     * @param  string $message
     * @return array
     */
    private static function fatal_result( string $message ): array {
        return [
            'processed'     => 0,
            'offset'        => 0,
            'total'         => 0,
            'done'          => true,
            'phase'         => 'rows',
            'counts'        => [ 'inserted' => 0, 'updated' => 0, 'linked' => 0, 'skipped' => 0 ],
            'errors'        => [ $message ],
            'warnings'      => [],
            'has_elementor' => false,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // LEGACY SINGLE-REQUEST MODE
    // ────────────────────────────────────────────────────────────────────

    public function import_from_file( string $file_path ): bool {

        if ( ! file_exists( $file_path ) ) {
            $this->errors[] = 'File not found: ' . esc_html( $file_path );
            return false;
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            $this->errors[] = 'Unable to open file.';
            return false;
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            $this->errors[] = 'Empty or unreadable CSV.';
            return false;
        }
        $header = array_map( 'trim', $header );

        while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
            $data = [];
            foreach ( $header as $i => $key ) {
                $data[ $key ] = $raw[ $i ] ?? '';
            }
            $this->process_row( $data );
        }

        fclose( $handle );
        $this->link_translations();
        self::regenerate_elementor_css( $this->elementor_post_ids );

        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Core row processor
    // ────────────────────────────────────────────────────────────────────

    private function process_row( array $data ): void {

        $title              = trim( $data['post_title'] ?? '' );
        $language_code      = trim( $data['_wpml_import_language_code'] ?? '' );
        $has_source_column  = array_key_exists( '_wpml_import_source_language_code', $data );
        $source_language    = trim( $data['_wpml_import_source_language_code'] ?? '' );
        $translation_group  = trim( $data['_wpml_import_translation_group'] ?? '' );

        if ( empty( $title ) ) {
            $this->counts['skipped']++;
            return;
        }

        $post_id = $this->find_existing_post( $translation_group, $language_code );

        $post_data = [
            'post_type'    => $this->post_type,
            'post_title'   => $title,
            'post_content' => $this->absolutize_urls_in_string( $data['post_content'] ?? '' ),
            'post_excerpt' => $this->absolutize_urls_in_string( $data['post_excerpt'] ?? '' ),
            'post_status'  => $data['post_status']  ?: 'publish',
            'menu_order'   => (int) ( $data['menu_order'] ?? 0 ),
        ];

        if ( ! empty( $data['post_date'] ) ) {
            $post_data['post_date']     = $data['post_date'];
            $post_data['post_date_gmt'] = get_gmt_from_date( $data['post_date'] );
            $post_data['edit_date']     = true;
        }

        if ( ! empty( $data['post_author'] ) ) {
            $post_data['post_author'] = (int) $data['post_author'];
        }

        if ( $post_id ) {
            $post_data['ID'] = $post_id;
            $result = wp_update_post( $post_data, true );
            if ( is_wp_error( $result ) ) {
                $this->errors[] = 'Update failed for "' . esc_html( $title ) . '": ' . $result->get_error_message();
                return;
            }
            $this->counts['updated']++;
        } else {
            // Temporarily unhook WPML's own insert handler so it doesn't register
            // the post in whatever the current active language happens to be.
            // We will register it correctly ourselves right after insertion.
            global $sitepress;
            if ( $sitepress ) {
                remove_action( 'save_post', [ $sitepress, 'save_post_actions' ], 100 );
            }

            $result = wp_insert_post( $post_data, true );

            if ( $sitepress ) {
                add_action( 'save_post', [ $sitepress, 'save_post_actions' ], 100 );
            }

            if ( is_wp_error( $result ) ) {
                $this->errors[] = 'Insert failed for "' . esc_html( $title ) . '": ' . $result->get_error_message();
                return;
            }
            $post_id = $result;
            $this->counts['inserted']++;
        }

        // The exporter always writes an explicit _wpml_import_source_language_code
        // column: blank means "this row is the group's original", a filled
        // value names the language it was translated from. That's the
        // authoritative signal for which row is the source — it must NOT be
        // inferred from whether this row's own language happens to match the
        // "Default language" chosen on the Import screen, since a translation
        // group's real source language can differ from that dropdown (e.g. a
        // CPT authored in a non-English language, or an import spanning groups
        // with different source languages). Older CSVs without the column at
        // all fall back to the previous heuristic so nothing regresses there.
        $is_source       = $has_source_column ? ( $source_language === '' ) : ( $language_code === $this->default_language );
        $source_lang_arg = $is_source ? null : ( $source_language !== '' ? $source_language : $this->default_language );

        // Register the correct language in WPML immediately after insert/update.
        // This prevents the Classic Editor from loading a blank page and ensures
        // icl_translations has the right row before the translation-linking step.
        global $sitepress;
        if ( $sitepress && ! empty( $language_code ) ) {
            $element_type = 'post_' . $this->post_type;

            $sitepress->set_element_language_details(
                $post_id,
                $element_type,
                false,           // trid = false → let WPML create/find it; will be corrected in link_translations()
                $language_code,
                $source_lang_arg
            );
        }

        // Taxonomies
        //
        // WPML filters term lookups (get_term_by(), get_terms()) to whatever
        // its "current language" is for any taxonomy set to a Translatable
        // mode — the same reason the exporter switch_lang()s per language
        // before reading posts (see WPML_Porter_Exporter::build_csv()). A
        // batch here processes rows for many different languages back to
        // back without ever changing that "current language" away from the
        // site default, so resolve_term_ids()'s get_term_by() could fail to
        // find an existing term that's genuinely in this row's language (it's
        // filtered out because it doesn't match the *default* language),
        // create a duplicate via wp_insert_term() instead, and — since that
        // duplicate is created without a language switch either — end up
        // tagged with the default language rather than this row's language.
        // That is what produced the mismatched/duplicated taxonomy terms.
        // Switching to this row's language for the lookup/creation/assignment
        // keeps every WPML-aware term call in the right language context.
        if ( $sitepress && ! empty( $language_code ) ) {
            $sitepress->switch_lang( $language_code, true );
        }

        foreach ( $data as $key => $value ) {
            if ( strpos( $key, 'tax:' ) !== 0 || $value === '' ) continue;
            $taxonomy = substr( $key, 4 );
            $slugs    = array_filter( array_map( 'trim', explode( '|', $value ) ) );
            $term_ids = $this->resolve_term_ids( $taxonomy, $slugs );
            if ( $term_ids !== false ) {
                wp_set_post_terms( $post_id, $term_ids, $taxonomy );
            }
        }

        if ( $sitepress && ! empty( $language_code ) ) {
            $sitepress->switch_lang( null, true );
        }

        // Custom fields — always imported as real content. We only leave an
        // informational note if a post was built with a page builder that
        // doesn't appear to be active here; we never discard the data itself
        // (that includes _elementor_data, the actual page layout — dropping
        // it would both lose the content and break Elementor's own frontend
        // rendering, which checks for _elementor_edit_mode to decide whether
        // to take over rendering at all).
        $builder_notice   = $this->detect_inactive_builder( $data );
        $has_elementor     = false;
        foreach ( $data as $key => $value ) {
            if ( strpos( $key, 'meta:' ) !== 0 ) continue;
            $meta_key = substr( $key, 5 );

            // Elementor's own CSS cache descriptor — never import it as-is.
            // It points at a compiled CSS file that only exists on the
            // source site; keeping it makes Elementor on this site believe
            // the CSS is already generated (and current), so the page loses
            // its styling. Skipping it here means Elementor compiles a
            // fresh CSS file for the new site the first time the page is
            // viewed (or when the regeneration below runs) — same visual
            // result, correct source. Everything else (_elementor_data,
            // _elementor_page_settings, _elementor_edit_mode, etc.) is real
            // content/config and is always imported unchanged.
            if ( $meta_key === '_elementor_css' || $meta_key === '_elementor_page_assets' ) {
                continue;
            }
            if ( strpos( $meta_key, '_elementor' ) === 0 ) {
                $has_elementor = true;
            }

            // The featured image column now carries the attachment's relative
            // uploads path (see WPML_Porter_Exporter), not a raw attachment ID
            // — that ID would be meaningless on this database. Resolve it to
            // whatever local attachment matches that path instead.
            if ( $meta_key === '_thumbnail_id' ) {
                $this->import_featured_image( $post_id, (string) $value, $title, $language_code );
                continue;
            }

            $meta_value = maybe_unserialize( $value );
            $meta_value = $this->absolutize_value( $meta_value );
            update_post_meta( $post_id, $meta_key, $meta_value );
        }

        if ( $has_elementor ) {
            $this->elementor_post_ids[] = $post_id;
        }

        if ( $builder_notice ) {
            $this->warnings[] = sprintf(
                'Post "%s" was built with %s, which doesn\'t appear to be active on this site. ' .
                'The layout data was imported as-is (nothing was discarded) — activate %s to see it render correctly.',
                esc_html( $title ),
                $builder_notice,
                $builder_notice
            );
        }

        // WPML helper meta
        update_post_meta( $post_id, '_wpml_import_language_code',        $language_code );
        update_post_meta( $post_id, '_wpml_import_source_language_code', $source_language );
        update_post_meta( $post_id, '_wpml_import_translation_group',    $translation_group );

        // Accumulate translation map
        if ( ! empty( $translation_group ) && ! empty( $language_code ) ) {
            $this->translation_map[ $translation_group ][ $language_code ] = $post_id;

            if ( $is_source ) {
                $this->group_source_lang[ $translation_group ] = $language_code;
            }
        }

    }

    // ────────────────────────────────────────────────────────────────────
    // WPML linking
    // ────────────────────────────────────────────────────────────────────

    private function link_translations(): void {
        if ( empty( $this->translation_map ) ) return;
        $this->link_translations_slice( array_keys( $this->translation_map ) );
    }

    /**
     * Link (and mark complete) translations for a subset of translation
     * groups only. Used by process_batch() to spread the linking work for a
     * large import across several Ajax calls instead of doing it all in one
     * request — see process_batch()'s linking-phase comment for why.
     *
     * @param string[] $groups Keys into $this->translation_map to process.
     */
    private function link_translations_slice( array $groups ): void {
        global $sitepress;

        if ( ! $sitepress ) {
            if ( ! empty( $this->translation_map ) ) {
                $this->warnings[] = 'WPML is not active – translations were not linked.';
            }
            return;
        }

        $element_type = 'post_' . $this->post_type;

        foreach ( $groups as $group ) {
            if ( ! isset( $this->translation_map[ $group ] ) ) continue;
            $translations = $this->translation_map[ $group ];

            // Prefer the language the CSV itself marked as this group's source
            // (see process_row()) over guessing from the import's default
            // language, since a group's real source can differ from that
            // setting. Only fall back to the old default-language/first-seen
            // heuristic when that signal isn't available (e.g. a legacy CSV
            // without the source-language column).
            $source_lang = $this->group_source_lang[ $group ] ?? null;
            if ( $source_lang === null || ! isset( $translations[ $source_lang ] ) ) {
                $source_lang = isset( $translations[ $this->default_language ] )
                    ? $this->default_language
                    : array_key_first( $translations );
            }

            $source_post_id = (int) $translations[ $source_lang ];

            $sitepress->set_element_language_details(
                $source_post_id, $element_type, false, $source_lang, null
            );

            $trid = $sitepress->get_element_trid( $source_post_id, $element_type );
            if ( ! $trid ) {
                $this->warnings[] = 'Could not get/create TRID for group ' . esc_html( $group );
                continue;
            }

            foreach ( $translations as $lang_code => $translated_post_id ) {
                if ( $lang_code === $source_lang ) continue;

                $translated_post_id = (int) $translated_post_id;

                $sitepress->set_element_language_details(
                    $translated_post_id, $element_type, $trid, $lang_code, $source_lang
                );

                // Mark the translation as complete in WPML's translation queue.
                // Without this, the Translation Editor treats the post as a fresh
                // (untranslated) job and opens a blank editor instead of the content.
                $this->mark_translation_complete( $translated_post_id, $source_post_id, $lang_code, $source_lang );

                $this->counts['linked']++;
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────

    private function find_existing_post( string $translation_group, string $language_code ): ?int {
        if ( empty( $translation_group ) || empty( $language_code ) ) return null;

        $posts = get_posts( [
            'post_type'        => $this->post_type,
            'post_status'      => 'any',
            'posts_per_page'   => 1,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'meta_query'       => [
                'relation' => 'AND',
                [ 'key' => '_wpml_import_translation_group', 'value' => $translation_group ],
                [ 'key' => '_wpml_import_language_code',     'value' => $language_code ],
            ],
        ] );

        return ! empty( $posts ) ? (int) $posts[0] : null;
    }

    /**
     * Mark a translated post as "translation complete" in WPML's tracking tables.
     *
     * WPML stores translation status in icl_translation_status (linked via
     * icl_translate_job). When this record is missing or set to "needs update",
     * the Translation Editor opens a blank job instead of loading existing content.
     *
     * We use the public `wpml_translation_job_data` + direct DB write as the WPML
     * API does not expose a single function for this in all versions.
     *
     * @param int    $translated_post_id
     * @param int    $source_post_id
     * @param string $target_lang
     * @param string $source_lang
     */
    private function mark_translation_complete( int $translated_post_id, int $source_post_id, string $target_lang, string $source_lang ): void {
        global $wpdb;

        // Resolve the rid (translation_status row id) from icl_translations
        $translated_rid = $wpdb->get_var( $wpdb->prepare(
            "SELECT translation_id FROM {$wpdb->prefix}icl_translations
             WHERE element_id = %d AND element_type = %s AND language_code = %s
             LIMIT 1",
            $translated_post_id,
            'post_' . $this->post_type,
            $target_lang
        ) );

        if ( ! $translated_rid ) {
            return;
        }

        // Build a translation package that WPML's Translation Editor reads to
        // pre-populate its fields. Without this, the editor opens a blank new job
        // even though the content was already imported.
        $trans_title   = get_post_field( 'post_title',   $translated_post_id );
        $trans_content = get_post_field( 'post_content',  $translated_post_id );
        $trans_excerpt = get_post_field( 'post_excerpt',  $translated_post_id );
        $source_content = get_post_field( 'post_content', $source_post_id );
        $source_title   = get_post_field( 'post_title',   $source_post_id );

        $translation_package = maybe_serialize( [
            'contents' => [
                'title'   => [ 'translate' => 1, 'data' => base64_encode( $trans_title ),   'format' => 'base64' ],
                'body'    => [ 'translate' => 1, 'data' => base64_encode( $trans_content ), 'format' => 'base64' ],
                'excerpt' => [ 'translate' => 1, 'data' => base64_encode( $trans_excerpt ), 'format' => 'base64' ],
            ],
            'format'   => 'WPML Package Exchange Document 1.0',
            'language' => $target_lang,
        ] );

        $md5 = md5( $source_content . $source_title );

        $existing_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT rid FROM {$wpdb->prefix}icl_translation_status WHERE rid = %d",
            $translated_rid
        ) );

        if ( $existing_status ) {
            $wpdb->update(
                $wpdb->prefix . 'icl_translation_status',
                [
                    'status'              => 10, // ICL_TM_COMPLETE
                    'needs_update'        => 0,
                    'translation_package' => $translation_package,
                    'md5'                 => $md5,
                ],
                [ 'rid' => $translated_rid ],
                [ '%d', '%d', '%s', '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $wpdb->prefix . 'icl_translation_status',
                [
                    'rid'                  => $translated_rid,
                    'status'               => 10, // ICL_TM_COMPLETE
                    'needs_update'         => 0,
                    'translator_id'        => 0,
                    'translation_service'  => 'local',
                    'translation_package'  => $translation_package,
                    'timestamp'            => current_time( 'mysql' ),
                    'links_fixed'          => 0,
                    'md5'                  => $md5,
                ],
                [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
            );
        }

        // Ensure a completed icl_translate_job row exists. Without it, clicking
        // "Translation Editor" in WPML creates a fresh blank job rather than
        // recognising the already-imported translated content.
        $jobs_table = $wpdb->prefix . 'icl_translate_job';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) ) === $jobs_table ) {
            $existing_job = $wpdb->get_var( $wpdb->prepare(
                "SELECT job_id FROM {$jobs_table} WHERE rid = %d LIMIT 1",
                $translated_rid
            ) );

            if ( ! $existing_job ) {
                $wpdb->insert(
                    $jobs_table,
                    [
                        'rid'           => (int) $translated_rid,
                        'editor'        => 'local',
                        'translated'    => 1,
                        'translator_id' => 0,
                    ],
                    [ '%d', '%s', '%d', '%d' ]
                );
            } else {
                $wpdb->update(
                    $jobs_table,
                    [ 'translated' => 1 ],
                    [ 'job_id'     => (int) $existing_job ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // REPAIR  (fixes posts already imported before the builder-meta fix)
    // ────────────────────────────────────────────────────────────────────

    /**
     * Repair one batch of already-imported posts:
     *  - deletes page-builder meta that should have been stripped at import time
     *  - patches icl_translation_status (proper package) and icl_translate_job
     *
     * @param  int    $offset        Which post to start from (0-based).
     * @param  int    $batch_size    Posts to process per call.
     * @return array { processed, offset, total, done, cleaned_meta, fixed_links }
     */
    /**
     * Repair one batch of already-imported posts: patches
     * icl_translation_status (proper package) and icl_translate_job so
     * WPML's Translation Management screen shows them correctly.
     *
     * (This tool previously also deleted page-builder meta such as
     * _elementor_data from posts when the matching builder plugin wasn't
     * detected as active — that behaviour has been removed. It was real
     * content loss: it silently deleted a post's actual Elementor/Divi/
     * Beaver Builder layout data from the database. Builder content is now
     * always preserved; see process_row() / detect_inactive_builder().)
     *
     * @param  int    $offset        Which post to start from (0-based).
     * @param  int    $batch_size    Posts to process per call.
     * @return array { processed, offset, total, done, cleaned_meta, fixed_links }
     */
    public static function repair_batch( int $offset = 0, int $batch_size = 30 ): array {
        global $wpdb, $sitepress;

        // Find all posts that WPML Porter imported (they carry our marker meta)
        $all_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wpml_import_translation_group'
             ORDER BY post_id ASC"
        );

        $total     = count( $all_ids );
        $batch_ids = array_slice( $all_ids, $offset, $batch_size );
        $cleaned   = 0;
        $fixed     = 0;

        $relinked = 0;

        foreach ( $batch_ids as $post_id ) {
            $post_id = (int) $post_id;

            // ── Fix WPML status tables for translated posts ────────────────
            if ( ! $sitepress ) continue;

            $lang_code         = get_post_meta( $post_id, '_wpml_import_language_code', true );
            $translation_group = get_post_meta( $post_id, '_wpml_import_translation_group', true );
            $post_type         = get_post_type( $post_id );
            if ( ! $lang_code || ! $post_type ) continue;

            $element_type = 'post_' . $post_type;

            // If this post was imported by a session that got interrupted
            // before the linking phase ever ran (e.g. a large import that
            // hit the PHP memory/time limit before this plugin's batched
            // linking was added), it carries our own _wpml_import_* meta but
            // was never actually registered with WPML at all — WPML has no
            // record of it whatsoever, not even as a lone/unlinked element.
            // The translation_status patch below can only ever help a post
            // WPML already recognises as a translation, so re-establish that
            // linking first, from our own meta, exactly as a normal import
            // would have.
            $lang_details = $sitepress->get_element_language_details( $post_id, $element_type );
            if ( ( ! $lang_details || empty( $lang_details->language_code ) ) && $translation_group !== '' ) {
                self::relink_translation_group( $translation_group, $post_type, $sitepress );
                $lang_details = $sitepress->get_element_language_details( $post_id, $element_type );
                if ( $lang_details ) $relinked++;
            }

            // Only fix translated (non-source) posts
            if ( ! $lang_details || empty( $lang_details->source_language_code ) ) continue;

            $source_lang = $lang_details->source_language_code;
            $trid        = $sitepress->get_element_trid( $post_id, $element_type );
            if ( ! $trid ) continue;

            // Find the source post for this translation group
            $source_id = apply_filters( 'wpml_object_id', $post_id, $post_type, false, $source_lang );
            if ( ! $source_id || (int) $source_id === $post_id ) continue;

            $translated_rid = $wpdb->get_var( $wpdb->prepare(
                "SELECT translation_id FROM {$wpdb->prefix}icl_translations
                 WHERE element_id = %d AND element_type = %s AND language_code = %s LIMIT 1",
                $post_id, $element_type, $lang_code
            ) );
            if ( ! $translated_rid ) continue;

            $trans_title   = get_post_field( 'post_title',   $post_id );
            $trans_content = get_post_field( 'post_content',  $post_id );
            $trans_excerpt = get_post_field( 'post_excerpt',  $post_id );
            $src_content   = get_post_field( 'post_content', (int) $source_id );
            $src_title     = get_post_field( 'post_title',   (int) $source_id );

            $package = maybe_serialize( [
                'contents' => [
                    'title'   => [ 'translate' => 1, 'data' => base64_encode( $trans_title ),   'format' => 'base64' ],
                    'body'    => [ 'translate' => 1, 'data' => base64_encode( $trans_content ), 'format' => 'base64' ],
                    'excerpt' => [ 'translate' => 1, 'data' => base64_encode( $trans_excerpt ), 'format' => 'base64' ],
                ],
                'format'   => 'WPML Package Exchange Document 1.0',
                'language' => $lang_code,
            ] );
            $md5 = md5( $src_content . $src_title );

            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT rid FROM {$wpdb->prefix}icl_translation_status WHERE rid = %d", $translated_rid
            ) );

            if ( $exists ) {
                $wpdb->update(
                    $wpdb->prefix . 'icl_translation_status',
                    [ 'status' => 10, 'needs_update' => 0, 'translation_package' => $package, 'md5' => $md5 ],
                    [ 'rid' => $translated_rid ],
                    [ '%d', '%d', '%s', '%s' ],
                    [ '%d' ]
                );
            } else {
                $wpdb->insert(
                    $wpdb->prefix . 'icl_translation_status',
                    [
                        'rid'                 => $translated_rid, 'status' => 10, 'needs_update' => 0,
                        'translator_id'       => 0, 'translation_service' => 'local',
                        'translation_package' => $package, 'timestamp' => current_time( 'mysql' ),
                        'links_fixed'         => 0, 'md5' => $md5,
                    ],
                    [ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
                );
            }

            $jobs_table = $wpdb->prefix . 'icl_translate_job';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $jobs_table ) ) === $jobs_table ) {
                $job = $wpdb->get_var( $wpdb->prepare(
                    "SELECT job_id FROM {$jobs_table} WHERE rid = %d LIMIT 1", $translated_rid
                ) );
                if ( ! $job ) {
                    $wpdb->insert( $jobs_table,
                        [ 'rid' => (int) $translated_rid, 'editor' => 'local', 'translated' => 1, 'translator_id' => 0 ],
                        [ '%d', '%s', '%d', '%d' ]
                    );
                } else {
                    $wpdb->update( $jobs_table, [ 'translated' => 1 ], [ 'job_id' => (int) $job ], [ '%d' ], [ '%d' ] );
                }
            }

            $fixed++;
        }

        $new_offset = $offset + count( $batch_ids );

        return [
            'processed'    => count( $batch_ids ),
            'offset'       => $new_offset,
            'total'        => $total,
            'done'         => $new_offset >= $total,
            'cleaned_meta' => $cleaned,
            'fixed_links'  => $fixed,
            'relinked'     => $relinked,
        ];
    }

    /**
     * Re-establish WPML's set_element_language_details()/trid linking for one
     * of this plugin's translation groups, using our own tracking meta
     * (_wpml_import_translation_group / _wpml_import_language_code /
     * _wpml_import_source_language_code) as the source of truth.
     *
     * Used by repair_batch() for posts that carry our meta but that WPML has
     * no record of at all — e.g. because the import session that created
     * them was interrupted (large-import timeout/memory limit) before ever
     * reaching the linking phase in process_batch()/link_translations(). A
     * post in that state isn't just missing its translation_package — WPML
     * doesn't know it exists as a translation (or a source) at all, so the
     * icl_translation_status patch further up repair_batch() has nothing to
     * attach to.
     *
     * Mirrors link_translations_slice()'s two-pass approach (source first, so
     * WPML creates/reuses a trid; translations reference it) but
     * reconstructed from postmeta instead of an in-memory session map, since
     * repair runs after the fact and no such map exists any more.
     *
     * @param string $translation_group
     * @param string $post_type
     * @param object $sitepress
     */
    private static function relink_translation_group( string $translation_group, string $post_type, $sitepress ): void {
        global $wpdb;

        $element_type = 'post_' . $post_type;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm1.post_id, pm2.meta_value AS lang_code, pm3.meta_value AS source_lang
             FROM {$wpdb->postmeta} pm1
             INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id AND pm2.meta_key = '_wpml_import_language_code'
             LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = pm1.post_id AND pm3.meta_key = '_wpml_import_source_language_code'
             WHERE pm1.meta_key = '_wpml_import_translation_group' AND pm1.meta_value = %s
               AND pm2.meta_value != ''",
            $translation_group
        ) );

        if ( ! $rows ) return;

        // Prefer the row the CSV itself marked as this group's source (blank
        // source-language column) — same authoritative signal process_row()
        // uses. Fall back to the site's default language, then whatever's first.
        $source_row = null;
        foreach ( $rows as $r ) {
            if ( (string) $r->source_lang === '' ) { $source_row = $r; break; }
        }
        if ( ! $source_row ) {
            $default_lang = method_exists( $sitepress, 'get_default_language' ) ? $sitepress->get_default_language() : '';
            foreach ( $rows as $r ) {
                if ( $r->lang_code === $default_lang ) { $source_row = $r; break; }
            }
            $source_row = $source_row ?: $rows[0];
        }

        $sitepress->set_element_language_details(
            (int) $source_row->post_id, $element_type, false, $source_row->lang_code, null
        );

        $trid = $sitepress->get_element_trid( (int) $source_row->post_id, $element_type );
        if ( ! $trid ) return;

        foreach ( $rows as $r ) {
            if ( (int) $r->post_id === (int) $source_row->post_id ) continue;
            $sitepress->set_element_language_details(
                (int) $r->post_id, $element_type, $trid, $r->lang_code, $source_row->lang_code
            );
        }
    }

    /**
     * Returns false for page-builder meta keys when that builder is not active.
     * Prevents Elementor/Divi/Beaver Builder scripts from loading on sites where
     * those plugins are absent, which causes wp.template errors and blank editors.
     */
    /**
     * Identify which page builder (if any) a row's meta indicates the post
     * was built with, and whether that builder doesn't currently appear to
     * be active on this site. Used only to surface an informational warning
     * — never to decide whether to import the data (all meta is always
     * imported as-is).
     *
     * @param  array $data  The row's raw CSV data (meta:* columns).
     * @return string|null  Human-readable builder name, or null if none
     *                      detected / the matching builder is active.
     */
    private function detect_inactive_builder( array $data ): ?string {
        static $builder_guards = [
            '_elementor_edit_mode' => [ 'ELEMENTOR_VERSION',        'Elementor' ],
            '_et_pb_use_builder'   => [ 'ET_BUILDER_PLUGIN_ACTIVE', 'Divi Builder' ],
            '_fl_builder_enabled'  => [ 'FL_BUILDER_VERSION',       'Beaver Builder' ],
        ];

        foreach ( $builder_guards as $meta_key => [ $constant, $label ] ) {
            if ( isset( $data[ 'meta:' . $meta_key ] ) && $data[ 'meta:' . $meta_key ] !== '' && ! defined( $constant ) ) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Force Elementor to recompile CSS for imported posts, once, after the
     * whole import session finishes.
     *
     * Why this is needed: Elementor compiles each page's styles into a
     * static CSS file the first time it's viewed (or edited), and remembers
     * that it's done so via the `_elementor_css` post meta. We deliberately
     * don't import that meta (see process_row()) since it points at a file
     * that only exists on the source site — but Elementor also keeps a
     * site-wide cache of things like global fonts/icons that's worth
     * clearing too, so imported pages render with correct, current styling
     * on first view instead of any stale/missing CSS.
     *
     * This mirrors what Elementor → Tools → "Regenerate CSS & Data" does —
     * we're just triggering it automatically so the user doesn't have to
     * remember to click it after every import.
     *
     * @param int[] $post_ids
     */
    private static function regenerate_elementor_css( array $post_ids ): void {
        if ( empty( $post_ids ) || ! did_action( 'elementor/loaded' ) && ! class_exists( '\Elementor\Plugin' ) ) {
            return;
        }

        try {
            if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
                // Site-wide cache (global CSS, fonts/icons cache, etc.)
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }

            // Belt-and-braces: explicitly delete any per-post CSS file meta
            // that might still exist (e.g. re-running an import over posts
            // that already had a coincidentally-valid-looking cache entry),
            // so every imported post is guaranteed to recompile fresh.
            foreach ( array_unique( $post_ids ) as $post_id ) {
                delete_post_meta( $post_id, '_elementor_css' );
            }
        } catch ( \Throwable $e ) {
            // Non-fatal — worst case the user runs Elementor's own
            // "Regenerate CSS & Data" tool manually. Don't let a cache
            // clear failure affect the (already-successful) content import.
        }
    }

    /**
     * Resolve an exported featured-image reference (a relative uploads path,
     * e.g. "/wp-content/uploads/2026/06/street-side-health-camp.jpg") to a
     * local attachment ID and set it as the post's _thumbnail_id — or leave
     * it unset with a warning if no matching file exists in this site's
     * media library yet (this plugin doesn't migrate the media library
     * itself, only the CPT content that references it).
     *
     * @param int    $post_id
     * @param string $value    Relative path (new format) or a bare numeric
     *                         attachment ID (older exports, kept working).
     * @param string $title    Post title, for the warning message only.
     * @param string $language_code Imported post language, if WPML is active.
     */
    private function import_featured_image( int $post_id, string $value, string $title, string $language_code = '' ): void {
        $value = trim( $value );
        if ( $value === '' ) return;

        // Back-compat: exports made before this fix stored a raw attachment
        // ID here. It will only resolve correctly if re-importing into the
        // very same database, but honour it rather than silently dropping it.
        if ( ctype_digit( $value ) ) {
            if ( get_post_type( (int) $value ) === 'attachment' ) {
                update_post_meta( $post_id, '_thumbnail_id', (int) $value );
            } else {
                delete_post_meta( $post_id, '_thumbnail_id' );
                $this->warnings[] = sprintf( 'Post "%s": legacy featured-image ID %d is not a local attachment, so it was left unset.', esc_html( $title ), (int) $value );
            }
            return;
        }

        $attachment_id = $this->find_attachment_by_relative_path( $value );

        // Fall back to WordPress's own URL matcher for anything our
        // path-based lookup didn't cover.
        if ( ! $attachment_id ) {
            $attachment_id = attachment_url_to_postid( home_url( $value ) );
        }

        // When WPML Media has a translated attachment for this image, use the
        // matching local-language attachment rather than an arbitrary member
        // of the media translation group.
        if ( $attachment_id && $language_code !== '' ) {
            $translated_attachment_id = apply_filters( 'wpml_object_id', $attachment_id, 'attachment', false, $language_code );
            if ( $translated_attachment_id ) {
                $attachment_id = (int) $translated_attachment_id;
            }
        }

        if ( $attachment_id && get_post_type( $attachment_id ) === 'attachment' ) {
            update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
        } else {
            delete_post_meta( $post_id, '_thumbnail_id' );
            $this->warnings[] = sprintf(
                'Post "%s": featured image (%s) was not found in this site\'s media library, so it was left unset — upload the file (matching path) or re-select the featured image manually.',
                esc_html( $title ),
                esc_html( $value )
            );
        }
    }

    /**
     * Match a relative uploads path against the _wp_attached_file meta that
     * every attachment stores (itself relative to the uploads *base* dir,
     * e.g. "2026/06/street-side-health-camp.jpg"), after stripping the
     * uploads-dir prefix and any WordPress-generated "-WIDTHxHEIGHT" size
     * suffix from the incoming path.
     *
     * @param  string $relative_path
     * @return int|null
     */
    private function find_attachment_by_relative_path( string $relative_path ): ?int {
        global $wpdb;

        // Work entirely in URL-path space (never string-match against
        // home_url()) so this still works when the uploads URL doesn't share
        // a literal prefix with home_url() — e.g. WordPress installed in a
        // subdirectory with a different Home URL than Site URL, or an
        // uploads baseurl remapped to a CDN/subdomain.
        $uploads_path = trim( (string) parse_url( wp_upload_dir()['baseurl'], PHP_URL_PATH ), '/' );

        // Attachment meta stores a decoded, slash-normalized path. URLs in a
        // CSV can legitimately be encoded (spaces/non-ASCII names) or come
        // from a site installed under a different subdirectory.
        $path = trim( str_replace( '\\', '/', rawurldecode( (string) parse_url( $relative_path, PHP_URL_PATH ) ) ), '/' );

        if ( $uploads_path !== '' ) {
            if ( stripos( $path, $uploads_path . '/' ) === 0 ) {
                $path = substr( $path, strlen( $uploads_path ) + 1 );
            } elseif ( strcasecmp( $path, $uploads_path ) === 0 ) {
                $path = '';
            }
        }

        // The source and destination can have different WordPress/home
        // subdirectories. For example, an export may contain
        // /wp-content/uploads/2026/02/file.jpg while the destination base URL
        // is /apf-clone/wp-content/uploads. In that case the exact destination
        // prefix above cannot match, but both paths still share the actual
        // uploads directory segment. Strip through that segment to obtain the
        // format WordPress stores in _wp_attached_file: 2026/02/file.jpg.
        $uploads_dir_name = wp_basename( $uploads_path );
        if ( $uploads_dir_name !== '' && preg_match( '#(?:^|/)' . preg_quote( $uploads_dir_name, '#' ) . '/(.+)$#i', $path, $matches ) ) {
            $path = $matches[1];
        }

        // Drop a "-768x432" style size suffix so a resized/cropped URL still
        // matches the original file's stored path.
        $path = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $path );

        if ( $path === '' ) return null;

        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s AND p.post_type = 'attachment' LIMIT 1",
            $path
        ) );

        if ( $found ) return (int) $found;

        // Last resort: match on the bare filename only (case-insensitive,
        // ignoring folder). Covers media that was re-uploaded/re-organized
        // into a different year/month folder since the export was made.
        $filename = wp_basename( $path );
        if ( $filename === '' ) return null;

        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value LIKE %s AND p.post_type = 'attachment' LIMIT 1",
            '%' . $wpdb->esc_like( $filename )
        ) );

        return $found ? (int) $found : null;
    }

    /**
     * Recursively convert any root-relative uploads-path string produced by
     * the exporter (e.g. "/wp-content/uploads/2026/06/x.jpg") back into a
     * fully-qualified URL on *this* site. Run on every imported custom field
     * value so ACF/plain URL fields come back as complete, working URLs
     * rather than bare paths — relative paths render fine inside HTML pages,
     * but break in contexts like email templates, feeds, or APIs that need
     * an absolute URL.
     *
     * @param  mixed $value
     * @return mixed
     */
    private function absolutize_value( $value ) {
        if ( is_string( $value ) ) {
            return $this->absolutize_urls_in_string( $value );
        }
        if ( is_array( $value ) ) {
            return array_map( [ $this, 'absolutize_value' ], $value );
        }
        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value->$key = $this->absolutize_value( $item );
            }
        }
        return $value;
    }

    /**
     * Convert root-relative internal URLs to this site's base URL. The export
     * format intentionally makes source-site URLs root-relative; converting
     * every such URL (not only uploads) also covers links in translated
     * content, ACF fields, JSON, and other custom fields. Protocol-relative
     * and absolute external URLs are deliberately left untouched.
     *
     * The leading '/' of a root-relative URL is only recognised when it sits
     * where a URL can actually start — the beginning of the string, right
     * after a quote/`(`/`=` (HTML attributes, CSS url(), unquoted attrs), or
     * after whitespace (a bare URL in text/JSON) — and is not immediately
     * followed by another '/', '>' or whitespace. Without those boundaries, a
     * bare '/' inside ordinary markup (e.g. the '/' in a closing tag like
     * `</p>`, or in a self-closing `<img .../>`) would be mistaken for a
     * root-relative URL and get a full domain spliced into the middle of the
     * tag, corrupting the HTML.
     *
     * @param  string $str
     * @return string
     */
    private function absolutize_urls_in_string( string $str ): string {
        if ( $str === '' ) return $str;

        return preg_replace( '#(?:^|(?<=[\'"\s(=]))/(?![/>\s])#', rtrim( home_url(), '/' ) . '/', $str );
    }

    private function resolve_term_ids( string $taxonomy, array $slugs ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            $this->warnings[] = 'Taxonomy not found: ' . esc_html( $taxonomy );
            return false;
        }
        $ids = [];
        foreach ( $slugs as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term ) {
                $result = wp_insert_term( $slug, $taxonomy, [ 'slug' => $slug ] );
                if ( is_wp_error( $result ) ) {
                    $this->warnings[] = 'Could not create term "' . esc_html( $slug ) . '" in ' . esc_html( $taxonomy );
                    continue;
                }
                $ids[] = (int) $result['term_id'];
            } else {
                $ids[] = (int) $term->term_id;
            }
        }
        return $ids;
    }
}
