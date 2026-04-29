<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Kuwej_Loyalty_Enterprise {
    const META_POINTS      = '_kuwej_loyalty_points';
    const META_LOG         = '_kuwej_loyalty_history';
    const META_LAST_ACTIVE = '_kuwej_loyalty_last_active';
    const META_PROCESSED   = '_kuwej_points_processed';
    const META_REFERRER    = '_kuwej_referrer_id';
    const EXPIRATION_DAYS  = 90;
    const COLOR_PRIMARY    = '#0746C0';
    const COLOR_GOLD       = '#d4af37';

    public function __construct() {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_fidelite_endpoint', [ $this, 'render_page' ] );
        add_action( 'woocommerce_order_status_completed', [ $this, 'add_points' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'add_points' ] );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'revoke_points' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'revoke_points' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'wp_head', [ $this, 'styles' ] );
        add_action( 'init', [ $this, 'detect_ref' ] );
        add_action( 'user_register', [ $this, 'bind_referrer' ] );
        add_action( 'user_register', [ $this, 'welcome_bonus' ] );
    }

    // --- POINTS ---
    private function get_points( $uid ) {
        $pts = (int) get_user_meta( $uid, self::META_POINTS, true );
        $last = (int) get_user_meta( $uid, self::META_LAST_ACTIVE, true );
        if ( $last && ( time() - $last ) > ( self::EXPIRATION_DAYS * DAY_IN_SECONDS ) ) {
            update_user_meta( $uid, self::META_POINTS, 0 );
            return 0;
        }
        return $pts;
    }

    private function add_points_internal( $uid, $amount, $reason ) {
        $new = $this->get_points( $uid ) + $amount;
        update_user_meta( $uid, self::META_POINTS, $new );
        update_user_meta( $uid, self::META_LAST_ACTIVE, time() );
        $logs = (array) get_user_meta( $uid, self::META_LOG, true );
        $logs[] = [ 'date' => current_time('Y-m-d H:i'), 'action' => "+$amount pts", 'ref' => $reason ];
        if ( count($logs) > 20 ) $logs = array_slice( $logs, -20 );
        update_user_meta( $uid, self::META_LOG, $logs );
        $this->send_email( $uid, $amount, $reason );
    }

    private function send_email( $uid, $amount, $reason ) {
        $user = get_userdata( $uid );
        if ( ! $user ) return;
        $subject = sprintf( '[%s] Mise a jour fidelite', get_bloginfo('name') );
        $body = "<p>Bonjour {$user->display_name},</p><p><strong>+{$amount} point(s)</strong> credites. Motif : $reason</p>";
        $body .= '<p><a href="' . esc_url( wc_get_account_endpoint_url('fidelite') ) . '">Voir mon solde</a></p>';
        wp_mail( $user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
    }

    // --- HOOKS WC ---
    public function add_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( self::META_PROCESSED ) === 'yes' ) return;
        $uid = $order->get_user_id();
        if ( ! $uid ) return;
        $order->update_meta_data( self::META_PROCESSED, 'yes' );
        $order->save();
        $this->add_points_internal( $uid, 25, 'Commande #' . $order_id );
        $ref = get_user_meta( $uid, self::META_REFERRER, true );
        if ( $ref ) $this->add_points_internal( (int)$ref, 10, 'Parrainage filleul #' . $uid );
    }

    public function revoke_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( self::META_PROCESSED ) !== 'yes' ) return;
        $uid = $order->get_user_id();
        if ( ! $uid ) return;
        $pts = $this->get_points( $uid );
        if ( $pts > 0 ) update_user_meta( $uid, self::META_POINTS, $pts - 1 );
        $order->delete_meta_data( self::META_PROCESSED );
        $order->save();
    }

    // --- ENDPOINT ---
    public function add_endpoint() { add_rewrite_endpoint( 'fidelite', EP_ROOT | EP_PAGES ); }
    public function query_vars( $v ) { $v[] = 'fidelite'; return $v; }
    public function add_menu_item( $items ) {
        $new = [];
        foreach ( $items as $k => $v ) {
            if ( $k === 'customer-logout' ) $new['fidelite'] = 'Carte de fidelite';
            $new[$k] = $v;
        }
        return $new;
    }

    // --- RENDER ---
    public function render_page() {
        $uid    = get_current_user_id();
        $pts    = $this->get_points( $uid );
        $target = (int) get_option( 'kuwej_target_count', 4 );
        $card   = $pts % $target;
        $ref    = home_url( '/?ref=' . $uid );
        echo '<div class="kuwej-wrap">';
        echo '<h2>Ma Carte de Fidelite</h2>';
        echo $this->card_html( $card, $target );
        echo '<div class="kuwej-ref-box">';
        echo '<h4>Parrainez et gagnez +10 pts</h4>';
        echo '<input type="text" value="' . esc_url($ref) . '" readonly onclick="this.select();" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;">';
        echo '</div>';
        $logs = (array) get_user_meta( $uid, self::META_LOG, true );
        if ( $logs ) {
            echo '<h4 style="margin-top:20px;">Historique</h4><ul>';
            foreach ( array_reverse( array_slice($logs,-5) ) as $l ) {
                echo '<li>' . esc_html($l['date']) . ' : <strong>' . esc_html($l['action']) . '</strong> (' . esc_html($l['ref']) . ')</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    private function card_html( $pts, $target ) {
        $out = '<div class="kuwej-card"><div class="kc-inner"><div class="kc-brand">KUWEJ PREMIUM</div><div class="kc-dots">';
        for ( $i = 1; $i <= $target; $i++ ) {
            $cls = $i <= $pts ? 'active' : '';
            $cls .= $i === $target ? ' gift' : '';
            $lbl = $i === $target ? 'G' : ( $i <= $pts ? 'V' : $i );
            $out .= '<div class="dot ' . $cls . '">' . $lbl . '</div>';
        }
        $out .= '</div></div></div>';
        return $out;
    }

    // --- PARRAINAGE ---
    public function detect_ref() {
        if ( isset($_GET['ref']) && ! is_user_logged_in() ) {
            setcookie( 'kuwej_ref', intval($_GET['ref']), time() + 7*DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        }
    }
    public function bind_referrer( $uid ) {
        if ( isset($_COOKIE['kuwej_ref']) ) {
            $ref = intval($_COOKIE['kuwej_ref']);
            if ( $ref !== $uid ) update_user_meta( $uid, self::META_REFERRER, $ref );
        }
    }
    public function welcome_bonus( $uid ) {
        if ( get_option('kuwej_welcome_bonus') === 'yes' ) $this->add_points_internal( $uid, 1, 'Bonus bienvenue' );
    }

    // --- ADMIN ---
    public function admin_menu() {
        add_menu_page( 'Fidelite', 'Fidelite', 'manage_options', 'kuwej-loyalty', [ $this, 'admin_page' ], 'dashicons-awards', 56 );
    }
    public function admin_page() {
        global $wpdb;
        $users = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, meta_value as pts FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value>0 ORDER BY meta_value+0 DESC LIMIT 30",
            self::META_POINTS
        ) );
        echo '<div class="wrap"><h1>Kuwej Loyalty Dashboard</h1>';
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Client</th><th>Points</th></tr></thead><tbody>';
        foreach ( $users as $u ) {
            $info = get_userdata( $u->user_id );
            if ( ! $info ) continue;
            echo '<tr><td>' . esc_html($info->display_name) . ' (' . esc_html($info->user_email) . ')</td><td>' . (int)$u->pts . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    // --- STYLES ---
    public function styles() {
        echo '<style>
        .kuwej-card{background:linear-gradient(135deg,#0746C0,#042a75);border-radius:16px;padding:30px;color:#fff;max-width:380px;margin:20px auto;box-shadow:0 15px 35px rgba(0,0,0,.2);}
        .kc-brand{font-weight:900;font-size:20px;letter-spacing:2px;margin-bottom:20px;}
        .kc-dots{display:flex;justify-content:space-between;}
        .dot{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;}
        .dot.active{background:#fff;color:#0746C0;border-color:#fff;}
        .dot.gift{border-color:#d4af37;color:#d4af37;}
        .dot.gift.active{background:#d4af37;color:#000;}
        .kuwej-ref-box{margin-top:20px;padding:20px;background:#f9f9f9;border-radius:10px;}
        .kuwej-ref-box h4{margin-top:0;}
        </style>';
    }
}
