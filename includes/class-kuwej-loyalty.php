<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Kuwej_Loyalty_Enterprise {

    // --- CONFIGURATION & CONSTANTES ---
    const STORE_NAMESPACE  = 'kuwejLoyalty';
    const META_POINTS      = '_kuwej_loyalty_points';
    const META_LOG         = '_kuwej_loyalty_history';
    const META_LAST_ACTIVE = '_kuwej_loyalty_last_active';
    const META_PROCESSED   = '_kuwej_points_processed';
    const META_REFERRER    = '_kuwej_referrer_id';
    const META_BIRTHDATE   = 'billing_birth_date';
    const META_REVIEW_COUNT = '_kuwej_review_counter';
    const EXPIRATION_DAYS  = 90;

    const COLOR_BG_START = '#0746C0';
    const COLOR_BG_END   = '#042a75';
    const COLOR_GOLD     = '#d4af37';

    public function __construct() {
        // Init & Core
        add_action( 'init', [ $this, 'init_plugin' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Endpoints My Account
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_loyalty_menu_item' ] );
        add_action( 'woocommerce_account_fidelite_endpoint', [ $this, 'render_loyalty_page' ] );

        // Logic (Commandes)
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order_points' ] );
        add_action( 'woocommerce_order_status_processing', [ $this, 'process_order_points' ] );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'revoke_order_points' ] );

        // Frontend & UI
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_head', [ $this, 'inject_styles' ] );
        add_action( 'wp_footer', [ $this, 'render_toast_notification' ] );

        // Marketing Features
        add_action( 'init', [ $this, 'handle_referral_cookie' ] );
        add_action( 'user_register', [ $this, 'process_new_user' ] );
        
        // Anniversaire (Cron Daily)
        if ( ! wp_next_scheduled( 'kuwej_loyalty_birthday_check' ) ) {
            wp_schedule_event( time(), 'daily', 'kuwej_loyalty_birthday_check' );
        }
        add_action( 'kuwej_loyalty_birthday_check', [ $this, 'run_birthday_rewards' ] );

        // Avis Produits
        add_action( 'comment_post', [ $this, 'handle_product_review' ], 10, 2 );
    }

    public function init_plugin() {
        add_rewrite_endpoint( 'fidelite', EP_ROOT | EP_PAGES );

        // Initialisation de l'Interactivity API
        if ( function_exists( 'wp_interactivity_state' ) ) {
            wp_interactivity_state( self::STORE_NAMESPACE, [
                'isCopied' => false,
                'points'   => is_user_logged_in() ? $this->get_points( get_current_user_id() ) : 0
            ]);
        }
    }

    // --- LOGIQUE DE POINTS ---
    private function get_points( $user_id ) {
        $pts  = (int) get_user_meta( $user_id, self::META_POINTS, true );
        $last = (int) get_user_meta( $user_id, self::META_LAST_ACTIVE, true );
        
        if ( $last && ( time() - $last ) > ( self::EXPIRATION_DAYS * DAY_IN_SECONDS ) ) {
            if ( $pts > 0 ) {
                update_user_meta( $user_id, self::META_POINTS, 0 );
                $this->log_activity( $user_id, 'Expiration', 'Inactivité > 90 jours' );
            }
            return 0;
        }
        return $pts;
    }

    private function add_points_internal( $user_id, $amount, $reason ) {
        $current = $this->get_points( $user_id );
        $new     = $current + $amount;
        update_user_meta( $user_id, self::META_POINTS, $new );
        update_user_meta( $user_id, self::META_LAST_ACTIVE, time() );
        $this->log_activity( $user_id, "+$amount pts", $reason );
        $this->send_notification( $user_id, $amount, $reason );
    }

    private function log_activity( $user_id, $action, $ref ) {
        $logs = (array) get_user_meta( $user_id, self::META_LOG, true );
        array_unshift( $logs, [ 'date' => current_time('Y-m-d H:i'), 'action' => $action, 'ref' => $ref ] );
        if ( count($logs) > 20 ) $logs = array_slice($logs, 0, 20);
        update_user_meta( $user_id, self::META_LOG, $logs );
    }

    private function send_notification( $user_id, $amount, $reason ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        $subject = sprintf( '[%s] Mise à jour de votre fidélité', get_bloginfo('name') );
        $body = "<h2>Bonjour {$user->display_name} !</h2>";
        $body .= "<p>Votre compte fidélité a été crédité de <strong>{$amount} points</strong>.</p>";
        $body .= "<p><em>Motif : $reason</em></p>";
        $body .= '<p><a href="' . esc_url( wc_get_account_endpoint_url('fidelite') ) . '" style="background:#0746C0; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px;">Voir ma carte de fidélité</a></p>';
        wp_mail( $user->user_email, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );
    }

    // --- HOOKS COMMANDE ---
    public function process_order_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( self::META_PROCESSED ) === 'yes' ) return;
        
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        $order->update_meta_data( self::META_PROCESSED, 'yes' );
        $order->save();

        // Calcul Happy Hour (Double points)
        $pts = 25;
        $hh  = get_option( 'kuwej_happy_hour', [] );
        if ( ! empty($hh['enabled']) && $hh['enabled'] === 'yes' ) {
            $now = current_time('timestamp');
            if ( $now >= strtotime($hh['start']) && $now <= strtotime($hh['end']) ) {
                $pts = 50;
            }
        }

        $this->add_points_internal( $user_id, $pts, "Commande #$order_id" );

        // Récompense Parrain
        $ref_id = get_user_meta( $user_id, self::META_REFERRER, true );
        if ( $ref_id ) {
            $this->add_points_internal( (int)$ref_id, 10, "Parrainage réussi (Client #$user_id)" );
        }
    }

    public function revoke_order_points( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_meta( self::META_PROCESSED ) !== 'yes' ) return;
        
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        $pts = $this->get_points( $user_id );
        if ( $pts > 0 ) {
            update_user_meta( $user_id, self::META_POINTS, max( 0, $pts - 25 ) );
            $order->delete_meta_data( self::META_PROCESSED );
            $order->save();
            $this->log_activity( $user_id, '-25 pts', "Remboursement #$order_id" );
        }
    }

    // --- MARKETING FEATURES ---
    public function handle_referral_cookie() {
        if ( isset($_GET['ref']) && ! is_user_logged_in() ) {
            setcookie( 'kuwej_ref', intval($_GET['ref']), time() + 7*DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );
        }
    }

    public function process_new_user( $user_id ) {
        // Lien parrain
        if ( isset($_COOKIE['kuwej_ref']) ) {
            $ref = intval($_COOKIE['kuwej_ref']);
            if ( $ref !== $user_id ) update_user_meta( $user_id, self::META_REFERRER, $ref );
        }
        // Bonus bienvenue
        if ( get_option('kuwej_welcome_bonus') === 'yes' ) {
            $this->add_points_internal( $user_id, 1, 'Bonus Bienvenue' );
        }
    }

    public function run_birthday_rewards() {
        global $wpdb;
        $today = date('m-d');
        // Sécurité SQL Senior : prepare
        $users = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            self::META_BIRTHDATE,
            '%-' . $today
        ) );

        if ( $users ) {
            foreach ( $users as $uid ) {
                $this->add_points_internal( (int)$uid, 5, 'Joyeux Anniversaire ! 🎉' );
            }
        }
    }

    public function handle_product_review( $comment_id, $approved ) {
        if ( ! $approved ) return;
        $comment = get_comment( $comment_id );
        if ( ! $comment->user_id || get_post_type( $comment->comment_post_ID ) !== 'product' ) return;

        $uid   = (int)$comment->user_id;
        $count = (int)get_user_meta( $uid, self::META_REVIEW_COUNT, true ) + 1;

        if ( $count >= 3 ) {
            $this->add_points_internal( $uid, 5, 'Récompense 3 avis produits' );
            update_user_meta( $uid, self::META_REVIEW_COUNT, 0 );
        } else {
            update_user_meta( $uid, self::META_REVIEW_COUNT, $count );
        }
    }

    // --- UI & RENDU ---
    public function add_loyalty_menu_item( $items ) {
        $new = [];
        foreach ( $items as $k => $v ) {
            if ( $k === 'customer-logout' ) $new['fidelite'] = 'Ma Carte Fidélité';
            $new[$k] = $v;
        }
        return $new;
    }

    public function render_loyalty_page() {
        $uid    = get_current_user_id();
        $pts    = $this->get_points( $uid );
        $target = (int) get_option( 'kuwej_target_count', 4 );
        $card   = $pts % $target;
        $ref    = home_url( '/?ref=' . $uid );

        echo '<div class="kuwej-loyalty-app" data-wp-interactive="' . esc_attr( self::STORE_NAMESPACE ) . '">';
        echo '<h2>' . esc_html__( 'Ma Carte de Fidélité', 'kuwej-loyalty' ) . '</h2>';
        echo $this->get_card_html( $card, $target );

        // Box Parrainage avec Interactivity API
        ?>
        <div class="referral-box">
            <h4>🎁 Gagnez des points gratuitement</h4>
            <p>Invitez un ami. S'il commande, vous gagnez <strong>+10 points</strong>.</p>
            <div class="copy-wrapper">
                <input type="text" id="ref-link" value="<?php echo esc_url($ref); ?>" readonly style="flex-grow:1;">
                <button data-wp-on--click="actions.copyLink" data-wp-class--success="state.isCopied">
                    <span data-wp-text="state.isCopied ? 'Copié !' : 'Copier'"></span>
                </button>
            </div>
        </div>
        <?php

        // Historique
        $logs = (array) get_user_meta( $uid, self::META_LOG, true );
        if ( $logs ) {
            echo '<div class="history-box"><h4>Historique Récent</h4><ul>';
            foreach ( array_slice($logs, 0, 5) as $l ) {
                echo '<li>' . esc_html($l['date']) . ' : <strong>' . esc_html($l['action']) . '</strong> <small>(' . esc_html($l['ref']) . ')</small></li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';
    }

    private function get_card_html( $pts, $target ) {
        ob_start();
        ?>
        <div class="premium-card">
            <div class="card-header">KUWEJ PREMIUM MEMBER</div>
            <div class="card-dots">
                <?php for ( $i = 1; $i <= $target; $i++ ) : 
                    $active = ($i <= $pts); 
                    $gift   = ($i === $target);
                ?>
                    <div class="dot <?php echo $active ? 'active' : ''; ?> <?php echo $gift ? 'gift' : ''; ?>">
                        <?php echo $gift ? '🎁' : ($active ? '✓' : esc_html($i)); ?>
                    </div>
                <?php endfor; ?>
            </div>
            <div class="card-footer">
                VOTRE PROCHAINE RÉCOMPENSE À <?php echo esc_html($target); ?> POINTS
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_assets() {
        // Enregistrement de la logique Interactivity API
        wp_add_inline_script( 'wp-interactivity', "
            wp.interactivity.store( '" . self::STORE_NAMESPACE . "', {
                actions: {
                    copyLink: ( { state } ) => {
                        const input = document.getElementById('ref-link');
                        input.select();
                        document.execCommand('copy');
                        state.isCopied = true;
                        setTimeout( () => { state.isCopied = false; }, 2000 );
                    }
                }
            });
        " );
    }

    public function inject_styles() {
        ?>
        <style>
            .premium-card { background: linear-gradient(135deg, <?php echo self::COLOR_BG_START; ?>, <?php echo self::COLOR_BG_END; ?>); border-radius: 18px; padding: 30px; color: #fff; box-shadow: 0 20px 40px rgba(0,0,0,0.15); max-width: 400px; margin: 20px auto; position: relative; }
            .card-header { font-size: 14px; letter-spacing: 2px; opacity: 0.8; margin-bottom: 25px; font-weight: 700; }
            .card-dots { display: flex; justify-content: space-between; gap: 10px; }
            .dot { width: 45px; height: 45px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2); display: flex; align-items: center; justify-content: center; font-weight: 800; transition: 0.3s; }
            .dot.active { background: #fff; color: <?php echo self::COLOR_BG_START; ?>; border-color: #fff; transform: scale(1.1); box-shadow: 0 0 15px rgba(255,255,255,0.4); }
            .dot.gift { border-color: <?php echo self::COLOR_GOLD; ?>; color: <?php echo self::COLOR_GOLD; ?>; }
            .dot.gift.active { background: <?php echo self::COLOR_GOLD; ?>; color: #fff; }
            .card-footer { margin-top: 25px; font-size: 11px; text-align: center; font-weight: 600; opacity: 0.7; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 15px; }
            .referral-box { background: #fdfdfd; border: 1px solid #eee; border-radius: 12px; padding: 20px; margin-top: 25px; }
            .copy-wrapper { display: flex; gap: 10px; margin-top: 10px; }
            .copy-wrapper input { border: 1px solid #ddd; border-radius: 6px; padding: 10px; background: #f9f9f9; }
            .copy-wrapper button { background: <?php echo self::COLOR_BG_START; ?>; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; min-width: 100px; transition: 0.3s; }
            .copy-wrapper button.success { background: #27ae60; }
            .history-box { margin-top: 25px; font-size: 14px; }
            .history-box ul { list-style: none; padding: 0; }
            .history-box li { padding: 8px 0; border-bottom: 1px solid #f4f4f4; }
        </style>
        <?php
    }

    public function render_toast_notification() {
        if ( ! is_user_logged_in() || ! is_account_page() ) return;
        // Simple toast CSS animation
        ?>
        <div id="loyalty-toast" style="display:none; position:fixed; bottom:20px; right:20px; background:#fff; padding:15px; border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,0.1); border-left:5px solid <?php echo self::COLOR_BG_START; ?>; z-index: 10000;">
            <strong>✨ Vos points Kuwej</strong><br><small>N'oubliez pas de vérifier vos récompenses !</small>
        </div>
        <script>
            setTimeout(() => { document.getElementById('loyalty-toast').style.display = 'block'; }, 2000);
            setTimeout(() => { document.getElementById('loyalty-toast').style.opacity = '0'; }, 8000);
        </script>
        <?php
    }

    // --- ADMIN ---
    public function add_admin_menu() {
        add_menu_page( 'Fidélité', 'Fidélité', 'manage_options', 'kuwej-loyalty', [ $this, 'render_admin_dashboard' ], 'dashicons-awards', 56 );
    }

    public function register_settings() {
        register_setting( 'kuwej_loyalty_opts', 'kuwej_target_count' );
        register_setting( 'kuwej_loyalty_opts', 'kuwej_welcome_bonus' );
        register_setting( 'kuwej_loyalty_opts', 'kuwej_happy_hour' );
    }

    public function render_admin_dashboard() {
        ?>
        <div class="wrap">
            <h1>Kuwej Loyalty Enterprise <span class="badge" style="background:#0746C0; color:#fff; font-size:12px; padding:3px 8px; border-radius:5px; vertical-align:middle;">v12.0</span></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'kuwej_loyalty_opts' ); ?>
                <table class="form-table">
                    <tr><th>Cible Points (Cadeau)</th><td><input type="number" name="kuwej_target_count" value="<?php echo esc_attr( get_option('kuwej_target_count', 4) ); ?>"></td></tr>
                    <tr><th>Bonus Bienvenue</th><td><input type="checkbox" name="kuwej_welcome_bonus" value="yes" <?php checked( get_option('kuwej_welcome_bonus'), 'yes' ); ?>> Activer</td></tr>
                    <tr>
                        <th>Happy Hour (Points x2)</th>
                        <td>
                            <?php $hh = get_option('kuwej_happy_hour', []); ?>
                            <label><input type="checkbox" name="kuwej_happy_hour[enabled]" value="yes" <?php checked($hh['enabled'] ?? '', 'yes'); ?>> Activer</label><br>
                            Début : <input type="datetime-local" name="kuwej_happy_hour[start]" value="<?php echo esc_attr($hh['start'] ?? ''); ?>"><br>
                            Fin : <input type="datetime-local" name="kuwej_happy_hour[end]" value="<?php echo esc_attr($hh['end'] ?? ''); ?>">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
