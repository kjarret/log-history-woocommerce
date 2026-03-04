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

        if (!$post_id || 'product' !== get_post_type($post_id)) {
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
        // -- 1. Vérification de la classe Log_Query -------------------------
        $log_query = self::make_log_query();

        if (null === $log_query) {
            return [];
        }

        // -- 2. Requête SQL directe pour récupérer les history_id ----------
        $post_ids = self::get_history_ids_for_post($post_id, 'SimplePostLogger', 'post_id');

        // -- 3. Requête Log_Query via context_filters (méthode API) --------
        $args_api = [
            'posts_per_page' => self::MERGE_LIMIT,
            'paged' => 1,
            'ungrouped' => true,
            'loggers' => ['SimplePostLogger'],
            'context_filters' => ['post_id' => (string) $post_id],
        ];

        $result_api = $log_query->query($args_api);

        // -- 4. Requête Log_Query via post__in (plus fiable) ---------------
        $rows = [];

        if (!empty($post_ids)) {
            $result_postids = $log_query->query([
                'posts_per_page' => self::MERGE_LIMIT,
                'paged' => 1,
                'ungrouped' => true,
                'post__in' => $post_ids,
            ]);
            $rows = $result_postids['log_rows'] ?? [];

            if (empty($rows)) {
                $rows = $result_api['log_rows'] ?? [];
            }
        } else {
            $rows = $result_api['log_rows'] ?? [];
        }

        // -- 5. Add-on WooCommerce (optionnel) ------------------------------
        if (self::is_wc_addon_active()) {
            $wc_ids = self::get_history_ids_for_post($post_id, 'WooCommerceLogger', 'product_id');

            if (!empty($wc_ids)) {
                $result_wc = $log_query->query([
                    'posts_per_page' => self::MERGE_LIMIT,
                    'paged' => 1,
                    'ungrouped' => true,
                    'post__in' => $wc_ids,
                ]);
                $rows = array_merge($rows, $result_wc['log_rows'] ?? []);
            }
        }

        // -- 6. Logger ACF (optionnel) -------------------------------------
        if (self::is_acf_active()) {
            $acf_ids = self::get_history_ids_for_post($post_id, 'LhwcAcfLogger', 'post_id');

            if (!empty($acf_ids)) {
                $result_acf = $log_query->query([
                    'posts_per_page' => self::MERGE_LIMIT,
                    'paged' => 1,
                    'ungrouped' => true,
                    'post__in' => $acf_ids,
                ]);
                $rows = array_merge($rows, $result_acf['log_rows'] ?? []);
            }
        }

        // -- 7. Tri + dédoublonnage -----------------------------------------
        usort($rows, static fn($a, $b) => strtotime($b->date) - strtotime($a->date));

        $seen = [];
        $rows = array_values(array_filter($rows, static function ($row) use (&$seen) {
            if (isset($seen[$row->id])) {
                return false;
            }
            $seen[$row->id] = true;
            return true;
        }));

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
            || class_exists('\Simple_History\AddOns\WooCommerce\WooCommerce_Logger')
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

                <?php
                $changed_fields_html = self::render_wc_changed_fields($context, $message_key);
                if ($changed_fields_html): ?>
                <div class="lhwc-row__changed-fields">
                    <?php echo $changed_fields_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </div>
                <?php endif; ?>

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
        // Traduction française des messages courants de SimplePostLogger + WooCommerce + ACF logger
        $fr_templates = [
            // SimplePostLogger
            'post_updated'          => 'Produit modifié : {post_title}',
            'post_created'          => 'Produit créé : {post_title}',
            'post_trashed'          => 'Produit mis à la corbeille : {post_title}',
            'post_deleted'          => 'Produit supprimé définitivement : {post_title}',
            'post_restored'         => 'Produit restauré : {post_title}',
            'post_status_changed'   => 'Statut changé ({post_prev_status} → {post_new_status}) : {post_title}',
            'post_published'        => 'Produit publié : {post_title}',
            // WooCommerceLogger
            'product_created'       => 'Produit créé : {product_title}',
            'product_updated'       => 'Produit mis à jour : {product_title}',
            'product_trashed'       => 'Produit mis à la corbeille : {product_title}',
            'product_untrashed'     => 'Produit restauré : {product_title}',
            'product_deleted'       => 'Produit supprimé définitivement : {product_title}',
            // LhwcAcfLogger (le tableau old→new est rendu séparément via render_changed_fields)
            'acf_field_updated'     => 'Champ ACF modifié : <em>{field_label}</em>',
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
            'post_created'      => 'plus-alt2 lhwc-icon--created',
            'post_updated'      => 'edit lhwc-icon--updated',
            'post_trashed'      => 'trash lhwc-icon--trashed',
            'post_deleted'      => 'dismiss lhwc-icon--deleted',
            'post_restored'     => 'undo lhwc-icon--restored',
            'post_status_changed' => 'flag lhwc-icon--status',
            'post_published'    => 'visibility lhwc-icon--published',
            'acf_field_updated' => 'editor-textcolor lhwc-icon--acf',
            // WooCommerceLogger
            'product_created'   => 'plus-alt2 lhwc-icon--created',
            'product_updated'   => 'edit lhwc-icon--updated',
            'product_trashed'   => 'trash lhwc-icon--trashed',
            'product_untrashed' => 'undo lhwc-icon--restored',
            'product_deleted'   => 'dismiss lhwc-icon--deleted',
        ];

        $default = 'SimplePostLogger' === $logger ? 'info-outline lhwc-icon--info' : 'cart lhwc-icon--woo';

        return '<span class="dashicons dashicons-' . esc_attr($map[$message_key] ?? $default) . '"></span>';
    }

    /**
     * Génère le HTML des champs modifiés pour un log WooCommerceLogger.
     *
     * Extrait les paires {champ}_new / {champ}_prev du contexte et les affiche
     * sous forme de tableau (ancienne valeur → nouvelle valeur).
     *
     * @param array  $context     Contexte du log.
     * @param string $message_key Clé du message.
     * @return string HTML ou chaîne vide.
     */
    private static function render_wc_changed_fields(array $context, string $message_key): string
    {
        $wc_message_keys = ['product_created', 'product_updated', 'product_trashed', 'product_untrashed', 'product_deleted'];
        if (!in_array($message_key, $wc_message_keys, true)) {
            return '';
        }

        // Étiquettes françaises des champs WooCommerce.
        $labels = [
            'title'              => 'Titre',
            'product_type'       => 'Type de produit',
            'status'             => 'Statut',
            'featured'           => 'Mise en avant',
            'catalog_visibility' => 'Visibilité catalogue',
            'description'        => 'Description',
            'short_description'  => 'Description courte',
            'sku'                => 'UGS',
            'price'              => 'Prix',
            'regular_price'      => 'Prix normal',
            'sale_price'         => 'Prix promo',
            'date_on_sale_from'  => 'Début promo',
            'date_on_sale_to'    => 'Fin promo',
            'manage_stock'       => 'Gestion du stock',
            'stock_quantity'     => 'Quantité en stock',
            'stock_status'       => 'État du stock',
            'backorders'         => 'Commandes en attente',
            'low_stock_amount'   => 'Seuil de stock faible',
            'sold_individually'  => 'Vente individuelle',
            'weight'             => 'Poids',
            'length'             => 'Longueur',
            'width'              => 'Largeur',
            'height'             => 'Hauteur',
            'shipping_class_id'  => 'Classe de livraison',
            'tax_status'         => 'Statut TVA',
            'tax_class'          => 'Classe TVA',
            'reviews_allowed'    => 'Avis clients',
            'virtual'            => 'Virtuel',
            'downloadable'       => 'Téléchargeable',
            'image_id'           => 'Image principale',
            'slug'               => 'Permalien',
            'upsell_ids'         => 'Ventes incitatives',
            'cross_sell_ids'     => 'Ventes croisées',
            'attributes_added'   => 'Attributs ajoutés',
            'attributes_removed' => 'Attributs supprimés',
            'attributes_modified' => 'Attributs modifiés',
            'name'               => 'Nom',
            'purchase_note'      => 'Note d\'achat',
            'download_limit'     => 'Limite de téléchargement',
            'download_expiry'    => 'Expiration du téléchargement',
        ];

        // Traductions des valeurs courantes.
        $value_labels = [
            'instock'     => 'En stock',
            'outofstock'  => 'Rupture de stock',
            'onbackorder' => 'En réapprovisionnement',
            'yes'         => 'Oui',
            'no'          => 'Non',
            'taxable'     => 'Taxable',
            'shipping'    => 'Livraison seulement',
            'none'        => 'Aucune',
            'visible'     => 'Visible',
            'catalog'     => 'Catalogue',
            'search'      => 'Recherche',
            'hidden'      => 'Masqué',
            'simple'      => 'Produit simple',
            'variable'    => 'Produit variable',
            'grouped'     => 'Produit groupé',
            'external'    => 'Produit externe',
            'publish'     => 'Publié',
            'draft'       => 'Brouillon',
            'private'     => 'Privé',
            'pending'     => 'En attente',
        ];

        $translate_value = static function (string $val) use ($value_labels): string {
            return $value_labels[$val] ?? $val;
        };

        // Collecte les paires _new / _prev.
        $changes = [];
        foreach ($context as $key => $value) {
            if (substr($key, -4) !== '_new') {
                continue;
            }
            $field = substr($key, 0, -4);
            // Ignore les clés internes Simple History.
            if (in_array($field, ['product_id', 'product_title', '_user', '_server', '_message'], true)) {
                continue;
            }
            $prev_key = $field . '_prev';
            $new_val  = is_array($value) ? wp_json_encode($value) : (string) $value;
            $prev_val = isset($context[$prev_key])
                ? (is_array($context[$prev_key]) ? wp_json_encode($context[$prev_key]) : (string) $context[$prev_key])
                : null;

            // N'affiche que si la valeur a changé (ou si c'est une création sans valeur précédente).
            if ($new_val === $prev_val) {
                continue;
            }

            $changes[$field] = [
                'label' => $labels[$field] ?? ucfirst(str_replace('_', ' ', $field)),
                'prev'  => null !== $prev_val ? $translate_value($prev_val) : null,
                'new'   => $translate_value($new_val),
            ];
        }

        // Attributs (stockés dans des clés spéciales sans suffixe _new/_prev).
        foreach (['attributes_added', 'attributes_removed', 'attributes_modified'] as $attr_key) {
            if (!empty($context[$attr_key])) {
                $raw = $context[$attr_key];
                $changes[$attr_key] = [
                    'label' => $labels[$attr_key],
                    'prev'  => null,
                    'new'   => is_string($raw) ? $raw : wp_json_encode($raw),
                ];
            }
        }

        if (empty($changes)) {
            return '';
        }

        ob_start();
        ?>
        <table class="lhwc-fields-table">
            <thead>
                <tr>
                    <th>Champ</th>
                    <th>Avant</th>
                    <th>Après</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($changes as $change): ?>
                <tr>
                    <td class="lhwc-field-name"><?php echo esc_html($change['label']); ?></td>
                    <td class="lhwc-field-prev">
                        <?php if (null !== $change['prev']): ?>
                            <del><?php echo esc_html($change['prev']); ?></del>
                        <?php else: ?>
                            <em>—</em>
                        <?php endif; ?>
                    </td>
                    <td class="lhwc-field-new"><?php echo esc_html($change['new']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}
