<?php
defined( 'ABSPATH' ) || exit;

/**
 * WPML Porter Admin
 */
class WPML_Porter_Admin {

    const MENU_SLUG     = 'wpml-porter';
    const NONCE_EXPORT  = 'wpml_porter_export';
    const NONCE_IMPORT  = 'wpml_porter_import';
    const NONCE_BATCH   = 'wpml_porter_batch';
    const NONCE_REPAIR  = 'wpml_porter_repair';
    const NONCE_TERMS_EXPORT   = 'wpml_porter_terms_export';
    const NONCE_TERMS_IMPORT   = 'wpml_porter_terms_import';
    const NONCE_STRUCT_EXPORT  = 'wpml_porter_struct_export';
    const NONCE_STRUCT_IMPORT  = 'wpml_porter_struct_import';
    const NONCE_PACKAGE_EXPORT = 'wpml_porter_package_export';
    const NONCE_PACKAGE_IMPORT = 'wpml_porter_package_import';

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_wpml_porter_export',         [ $this, 'handle_export' ] );
        add_action( 'admin_post_wpml_porter_import',         [ $this, 'handle_import_start' ] );
        add_action( 'admin_post_wpml_porter_terms_export',   [ $this, 'handle_terms_export' ] );
        add_action( 'admin_post_wpml_porter_terms_import',   [ $this, 'handle_terms_import' ] );
        add_action( 'admin_post_wpml_porter_struct_export',  [ $this, 'handle_struct_export' ] );
        add_action( 'admin_post_wpml_porter_struct_import',  [ $this, 'handle_struct_import' ] );
        add_action( 'admin_post_wpml_porter_package_export', [ $this, 'handle_package_export' ] );
        add_action( 'admin_post_wpml_porter_package_import', [ $this, 'handle_package_import' ] );
        add_action( 'wp_ajax_wpml_porter_batch',  [ $this, 'handle_ajax_batch' ] );
        add_action( 'wp_ajax_wpml_porter_repair', [ $this, 'handle_ajax_repair' ] );
        add_filter( 'wpml_translation_job_data', [ $this, 'prefill_translation_job_from_existing' ], 10, 2 );
    }

    // ─── Menu ────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_management_page(
            __( 'WPML Porter', 'wpml-porter' ),
            __( 'WPML Porter', 'wpml-porter' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // ─── Assets ──────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) return;
        wp_add_inline_style( 'wp-admin', $this->inline_css() );
    }

    // ─── Page ────────────────────────────────────────────────────────────

    public function render_page(): void {
        $allowed_tabs = [ 'export', 'import', 'repair', 'taxonomy', 'package' ];
        $active_tab   = in_array( $_GET['tab'] ?? '', $allowed_tabs, true ) ? $_GET['tab'] : 'export';
        $post_types   = $this->get_importable_post_types();
        $languages    = $this->get_wpml_languages();
        $notice       = $this->get_stored_notice();

        // Batch progress mode — session started, show progress UI
        $session_id  = isset( $_GET['session'] ) ? sanitize_text_field( $_GET['session'] ) : '';
        $import_pt   = isset( $_GET['ipt'] )     ? sanitize_key( $_GET['ipt'] )            : '';
        $import_lang = isset( $_GET['ilang'] )   ? sanitize_text_field( $_GET['ilang'] )   : 'en';
        $total_rows  = isset( $_GET['total'] )   ? (int) $_GET['total']                    : 0;
        // Which tab to return to once the batch finishes — 'import' for a plain
        // CSV post import, 'package' when the session was started from a Full
        // Package import (posts.csv extracted from the .zip).
        $done_tab    = isset( $_GET['done_tab'] ) && in_array( $_GET['done_tab'], [ 'import', 'package' ], true )
            ? $_GET['done_tab'] : 'import';
        ?>
        <div class="wrap wpml-porter-wrap">

            <div class="wpml-porter-header">
                <span class="wpml-porter-logo">&#9854;</span>
                <h1><?php esc_html_e( 'WPML Porter', 'wpml-porter' ); ?></h1>
                <p class="wpml-porter-tagline"><?php esc_html_e( 'Import & export any CPT — taxonomies, custom fields, WPML translations.', 'wpml-porter' ); ?></p>
            </div>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible wpml-porter-notice">
                    <?php foreach ( $notice['messages'] as $msg ) : ?>
                        <p><?php echo wp_kses_post( $msg ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <nav class="wpml-porter-tabs">
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=export' ) ); ?>"
                   class="wpml-porter-tab <?php echo $active_tab === 'export' ? 'is-active' : ''; ?>">
                    ↑ <?php esc_html_e( 'Export', 'wpml-porter' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=import' ) ); ?>"
                   class="wpml-porter-tab <?php echo $active_tab === 'import' ? 'is-active' : ''; ?>">
                    ↓ <?php esc_html_e( 'Import', 'wpml-porter' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=taxonomy' ) ); ?>"
                   class="wpml-porter-tab <?php echo $active_tab === 'taxonomy' ? 'is-active' : ''; ?>">
                    &#9783; <?php esc_html_e( 'Taxonomy &amp; ACF', 'wpml-porter' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=package' ) ); ?>"
                   class="wpml-porter-tab <?php echo $active_tab === 'package' ? 'is-active' : ''; ?>">
                    &#128230; <?php esc_html_e( 'Full Package', 'wpml-porter' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=repair' ) ); ?>"
                   class="wpml-porter-tab <?php echo $active_tab === 'repair' ? 'is-active' : ''; ?>">
                    &#10003; <?php esc_html_e( 'Repair', 'wpml-porter' ); ?>
                </a>
            </nav>

            <div class="wpml-porter-body">
                <?php if ( $active_tab === 'export' ) : ?>
                    <?php $this->render_export_tab( $post_types ); ?>

                <?php elseif ( $active_tab === 'import' && $session_id ) : ?>
                    <?php $this->render_progress_ui( $session_id, $import_pt, $import_lang, $total_rows, $done_tab ); ?>

                <?php elseif ( $active_tab === 'import' ) : ?>
                    <?php $this->render_import_tab( $post_types, $languages ); ?>

                <?php elseif ( $active_tab === 'taxonomy' ) : ?>
                    <?php $this->render_taxonomy_tab( $post_types ); ?>

                <?php elseif ( $active_tab === 'package' && $session_id ) : ?>
                    <?php $this->render_progress_ui( $session_id, $import_pt, $import_lang, $total_rows, $done_tab ); ?>

                <?php elseif ( $active_tab === 'package' ) : ?>
                    <?php $this->render_package_tab( $post_types, $languages ); ?>

                <?php elseif ( $active_tab === 'repair' ) : ?>
                    <?php $this->render_repair_tab(); ?>

                <?php else : ?>
                    <?php $this->render_import_tab( $post_types, $languages ); ?>
                <?php endif; ?>
            </div>

            <div class="wpml-porter-footer">
                <p><?php printf(
                    esc_html__( 'WPML Porter v%s', 'wpml-porter' ),
                    esc_html( WPML_PORTER_VERSION )
                ); ?></p>
            </div>

        </div>
        <?php
    }

    // ─── Export tab ──────────────────────────────────────────────────────

    private function render_export_tab( array $post_types ): void { ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpml-porter-form">
            <?php wp_nonce_field( self::NONCE_EXPORT, '_wpml_porter_nonce' ); ?>
            <input type="hidden" name="action" value="wpml_porter_export">

            <div class="wpml-porter-fieldset">
                <h2><?php esc_html_e( 'Export Settings', 'wpml-porter' ); ?></h2>

                <div class="wpml-porter-field">
                    <label for="export_post_type"><?php esc_html_e( 'Post Type', 'wpml-porter' ); ?></label>
                    <select name="export_post_type" id="export_post_type" required>
                        <option value=""><?php esc_html_e( '— Select post type —', 'wpml-porter' ); ?></option>
                        <?php foreach ( $post_types as $slug => $label ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( "$label ($slug)" ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'All WPML languages are exported automatically.', 'wpml-porter' ); ?></p>
                </div>

                <div class="wpml-porter-field">
                    <label><?php esc_html_e( 'Custom Fields (optional)', 'wpml-porter' ); ?></label>
                    <textarea name="export_meta_keys" rows="4"
                        placeholder="<?php esc_attr_e( 'One meta key per line. Leave blank to export ALL.', 'wpml-porter' ); ?>"></textarea>
                </div>

                <div class="wpml-porter-field wpml-porter-field--check">
                    <label>
                        <input type="checkbox" name="export_all_statuses" value="1">
                        <?php esc_html_e( 'Include draft / pending / private posts', 'wpml-porter' ); ?>
                    </label>
                </div>
            </div>

            <button type="submit" class="button button-primary wpml-porter-btn">
                ↓ <?php esc_html_e( 'Download CSV', 'wpml-porter' ); ?>
            </button>
        </form>

        <div class="wpml-porter-info-box">
            <h3><?php esc_html_e( 'CSV Column Format', 'wpml-porter' ); ?></h3>
            <ul>
                <li><code>tax:{taxonomy}</code> — <?php esc_html_e( 'pipe-separated term slugs', 'wpml-porter' ); ?></li>
                <li><code>meta:{key}</code> — <?php esc_html_e( 'custom field (arrays serialized)', 'wpml-porter' ); ?></li>
                <li><code>_wpml_import_language_code</code> — <?php esc_html_e( 'language of this row', 'wpml-porter' ); ?></li>
                <li><code>_wpml_import_source_language_code</code> — <?php esc_html_e( 'original language', 'wpml-porter' ); ?></li>
                <li><code>_wpml_import_translation_group</code> — <?php esc_html_e( 'links translations together', 'wpml-porter' ); ?></li>
            </ul>
        </div>
    <?php }

    // ─── Import tab ──────────────────────────────────────────────────────

    private function render_import_tab( array $post_types, array $languages ): void { ?>
        <form method="post"
              action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              enctype="multipart/form-data"
              class="wpml-porter-form">
            <?php wp_nonce_field( self::NONCE_IMPORT, '_wpml_porter_nonce' ); ?>
            <input type="hidden" name="action" value="wpml_porter_import">

            <div class="wpml-porter-fieldset">
                <h2><?php esc_html_e( 'Import Settings', 'wpml-porter' ); ?></h2>

                <div class="wpml-porter-field">
                    <label for="import_post_type"><?php esc_html_e( 'Target Post Type', 'wpml-porter' ); ?></label>
                    <select name="import_post_type" id="import_post_type" required>
                        <option value=""><?php esc_html_e( '— Select post type —', 'wpml-porter' ); ?></option>
                        <?php foreach ( $post_types as $slug => $label ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( "$label ($slug)" ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="wpml-porter-field">
                    <label for="import_default_lang"><?php esc_html_e( 'Source Language', 'wpml-porter' ); ?></label>
                    <select name="import_default_lang" id="import_default_lang">
                        <?php if ( empty( $languages ) ) : ?>
                            <option value="en">English (en)</option>
                        <?php else : ?>
                            <?php foreach ( $languages as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, 'en' ); ?>>
                                    <?php echo esc_html( "$name ($code)" ); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'The language that other translations are translated from.', 'wpml-porter' ); ?></p>
                </div>

                <div class="wpml-porter-field">
                    <label for="import_csv"><?php esc_html_e( 'CSV File', 'wpml-porter' ); ?></label>
                    <input type="file" name="import_csv" id="import_csv" accept=".csv,text/csv" required>
                    <p class="description"><?php esc_html_e( 'Rows are processed automatically in small batches — no timeout issues.', 'wpml-porter' ); ?></p>
                </div>
            </div>

            <button type="submit" class="button button-primary wpml-porter-btn">
                ↑ <?php esc_html_e( 'Start Import', 'wpml-porter' ); ?>
            </button>
        </form>

        <div class="wpml-porter-info-box">
            <h3><?php esc_html_e( 'Import Behaviour', 'wpml-porter' ); ?></h3>
            <ul>
                <li><?php esc_html_e( 'Existing posts are matched by translation group + language — updated, not duplicated.', 'wpml-porter' ); ?></li>
                <li><?php esc_html_e( 'Missing taxonomy terms are created automatically.', 'wpml-porter' ); ?></li>
                <li><?php esc_html_e( 'WPML translation links are established after all rows are processed.', 'wpml-porter' ); ?></li>
                <li><?php esc_html_e( 'Large files are processed in small batches, streamed from disk — safe against server timeouts even with heavy content like Elementor pages.', 'wpml-porter' ); ?></li>
            </ul>
        </div>
    <?php }

    // ─── Progress UI (batch mode) ─────────────────────────────────────────

    private function render_progress_ui( string $session_id, string $post_type, string $default_lang, int $total_rows, string $done_tab = 'import' ): void {
        $batch_nonce  = wp_create_nonce( self::NONCE_BATCH );
        $ajax_url     = admin_url( 'admin-ajax.php' );
        $done_url     = admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=' . $done_tab );
        ?>
        <div class="wpml-porter-progress-wrap" id="wpml-porter-progress">
            <h2><?php esc_html_e( 'Import in progress…', 'wpml-porter' ); ?></h2>

            <div class="wpml-porter-progress-bar-wrap">
                <div class="wpml-porter-progress-bar" id="wpml-porter-bar" style="width:0%"></div>
            </div>
            <p class="wpml-porter-progress-label" id="wpml-porter-label">
                <?php echo esc_html( sprintf( __( '0 / %d rows processed', 'wpml-porter' ), $total_rows ) ); ?>
            </p>

            <div class="wpml-porter-progress-stats" id="wpml-porter-stats" style="display:none">
                <span id="stat-inserted">0 inserted</span> &nbsp;·&nbsp;
                <span id="stat-updated">0 updated</span> &nbsp;·&nbsp;
                <span id="stat-linked">0 linked</span> &nbsp;·&nbsp;
                <span id="stat-skipped">0 skipped</span>
            </div>

            <div class="wpml-porter-log" id="wpml-porter-log"></div>
        </div>

        <script>
        (function() {
            const SESSION   = <?php echo wp_json_encode( $session_id ); ?>;
            const POST_TYPE = <?php echo wp_json_encode( $post_type ); ?>;
            const LANG      = <?php echo wp_json_encode( $default_lang ); ?>;
            const NONCE     = <?php echo wp_json_encode( $batch_nonce ); ?>;
            const AJAX_URL  = <?php echo wp_json_encode( $ajax_url ); ?>;
            const DONE_URL  = <?php echo wp_json_encode( $done_url ); ?>;
            const TOTAL     = <?php echo (int) $total_rows; ?>;

            const bar    = document.getElementById('wpml-porter-bar');
            const label  = document.getElementById('wpml-porter-label');
            const stats  = document.getElementById('wpml-porter-stats');
            const log    = document.getElementById('wpml-porter-log');

            // Batch sizes shrink automatically if this host can't handle the
            // defaults (tight memory_limit/max_execution_time, or a proxy/
            // gateway in front of PHP with its own timeout) — see degrade()
            // below. Starting values match the server-side defaults, so a
            // healthy host never sees any change in behavior.
            var rowBatch  = 20;
            var linkBatch = 100;
            var loggedServerLimits = false;

            function degrade() {
                rowBatch  = Math.max( 3, Math.floor( rowBatch  / 2 ) );
                linkBatch = Math.max( 10, Math.floor( linkBatch / 2 ) );
            }

            function setStats(c) {
                document.getElementById('stat-inserted').textContent = c.inserted + ' inserted';
                document.getElementById('stat-updated').textContent  = c.updated  + ' updated';
                document.getElementById('stat-linked').textContent   = c.linked   + ' linked';
                document.getElementById('stat-skipped').textContent  = c.skipped  + ' skipped';
                stats.style.display = '';
            }

            function addLog(msgs, cls) {
                if (!msgs || !msgs.length) return;
                msgs.forEach(function(m) {
                    var p = document.createElement('p');
                    p.className = cls || '';
                    p.textContent = m;
                    log.appendChild(p);
                });
            }

            function runBatch(retryCount) {
                retryCount = retryCount || 0;

                var body = new URLSearchParams({
                    action:       'wpml_porter_batch',
                    _wpnonce:     NONCE,
                    session_id:   SESSION,
                    post_type:    POST_TYPE,
                    default_lang: LANG,
                    row_batch:    rowBatch,
                    link_batch:   linkBatch,
                });

                fetch(AJAX_URL, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body.toString(),
                })
                .then(function(r) {
                    return r.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            // Response wasn't valid JSON — typically means the
                            // server was killed mid-batch (timeout/memory limit,
                            // or a proxy/gateway in front of PHP, on a
                            // particularly heavy batch). The batch is safe to
                            // retry: nothing partially written gets duplicated,
                            // rows just get re-processed/updated. Shrink the
                            // batch size so the retry asks the host for less
                            // work — a same-size retry would just hit the same
                            // limit again.
                            degrade();
                            throw new Error('Server returned an incomplete response (likely a timeout/memory limit on this batch) — retrying with a smaller batch (' + rowBatch + ' rows).');
                        }
                    });
                })
                .then(function(data) {
                    if (!data.success) {
                        return retryOrFail(retryCount, 'Batch error: ' + (data.data || 'unknown'));
                    }

                    var d = data.data;

                    // A fatal, un-retryable condition (e.g. the session
                    // expired/vanished — see WPML_Porter_Importer::fatal_result())
                    // is reported as d.done=true with an error and nothing
                    // processed. Without this check it fell through to the
                    // "✓ Import complete" branch below, which redirected with
                    // all-zero counts and hid the real reason from the user.
                    if (d.done && d.errors && d.errors.length && !d.offset && !d.total) {
                        addLog(d.errors, 'wpml-porter-log-error');
                        bar.style.background = '#cc1818';
                        label.textContent = 'Import stopped: ' + d.errors[0];
                        return;
                    }

                    if (!loggedServerLimits && d.server_limits) {
                        loggedServerLimits = true;
                        addLog(['Server limits: memory_limit=' + d.server_limits.memory_limit
                            + ', max_execution_time=' + d.server_limits.max_execution_time
                            + 's (0 = no limit)'], 'wpml-porter-log-info');
                    }

                    if (d.phase === 'linking' && d.link_total) {
                        // Rows are fully imported; this stage links WPML
                        // translations in its own small batches so a large
                        // import can't stall out on one giant final step.
                        var lpct = Math.round((d.link_offset / d.link_total) * 100);
                        bar.style.width = lpct + '%';
                        label.textContent = 'Linking translations — ' + d.link_offset + ' / ' + d.link_total + ' groups';
                    } else {
                        var pct = TOTAL > 0 ? Math.round((d.offset / TOTAL) * 100) : 100;
                        bar.style.width = pct + '%';
                        label.textContent = d.offset + ' / ' + TOTAL + ' rows processed';
                    }
                    setStats(d.counts);
                    addLog(d.warnings, 'wpml-porter-log-warn');
                    addLog(d.errors,   'wpml-porter-log-error');

                    if (d.done) {
                        bar.style.width      = '100%';
                        bar.style.background = '#00a32a';
                        label.textContent    = '✓ Import complete — ' + TOTAL + ' rows processed.';

                        // Store final summary in a transient via a quick ping
                        setTimeout(function() {
                            window.location.href = DONE_URL + '&imported=1&inserted=' + d.counts.inserted
                                + '&updated=' + d.counts.updated + '&linked=' + d.counts.linked
                                + '&elementor=' + (d.has_elementor ? '1' : '0');
                        }, 1800);
                    } else {
                        // Small delay between batches to give the server a breath
                        setTimeout(function() { runBatch(0); }, 300);
                    }
                })
                .catch(function(err) {
                    // A connection reset/abort is also consistent with a
                    // proxy or gateway timeout killing the request.
                    if (!/smaller batch/.test(err.message)) degrade();
                    retryOrFail(retryCount, 'Network error: ' + err.message);
                });
            }

            function retryOrFail(retryCount, message) {
                // Raised from 5: each retry now also shrinks the batch (see
                // degrade()), so extra attempts are cheap and give a
                // tight-limit host more chances to find a batch size small
                // enough to succeed, down to the floor of rowBatch=3/linkBatch=10.
                var MAX_RETRIES = 8;
                if (retryCount < MAX_RETRIES) {
                    var delay = Math.min(2000 * Math.pow(1.6, retryCount), 15000); // backoff, capped at 15s
                    label.textContent = message + ' — retrying (' + (retryCount + 1) + '/' + MAX_RETRIES + ')…';
                    setTimeout(function() { runBatch(retryCount + 1); }, delay);
                    return;
                }
                addLog([message], 'wpml-porter-log-error');
                var limitNote = loggedServerLimits
                    ? ' (server_limits above)'
                    : '';
                label.textContent = 'Import stopped after repeated failures, even at the smallest batch size (' + rowBatch + ' rows / ' + linkBatch + ' groups)' + limitNote
                    + '. This points to something other than batch size — e.g. a proxy/WAF blocking the request outright, or a fixed low memory_limit/max_execution_time your host won\'t let PHP override. '
                    + 'You can re-run the import — existing rows will be updated, not duplicated. Check your host\'s error log for the exact PHP fatal, or ask them to raise memory_limit / max_execution_time and any proxy/gateway timeout.';
            }

            // Kick off first batch immediately
            runBatch(0);
        })();
        </script>
    <?php }

    // ─── Taxonomy & ACF tab ───────────────────────────────────────────────

    private function render_taxonomy_tab( array $post_types ): void {
        $all_taxonomies = $this->get_all_taxonomies();
        $has_acf        = function_exists( 'acf_get_field_groups' );
        $languages      = $this->get_wpml_languages();
        $has_wpml       = ! empty( $languages );
        ?>
        <div class="wpml-porter-taxonomy-grid">

            <!-- ── SECTION 1 : Terms Export ── -->
            <div class="wpml-porter-fieldset">
                <h2>&#9783; <?php esc_html_e( 'Export Taxonomy Terms', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px"><?php esc_html_e( 'Downloads a CSV of every term in the chosen taxonomies, including their meta/ACF fields and WPML language links.', 'wpml-porter' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_TERMS_EXPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_terms_export">

                    <div class="wpml-porter-field">
                        <label><?php esc_html_e( 'Taxonomies', 'wpml-porter' ); ?></label>
                        <div class="wpml-porter-checklist">
                        <?php foreach ( $all_taxonomies as $slug => $label ) : ?>
                            <label class="wpml-porter-check-row">
                                <input type="checkbox" name="export_taxonomies[]" value="<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( "$label ($slug)" ); ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ( $has_acf ) : ?>
                    <div class="wpml-porter-field wpml-porter-field--check">
                        <label>
                            <input type="checkbox" name="export_acf_term_fields" value="1" checked>
                            <?php esc_html_e( 'Include ACF fields for these taxonomies', 'wpml-porter' ); ?>
                        </label>
                    </div>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e( 'ACF not active — ACF field export is unavailable.', 'wpml-porter' ); ?></p>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px">
                        ↓ <?php esc_html_e( 'Download Terms CSV', 'wpml-porter' ); ?>
                    </button>
                </form>
            </div>

            <!-- ── SECTION 2 : Terms Import ── -->
            <div class="wpml-porter-fieldset">
                <h2>↑ <?php esc_html_e( 'Import Taxonomy Terms', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px"><?php esc_html_e( 'Upload a previously exported Terms CSV. Existing terms (matched by taxonomy + slug) are updated; new terms are inserted. Parent-child hierarchy and WPML links are restored.', 'wpml-porter' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( self::NONCE_TERMS_IMPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_terms_import">

                    <div class="wpml-porter-field">
                        <label for="terms_import_csv"><?php esc_html_e( 'Terms CSV File', 'wpml-porter' ); ?></label>
                        <input type="file" name="terms_import_csv" id="terms_import_csv" accept=".csv,text/csv" required>
                    </div>

                    <?php if ( $has_wpml ) : ?>
                    <div class="wpml-porter-field">
                        <label for="terms_import_default_lang"><?php esc_html_e( 'Default Language (fallback)', 'wpml-porter' ); ?></label>
                        <select name="terms_import_default_lang" id="terms_import_default_lang">
                            <?php foreach ( $languages as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, 'en' ); ?>>
                                    <?php echo esc_html( "$name ($code)" ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px">
                        ↑ <?php esc_html_e( 'Import Terms', 'wpml-porter' ); ?>
                    </button>
                </form>
            </div>

            <!-- ── SECTION 3 : CPT + Taxonomy Structure Export ── -->
            <div class="wpml-porter-fieldset">
                <h2>&#9883; <?php esc_html_e( 'Export CPT & Taxonomy Structure', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px"><?php esc_html_e( 'Downloads a JSON snapshot of the registration args for the chosen CPTs and taxonomies, plus all ACF field groups (if ACF is active). Use this to re-register identical structures on another site.', 'wpml-porter' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_STRUCT_EXPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_struct_export">

                    <div class="wpml-porter-field">
                        <label><?php esc_html_e( 'Post Types', 'wpml-porter' ); ?></label>
                        <div class="wpml-porter-checklist">
                        <?php foreach ( $post_types as $slug => $label ) : ?>
                            <label class="wpml-porter-check-row">
                                <input type="checkbox" name="export_cpt[]" value="<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( "$label ($slug)" ); ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="wpml-porter-field">
                        <label><?php esc_html_e( 'Taxonomies', 'wpml-porter' ); ?></label>
                        <div class="wpml-porter-checklist">
                        <?php foreach ( $all_taxonomies as $slug => $label ) : ?>
                            <label class="wpml-porter-check-row">
                                <input type="checkbox" name="export_tax[]" value="<?php echo esc_attr( $slug ); ?>">
                                <?php echo esc_html( "$label ($slug)" ); ?>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if ( $has_acf ) : ?>
                    <div class="wpml-porter-field wpml-porter-field--check">
                        <label>
                            <input type="checkbox" name="export_acf_groups" value="1" checked>
                            <?php esc_html_e( 'Include ACF Field Groups assigned to the selected post types', 'wpml-porter' ); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px">
                        ↓ <?php esc_html_e( 'Download Structure JSON', 'wpml-porter' ); ?>
                    </button>
                </form>
            </div>

            <!-- ── SECTION 4 : Structure Import ── -->
            <div class="wpml-porter-fieldset">
                <h2>↑ <?php esc_html_e( 'Import CPT & Taxonomy Structure', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px"><?php esc_html_e( 'Upload a Structure JSON file. CPTs and taxonomies will be dynamically registered for this session and written to a must-use plugin so they persist. ACF field groups will be imported via ACF\'s own importer (requires ACF Pro on destination site).', 'wpml-porter' ); ?></p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( self::NONCE_STRUCT_IMPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_struct_import">

                    <div class="wpml-porter-field">
                        <label for="struct_import_json"><?php esc_html_e( 'Structure JSON File', 'wpml-porter' ); ?></label>
                        <input type="file" name="struct_import_json" id="struct_import_json" accept=".json,application/json" required>
                    </div>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px">
                        ↑ <?php esc_html_e( 'Import Structure', 'wpml-porter' ); ?>
                    </button>
                </form>

                <div class="wpml-porter-info-box" style="margin-top:18px">
                    <h3><?php esc_html_e( 'What gets imported', 'wpml-porter' ); ?></h3>
                    <ul>
                        <li><?php esc_html_e( 'CPTs and taxonomies that are ACF-managed on the source site are re-registered through ACF itself, saved directly to ACF\'s database (no Local JSON files) — they appear in ACF → Post Types/Taxonomies alongside your existing ones. ACF must be active.', 'wpml-porter' ); ?></li>
                        <li><?php esc_html_e( 'ACF field groups and all field definitions', 'wpml-porter' ); ?></li>
                        <li><?php esc_html_e( 'CPTs/taxonomies that were NOT ACF-managed on the source site are not auto-registered — a code snippet is provided to add manually, so nothing competes with your site\'s existing registrations.', 'wpml-porter' ); ?></li>
                    </ul>
                </div>
            </div>

        </div><!-- .wpml-porter-taxonomy-grid -->

        <div class="wpml-porter-info-box" style="margin-top:0;max-width:100%">
            <h3><?php esc_html_e( 'Terms CSV Column Format', 'wpml-porter' ); ?></h3>
            <ul>
                <li><code>term_id</code> — <?php esc_html_e( 'source-site term ID (reference only, not imported)', 'wpml-porter' ); ?></li>
                <li><code>taxonomy</code> — <?php esc_html_e( 'taxonomy slug', 'wpml-porter' ); ?></li>
                <li><code>slug / name / description / parent_slug</code> — <?php esc_html_e( 'core term fields; parent matched by slug', 'wpml-porter' ); ?></li>
                <li><code>meta:{key}</code> — <?php esc_html_e( 'term meta / ACF field value (arrays serialized)', 'wpml-porter' ); ?></li>
                <li><code>_wpml_term_language</code> — <?php esc_html_e( 'language code for this row (en, fr, ar …)', 'wpml-porter' ); ?></li>
                <li><code>_wpml_term_source_language</code> — <?php esc_html_e( 'blank = this row is the original; filled = it is a translation of that language', 'wpml-porter' ); ?></li>
                <li><code>_wpml_term_trid</code> — <?php esc_html_e( 'translation group ID from source site — remapped to a new trid on the destination site automatically', 'wpml-porter' ); ?></li>
                <li><code>_wpml_term_ttid</code> — <?php esc_html_e( 'term_taxonomy_id from source site (reference only)', 'wpml-porter' ); ?></li>
            </ul>
            <p style="margin:8px 0 0;font-size:12px;color:#646970"><?php esc_html_e( 'Each WPML language version of a term is its own row. On import, original-language rows are processed first; translation rows are then linked using a remapped trid — so translation groups are correctly re-established on the destination site even if it has different IDs.', 'wpml-porter' ); ?></p>
        </div>
        <?php
    }

    // ─── Full Package tab ───────────────────────────────────────────────────

    private function render_package_tab( array $post_types, array $languages ): void {
        $has_acf  = function_exists( 'acf_get_field_groups' );
        $has_zip  = class_exists( 'ZipArchive' );
        $has_wpml = ! empty( $languages );
        ?>
        <?php if ( ! $has_zip ) : ?>
            <div class="notice notice-error" style="margin:0 0 16px"><p>
                <?php esc_html_e( 'The PHP ZipArchive extension is not available on this server, so Full Package export/import cannot run. Please ask your host to enable it, or use the separate Export / Taxonomy tabs instead.', 'wpml-porter' ); ?>
            </p></div>
        <?php endif; ?>

        <div class="wpml-porter-taxonomy-grid">

            <!-- ── Package Export ── -->
            <div class="wpml-porter-fieldset">
                <h2>&#128230; <?php esc_html_e( 'Export Full Package', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px">
                    <?php esc_html_e( 'Downloads a single .zip containing everything related to one post type: its taxonomies (definitions + all terms, every WPML language), any ACF field groups assigned to it, and all of its posts. Import this one file on the destination site to restore all of it in the right order automatically.', 'wpml-porter' ); ?>
                </p>
                <p class="description" style="margin:0 0 14px">
                    <?php esc_html_e( 'For WordPress\'s own built-in types — Posts, Pages, Categories, Tags — only the content (posts, pages, terms, and their custom field values) is exported/imported. They are never registered as ACF Post Types/Taxonomies, since those are core WordPress structure, not custom configuration. Genuinely custom post types and taxonomies are still registered through ACF as before.', 'wpml-porter' ); ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( self::NONCE_PACKAGE_EXPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_package_export">

                    <div class="wpml-porter-field">
                        <label for="package_export_post_type"><?php esc_html_e( 'Post Type', 'wpml-porter' ); ?></label>
                        <select name="package_export_post_type" id="package_export_post_type" required <?php disabled( ! $has_zip ); ?>>
                            <option value=""><?php esc_html_e( '— Select post type —', 'wpml-porter' ); ?></option>
                            <?php foreach ( $post_types as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( "$label ($slug)" ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Its attached taxonomies are detected and included automatically.', 'wpml-porter' ); ?></p>
                    </div>

                    <div class="wpml-porter-field">
                        <label><?php esc_html_e( 'Custom Fields (optional)', 'wpml-porter' ); ?></label>
                        <textarea name="package_export_meta_keys" rows="3" <?php disabled( ! $has_zip ); ?>
                            placeholder="<?php esc_attr_e( 'One meta key per line. Leave blank to export ALL.', 'wpml-porter' ); ?>"></textarea>
                    </div>

                    <?php if ( $has_acf ) : ?>
                    <div class="wpml-porter-field wpml-porter-field--check">
                        <label>
                            <input type="checkbox" name="package_export_acf" value="1" checked <?php disabled( ! $has_zip ); ?>>
                            <?php esc_html_e( 'Include ACF field groups & ACF settings', 'wpml-porter' ); ?>
                        </label>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px" <?php disabled( ! $has_zip ); ?>>
                        ↓ <?php esc_html_e( 'Download Package (.zip)', 'wpml-porter' ); ?>
                    </button>
                </form>
            </div>

            <!-- ── Package Import ── -->
            <div class="wpml-porter-fieldset">
                <h2>↑ <?php esc_html_e( 'Import Full Package', 'wpml-porter' ); ?></h2>
                <p class="description" style="margin:0 0 14px">
                    <?php esc_html_e( 'Upload a Full Package .zip. The post type, its taxonomies, ACF field groups, taxonomy terms, and posts are all restored automatically, in the correct order — no need to run separate imports.', 'wpml-porter' ); ?>
                </p>

                <form method="post"
                      action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      enctype="multipart/form-data">
                    <?php wp_nonce_field( self::NONCE_PACKAGE_IMPORT, '_wpml_porter_nonce' ); ?>
                    <input type="hidden" name="action" value="wpml_porter_package_import">

                    <div class="wpml-porter-field">
                        <label for="package_import_zip"><?php esc_html_e( 'Package .zip File', 'wpml-porter' ); ?></label>
                        <input type="file" name="package_import_zip" id="package_import_zip" accept=".zip,application/zip" required <?php disabled( ! $has_zip ); ?>>
                    </div>

                    <?php if ( $has_wpml ) : ?>
                    <div class="wpml-porter-field">
                        <label for="package_import_default_lang"><?php esc_html_e( 'Source Language', 'wpml-porter' ); ?></label>
                        <select name="package_import_default_lang" id="package_import_default_lang" <?php disabled( ! $has_zip ); ?>>
                            <?php foreach ( $languages as $code => $name ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, 'en' ); ?>>
                                    <?php echo esc_html( "$name ($code)" ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'The language that other translations in this package are translated from.', 'wpml-porter' ); ?></p>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="button button-primary wpml-porter-btn" style="margin-top:8px" <?php disabled( ! $has_zip ); ?>>
                        ↑ <?php esc_html_e( 'Import Package', 'wpml-porter' ); ?>
                    </button>
                </form>

                <div class="wpml-porter-info-box" style="margin-top:18px">
                    <h3><?php esc_html_e( 'Import order', 'wpml-porter' ); ?></h3>
                    <ul>
                        <li><?php esc_html_e( '1. Custom post type / taxonomy structure and ACF field groups — saved directly to ACF\'s database (no Local JSON). Built-in WordPress types (Posts, Pages, Categories, Tags) are never registered in ACF — only genuinely custom structure is.', 'wpml-porter' ); ?></li>
                        <li><?php esc_html_e( '2. Taxonomy terms, in every WPML language, with translation links restored.', 'wpml-porter' ); ?></li>
                        <li><?php esc_html_e( '3. Posts — processed in batches on a progress screen, same as a normal CSV import, so large packages won\'t time out.', 'wpml-porter' ); ?></li>
                    </ul>
                </div>
            </div>

        </div><!-- .wpml-porter-taxonomy-grid -->
        <?php
    }

    // ─── Repair tab ───────────────────────────────────────────────────────

    private function render_repair_tab(): void {
        $nonce    = wp_create_nonce( self::NONCE_REPAIR );
        $ajax_url = admin_url( 'admin-ajax.php' );
        ?>
        <div class="wpml-porter-fieldset" style="max-width:640px">
            <h2><?php esc_html_e( 'Repair Previously Imported Posts', 'wpml-porter' ); ?></h2>
            <p><?php esc_html_e( 'Run this if translated posts open blank in WPML\'s Translation Editor. It will:', 'wpml-porter' ); ?></p>
            <ul style="list-style:disc;padding-left:20px;margin-bottom:16px">
                <li><?php esc_html_e( 'Patch WPML\'s translation_status and translate_job tables with the existing translated content so the Translation Editor opens pre-filled instead of blank.', 'wpml-porter' ); ?></li>
            </ul>
            <button id="wpml-porter-repair-btn" class="button button-primary wpml-porter-btn">
                &#10003; <?php esc_html_e( 'Run Repair', 'wpml-porter' ); ?>
            </button>
        </div>

        <div id="wpml-porter-repair-wrap" style="display:none;max-width:640px;margin-top:20px">
            <div class="wpml-porter-progress-bar-wrap">
                <div class="wpml-porter-progress-bar" id="wpml-porter-repair-bar" style="width:0%"></div>
            </div>
            <p class="wpml-porter-progress-label" id="wpml-porter-repair-label"></p>
            <div class="wpml-porter-log" id="wpml-porter-repair-log"></div>
        </div>

        <script>
        (function() {
            var btn      = document.getElementById('wpml-porter-repair-btn');
            var wrap     = document.getElementById('wpml-porter-repair-wrap');
            var bar      = document.getElementById('wpml-porter-repair-bar');
            var label    = document.getElementById('wpml-porter-repair-label');
            var log      = document.getElementById('wpml-porter-repair-log');
            var offset   = 0;
            var total    = 0;
            // repair_batch() returns per-batch counts, not a running total —
            // accumulate them here so the final "Repair complete" message
            // reflects everything fixed across the whole run instead of just
            // whatever the very last batch happened to touch (which, for a
            // large site, is very often zero — making the tool look like it
            // did nothing even after successfully repairing hundreds of posts
            // in earlier batches).
            var totalFixed    = 0;
            var totalRelinked = 0;

            function addLog(msg, cls) {
                var p = document.createElement('p');
                p.className = cls || '';
                p.textContent = msg;
                log.appendChild(p);
                log.scrollTop = log.scrollHeight;
            }

            function runRepair() {
                var body = new URLSearchParams({
                    action:   'wpml_porter_repair',
                    _wpnonce: <?php echo wp_json_encode( $nonce ); ?>,
                    offset:   offset,
                });

                fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body.toString(),
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) {
                        addLog('Error: ' + (data.data || 'unknown'), 'wpml-porter-log-error');
                        label.textContent = 'Repair stopped.';
                        return;
                    }
                    var d = data.data;
                    total  = d.total || total;
                    offset = d.offset;
                    totalFixed    += d.fixed_links || 0;
                    totalRelinked += d.relinked     || 0;

                    var pct = total > 0 ? Math.round((offset / total) * 100) : 100;
                    bar.style.width  = pct + '%';
                    label.textContent = offset + ' / ' + total + ' posts checked'
                        + ' — ' + totalFixed + ' WPML records updated so far.';

                    if (d.done) {
                        bar.style.width      = '100%';
                        bar.style.background = '#00a32a';
                        label.textContent    = '✓ Repair complete. '
                            + totalFixed + ' WPML translation records patched.';
                        if (totalRelinked > 0) {
                            addLog(totalRelinked + ' post(s) had never been linked as WPML translations at all (e.g. from an import that was interrupted) — that link was re-established before patching.', '');
                        }
                        addLog('Done! Reload your posts list and try the Translation Editor again.', '');
                    } else {
                        setTimeout(runRepair, 200);
                    }
                })
                .catch(function(err) {
                    addLog('Network error: ' + err.message, 'wpml-porter-log-error');
                });
            }

            btn.addEventListener('click', function() {
                btn.disabled     = true;
                wrap.style.display = '';
                label.textContent  = 'Starting…';
                offset = 0; total = 0; totalFixed = 0; totalRelinked = 0;
                runRepair();
            });
        })();
        </script>
        <?php
    }

    // ─── Form handlers ────────────────────────────────────────────────────

    public function handle_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_EXPORT, '_wpml_porter_nonce' );

        $post_type = sanitize_key( $_POST['export_post_type'] ?? '' );
        if ( ! $post_type || ! post_type_exists( $post_type ) ) wp_die( 'Invalid post type.' );

        $raw       = trim( $_POST['export_meta_keys'] ?? '' );
        $meta_keys = $raw ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) : [];

        $exporter = new WPML_Porter_Exporter( $post_type, compact( 'meta_keys' ) );
        $exporter->stream_csv();
    }

    /**
     * Step 1: Receive the uploaded file, parse it into a session, redirect to progress UI.
     */
    public function handle_import_start(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_IMPORT, '_wpml_porter_nonce' );

        $post_type    = sanitize_key( $_POST['import_post_type'] ?? '' );
        $default_lang = sanitize_text_field( $_POST['import_default_lang'] ?? 'en' );

        if ( ! $post_type || ! post_type_exists( $post_type ) ) {
            $this->store_notice( 'error', [ __( 'Invalid post type.', 'wpml-porter' ) ] );
            $this->redirect_back( 'import' );
            return;
        }

        if ( empty( $_FILES['import_csv']['tmp_name'] ) || $_FILES['import_csv']['error'] !== UPLOAD_ERR_OK ) {
            $this->store_notice( 'error', [ __( 'File upload failed. Please try again.', 'wpml-porter' ) ] );
            $this->redirect_back( 'import' );
            return;
        }

        // MIME check
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $_FILES['import_csv']['tmp_name'] );
        finfo_close( $finfo );
        $allowed = [ 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' ];
        if ( ! in_array( $mime, $allowed, true ) ) {
            $this->store_notice( 'error', [ __( 'Uploaded file does not look like a CSV.', 'wpml-porter' ) ] );
            $this->redirect_back( 'import' );
            return;
        }

        // Move to uploads dir so it survives across multiple Ajax requests
        $upload_dir = wp_upload_dir();
        $dest       = $upload_dir['basedir'] . '/wpml-porter-tmp-' . wp_generate_password( 12, false ) . '.csv';
        if ( ! move_uploaded_file( $_FILES['import_csv']['tmp_name'], $dest ) ) {
            $this->store_notice( 'error', [ __( 'Could not save uploaded file.', 'wpml-porter' ) ] );
            $this->redirect_back( 'import' );
            return;
        }

        // Create session
        $result = WPML_Porter_Importer::prepare_session( $dest );
        @unlink( $dest ); // rows are now in the transient; delete the file

        if ( ! empty( $result['errors'] ) || ! $result['session_id'] ) {
            $this->store_notice( 'error', $result['errors'] ?: [ 'Unknown error creating session.' ] );
            $this->redirect_back( 'import' );
            return;
        }

        // Redirect to progress UI
        wp_safe_redirect( add_query_arg( [
            'page'    => self::MENU_SLUG,
            'tab'     => 'import',
            'session' => $result['session_id'],
            'ipt'     => $post_type,
            'ilang'   => $default_lang,
            'total'   => $result['total_rows'],
        ], admin_url( 'tools.php' ) ) );
        exit;
    }

    /**
     * Step 2 (Ajax): Process one batch.
     */
    public function handle_ajax_batch(): void {
        check_ajax_referer( self::NONCE_BATCH );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $session_id   = sanitize_text_field( $_POST['session_id']   ?? '' );
        $post_type    = sanitize_key( $_POST['post_type']            ?? '' );
        $default_lang = sanitize_text_field( $_POST['default_lang']  ?? 'en' );

        // Browser may request a smaller-than-default batch after seeing this
        // batch size fail on the current host (see wpml-porter-progress.js
        // degrade logic) — null lets process_batch() fall back to its
        // normal default.
        $row_batch_size  = isset( $_POST['row_batch'] )  && $_POST['row_batch']  !== '' ? (int) $_POST['row_batch']  : null;
        $link_batch_size = isset( $_POST['link_batch'] ) && $_POST['link_batch'] !== '' ? (int) $_POST['link_batch'] : null;

        if ( ! $session_id || ! $post_type ) {
            wp_send_json_error( 'Missing parameters.' );
        }

        // Raise time/memory limits for this request — large per-post payloads
        // (e.g. Elementor page data) make batch processing more resource-hungry
        // than a typical Ajax request. These are best-effort: some hosts lock
        // memory_limit/max_execution_time and will silently ignore the change,
        // which is exactly why row_batch/link_batch above exist as a fallback
        // that doesn't depend on the host allowing a higher limit at all.
        @set_time_limit( 120 );
        @ini_set( 'memory_limit', '512M' );

        // Some hosts (or a security/debug plugin) print PHP notices, warnings,
        // or deprecation messages straight into the response body when
        // display_errors is on. Even a few stray bytes ahead of our JSON
        // output make the browser's JSON.parse() fail on every single
        // request — indistinguishable from a truncated/timed-out response
        // from the outside, but no batch-size change can fix it since it
        // isn't a resource problem at all. @ini_set is best-effort (some
        // hosts lock it), so the real safety net is the output buffer below:
        // it captures everything printed during process_batch() regardless
        // of the display_errors setting, and gets discarded — only our own
        // wp_send_json_*() call's output reaches the browser.
        @ini_set( 'display_errors', '0' );
        ob_start();

        try {
            $result = WPML_Porter_Importer::process_batch( $session_id, $post_type, $default_lang, $row_batch_size, $link_batch_size );
        } catch ( \Throwable $e ) {
            ob_end_clean();
            // Make sure a mid-batch fatal still produces a clean, parseable
            // JSON response instead of a truncated one the browser can't
            // parse — the batch can simply be retried (it's idempotent,
            // existing rows get updated rather than duplicated).
            wp_send_json_error( 'A batch failed unexpectedly: ' . $e->getMessage() . '. You can retry — already-imported rows will be updated, not duplicated.' );
        }

        $stray_output = ob_get_clean();

        // Actual limits in effect on this host, for the "keeps happening" log
        // line — saves the user having to find phpinfo() or ask their host
        // what these are already set to.
        $result['server_limits'] = [
            'memory_limit'      => ini_get( 'memory_limit' ),
            'max_execution_time'=> ini_get( 'max_execution_time' ),
        ];

        // Surface (rather than silently swallow) anything that was caught —
        // as a warning, not an error, since the import itself still
        // succeeded; this is diagnostic information about what was polluting
        // the response, trimmed in case it's a large stack trace dump.
        if ( trim( $stray_output ) !== '' ) {
            $result['warnings'][] = 'Note: the server printed extra output during this batch (truncated): '
                . esc_html( mb_substr( trim( wp_strip_all_tags( $stray_output ) ), 0, 300 ) );
        }

        wp_send_json_success( $result );
    }

    /**
     * Ajax: run one repair batch.
     */
    public function handle_ajax_repair(): void {
        check_ajax_referer( self::NONCE_REPAIR );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        @set_time_limit( 120 );

        $offset = max( 0, (int) ( $_POST['offset'] ?? 0 ) );
        $result = WPML_Porter_Importer::repair_batch( $offset, 30 );

        wp_send_json_success( $result );
    }

    // ─── Taxonomy Terms Export handler ────────────────────────────────────

    public function handle_terms_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_TERMS_EXPORT, '_wpml_porter_nonce' );

        $taxonomies = isset( $_POST['export_taxonomies'] ) && is_array( $_POST['export_taxonomies'] )
            ? array_map( 'sanitize_key', $_POST['export_taxonomies'] )
            : [];

        if ( empty( $taxonomies ) ) {
            $this->store_notice( 'error', [ __( 'Please select at least one taxonomy.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        // Validate all selected taxonomies exist
        $taxonomies = array_filter( $taxonomies, 'taxonomy_exists' );
        if ( empty( $taxonomies ) ) {
            $this->store_notice( 'error', [ __( 'None of the selected taxonomies are registered.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        $include_acf = ! empty( $_POST['export_acf_term_fields'] );
        WPML_Porter_Terms::export_terms_csv( $taxonomies, $include_acf );
    }

    // ─── Taxonomy Terms Import handler ────────────────────────────────────

    public function handle_terms_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_TERMS_IMPORT, '_wpml_porter_nonce' );

        $default_lang = sanitize_text_field( $_POST['terms_import_default_lang'] ?? 'en' );

        if ( empty( $_FILES['terms_import_csv']['tmp_name'] ) || $_FILES['terms_import_csv']['error'] !== UPLOAD_ERR_OK ) {
            $this->store_notice( 'error', [ __( 'File upload failed.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        $tmp = $_FILES['terms_import_csv']['tmp_name'];

        // Parse CSV
        $handle = fopen( $tmp, 'r' );
        if ( ! $handle ) {
            $this->store_notice( 'error', [ __( 'Cannot open uploaded file.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        $header = array_map( 'trim', fgetcsv( $handle ) ?: [] );
        $rows   = [];
        while ( ( $raw = fgetcsv( $handle ) ) !== false ) {
            $row = [];
            foreach ( $header as $i => $key ) {
                $row[ $key ] = $raw[ $i ] ?? '';
            }
            $rows[] = $row;
        }
        fclose( $handle );

        $result = WPML_Porter_Terms::import_terms( $rows, $default_lang );
        $c      = $result['counts'];

        $messages = [
            sprintf(
                __( 'Terms import complete — <strong>%d inserted</strong>, <strong>%d updated</strong>, <strong>%d linked (WPML)</strong>, <strong>%d skipped</strong>.', 'wpml-porter' ),
                $c['inserted'], $c['updated'], $c['linked'], $c['skipped']
            ),
        ];
        if ( ! empty( $result['errors'] ) ) {
            $messages[] = __( 'Errors:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $result['errors'] ) );
        }
        if ( ! empty( $result['warnings'] ) ) {
            $messages[] = __( 'Warnings:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $result['warnings'] ) );
        }

        $type = empty( $result['errors'] ) ? 'success' : 'warning';
        $this->store_notice( $type, $messages );
        $this->redirect_back( 'taxonomy' );
    }

    // ─── Structure Export handler ─────────────────────────────────────────

    public function handle_struct_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_STRUCT_EXPORT, '_wpml_porter_nonce' );

        $post_types = isset( $_POST['export_cpt'] ) && is_array( $_POST['export_cpt'] )
            ? array_map( 'sanitize_key', $_POST['export_cpt'] ) : [];
        $taxonomies = isset( $_POST['export_tax'] ) && is_array( $_POST['export_tax'] )
            ? array_map( 'sanitize_key', $_POST['export_tax'] ) : [];
        $include_acf = ! empty( $_POST['export_acf_groups'] );

        WPML_Porter_Terms::export_structure_json( $post_types, $taxonomies, $include_acf );
    }

    // ─── Structure Import handler ─────────────────────────────────────────

    public function handle_struct_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_STRUCT_IMPORT, '_wpml_porter_nonce' );

        if ( empty( $_FILES['struct_import_json']['tmp_name'] ) || $_FILES['struct_import_json']['error'] !== UPLOAD_ERR_OK ) {
            $this->store_notice( 'error', [ __( 'File upload failed.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        $content = file_get_contents( $_FILES['struct_import_json']['tmp_name'] );
        $data    = json_decode( $content, true );
        if ( ! is_array( $data ) ) {
            $this->store_notice( 'error', [ __( 'Invalid JSON file.', 'wpml-porter' ) ] );
            $this->redirect_back( 'taxonomy' );
            return;
        }

        $result = WPML_Porter_Terms::import_structure( $data );

        // Remove any mu-plugin stub left over from an older version of this
        // plugin. That file registered CPTs/taxonomies through a separate,
        // competing code path outside of ACF, which is what caused
        // already-existing ACF post types to disappear from the
        // ACF → Post Types screen after an import. Everything is now stored
        // and registered exclusively through ACF's own database (via ACF's
        // native import functions) — no Local JSON files are written.
        $this->remove_legacy_mu_plugin_stub();

        $messages = [
            sprintf(
                __( 'Structure import complete — <strong>%d CPTs</strong>, <strong>%d taxonomies</strong>, <strong>%d ACF groups</strong> registered with ACF.', 'wpml-porter' ),
                $result['registered_cpts'],
                $result['registered_taxes'],
                $result['acf_imported']
            ),
        ];
        if ( ! empty( $result['errors'] ) ) {
            $messages[] = __( 'Errors:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $result['errors'] ) );
        }

        // Let the user know the imported structure lives only in ACF's own
        // database storage — no ACF Local JSON files are written anywhere.
        if ( $result['acf_imported'] || $result['registered_cpts'] || $result['registered_taxes'] ) {
            $messages[] = __( 'All imported post types, taxonomies, and field groups were saved directly to ACF\'s database — the same as if you\'d created or imported them by hand in ACF → Post Types / Taxonomies / Field Groups. No files were written to the theme\'s <code>acf-json</code> folder or anywhere else.', 'wpml-porter' );
        }

        if ( ! empty( $result['manual_cpts'] ) || ! empty( $result['manual_taxes'] ) ) {
            $messages[] = __( 'Some post types/taxonomies in this file were not ACF-managed on the source site, so they were not auto-registered (to avoid conflicting with your site). Add this to your theme\'s <code>functions.php</code> (or a custom plugin) instead:', 'wpml-porter' );
            $messages[] = '<pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto">'
                . esc_html( $this->build_manual_registration_snippet( $result['manual_cpts'], $result['manual_taxes'] ) )
                . '</pre>';
        }

        if ( ! empty( $result['skipped_builtin'] ) ) {
            $messages[] = sprintf(
                __( 'Skipped registering WordPress\'s own built-in type(s) in ACF: <strong>%s</strong>. These are core WordPress content types, not custom structure — only their content (posts/terms) is ever imported.', 'wpml-porter' ),
                esc_html( implode( ', ', array_unique( $result['skipped_builtin'] ) ) )
            );
        }

        $this->store_notice( empty( $result['errors'] ) ? 'success' : 'warning', $messages );
        $this->redirect_back( 'taxonomy' );
    }

    // ─── Full Package Export handler ──────────────────────────────────────

    public function handle_package_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_PACKAGE_EXPORT, '_wpml_porter_nonce' );

        $post_type = sanitize_key( $_POST['package_export_post_type'] ?? '' );
        if ( ! $post_type || ! post_type_exists( $post_type ) ) wp_die( 'Invalid post type.' );

        $raw       = trim( $_POST['package_export_meta_keys'] ?? '' );
        $meta_keys = $raw ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) : [];
        $include_acf = ! empty( $_POST['package_export_acf'] );

        WPML_Porter_Package::export( $post_type, $meta_keys, $include_acf );
    }

    // ─── Full Package Import handler ──────────────────────────────────────

    public function handle_package_import(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
        check_admin_referer( self::NONCE_PACKAGE_IMPORT, '_wpml_porter_nonce' );

        $default_lang = sanitize_text_field( $_POST['package_import_default_lang'] ?? 'en' );

        if ( empty( $_FILES['package_import_zip']['tmp_name'] ) || $_FILES['package_import_zip']['error'] !== UPLOAD_ERR_OK ) {
            $this->store_notice( 'error', [ __( 'File upload failed. Please try again.', 'wpml-porter' ) ] );
            $this->redirect_back( 'package' );
            return;
        }

        // Move to uploads dir first (PHP's own tmp file gets cleaned up at
        // end of request, and ZipArchive needs a stable path).
        $upload_dir = wp_upload_dir();
        $dest       = $upload_dir['basedir'] . '/wpml-porter-pkg-' . wp_generate_password( 12, false ) . '.zip';
        if ( ! move_uploaded_file( $_FILES['package_import_zip']['tmp_name'], $dest ) ) {
            $this->store_notice( 'error', [ __( 'Could not save uploaded file.', 'wpml-porter' ) ] );
            $this->redirect_back( 'package' );
            return;
        }

        $result = WPML_Porter_Package::import( $dest, $default_lang );
        @unlink( $dest );

        $this->remove_legacy_mu_plugin_stub();

        if ( ! empty( $result['errors'] ) && ! $result['manifest'] ) {
            // Fatal — couldn't even read the package.
            $this->store_notice( 'error', $result['errors'] );
            $this->redirect_back( 'package' );
            return;
        }

        $messages = [];
        $post_type = $result['manifest']['post_type'] ?? '';

        if ( $result['structure'] ) {
            $s = $result['structure'];
            $messages[] = sprintf(
                __( 'Structure — <strong>%1$d CPT(s)</strong>, <strong>%2$d taxonomy/ies</strong>, <strong>%3$d ACF field group(s)</strong> saved directly to ACF\'s database.', 'wpml-porter' ),
                $s['registered_cpts'], $s['registered_taxes'], $s['acf_imported']
            );
            if ( ! empty( $s['errors'] ) ) {
                $messages[] = __( 'Structure errors:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $s['errors'] ) );
            }
            if ( ! empty( $s['manual_cpts'] ) || ! empty( $s['manual_taxes'] ) ) {
                $messages[] = __( 'Some post types/taxonomies in this package were not ACF-managed on the source site, so they were not auto-registered. Add this to your theme\'s <code>functions.php</code> (or a custom plugin) instead:', 'wpml-porter' );
                $messages[] = '<pre style="white-space:pre-wrap;background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto">'
                    . esc_html( $this->build_manual_registration_snippet( $s['manual_cpts'], $s['manual_taxes'] ) )
                    . '</pre>';
            }
            if ( ! empty( $s['skipped_builtin'] ) ) {
                $messages[] = sprintf(
                    __( 'Skipped registering WordPress\'s own built-in type(s) in ACF: <strong>%s</strong> — only their content (posts/terms) was imported.', 'wpml-porter' ),
                    esc_html( implode( ', ', array_unique( $s['skipped_builtin'] ) ) )
                );
            }
        }

        if ( $result['terms'] ) {
            $c = $result['terms']['counts'];
            $messages[] = sprintf(
                __( 'Terms — <strong>%1$d inserted</strong>, <strong>%2$d updated</strong>, <strong>%3$d linked (WPML)</strong>, <strong>%4$d skipped</strong>.', 'wpml-porter' ),
                $c['inserted'], $c['updated'], $c['linked'], $c['skipped']
            );
            if ( ! empty( $result['terms']['errors'] ) ) {
                $messages[] = __( 'Terms errors:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $result['terms']['errors'] ) );
            }
        }

        if ( ! empty( $result['errors'] ) ) {
            $messages[] = __( 'Errors:', 'wpml-porter' ) . ' ' . implode( ' | ', array_map( 'esc_html', $result['errors'] ) );
        }

        $this->store_notice( empty( $result['errors'] ) ? 'success' : 'warning', $messages );

        // If the package included posts, hand off to the same batch/progress
        // UI a normal CSV post import uses — structure & terms above already
        // ran synchronously, so only the (potentially large) posts list needs
        // the batched Ajax treatment.
        if ( ! empty( $result['session'] ) ) {
            wp_safe_redirect( add_query_arg( [
                'page'     => self::MENU_SLUG,
                'tab'      => 'package',
                'session'  => $result['session']['session_id'],
                'ipt'      => $result['session']['post_type'],
                'ilang'    => $default_lang,
                'total'    => $result['session']['total_rows'],
                'done_tab' => 'package',
            ], admin_url( 'tools.php' ) ) );
            exit;
        }

        $this->redirect_back( 'package' );
    }

    /**
     * One-time cleanup: delete the mu-plugin stub written by older WPML
     * Porter versions, if present. Safe no-op if it doesn't exist.
     */
    private function remove_legacy_mu_plugin_stub(): void {
        if ( ! defined( 'WPMU_PLUGIN_DIR' ) ) return;
        $mu_file = WPMU_PLUGIN_DIR . '/wpml-porter-structure.php';
        if ( file_exists( $mu_file ) ) {
            @unlink( $mu_file );
        }
    }

    /**
     * Build a plain, copy-pasteable register_post_type()/register_taxonomy()
     * snippet for structures that weren't ACF-managed on the source site.
     * This is display-only text — WPML Porter never writes or executes it.
     */
    private function build_manual_registration_snippet( array $manual_cpts, array $manual_taxes ): string {
        $lines   = [];
        $lines[] = "add_action( 'init', function () {";

        foreach ( $manual_cpts as $slug => $args ) {
            $lines[] = "\tregister_post_type( " . var_export( $slug, true ) . ', ' . var_export( $args, true ) . ' );';
        }

        foreach ( $manual_taxes as $slug => $args ) {
            $post_types = $args['object_type'] ?? [];
            unset( $args['object_type'] );
            $lines[] = "\tregister_taxonomy( " . var_export( $slug, true ) . ', ' . var_export( $post_types, true ) . ', ' . var_export( $args, true ) . ' );';
        }

        $lines[] = '} );';

        return implode( "\n", $lines );
    }

    // ─── Finish redirect handler ──────────────────────────────────────────

    public function render_page_maybe_show_result(): void {
        // Handled inline in render_page via get_stored_notice
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Hook: wpml_translation_job_data
     *
     * When a Translation Editor job is being prepared for a post that our plugin
     * already imported a translation for, pre-populate the job fields with the
     * existing translated content. Without this, the editor starts a blank new job.
     *
     * @param  array $job_data  WPML job field data (contents keyed by field name).
     * @param  mixed $element   Element descriptor passed by WPML (varies by version).
     * @return array
     */
    public function prefill_translation_job_from_existing( $job_data, $element ) {
        if ( ! is_array( $job_data ) || ! isset( $job_data['contents'] ) ) {
            return $job_data;
        }

        // WPML passes source post ID and target language in several possible keys
        $source_id   = (int) ( $job_data['original_doc_id'] ?? $job_data['element_id'] ?? 0 );
        $target_lang = $job_data['language_code']
            ?? ( is_array( $element ) ? ( $element['language_code'] ?? '' ) : '' );

        if ( ! $source_id || ! $target_lang ) {
            return $job_data;
        }

        $post_type = get_post_type( $source_id );
        if ( ! $post_type ) {
            return $job_data;
        }

        // Find the already-imported translated post in the target language
        $translated_id = apply_filters( 'wpml_object_id', $source_id, $post_type, false, $target_lang );
        if ( ! $translated_id || (int) $translated_id === $source_id ) {
            return $job_data;
        }

        $translated_post = get_post( $translated_id );
        if ( ! $translated_post ) {
            return $job_data;
        }

        $field_map = [
            'title'   => $translated_post->post_title,
            'body'    => $translated_post->post_content,
            'excerpt' => $translated_post->post_excerpt,
        ];

        foreach ( $field_map as $field => $value ) {
            if ( isset( $job_data['contents'][ $field ] ) ) {
                $job_data['contents'][ $field ]['data']   = base64_encode( $value );
                $job_data['contents'][ $field ]['format'] = 'base64';
            }
        }

        return $job_data;
    }

    private function get_all_taxonomies(): array {
        $excluded = [ 'nav_menu', 'link_category', 'post_format' ];
        $result   = [];
        foreach ( get_taxonomies( [], 'objects' ) as $slug => $obj ) {
            if ( in_array( $slug, $excluded, true ) ) continue;
            $result[ $slug ] = $obj->labels->singular_name ?: $slug;
        }
        asort( $result );
        return $result;
    }

    private function get_importable_post_types(): array {
        $excluded = [ 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset',
                      'oembed_cache', 'wp_block', 'wp_template', 'wp_template_part',
                      'wp_global_styles', 'wp_navigation' ];

        $result = [];
        foreach ( get_post_types( [], 'objects' ) as $slug => $obj ) {
            if ( in_array( $slug, $excluded, true ) ) continue;
            $result[ $slug ] = $obj->labels->singular_name ?: $slug;
        }
        asort( $result );
        return $result;
    }

    private function get_wpml_languages(): array {
        global $sitepress;
        if ( ! $sitepress ) return [];
        $result = [];
        foreach ( $sitepress->get_active_languages() as $code => $data ) {
            $result[ $code ] = $data['display_name'] ?? $code;
        }
        return $result;
    }

    private function store_notice( string $type, array $messages ): void {
        set_transient( 'wpml_porter_notice_' . get_current_user_id(), compact( 'type', 'messages' ), 60 );
    }

    private function get_stored_notice(): ?array {
        $key = 'wpml_porter_notice_' . get_current_user_id();
        // Also handle ?imported=1 query string set by the JS after completion
        if ( isset( $_GET['imported'] ) ) {
            $messages = [ sprintf(
                __( 'Import complete — <strong>%d inserted</strong>, <strong>%d updated</strong>, <strong>%d linked</strong>.', 'wpml-porter' ),
                (int) ( $_GET['inserted'] ?? 0 ),
                (int) ( $_GET['updated']  ?? 0 ),
                (int) ( $_GET['linked']   ?? 0 )
            ) ];

            if ( ! empty( $_GET['elementor'] ) ) {
                $elementor_active = did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
                if ( $elementor_active ) {
                    $messages[] = __(
                        'This import included Elementor content — its layout data was imported as-is and its CSS cache was automatically cleared, so pages should render correctly (in both Classic and Block themes — Elementor renders its own layout on the front end regardless of theme type). If any images look missing, that\'s because this plugin doesn\'t migrate the media library — the imported layout still references the source site\'s attachment IDs, so images need to already exist on this site with matching IDs, or be re-selected in Elementor.',
                        'wpml-porter'
                    );
                } else {
                    $messages[] = __(
                        'This import included Elementor content, but the Elementor plugin doesn\'t appear to be active on this site. The layout data was imported as-is (nothing was discarded) — install/activate Elementor to see it render.',
                        'wpml-porter'
                    );
                }
            }

            return [ 'type' => 'success', 'messages' => $messages ];
        }
        $notice = get_transient( $key );
        if ( $notice ) { delete_transient( $key ); return $notice; }
        return null;
    }

    private function redirect_back( string $tab = 'import' ): void {
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&tab=' . $tab ) );
        exit;
    }

    // ─── CSS ─────────────────────────────────────────────────────────────

    private function inline_css(): string {
        return '
        .wpml-porter-wrap { max-width: 900px; }
        .wpml-porter-header { display:flex; align-items:center; gap:12px; padding:28px 0 12px; border-bottom:2px solid #2271b1; }
        .wpml-porter-logo { font-size:36px; line-height:1; }
        .wpml-porter-header h1 { margin:0; font-size:24px; color:#1d2327; }
        .wpml-porter-tagline { margin:0 0 0 4px; color:#646970; font-size:13px; align-self:flex-end; padding-bottom:3px; }
        .wpml-porter-tabs { display:flex; margin-top:0; border-bottom:1px solid #c3c4c7; }
        .wpml-porter-tab { padding:10px 20px; font-size:14px; font-weight:600; text-decoration:none; color:#646970; border:1px solid transparent; border-bottom:none; margin-bottom:-1px; border-radius:4px 4px 0 0; }
        .wpml-porter-tab:hover { color:#2271b1; }
        .wpml-porter-tab.is-active { color:#1d2327; background:#f6f7f7; border-color:#c3c4c7; border-bottom-color:#f6f7f7; }
        .wpml-porter-body { background:#f6f7f7; border:1px solid #c3c4c7; border-top:none; padding:28px; border-radius:0 0 4px 4px; }
        .wpml-porter-form { max-width:640px; }
        .wpml-porter-fieldset { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:20px 24px; margin-bottom:20px; }
        .wpml-porter-fieldset h2 { margin:0 0 16px; font-size:15px; font-weight:600; color:#1d2327; padding-bottom:10px; border-bottom:1px solid #f0f0f1; }
        .wpml-porter-field { margin-bottom:18px; }
        .wpml-porter-field label { display:block; font-weight:600; margin-bottom:6px; color:#1d2327; font-size:13px; }
        .wpml-porter-field select, .wpml-porter-field textarea, .wpml-porter-field input[type="file"] { width:100%; max-width:100%; }
        .wpml-porter-field textarea { font-family:monospace; font-size:12px; resize:vertical; }
        .wpml-porter-field .description { margin-top:6px; font-size:12px; color:#646970; }
        .wpml-porter-field--check label { font-weight:normal; display:flex; align-items:center; gap:8px; cursor:pointer; }
        .wpml-porter-btn { padding:8px 20px !important; font-size:14px !important; }
        .wpml-porter-info-box { margin-top:28px; background:#fff; border:1px solid #dcdcde; border-left:4px solid #2271b1; border-radius:0 4px 4px 0; padding:16px 20px; max-width:640px; }
        .wpml-porter-info-box h3 { margin:0 0 10px; font-size:13px; font-weight:600; }
        .wpml-porter-info-box ul { margin:0; list-style:disc; padding-left:18px; }
        .wpml-porter-info-box li { margin-bottom:5px; font-size:13px; color:#3c434a; }
        .wpml-porter-info-box code { font-size:12px; background:#f0f0f1; padding:1px 4px; border-radius:2px; }
        .wpml-porter-notice p { margin:6px 0; }
        .wpml-porter-footer { margin-top:20px; font-size:12px; color:#787c82; text-align:center; }

        /* ── Progress UI ── */
        .wpml-porter-progress-wrap h2 { margin:0 0 20px; font-size:16px; }
        .wpml-porter-progress-bar-wrap { background:#dcdcde; border-radius:6px; height:22px; overflow:hidden; max-width:600px; margin-bottom:10px; }
        .wpml-porter-progress-bar { height:100%; background:#2271b1; border-radius:6px; transition:width .4s ease; }
        .wpml-porter-progress-label { margin:0 0 12px; font-size:13px; color:#1d2327; font-weight:600; }
        .wpml-porter-progress-stats { font-size:13px; color:#646970; margin-bottom:16px; }
        .wpml-porter-log { max-width:600px; max-height:200px; overflow-y:auto; background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:10px 14px; font-size:12px; font-family:monospace; }
        .wpml-porter-log p { margin:3px 0; }
        .wpml-porter-log-warn  { color:#996800; }
        .wpml-porter-log-error { color:#cc1818; }
        .wpml-porter-log-info  { color:#646970; }
        /* ── Taxonomy & ACF tab grid ── */
        .wpml-porter-taxonomy-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px; }
        @media (max-width: 900px) { .wpml-porter-taxonomy-grid { grid-template-columns:1fr; } }
        .wpml-porter-checklist { max-height:180px; overflow-y:auto; border:1px solid #dcdcde; border-radius:4px; padding:8px 10px; background:#fafafa; }
        .wpml-porter-check-row { display:flex; align-items:center; gap:7px; padding:3px 0; font-size:13px; font-weight:normal; cursor:pointer; }
        .wpml-porter-check-row input[type="checkbox"] { margin:0; }
        ';
    }
}
