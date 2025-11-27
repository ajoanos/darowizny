<?php
/*
Plugin Name: P24 Dobrowolne Wsparcie
Description: Formularz dowolnej wpłaty przez Przelewy24 (donacje / wsparcie) + log wpłat, statystyki i historia.
Version: 1.3.4
Author: Allemedia / ChatGPT
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class P24_Dobrowolne_Wsparcie {

    const OPTION_KEY      = 'p24_donation_settings';
    const TABLE_NAME      = 'p24_donations';
    const EMAIL_NOTIFY_TO = 'zamowienia@allemedia.pl';

    protected static $form_styles_printed = false;

    public function __construct() {
        // Admin menu + ustawienia
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Shortcode
        add_shortcode( 'p24_donation', [ $this, 'render_donation_form' ] );

        // Obsługa formularza płatności
        add_action( 'admin_post_nopriv_p24_donation_payment', [ $this, 'handle_payment' ] );
        add_action( 'admin_post_p24_donation_payment', [ $this, 'handle_payment' ] );

        // Webhook (urlStatus) z Przelewy24
        add_action( 'admin_post_nopriv_p24_donation_notify', [ $this, 'handle_status_notification' ] );
        add_action( 'admin_post_p24_donation_notify', [ $this, 'handle_status_notification' ] );

        // Bulk delete w historii wpłat
        add_action( 'admin_post_p24_bulk_delete_donations', [ $this, 'handle_bulk_delete_donations' ] );

        // Komunikat „Dziękujemy” po powrocie z P24 + fallback zmiany statusu
        add_filter( 'the_content', [ $this, 'maybe_prepend_thankyou_message' ] );
    }

    /**
     * Tworzenie tabeli w bazie przy aktywacji
     */
    public static function activate() {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            amount INT(11) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'PLN',
            email VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'initiated',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Menu w panelu admina:
     * - P24 Wsparcie (główne)
     *   - Ustawienia
     *   - Historia wpłat
     */
    public function add_admin_menu() {
        $capability = 'manage_options';

        add_menu_page(
            'P24 Wsparcie',
            'P24 Wsparcie',
            $capability,
            'p24-donation-main',
            [ $this, 'settings_page_html' ],
            'dashicons-heart',
            56
        );

        add_submenu_page(
            'p24-donation-main',
            'Ustawienia P24',
            'Ustawienia',
            $capability,
            'p24-donation-main',
            [ $this, 'settings_page_html' ]
        );

        add_submenu_page(
            'p24-donation-main',
            'Historia wpłat',
            'Historia wpłat',
            $capability,
            'p24-donation-history',
            [ $this, 'history_page_html' ]
        );
    }

    /**
     * Rejestracja ustawień
     */
    public function register_settings() {
        register_setting( 'p24_donation_group', self::OPTION_KEY );

        add_settings_section(
            'p24_donation_main',
            'Ustawienia Przelewy24',
            function () {
                echo '<p>Uzupełnij dane z panelu Przelewy24 (Moje dane → API). Plugin korzysta z prostego flow + urlStatus do oznaczania wpłat jako <strong>success</strong>.</p>';
            },
            'p24-donation-main'
        );

        $fields = [
            'merchant_id' => 'Merchant ID',
            'pos_id'      => 'POS ID',
            'crc'         => 'CRC (klucz do sign)',
            'api_key'     => 'API key (REST / reportKey)',
            'sandbox'     => 'Tryb sandbox (testowy)',
            'description' => 'Opis transakcji (np. "Dobrowolne wsparcie twórcy")',
            'min_amount'  => 'Minimalna kwota (PLN, np. 5)',
        ];

        foreach ( $fields as $key => $label ) {
            add_settings_field(
                $key,
                esc_html( $label ),
                [ $this, 'render_setting_field' ],
                'p24-donation-main',
                'p24_donation_main',
                [ 'key' => $key ]
            );
        }
    }

    public function render_setting_field( $args ) {
        $options = get_option( self::OPTION_KEY, [] );
        $key     = $args['key'];
        $value   = isset( $options[ $key ] ) ? $options[ $key ] : '';

        if ( $key === 'sandbox' ) {
            ?>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY . "[{$key}]" ); ?>"
                       value="1" <?php checked( $value, '1' ); ?> />
                Włącz tryb testowy (sandbox)
            </label>
            <?php
        } elseif ( $key === 'description' ) {
            ?>
            <input type="text"
                   class="regular-text"
                   name="<?php echo esc_attr( self::OPTION_KEY . "[{$key}]" ); ?>"
                   value="<?php echo esc_attr( $value ); ?>"
                   placeholder="Dobrowolne wsparcie twórcy" />
            <?php
        } else {
            ?>
            <input type="text"
                   class="regular-text"
                   name="<?php echo esc_attr( self::OPTION_KEY . "[{$key}]" ); ?>"
                   value="<?php echo esc_attr( $value ); ?>" />
            <?php
        }
    }

    /**
     * Strona ustawień
     */
    public function settings_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>P24 Dobrowolne Wsparcie – ustawienia</h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'p24_donation_group' );
                do_settings_sections( 'p24-donation-main' );
                submit_button();
                ?>
            </form>

            <hr>
            <h2>Użycie</h2>
            <p>Wstaw shortcode <code>[p24_donation]</code> w dowolnym miejscu na stronie, gdzie ma być formularz wpłaty.</p>
        </div>
        <?php
    }

    /**
     * Strona Historia wpłat
     */
    public function history_page_html() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Dostępne miesiące
        $months_raw = $wpdb->get_results(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS cnt
             FROM {$table_name}
             GROUP BY ym
             ORDER BY ym DESC",
            ARRAY_A
        );

        $months = [];
        foreach ( $months_raw as $m ) {
            $ym = $m['ym'];
            if ( ! $ym ) {
                continue;
            }
            list( $year, $month ) = array_map( 'intval', explode( '-', $ym ) );
            $timestamp            = mktime( 0, 0, 0, $month, 1, $year );
            $label                = date_i18n( 'F Y', $timestamp );
            $months[ $ym ]        = [
                'label' => $label,
                'cnt'   => (int) $m['cnt'],
            ];
        }

        // Wybrany miesiąc
        $selected_month = isset( $_GET['p24_month'] ) ? sanitize_text_field( $_GET['p24_month'] ) : '';
        if ( $selected_month && $selected_month !== 'all' && ! preg_match( '/^\d{4}-\d{2}$/', $selected_month ) ) {
            $selected_month = '';
        }

        // WHERE dla zapytań
        $where   = '';
        $where_s = '';

        if ( $selected_month && $selected_month !== 'all' ) {
            $where   = $wpdb->prepare(
                "WHERE DATE_FORMAT(created_at,'%Y-%m') = %s",
                $selected_month
            );
            $where_s = $where . ' AND ';
        } else {
            $where   = '';
            $where_s = 'WHERE ';
        }

        // Statystyki
        $total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where}" );
        $success     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where_s}status = 'success'" );
        $initiated   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where_s}status = 'initiated'" );
        $failed      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} {$where_s}status = 'failed'" );
        $success_sum = (int) $wpdb->get_var( "SELECT COALESCE(SUM(amount),0) FROM {$table_name} {$where_s}status = 'success'" );

        $success_sum_pln = number_format( $success_sum / 100, 2, ',', ' ' );

        // Wpłaty
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table_name} {$where} ORDER BY created_at DESC",
            ARRAY_A
        );

        // Grupowanie po miesiącach
        $grouped = [];
        foreach ( $rows as $row ) {
            $ym = date( 'Y-m', strtotime( $row['created_at'] ) );
            if ( ! isset( $grouped[ $ym ] ) ) {
                $grouped[ $ym ] = [];
            }
            $grouped[ $ym ][] = $row;
        }

        // Najnowszy miesiąc
        $first_month_key = '';
        if ( ! empty( $grouped ) ) {
            $keys = array_keys( $grouped );
            rsort( $keys );
            $first_month_key = reset( $keys );
        }

        $action_bulk = esc_url( admin_url( 'admin-post.php' ) );
        ?>
        <div class="wrap">
            <h1>Historia wpłat Przelewy24</h1>

            <h2>Filtr miesiąca</h2>
            <form method="get" action="">
                <input type="hidden" name="page" value="p24-donation-history">
                <select name="p24_month">
                    <option value="all"<?php selected( $selected_month, 'all' ); ?>>Wszystkie miesiące</option>
                    <?php foreach ( $months as $ym => $info ) : ?>
                        <option value="<?php echo esc_attr( $ym ); ?>" <?php selected( $selected_month, $ym ); ?>>
                            <?php echo esc_html( $info['label'] . ' (' . $info['cnt'] . ')' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Pokaż</button>
            </form>

            <hr>
            <h2>Statystyki<?php
                if ( $selected_month && $selected_month !== 'all' ) {
                    echo ' – ' . esc_html( $months[ $selected_month ]['label'] ?? $selected_month );
                }
            ?></h2>
            <p>
                <strong>Łącznie transakcji:</strong> <?php echo esc_html( $total ); ?><br>
                <strong>Success:</strong> <?php echo esc_html( $success ); ?><br>
                <strong>Initiated (bez potwierdzenia):</strong> <?php echo esc_html( $initiated ); ?><br>
                <strong>Failed:</strong> <?php echo esc_html( $failed ); ?><br>
                <strong>Łączna kwota wpłat zakończonych sukcesem:</strong>
                <?php echo esc_html( $success_sum_pln ); ?> PLN
            </p>

            <hr>
            <h2>Wpłaty</h2>

            <?php if ( empty( $rows ) ) : ?>
                <p>Brak wpłat dla wybranego zakresu.</p>
            <?php else : ?>
                <form method="post" action="<?php echo $action_bulk; ?>"
                      onsubmit="return confirm('Na pewno usunąć zaznaczone wpłaty?');">

                    <input type="hidden" name="action" value="p24_bulk_delete_donations">
                    <?php wp_nonce_field( 'p24_bulk_delete_donations', 'p24_bulk_delete_nonce' ); ?>

                    <p>
                        <label>
                            <input type="checkbox" id="p24-select-all">
                            Zaznacz wszystkie
                        </label>
                    </p>

                    <?php foreach ( $grouped as $ym => $group_rows ) : ?>
                        <?php
                        if ( isset( $months[ $ym ] ) ) {
                            $label = $months[ $ym ]['label'];
                        } else {
                            list( $year, $month ) = array_map( 'intval', explode( '-', $ym ) );
                            $timestamp            = mktime( 0, 0, 0, $month, 1, $year );
                            $label                = date_i18n( 'F Y', $timestamp );
                        }

                        $open_attr = '';
                        if ( $selected_month && $selected_month !== 'all' ) {
                            if ( $selected_month === $ym ) {
                                $open_attr = ' open';
                            }
                        } elseif ( $ym === $first_month_key ) {
                            $open_attr = ' open';
                        }
                        ?>
                        <details<?php echo $open_attr; ?> style="margin-bottom: 15px;">
                            <summary style="cursor:pointer; font-weight:600;">
                                <?php echo esc_html( $label . ' (' . count( $group_rows ) . ' wpłat)' ); ?>
                            </summary>
                            <table class="widefat striped" style="margin-top:10px;">
                                <thead>
                                <tr>
                                    <th style="width:30px;">#</th>
                                    <th>Data</th>
                                    <th>Kwota</th>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Session ID</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $group_rows as $row ) : ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="p24-history-checkbox" name="ids[]"
                                                   value="<?php echo (int) $row['id']; ?>">
                                        </td>
                                        <td><?php echo esc_html( $row['created_at'] ); ?></td>
                                        <td><?php echo esc_html( number_format( $row['amount'] / 100, 2, ',', ' ' ) . ' ' . $row['currency'] ); ?></td>
                                        <td><?php echo esc_html( $row['email'] ); ?></td>
                                        <td><?php echo esc_html( $row['status'] ); ?></td>
                                        <td><?php echo esc_html( $row['session_id'] ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </details>
                    <?php endforeach; ?>

                    <p>
                        <button type="submit" class="button button-secondary">
                            Usuń zaznaczone wpłaty
                        </button>
                    </p>
                </form>

                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var master = document.getElementById('p24-select-all');
                        if (!master) return;
                        master.addEventListener('change', function () {
                            var checked = this.checked;
                            document.querySelectorAll('.p24-history-checkbox').forEach(function (cb) {
                                cb.checked = checked;
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Shortcode [p24_donation] – nowoczesny wygląd formularza
     */
    public function render_donation_form( $atts ) {
        $atts = shortcode_atts( [
            'button_label' => 'Wesprzyj',
        ], $atts, 'p24_donation' );

        $action      = esc_url( admin_url( 'admin-post.php' ) );
        $current_url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        ob_start();

        // Jednorazowe style dla formularza
        if ( ! self::$form_styles_printed ) {
            self::$form_styles_printed = true;
            ?>
            <style>
                .p24-donation-card {
                    max-width: 420px;
                    margin: 30px auto;
                    padding: 24px 24px 28px;
                    border-radius: 18px;
                    background: #ffffff;
                    box-shadow: 0 18px 45px rgba(0,0,0,0.08);
                    text-align: center;
                    border: 1px solid rgba(0,0,0,0.03);
                }
                .p24-donation-card .p24-field {
                    margin-bottom: 14px;
                    text-align: center;
                }
                .p24-donation-card label {
                    display: block;
                    font-size: 13px;
                    margin-bottom: 4px;
                    color: #444;
                    font-weight: 500;
                }
                .p24-donation-card .p24-input-wrapper {
                    max-width: 260px;
                    margin: 0 auto;
                }
                .p24-donation-card input[type="number"],
                .p24-donation-card input[type="email"] {
                    width: 100%;
                    padding: 10px 12px;
                    border-radius: 999px;
                    border: 1px solid #d0d0d7;
                    font-size: 14px;
                    outline: none;
                    transition: border-color 0.2s ease, box-shadow 0.2s ease;
                    text-align: center;
                    background: #fafafb;
                    box-sizing: border-box;
                }
                .p24-donation-card input[type="number"]:focus,
                .p24-donation-card input[type="email"]:focus {
                    border-color: #f97316;
                    box-shadow: 0 0 0 2px rgba(249,115,22,0.18);
                    background: #ffffff;
                }
                .p24-donation-card .p24-amount-wrapper {
                    position: relative;
                }
                .p24-donation-card .p24-amount-wrapper span.p24-currency {
                    position: absolute;
                    right: 12px;
                    top: 50%;
                    transform: translateY(-50%);
                    font-size: 12px;
                    color: #999;
                    pointer-events: none;
                }
                .p24-donation-card button.p24-donate-btn {
                    margin-top: 8px;
                    width: 100%;
                    max-width: 260px;
                    padding: 11px 16px;
                    border-radius: 999px;
                    border: none;
                    font-size: 15px;
                    font-weight: 600;
                    cursor: pointer;
                    background: linear-gradient(135deg, #f97316, #fb923c);
                    color: #fff;
                    box-shadow: 0 10px 25px rgba(248,113,22,0.35);
                    transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
                }
                .p24-donation-card button.p24-donate-btn:hover {
                    transform: translateY(-1px);
                    box-shadow: 0 14px 32px rgba(248,113,22,0.45);
                    filter: brightness(1.03);
                }
                .p24-donation-card button.p24-donate-btn:active {
                    transform: translateY(0);
                    box-shadow: 0 8px 18px rgba(248,113,22,0.3);
                }
                .p24-donation-card .p24-secure {
                    margin-top: 14px;
                    font-size: 12px;
                    color: #444;
                    font-weight: 600;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                }
                .p24-donation-card .p24-secure::before {
                    content: "🔒";
                    font-size: 14px;
                }

                /* Styl dla komunikatu "Dziękujemy" */
                .p24-thanks-wrapper {
                    max-width: 520px;
                    margin: 24px auto;
                    padding: 0 16px;
                }
                .p24-thanks-box {
                    padding: 18px 20px;
                    border-radius: 16px;
                    background: linear-gradient(135deg, #ecfff3, #ffffff);
                    border: 1px solid rgba(70,180,80,0.45);
                    box-shadow: 0 10px 28px rgba(22,163,74,0.12);
                    text-align: center;
                    font-size: 15px;
                    line-height: 1.6;
                    color: #14532d;
                }
                .p24-thanks-box-title {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    font-size: 18px;
                    font-weight: 700;
                    margin-bottom: 4px;
                }
                .p24-thanks-box-title span.emoji {
                    font-size: 20px;
                }
                .p24-thanks-box-sub {
                    font-size: 13px;
                    opacity: 0.95;
                }
            </style>
            <?php
        }
        ?>

        <div class="p24-donation-card">
            <form method="post" action="<?php echo $action; ?>" class="p24-donation-form">
                <input type="hidden" name="action" value="p24_donation_payment">
                <?php wp_nonce_field( 'p24_donation_payment', 'p24_donation_nonce' ); ?>
                <input type="hidden" name="redirect_url" value="<?php echo esc_url( $current_url ); ?>">

                <div class="p24-field">
                    <label>Kwota wsparcia</label>
                    <div class="p24-input-wrapper">
                        <div class="p24-amount-wrapper">
                            <input type="number" name="amount" required min="1" step="0.01" placeholder="np. 10"
                                   inputmode="decimal">
                            <span class="p24-currency">PLN</span>
                        </div>
                    </div>
                </div>

                <div class="p24-field">
                    <label>Twój e-mail (do potwierdzenia płatności)</label>
                    <div class="p24-input-wrapper">
                        <input type="email" name="email" required placeholder="twoj@mail.pl">
                    </div>
                </div>

                <button type="submit" class="p24-donate-btn">
                    <?php echo esc_html( $atts['button_label'] ); ?> ❤️
                </button>

                <div class="p24-secure">
                    Płatność obsługiwana bezpiecznie przez Przelewy24
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Obsługa formularza – rejestracja transakcji w P24 i przekierowanie
     */
    public function handle_payment() {
        if ( ! isset( $_POST['p24_donation_nonce'] ) || ! wp_verify_nonce( $_POST['p24_donation_nonce'], 'p24_donation_payment' ) ) {
            wp_die( 'Niepoprawne żądanie (nonce).' );
        }

        $options = get_option( self::OPTION_KEY, [] );

        $merchant_id = isset( $options['merchant_id'] ) ? trim( $options['merchant_id'] ) : '';
        $pos_id      = isset( $options['pos_id'] ) ? trim( $options['pos_id'] ) : '';
        $crc         = isset( $options['crc'] ) ? trim( $options['crc'] ) : '';
        $api_key     = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : '';
        $description = ! empty( $options['description'] ) ? $options['description'] : 'Dobrowolne wsparcie twórcy';
        $sandbox     = ! empty( $options['sandbox'] );
        $min_amount  = isset( $options['min_amount'] ) ? floatval( str_replace( ',', '.', $options['min_amount'] ) ) : 0;

        if ( ! $merchant_id || ! $pos_id || ! $crc || ! $api_key ) {
            wp_die( 'Brak konfiguracji Przelewy24. Uzupełnij ustawienia w panelu.' );
        }

        $amount_raw   = isset( $_POST['amount'] ) ? str_replace( ',', '.', $_POST['amount'] ) : '';
        $email        = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $redirect_url = isset( $_POST['redirect_url'] ) ? esc_url_raw( $_POST['redirect_url'] ) : home_url( '/' );

        $amount = floatval( $amount_raw );

        if ( $amount <= 0 ) {
            wp_die( 'Nieprawidłowa kwota.' );
        }

        if ( $min_amount > 0 && $amount < $min_amount ) {
            wp_die( 'Minimalna kwota wpłaty to ' . esc_html( $min_amount ) . ' PLN.' );
        }

        if ( ! is_email( $email ) ) {
            wp_die( 'Nieprawidłowy adres e-mail.' );
        }

        // Kwota w groszach
        $amount_grosz = (int) round( $amount * 100 );

        // Unikalne sessionId
        $session_id = 'donation_' . wp_generate_uuid4();

        // URL powrotu – dokładnie tam, skąd wyszła płatność
        $return_url = add_query_arg( [
            'p24_donation_status' => 'return',
            'p24_session'         => $session_id,
        ], $redirect_url );

        // URL dla webhooka (urlStatus)
        $status_url = admin_url( 'admin-post.php?action=p24_donation_notify' );

        // Sign wg dokumentacji
        $sign_data = [
            'sessionId'  => $session_id,
            'merchantId' => (int) $merchant_id,
            'amount'     => $amount_grosz,
            'currency'   => 'PLN',
            'crc'        => $crc,
        ];

        $sign = hash(
            'sha384',
            json_encode( $sign_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );

        $body = [
            'merchantId'  => (int) $merchant_id,
            'posId'       => (int) $pos_id,
            'sessionId'   => $session_id,
            'amount'      => $amount_grosz,
            'currency'    => 'PLN',
            'description' => $description,
            'email'       => $email,
            'country'     => 'PL',
            'language'    => 'pl',
            'urlReturn'   => $return_url,
            'urlStatus'   => $status_url,
            'sign'        => $sign,
        ];

        $api_base = $sandbox
            ? 'https://sandbox.przelewy24.pl'
            : 'https://secure.przelewy24.pl';

        $endpoint = $api_base . '/api/v1/transaction/register';

        // Basic Auth: posId:apiKey
        $auth = base64_encode( $pos_id . ':' . $api_key );

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $auth,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_die( 'Błąd połączenia z Przelewy24: ' . esc_html( $response->get_error_message() ) );
        }

        $code      = wp_remote_retrieve_response_code( $response );
        $body_resp = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_resp, true );

        if ( $code !== 200 || empty( $data['data']['token'] ) ) {
            wp_die(
                'Błąd rejestracji transakcji w Przelewy24. Kod: '
                . esc_html( $code ) . '<br>Odpowiedź: <pre>' . esc_html( $body_resp ) . '</pre>'
            );
        }

        $token = $data['data']['token'];

        // Log: initiated
        $this->log_transaction( $session_id, $amount_grosz, 'PLN', $email, 'initiated' );

        // Redirect do bramki
        $redirect_to_p24 = $api_base . '/trnRequest/' . rawurlencode( $token );
        wp_redirect( $redirect_to_p24 );
        exit;
    }

    /**
     * Zapis do tabeli
     */
    protected function log_transaction( $session_id, $amount, $currency, $email, $status = 'initiated' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $now        = current_time( 'mysql' );

        $wpdb->insert(
            $table_name,
            [
                'session_id' => $session_id,
                'amount'     => (int) $amount,
                'currency'   => $currency,
                'email'      => $email,
                'status'     => $status,
                'created_at' => $now,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Obsługa bulk delete
     */
    public function handle_bulk_delete_donations() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Brak uprawnień.' );
        }

        if ( ! isset( $_POST['p24_bulk_delete_nonce'] ) || ! wp_verify_nonce( $_POST['p24_bulk_delete_nonce'], 'p24_bulk_delete_donations' ) ) {
            wp_die( 'Niepoprawne żądanie (nonce).' );
        }

        $ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', $_POST['ids'] ) : [];

        if ( ! empty( $ids ) ) {
            global $wpdb;
            $table_name = $wpdb->prefix . self::TABLE_NAME;

            foreach ( $ids as $id ) {
                if ( $id > 0 ) {
                    $wpdb->delete(
                        $table_name,
                        [ 'id' => $id ],
                        [ '%d' ]
                    );
                }
            }
        }

        wp_redirect( admin_url( 'admin.php?page=p24-donation-history' ) );
        exit;
    }

    /**
     * Webhook: urlStatus z Przelewy24
     * - oznaczamy wpłatę jako success
     * - wysyłamy maila
     */
    public function handle_status_notification() {
        $raw  = file_get_contents( 'php://input' );
        $data = json_decode( $raw, true );

        // Przelewy24 przesyła urlStatus jako application/x-www-form-urlencoded,
        // więc musimy obsłużyć zarówno JSON jak i $_POST.
        if ( empty( $data ) && ! empty( $_POST ) ) {
            $data = wp_unslash( $_POST );
        }

        if ( empty( $data ) || empty( $data['sessionId'] ) ) {
            status_header( 400 );
            echo 'ERROR: no data';
            exit;
        }

        $options = get_option( self::OPTION_KEY, [] );

        $merchant_id = isset( $options['merchant_id'] ) ? trim( $options['merchant_id'] ) : '';
        $pos_id      = isset( $options['pos_id'] ) ? trim( $options['pos_id'] ) : '';
        $crc         = isset( $options['crc'] ) ? trim( $options['crc'] ) : '';
        $api_key     = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : '';
        $sandbox     = ! empty( $options['sandbox'] );

        if ( ! $merchant_id || ! $pos_id || ! $crc || ! $api_key ) {
            status_header( 500 );
            echo 'ERROR: no config';
            exit;
        }

        if ( empty( $data['sign'] ) || empty( $data['orderId'] ) || empty( $data['amount'] ) || empty( $data['currency'] ) ) {
            status_header( 400 );
            echo 'ERROR: invalid data';
            exit;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT status, amount, currency FROM {$table_name} WHERE session_id = %s LIMIT 1",
                $data['sessionId']
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            status_header( 400 );
            echo 'ERROR: unknown session';
            exit;
        }

        // Sprawdzenie zgodności kwoty/currency z tym, co zapisaliśmy w bazie
        if ( (int) $row['amount'] !== (int) $data['amount'] || $row['currency'] !== $data['currency'] ) {
            status_header( 400 );
            echo 'ERROR: amount mismatch';
            exit;
        }

        // Jeśli P24 przekazało status od razu, respektujemy go zanim wyślemy verify
        if ( ! empty( $data['status'] ) && strtolower( $data['status'] ) !== 'success' ) {
            $this->mark_transaction_status( $data['sessionId'], 'failed' );
            status_header( 200 );
            echo 'OK';
            exit;
        }

        // Weryfikacja sign z notyfikacji
        $sign_check_data = [
            'sessionId' => $data['sessionId'],
            'orderId'   => (int) $data['orderId'],
            'amount'    => (int) $data['amount'],
            'currency'  => $data['currency'],
            'crc'       => $crc,
        ];

        $sign_check = hash(
            'sha384',
            json_encode( $sign_check_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );

        if ( $sign_check !== $data['sign'] ) {
            status_header( 400 );
            echo 'ERROR: bad sign';
            exit;
        }

        // Opcjonalny verify – teraz wykorzystujemy go do ustawienia statusu
        $verify_sign_data = [
            'sessionId' => $data['sessionId'],
            'orderId'   => (int) $data['orderId'],
            'amount'    => (int) $data['amount'],
            'currency'  => $data['currency'],
            'crc'       => $crc,
        ];

        $verify_sign = hash(
            'sha384',
            json_encode( $verify_sign_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
        );

        $verify_body = [
            'merchantId' => (int) $merchant_id,
            'posId'      => (int) $pos_id,
            'sessionId'  => $data['sessionId'],
            'amount'     => (int) $data['amount'],
            'currency'   => $data['currency'],
            'orderId'    => (int) $data['orderId'],
            'sign'       => $verify_sign,
        ];

        $api_base = $sandbox
            ? 'https://sandbox.przelewy24.pl'
            : 'https://secure.przelewy24.pl';

        $endpoint = $api_base . '/api/v1/transaction/verify';
        $auth     = base64_encode( $pos_id . ':' . $api_key );

        $verify_response = wp_remote_post( $endpoint, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $auth,
            ],
            'body'    => wp_json_encode( $verify_body ),
            'timeout' => 20,
        ] );

        $verify_code = wp_remote_retrieve_response_code( $verify_response );
        $verify_body_resp = wp_remote_retrieve_body( $verify_response );
        $verify_data = json_decode( $verify_body_resp, true );

        $verified_success = (
            $verify_code === 200 &&
            (int) ( $verify_data['error'] ?? 1 ) === 0 &&
            ! empty( $verify_data['data']['status'] ) &&
            $verify_data['data']['status'] === 'success'
        );

        if ( $verified_success ) {
            $this->mark_transaction_status( $data['sessionId'], 'success' );
            $this->send_notification_email( $data['sessionId'] );
        } else {
            $this->mark_transaction_status( $data['sessionId'], 'failed' );
        }

        status_header( 200 );
        echo 'OK';
        exit;
    }

    /**
     * Oznaczenie transakcji jako success / failed
     */
    protected function mark_transaction_status( $session_id, $status ) {
        if ( ! in_array( $status, [ 'success', 'failed' ], true ) ) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $wpdb->update(
            $table_name,
            [ 'status' => $status ],
            [ 'session_id' => $session_id ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Mail do Ciebie po potwierdzonej wpłacie
     */
    protected function send_notification_email( $session_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE session_id = %s LIMIT 1",
                $session_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return;
        }

        $amount_pln = number_format( $row['amount'] / 100, 2, ',', ' ' );
        $email      = $row['email'];

        $subject = 'Nowa opłacona wpłata przez Przelewy24';
        $message = "Nowa potwierdzona wpłata przez formularz P24.\n\n"
                 . "Kwota: {$amount_pln} {$row['currency']}\n"
                 . "Status: {$row['status']}\n"
                 . "Email płacącego: {$email}\n"
                 . "Session ID: {$session_id}\n\n"
                 . "Wpłata została oznaczona jako success.";

        wp_mail( self::EMAIL_NOTIFY_TO, $subject, $message );
    }

    /**
     * Ładny komunikat "Dziękujemy" po powrocie z Przelewy24
     */
    public function maybe_prepend_thankyou_message( $content ) {
        if ( isset( $_GET['p24_donation_status'] ) && $_GET['p24_donation_status'] === 'return' ) {

            $msg = '
            <div class="p24-thanks-wrapper">
                <div class="p24-thanks-box">
                    <div class="p24-thanks-box-title">
                        <span class="emoji">❤️</span>
                        <span>Dziękujemy za Twoje wsparcie!</span>
                    </div>
                    <div class="p24-thanks-box-sub">
                        Twoja wpłata została przekazana do realizacji w systemie Przelewy24.
                    </div>
                </div>
            </div>';

            return $msg . $content;
        }

        return $content;
    }
}

new P24_Dobrowolne_Wsparcie();

// Hook aktywacji – tworzenie tabeli
register_activation_hook( __FILE__, [ 'P24_Dobrowolne_Wsparcie', 'activate' ] );
