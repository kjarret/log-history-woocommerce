<?php
/**
 * Metabox affichant l'historique Simple History sur la fiche produit WooCommerce.
 *
 * @package LHWC
 */

namespace LHWC;

defined('ABSPATH') || exit;

class Product_Log_Metabox
{

    /** Nombre d'entrées par page. */
    const PER_PAGE = 20;

    /** Limite haute pour la fusion de deux loggers (chargée en mémoire). */
    const MERGE_LIMIT = 200;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'register']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('wp_ajax_lhwc_load_logs', [__CLASS__, 'ajax_load_logs']);
    }

    // -------------------------------------------------------------------------
    // Enregistrement de la metabox
    // -------------------------------------------------------------------------

    public static function register(): void
    {
        add_meta_box(
            'lhwc-product-logs',
            'Historique des modifications',
            [__CLASS__, 'render'],
            'product',
            'normal',
            'low'
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        global $post;
        if (!$post || 'product' !== $post->post_type) {
            return;
        }

        wp_enqueue_style(
            'lhwc-admin',
            LHWC_PLUGIN_URL . 'assets/css/admin.css',
            [],
            LHWC_VERSION
        );

        wp_enqueue_script(
            'lhwc-admin',
            LHWC_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            LHWC_VERSION,
            true
        );

        wp_localize_script('lhwc-admin', 'lhwcData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lhwc_load_logs'),
            'postId' => $post->ID,
            'i18n' => [
                'loadMore' => 'Charger plus',
                'loading' => 'Chargement…',
                'noMore' => 'Tous les événements sont affichés.',
                'error' => 'Erreur lors du chargement.',
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Rendu initial (côté serveur)
    // -------------------------------------------------------------------------

    public static function render(\WP_Post $post): void
    {
        if ('product' !== $post->post_type) {
            return;
        }

        $all_rows = self::fetch_all_rows($post->ID);
        $total = count($all_rows);
        $page1 = array_slice($all_rows, 0, self::PER_PAGE);

        echo '<div class="lhwc-wrapper">';

        echo '<div class="lhwc-toolbar">';
        echo '<span class="lhwc-total">' . ($total
            ? esc_html($total . ' ' . (1 === $total ? 'événement' : 'événements'))
            : 'Aucun événement'
        ) . '</span>';
        echo '</div>';

        echo '<div class="lhwc-log-list" data-post-id="' . esc_attr($post->ID) . '">';

        if (empty($page1)) {
            echo '<p class="lhwc-empty">Aucun log trouvé pour ce produit.</p>';
        } else {
            foreach ($page1 as $row) {
                echo self::render_row($row); // phpcs:ignore WordPress.Security.EscapeOutput
            }
        }

        echo '</div>';

        if ($total > self::PER_PAGE) {
            echo '<div class="lhwc-footer">';
            echo '<button type="button" class="button lhwc-load-more"'
                . ' data-offset="' . self::PER_PAGE . '">'
                . 'Charger plus'
                . '</button>';
            echo '</div>';
        }

        echo '</div>';
    }

    // -------------------------------------------------------------------------
    // AJAX : charger une page supplémentaire
    // -------------------------------------------------------------------------

    public static function ajax_load_logs(): void
    {
        check_ajax_referer('lhwc_load_logs', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => 'Non autorisé.'], 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $offset = absint($_POST['offset'] ?? 0);

        error_log('[LHWC] ajax_load_logs — post_id=' . $post_id . ' offset=' . $offset);

        if (!$post_id || 'product' !== get_post_type($post_id)) {
            error_log('[LHWC] ajax_load_logs — post_id invalide ou pas un produit.');
            wp_send_json_error(['message' => 'Produit invalide.'], 400);
        }

        $all_rows = self::fetch_all_rows($post_id);
        $slice = array_slice($all_rows, $offset, self::PER_PAGE);
        $html = '';

        foreach ($slice as $row) {
            $html .= self::render_row($row);
        }

        $new_offset = $offset + self::PER_PAGE;

        wp_send_json_success([
            'html' => $html,
            'has_more' => $new_offset < count($all_rows),
            'new_offset' => $new_offset,
        ]);
    }

    // -------------------------------------------------------------------------
    // Récupération des logs
    // -------------------------------------------------------------------------

    /**
     * Récupère tous les logs pour un produit, triés par date décroissante.
     *
     * Stratégie :
     *  1. Requête SQL directe pour être certain de trouver les bons history_id.
     *  2. Requête Log_Query avec post__in pour récupérer les rows complètes
     *     (contexte inclus).
     *  3. Si l'add-on WooCommerce est actif, même chose pour WooCommerceLogger.
     */
    private static function fetch_all_rows(int $post_id): array
    {
        error_log('[LHWC] ===== fetch_all_rows START post_id=' . $post_id . ' =====');

        // -- 1. Vérification de la classe Log_Query -------------------------
        $log_query = self::make_log_query();

        if (null === $log_query) {
            error_log('[LHWC] ERREUR : aucune classe Log_Query trouvée (SimpleHistoryLogQuery / Simple_History\Log_Query).');
            error_log('[LHWC] Classes disponibles (filtrage "simple") : ' . implode(', ', array_filter(get_declared_classes(), fn($c) => false !== stripos($c, 'simple'))));
            return [];
        }

        error_log('[LHWC] Log_Query trouvée : ' . get_class($log_query));

        // -- 2. Requête SQL directe pour récupérer les history_id ----------
        $post_ids = self::get_history_ids_for_post($post_id, 'SimplePostLogger', 'post_id');
        error_log('[LHWC] SQL direct SimplePostLogger — ' . count($post_ids) . ' history_id trouvés : ' . implode(', ', array_slice($post_ids, 0, 10)));

        // -- 3. Requête Log_Query via context_filters (méthode API) --------
        $args_api = [
            'posts_per_page' => self::MERGE_LIMIT,
            'paged' => 1,
            'ungrouped' => true,
            'loggers' => ['SimplePostLogger'],
            'context_filters' => ['post_id' => (string) $post_id],
        ];
        error_log('[LHWC] Log_Query args (context_filters) : ' . wp_json_encode($args_api));

        $result_api = $log_query->query($args_api);
        error_log('[LHWC] Log_Query (context_filters) — total_row_count=' . ($result_api['total_row_count'] ?? 'N/A') . ' log_rows=' . count($result_api['log_rows'] ?? []));

        // -- 4. Requête Log_Query via post__in (plus fiable) ---------------
        $rows = [];

        if (!empty($post_ids)) {
            $args_postids = [
                'posts_per_page' => self::MERGE_LIMIT,
                'paged' => 1,
                'ungrouped' => true,
                'post__in' => $post_ids,
            ];
            error_log('[LHWC] Log_Query args (post__in) : ' . count($post_ids) . ' IDs');

            $result_postids = $log_query->query($args_postids);
            $rows = $result_postids['log_rows'] ?? [];
            error_log('[LHWC] Log_Query (post__in) — total_row_count=' . ($result_postids['total_row_count'] ?? 'N/A') . ' log_rows=' . count($rows));

            // Si post__in a retourné des résultats, on les utilise (plus précis).
            // Sinon on se rabat sur context_filters.
            if (empty($rows)) {
                error_log('[LHWC] post__in vide — on utilise le résultat context_filters.');
                $rows = $result_api['log_rows'] ?? [];
            }
        } else {
            error_log('[LHWC] Aucun history_id SQL — on utilise uniquement context_filters.');
            $rows = $result_api['log_rows'] ?? [];
        }

        // Log du premier résultat pour inspecter la structure
        if (!empty($rows)) {
            $first = $rows[0];
            error_log('[LHWC] Premier log — id=' . $first->id . ' date=' . $first->date . ' logger=' . $first->logger . ' message=' . $first->message);
            error_log('[LHWC] Contexte du premier log : ' . wp_json_encode($first->context ?? []));
        }

        // -- 5. Add-on WooCommerce (optionnel) ------------------------------
        if (self::is_wc_addon_active()) {
            error_log('[LHWC] Add-on WooCommerce détecté.');

            $wc_ids = self::get_history_ids_for_post($post_id, 'WooCommerceLogger', 'product_id');
            error_log('[LHWC] SQL direct WooCommerceLogger — ' . count($wc_ids) . ' history_id trouvés.');

            if (!empty($wc_ids)) {
                $result_wc = $log_query->query([
                    'posts_per_page' => self::MERGE_LIMIT,
                    'paged' => 1,
                    'ungrouped' => true,
                    'post__in' => $wc_ids,
                ]);
                $wc_rows = $result_wc['log_rows'] ?? [];
                error_log('[LHWC] WooCommerceLogger — ' . count($wc_rows) . ' lignes.');
                $rows = array_merge($rows, $wc_rows);
            }
        } else {
            error_log('[LHWC] Add-on WooCommerce non détecté.');
        }

        // -- 6. Logger ACF (optionnel) -------------------------------------
        if (self::is_acf_active()) {
            $acf_ids = self::get_history_ids_for_post($post_id, 'LhwcAcfLogger', 'post_id');
            error_log('[LHWC] LhwcAcfLogger — ' . count($acf_ids) . ' history_id trouvés.');

            if (!empty($acf_ids)) {
                $result_acf = $log_query->query([
                    'posts_per_page' => self::MERGE_LIMIT,
                    'paged' => 1,
                    'ungrouped' => true,
                    'post__in' => $acf_ids,
                ]);
                $acf_rows = $result_acf['log_rows'] ?? [];
                error_log('[LHWC] LhwcAcfLogger — ' . count($acf_rows) . ' lignes.');
                $rows = array_merge($rows, $acf_rows);
            }
        } else {
            error_log('[LHWC] ACF non détecté — LhwcAcfLogger ignoré.');
        }

        // -- 6. Tri + dédoublonnage -----------------------------------------
        usort($rows, static fn($a, $b) => strtotime($b->date) - strtotime($a->date));

        $seen = [];
        $rows = array_values(array_filter($rows, static function ($row) use (&$seen) {
            if (isset($seen[$row->id])) {
                return false;
            }
            $seen[$row->id] = true;
            return true;
        }));

        error_log('[LHWC] fetch_all_rows END — ' . count($rows) . ' lignes après fusion/dédup.');

        return $rows;
    }

    /**
     * Requête SQL directe pour récupérer les history_id d'un logger
     * correspondant à un post_id (via la clé de contexte indiquée).
     *
     * Utiliser SQL directement contourne les éventuels bugs du contexte
     * context_filters de Log_Query et nous permet de vérifier la base réelle.
     *
     * @param int    $post_id     ID du produit WooCommerce.
     * @param string $logger_slug Slug du logger (ex: 'SimplePostLogger').
     * @param string $context_key Clé de contexte à filtrer (ex: 'post_id').
     * @return int[] Liste de history_id.
     */
    private static function get_history_ids_for_post(int $post_id, string $logger_slug, string $context_key): array
    {
        global $wpdb;

        $table_h = $wpdb->prefix . 'simple_history';
        $table_c = $wpdb->prefix . 'simple_history_contexts';

        // Vérification que les tables existent
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_h}'"); // phpcs:ignore
        if (!$table_exists) {
            error_log('[LHWC] Table ' . $table_h . ' introuvable !');
            return [];
        }

        // On cherche les history_id associés au post_id via la clé de contexte.
        // On log aussi le count brut pour être sûr de ce qui est en base.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT c.history_id
             FROM {$table_c} c
             INNER JOIN {$table_h} h ON h.id = c.history_id
             WHERE h.logger = %s
               AND c.`key`  = %s
               AND c.value  = %s
             ORDER BY c.history_id DESC
             LIMIT %d",
            $logger_slug,
            $context_key,
            (string) $post_id,
            self::MERGE_LIMIT
        ));

        if ($wpdb->last_error) {
            error_log('[LHWC] Erreur SQL get_history_ids_for_post : ' . $wpdb->last_error);
        }

        // Debug étendu : affiche quelques lignes brutes pour ce logger/post
        $sample = $wpdb->get_results($wpdb->prepare(
            "SELECT h.id, h.logger, h.date, h.message, h.initiator
             FROM {$table_c} c
             INNER JOIN {$table_h} h ON h.id = c.history_id
             WHERE c.`key` = %s AND c.value = %s
             ORDER BY h.date DESC
             LIMIT 5",
            $context_key,
            (string) $post_id
        ));

        error_log('[LHWC] Échantillon SQL (' . $context_key . '=' . $post_id . ') : ' . wp_json_encode($sample));

        // ---------------------------------------------------------------
        // DEBUG ÉTENDU (uniquement pour SimplePostLogger au premier appel)
        // ---------------------------------------------------------------
        if ('SimplePostLogger' === $logger_slug) {

            // 1. Cherche la valeur $post_id dans N'IMPORTE quelle clé de contexte
            //    → révèle si Simple History stocke l'ID sous un autre nom de clé.
            $any_key = $wpdb->get_results($wpdb->prepare(
                "SELECT h.id, h.logger, c.`key` as ctx_key, c.value as ctx_val, h.date
                 FROM {$table_c} c
                 INNER JOIN {$table_h} h ON h.id = c.history_id
                 WHERE c.value = %s
                 ORDER BY h.date DESC
                 LIMIT 10",
                (string) $post_id
            ));
            error_log('[LHWC] DEBUG any_key: clés contenant la valeur ' . $post_id . ' : ' . wp_json_encode($any_key));

            // 2. Montre les 5 derniers logs de SimplePostLogger (tous produits confondus)
            //    → vérifie que Simple History capture bien les sauvegardes de produits.
            $recent_product = $wpdb->get_results($wpdb->prepare(
                "SELECT h.id, h.date, h.message, h.initiator,
                        MAX(CASE WHEN c.`key`='post_id'    THEN c.value END) as ctx_post_id,
                        MAX(CASE WHEN c.`key`='post_type'  THEN c.value END) as ctx_post_type,
                        MAX(CASE WHEN c.`key`='_message_key' THEN c.value END) as ctx_msg_key,
                        MAX(CASE WHEN c.`key`='_user_login'  THEN c.value END) as ctx_user
                 FROM {$table_h} h
                 LEFT JOIN {$table_c} c ON c.history_id = h.id
                 WHERE h.logger = %s
                 GROUP BY h.id
                 ORDER BY h.date DESC
                 LIMIT 5",
                $logger_slug
            ));
            error_log('[LHWC] DEBUG recent_logs SimplePostLogger (5 derniers, tous produits) : ' . wp_json_encode($recent_product));
        }
        // ---------------------------------------------------------------

        return array_map('intval', $ids ?: []);
    }

    /**
     * Instancie le Log_Query en gérant les deux nommages.
     */
    private static function make_log_query(): ?\Simple_History\Log_Query
    {
        if (class_exists('\Simple_History\Log_Query')) {
            return new \Simple_History\Log_Query();
        }
        if (class_exists('SimpleHistoryLogQuery')) {
            /** @var \Simple_History\Log_Query */
            return new \SimpleHistoryLogQuery(); // @phpstan-ignore-line
        }
        return null;
    }

    /**
     * Détecte si ACF (Advanced Custom Fields) est actif.
     */
    private static function is_acf_active(): bool
    {
        return function_exists('acf_get_field') || class_exists('ACF');
    }

    /**
     * Détecte si l'add-on WooCommerce de Simple History est actif.
     */
    private static function is_wc_addon_active(): bool
    {
        return class_exists('SimpleHistoryWooCommerceLogger')
            || class_exists('\Simple_History\Loggers\WooCommerceLogger')
            || class_exists('WooCommerceLogger');
    }

    // -------------------------------------------------------------------------
    // Rendu d'un seul log row
    // -------------------------------------------------------------------------

    private static function render_row(object $row): string
    {
        $context = (array) ($row->context ?? []);
        $message_key = $context['_message_key'] ?? '';
        $level = $row->level ?? 'info';
        $logger = $row->logger ?? '';

        $message = self::interpolate($row->message ?? '', $context, $message_key);

        // Utilisateur
        $user_login = $context['_user_login'] ?? '';
        $user_email = $context['_user_email'] ?? '';
        $user_name = $user_login ?: 'Système';

        $avatar = $user_email
            ? get_avatar($user_email, 32, '', $user_name, ['class' => 'lhwc-avatar'])
            : '<span class="lhwc-avatar lhwc-avatar--system dashicons dashicons-admin-generic"></span>';

        // Date
        $ts = $row->date ? strtotime($row->date . ' UTC') : 0;
        $date_formatted = $ts ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '–';
        $date_relative = $ts ? 'il y a ' . human_time_diff($ts) : '';

        $icon = self::event_icon($message_key, $logger);
        $repeat = (int) ($row->subsequentOccasions ?? $row->repeatCount ?? 0);
        $ip = $context['_server_remote_addr'] ?? '';

        ob_start();
        ?>
        <div class="lhwc-row lhwc-level-<?php echo esc_attr($level); ?>">

            <div class="lhwc-row__icon" aria-hidden="true">
                <?php echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>

            <div class="lhwc-row__body">

                <div class="lhwc-row__header">
                    <?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <span class="lhwc-row__user"><?php echo esc_html($user_name); ?></span>
                    <time class="lhwc-row__date" datetime="<?php echo esc_attr($row->date); ?>"
                        title="<?php echo esc_attr($date_formatted); ?>">
                        <?php echo esc_html($date_formatted); ?>
                    </time>
                    <?php if ($date_relative): ?>
                        <span class="lhwc-row__rel"><?php echo esc_html($date_relative); ?></span>
                    <?php endif; ?>
                    <?php if ($repeat > 0): ?>
                        <span class="lhwc-row__badge" title="Événements groupés">
                            <?php echo esc_html($repeat + 1); ?>×
                        </span>
                    <?php endif; ?>
                </div>

                <div class="lhwc-row__message">
                    <?php echo wp_kses($message, ['strong' => [], 'em' => [], 'code' => []]); ?>
                </div>

                <?php if ($ip): ?>
                    <div class="lhwc-row__meta">
                        <span class="lhwc-row__ip dashicons-before dashicons-location-alt">
                            <?php echo esc_html($ip); ?>
                        </span>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    /**
     * Traduit et interpole le message Simple History en français.
     *
     * Simple History stocke des templates anglais comme "Updated {post_type} \"{post_title}\"".
     * On remplace d'abord par un template français, puis on substitue les {placeholders}.
     */
    private static function interpolate(string $message, array $context, string $message_key = ''): string
    {
        // Traduction française des messages courants de SimplePostLogger + ACF logger
        $fr_templates = [
            // SimplePostLogger
            'post_updated' => 'Produit modifié : {post_title}',
            'post_created' => 'Produit créé : {post_title}',
            'post_trashed' => 'Produit mis à la corbeille : {post_title}',
            'post_deleted' => 'Produit supprimé définitivement : {post_title}',
            'post_restored' => 'Produit restauré : {post_title}',
            'post_status_changed' => 'Statut changé ({post_prev_status} → {post_new_status}) : {post_title}',
            'post_published' => 'Produit publié : {post_title}',
            // LhwcAcfLogger
            'acf_field_updated' => 'Champ <em>{field_label}</em> modifié : {old_value} → {new_value}',
        ];

        $template = $fr_templates[$message_key] ?? $message;

        if ('' === $template) {
            return '';
        }

        $replace = [];
        foreach ($context as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $replace['{' . $key . '}'] = '<strong>' . esc_html((string) $value) . '</strong>';
            }
        }

        // Les templates sont des chaînes statiques contrôlées (pas d'input utilisateur).
        // On ne les passe PAS dans esc_html pour préserver les balises HTML (<em>…).
        // Les valeurs substituées via $replace sont, elles, déjà escapées.
        return strtr($template, $replace);
    }

    /**
     * Retourne une icône Dashicons pour un type d'événement.
     */
    private static function event_icon(string $message_key, string $logger): string
    {
        $map = [
            'post_created' => 'plus-alt2 lhwc-icon--created',
            'post_updated' => 'edit lhwc-icon--updated',
            'post_trashed' => 'trash lhwc-icon--trashed',
            'post_deleted' => 'dismiss lhwc-icon--deleted',
            'post_restored' => 'undo lhwc-icon--restored',
            'post_status_changed' => 'flag lhwc-icon--status',
            'post_published' => 'visibility lhwc-icon--published',
            'acf_field_updated' => 'editor-textcolor lhwc-icon--acf',
        ];

        $default = 'SimplePostLogger' === $logger ? 'info-outline lhwc-icon--info' : 'cart lhwc-icon--woo';

        return '<span class="dashicons dashicons-' . esc_attr($map[$message_key] ?? $default) . '"></span>';
    }
}
