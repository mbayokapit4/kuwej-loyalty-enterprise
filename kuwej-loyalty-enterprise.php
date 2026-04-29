<?php
/**
 * Plugin Name: Kuwej Loyalty Enterprise
 * Plugin URI:  https://github.com/mbayokapit4/kuwej-loyalty-enterprise
 * Description: Suite Marketing : Fidélité + Parrainage + Stats + Notifications Admin/Client. (v11.0 Senior Edition).
 * Version:     11.0.1
 * Author:      Kuwej Senior Dev
 * Author URI:  https://kuwej.com
 * Text Domain: kuwej-loyalty
 * Domain Path: /languages
 * License:     GPL-2.0+
 *
 * GitHub Plugin URI: mbayokapit4/kuwej-loyalty-enterprise
 * Primary Branch:    main
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Déclaration de compatibilité HPOS (High Performance Order Storage)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

/**
 * Système d'auto-update GitHub (Natif et discret)
 */
add_filter( 'pre_set_site_transient_update_plugins', 'kuwej_loyalty_core_check_update' );
function kuwej_loyalty_core_check_update( $transient ) {
    if ( empty( $transient->checked ) ) return $transient;
    $plugin_slug = 'kuwej-loyalty-enterprise/kuwej-loyalty-enterprise.php';
    $current_version = $transient->checked[ $plugin_slug ] ?? '0.0.0';
    $cache_key = 'kuwej_loyalty_core_update';
    $cached = get_transient( $cache_key );
    if ( false === $cached ) {
        $response = wp_remote_get( 'https://api.github.com/repos/mbayokapit4/kuwej-loyalty-enterprise/releases/latest', [ 'headers' => [ 'User-Agent' => 'WordPress/' . get_bloginfo('version') ], 'timeout' => 10 ] );
        if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
            $cached = json_decode( wp_remote_retrieve_body( $response ) );
            set_transient( $cache_key, $cached, HOUR_IN_SECONDS * 6 );
        }
    }
    if ( ! empty( $cached->tag_name ) ) {
        $latest_version = ltrim( $cached->tag_name, 'v' );
        if ( version_compare( $latest_version, $current_version, '>' ) ) {
            $transient->response[ $plugin_slug ] = (object) [ 'slug' => 'kuwej-loyalty-enterprise', 'plugin' => $plugin_slug, 'new_version' => $latest_version, 'url' => 'https://github.com/mbayokapit4/kuwej-loyalty-enterprise', 'package' => $cached->zipball_url ];
        }
    }
    return $transient;
}

/**
 * CODE ORIGINAL RESTAURÉ (v11.0)
 */
class Kuwej_Loyalty_Enterprise {

    // --- CONFIGURATION DESIGN ---
    const COLOR_BG_START = '#0746C0'; 
    const COLOR_BG_END   = '#042a75'; 
    const COLOR_TEXT     = '#FFFFFF'; 
    const COLOR_GOLD     = '#d4af37';

    // Clés Meta & Transients
    const META_POINTS       = '_kuwej_loyalty_points';
    const META_LOG          = '_kuwej_loyalty_history';
    const META_PROCESSED    = '_kuwej_points_processed';
    const META_LAST_ACTIVE  = '_kuwej_loyalty_last_active'; 
    const EXPIRATION_DAYS   = 90; 
    
    const META_REFERRER     = '_kuwej_referrer_id';
    const META_BIRTHDATE    = 'billing_birth_date';
    const META_CARDS_COMPLETED = '_kuwej_cards_completed_count';
    const META_REVIEW_COUNT = '_kuwej_review_counter';
    const OPTION_HAPPY_HOUR = 'kuwej_happy_hour_settings';

    public function __construct() {
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'init', [ $this, 'add_loyalty_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'loyalty_query_vars' ], 0 );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_loyalty_tab_link' ] );
        add_action( 'woocommerce_account_fidelite_endpoint', [ $this, 'render_loyalty_tab_content' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_add_points' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'process_add_points' ], 10, 1 ); 
        add_action( 'woocommerce_order_status_refunded', [ $this, 'process_revoke_points' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'process_revoke_points' ], 10, 1 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_cart_rewards' ], 10, 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] ); 
        add_action( 'wp_head', [ $this, 'frontend_styles' ] );
        add_action( 'admin_head', [ $this, 'frontend_styles' ] );
        add_action( 'wp_footer', [ $this, 'render_loyalty_popup' ] );
        add_action( 'admin_post_kuwej_adjust_points', [ $this, 'handle_admin_point_adjustment' ] );
        add_action( 'init', [ $this, 'detect_referral_cookie' ] );
        add_action( 'user_register', [ $this, 'bind_new_user_to_referrer' ] );
        add_action( 'user_register', [ $this, 'give_welcome_bonus' ] );
        add_action( 'woocommerce_edit_account_form', [ $this, 'add_birthday_field' ] );
        add_action( 'woocommerce_save_account_details', [ $this, 'save_birthday_field' ] );
        if ( ! wp_next_scheduled( 'kuwej_daily_birthday_check' ) ) {
            wp_schedule_event( time(), 'daily', 'kuwej_daily_birthday_check' );
        }
        add_action( 'kuwej_daily_birthday_check', [ $this, 'process_birthday_rewards' ] );
        add_action( 'comment_post', [ $this, 'check_review_reward' ], 10, 2 );
        add_action( 'transition_comment_status', [ $this, 'check_review_reward_on_approve' ], 10, 3 );
    }

    private function send_notification_email( $user_id, $type, $args = [] ) {
        $user = get_userdata( $user_id ); if ( ! $user ) return;
        $mailer = WC()->mailer();
        $blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $subjects = [
            'points_add'     => sprintf( __( '[%s] Mise à jour de votre compte', 'kuwej-loyalty' ), $blogname ),
            'points_remove'  => sprintf( __( '[%s] Ajustement de solde', 'kuwej-loyalty' ), $blogname ),
            'vip_reached'    => sprintf( __( '[%s] Statut de compte modifié', 'kuwej-loyalty' ), $blogname ),
            'referral_win'   => sprintf( __( '[%s] Notification de service', 'kuwej-loyalty' ), $blogname ),
            'birthday'       => sprintf( __( '[%s] Un message pour vous', 'kuwej-loyalty' ), $blogname ),
        ];
        $subject = isset( $subjects[$type] ) ? $subjects[$type] : 'Notification Kuwej';
        $header = "Bonjour " . $user->display_name . ",";
        $message_body = "";
        switch ( $type ) {
            case 'points_add':
                $message_body = "<p>Votre solde de fidélité a été crédité suite à votre récente activité.</p>";
                if ( isset( $args['amount'] ) ) $message_body .= "<p><strong>+" . $args['amount'] . " points</strong> ajoutés.</p>";
                if ( isset( $args['reason'] ) ) $message_body .= "<p><em>Motif : " . $args['reason'] . "</em></p>";
                break;
            case 'points_remove':
                $message_body = "<p>Un ajustement a été effectué sur votre solde de points.</p><p><strong>-1 point</strong> retiré (Remboursement ou Annulation).</p>";
                break;
            case 'referral_win':
                $message_body = "<p>Bonne nouvelle ! Une personne que vous avez parrainée vient de passer commande.</p><p>Vous avez reçu des points bonus en récompense.</p>";
                break;
            case 'birthday':
                $message_body = "<p>Toute l'équipe vous souhaite un excellent anniversaire !</p><p>Un petit bonus a été ajouté à votre carte de fidélité.</p>";
                break;
        }
        $link = wc_get_account_endpoint_url( 'fidelite' );
        $message_body .= '<p style="margin-top:20px;"><a href="' . esc_url( $link ) . '" style="background-color:'.self::COLOR_BG_START.'; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;">Voir mon solde actuel</a></p>';
        $mailer->send( $user->user_email, $subject, $mailer->wrap_message( $subject, $header . $message_body ) );
    }

    private function check_and_get_points( $user_id ) {
        if ( ! $user_id ) return 0;
        $points = (int) get_user_meta( $user_id, self::META_POINTS, true );
        $last_active = get_user_meta( $user_id, self::META_LAST_ACTIVE, true );
        if ( empty( $last_active ) || $points === 0 ) {
            if ( $points > 0 && empty( $last_active ) ) update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
            return $points;
        }
        if ( ( time() - $last_active ) / ( 60 * 60 * 24 ) > self::EXPIRATION_DAYS ) {
            update_user_meta( $user_id, self::META_POINTS, 0 );
            update_user_meta( $user_id, self::META_LAST_ACTIVE, time() ); 
            $this->log_transaction( $user_id, 'Expiration Points', 'Inactivité > 90j' );
            return 0;
        }
        return $points;
    }

    private function add_points_internal( $user_id, $amount, $reason, $type_email = 'points_add' ) {
        $current = $this->check_and_get_points( $user_id );
        update_user_meta( $user_id, self::META_POINTS, $current + $amount );
        update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
        $this->log_transaction( $user_id, "+$amount Point(s)", $reason );
        $this->send_notification_email( $user_id, $type_email, ['amount' => $amount, 'reason' => $reason] );
        $target = $this->get_settings()['target'];
        if ( (($current + $amount) % $target) == 0 && $this->get_settings()['reward_type'] == 'coupon' ) {
            $this->create_coupon_reward( $user_id );
        }
    }

    public function detect_referral_cookie() {
        if ( isset( $_GET['ref'] ) && ! is_user_logged_in() ) {
            setcookie( 'kuwej_ref', intval( $_GET['ref'] ), time() + ( 7 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
        }
    }

    public function bind_new_user_to_referrer( $user_id ) {
        if ( isset( $_COOKIE['kuwej_ref'] ) ) {
            $ref_id = intval( $_COOKIE['kuwej_ref'] );
            if ( $ref_id != $user_id ) { 
                update_user_meta( $user_id, self::META_REFERRER, $ref_id );
                $this->log_transaction( $ref_id, 'Parrainage (En attente)', 'Nouveau filleul ID: ' . $user_id );
            }
        }
    }

    public function give_welcome_bonus( $user_id ) {
        if ( get_option( 'kuwej_welcome_bonus' ) === 'yes' ) $this->add_points_internal( $user_id, 1, 'Bonus Bienvenue' );
    }

    public function add_birthday_field() {
        $user = wp_get_current_user(); $birthdate = get_user_meta( $user->ID, self::META_BIRTHDATE, true );
        echo '<p class="woocommerce-form-row form-row-wide"><label for="billing_birth_date">Date de naissance (pour votre cadeau !)</label><input type="date" class="input-text" name="billing_birth_date" id="billing_birth_date" value="' . esc_attr( $birthdate ) . '" /></p>';
    }

    public function save_birthday_field( $user_id ) {
        if ( isset( $_POST['billing_birth_date'] ) ) update_user_meta( $user_id, self::META_BIRTHDATE, sanitize_text_field( $_POST['billing_birth_date'] ) );
    }

    public function process_birthday_rewards() {
        global $wpdb; $today = date( 'm-d' );
        $results = $wpdb->get_col( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '" . self::META_BIRTHDATE . "' AND meta_value LIKE '%-$today'" );
        if ( $results ) foreach ( $results as $uid ) $this->add_points_internal( $uid, 1, 'Joyeux Anniversaire !', 'birthday' );
    }

    public function check_review_reward_on_approve( $new, $old, $comment ) { if ( $new === 'approved' && $old !== 'approved' ) $this->check_review_reward( $comment->comment_ID, $comment->comment_approved ); }
    public function check_review_reward( $id, $status ) {
        if ( $status != 1 ) return; $comment = get_comment( $id ); $uid = $comment->user_id;
        if ( $uid && get_post_type( $comment->comment_post_ID ) === 'product' ) {
            $count = (int) get_user_meta( $uid, self::META_REVIEW_COUNT, true ) + 1;
            if ( $count >= 3 ) { $this->add_points_internal( $uid, 1, 'Récompense Avis (3 avis)' ); update_user_meta( $uid, self::META_REVIEW_COUNT, 0 ); }
            else update_user_meta( $uid, self::META_REVIEW_COUNT, $count );
        }
    }

    public function add_loyalty_endpoint() { add_rewrite_endpoint( 'fidelite', EP_ROOT | EP_PAGES ); }
    public function loyalty_query_vars( $vars ) { $vars[] = 'fidelite'; return $vars; }
    public function add_loyalty_tab_link( $items ) {
        $new = []; foreach ( $items as $k => $v ) { if ( $k === 'customer-logout' ) $new['fidelite'] = 'Carte de fidélité'; $new[$k] = $v; } return $new;
    }

    public function render_loyalty_tab_content() {
        $user = wp_get_current_user(); if ( ! $user->ID ) return;
        $total = $this->check_and_get_points( $user->ID ); $target = $this->get_settings()['target'];
        $current = $total % $target; $is_vip = ((int)get_user_meta($user->ID, self::META_CARDS_COMPLETED, true) >= 3);
        echo '<div class="kuwej-loyalty-container">';
        echo '<div class="kuwej-loyalty-header"><h2>Ma Carte de Fidélité</h2>' . ($is_vip?'<span class="kuwej-vip-badge">🏅 MEMBER GOLD</span>':'') . '</div>';
        echo '<p>Cumulez <strong>' . $target . ' commandes</strong> pour obtenir votre récompense.</p>';
        echo $this->get_card_html( $current, $target, $user->user_email );
        $ref = home_url( '/?ref=' . $user->ID );
        echo '<div style="margin:20px 0; padding:20px; background:white; border:1px solid #eee; border-radius:8px; text-align:center;"><h4>🤝 Gagnez des points gratuitement !</h4><p style="font-size:0.9em;">Invitez un ami. S\'il commande, vous gagnez <strong>+10 points</strong>.</p><input type="text" value="' . esc_url($ref) . '" readonly style="width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; text-align:center; background:#f9f9f9; margin-bottom:10px;" onclick="this.select();"><a href="https://wa.me/?text=' . urlencode("Salut ! Commande sur Kuwej avec ce lien : " . $ref) . '" target="_blank" class="button" style="background:#25D366; color:white; border-color:#25D366;">Partager sur WhatsApp 📲</a></div>';
        echo '<div style="margin-top:20px; padding:20px; background:#f9f9f9; border-radius:8px; border-left:4px solid '.self::COLOR_BG_START.';"><strong>État actuel :</strong> ' . $current . ' / ' . $target . ' commandes validées.</div>';
        $this->render_user_history( $user->ID ); echo '</div>';
    }

    private function render_user_history( $uid ) {
        $logs = get_user_meta( $uid, self::META_LOG, true );
        if ( ! empty( $logs ) && is_array( $logs ) ) {
            echo '<h4 style="margin-top:30px;">Historique Récent</h4><ul style="font-size:0.9em; color:#666; list-style:none; padding:0;">';
            foreach ( array_reverse( array_slice( $logs, -5 ) ) as $l ) echo '<li style="border-bottom:1px solid #eee; padding:5px 0;">' . esc_html( $l['date'] ) . ' : <strong>' . esc_html( $l['action'] ) . '</strong> <small>(' . esc_html( $l['ref'] ) . ')</small></li>';
            echo '</ul>';
        }
    }

    public function process_add_points( $id ) {
        $order = wc_get_order( $id ); if ( ! $order || $order->get_meta( self::META_PROCESSED ) === 'yes' ) return;
        $uid = $order->get_user_id(); if ( $uid ) {
            $order->update_meta_data( self::META_PROCESSED, 'yes' ); $order->save();
            $pts = 25; $hh = get_option( self::OPTION_HAPPY_HOUR );
            if( !empty($hh) && isset($hh['enabled']) && $hh['enabled'] == 'yes' ) {
                $now = current_time('timestamp'); if( $now >= strtotime($hh['start']) && $now <= strtotime($hh['end']) ) $pts = 50;
            }
            $this->add_points_internal( $uid, $pts, 'Commande #' . $id );
            $ref = get_user_meta( $uid, self::META_REFERRER, true );
            if ( $ref ) $this->add_points_internal( $ref, 10, 'Parrainage réussi (Filleul #' . $uid . ')', 'referral_win' );
            $total = $this->check_and_get_points( $uid ); $target = $this->get_settings()['target'];
            if ( ($total % $target) == 0 && $total > 0 ) {
                update_user_meta( $uid, self::META_CARDS_COMPLETED, (int)get_user_meta($uid, self::META_CARDS_COMPLETED, true)+1 );
                $this->send_notification_email( $uid, 'vip_reached' );
            }
        }
    }

    public function process_revoke_points( $id ) {
        $order = wc_get_order( $id ); if ( ! $order || $order->get_meta( self::META_PROCESSED ) !== 'yes' ) return;
        $uid = $order->get_user_id(); if ( $uid ) {
            $curr = (int)get_user_meta( $uid, self::META_POINTS, true );
            if ( $curr > 0 ) { update_user_meta( $uid, self::META_POINTS, $curr - 1 ); $order->delete_meta_data( self::META_PROCESSED ); $order->save(); $this->log_transaction( $uid, '-1 Point', 'Annulation #' . $id ); $this->send_notification_email( $uid, 'points_remove' ); }
        }
    }

    private function log_transaction( $uid, $action, $ref ) {
        $logs = (array)get_user_meta( $uid, self::META_LOG, true ); $logs[] = [ 'date' => current_time( 'Y-m-d H:i' ), 'action' => $action, 'ref' => $ref ];
        update_user_meta( $uid, self::META_LOG, array_slice( $logs, -20 ) );
    }

    private function create_coupon_reward( $uid ) {
        $s = $this->get_settings(); $code = 'KUWEJ-VIP-' . strtoupper( substr( md5( time() . $uid ), 0, 8 ) );
        $c = new WC_Coupon(); $c->set_code( $code ); $c->set_discount_type( 'percent' ); $c->set_amount( $s['coupon_amount'] ); $c->set_individual_use( true ); $c->set_usage_limit( 1 ); $c->set_email_restrictions( [ get_userdata( $uid )->user_email ] ); $c->set_date_expires( time() + ( 60 * 24 * 60 * 60 ) ); $c->save();
        $this->log_transaction( $uid, 'Coupon Généré', $code );
    }

    public function apply_cart_rewards( $cart ) {
        if ( is_admin() || ! is_user_logged_in() ) return;
        $s = $this->get_settings(); if ( $s['reward_type'] !== 'product' ) return;
        $pts = $this->check_and_get_points( get_current_user_id() );
        $eligible = ( $pts === ($s['target'] - 1) );
        foreach ( $cart->get_cart() as $k => $item ) if ( $item['product_id'] == $s['gift_id'] ) { if ( ! $eligible ) $cart->remove_cart_item( $k ); else $item['data']->set_price( 0 ); }
        if ( $eligible && ! array_filter($cart->get_cart(), function($i) use($s){return $i['product_id']==$s['gift_id'];}) ) { $cart->add_to_cart( $s['gift_id'] ); wc_add_notice( '🎁 Cadeau fidélité ajouté !', 'success' ); }
    }

    public function add_admin_menu() { add_menu_page( 'Fidélité', 'Fidélité', 'manage_options', 'kuwej-loyalty', [ $this, 'render_admin_dashboard' ], 'dashicons-awards', 56 ); }
    public function register_settings() { register_setting( 'kuwej_opts', 'kuwej_gift_id' ); register_setting( 'kuwej_opts', 'kuwej_target_count' ); register_setting( 'kuwej_opts', 'kuwej_reward_type' ); register_setting( 'kuwej_opts', 'kuwej_coupon_amount' ); register_setting( 'kuwej_opts', 'kuwej_welcome_bonus' ); register_setting( 'kuwej_opts', self::OPTION_HAPPY_HOUR ); }
    
    public function render_admin_dashboard() {
        $tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'dashboard';
        echo '<div class="wrap"><h1 style="background:'.self::COLOR_BG_START.'; color:white; padding:15px; border-radius:5px;">Kuwej Growth Engine (v11.0)</h1><nav class="nav-tab-wrapper"><a href="?page=kuwej-loyalty&tab=dashboard" class="nav-tab '.($tab=='dashboard'?'nav-tab-active':'').'">Vue d\'ensemble</a><a href="?page=kuwej-loyalty&tab=customers" class="nav-tab '.($tab=='customers'?'nav-tab-active':'').'">Gérer les Clients</a><a href="?page=kuwej-loyalty&tab=settings" class="nav-tab '.($tab=='settings'?'nav-tab-active':'').'">Réglages & Marketing</a></nav><div style="background:white; padding:20px; border:1px solid #ccd0d4; margin-top:15px;">';
        if( $tab == 'dashboard' ) $this->tab_dashboard(); elseif( $tab == 'customers' ) $this->tab_customers(); elseif( $tab == 'settings' ) $this->tab_settings();
        echo '</div></div>';
    }

    private function tab_dashboard() {
        global $wpdb; $active = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value > 0", self::META_POINTS ) );
        echo '<div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:20px;">' . $this->kpi_box( 'Clients Actifs', $active, 'dashicons-groups' ) . '</div>';
    }

    private function tab_customers() {
        global $wpdb; $users = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value as points FROM $wpdb->usermeta WHERE meta_key = %s ORDER BY user_id DESC LIMIT 50", self::META_POINTS ) );
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Client</th><th>Points</th><th>Actions</th></tr></thead><tbody>';
        foreach( $users as $u ) {
            $info = get_userdata( $u->user_id ); if ( ! $info ) continue; $pts = $this->check_and_get_points($u->user_id) % $this->get_settings()['target'];
            echo '<tr><td>' . esc_html($info->display_name) . '</td><td><strong>' . $pts . '</strong></td><td><form method="post" action="admin-post.php"><input type="hidden" name="action" value="kuwej_adjust_points"><input type="hidden" name="user_id" value="' . $u->user_id . '">' . wp_nonce_field( 'adjust_points_' . $u->user_id, '_wpnonce', true, false ) . '<button type="submit" name="adjustment" value="plus" class="button">+</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private function tab_settings() {
        echo '<form method="post" action="options.php">'; settings_fields( 'kuwej_opts' ); echo '<table class="form-table"><tr><th>Cible Achats</th><td><input type="number" name="kuwej_target_count" value="' . esc_attr( get_option( 'kuwej_target_count', 4 ) ) . '"></td></tr></table>'; submit_button(); echo '</form>';
    }

    public function handle_admin_point_adjustment() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Interdit' ); $uid = intval( $_POST['user_id'] ); check_admin_referer( 'adjust_points_' . $uid );
        $curr = (int) get_user_meta( $uid, self::META_POINTS, true ); update_user_meta( $uid, self::META_POINTS, $curr + 1 ); update_user_meta( $uid, self::META_LAST_ACTIVE, time() );
        $this->log_transaction( $uid, '+1 Point', 'Admin' ); wp_redirect( admin_url( 'admin.php?page=kuwej-loyalty&tab=customers' ) ); exit;
    }

    private function kpi_box( $t, $v, $i ) { return "<div style='background:#f9f9f9; padding:20px; border-radius:5px; text-align:center; border:1px solid #e5e5e5;'><div class='dashicons $i' style='font-size:32px; color:".self::COLOR_BG_START.";'></div><div style='font-size:24px; font-weight:bold;'>$v</div><div>$t</div></div>"; }
    private function get_settings() { return [ 'target' => (int) get_option( 'kuwej_target_count', 4 ), 'gift_id' => (int) get_option( 'kuwej_gift_id' ), 'reward_type' => get_option( 'kuwej_reward_type', 'product' ), 'coupon_amount' => get_option( 'kuwej_coupon_amount', 10 ) ]; }

    public function render_loyalty_popup() {
        if ( ! is_user_logged_in() || ! is_account_page() ) return;
        echo '<div id="kuwej-loyalty-toast" style="position:fixed; bottom:20px; right:20px; background:white; padding:15px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.15); border-left:5px solid '.self::COLOR_BG_START.'; z-index:99999;"><h4>🃏 Carte de Fidélité</h4><p>Vérifiez vos récompenses !</p><a href="' . esc_url( wc_get_account_endpoint_url( 'fidelite' ) ) . '">Voir ma carte &rarr;</a></div>';
    }

    public function enqueue_assets() { wp_enqueue_style( 'dashicons' ); }

    private function get_card_html( $points, $target, $email ) {
        $parts = explode('@', $email); $name = strtoupper(substr($parts[0], 0, 18));
        ob_start(); ?>
        <div class="kuwej-card"><div class="card-bg"></div><div class="card-content"><div class="card-row top"><span class="brand">KUWEJ</span><span class="label">MEMBER</span></div><div class="card-row middle"><div class="chip"><span></span><span></span><span></span><span></span></div></div><div class="card-row number-row"><div class="card-number">**** **** **** <?php echo rand(1000, 9999); ?></div><div class="card-name"><?php echo esc_html($name); ?></div></div><div class="card-row bottom-tracker"><?php for($i=1; $i<=$target; $i++): $filled = ($i <= $points); $is_last = ($i == $target); ?><div class="dot <?php echo $filled ? 'filled' : ''; ?> <?php echo $is_last ? 'gift' : ''; ?>"><?php if($is_last): ?>🎁<?php elseif($filled): ?>✅<?php else: ?><?php echo $i; ?><?php endif; ?></div><?php endfor; ?></div></div><div class="shine"></div></div>
        <?php return ob_get_clean();
    }

    public function frontend_styles() {
        $pts_badge = is_user_logged_in() ? $this->check_and_get_points(get_current_user_id()) : 0;
        ?>
        <style>
            .kuwej-loyalty-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
            .kuwej-card { position: relative; width: 100%; max-width: 380px; aspect-ratio: 1.58; border-radius: 16px; margin: 20px auto; box-shadow: 0 15px 35px rgba(0,0,0,0.25); overflow: hidden; font-family: 'Courier New', monospace; color: white; }
            .card-bg { position: absolute; top:0; left:0; right:0; bottom:0; background: linear-gradient(135deg, <?php echo self::COLOR_BG_START; ?>, <?php echo self::COLOR_BG_END; ?>); z-index: 1; }
            .card-content { position: relative; z-index: 2; padding: 25px; height: 100%; display: flex; flex-direction: column; justify-content: space-between; box-sizing: border-box; }
            .brand { font-weight: 900; font-size: 24px; letter-spacing: 2px; }
            .dot { width: 30px; height: 30px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.3); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; }
            .dot.filled { background: #fff; color: <?php echo self::COLOR_BG_START; ?>; border-color: #fff; }
            .dot.gift { border-color: <?php echo self::COLOR_GOLD; ?>; color: <?php echo self::COLOR_GOLD; ?>; }
            .dot.gift.filled { background: <?php echo self::COLOR_GOLD; ?>; color: #000; }
            .woocommerce-MyAccount-navigation-link--fidelite a::before { content: '\f3a1'; font-family: 'dashicons' !important; margin-right: 10px; }
        </style>
        <?php
    }
}

new Kuwej_Loyalty_Enterprise();
