<?php
/**
 * Logger Simple History pour les modifications de champs ACF sur les produits WooCommerce.
 *
 * Ce logger est enregistré conditionnellement (uniquement si ACF est actif).
 * Il s'appuie sur le hook acf/save_post pour capturer les anciennes valeurs (priority 1)
 * puis loguer les changements après la sauvegarde ACF (priority 20).
 *
 * @package LHWC
 */

namespace LHWC;

defined( 'ABSPATH' ) || exit;

class ACF_Logger extends \Simple_History\Loggers\Logger {

    /** Slug utilisé pour requêter les logs de ce logger. */
    public $slug = 'LhwcAcfLogger';

    /** Stockage des anciennes valeurs entre les deux passes du hook acf/save_post. */
    private static array $old_values = [];

    // -------------------------------------------------------------------------
    // Simple History : informations du logger
    // -------------------------------------------------------------------------

    public function get_info(): array {
        return [
            'name'        => 'LHWC — Champs ACF WooCommerce',
            'description' => 'Enregistre les modifications de champs ACF sur les produits WooCommerce.',
            'capability'  => 'edit_products',
            'messages'    => [
                __CLASS__ . '.acf_field_updated' => 'Champ ACF {field_label} modifié',
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Simple History : hooks enregistrés quand le logger est initialisé
    // -------------------------------------------------------------------------

    public function loaded(): void {
        // Priorité 1  : avant que ACF sauvegarde → récupérer les valeurs ACTUELLES en base.
        add_action( 'acf/save_post', [ $this, 'capture_old_values' ], 1 );

        // Priorité 20 : après la sauvegarde ACF (priority 10 par défaut) → comparer et logger.
        add_action( 'acf/save_post', [ $this, 'log_changes' ], 20 );
    }

    // -------------------------------------------------------------------------
    // Capture des anciennes valeurs (avant save)
    // -------------------------------------------------------------------------

    /**
     * Appelé à la priorité 1 de acf/save_post, avant qu'ACF écrive en base.
     * On lit les valeurs brutes actuelles pour chaque field_key soumis.
     *
     * @param int|string $post_id
     */
    public function capture_old_values( $post_id ): void {
        $post_id = (int) $post_id;

        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        if ( empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $old = [];
        foreach ( array_keys( $_POST['acf'] ) as $field_key ) { // phpcs:ignore WordPress.Security.NonceVerification
            // false = valeur brute (ID pour image/file, pas l'objet formaté)
            $old[ $field_key ] = get_field( $field_key, $post_id, false );
        }

        self::$old_values[ $post_id ] = $old;

        error_log( '[LHWC ACF] capture_old_values post_id=' . $post_id . ' — ' . count( $old ) . ' champs.' );
    }

    // -------------------------------------------------------------------------
    // Log des changements (après save)
    // -------------------------------------------------------------------------

    /**
     * Appelé à la priorité 20 de acf/save_post, après qu'ACF a sauvegardé.
     * Compare les nouvelles valeurs avec celles capturées et log les différences.
     *
     * @param int|string $post_id
     */
    public function log_changes( $post_id ): void {
        $post_id = (int) $post_id;

        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }

        if ( empty( $_POST['acf'] ) || ! is_array( $_POST['acf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        $old    = self::$old_values[ $post_id ] ?? [];
        $logged = 0;

        foreach ( array_keys( $_POST['acf'] ) as $field_key ) { // phpcs:ignore WordPress.Security.NonceVerification
            $field = acf_get_field( $field_key );
            if ( ! $field || ! is_array( $field ) ) {
                continue;
            }

            // Ignorer les champs internes ACF (tab, message, accordion…)
            if ( in_array( $field['type'], [ 'tab', 'message', 'accordion', 'group' ], true ) ) {
                continue;
            }

            $new_val = get_field( $field_key, $post_id, false );
            $old_val = $old[ $field_key ] ?? null;

            // Comparer en sérialisant pour gérer les tableaux
            if ( maybe_serialize( $old_val ) === maybe_serialize( $new_val ) ) {
                continue; // Aucune modification réelle
            }

            $old_formatted = self::format_value( $old_val, $field );
            $new_formatted = self::format_value( $new_val, $field );

            error_log( sprintf(
                '[LHWC ACF] log_changes — post_id=%d champ="%s" (%s) : "%s" → "%s"',
                $post_id,
                $field['label'],
                $field['type'],
                $old_formatted,
                $new_formatted
            ) );

            $this->info_message(
                'acf_field_updated',
                [
                    'post_id'     => $post_id,
                    'field_label' => $field['label'],
                    'field_name'  => $field['name'],
                    'field_key'   => $field_key,
                    'field_type'  => $field['type'],
                    'old_value'   => $old_formatted,
                    'new_value'   => $new_formatted,
                ]
            );

            ++$logged;
        }

        error_log( '[LHWC ACF] log_changes post_id=' . $post_id . ' — ' . $logged . ' champ(s) loggué(s).' );

        // Nettoyer pour éviter les fuites mémoire sur les imports batch
        unset( self::$old_values[ $post_id ] );
    }

    // -------------------------------------------------------------------------
    // Formatage des valeurs pour l'affichage
    // -------------------------------------------------------------------------

    /**
     * Convertit une valeur ACF brute en chaîne lisible.
     * Les valeurs complexes (repeater, galerie, etc.) sont résumées.
     *
     * @param mixed $value  Valeur brute renvoyée par get_field( ..., false ).
     * @param array $field  Tableau de configuration du champ ACF.
     * @return string
     */
    private static function format_value( $value, array $field ): string {
        $type = $field['type'] ?? '';

        // Vide
        if ( null === $value || false === $value || '' === $value ) {
            return '(vide)';
        }

        // --- Champs simples (scalaire) ---
        if ( is_string( $value ) || is_numeric( $value ) ) {
            $str = (string) $value;
            // Tronquer si trop long
            return mb_strlen( $str ) > 80 ? mb_substr( $str, 0, 77 ) . '…' : $str;
        }

        // --- Image / Fichier : get_field retourne l'ID (raw=false attend un int) ---
        if ( in_array( $type, [ 'image', 'file' ], true ) && is_int( $value ) ) {
            $filename = basename( get_attached_file( $value ) ?: '' );
            return $filename ?: 'ID ' . $value;
        }

        // --- Objet WP_Post (post_object, page_link) ---
        if ( $value instanceof \WP_Post ) {
            return $value->post_title ?: '(post sans titre)';
        }

        // --- Tableau ---
        if ( is_array( $value ) ) {
            // Tableau d'attachements (gallery) : liste des noms
            if ( ! empty( $value ) && is_int( $value[0] ?? null ) && in_array( $type, [ 'gallery' ], true ) ) {
                $count = count( $value );
                return $count . ' image' . ( $count > 1 ? 's' : '' );
            }

            // Tableau de WP_Post (relationship, post_object multiple)
            if ( ! empty( $value ) && $value[0] instanceof \WP_Post ) {
                $titles = array_map( static fn( $p ) => $p->post_title, array_slice( $value, 0, 3 ) );
                $extra  = count( $value ) > 3 ? ' (+' . ( count( $value ) - 3 ) . ')' : '';
                return implode( ', ', $titles ) . $extra;
            }

            // Select / Checkbox multiples : tableau de chaînes
            if ( ! empty( $value ) && is_string( $value[0] ?? null ) ) {
                $count = count( $value );
                if ( $count <= 3 ) {
                    return implode( ', ', $value );
                }
                return implode( ', ', array_slice( $value, 0, 3 ) ) . ' (+' . ( $count - 3 ) . ')';
            }

            // Repeater / Flexible content / autre tableau complexe
            $count = count( $value );
            return '(' . $count . ' élément' . ( $count > 1 ? 's' : '' ) . ')';
        }

        // Objet non reconnu
        return '(valeur complexe)';
    }
}
