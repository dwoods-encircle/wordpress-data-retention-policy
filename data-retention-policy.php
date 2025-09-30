<?php
/**
 * Plugin Name: Data Retention Policy Manager
 * Description: Allows administrators to configure data retention policies for users, posts, and pages.
 * Version: 1.1.0
 * Author: OpenAI Assistant
 * License: GPL2+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: data-retention-policy
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DRP_Manager {
    const VERSION               = '1.1.0';
    const OPTION_KEY            = 'drp_settings';
    const CRON_HOOK             = 'drp_run_policies';
    const USER_DISABLED_META    = 'drp_disabled';
    const USER_LAST_ACTIVE_META = 'drp_last_active';
    const CONTENT_ARCHIVED_META = 'drp_archived_at';

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_init', [ $this, 'ensure_scheduled_event' ] );
        add_action( 'admin_init', [ $this, 'handle_admin_actions' ] );
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_notices', [ $this, 'render_admin_notices' ] );
        add_action( self::CRON_HOOK, [ $this, 'execute_policies' ] );
        add_action( 'init', [ $this, 'register_post_status' ] );
        add_filter( 'authenticate', [ $this, 'block_disabled_user_login' ], 30, 3 );
        add_action( 'wp_login', [ $this, 'record_user_login' ], 10, 2 );
        add_action( 'user_register', [ $this, 'record_new_user' ], 10, 1 );
        add_action( 'deleted_user', [ $this, 'cleanup_user_meta' ], 10, 1 );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );
        add_filter( 'manage_users_columns', [ $this, 'register_user_columns' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_user_column' ], 10, 3 );
        add_filter( 'user_row_actions', [ $this, 'register_user_actions' ], 10, 2 );

        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( __FILE__, [ __CLASS__, 'deactivate' ] );
    }

    public static function activate() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'data-retention-policy', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    public function ensure_scheduled_event() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public function handle_admin_actions() {
        if ( ! is_admin() || ! current_user_can( 'promote_users' ) ) {
            return;
        }

        if ( empty( $_GET['drp_action'] ) || 'reenable_user' !== $_GET['drp_action'] ) {
            return;
        }

        $user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
        if ( ! $user_id || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'drp-reenable-user-' . $user_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'data-retention-policy' ) );
        }

        delete_user_meta( $user_id, self::USER_DISABLED_META );

        $redirect_url = remove_query_arg( [ 'drp_action', '_wpnonce' ] );
        $redirect_url = add_query_arg( 'drp_notice', 'user_reenabled', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    public function render_admin_notices() {
        if ( empty( $_GET['drp_notice'] ) ) {
            return;
        }

        $message = '';
        switch ( sanitize_key( wp_unslash( $_GET['drp_notice'] ) ) ) {
            case 'user_reenabled':
                $message = __( 'User account has been re-enabled.', 'data-retention-policy' );
                break;
        }

        if ( $message ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
        }
    }

    public function record_user_login( $user_login, $user ) {
        if ( $user instanceof WP_User ) {
            update_user_meta( $user->ID, self::USER_LAST_ACTIVE_META, time() );
            delete_user_meta( $user->ID, self::USER_DISABLED_META );
        }
    }

    public function record_new_user( $user_id ) {
        update_user_meta( $user_id, self::USER_LAST_ACTIVE_META, time() );
    }

    public function cleanup_user_meta( $user_id ) {
        delete_user_meta( $user_id, self::USER_LAST_ACTIVE_META );
        delete_user_meta( $user_id, self::USER_DISABLED_META );
    }

    public function add_settings_link( $links ) {
        $url      = admin_url( 'options-general.php?page=drp-settings' );
        $links [] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'data-retention-policy' ) . '</a>';

        return $links;
    }

    public function register_user_columns( $columns ) {
        $columns['drp_status']     = __( 'Retention Status', 'data-retention-policy' );
        $columns['drp_last_active'] = __( 'Last Active', 'data-retention-policy' );

        return $columns;
    }

    public function render_user_column( $output, $column_name, $user_id ) {
        if ( 'drp_status' === $column_name ) {
            $disabled_at = get_user_meta( $user_id, self::USER_DISABLED_META, true );
            if ( $disabled_at ) {
                $output = esc_html__( 'Disabled', 'data-retention-policy' );
            } else {
                $output = esc_html__( 'Active', 'data-retention-policy' );
            }
        }

        if ( 'drp_last_active' === $column_name ) {
            $last_active = absint( get_user_meta( $user_id, self::USER_LAST_ACTIVE_META, true ) );
            if ( $last_active ) {
                $output = esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_active ) );
            } else {
                $output = '&mdash;';
            }
        }

        return $output;
    }

    public function register_user_actions( $actions, $user_object ) {
        if ( ! current_user_can( 'promote_users' ) ) {
            return $actions;
        }

        $disabled_at = get_user_meta( $user_object->ID, self::USER_DISABLED_META, true );
        if ( ! $disabled_at ) {
            return $actions;
        }

        $url = add_query_arg(
            [
                'drp_action' => 'reenable_user',
                'user'       => $user_object->ID,
                '_wpnonce'   => wp_create_nonce( 'drp-reenable-user-' . $user_object->ID ),
            ],
            admin_url( 'users.php' )
        );

        $actions['drp_reenable'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Re-enable', 'data-retention-policy' ) . '</a>';

        return $actions;
    }

    public function register_post_status() {
        register_post_status( 'archived', [
            'label'                     => _x( 'Archived', 'post status', 'data-retention-policy' ),
            'public'                    => false,
            'internal'                  => true,
            'protected'                 => true,
            'publicly_queryable'        => false,
            'exclude_from_search'       => true,
            'show_in_rest'              => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Archived <span class="count">(%s)</span>', 'Archived <span class="count">(%s)</span>' ),
        ] );
    }

    public function register_settings() {
        register_setting( 'drp_settings_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

        add_settings_section(
            'drp_user_section',
            __( 'User Retention', 'data-retention-policy' ),
            function () {
                esc_html_e( 'Configure how long inactive users remain active before being disabled and subsequently deleted.', 'data-retention-policy' );
            },
            'drp_settings_page'
        );

        add_settings_section(
            'drp_content_section',
            __( 'Content Retention', 'data-retention-policy' ),
            function () {
                esc_html_e( 'Configure how long posts and pages remain published before being archived.', 'data-retention-policy' );
            },
            'drp_settings_page'
        );

        add_settings_field( 'user_disable', __( 'Disable users after', 'data-retention-policy' ), [ $this, 'render_duration_field' ], 'drp_settings_page', 'drp_user_section', [
            'name'    => 'user_disable',
            'description' => __( 'Disabled users cannot log in. Leave blank or zero to skip.', 'data-retention-policy' ),
        ] );

        add_settings_field( 'user_delete', __( 'Delete users after', 'data-retention-policy' ), [ $this, 'render_duration_field' ], 'drp_settings_page', 'drp_user_section', [
            'name'    => 'user_delete',
            'description' => __( 'Users are deleted this long after they are disabled. Leave blank or zero to skip.', 'data-retention-policy' ),
        ] );

        add_settings_field( 'post_archive', __( 'Archive posts after', 'data-retention-policy' ), [ $this, 'render_duration_field' ], 'drp_settings_page', 'drp_content_section', [
            'name'        => 'post_archive',
            'description' => __( 'Archived posts remain accessible to administrators but are removed from public listings.', 'data-retention-policy' ),
        ] );

        add_settings_field( 'page_archive', __( 'Archive pages after', 'data-retention-policy' ), [ $this, 'render_duration_field' ], 'drp_settings_page', 'drp_content_section', [
            'name'        => 'page_archive',
            'description' => __( 'Archived pages retain their content but are hidden from the front end.', 'data-retention-policy' ),
        ] );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'Data Retention Policy', 'data-retention-policy' ),
            __( 'Data Retention', 'data-retention-policy' ),
            'manage_options',
            'drp-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Data Retention Policy', 'data-retention-policy' ); ?></h1>
            <?php settings_errors( self::OPTION_KEY ); ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'drp_settings_group' );
                do_settings_sections( 'drp_settings_page' );
                submit_button();
                ?>
            </form>
            <p class="description">
                <?php esc_html_e( 'Retention periods are measured from the last recorded user activity and the original publish date for content.', 'data-retention-policy' ); ?>
            </p>
        </div>
        <?php
    }

    public function render_duration_field( $args ) {
        $settings = $this->get_settings();
        $name     = $args['name'];
        $value    = isset( $settings[ $name ] ) ? $settings[ $name ] : [ 'quantity' => '', 'unit' => 'days' ];
        $quantity = isset( $value['quantity'] ) ? absint( $value['quantity'] ) : '';
        $unit     = isset( $value['unit'] ) ? sanitize_key( $value['unit'] ) : 'days';
        ?>
        <fieldset>
            <label>
                <input type="number" min="0" step="1" name="<?php echo esc_attr( self::OPTION_KEY . "[$name][quantity]" ); ?>" value="<?php echo esc_attr( $quantity ); ?>" />
            </label>
            <label>
                <select name="<?php echo esc_attr( self::OPTION_KEY . "[$name][unit]" ); ?>">
                    <?php foreach ( $this->get_units() as $unit_key => $label ) : ?>
                        <option value="<?php echo esc_attr( $unit_key ); ?>" <?php selected( $unit, $unit_key ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ( ! empty( $args['description'] ) ) : ?>
                <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public function sanitize_settings( $input ) {
        $output   = [];
        $defaults = $this->get_default_settings();

        foreach ( $defaults as $key => $default_value ) {
            $quantity = 0;
            $unit     = $default_value['unit'];

            if ( isset( $input[ $key ]['quantity'] ) ) {
                $quantity = max( 0, absint( $input[ $key ]['quantity'] ) );
            }

            if ( isset( $input[ $key ]['unit'] ) ) {
                $candidate_unit = sanitize_key( $input[ $key ]['unit'] );
                if ( array_key_exists( $candidate_unit, $this->get_units() ) ) {
                    $unit = $candidate_unit;
                }
            }

            $output[ $key ] = [
                'quantity' => $quantity,
                'unit'     => $unit,
            ];
        }

        if ( ! $this->has_duration( $output['user_disable'] ) && $this->has_duration( $output['user_delete'] ) ) {
            $output['user_delete']['quantity'] = 0;
            add_settings_error( self::OPTION_KEY, 'drp_user_delete_without_disable', __( 'Users must be disabled before they can be deleted. The delete policy has been cleared.', 'data-retention-policy' ), 'warning' );
        }

        return $output;
    }

    public function block_disabled_user_login( $user, $username, $password ) {
        if ( $user instanceof WP_User ) {
            $disabled_at = get_user_meta( $user->ID, self::USER_DISABLED_META, true );
            if ( ! empty( $disabled_at ) ) {
                return new WP_Error( 'drp_disabled', __( 'Your account has been disabled due to inactivity.', 'data-retention-policy' ) );
            }
        }

        return $user;
    }

    public function execute_policies() {
        $settings = $this->get_settings();

        if ( $this->has_duration( $settings['user_disable'] ) ) {
            $this->disable_inactive_users( $settings['user_disable'] );
        }

        if ( $this->has_duration( $settings['user_delete'] ) ) {
            $this->delete_disabled_users( $settings['user_delete'] );
        }

        if ( $this->has_duration( $settings['post_archive'] ) ) {
            $this->archive_content( 'post', $settings['post_archive'] );
        }

        if ( $this->has_duration( $settings['page_archive'] ) ) {
            $this->archive_content( 'page', $settings['page_archive'] );
        }
    }

    protected function disable_inactive_users( $duration ) {
        $threshold = $this->get_time_threshold( $duration );
        if ( ! $threshold ) {
            return;
        }
        $batch_size           = $this->get_batch_size( 'disable_users' );
        $excluded_roles       = $this->get_excluded_roles();
        $super_admin_ids      = $this->get_super_admin_ids();
        $common_disabled_meta = [
            'relation' => 'OR',
            [
                'key'     => self::USER_DISABLED_META,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => self::USER_DISABLED_META,
                'value'   => '',
                'compare' => '=',
            ],
        ];
        $date_threshold      = gmdate( 'Y-m-d H:i:s', $threshold );

        $queries = [
            [
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'     => self::USER_LAST_ACTIVE_META,
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key'     => self::USER_LAST_ACTIVE_META,
                        'value'   => $threshold,
                        'compare' => '<=',
                        'type'    => 'NUMERIC',
                    ],
                    $common_disabled_meta,
                ],
            ],
            [
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [
                            'key'     => self::USER_LAST_ACTIVE_META,
                            'compare' => 'NOT EXISTS',
                        ],
                        [
                            'key'     => self::USER_LAST_ACTIVE_META,
                            'value'   => '',
                            'compare' => '=',
                        ],
                    ],
                    $common_disabled_meta,
                ],
                'date_query' => [
                    [
                        'column' => 'user_registered',
                        'before' => $date_threshold,
                    ],
                ],
            ],
        ];

        foreach ( $queries as $args ) {
            $page = 1;

            do {
                $query_args = array_merge(
                    [
                        'fields'  => 'ID',
                        'number'  => $batch_size,
                        'paged'   => $page,
                        'orderby' => 'ID',
                        'order'   => 'ASC',
                    ],
                    $args
                );

                if ( ! empty( $excluded_roles ) ) {
                    $query_args['role__not_in'] = $excluded_roles;
                }

                $query   = new WP_User_Query( $query_args );
                $results = $query->get_results();

                if ( empty( $results ) ) {
                    break;
                }

                $users = array_diff( $results, $super_admin_ids );

                foreach ( $users as $user_id ) {
                    update_user_meta( $user_id, self::USER_DISABLED_META, time() );
                    do_action( 'drp_user_disabled', $user_id );
                }

                $page++;
            } while ( count( $results ) === $batch_size );
        }
    }

    protected function delete_disabled_users( $duration ) {
        $threshold = $this->get_time_threshold( $duration );
        if ( ! $threshold ) {
            return;
        }
        $batch_size      = $this->get_batch_size( 'delete_users' );
        $excluded_roles  = $this->get_excluded_roles();
        $super_admin_ids = $this->get_super_admin_ids();
        $page            = 1;

        if ( $batch_size <= 0 ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';

        do {
            $query_args = [
                'fields'     => 'ID',
                'number'     => $batch_size,
                'paged'      => $page,
                'orderby'    => 'ID',
                'order'      => 'ASC',
                'meta_query' => [
                    [
                        'key'     => self::USER_DISABLED_META,
                        'value'   => $threshold,
                        'compare' => '<=',
                        'type'    => 'NUMERIC',
                    ],
                ],
            ];

            if ( ! empty( $excluded_roles ) ) {
                $query_args['role__not_in'] = $excluded_roles;
            }

            $query   = new WP_User_Query( $query_args );
            $results = $query->get_results();
            $users   = array_diff( $results, $super_admin_ids );

            foreach ( $users as $user_id ) {
                wp_delete_user( $user_id );
                do_action( 'drp_user_deleted', $user_id );
            }

            $page++;
        } while ( count( $results ) === $batch_size );
    }

    protected function archive_content( $post_type, $duration ) {
        $threshold = $this->get_time_threshold( $duration );
        if ( ! $threshold ) {
            return;
        }

        $batch_size = $this->get_batch_size( 'archive_' . $post_type );
        $page       = 1;

        do {
            $query = new WP_Query( [
                'post_type'              => $post_type,
                'post_status'            => [ 'publish' ],
                'date_query'             => [
                    [
                        'column' => 'post_date_gmt',
                        'before' => gmdate( 'Y-m-d H:i:s', $threshold ),
                    ],
                ],
                'fields'                 => 'ids',
                'posts_per_page'         => $batch_size,
                'paged'                  => $page,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => true,
                'suppress_filters'       => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ] );

            $post_ids = $query->posts;

            foreach ( $post_ids as $post_id ) {
                wp_update_post( [
                    'ID'          => $post_id,
                    'post_status' => 'archived',
                ] );

                update_post_meta( $post_id, self::CONTENT_ARCHIVED_META, time() );
                do_action( 'drp_content_archived', $post_id, $post_type );
            }

            $page++;
        } while ( count( $post_ids ) === $batch_size );
    }

    protected function has_duration( $duration ) {
        return ! empty( $duration['quantity'] ) && absint( $duration['quantity'] ) > 0;
    }

    protected function get_time_threshold( $duration ) {
        $seconds = $this->convert_to_seconds( $duration );
        if ( ! $seconds ) {
            return false;
        }

        return time() - $seconds;
    }

    protected function convert_to_seconds( $duration ) {
        $quantity = isset( $duration['quantity'] ) ? absint( $duration['quantity'] ) : 0;
        $unit     = isset( $duration['unit'] ) ? sanitize_key( $duration['unit'] ) : 'days';
        if ( $quantity <= 0 ) {
            return 0;
        }

        switch ( $unit ) {
            case 'days':
                $factor = DAY_IN_SECONDS;
                break;
            case 'months':
                $factor = DAY_IN_SECONDS * 30;
                break;
            case 'years':
                $factor = DAY_IN_SECONDS * 365;
                break;
            default:
                $factor = DAY_IN_SECONDS;
                break;
        }

        return $quantity * $factor;
    }

    protected function get_units() {
        return [
            'days'   => __( 'Days', 'data-retention-policy' ),
            'months' => __( 'Months (30 days)', 'data-retention-policy' ),
            'years'  => __( 'Years (365 days)', 'data-retention-policy' ),
        ];
    }

    protected function get_default_settings() {
        return [
            'user_disable' => [ 'quantity' => 0, 'unit' => 'days' ],
            'user_delete'  => [ 'quantity' => 0, 'unit' => 'days' ],
            'post_archive' => [ 'quantity' => 0, 'unit' => 'days' ],
            'page_archive' => [ 'quantity' => 0, 'unit' => 'days' ],
        ];
    }

    protected function get_settings() {
        $saved    = get_option( self::OPTION_KEY, [] );
        $defaults = $this->get_default_settings();

        return wp_parse_args( $saved, $defaults );
    }

    protected function get_excluded_roles() {
        $roles = [ 'administrator' ];

        /**
         * Filter the roles that should be excluded from retention processing.
         *
         * @since 1.1.0
         *
         * @param string[] $roles Role slugs to exclude.
         */
        $roles = apply_filters( 'drp_excluded_roles', $roles );

        return array_filter( array_map( 'sanitize_key', (array) $roles ) );
    }

    protected function get_super_admin_ids() {
        if ( ! is_multisite() ) {
            return [];
        }

        $ids = [];
        foreach ( get_super_admins() as $login ) {
            $user = get_user_by( 'login', $login );
            if ( $user ) {
                $ids[] = $user->ID;
            }
        }

        return $ids;
    }

    protected function get_batch_size( $context ) {
        $default = 100;

        if ( 'delete_users' === $context ) {
            $default = 50;
        }

        /**
         * Filter the batch size used for retention processing queries.
         *
         * @since 1.1.0
         *
         * @param int    $default Default batch size.
         * @param string $context Processing context (e.g. disable_users, delete_users, archive_post).
         */
        $size = (int) apply_filters( 'drp_batch_size', $default, $context );

        return max( 1, $size );
    }
}

new DRP_Manager();
