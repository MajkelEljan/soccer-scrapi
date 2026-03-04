<?php
/**
 * Plugin Name: Soccer ScrAPI
 * Plugin URI: https://nafciarski.pl
 * Description: Plugin do pobierania i wyświetlania danych Ekstraklasy (SofaScore API) oraz III ligi - Wisła II Płock (90minut.pl)
 * Version: 1.7.0
 * Author: Majkel
 * License: GPL v2 or later
 * Text Domain: sofascore-ekstraklasa
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Stałe pluginu
define('SOFASCORE_PLUGIN_VERSION', '1.7.0');
define('SOFASCORE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SOFASCORE_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * Główna klasa pluginu
 */
class SofaScoreEkstraklasa {
    
    private $api_key = '47d18d56d3msh2a53ad1f94f71afp164affjsn2afff0065672';
    private $api_host = 'sportapi7.p.rapidapi.com';
    private $base_url = 'https://sportapi7.p.rapidapi.com/api/v1';
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
        add_action('wp_ajax_test_sofascore_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_load_rounds_list', array($this, 'ajax_load_rounds_list'));
        add_action('wp_ajax_update_round_data', array($this, 'ajax_update_round_data'));
        add_action('wp_ajax_delete_round_data', array($this, 'ajax_delete_round_data'));
        
        // AJAX handlers dla Wisły II Płock
        add_action('wp_ajax_update_wisla_ii_data', array($this, 'ajax_update_wisla_ii_data'));
        add_action('wp_ajax_upload_wisla_ii_csv', array($this, 'ajax_upload_wisla_ii_csv'));
        
        // AJAX handlers dla nowego systemu kadry API
        add_action('wp_ajax_fetch_wisla_squad_api', array($this, 'ajax_fetch_wisla_squad_api'));
        add_action('wp_ajax_update_selected_players', array($this, 'ajax_update_selected_players'));
        add_action('wp_ajax_delete_selected_players', array($this, 'ajax_delete_selected_players'));
        add_action('wp_ajax_upload_player_photo', array($this, 'ajax_upload_player_photo'));
        add_action('wp_ajax_attach_player_photo', array($this, 'ajax_attach_player_photo'));
        add_action('wp_ajax_edit_player_data', array($this, 'ajax_edit_player_data'));
        add_action('wp_ajax_get_player_data', array($this, 'ajax_get_player_data'));
        
        // AJAX handler dla ustawień
        add_action('wp_ajax_save_sofascore_settings', array($this, 'ajax_save_settings'));
        
        // AJAX handlers dla edycji meczów
        add_action('wp_ajax_get_match_override_data', array($this, 'ajax_get_match_override_data'));
        add_action('wp_ajax_save_match_override', array($this, 'ajax_save_match_override'));
        add_action('wp_ajax_remove_match_override', array($this, 'ajax_remove_match_override'));
        
        // AJAX handlers dla integracji z Football Pool
        add_action('wp_ajax_sofascore_get_fp_rankings', array($this, 'ajax_get_fp_rankings'));
        add_action('wp_ajax_sofascore_get_fp_ranking_matches', array($this, 'ajax_get_fp_ranking_matches'));
        add_action('wp_ajax_sofascore_save_fp_mapping', array($this, 'ajax_save_fp_mapping'));
        add_action('wp_ajax_sofascore_remove_fp_mapping', array($this, 'ajax_remove_fp_mapping'));
        add_action('wp_ajax_sofascore_manual_fp_sync', array($this, 'ajax_manual_fp_sync'));

        // AJAX handlers dla smart scheduler
        add_action('wp_ajax_sofascore_rescan_daily_plan', array($this, 'ajax_rescan_daily_plan'));
        add_action('wp_ajax_sofascore_reset_match', array($this, 'ajax_reset_match'));
        add_action('wp_ajax_sofascore_get_daily_plan', array($this, 'ajax_get_daily_plan'));
        add_action('wp_ajax_sofascore_backfill_incidents_media', array($this, 'ajax_backfill_incidents_media'));
        
        // Rejestracja shortcodes - Ekstraklasa
        add_shortcode('tabela_ekstraklasa', array($this, 'shortcode_tabela'));
        add_shortcode('tabela_ekstraklasa_zamrozona', array($this, 'shortcode_tabela_zamrozona'));
        add_shortcode('terminarz_ekstraklasa', array($this, 'shortcode_terminarz'));
        add_shortcode('terminarz_wisla', array($this, 'shortcode_terminarz_wisla'));
        add_shortcode('wisla_kadra', array($this, 'shortcode_wisla_kadra'));
        
        // Rejestracja shortcodes - Wisła II Płock (III Liga)
        add_shortcode('tabela_3_liga', array($this, 'shortcode_tabela_3_liga'));
        add_shortcode('terminarz_3_liga', array($this, 'shortcode_terminarz_3_liga'));
        add_shortcode('terminarz_wisla_ii', array($this, 'shortcode_terminarz_wisla_ii'));
        add_shortcode('wisla_ii_kadra', array($this, 'shortcode_wisla_ii_kadra'));
        
        // Cron job do automatycznej aktualizacji
        add_action('wp', array($this, 'schedule_updates'));
        add_action('sofascore_update_data', array($this, 'update_all_data'));
        add_action('sofascore_auto_refresh', array($this, 'auto_refresh_data'));
        add_action('sofascore_check_media', array($this, 'hourly_media_check'));
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Hook aktywacji/deaktywacji
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Ładowanie tłumaczeń
        load_plugin_textdomain('sofascore-ekstraklasa', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }
    
    /**
     * Wykonaj zapytanie do API
     */
    private function make_api_request($endpoint) {
        $url = $this->base_url . $endpoint;
        
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => [
                "x-rapidapi-host: " . $this->api_host,
                "x-rapidapi-key: " . $this->api_key
            ],
        ]);
        
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $err = curl_error($curl);
        
        curl_close($curl);
        
        if ($err) {
            return array(
                'success' => false,
                'error' => 'cURL Error: ' . $err
            );
        }
        
        if ($http_code !== 200) {
            return array(
                'success' => false,
                'error' => 'HTTP Error: ' . $http_code,
                'response' => $response
            );
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'error' => 'JSON Parse Error: ' . json_last_error_msg()
            );
        }
        
        return array(
            'success' => true,
            'data' => $data
        );
    }
    
    /**
     * Pobierz tabelę Ekstraklasy
     */
    public function get_standings($season_id = '76477') {
        $endpoint = "/unique-tournament/202/season/{$season_id}/standings/total";
        return $this->make_api_request($endpoint);
    }
    
    /**
     * Pobierz terminarz konkretnej kolejki
     */
    public function get_round_fixtures($season_id = '76477', $round = 1) {
        $endpoint = "/unique-tournament/202/season/{$season_id}/events/round/{$round}";
        return $this->make_api_request($endpoint);
    }
    
    /**
     * Pobierz wszystkie mecze sezonu
     */
    public function get_season_fixtures($season_id = '76477') {
        $endpoint = "/unique-tournament/202/season/{$season_id}/events";
        return $this->make_api_request($endpoint);
    }
    
    /**
     * Pobierz szczegóły wydarzenia (meczu) z wynikami
     */
    public function get_event_details($event_id) {
        // Sprawdź cache najpierw
        $cache_key = 'sofascore_event_' . $event_id;
        $cached_details = get_transient($cache_key);
        
        if ($cached_details !== false) {
            return array(
                'success' => true,
                'data' => $cached_details,
                'from_cache' => true
            );
        }
        
        $endpoint = "/event/{$event_id}";
        $result = $this->make_api_request($endpoint);
        
        // Jeśli zapytanie się powiodło, zapisz w cache na 24 godziny
        // (wyniki nie zmieniają się więc można cache'ować długo)
        if ($result['success']) {
            set_transient($cache_key, $result['data'], DAY_IN_SECONDS);
        }
        
        return $result;
    }

    public function get_event_incidents($event_id) {
        $endpoint = "/event/{$event_id}/incidents";
        return $this->make_api_request($endpoint);
    }

    public function get_event_media($event_id) {
        $endpoint = "/event/{$event_id}/media";
        return $this->make_api_request($endpoint);
    }
    
    /**
     * Test połączenia z API
     */
    public function test_connection() {
        $result = $this->get_standings();
        
        if ($result['success']) {
            return array(
                'success' => true,
                'message' => 'Połączenie z SofaScore API działa poprawnie!'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Błąd połączenia: ' . $result['error']
            );
        }
    }
    
    /**
     * AJAX handler dla testu połączenia
     */
    public function test_api_connection() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $test = $this->test_connection();
        wp_send_json($test);
    }
    
    /**
     * Załaduj listę rund (AJAX)
     */
    public function ajax_load_rounds_list() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $tournament = sanitize_text_field($_POST['tournament']);
        $season = sanitize_text_field($_POST['season']);
        
        // Pobierz zapisane rundy
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        
        // Generuj HTML dla 34 rund Ekstraklasy
        $html = '<div class="rounds-grid">';
        
        for ($round = 1; $round <= 34; $round++) {
            $has_data = isset($saved_rounds[$round]);
            $class = $has_data ? 'round-item has-data' : 'round-item';
            
            $status = $has_data ? 
                'Dane pobrane: ' . $saved_rounds[$round]['updated'] : 
                'Brak danych';
            
            $button_text = $has_data ? 'Aktualizuj' : 'Pobierz';
            $button_class = $has_data ? 'button-secondary' : 'button-primary';
            
            $html .= '<div class="' . $class . '">';
            $html .= '<h4>Runda ' . $round . '</h4>';
            $html .= '<div class="round-status">' . $status . '</div>';
            $html .= '<div class="round-actions">';
            $html .= '<button class="button ' . $button_class . ' update-round-btn" data-round="' . $round . '">' . $button_text . '</button>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        wp_send_json_success(array(
            'html' => $html
        ));
    }
    
    /**
     * Aktualizuj dane rundy (AJAX)
     */
    public function ajax_update_round_data() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $tournament = sanitize_text_field($_POST['tournament']);
        $season = sanitize_text_field($_POST['season']);
        $round = intval($_POST['round']);
        
        // Pobierz dane z API
        $result = $this->get_round_fixtures($season, $round);
        
        if ($result['success']) {
            // Zapisz dane
            $saved_rounds = get_option('sofascore_saved_rounds', array());
            $saved_rounds[$round] = array(
                'data' => $result['data'],
                'updated' => current_time('Y-m-d H:i:s'),
                'matches_count' => count($result['data']['events'] ?? array())
            );
            
            update_option('sofascore_saved_rounds', $saved_rounds);
            
            wp_send_json_success(array(
                'message' => 'Dane rundy ' . $round . ' zostały zaktualizowane',
                'updated' => current_time('Y-m-d H:i:s')
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Błąd pobierania danych: ' . $result['error']
            ));
        }
    }
    
    /**
     * Usuń dane rundy (AJAX)
     */
    public function ajax_delete_round_data() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $round = intval($_POST['round']);
        
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        
        if (isset($saved_rounds[$round])) {
            unset($saved_rounds[$round]);
            update_option('sofascore_saved_rounds', $saved_rounds);
            
            wp_send_json_success(array(
                'message' => 'Dane rundy ' . $round . ' zostały usunięte'
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Nie znaleziono danych dla rundy ' . $round
            ));
        }
    }
    
    /**
     * Shortcode dla tabeli Ekstraklasy
     */
    public function shortcode_tabela($atts) {
        $atts = shortcode_atts(array(
            'season' => '76477',
            'pokazuj_kwalifikacje' => 'tak'
        ), $atts);
        
        // Spróbuj wczytać z cache
        $cache_key = 'sofascore_standings_' . $atts['season'];
        $standings = get_transient($cache_key);
        
        if ($standings === false) {
            // Pobierz z API
            $api_result = $this->get_standings($atts['season']);
            
            if (!$api_result['success']) {
                return '<div class="sofascore-error">Błąd pobierania tabeli: ' . esc_html($api_result['error']) . '</div>';
            }
            
            $standings = $api_result['data'];
            
            // Zapisz w cache na 1 godzinę
            set_transient($cache_key, $standings, HOUR_IN_SECONDS);
        }
        
        return $this->render_standings_table($standings, $atts);
    }
    
    /**
     * Shortcode dla zamrożonej tabeli Ekstraklasy
     * Pozwala zapisać aktualny stan tabeli i wyświetlać go bez aktualizacji
     * 
     * Parametry:
     * - id: unikalny identyfikator zamrożonej tabeli (wymagany)
     * - zapisz: "tak" - zapisuje aktualną tabelę pod danym ID
     * - season: sezon (domyślnie 76477)
     * - pokazuj_kwalifikacje: czy pokazywać legendę kwalifikacji (domyślnie "tak")
     * 
     * Przykłady użycia:
     * [tabela_ekstraklasa_zamrozona id="poczatek_sezonu_2024" zapisz="tak"] - zapisuje aktualną tabelę
     * [tabela_ekstraklasa_zamrozona id="poczatek_sezonu_2024"] - wyświetla zapisaną tabelę
     */
    public function shortcode_tabela_zamrozona($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'zapisz' => 'nie',
            'season' => '76477',
            'pokazuj_kwalifikacje' => 'tak'
        ), $atts);
        
        // Sprawdź czy podano ID
        if (empty($atts['id'])) {
            return '<div class="sofascore-error">Błąd: Nie podano ID dla zamrożonej tabeli. Użyj parametru id="nazwa_tabeli"</div>';
        }
        
        $frozen_table_key = 'sofascore_frozen_table_' . sanitize_key($atts['id']);
        
        // Jeśli parametr "zapisz" jest ustawiony na "tak", zapisz aktualną tabelę
        if (strtolower($atts['zapisz']) === 'tak') {
            // Pobierz aktualną tabelę z API
            $api_result = $this->get_standings($atts['season']);
            
            if (!$api_result['success']) {
                return '<div class="sofascore-error">Błąd pobierania tabeli do zapisania: ' . esc_html($api_result['error']) . '</div>';
            }
            
            // Zapisz tabelę z metadanymi
            $frozen_data = array(
                'data' => $api_result['data'],
                'season' => $atts['season'],
                'created_at' => current_time('mysql'),
                'timestamp' => time(),
                'atts' => $atts
            );
            
            update_option($frozen_table_key, $frozen_data);
            
            // Wyświetl zapisaną tabelę z informacją o zapisaniu
            $output = '<div class="sofascore-info" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
            $output .= '✅ Tabela została zapisana jako "' . esc_html($atts['id']) . '" z datą: ' . date('d.m.Y H:i', $frozen_data['timestamp']);
            $output .= '</div>';
            
            return $output . $this->render_standings_table($api_result['data'], $atts, true, $frozen_data['timestamp']);
        }
        
        // Odczytaj zapisaną tabelę
        $frozen_data = get_option($frozen_table_key, false);
        
        if ($frozen_data === false) {
            return '<div class="sofascore-error">Błąd: Nie znaleziono zamrożonej tabeli o ID "' . esc_html($atts['id']) . '". Najpierw zapisz tabelę używając parametru zapisz="tak"</div>';
        }
        
        // Wyświetl zapisaną tabelę z informacją o dacie zapisu
        return $this->render_standings_table($frozen_data['data'], $atts, true, $frozen_data['timestamp']);
    }
    
    /**
     * Shortcode dla terminarza
     */
    public function shortcode_terminarz($atts) {
        $atts = shortcode_atts(array(
            'season' => '76477',
            'round' => '1',
            'limit' => '10'
        ), $atts);
        
        $round = intval($atts['round']);
        
        // Sprawdź czy mamy zapisane dane dla tej rundy
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        
        if (isset($saved_rounds[$round])) {
            // Użyj zapisanych danych
            $fixtures = $saved_rounds[$round]['data'];
            $last_updated = $saved_rounds[$round]['updated'];
        } else {
            // Spróbuj pobrać z cache API
            $cache_key = 'sofascore_fixtures_' . $atts['season'] . '_' . $atts['round'];
            $fixtures = get_transient($cache_key);
            
            if ($fixtures === false) {
                // Jeśli nie ma danych, pokaż komunikat
                return '<div class="sofascore-info">
                    <h4>Brak danych dla rundy ' . $round . '</h4>
                    <p>Dane dla tej rundy nie zostały jeszcze pobrane. Przejdź do panelu administracyjnego (SofaScore Ekstraklasa → Terminarz) aby pobrać dane dla rundy ' . $round . '.</p>
                </div>';
            }
            $last_updated = 'Cache API';
        }
        
        return $this->render_fixtures_table($fixtures, $atts, $last_updated ?? null);
    }
    
    /**
     * Shortcode dla kadry Wisły Płock (Ekstraklasa) - NOWA WERSJA Z BAZĄ DANYCH
     */
    public function shortcode_wisla_kadra($atts) {
        $atts = shortcode_atts(array(
            'pozycja' => '',
            'kolumny' => '3',
            'sortowanie' => 'numer',
            'limit' => '50',
            'styl' => 'karty',
            'debug' => '0'
        ), $atts);
        
        // Sprawdź czy tabela istnieje
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $table_name
        )) === $table_name;
        
        if (!$table_exists) {
            return '<div class="wisla-error">❌ Tabela zawodników nie została znaleziona. Skontaktuj się z administratorem aby aktywować ponownie plugin.<br><small>Tabela: ' . $table_name . '</small></div>';
        }
        
        // Wczytaj zawodników z bazy danych
        $players = $this->load_wisla_kadra_database();
        
        // Debug mode
        if ($atts['debug'] === '1') {
            $debug_info = '<div style="background: #fff3cd; padding: 15px; margin: 10px 0; border-radius: 5px;">';
            $debug_info .= '<h4>🔍 Debug informacje:</h4>';
            $debug_info .= '<p><strong>Źródło danych:</strong> Baza danych (Tabela: ' . $table_name . ')</p>';
            $debug_info .= '<p><strong>Tabela istnieje:</strong> ' . ($table_exists ? 'TAK' : 'NIE') . '</p>';
            $debug_info .= '<p><strong>Znalezionych zawodników:</strong> ' . count($players) . '</p>';
            if (count($players) > 0) {
                $debug_info .= '<p><strong>Pierwszy zawodnik:</strong> ' . esc_html($players[0]['imie_nazwisko']) . ' (nr ' . $players[0]['numer'] . ', ' . $players[0]['pozycja'] . ')</p>';
            }
            $debug_info .= '<p><strong>Panel zarządzania:</strong> <a href="' . admin_url('admin.php?page=wisla-kadra-admin') . '">Soccer ScrAPI → Kadra Wisły Płock</a></p>';
            $debug_info .= '<p><small>Dodaj <code>debug="1"</code> do shortcode aby zobaczyć te informacje.</small></p>';
            $debug_info .= '</div>';
            
            if (empty($players)) {
                return $debug_info . '<div class="wisla-error">❌ Brak zawodników w bazie danych. Użyj panelu administracyjnego aby pobrać kadrę z API.</div>';
            }
            
            $output = $debug_info;
        } else {
            if (empty($players)) {
                return '<div class="wisla-error">❌ Brak zawodników w bazie danych. <a href="' . admin_url('admin.php?page=wisla-kadra-admin') . '">Przejdź do panelu zarządzania</a> aby pobrać kadrę z SofaScore API.<br><small>Spróbuj dodać <code>debug="1"</code> do shortcode aby zobaczyć więcej informacji.</small></div>';
            }
            $output = '';
        }
        
        // Filtruj po pozycji
        if (!empty($atts['pozycja'])) {
            $players = array_filter($players, function($player) use ($atts) {
                return stripos($player['pozycja'], $atts['pozycja']) !== false;
            });
        }
        
        // Ogranicz liczbę zawodników
        $players = array_slice($players, 0, (int)$atts['limit']);
        
        // Generuj HTML - style są ładowane przez wp_enqueue_scripts
        // Fallback: jeśli style nie zostały załadowane, dodaj inline
        if (!wp_style_is('wp-block-library', 'enqueued')) {
            $output .= $this->wisla_kadra_css();
        }
        $output .= $this->render_wisla_kadra_enhanced($players, $atts);
        
        // Dodaj SEO Schema.org
        $output .= $this->wisla_generate_team_schema($players);
        
        return $output;
    }
    
    /**
     * Shortcode dla terminarza Wisły Płock
     */
    public function shortcode_terminarz_wisla($atts) {
        $atts = shortcode_atts(array(
            'season' => '76477',
            'limit' => '50'
        ), $atts);
        
        // Pobierz wszystkie zapisane rundy
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        
        if (empty($saved_rounds)) {
            return '<div class="sofascore-info">
                <h4>Brak danych meczów</h4>
                <p>Nie znaleziono zapisanych danych rund. Przejdź do panelu administracyjnego (SofaScore Ekstraklasa → Terminarz) aby pobrać dane.</p>
            </div>';
        }
        
        // Pobierz overrides
        $overrides = get_option('sofascore_match_overrides', array());
        
        // Zbierz wszystkie mecze Wisły Płock (z deduplikacją po match_id i drużynach+dacie)
        $wisla_matches = array();
        $seen_match_ids = array();
        $seen_matches_signature = array(); // Deduplikacja po drużynach + dacie
        
        foreach ($saved_rounds as $round_num => $round_data) {
            if (isset($round_data['data']['events'])) {
                foreach ($round_data['data']['events'] as $match) {
                    $match_id = $match['id'] ?? null;
                    
                    // Pomiń duplikaty - ten sam match_id
                    if ($match_id && isset($seen_match_ids[$match_id])) {
                        continue;
                    }
                    
                    // Zastosuj override jeśli istnieje
                    if ($match_id && isset($overrides[$match_id])) {
                        $override = $overrides[$match_id];
                        
                        $match['homeTeam']['name'] = $override['home_team'];
                        $match['awayTeam']['name'] = $override['away_team'];
                        $match['startTimestamp'] = $override['timestamp'];
                        $match['status']['description'] = $override['status'];
                        
                        if ($override['home_score'] !== null) {
                            $match['homeScore']['current'] = $override['home_score'];
                        }
                        if ($override['away_score'] !== null) {
                            $match['awayScore']['current'] = $override['away_score'];
                        }
                        // Wyniki do przerwy z override
                        if (isset($override['home_score_ht']) && $override['home_score_ht'] !== null) {
                            $match['homeScore']['period1'] = $override['home_score_ht'];
                        }
                        if (isset($override['away_score_ht']) && $override['away_score_ht'] !== null) {
                            $match['awayScore']['period1'] = $override['away_score_ht'];
                        }
                    }
                    
                    // Sprawdź czy Wisła Płock gra w tym meczu
                    $is_wisla_match = (
                        stripos($match['homeTeam']['name'], 'Wisła Płock') !== false ||
                        stripos($match['awayTeam']['name'], 'Wisła Płock') !== false
                    );
                    
                    if ($is_wisla_match) {
                        // Utwórz unikalny sygnaturę meczu na podstawie drużyn + data (dzień) + kolejka
                        $home_team = strtolower(trim($match['homeTeam']['name']));
                        $away_team = strtolower(trim($match['awayTeam']['name']));
                        $match_date = isset($match['startTimestamp']) ? date('Y-m-d', $match['startTimestamp']) : 'nodate';
                        $signature = $home_team . '|' . $away_team . '|' . $match_date . '|' . $round_num;
                        
                        // Pomiń jeśli taki mecz już był (te same drużyny, ta sama data, ta sama kolejka)
                        if (isset($seen_matches_signature[$signature])) {
                            // Priorytet dla meczu z override
                            $has_override = $match_id && isset($overrides[$match_id]);
                            if (!$has_override) {
                                // Ten mecz nie ma override, a już mamy taki mecz w liście - pomiń
                                continue;
                            } else {
                                // Ten mecz ma override - usuń poprzedni i dodaj ten
                                $wisla_matches = array_filter($wisla_matches, function($m) use ($signature, $home_team, $away_team, $match_date, $round_num) {
                                    $m_home = strtolower(trim($m['homeTeam']['name']));
                                    $m_away = strtolower(trim($m['awayTeam']['name']));
                                    $m_date = isset($m['startTimestamp']) ? date('Y-m-d', $m['startTimestamp']) : 'nodate';
                                    $m_sig = $m_home . '|' . $m_away . '|' . $m_date . '|' . $m['round_number'];
                                    return $m_sig !== $signature;
                                });
                            }
                        }
                        
                        $match['round_number'] = $round_num;
                        $wisla_matches[] = $match;
                        
                        // Zaznacz że ten match_id został już użyty
                        if ($match_id) {
                            $seen_match_ids[$match_id] = true;
                        }
                        
                        // Zaznacz sygnaturę meczu
                        $seen_matches_signature[$signature] = true;
                    }
                }
            }
        }
        
        if (empty($wisla_matches)) {
            return '<div class="sofascore-info">
                <h4>Brak meczów Wisły Płock</h4>
                <p>Nie znaleziono meczów Wisły Płock w pobranych danych. Pobierz więcej rund w panelu administracyjnym.</p>
            </div>';
        }
        
        // Sortuj mecze według daty - Wisła zawsze pierwsza jeśli ta sama data/godzina
        usort($wisla_matches, function($a, $b) {
            $time_diff = $a['startTimestamp'] - $b['startTimestamp'];
            
            // Jeśli ta sama data i godzina, Wisła ma priorytet
            if ($time_diff == 0) {
                $a_wisla = stripos($a['homeTeam']['name'], 'Wisła Płock') !== false || 
                          stripos($a['awayTeam']['name'], 'Wisła Płock') !== false;
                $b_wisla = stripos($b['homeTeam']['name'], 'Wisła Płock') !== false || 
                          stripos($b['awayTeam']['name'], 'Wisła Płock') !== false;
                
                if ($a_wisla && !$b_wisla) return -1;
                if (!$a_wisla && $b_wisla) return 1;
            }
            
            return $time_diff;
        });
        
        // Ogranicz liczbę meczów
        $wisla_matches = array_slice($wisla_matches, 0, intval($atts['limit']));
        
        return $this->render_wisla_fixtures_table($wisla_matches, $atts);
    }
    
    /**
     * Renderuj tabelę ligową
     */
    private function render_standings_table($data, $atts, $is_frozen = false, $frozen_timestamp = null) {
        if (!isset($data['standings'][0]['rows'])) {
            return '<div class="sofascore-error">Brak danych tabeli</div>';
        }
        
        $teams = $data['standings'][0]['rows'];
        $tournament_name = $data['standings'][0]['name'] ?? 'Ekstraklasa';
        
        ob_start();
        ?>
        <div class="ekstraklasa-container">
            <div class="ekstraklasa-header">
                <h3 class="ekstraklasa-title"><?php echo esc_html($tournament_name); ?></h3>
                <?php if ($is_frozen && $frozen_timestamp): ?>
                    <div class="frozen-table-info" style="font-size: 0.85em; opacity: 0.9; margin-top: 5px;">
                        📅 Zamrożona tabela z dnia: <?php echo date('d.m.Y H:i', $frozen_timestamp); ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="ekstraklasa-table-wrapper">
                <table class="ekstraklasa-table">
                    <thead>
                        <tr>
                            <th>Poz.</th>
                            <th>Drużyna</th>
                            <th>M</th>
                            <th>Pkt</th>
                            <th>Z</th>
                            <th>R</th>
                            <th>P</th>
                            <th>Bramki</th>
                            <th class="desktop-only">Bilans</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $index => $team): ?>
                            <?php
                            $team_data = $team['team'];
                            
                            // Użyj rzeczywistej pozycji z API
                            $display_position = $team['position'];

                            // Określ klasę CSS na podstawie wyświetlanej pozycji
                            $qualification_class = '';
                            if ($display_position <= 2) {
                                $qualification_class = 'champions-league';
                            } elseif ($display_position <= 4) {
                                $qualification_class = 'conference-league';
                            } elseif ($display_position >= 16) {
                                $qualification_class = 'relegation';
                            }
                            ?>
                            <tr class="team-row <?php echo $qualification_class; ?>">
                                <td class="position">
                                    <span class="position-number"><?php echo $display_position; ?></span>
                                </td>
                                <td class="team-info">
                                    <div class="team-name-wrapper">
                                        <div class="team-details">
                                            <span class="team-name"><?php echo esc_html($team_data['name']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="matches"><?php echo $team['matches']; ?></td>
                                <td class="points">
                                    <strong><?php echo $team['points']; ?></strong>
                                </td>
                                <td class="wins"><?php echo $team['wins']; ?></td>
                                <td class="draws"><?php echo $team['draws']; ?></td>
                                <td class="losses"><?php echo $team['losses']; ?></td>
                                <td class="goals">
                                    <span class="goals-for"><?php echo $team['scoresFor']; ?></span>:<span class="goals-against"><?php echo $team['scoresAgainst']; ?></span>
                                </td>
                                <td class="goal-diff desktop-only <?php echo ($team['scoresFor'] - $team['scoresAgainst']) > 0 ? 'positive' : (($team['scoresFor'] - $team['scoresAgainst']) < 0 ? 'negative' : 'neutral'); ?>">
                                    <?php 
                                    $diff = $team['scoresFor'] - $team['scoresAgainst'];
                                    echo ($diff > 0 ? '+' : '') . $diff; 
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($atts['pokazuj_kwalifikacje'] === 'tak'): ?>
            <div class="ekstraklasa-legend">
                <div class="legend-item champions">
                    <span class="legend-color"></span>
                    Liga Mistrzów
                </div>
                <div class="legend-item conference">
                    <span class="legend-color"></span>
                    Liga Konferencji
                </div>
                <div class="legend-item relegation">
                    <span class="legend-color"></span>
                    Spadek
                </div>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .ekstraklasa-container {
            max-width: 100%;
            margin: 20px 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .ekstraklasa-header {
            background: linear-gradient(135deg, #0299d6 0%, #1e7bb8 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }

        .ekstraklasa-title {
            margin: 0;
            font-size: 2.2em;
            font-weight: 600;
            color: white;
        }

        .ekstraklasa-legend {
            display: flex;
            justify-content: center;
            gap: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            font-weight: 500;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
        }

        .legend-item.champions .legend-color {
            background: #28a745;
        }

        .legend-item.conference .legend-color {
            background: #ffc107;
        }

        .legend-item.relegation .legend-color {
            background: #dc3545;
        }

        .ekstraklasa-table-wrapper {
            overflow-x: auto;
        }

        .ekstraklasa-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .ekstraklasa-table th {
            background: #343a40;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 0.9em;
            border: none;
        }

        .ekstraklasa-table td {
            padding: 12px 8px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .team-row:hover {
            background-color: #f8f9fa;
        }

        .team-row.champions-league {
            border-left: 4px solid #28a745;
        }

        .team-row.conference-league {
            border-left: 4px solid #ffc107;
        }

        .team-row.relegation {
            border-left: 4px solid #dc3545;
        }

        .position {
            font-weight: 600;
            font-size: 1.1em;
        }

        .team-info {
            text-align: left !important;
            min-width: 200px;
        }

        .team-name-wrapper {
            display: flex;
            align-items: center;
        }

        .team-details {
            display: flex;
            flex-direction: column;
        }

        .team-name {
            font-weight: 600;
            font-size: 1em;
            color: #212529;
            line-height: 1.2;
        }

        .goals {
            font-weight: 500;
        }

        .goal-diff.positive {
            color: #28a745;
            font-weight: 600;
        }

        .goal-diff.negative {
            color: #dc3545;
            font-weight: 600;
        }

        .goal-diff.neutral {
            color: #6c757d;
        }

        .points {
            font-size: 1.1em;
            color: #0299d6;
        }

        .sofascore-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            margin: 20px 0;
        }

        @media (max-width: 768px) {
            .ekstraklasa-legend {
                gap: 10px;
            }
            
            .legend-item {
                font-size: 0.8em;
            }

            .ekstraklasa-table th,
            .ekstraklasa-table td {
                padding: 8px 4px;
                font-size: 0.85em;
            }

            .team-name {
                font-size: 0.9em;
            }
            
            .desktop-only {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .ekstraklasa-container {
                margin: 10px -15px;
                border-radius: 0;
            }

            .ekstraklasa-header {
                padding: 15px;
            }

            .ekstraklasa-title {
                font-size: 1.6em;
            }

            .ekstraklasa-table th,
            .ekstraklasa-table td {
                padding: 6px 2px;
                font-size: 0.8em;
            }

            .team-info {
                min-width: 120px;
            }
        }
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderuj tabelę terminarza
     */
    private function render_fixtures_table($data, $atts, $last_updated = null) {
        if (!isset($data['events'])) {
            return '<div class="sofascore-error">Brak danych terminarza</div>';
        }
        
        // Pobierz overrides i zastosuj je do eventów
        $overrides = get_option('sofascore_match_overrides', array());
        $events_with_overrides = array();
        $seen_match_ids = array();
        $seen_matches_signature = array();
        
        foreach ($data['events'] as $event) {
            $match_id = $event['id'] ?? null;
            
            // Pomiń duplikaty - ten sam match_id
            if ($match_id && isset($seen_match_ids[$match_id])) {
                continue;
            }
            
            // Zastosuj override jeśli istnieje
            if ($match_id && isset($overrides[$match_id])) {
                $override = $overrides[$match_id];
                
                // Nadpisz dane overridem
                $event['homeTeam']['name'] = $override['home_team'];
                $event['awayTeam']['name'] = $override['away_team'];
                $event['startTimestamp'] = $override['timestamp'];
                $event['status']['description'] = $override['status'];
                
                // Nadpisz wyniki
                if ($override['home_score'] !== null) {
                    $event['homeScore']['current'] = $override['home_score'];
                }
                if ($override['away_score'] !== null) {
                    $event['awayScore']['current'] = $override['away_score'];
                }
                // Nadpisz wyniki do przerwy (jeśli podane)
                if (isset($override['home_score_ht']) && $override['home_score_ht'] !== null) {
                    $event['homeScore']['period1'] = $override['home_score_ht'];
                }
                if (isset($override['away_score_ht']) && $override['away_score_ht'] !== null) {
                    $event['awayScore']['period1'] = $override['away_score_ht'];
                }
            }
            
            // Deduplikacja po drużynach + data
            $home_team = strtolower(trim($event['homeTeam']['name'] ?? ''));
            $away_team = strtolower(trim($event['awayTeam']['name'] ?? ''));
            $match_date = isset($event['startTimestamp']) ? date('Y-m-d', $event['startTimestamp']) : 'nodate';
            $signature = $home_team . '|' . $away_team . '|' . $match_date;
            
            // Pomiń jeśli taki mecz już był (te same drużyny, ta sama data)
            if (isset($seen_matches_signature[$signature])) {
                // Priorytet dla meczu z override
                $has_override = $match_id && isset($overrides[$match_id]);
                if (!$has_override) {
                    // Ten mecz nie ma override, a już mamy taki mecz w liście - pomiń
                    continue;
                } else {
                    // Ten mecz ma override - usuń poprzedni i dodaj ten
                    $events_with_overrides = array_filter($events_with_overrides, function($m) use ($signature, $home_team, $away_team, $match_date) {
                        $m_home = strtolower(trim($m['homeTeam']['name'] ?? ''));
                        $m_away = strtolower(trim($m['awayTeam']['name'] ?? ''));
                        $m_date = isset($m['startTimestamp']) ? date('Y-m-d', $m['startTimestamp']) : 'nodate';
                        $m_sig = $m_home . '|' . $m_away . '|' . $m_date;
                        return $m_sig !== $signature;
                    });
                }
            }
            
            $events_with_overrides[] = $event;
            
            // Zaznacz jako użyty
            if ($match_id) {
                $seen_match_ids[$match_id] = true;
            }
            $seen_matches_signature[$signature] = true;
        }
        
        // Filtruj mecze - usuń postponed (PO zastosowaniu overrides)
        $filtered_events = array_filter($events_with_overrides, function($match) {
            $status = strtolower($match['status']['description'] ?? '');
            return $status !== 'postponed';
        });
        
        $events = array_slice($filtered_events, 0, intval($atts['limit']));

        $show_incidents = get_option('sofascore_show_incidents', 0);
        $show_media = get_option('sofascore_show_media', 0);
        $has_details = ($show_incidents || $show_media);
        
        ob_start();
        ?>
        <div class="terminarz-container">
            <div class="terminarz-header">
                <h3>Kolejka <?php echo esc_html($atts['round']); ?></h3>
            </div>
            <div class="terminarz-matches">
                <?php foreach ($events as $match): ?>
                    <?php
                    // Sprawdź czy to mecz Wisły Płock
                    $is_wisla_match = (
                        stripos($match['homeTeam']['name'], 'Wisła Płock') !== false ||
                        stripos($match['awayTeam']['name'], 'Wisła Płock') !== false
                    );
                    
                    $match_class = $is_wisla_match ? 'match-item wisla-match' : 'match-item';
                    
                    // Sprawdź status meczu i pobierz wyniki dla zakończonych
                    $status = $match['status']['description'] ?? '';
                    $is_finished = (strtolower($status) === 'ended' || strtolower($match['status']['type'] ?? '') === 'finished');
                    
                    // Sprawdź czy mecz ma ręczny override
                    $match_id = $match['id'] ?? null;
                    $has_manual_override = $match_id && isset($overrides[$match_id]);
                    
                    // Dla zakończonych meczów pobierz szczegóły z wynikami (tylko jeśli NIE ma override)
                    $event_details = null;
                    if ($is_finished && isset($match['id']) && !$has_manual_override) {
                        $details_result = $this->get_event_details($match['id']);
                        if ($details_result['success']) {
                            $event_details = $details_result['data'];
                        }
                    }
                    
                    // Usuń "not started" z wyświetlania
                    if (strtolower($status) === 'not started') {
                        $status = '';
                    }
                    ?>
                    <?php
                    $event_id = $match['id'] ?? null;
                    $match_incidents = array();
                    $match_media = array();
                    $has_expandable = false;
                    $is_live_check = in_array(strtolower($match['status']['type'] ?? ''), ['inprogress']);
                    if (($is_finished || $is_live_check) && $event_id && $has_details) {
                        if ($show_incidents) {
                            $match_incidents = $this->get_db_incidents($event_id);
                        }
                        if ($show_media && $is_finished) {
                            $match_media = $this->get_db_media($event_id);
                        }
                        $has_expandable = (!empty($match_incidents) || !empty($match_media));
                    }
                    ?>
                    <div class="match-wrapper">
                        <div class="<?php echo $match_class; ?><?php echo $has_expandable ? ' match-expandable' : ''; ?>" <?php if ($has_expandable): ?>role="button" tabindex="0" aria-expanded="false"<?php endif; ?>>
                            <div class="match-teams">
                                <span class="home-team"><?php echo esc_html($match['homeTeam']['name']); ?></span>
                                <span class="vs">
                                    <?php
                                    $is_live_pre = $is_live_check;
                                    $has_score_pre = isset($match['homeScore']['current']);
                                    if (($is_finished && (($event_details && isset($event_details['event']['homeScore']['current'])) || $has_manual_override)) || ($is_live_pre && $has_score_pre)): ?>
                                        <span class="match-separator">-</span>
                                    <?php else: ?>
                                        vs
                                    <?php endif; ?>
                                </span>
                                <span class="away-team"><?php echo esc_html($match['awayTeam']['name']); ?></span>
                            </div>
                            <div class="match-info">
                                <?php
                                $is_live = $is_live_pre;
                                $has_live_score = isset($match['homeScore']['current'], $match['awayScore']['current']);
                                ?>
                                <?php if ($is_finished): ?>
                                    <?php if ($has_manual_override && isset($match['homeScore']['current'], $match['awayScore']['current'])): ?>
                                        <span class="match-result">
                                            <strong><?php echo $match['homeScore']['current']; ?>:<?php echo $match['awayScore']['current']; ?></strong>
                                            <?php if (isset($match['homeScore']['period1'], $match['awayScore']['period1'])): ?>
                                                <span class="halftime-result">(<?php echo $match['homeScore']['period1']; ?>:<?php echo $match['awayScore']['period1']; ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif ($event_details && isset($event_details['event']['homeScore']['current'], $event_details['event']['awayScore']['current'])): ?>
                                        <span class="match-result">
                                            <strong><?php echo $event_details['event']['homeScore']['current']; ?>:<?php echo $event_details['event']['awayScore']['current']; ?></strong> 
                                            <?php if (isset($event_details['event']['homeScore']['period1'], $event_details['event']['awayScore']['period1'])): ?>
                                                <span class="halftime-result">(<?php echo $event_details['event']['homeScore']['period1']; ?>:<?php echo $event_details['event']['awayScore']['period1']; ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                <?php elseif ($is_live && $has_live_score): ?>
                                    <?php
                                    $live_minute = null;
                                    $st = $match['statusTime'] ?? $match['time'] ?? array();
                                    $status_code = $match['status']['code'] ?? null;
                                    if ($status_code == 31) {
                                        $live_minute = 45;
                                    } elseif (!empty($st)) {
                                        $initial = $st['initial'] ?? 0;
                                        $ts_period = $st['timestamp'] ?? ($match['currentPeriodStartTimestamp'] ?? 0);
                                        if ($ts_period > 0) {
                                            $live_minute = intval(($initial + (time() - $ts_period)) / 60);
                                        }
                                    }
                                    ?>
                                    <span class="match-result match-live-result">
                                        <strong><?php echo $match['homeScore']['current']; ?>:<?php echo $match['awayScore']['current']; ?></strong>
                                        <?php if (isset($match['homeScore']['period1'], $match['awayScore']['period1'])): ?>
                                            <span class="halftime-result">(<?php echo $match['homeScore']['period1']; ?>:<?php echo $match['awayScore']['period1']; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($live_minute !== null): ?>
                                        <span class="match-live-minute"><?php echo $live_minute; ?><span class="live-pulse">'</span></span>
                                    <?php else: ?>
                                        <span class="match-status match-status-live"><?php echo esc_html($status); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="match-date"><?php echo date('d.m.Y H:i', $this->apply_timezone_offset($match['startTimestamp'])); ?></span>
                                    <?php if (!empty($status)): ?>
                                        <span class="match-status"><?php echo esc_html($status); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($has_expandable): ?>
                        <div class="match-details" style="display:none;">
                            <?php if (!empty($match_incidents)): ?>
                                <?php echo $this->render_match_timeline($match_incidents); ?>
                            <?php endif; ?>
                            <?php if (!empty($match_media)): ?>
                            <div class="match-media">
                                <?php foreach ($match_media as $media): ?>
                                    <?php
                                    $yt_id = null;
                                    $url = $media['url'] ?? '';
                                    if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $ym)) {
                                        $yt_id = $ym[1];
                                    }
                                    ?>
                                    <?php if ($yt_id): ?>
                                    <div class="media-embed">
                                        <div class="media-title"><?php echo esc_html($media['title'] ?? ''); ?></div>
                                        <div class="youtube-container">
                                            <iframe src="https://www.youtube.com/embed/<?php echo esc_attr($yt_id); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="media-link">
                                        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($media['title'] ?? 'Obejrzyj'); ?></a>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
        .terminarz-container {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .terminarz-header {
            background: #1e3d59;
            color: white;
            padding: 15px 20px;
            margin: -20px -20px 20px -20px;
            border-radius: 6px 6px 0 0;
        }
        
        .terminarz-header h3 {
            margin: 0 0 5px 0;
            color: white;
        }
        
        .last-updated {
            font-size: 0.9em;
            color: #666;
            font-style: italic;
        }
        
        .sofascore-info {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .sofascore-info h4 {
            margin-top: 0;
            color: #0299d6;
        }
        
        .match-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .match-teams {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .home-team, .away-team {
            font-weight: 600;
        }
        
        .vs {
            color: #666;
            font-size: 0.9em;
            display: flex;
            align-items: center;
        }
        
        .match-separator {
            color: #666;
            font-size: 0.9em;
        }
        
        .match-info {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
        }
        
        .match-date {
            font-weight: 500;
            color: #0299d6;
        }
        
        .match-status {
            font-size: 0.8em;
            color: #666;
        }
        .match-status-live {
            color: #dc3545;
            font-weight: 600;
        }
        .match-live-result strong {
            color: #dc3545;
        }
        .match-live-minute {
            color: #dc3545;
            font-weight: 700;
            font-size: 0.85em;
        }
        .live-pulse {
            animation: live-tick 1.5s ease-in-out infinite;
        }
        @keyframes live-tick {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }
        
        .match-result {
            font-weight: 500;
            color: #2c3e50;
            font-size: 1em;
        }
        
        .match-result strong {
            font-weight: 600;
        }
        
        .halftime-result {
            font-weight: normal;
            color: #666;
            margin-left: 5px;
        }
        
                 .terminarz-container:not(.wisla-terminarz) .wisla-match {
             background: #f0f4f8;
             border-left: 6px solid #ED8311;
         }
         
         .wisla-terminarz .wisla-match {
             background: #f0f4f8;
             border-left: 6px solid #ED8311;
         }
         
         /* Responsywność dla zwykłego terminarza */
         @media (max-width: 768px) {
             .terminarz-container {
                 margin: 15px 0;
                 padding: 15px;
             }
             
             .terminarz-header h3 {
                 font-size: 1.4em;
             }
             
             .match-item {
                 padding: 15px;
                 display: block;
             }
             
             .match-teams {
                 text-align: center;
                 margin: 10px 0;
             }
             
             .home-team, .away-team {
                 display: inline-block;
                 font-size: 1em;
                 margin: 0 5px;
             }
             
             .vs {
                 font-size: 0.8em;
                 color: #999;
                 margin: 0 5px;
             }
             
             .match-info {
                 text-align: center;
                 margin-top: 10px;
             }
             
             .match-date {
                 font-size: 0.9em;
                 display: block;
                 margin-bottom: 5px;
             }
             
             .match-status {
                 font-size: 0.8em;
             }
             
             .terminarz-container:not(.wisla-terminarz) .wisla-match {
                 border-left-width: 6px;
             }
         }
         
         @media (max-width: 480px) {
             .terminarz-container {
                 margin: 10px 0;
                 padding: 12px;
             }
             
             .terminarz-header h3 {
                 font-size: 1.2em;
             }
             
             .match-item {
                 padding: 12px;
             }
             
             .home-team, .away-team {
                 font-size: 0.9em;
                 display: block;
                 margin: 5px 0;
             }
             
             .vs {
                 font-size: 0.75em;
                 display: block;
                 margin: 5px 0;
             }
             
             .match-date {
                 font-size: 0.85em;
             }
             
             .terminarz-container:not(.wisla-terminarz) .wisla-match {
                 border-left-width: 4px;
             }
         }

        /* Expandable match details */
        .match-wrapper {
            border-bottom: 1px solid #eee;
        }
        .match-wrapper .match-item {
            border-bottom: none;
        }
        .match-expandable {
            cursor: pointer;
            transition: background 0.15s;
        }
        .match-expandable:hover {
            background: #f8f9fa;
        }
        .match-details {
            padding: 5px 0 10px 0;
            background: #fafbfc;
            border-top: 1px solid #eee;
        }

        /* Timeline */
        .match-timeline {
            padding: 0;
        }
        .timeline-period {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f0f0f0;
            padding: 6px 16px;
            font-weight: 600;
            font-size: 0.8em;
            color: #555;
            text-transform: uppercase;
            border-radius: 4px;
            margin: 2px 8px;
        }
        .timeline-row {
            display: flex;
            min-height: 34px;
            align-items: center;
        }
        .tl-left, .tl-right {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 5px 16px;
            white-space: nowrap;
        }
        .tl-left {
            justify-content: flex-start;
        }
        .tl-right {
            justify-content: flex-end;
        }
        .tl-time {
            font-weight: 600;
            color: #555;
            min-width: 36px;
            font-size: 0.88em;
        }
        .tl-home .tl-time { text-align: left; }
        .tl-away .tl-time { text-align: right; }
        .tl-icon {
            font-size: 1.05em;
            line-height: 1;
        }
        .tl-score {
            font-weight: 700;
            background: #f0f0f0;
            padding: 1px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            white-space: nowrap;
        }
        .tl-player {
            font-size: 0.9em;
            color: #222;
        }
        .tl-player strong {
            font-weight: 700;
        }
        .tl-assist {
            font-size: 0.85em;
            color: #888;
        }
        .tl-sub-out {
            font-size: 0.88em;
            color: #777;
        }
        .tl-reason {
            font-size: 0.82em;
            color: #999;
            font-style: italic;
        }

        /* Media - wyśrodkowane */
        .match-media {
            margin-top: 10px;
            text-align: center;
        }
        .media-embed {
            margin: 8px auto;
            max-width: 560px;
        }
        .media-title {
            font-size: 0.85em;
            color: #555;
            margin-bottom: 6px;
        }
        .youtube-container {
            position: relative;
            width: 100%;
            max-width: 560px;
            padding-bottom: min(315px, 56.25%);
            height: 0;
            overflow: hidden;
            border-radius: 6px;
            margin: 0 auto;
        }
        .youtube-container iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
        }
        .media-link {
            margin: 6px 0;
        }
        .media-link a {
            color: #0073aa;
            text-decoration: none;
        }
        .media-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 600px) {
            .timeline-row {
                min-height: 28px;
            }
            .tl-left, .tl-right {
                padding: 4px 10px;
                gap: 4px;
            }
            .tl-time { min-width: 30px; font-size: 0.82em; }
            .tl-player { font-size: 0.84em; }
            .tl-sub-out, .tl-assist { font-size: 0.8em; }
            .timeline-period { padding: 5px 10px; font-size: 0.75em; }
        }
        </style>

        <?php if ($has_details): ?>
        <script>
        (function() {
            document.querySelectorAll('.match-expandable').forEach(function(el) {
                el.addEventListener('click', function() {
                    var wrapper = el.closest('.match-wrapper');
                    var details = wrapper.querySelector('.match-details');
                    if (!details) return;
                    var isOpen = wrapper.classList.contains('open');
                    wrapper.classList.toggle('open');
                    el.setAttribute('aria-expanded', !isOpen);
                    details.style.display = isOpen ? 'none' : 'block';
                });
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        el.click();
                    }
                });
            });
        })();
        </script>
        <?php endif; ?>

        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderuj tabelę terminarza Wisły Płock
     */
    private function render_wisla_fixtures_table($matches, $atts) {
        // Pobierz overrides
        $overrides = get_option('sofascore_match_overrides', array());

        $show_incidents = get_option('sofascore_show_incidents', 0);
        $show_media = get_option('sofascore_show_media', 0);
        $has_details = ($show_incidents || $show_media);
        
        ob_start();
        ?>
                 <div class="terminarz-container wisla-terminarz">
             <div class="terminarz-header">
                 <h3>Ekstraklasa 2025/26</h3>
                 <div class="matches-count">Terminarz meczów Wisły Płock</div>
             </div>
            <div class="terminarz-matches">
                <?php foreach ($matches as $match): ?>
                    <?php
                    // Sprawdź status meczu i pobierz wyniki dla zakończonych
                    $status = $match['status']['description'] ?? '';
                    $is_finished = (strtolower($status) === 'ended' || strtolower($match['status']['type'] ?? '') === 'finished');
                    
                    // Sprawdź czy mecz ma ręczny override
                    $match_id = $match['id'] ?? null;
                    $has_manual_override = $match_id && isset($overrides[$match_id]);
                    
                    // Dla zakończonych meczów pobierz szczegóły z wynikami (tylko jeśli NIE ma override)
                    $event_details = null;
                    if ($is_finished && isset($match['id']) && !$has_manual_override) {
                        $details_result = $this->get_event_details($match['id']);
                        if ($details_result['success']) {
                            $event_details = $details_result['data'];
                        }
                    }
                    
                    // Usuń "not started" z wyświetlania
                    if (strtolower($status) === 'not started') {
                        $status = '';
                    }
                    
                    // Sprawdź czy Wisła gra u siebie czy na wyjeździe
                    $wisla_home = (stripos($match['homeTeam']['name'], 'Wisła') !== false);

                    $event_id = $match['id'] ?? null;
                    $match_incidents = array();
                    $match_media = array();
                    $has_expandable = false;
                    $is_live_check_w = in_array(strtolower($match['status']['type'] ?? ''), ['inprogress']);
                    if (($is_finished || $is_live_check_w) && $event_id && $has_details) {
                        if ($show_incidents) {
                            $match_incidents = $this->get_db_incidents($event_id);
                        }
                        if ($show_media && $is_finished) {
                            $match_media = $this->get_db_media($event_id);
                        }
                        $has_expandable = (!empty($match_incidents) || !empty($match_media));
                    }
                    ?>
                    <div class="match-wrapper">
                     <div class="match-item wisla-match<?php echo $has_expandable ? ' match-expandable' : ''; ?>" <?php if ($has_expandable): ?>role="button" tabindex="0" aria-expanded="false"<?php endif; ?>>
                         <div class="match-round">
                             <span class="round-number">Kolejka <?php echo esc_html($match['round_number']); ?></span>
                         </div>
                        <div class="match-teams">
                            <span class="home-team <?php echo $wisla_home ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['homeTeam']['name']); ?>
                            </span>
                            <span class="vs">
                                <?php
                                $is_live_w = in_array(strtolower($match['status']['type'] ?? ''), ['inprogress']);
                                $has_score_w = isset($match['homeScore']['current']);
                                if (($is_finished && (($event_details && isset($event_details['event']['homeScore']['current'])) || $has_manual_override)) || ($is_live_w && $has_score_w)): ?>
                                    <span class="match-separator">-</span>
                                <?php else: ?>
                                    vs
                                <?php endif; ?>
                            </span>
                            <span class="away-team <?php echo !$wisla_home ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['awayTeam']['name']); ?>
                            </span>
                        </div>
                        <div class="match-info">
                            <?php if ($is_finished): ?>
                                <?php if ($has_manual_override && isset($match['homeScore']['current'], $match['awayScore']['current'])): ?>
                                    <span class="match-result">
                                        <strong><?php echo $match['homeScore']['current']; ?>:<?php echo $match['awayScore']['current']; ?></strong>
                                        <?php if (isset($match['homeScore']['period1'], $match['awayScore']['period1'])): ?>
                                            <span class="halftime-result">(<?php echo $match['homeScore']['period1']; ?>:<?php echo $match['awayScore']['period1']; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php elseif ($event_details && isset($event_details['event']['homeScore']['current'], $event_details['event']['awayScore']['current'])): ?>
                                    <span class="match-result">
                                        <strong><?php echo $event_details['event']['homeScore']['current']; ?>:<?php echo $event_details['event']['awayScore']['current']; ?></strong> 
                                        <?php if (isset($event_details['event']['homeScore']['period1'], $event_details['event']['awayScore']['period1'])): ?>
                                            <span class="halftime-result">(<?php echo $event_details['event']['homeScore']['period1']; ?>:<?php echo $event_details['event']['awayScore']['period1']; ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif ($is_live_w && $has_score_w): ?>
                                <?php
                                $live_minute_w = null;
                                $st_w = $match['statusTime'] ?? $match['time'] ?? array();
                                $status_code_w = $match['status']['code'] ?? null;
                                if ($status_code_w == 31) {
                                    $live_minute_w = 45;
                                } elseif (!empty($st_w)) {
                                    $initial_w = $st_w['initial'] ?? 0;
                                    $ts_w = $st_w['timestamp'] ?? ($match['currentPeriodStartTimestamp'] ?? 0);
                                    if ($ts_w > 0) {
                                        $live_minute_w = intval(($initial_w + (time() - $ts_w)) / 60);
                                    }
                                }
                                ?>
                                <span class="match-result match-live-result">
                                    <strong><?php echo $match['homeScore']['current']; ?>:<?php echo $match['awayScore']['current']; ?></strong>
                                    <?php if (isset($match['homeScore']['period1'], $match['awayScore']['period1'])): ?>
                                        <span class="halftime-result">(<?php echo $match['homeScore']['period1']; ?>:<?php echo $match['awayScore']['period1']; ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($live_minute_w !== null): ?>
                                    <span class="match-live-minute"><?php echo $live_minute_w; ?><span class="live-pulse">'</span></span>
                                <?php else: ?>
                                    <span class="match-status match-status-live"><?php echo esc_html($status); ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="match-date"><?php echo date('d.m.Y H:i', $this->apply_timezone_offset($match['startTimestamp'])); ?></span>
                                <?php if (!empty($status)): ?>
                                    <span class="match-status"><?php echo esc_html($status); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($has_expandable): ?>
                    <div class="match-details" style="display:none;">
                        <?php if (!empty($match_incidents)): ?>
                            <?php echo $this->render_match_timeline($match_incidents); ?>
                        <?php endif; ?>
                        <?php if (!empty($match_media)): ?>
                        <div class="match-media">
                            <?php foreach ($match_media as $media): ?>
                                <?php
                                $yt_id = null;
                                $url = $media['url'] ?? '';
                                if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $url, $ym)) {
                                    $yt_id = $ym[1];
                                }
                                ?>
                                <?php if ($yt_id): ?>
                                <div class="media-embed">
                                    <div class="media-title"><?php echo esc_html($media['title'] ?? ''); ?></div>
                                    <div class="youtube-container">
                                        <iframe src="https://www.youtube.com/embed/<?php echo esc_attr($yt_id); ?>" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="media-link">
                                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($media['title'] ?? 'Obejrzyj'); ?></a>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
        .wisla-terminarz {
            background: white;
            max-width: 100%;
            overflow: hidden;
        }
        
        .wisla-terminarz .terminarz-header {
            background: #1e3d59;
            color: white;
            margin: -20px -20px 20px -20px;
            padding: 15px 20px;
            border-radius: 6px 6px 0 0;
        }
        
        .wisla-terminarz .terminarz-header h3 {
            color: white;
            margin: 5px 0 0 0;
        }
        
        .matches-count {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .match-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 0;
        }
        
        .match-item:last-child {
            border-bottom: none;
        }
        
        .match-round {
            flex: 0 0 auto;
        }
        
        .round-number {
            background: #1e3d59;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85em;
        }
        
        .match-teams {
            flex: 1;
            text-align: center;
            margin: 0 20px;
        }
        
        .match-info {
            flex: 0 0 auto;
            text-align: right;
        }
        
        .wisla-team {
            font-weight: 700;
            color: #1e3d59;
        }
        
        /* Responsywność dla terminarza Wisły */
        @media (max-width: 768px) {
            .wisla-terminarz {
                margin: 15px auto;
                border-radius: 8px;
                max-width: 95%;
            }
            
            .wisla-terminarz .terminarz-header {
                text-align: center;
            }
            
            .wisla-terminarz .terminarz-header h3 {
                font-size: 1.4em;
            }
            
            .matches-count {
                font-size: 0.9em;
            }
            
            .match-item {
                display: block;
                padding: 20px 15px;
                margin-bottom: 20px;
                border-bottom: 2px solid #e0e0e0;
                text-align: center;
            }
            
            .match-item:last-child {
                border-bottom: none;
                margin-bottom: 0;
            }
            
            .match-round {
                margin-bottom: 10px;
            }
            
            .round-number {
                font-size: 0.85em;
                padding: 4px 12px;
            }
            
            .match-teams {
                margin: 10px 0;
                text-align: center;
            }
            
            .home-team, .away-team {
                display: inline;
                font-size: 1.1em;
                font-weight: 600;
            }
            
            .vs {
                font-size: 0.9em;
                color: #666;
                margin: 0 8px;
            }
            
            .match-info {
                margin-top: 10px;
                text-align: center;
            }
            
            .match-date {
                font-size: 1em;
                color: #0299d6;
                font-weight: 500;
            }
            
            .match-status {
                font-size: 0.85em;
                color: #666;
                margin-top: 5px;
                display: block;
            }
        }
        
        @media (max-width: 480px) {
            .wisla-terminarz {
                margin: 10px auto;
                max-width: 98%;
            }
            
            .wisla-terminarz .terminarz-header {
                padding: 12px 15px;
            }
            
            .wisla-terminarz .terminarz-header h3 {
                font-size: 1.2em;
            }
            
            .matches-count {
                font-size: 0.85em;
            }
            
            .match-item {
                padding: 15px 12px;
                margin-bottom: 18px;
            }
            
            .match-round {
                margin-bottom: 8px;
            }
            
            .round-number {
                font-size: 0.8em;
                padding: 3px 10px;
            }
            
            .match-teams {
                margin: 8px 0;
            }
            
            .home-team, .away-team {
                font-size: 1em;
                display: block;
                margin: 3px 0;
            }
            
            .vs {
                font-size: 0.8em;
                margin: 3px 0;
                display: block;
            }
            
            .match-date {
                font-size: 0.9em;
            }
            
            .match-info {
                margin-top: 8px;
            }
        }

        /* Expandable match details - Wisła */
        .wisla-terminarz .match-wrapper {
            border-bottom: 1px solid #eee;
        }
        .wisla-terminarz .match-wrapper .match-item {
            border-bottom: none;
        }
        .wisla-terminarz .match-expandable {
            cursor: pointer;
            transition: background 0.15s;
        }
        .wisla-terminarz .match-expandable:hover {
            background: #f0f4f8;
        }
        .match-details {
            padding: 5px 0 10px 0;
            background: #fafbfc;
            border-top: 1px solid #eee;
        }

        .match-timeline { padding: 0; }
        .timeline-period {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f0f0f0;
            padding: 6px 16px;
            font-weight: 600;
            font-size: 0.8em;
            color: #555;
            text-transform: uppercase;
            border-radius: 4px;
            margin: 2px 8px;
        }
        .timeline-row { display: flex; min-height: 34px; align-items: center; }
        .tl-left, .tl-right {
            flex: 1; display: flex; align-items: center; gap: 6px; padding: 5px 16px; white-space: nowrap;
        }
        .tl-left { justify-content: flex-start; }
        .tl-right { justify-content: flex-end; }
        .tl-time { font-weight: 600; color: #555; min-width: 36px; font-size: 0.88em; }
        .tl-home .tl-time { text-align: left; }
        .tl-away .tl-time { text-align: right; }
        .tl-icon { font-size: 1.05em; line-height: 1; }
        .tl-score {
            font-weight: 700; background: #f0f0f0; padding: 1px 8px;
            border-radius: 4px; font-size: 0.85em; white-space: nowrap;
        }
        .tl-player { font-size: 0.9em; color: #222; }
        .tl-player strong { font-weight: 700; }
        .tl-assist { font-size: 0.85em; color: #888; }
        .tl-sub-out { font-size: 0.88em; color: #777; }
        .tl-reason { font-size: 0.82em; color: #999; font-style: italic; }

        .match-media { margin-top: 10px; text-align: center; }
        .media-embed { margin: 8px auto; max-width: 560px; }
        .media-title { font-size: 0.85em; color: #555; margin-bottom: 6px; }
        .youtube-container {
            position: relative; width: 100%; max-width: 560px;
            padding-bottom: min(315px, 56.25%); height: 0;
            overflow: hidden; border-radius: 6px; margin: 0 auto;
        }
        .youtube-container iframe {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        }
        .media-link { margin: 6px 0; }
        .media-link a { color: #0073aa; text-decoration: none; }
        .media-link a:hover { text-decoration: underline; }

        @media (max-width: 600px) {
            .timeline-row { min-height: 28px; }
            .tl-left, .tl-right { padding: 4px 10px; gap: 4px; }
            .tl-time { min-width: 30px; font-size: 0.82em; }
            .tl-player { font-size: 0.84em; }
            .tl-sub-out, .tl-assist { font-size: 0.8em; }
            .timeline-period { padding: 5px 10px; font-size: 0.75em; }
        }
        </style>

        <?php if ($has_details): ?>
        <script>
        (function() {
            document.querySelectorAll('.wisla-terminarz .match-expandable').forEach(function(el) {
                el.addEventListener('click', function() {
                    var wrapper = el.closest('.match-wrapper');
                    var details = wrapper.querySelector('.match-details');
                    if (!details) return;
                    var isOpen = wrapper.classList.contains('open');
                    wrapper.classList.toggle('open');
                    el.setAttribute('aria-expanded', !isOpen);
                    details.style.display = isOpen ? 'none' : 'block';
                });
                el.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        el.click();
                    }
                });
            });
        })();
        </script>
        <?php endif; ?>

        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Panel administracyjny
     */
    public function admin_menu() {
        // Główne menu
        add_menu_page(
            'Soccer ScrAPI',
            'Soccer ScrAPI',
            'manage_options',
            'sofascore-ekstraklasa',
            array($this, 'admin_page'),
            'dashicons-media-spreadsheet',
            30
        );
        
        // === EKSTRAKLASA ===
        // Podmenu - Terminarz Ekstraklasa
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Terminarz Ekstraklasa - Zarządzanie rundami',
            'Terminarz Ekstraklasa',
            'manage_options',
            'sofascore-terminarz',
            array($this, 'terminarz_module_page')
        );
        
        // Podmenu - Tabela Ekstraklasa
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Tabela Ekstraklasa - Zarządzanie',
            'Tabela Ekstraklasa',
            'manage_options',
            'sofascore-tabela',
            array($this, 'tabela_module_page')
        );
        
        // Podmenu - Zamrożone Tabele
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Zamrożone Tabele - Zarządzanie',
            'Zamrożone Tabele',
            'manage_options',
            'sofascore-frozen-tables',
            array($this, 'frozen_tables_page')
        );
        
        // === WISŁA II PŁOCK - III LIGA ===
        // Podmenu - Wisła II - Główne
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Wisła II Płock - III Liga',
            'Wisła II Płock',
            'manage_options',
            'wisla-ii-main',
            array($this, 'wisla_ii_main_page')
        );
        
        // Podmenu - Kadra Wisły II
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Kadra Wisły II - Upload CSV',
            'Kadra Wisły II',
            'manage_options',
            'wisla-ii-kadra',
            array($this, 'wisla_ii_kadra_page')
        );
        
        // === KADRA WISŁY PŁOCK - EKSTRAKLASA ===
        // Podmenu - Kadra Wisły Płock (Ekstraklasa)
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Kadra Wisły Płock - Ekstraklasa',
            'Kadra Wisły Płock',
            'manage_options',
            'wisla-kadra-admin',
            array($this, 'wisla_kadra_admin_page')
        );
        
        // === EDYCJA MECZÓW ===
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Edycja meczów - Ręczne korekty',
            'Edycja meczów',
            'manage_options',
            'sofascore-edit-matches',
            array($this, 'edit_matches_page')
        );
        
        // === USTAWIENIA ===
        add_submenu_page(
            'sofascore-ekstraklasa',
            'Ustawienia Soccer ScrAPI',
            'Ustawienia',
            'manage_options',
            'sofascore-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Ładuj skrypty admin
     */
    public function admin_enqueue_scripts($hook) {
        // Ładuj media library tylko na stronach tego pluginu
        if (strpos($hook, 'sofascore') !== false || strpos($hook, 'wisla') !== false) {
            wp_enqueue_media();
        }
    }
    
    /**
     * Ładuj style frontend
     */
    public function enqueue_frontend_styles() {
        // Ładuj style kadry z wyższą specyfikacją CSS
        if (!is_admin()) {
            wp_add_inline_style('wp-block-library', $this->get_improved_kadra_css());
        }
    }
    
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>🏆 Soccer ScrAPI v1.4.6 - Ustawienia</h1>
            
            <div class="card">
                <h2>🏆 Moduły pluginu</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #007cba;">
                        <h3>⚽ Ekstraklasa</h3>
                        <p><strong>Źródło:</strong> SofaScore API (RapidAPI)</p>
                        <p><strong>Funkcje:</strong> Tabela, Terminarz, Kadra Wisły</p>
                        <p><strong>Status:</strong> Wymaga klucza API</p>
                    </div>
                    <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #1e3d59;">
                        <h3>🥅 Wisła II Płock - III Liga</h3>
                        <p><strong>Źródło:</strong> 90minut.pl (scraping)</p>
                        <p><strong>Funkcje:</strong> Tabela, Terminarz, Kadra</p>
                        <p><strong>Status:</strong> Gotowy do użycia</p>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2>🔍 Test połączenia z API Ekstraklasy</h2>
                <p>Sprawdź czy plugin może połączyć się z SofaScore API.</p>
                <button type="button" class="button button-primary" id="test-connection">Testuj połączenie</button>
                <div id="test-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="card">
                <h2>📋 Wszystkie shortcodes</h2>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <h3>⚽ Ekstraklasa</h3>
                        <ul>
                            <li><code>[tabela_ekstraklasa]</code> - Tabela ligowa</li>
                            <li><code>[tabela_ekstraklasa_zamrozona id="nazwa"]</code> - Zamrożona tabela → <a href="<?php echo admin_url('admin.php?page=sofascore-frozen-tables'); ?>">Zarządzaj</a></li>
                            <li><code>[terminarz_ekstraklasa round="1"]</code> - Terminarz rundy</li>
                            <li><code>[terminarz_wisla]</code> - Terminarz Wisły Płock</li>
                            <li><code>[wisla_kadra]</code> - Kadra Wisły Płock → <a href="<?php echo admin_url('admin.php?page=wisla-kadra-admin'); ?>">Zarządzaj</a></li>
                        </ul>
                    </div>
                    <div>
                        <h3>🥅 Wisła II Płock</h3>
                        <ul>
                            <li><code>[tabela_3_liga]</code> - Tabela III ligi</li>
                            <li><code>[terminarz_3_liga]</code> - Terminarz III ligi</li>
                            <li><code>[terminarz_wisla_ii]</code> - Terminarz Wisły II</li>
                            <li><code>[wisla_ii_kadra]</code> - Kadra Wisły II → <a href="<?php echo admin_url('admin.php?page=wisla-ii-kadra'); ?>">Zarządzaj</a></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2>⚙️ Informacje o pluginie</h2>
                <p><strong>Wersja:</strong> <?php echo SOFASCORE_PLUGIN_VERSION; ?></p>
                <p><strong>Cache:</strong> Dane są cache'owane na 30 minut dla lepszej wydajności</p>
                <p><strong>Ekstraklasa:</strong> Zarządzanie rundami przez "Terminarz Ekstraklasa"</p>
                <p><strong>Wisła II:</strong> Automatyczne pobieranie z 90minut.pl</p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-connection').click(function() {
                var button = $(this);
                var result = $('#test-result');
                
                button.prop('disabled', true).text('Testowanie...');
                result.html('');
                
                $.post(ajaxurl, {
                    action: 'test_sofascore_connection',
                    nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        result.html('<div class="notice notice-success"><p>✅ ' + response.message + '</p></div>');
                    } else {
                        result.html('<div class="notice notice-error"><p>❌ ' + response.message + '</p></div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Testuj połączenie');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Strona modułu Terminarz
     */
    public function terminarz_module_page() {
        $current_season = '76477';
        $current_tournament = '202';
        
        // Pobierz listę zapisanych rund
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        ?>
        <div class="wrap">
            <h1>Moduł Terminarz - Zarządzanie Rundami</h1>
            
            <div class="card">
                <h2>Konfiguracja</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Liga</th>
                        <td>
                            <select id="tournament-select">
                                <option value="202">Ekstraklasa</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sezon</th>
                        <td>
                            <select id="season-select">
                                <option value="76477">2024/25</option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="card">
                <h2>Zarządzanie Rundami</h2>
                <p>Wybierz rundy do pobrania/aktualizacji. Ekstraklasa ma 34 rundy w sezonie.</p>
                
                <div style="margin-bottom: 20px;">
                    <button type="button" class="button button-primary" id="load-rounds-list">
                        Załaduj listę rund
                    </button>
                    <span id="loading-rounds" style="display: none;">Ładowanie...</span>
                </div>
                
                <div id="rounds-container">
                    <p>Kliknij "Załaduj listę rund" aby zobaczyć dostępne rundy.</p>
                </div>
            </div>
            
            <div class="card">
                <h2>Zapisane dane</h2>
                <div id="saved-rounds-list">
                    <?php if (empty($saved_rounds)): ?>
                        <p>Brak zapisanych danych rund.</p>
                    <?php else: ?>
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Runda</th>
                                    <th>Ostatnia aktualizacja</th>
                                    <th>Liczba meczów</th>
                                    <th>Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($saved_rounds as $round => $data): ?>
                                    <tr>
                                        <td><strong>Runda <?php echo esc_html($round); ?></strong></td>
                                        <td><?php echo esc_html($data['updated'] ?? 'Nieznana'); ?></td>
                                        <td><?php echo esc_html($data['matches_count'] ?? 'N/A'); ?></td>
                                        <td>
                                            <button class="button button-small update-round" data-round="<?php echo esc_attr($round); ?>">
                                                Aktualizuj
                                            </button>
                                            <button class="button button-small button-link-delete delete-round" data-round="<?php echo esc_attr($round); ?>">
                                                Usuń
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card">
                <h2>Shortcodes</h2>
                <p><strong>Terminarz kolejki:</strong></p>
                <code>[terminarz_ekstraklasa round="NUMER_RUNDY"]</code>
                <p><strong>Przykłady:</strong></p>
                <ul>
                    <li><code>[terminarz_ekstraklasa round="1"]</code> - Runda 1</li>
                    <li><code>[terminarz_ekstraklasa round="15"]</code> - Runda 15</li>
                </ul>
                
                <p><strong>Terminarz Wisły Płock:</strong></p>
                <code>[terminarz_wisla]</code>
                <p>Wyświetla wszystkie mecze Wisły Płock z pobranych rund (automatycznie sortowane według daty).</p>
                <p><strong>Parametry:</strong></p>
                <ul>
                    <li><code>limit="50"</code> - maksymalna liczba meczów (domyślnie 50)</li>
                </ul>
            </div>
        </div>
        
        <style>
        .rounds-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
        }
        
        .round-item {
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            background: #f9f9f9;
            text-align: center;
        }
        
        .round-item.has-data {
            background: #e7f7e7;
            border-color: #28a745;
        }
        
        .round-item h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .round-status {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 10px;
        }
        
        .round-item.has-data .round-status {
            color: #28a745;
            font-weight: 600;
        }
        
        .round-actions {
            margin-top: 10px;
        }
        
        .round-actions .button {
            margin: 2px;
        }
        
        #rounds-container {
            min-height: 100px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Załaduj listę rund
            $('#load-rounds-list').click(function() {
                var button = $(this);
                var loading = $('#loading-rounds');
                var container = $('#rounds-container');
                
                button.prop('disabled', true);
                loading.show();
                
                $.post(ajaxurl, {
                    action: 'load_rounds_list',
                    tournament: $('#tournament-select').val(),
                    season: $('#season-select').val(),
                    nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        container.html(response.data.html);
                    } else {
                        container.html('<div class="notice notice-error"><p>Błąd: ' + response.data.message + '</p></div>');
                    }
                }).always(function() {
                    button.prop('disabled', false);
                    loading.hide();
                });
            });
            
            // Aktualizuj rundę (delegated event)
            $(document).on('click', '.update-round-btn', function() {
                var button = $(this);
                var round = button.data('round');
                var roundItem = button.closest('.round-item');
                
                button.prop('disabled', true).text('Pobieranie...');
                
                $.post(ajaxurl, {
                    action: 'update_round_data',
                    tournament: $('#tournament-select').val(),
                    season: $('#season-select').val(),
                    round: round,
                    nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        roundItem.addClass('has-data');
                        roundItem.find('.round-status').text('Dane pobrane: ' + response.data.updated);
                        button.text('Aktualizuj').removeClass('button-primary').addClass('button-secondary');
                        
                        // Odśwież listę zapisanych rund
                        location.reload();
                    } else {
                        alert('Błąd: ' + response.data.message);
                    }
                }).always(function() {
                    button.prop('disabled', false);
                });
            });
            
            // Usuń dane rundy
            $(document).on('click', '.delete-round', function() {
                var button = $(this);
                var round = button.data('round');
                
                if (!confirm('Czy na pewno chcesz usunąć dane dla rundy ' + round + '?')) {
                    return;
                }
                
                $.post(ajaxurl, {
                    action: 'delete_round_data',
                    round: round,
                    nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Błąd: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Strona modułu Tabela
     */
    public function tabela_module_page() {
        ?>
        <div class="wrap">
            <h1>Moduł Tabela - Zarządzanie</h1>
            
            <div class="card">
                <h2>Aktualizacja tabeli ligowej</h2>
                <p>Zarządzaj danymi tabeli Ekstraklasy.</p>
                
                <button type="button" class="button button-primary" id="update-table">
                    Aktualizuj tabelę
                </button>
                <div id="table-result" style="margin-top: 10px;"></div>
            </div>
            
            <div class="card">
                <h2>Shortcodes</h2>
                <p>Użyj tych shortcodes do wyświetlania tabel:</p>
                <ul>
                    <li><code>[tabela_ekstraklasa]</code> - Aktualna tabela ligowa</li>
                    <li><code>[tabela_ekstraklasa_zamrozona id="nazwa"]</code> - Zamrożona tabela → <a href="<?php echo admin_url('admin.php?page=sofascore-frozen-tables'); ?>">Zarządzaj</a></li>
                </ul>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#update-table').click(function() {
                var button = $(this);
                var result = $('#table-result');
                
                button.prop('disabled', true).text('Aktualizowanie...');
                result.html('');
                
                // Wyczyść cache tabeli
                $.post(ajaxurl, {
                    action: 'test_sofascore_connection', // Używamy istniejącej akcji
                    nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                }, function(response) {
                    if (response.success) {
                        result.html('<div class="notice notice-success"><p>✅ Tabela została zaktualizowana!</p></div>');
                    } else {
                        result.html('<div class="notice notice-error"><p>❌ Błąd aktualizacji</p></div>');
                    }
                }).always(function() {
                    button.prop('disabled', false).text('Aktualizuj tabelę');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Strona zarządzania zamrożonymi tabelami
     */
    public function frozen_tables_page() {
        // Obsługa usuwania tabeli
        if (isset($_POST['delete_table']) && isset($_POST['table_id'])) {
            $table_id = sanitize_text_field($_POST['table_id']);
            $option_key = 'sofascore_frozen_table_' . sanitize_key($table_id);
            
            if (delete_option($option_key)) {
                echo '<div class="notice notice-success"><p>✅ Zamrożona tabela "' . esc_html($table_id) . '" została usunięta.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ Nie udało się usunąć tabeli.</p></div>';
            }
        }
        
        // Pobierz wszystkie zamrożone tabele
        global $wpdb;
        $frozen_tables = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'sofascore_frozen_table_%'",
            ARRAY_A
        );
        
        ?>
        <div class="wrap">
            <h1>Zamrożone Tabele - Zarządzanie</h1>
            
            <div class="card">
                <h2>Co to są zamrożone tabele?</h2>
                <p>Zamrożone tabele pozwalają zapisać aktualny stan tabeli Ekstraklasy i wyświetlać go bez aktualizacji. Jest to przydatne do artykułów historycznych.</p>
                
                <h3>Jak używać:</h3>
                <ol>
                    <li><strong>Zapisanie tabeli:</strong> <code>[tabela_ekstraklasa_zamrozona id="nazwa_tabeli" zapisz="tak"]</code></li>
                    <li><strong>Wyświetlenie zapisanej tabeli:</strong> <code>[tabela_ekstraklasa_zamrozona id="nazwa_tabeli"]</code></li>
                </ol>
                
                <p><strong>Przykład:</strong> Jeśli chcesz zapisać tabelę na początku sezonu, użyj:<br>
                <code>[tabela_ekstraklasa_zamrozona id="poczatek_sezonu_2024" zapisz="tak"]</code></p>
            </div>
            
            <div class="card">
                <h2>Istniejące zamrożone tabele</h2>
                
                <?php if (empty($frozen_tables)): ?>
                    <p>Brak zapisanych zamrożonych tabel.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ID Tabeli</th>
                                <th>Data utworzenia</th>
                                <th>Sezon</th>
                                <th>Shortcode</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($frozen_tables as $table): ?>
                                <?php
                                $table_id = str_replace('sofascore_frozen_table_', '', $table['option_name']);
                                $table_data = maybe_unserialize($table['option_value']);
                                $created_at = isset($table_data['created_at']) ? $table_data['created_at'] : 'Nieznana';
                                $season = isset($table_data['season']) ? $table_data['season'] : 'Nieznany';
                                ?>
                                <tr>
                                    <td><strong><?php echo esc_html($table_id); ?></strong></td>
                                    <td><?php echo esc_html($created_at); ?></td>
                                    <td><?php echo esc_html($season); ?></td>
                                    <td><code>[tabela_ekstraklasa_zamrozona id="<?php echo esc_attr($table_id); ?>"]</code></td>
                                    <td>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Czy na pewno chcesz usunąć tę zamrożoną tabelę?');">
                                            <input type="hidden" name="table_id" value="<?php echo esc_attr($table_id); ?>">
                                            <input type="submit" name="delete_table" value="Usuń" class="button button-secondary">
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h2>Tworzenie nowej zamrożonej tabeli</h2>
                <p>Aby utworzyć nową zamrożoną tabelę, dodaj shortcode do swojego wpisu/strony:</p>
                <p><code>[tabela_ekstraklasa_zamrozona id="UNIKALNY_ID" zapisz="tak"]</code></p>
                <p><strong>Uwaga:</strong> Zamień "UNIKALNY_ID" na własną nazwę (np. "poczatek_rundy_wiosennej").</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Zaplanuj automatyczne aktualizacje
     */
    public function schedule_updates() {
        if (!wp_next_scheduled('sofascore_update_data')) {
            wp_schedule_event(time(), 'hourly', 'sofascore_update_data');
        }
        
        // Zaplanuj auto-refresh jeśli jest włączony (co 1 minutę -- smart scheduler sam decyduje kiedy odpytywać)
        if (get_option('sofascore_auto_refresh_enabled', 0)) {
            if (!wp_next_scheduled('sofascore_auto_refresh')) {
                wp_schedule_event(time(), 'every_minute', 'sofascore_auto_refresh');
            }
        }

        if (!wp_next_scheduled('sofascore_check_media')) {
            wp_schedule_event(time(), 'hourly', 'sofascore_check_media');
        }
    }
    
    /**
     * Aktualizuj wszystkie dane
     */
    public function update_all_data() {
        // Wyczyść cache
        delete_transient('sofascore_standings_76477');
        
        // Pobierz nowe dane (zostaną zapisane w cache przy pierwszym użyciu shortcode)
        $this->get_standings();
        
        update_option('sofascore_last_update', current_time('mysql'));
    }
    
    /**
     * Aktywacja pluginu
     */
    public function activate() {
        // Zaplanuj pierwsze zadania
        wp_schedule_event(time(), 'hourly', 'sofascore_update_data');
        wp_schedule_event(time(), 'hourly', 'sofascore_check_media');
        
        // Utwórz tabele bazy danych
        $this->create_database_tables();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Tworzenie tabel bazy danych (bezpieczne)
     */
    private function create_database_tables() {
        global $wpdb;
        
        // Dodaj prefiks WordPress
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        // Sprawdź czy tabela już istnieje
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $table_name
        )) === $table_name;
        
        if (!$table_exists) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE {$table_name} (
                id int(11) NOT NULL AUTO_INCREMENT,
                api_id varchar(50) DEFAULT NULL,
                team_id varchar(10) DEFAULT '3122',
                imie_nazwisko varchar(255) NOT NULL,
                pozycja varchar(50) DEFAULT NULL,
                numer int(3) DEFAULT NULL,
                wiek int(3) DEFAULT NULL,
                data_urodzenia date DEFAULT NULL,
                kraj varchar(10) DEFAULT NULL,
                noga varchar(20) DEFAULT NULL,
                kontrakt_do date DEFAULT NULL,
                zdjecie_id int(11) DEFAULT NULL,
                status varchar(20) DEFAULT 'active',
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY api_id_unique (api_id),
                KEY team_id_index (team_id),
                KEY status_index (status)
            ) {$charset_collate};";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            // Sprawdź czy tabela została utworzona
            $created = $wpdb->get_var($wpdb->prepare(
                "SHOW TABLES LIKE %s", 
                $table_name
            )) === $table_name;
            
            if ($created) {
                update_option('sofascore_db_version', '1.0');
                error_log('SofaScore Plugin: Tabela ' . $table_name . ' została utworzona pomyślnie.');
            } else {
                error_log('SofaScore Plugin: BŁĄD - Nie udało się utworzyć tabeli ' . $table_name);
            }
        } else {
            error_log('SofaScore Plugin: Tabela ' . $table_name . ' już istnieje - pomijam tworzenie.');
        }

        // --- Tabela incidents (gole, kartki, zmiany) ---
        $this->create_table_if_not_exists(
            $wpdb->prefix . 'sofascore_incidents',
            "CREATE TABLE {$wpdb->prefix}sofascore_incidents (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                event_id bigint(20) NOT NULL,
                incident_type varchar(30) NOT NULL,
                time int(4) DEFAULT NULL,
                added_time int(4) DEFAULT NULL,
                is_home tinyint(1) DEFAULT NULL,
                player_name varchar(255) DEFAULT NULL,
                player_id bigint(20) DEFAULT NULL,
                assist_name varchar(255) DEFAULT NULL,
                assist_id bigint(20) DEFAULT NULL,
                incident_class varchar(30) DEFAULT NULL,
                home_score int(3) DEFAULT NULL,
                away_score int(3) DEFAULT NULL,
                description varchar(255) DEFAULT NULL,
                raw_json text DEFAULT NULL,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id_idx (event_id),
                KEY incident_type_idx (incident_type)
            ) {$wpdb->get_charset_collate()};"
        );

        // --- Tabela media (YouTube highlights) ---
        $this->create_table_if_not_exists(
            $wpdb->prefix . 'sofascore_media',
            "CREATE TABLE {$wpdb->prefix}sofascore_media (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                event_id bigint(20) NOT NULL,
                title varchar(500) DEFAULT NULL,
                subtitle varchar(500) DEFAULT NULL,
                url varchar(1000) NOT NULL,
                thumbnail_url varchar(1000) DEFAULT NULL,
                media_type int(4) DEFAULT NULL,
                source_url varchar(1000) DEFAULT NULL,
                api_media_id bigint(20) DEFAULT NULL,
                is_key_highlight tinyint(1) DEFAULT 0,
                created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_media_unique (event_id, api_media_id),
                KEY event_id_idx (event_id)
            ) {$wpdb->get_charset_collate()};"
        );

        // --- Tabela matches (warstwa zarządzania meczami Ekstraklasy + mapowanie FP) ---
        $this->create_table_if_not_exists(
            $wpdb->prefix . 'sofascore_matches',
            "CREATE TABLE {$wpdb->prefix}sofascore_matches (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                event_id bigint(20) NOT NULL,
                round int(3) NOT NULL,
                home_team varchar(255) NOT NULL,
                away_team varchar(255) NOT NULL,
                home_score tinyint DEFAULT NULL,
                away_score tinyint DEFAULT NULL,
                status varchar(30) DEFAULT NULL,
                start_timestamp int(11) DEFAULT NULL,
                fp_match_id int(11) DEFAULT NULL,
                fp_synced tinyint(1) DEFAULT 0,
                fp_synced_at datetime DEFAULT NULL,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY event_id_unique (event_id),
                KEY fp_match_id_idx (fp_match_id),
                KEY round_idx (round),
                KEY status_idx (status)
            ) {$wpdb->get_charset_collate()};"
        );

        // --- Tabela sync log (audit trail synchronizacji z Football Pool) ---
        $this->create_table_if_not_exists(
            $wpdb->prefix . 'sofascore_fp_sync_log',
            "CREATE TABLE {$wpdb->prefix}sofascore_fp_sync_log (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                event_id bigint(20) NOT NULL,
                fp_match_id int(11) NOT NULL,
                action varchar(20) NOT NULL,
                home_score_src tinyint DEFAULT NULL,
                away_score_src tinyint DEFAULT NULL,
                home_score_dst tinyint DEFAULT NULL,
                away_score_dst tinyint DEFAULT NULL,
                result varchar(20) NOT NULL,
                message text DEFAULT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY event_id_idx (event_id),
                KEY created_at_idx (created_at)
            ) {$wpdb->get_charset_collate()};"
        );

        // Migracja danych z saved_rounds do sofascore_matches (jednorazowa)
        $this->migrate_saved_rounds_to_matches_table();
    }

    private function create_table_if_not_exists($table_name, $sql) {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        if (!$exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            error_log('SofaScore Plugin: Tabela ' . $table_name . ' utworzona.');
        }
    }

    /**
     * Jednorazowa migracja meczów z saved_rounds do sofascore_matches.
     * Bezpieczna: używa INSERT IGNORE, więc nie nadpisze istniejących danych.
     */
    private function migrate_saved_rounds_to_matches_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'sofascore_matches';
        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($existing > 0) {
            return;
        }

        $saved_rounds = get_option('sofascore_saved_rounds', array());
        if (empty($saved_rounds)) {
            return;
        }

        $count = 0;
        foreach ($saved_rounds as $round_number => $round_data) {
            $events = $round_data['data']['events'] ?? array();
            foreach ($events as $event) {
                $inserted = $this->upsert_match_to_table($event, intval($round_number));
                if ($inserted) {
                    $count++;
                }
            }
        }

        if ($count > 0) {
            error_log("SofaScore Plugin: Zmigrowano {$count} meczów do sofascore_matches.");
        }
    }

    /**
     * Wstawia lub aktualizuje mecz w tabeli sofascore_matches.
     * Nie dotyka kolumn fp_match_id / fp_synced (te zarządza admin ręcznie).
     */
    public function upsert_match_to_table($event, $round) {
        global $wpdb;

        $event_id = $event['id'] ?? null;
        if (!$event_id) {
            return false;
        }

        $table = $wpdb->prefix . 'sofascore_matches';

        $home_team = $event['homeTeam']['name'] ?? 'N/A';
        $away_team = $event['awayTeam']['name'] ?? 'N/A';
        $status = $event['status']['description'] ?? null;
        $start_ts = $event['startTimestamp'] ?? null;
        $home_score = $event['homeScore']['current'] ?? null;
        $away_score = $event['awayScore']['current'] ?? null;

        $overrides = get_option('sofascore_match_overrides', array());
        if (isset($overrides[$event_id])) {
            $o = $overrides[$event_id];
            $home_team = $o['home_team'] ?? $home_team;
            $away_team = $o['away_team'] ?? $away_team;
            $home_score = $o['home_score'] ?? $home_score;
            $away_score = $o['away_score'] ?? $away_score;
            $status = $o['status'] ?? $status;
            $start_ts = $o['timestamp'] ?? $start_ts;
        }

        $data = array(
            'event_id'        => $event_id,
            'round'           => $round,
            'home_team'       => $home_team,
            'away_team'       => $away_team,
            'home_score'      => $home_score,
            'away_score'      => $away_score,
            'status'          => $status,
            'start_timestamp' => $start_ts,
            'updated_at'      => current_time('mysql'),
        );

        $formats = array('%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s');

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE event_id = %d", $event_id
        ));

        if ($exists) {
            unset($data['event_id']);
            array_shift($formats);
            $wpdb->update($table, $data, array('event_id' => $event_id), $formats, array('%d'));
        } else {
            $wpdb->insert($table, $data, $formats);
        }

        return true;
    }

    /**
     * Zapisuje wpis do audyt logu synchronizacji z Football Pool.
     */
    public function log_fp_sync($event_id, $fp_match_id, $action, $result, $scores = array(), $message = '') {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'sofascore_fp_sync_log',
            array(
                'event_id'       => $event_id,
                'fp_match_id'    => $fp_match_id,
                'action'         => $action,
                'home_score_src' => $scores['home_src'] ?? null,
                'away_score_src' => $scores['away_src'] ?? null,
                'home_score_dst' => $scores['home_dst'] ?? null,
                'away_score_dst' => $scores['away_dst'] ?? null,
                'result'         => $result,
                'message'        => $message,
                'created_at'     => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );
    }

    public function fetch_and_store_incidents($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_incidents';

        $result = $this->get_event_incidents($event_id);
        if (!$result['success']) {
            error_log("SofaScore: Nie udalo sie pobrac incidents dla event {$event_id}");
            return false;
        }

        $incidents = $result['data']['incidents'] ?? array();
        if (empty($incidents)) return 0;

        $wpdb->delete($table, array('event_id' => $event_id), array('%d'));

        $count = 0;
        foreach ($incidents as $inc) {
            $type = $inc['incidentType'] ?? null;
            if (!$type || in_array($type, array('period'), true)) continue;

            $player = $inc['player'] ?? null;
            $assist = $inc['assist1'] ?? null;

            if ($type === 'substitution') {
                $pin  = $inc['playerIn'] ?? null;
                $pout = $inc['playerOut'] ?? null;
                if ($pin)  $player = $pin;
                if ($pout) $assist = $pout;
            }

            $wpdb->insert($table, array(
                'event_id'       => $event_id,
                'incident_type'  => $type,
                'time'           => $inc['time'] ?? null,
                'added_time'     => $inc['addedTime'] ?? null,
                'is_home'        => isset($inc['isHome']) ? ($inc['isHome'] ? 1 : 0) : null,
                'player_name'    => $player ? ($player['shortName'] ?? $player['name'] ?? null) : null,
                'player_id'      => $player['id'] ?? null,
                'assist_name'    => $assist ? ($assist['shortName'] ?? $assist['name'] ?? null) : null,
                'assist_id'      => $assist['id'] ?? null,
                'incident_class' => $inc['incidentClass'] ?? null,
                'home_score'     => $inc['homeScore'] ?? null,
                'away_score'     => $inc['awayScore'] ?? null,
                'description'    => $inc['description'] ?? ($inc['reason'] ?? null),
                'raw_json'       => wp_json_encode($inc),
            ), array('%d','%s','%d','%d','%d','%s','%d','%s','%d','%s','%d','%d','%s','%s'));

            $count++;
        }

        error_log("SofaScore: Zapisano {$count} incidents dla event {$event_id}");
        return $count;
    }

    public function fetch_and_store_media($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_media';

        $result = $this->get_event_media($event_id);
        if (!$result['success']) {
            error_log("SofaScore: Nie udalo sie pobrac media dla event {$event_id}");
            return false;
        }

        $media_items = $result['data']['media'] ?? array();
        if (empty($media_items)) return 0;

        $count = 0;
        foreach ($media_items as $m) {
            $api_id = $m['id'] ?? null;
            $url    = $m['url'] ?? null;
            if (!$url) continue;

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE event_id = %d AND api_media_id = %d",
                $event_id, $api_id
            ));

            $data = array(
                'event_id'        => $event_id,
                'title'           => $m['title'] ?? null,
                'subtitle'        => $m['subtitle'] ?? null,
                'url'             => $url,
                'thumbnail_url'   => $m['thumbnailUrl'] ?? null,
                'media_type'      => $m['mediaType'] ?? null,
                'source_url'      => $m['sourceUrl'] ?? null,
                'api_media_id'    => $api_id,
                'is_key_highlight' => !empty($m['keyHighlight']) ? 1 : 0,
            );

            if ($existing) {
                $wpdb->update($table, $data, array('id' => $existing));
            } else {
                $wpdb->insert($table, $data);
                $count++;
            }
        }

        error_log("SofaScore: Zapisano/zaktualizowano media dla event {$event_id} (nowe: {$count})");
        return $count;
    }

    public function get_db_incidents($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_incidents';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d ORDER BY time ASC, added_time ASC",
            $event_id
        ), ARRAY_A);
    }

    public function get_db_media($event_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_media';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE event_id = %d ORDER BY is_key_highlight DESC, id ASC",
            $event_id
        ), ARRAY_A);
    }

    private function render_match_timeline($incidents) {
        $first_half = array();
        $second_half = array();

        foreach ($incidents as $inc) {
            if (($inc['incident_type'] ?? '') === 'injuryTime') continue;
            $t = intval($inc['time'] ?? 0);
            if ($t <= 45) {
                $first_half[] = $inc;
            } else {
                $second_half[] = $inc;
            }
        }

        $fh_home = 0; $fh_away = 0;
        foreach ($first_half as $inc) {
            if ($inc['incident_type'] === 'goal' && $inc['home_score'] !== null) {
                $fh_home = intval($inc['home_score']);
                $fh_away = intval($inc['away_score']);
            }
        }

        $ft_home = $fh_home; $ft_away = $fh_away;
        foreach ($second_half as $inc) {
            if ($inc['incident_type'] === 'goal' && $inc['home_score'] !== null) {
                $ft_home = intval($inc['home_score']);
                $ft_away = intval($inc['away_score']);
            }
        }

        $sh_home = $ft_home - $fh_home;
        $sh_away = $ft_away - $fh_away;

        $html = '<div class="match-timeline">';
        $html .= '<div class="timeline-period"><span>1. POŁOWA</span><span>' . $fh_home . ' - ' . $fh_away . '</span></div>';
        foreach ($first_half as $inc) {
            $html .= $this->render_timeline_incident($inc);
        }

        if (!empty($second_half)) {
            $html .= '<div class="timeline-period"><span>2. POŁOWA</span><span>' . $sh_home . ' - ' . $sh_away . '</span></div>';
            foreach ($second_half as $inc) {
                $html .= $this->render_timeline_incident($inc);
            }
        }

        $html .= '</div>';
        return $html;
    }

    private function render_timeline_incident($inc) {
        $time = intval($inc['time']);
        $added = intval($inc['added_time'] ?? 0);
        $time_str = $added > 0 ? "{$time}+{$added}'" : "{$time}'";
        $type = $inc['incident_type'] ?? '';
        $player = esc_html($inc['player_name'] ?? '');
        $assist = esc_html($inc['assist_name'] ?? '');
        $is_home = intval($inc['is_home'] ?? 0);
        $desc = strtolower($inc['description'] ?? '');

        if ($type === 'substitution' && empty($player) && empty($assist) && !empty($inc['raw_json'])) {
            $raw = json_decode($inc['raw_json'], true);
            if ($raw) {
                $pin  = $raw['playerIn'] ?? $raw['player'] ?? null;
                $pout = $raw['playerOut'] ?? $raw['assist1'] ?? null;
                if ($pin)  $player = esc_html($pin['shortName'] ?? $pin['name'] ?? '');
                if ($pout) $assist = esc_html($pout['shortName'] ?? $pout['name'] ?? '');
            }
        }

        switch ($type) {
            case 'goal': $icon = '⚽'; break;
            case 'card':
                $cc = strtolower($inc['incident_class'] ?? $desc);
                $icon = (strpos($cc, 'red') !== false || strpos($cc, 'yellowred') !== false) ? '🟥' : '🟨';
                break;
            case 'substitution': $icon = '🔄'; break;
            default: $icon = '•'; break;
        }

        $p = array();
        $t_span  = '<span class="tl-time">' . $time_str . '</span>';
        $i_span  = '<span class="tl-icon">' . $icon . '</span>';

        if ($type === 'goal') {
            $suffix = '';
            if ($inc['incident_class'] === 'ownGoal') $suffix = ' (sam.)';
            if ($inc['incident_class'] === 'penalty') $suffix = ' (k.)';
            $score = ($inc['home_score'] !== null && $inc['away_score'] !== null)
                ? '<span class="tl-score">' . intval($inc['home_score']) . ' - ' . intval($inc['away_score']) . '</span>'
                : '';
            $assist_span = $assist ? '<span class="tl-assist">(' . $assist . ')</span>' : '';
            $player_span = '<span class="tl-player"><strong>' . $player . $suffix . '</strong></span>';

            if ($is_home) {
                $p = array($t_span, $i_span, $score, $player_span, $assist_span);
            } else {
                $p = array($assist_span, $player_span, $score, $i_span, $t_span);
            }
        } elseif ($type === 'substitution') {
            $injury = (strpos($desc, 'injury') !== false) ? '<span class="tl-reason">(Kontuzja)</span>' : '';
            $in_span  = '<span class="tl-player"><strong>' . $player . '</strong></span>';
            $out_span = '<span class="tl-sub-out">' . $assist . '</span>';

            if ($is_home) {
                $p = array($t_span, $i_span, $in_span, $injury, $out_span);
            } else {
                $p = array($injury, $out_span, $in_span, $i_span, $t_span);
            }
        } elseif ($type === 'card') {
            $player_span = '<span class="tl-player"><strong>' . $player . '</strong></span>';
            if ($is_home) {
                $p = array($t_span, $i_span, $player_span);
            } else {
                $p = array($player_span, $i_span, $t_span);
            }
        } else {
            $text = $player ?: esc_html($inc['description'] ?? $type);
            $text_span = '<span class="tl-player">' . $text . '</span>';
            if ($is_home) {
                $p = array($t_span, $i_span, $text_span);
            } else {
                $p = array($text_span, $i_span, $t_span);
            }
        }

        $content = implode(' ', array_filter($p));

        if ($is_home) {
            return '<div class="timeline-row tl-home"><div class="tl-left">' . $content . '</div><div class="tl-right"></div></div>';
        }
        return '<div class="timeline-row tl-away"><div class="tl-left"></div><div class="tl-right">' . $content . '</div></div>';
    }

    /**
     * Deaktywacja pluginu
     */
    public function deactivate() {
        // Usuń zaplanowane zadania
        wp_clear_scheduled_hook('sofascore_update_data');
        wp_clear_scheduled_hook('sofascore_check_media');
        
        // Wyczyść cache
        delete_transient('sofascore_standings_76477');
        delete_transient('wisla_ii_table_data');
        delete_transient('wisla_ii_fixtures_data');
    }
    
    // ===============================================
    // MODUŁ WISŁA II PŁOCK - III LIGA (90minut.pl)
    // ===============================================
    
    /**
     * Scraping danych z 90minut.pl
     */
    private function scrape_90minut_data($url = null) {
        // Jeśli nie podano URL, pobierz z ustawień
        if (!$url) {
            $url = get_option('wisla_ii_90minut_url', 'http://www.90minut.pl/liga/1/liga14154.html');
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        if ($http_code !== 200) {
            return array('success' => false, 'error' => 'HTTP Error: ' . $http_code);
        }
        
        return array('success' => true, 'data' => $body);
    }
    
    /**
     * Parsowanie tabeli III ligi z 90minut.pl
     */
    private function parse_3_liga_table($html) {
        if (!$html) return false;
        
        // Lepsze kodowanie - sprawdź czy to już UTF-8
        if (!mb_check_encoding($html, 'UTF-8')) {
            // Spróbuj różnych kodowań
            $encodings = ['ISO-8859-2', 'Windows-1250', 'CP1250'];
            foreach ($encodings as $encoding) {
                if (mb_check_encoding($html, $encoding)) {
                    $html = mb_convert_encoding($html, 'UTF-8', $encoding);
                    break;
                }
            }
        }
        
        $teams = array();
        $position = 1;
        
        // Regex do wyciągnięcia wierszy tabeli z kolorowym tłem (drużyny)
        preg_match_all('/<tr[^>]*bgcolor="(?:#[A-F0-9]{6}|[A-Z]+)"[^>]*>.*?<\/tr>/si', $html, $rows);
        
        // Debug: sprawdź ile wierszy znaleziono
        $debug_rows_found = count($rows[0]);
        
        // Jeśli nie znaleziono wierszy z bgcolor, spróbuj prostszego podejścia
        if ($debug_rows_found == 0) {
            // Szukaj wszystkich wierszy w tabeli main2
            if (preg_match('/<table[^>]*class="main2"[^>]*>(.*?)<\/table>/si', $html, $table_match)) {
                preg_match_all('/<tr[^>]*>(.*?)<\/tr>/si', $table_match[1], $all_rows);
                
                foreach ($all_rows[1] as $row_content) {
                    // Sprawdź czy wiersz zawiera link do drużyny
                    if (preg_match('/<a[^>]*>([^<]+)<\/a>/', $row_content, $name)) {
                        // Wyciągnij wszystkie liczby z wiersza
                        preg_match_all('/<td[^>]*>(\d+)<\/td>/', $row_content, $numbers);
                        preg_match('/<td[^>]*>(\d+-\d+)<\/td>/', $row_content, $goals);
                        
                        // Sprawdź czy mamy wystarczająco danych
                        if (count($numbers[1]) >= 5) {
                            $teams[] = array(
                                'position' => $position,
                                'name' => trim($name[1]),
                                'matches' => (int)$numbers[1][0],
                                'points' => (int)$numbers[1][1],
                                'wins' => (int)$numbers[1][2],
                                'draws' => (int)$numbers[1][3],
                                'losses' => (int)$numbers[1][4],
                                'goals' => isset($goals[1]) ? $goals[1] : '0-0'
                            );
                            $position++;
                        }
                    }
                }
            }
        } else {
            // Oryginalne podejście z bgcolor
            foreach ($rows[0] as $row) {
                // Pomiń nagłówek z czerwonym tłem
                if (strpos($row, '#B81B1B') !== false || strpos($row, 'Nazwa') !== false) {
                    continue;
                }
                
                // Sprawdź czy wiersz zawiera link do drużyny
                if (preg_match('/<a[^>]*>([^<]+)<\/a>/', $row, $name)) {
                    // Wyciągnij wszystkie liczby z wiersza
                    preg_match_all('/<td[^>]*>(\d+)<\/td>/', $row, $numbers);
                    preg_match('/<td[^>]*>(\d+-\d+)<\/td>/', $row, $goals);
                    
                    // Sprawdź czy mamy wystarczająco danych (minimum 5 liczb: M, Pkt, Z, R, P)
                    if (count($numbers[1]) >= 5) {
                        $teams[] = array(
                            'position' => $position,
                            'name' => trim($name[1]),
                            'matches' => (int)$numbers[1][0],    // Mecze
                            'points' => (int)$numbers[1][1],     // Punkty
                            'wins' => (int)$numbers[1][2],       // Zwycięstwa
                            'draws' => (int)$numbers[1][3],      // Remisy
                            'losses' => (int)$numbers[1][4],     // Porażki
                            'goals' => isset($goals[1]) ? $goals[1] : '0-0'
                        );
                        $position++;
                    }
                }
            }
        }
        
        // Jeśli nie znaleziono drużyn, zapisz debug info
        if (empty($teams)) {
            error_log("90minut.pl parser debug: Znaleziono $debug_rows_found wierszy, ale 0 drużyn");
        }
        
        return $teams;
    }
    
    /**
     * Parsowanie terminarza z 90minut.pl
     */
    private function parse_3_liga_fixtures($html) {
        if (!$html) return false;
        
        // Lepsze kodowanie - sprawdź czy to już UTF-8
        if (!mb_check_encoding($html, 'UTF-8')) {
            // Spróbuj różnych kodowań
            $encodings = ['ISO-8859-2', 'Windows-1250', 'CP1250'];
            foreach ($encodings as $encoding) {
                if (mb_check_encoding($html, $encoding)) {
                    $html = mb_convert_encoding($html, 'UTF-8', $encoding);
                    break;
                }
            }
        }
        
        $fixtures = array();
        
        // Wyciągnij kolejki
        preg_match_all('/<b><u>Kolejka (\d+) - ([^<]+)<\/u><\/b>.*?<table[^>]*>(.*?)<\/table>/s', $html, $rounds, PREG_SET_ORDER);
        
        foreach ($rounds as $round) {
            $round_number = (int)$round[1];
            $round_date = trim($round[2]);
            $matches_html = $round[3];
            
            // Wyciągnij mecze z tej kolejki
            preg_match_all('/<tr[^>]*>.*?<td[^>]*>([^<]+)<\/td>.*?<td[^>]*>-<\/td>.*?<td[^>]*>([^<]+)<\/td>/s', $matches_html, $matches, PREG_SET_ORDER);
            
            foreach ($matches as $match) {
                $home_team = trim($match[1]);
                $away_team = trim($match[2]);
                
                $fixtures[] = array(
                    'round' => $round_number,
                    'date' => $round_date,
                    'home_team' => $home_team,
                    'away_team' => $away_team,
                    'status' => 'scheduled'
                );
            }
        }
        
        return $fixtures;
    }
    
    /**
     * Shortcode: Tabela III Liga
     */
    public function shortcode_tabela_3_liga($atts) {
        $atts = shortcode_atts(array(
            'cache' => '1800' // 30 minut cache
        ), $atts);
        
        $cache_key = 'wisla_ii_table_data';
        $cached_data = get_transient($cache_key);
        
        if ($cached_data === false) {
            $scrape_result = $this->scrape_90minut_data();
            
            if (!$scrape_result['success']) {
                return '<div class="sofascore-error">Błąd pobierania danych: ' . $scrape_result['error'] . '</div>';
            }
            
            $teams = $this->parse_3_liga_table($scrape_result['data']);
            
            if (empty($teams)) {
                // Debug: sprawdź czy dane zostały pobrane
                $debug_info = 'Dane pobrane: ' . (strlen($scrape_result['data']) > 0 ? 'TAK (' . strlen($scrape_result['data']) . ' znaków)' : 'NIE');
                
                // Sprawdź czy HTML zawiera oczekiwane elementy
                $has_table = strpos($scrape_result['data'], 'class="main2"') !== false ? 'TAK' : 'NIE';
                $has_teams = strpos($scrape_result['data'], 'Wisła II') !== false ? 'TAK' : 'NIE';
                $has_bgcolor = preg_match('/<tr[^>]*bgcolor="/i', $scrape_result['data']) ? 'TAK' : 'NIE';
                
                $debug_info .= '<br>Tabela główna: ' . $has_table;
                $debug_info .= '<br>Wisła II w HTML: ' . $has_teams;
                $debug_info .= '<br>Wiersze z bgcolor: ' . $has_bgcolor;
                
                return '<div class="sofascore-error">Nie udało się pobrać tabeli III ligi<br><small>' . $debug_info . '</small></div>';
            }
            
            set_transient($cache_key, $teams, (int)$atts['cache']);
            $cached_data = $teams;
        }
        
        return $this->render_3_liga_table($cached_data);
    }
    
    /**
     * Shortcode: Terminarz III Liga
     */
    public function shortcode_terminarz_3_liga($atts) {
        $atts = shortcode_atts(array(
            'kolejka' => '',
            'cache' => '1800'
        ), $atts);
        
        $cache_key = 'wisla_ii_fixtures_data';
        $cached_data = get_transient($cache_key);
        
        if ($cached_data === false) {
            $scrape_result = $this->scrape_90minut_data();
            
            if (!$scrape_result['success']) {
                return '<div class="sofascore-error">Błąd pobierania danych: ' . $scrape_result['error'] . '</div>';
            }
            
            $fixtures = $this->parse_3_liga_fixtures($scrape_result['data']);
            
            if (empty($fixtures)) {
                return '<div class="sofascore-error">Nie udało się pobrać terminarza III ligi</div>';
            }
            
            set_transient($cache_key, $fixtures, (int)$atts['cache']);
            $cached_data = $fixtures;
        }
        
        // Filtruj po kolejce jeśli podano
        if (!empty($atts['kolejka'])) {
            $cached_data = array_filter($cached_data, function($match) use ($atts) {
                return $match['round'] == (int)$atts['kolejka'];
            });
        }
        
        return $this->render_3_liga_fixtures($cached_data, $atts);
    }
    
    /**
     * Shortcode: Terminarz Wisła II Płock
     */
    public function shortcode_terminarz_wisla_ii($atts) {
        $atts = shortcode_atts(array(
            'limit' => '50',
            'cache' => '1800'
        ), $atts);
        
        $cache_key = 'wisla_ii_fixtures_data';
        $cached_data = get_transient($cache_key);
        
        if ($cached_data === false) {
            $scrape_result = $this->scrape_90minut_data();
            
            if (!$scrape_result['success']) {
                return '<div class="sofascore-error">Błąd pobierania danych: ' . $scrape_result['error'] . '</div>';
            }
            
            $fixtures = $this->parse_3_liga_fixtures($scrape_result['data']);
            set_transient($cache_key, $fixtures, (int)$atts['cache']);
            $cached_data = $fixtures;
        }
        
        // Filtruj mecze Wisły II
        $wisla_matches = array_filter($cached_data, function($match) {
            return stripos($match['home_team'], 'Wisła II') !== false || 
                   stripos($match['away_team'], 'Wisła II') !== false;
        });
        
        // Sortuj chronologicznie
        usort($wisla_matches, function($a, $b) {
            return $a['round'] - $b['round'];
        });
        
        // Ogranicz liczbę meczów
        $wisla_matches = array_slice($wisla_matches, 0, (int)$atts['limit']);
        
        return $this->render_wisla_ii_fixtures($wisla_matches);
    }
    
    /**
     * Shortcode: Kadra Wisła II Płock
     */
    public function shortcode_wisla_ii_kadra($atts) {
        $atts = shortcode_atts(array(
            'pozycja' => '',
            'styl' => 'karty',
            'kolumny' => '3',
            'sortowanie' => 'pozycja'
        ), $atts);
        
        $csv_file = get_template_directory() . '/wisla-ii-kadra.csv';
        
        if (!file_exists($csv_file)) {
            return '<div class="wisla-error">❌ Plik z kadrą Wisły II nie został znaleziony. Użyj panelu administracyjnego do uploadu pliku CSV.</div>';
        }
        
        $players = $this->load_wisla_ii_csv($csv_file);
        
        if (empty($players)) {
            return '<div class="wisla-error">❌ Nie znaleziono zawodników w pliku CSV.</div>';
        }
        
        // Filtruj po pozycji
        if (!empty($atts['pozycja'])) {
            $players = array_filter($players, function($player) use ($atts) {
                return stripos($player['pozycja'], $atts['pozycja']) !== false;
            });
        }
        
        return $this->render_wisla_ii_kadra($players, $atts);
    }
    
    /**
     * Renderowanie tabeli III ligi
     */
    private function render_3_liga_table($teams) {
        ob_start();
        ?>
        <div class="liga-3-container">
            <div class="liga-3-header">
                <h3>Betclic III Liga 2025/26 - Grupa I</h3>
            </div>
            
            <div class="liga-3-table-wrapper">
                <table class="liga-3-table">
                    <thead>
                        <tr>
                            <th>Poz.</th>
                            <th>Drużyna</th>
                            <th>M</th>
                            <th>Pkt</th>
                            <th>Z</th>
                            <th>R</th>
                            <th>P</th>
                            <th>Bramki</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $index => $team): ?>
                            <?php
                            $is_wisla = stripos($team['name'], 'Wisła II') !== false;
                            $display_position = $team['position'];
                            $row_class = $is_wisla ? 'wisla-row' : '';
                            ?>
                            <tr class="team-row <?php echo $row_class; ?>">
                                <td class="position"><?php echo $display_position; ?></td>
                                <td class="team-name"><?php echo esc_html($team['name']); ?></td>
                                <td><?php echo $team['matches']; ?></td>
                                <td><strong><?php echo $team['points']; ?></strong></td>
                                <td><?php echo $team['wins']; ?></td>
                                <td><?php echo $team['draws']; ?></td>
                                <td><?php echo $team['losses']; ?></td>
                                <td><?php echo $team['goals']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .liga-3-container {
            max-width: 100%;
            margin: 20px 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .liga-3-header {
            background: linear-gradient(135deg, #1e3d59 0%, #2d5a87 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .liga-3-header h3 {
            margin: 0;
            font-size: 1.8em;
            font-weight: 600;
            color: white;
        }
        
        .liga-3-table-wrapper {
            overflow-x: auto;
        }
        
        .liga-3-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .liga-3-table th {
            background: #343a40;
            color: white;
            padding: 12px 8px;
            text-align: center;
            font-weight: 600;
            border-bottom: 2px solid #495057;
        }
        
        .liga-3-table td {
            padding: 10px 8px;
            text-align: center;
            border-bottom: 1px solid #e9ecef;
        }
        
        .team-name {
            text-align: left !important;
            font-weight: 500;
        }
        
        .wisla-row {
            background: #f0f4f8 !important;
            border-left: 6px solid #1e3d59;
        }
        
        .wisla-row .team-name {
            font-weight: 700;
            color: #1e3d59;
        }
        
        @media (max-width: 768px) {
            .liga-3-container {
                margin: 15px 0;
                border-radius: 8px;
            }
            
            .liga-3-header h3 {
                font-size: 1.4em;
            }
            
            .liga-3-table th,
            .liga-3-table td {
                padding: 8px 4px;
                font-size: 0.9em;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderowanie terminarza III ligi
     */
    private function render_3_liga_fixtures($fixtures, $atts) {
        if (empty($fixtures)) {
            return '<div class="sofascore-error">Brak meczów do wyświetlenia</div>';
        }
        
        ob_start();
        ?>
        <div class="terminarz-container wisla-terminarz">
            <div class="terminarz-header">
                <h3>Betclic III Liga 2025/26 - Grupa I</h3>
                <?php if (!empty($atts['kolejka'])): ?>
                    <div class="matches-count">Kolejka <?php echo $atts['kolejka']; ?></div>
                <?php else: ?>
                    <div class="matches-count">Terminarz rozgrywek</div>
                <?php endif; ?>
            </div>
            
            <div class="terminarz-matches">
                <?php 
                $current_round = null;
                foreach ($fixtures as $match): 
                    if ($current_round !== $match['round']):
                        if ($current_round !== null) echo '</div>';
                        $current_round = $match['round'];
                        echo '<div class="round-section">';
                        echo '<div class="round-header"><h4>Kolejka ' . $current_round . ' - ' . esc_html($match['date']) . '</h4></div>';
                    endif;
                    
                    $wisla_home = (stripos($match['home_team'], 'Wisła II') !== false);
                    $wisla_away = (stripos($match['away_team'], 'Wisła II') !== false);
                    $is_wisla_match = $wisla_home || $wisla_away;
                    ?>
                    <div class="match-item <?php echo $is_wisla_match ? 'wisla-match' : ''; ?>">
                        <div class="match-teams">
                            <span class="home-team <?php echo $wisla_home ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['home_team']); ?>
                            </span>
                            <span class="vs">vs</span>
                            <span class="away-team <?php echo $wisla_away ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['away_team']); ?>
                            </span>
                        </div>
                    </div>
                <?php 
                endforeach; 
                if ($current_round !== null) echo '</div>';
                ?>
            </div>
        </div>
        
        <style>
        .wisla-terminarz {
            background: white;
            max-width: 100%;
            overflow: hidden;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .wisla-terminarz .terminarz-header {
            background: #1e3d59;
            color: white;
            margin: -20px -20px 20px -20px;
            padding: 15px 20px;
            border-radius: 6px 6px 0 0;
        }
        
        .wisla-terminarz .terminarz-header h3 {
            color: white;
            margin: 5px 0 0 0;
        }
        
        .matches-count {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .match-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 0;
        }
        
        .match-item:last-child {
            border-bottom: none;
        }
        
        .match-teams {
            flex: 1;
            text-align: center;
            margin: 0 20px;
        }
        
        .match-info {
            flex: 0 0 auto;
            text-align: right;
        }
        
        .wisla-team {
            font-weight: 700;
            color: #1e3d59;
        }
        
        .home-team, .away-team {
            font-weight: 600;
        }
        
        .vs {
            color: #666;
            margin: 0 10px;
            font-size: 0.9em;
        }
        
        .match-date {
            font-weight: 500;
            color: #0299d6;
        }
        
        .wisla-match {
            background: #f0f4f8;
            border-left: 6px solid #ED8311;
        }
        
        .round-section {
            margin-bottom: 25px;
        }
        
        .round-header {
            background: #1e3d59;
            color: white;
            padding: 10px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        
        .round-header h4 {
            margin: 0;
            font-size: 1.1em;
            font-weight: 600;
            color: white;
        }
        
        /* Responsywność */
        @media (max-width: 768px) {
            .wisla-terminarz {
                margin: 15px auto;
                max-width: 95%;
            }
            
            .match-item {
                display: block;
                padding: 20px 15px;
                text-align: center;
            }
            
            .match-teams {
                margin: 10px 0;
            }
            
            .match-info {
                margin-top: 10px;
                text-align: center;
            }
        }
        </style>

        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderowanie terminarza Wisły II
     */
    private function render_wisla_ii_fixtures($matches) {
        if (empty($matches)) {
            return '<div class="sofascore-error">Brak meczów Wisły II Płock</div>';
        }
        
        ob_start();
        ?>
        <div class="terminarz-container wisla-terminarz">
            <div class="terminarz-header">
                <h3>Betclic III Liga 2025/26</h3>
                <div class="matches-count">Terminarz meczów Wisły II Płock</div>
            </div>
            
            <div class="terminarz-matches">
                <?php foreach ($matches as $match): ?>
                    <?php
                    $wisla_home = (stripos($match['home_team'], 'Wisła II') !== false);
                    $wisla_away = (stripos($match['away_team'], 'Wisła II') !== false);
                    ?>
                    <div class="match-item">
                        <div class="match-round">
                            <span class="round-number">Kolejka <?php echo $match['round']; ?></span>
                        </div>
                        <div class="match-teams">
                            <span class="home-team <?php echo $wisla_home ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['home_team']); ?>
                            </span>
                            <span class="vs">vs</span>
                            <span class="away-team <?php echo $wisla_away ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['away_team']); ?>
                            </span>
                        </div>
                        <div class="match-info">
                            <span class="match-date"><?php echo esc_html($match['date']); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
        .wisla-terminarz {
            background: white;
            max-width: 100%;
            overflow: hidden;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .wisla-terminarz .terminarz-header {
            background: #1e3d59;
            color: white;
            margin: -20px -20px 20px -20px;
            padding: 15px 20px;
            border-radius: 6px 6px 0 0;
        }
        
        .wisla-terminarz .terminarz-header h3 {
            color: white;
            margin: 5px 0 0 0;
        }
        
        .matches-count {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .match-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            margin-bottom: 0;
        }
        
        .match-item:last-child {
            border-bottom: none;
        }
        
        .match-teams {
            flex: 1;
            text-align: center;
            margin: 0 20px;
        }
        
        .match-info {
            flex: 0 0 auto;
            text-align: right;
        }
        
        .wisla-team {
            font-weight: 700;
            color: #1e3d59;
        }
        
        .home-team, .away-team {
            font-weight: 600;
        }
        
        .vs {
            color: #666;
            margin: 0 10px;
            font-size: 0.9em;
        }
        
        .match-date {
            font-weight: 500;
            color: #0299d6;
        }
        
        .match-round {
            flex: 0 0 auto;
        }
        
        .round-number {
            background: #1e3d59;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.85em;
        }
        
        /* Responsywność */
        @media (max-width: 768px) {
            .wisla-terminarz {
                margin: 15px auto;
                max-width: 95%;
            }
            
            .match-item {
                display: block;
                padding: 20px 15px;
                text-align: center;
            }
            
            .match-round {
                margin-bottom: 10px;
            }
            
            .match-teams {
                margin: 10px 0;
            }
            
            .match-info {
                margin-top: 10px;
                text-align: center;
            }
        }
        </style>

        <?php
        return ob_get_clean();
    }
    
    /**
     * Wczytaj CSV kadry Wisły Płock (Ekstraklasa)
     */
    private function load_wisla_kadra_csv($csv_file) {
        $players = array();
        
        if (!file_exists($csv_file)) {
            return $players;
        }
        
        // Wczytaj cały plik i usuń BOM jeśli istnieje
        $content = file_get_contents($csv_file);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
            file_put_contents($csv_file, $content); // Zapisz bez BOM
        }
        
        if (($handle = fopen($csv_file, 'r')) !== FALSE) {
            // Sprawdź separator - ; lub ,
            $first_line = fgets($handle);
            rewind($handle);
            
            $separator = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
            
            // Pomiń nagłówek
            $header = fgetcsv($handle, 1000, $separator);
            
            $row_count = 0;
            while (($data = fgetcsv($handle, 1000, $separator)) !== FALSE) {
                $row_count++;
                
                // Sprawdź czy wiersz ma wystarczającą liczbę kolumn
                if (count($data) >= 6) { // Minimum: imię, numer, pozycja, wiek, data, wzrost
                    // Czyść dane z niepotrzebnych cudzysłowów i białych znaków
                    $cleaned_data = array_map(function($field) {
                        return trim($field, " \t\n\r\0\x0B\"");
                    }, $data);
                    
                    // Sprawdź czy to nie jest pusty wiersz
                    if (!empty($cleaned_data[0])) {
                        $players[] = array(
                            'imie_nazwisko' => $cleaned_data[0],
                            'numer' => isset($cleaned_data[1]) ? $cleaned_data[1] : 'N/A',
                            'pozycja' => isset($cleaned_data[2]) ? $cleaned_data[2] : 'N/A',
                            'wiek' => isset($cleaned_data[3]) ? $cleaned_data[3] : 'N/A',
                            'data_urodzenia' => isset($cleaned_data[4]) ? $cleaned_data[4] : 'N/A',
                            'wzrost' => isset($cleaned_data[5]) ? $cleaned_data[5] : 'N/A',
                            'kraj' => isset($cleaned_data[6]) ? $cleaned_data[6] : 'N/A',
                            'noga' => isset($cleaned_data[7]) ? $cleaned_data[7] : 'N/A',
                            'wartosc' => isset($cleaned_data[8]) ? $cleaned_data[8] : 'N/A',
                            'kontrakt_do' => isset($cleaned_data[9]) ? $cleaned_data[9] : 'N/A',
                            'zdjecie' => isset($cleaned_data[10]) ? $cleaned_data[10] : ''
                        );
                    }
                }
            }
            fclose($handle);
        }
        
        return $players;
    }
    
    /**
     * Wczytaj CSV kadry Wisły II
     */
    private function load_wisla_ii_csv($csv_file) {
        $players = array();
        
        if (($handle = fopen($csv_file, 'r')) !== FALSE) {
            $header = fgetcsv($handle, 1000, ',');
            
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if (count($data) >= 8) {
                    $players[] = array(
                        'imie_nazwisko' => trim($data[0], '"'),
                        'numer' => $data[1],
                        'pozycja' => $data[2],
                        'wiek' => $data[3],
                        'wzrost' => $data[4],
                        'kraj' => $data[5],
                        'noga' => $data[6],
                        'zdjecie' => isset($data[7]) ? $data[7] : ''
                    );
                }
            }
            fclose($handle);
        }
        
        return $players;
    }
    
    /**
     * Renderowanie kadry Wisły Płock (Ekstraklasa)
     */
    private function render_wisla_kadra($players, $atts) {
        // Sortowanie
        $players = $this->sort_wisla_players($players, $atts['sortowanie']);
        
        ob_start();
        ?>
        <div class="wisla-kadra-container">
            <div class="kadra-header">
                <h3>Kadra Wisły Płock</h3>
            </div>
            
            <div class="players-grid columns-<?php echo $atts['kolumny']; ?>">
                <?php foreach ($players as $player): ?>
                    <div class="player-card">
                        <?php if (!empty($player['zdjecie'])): ?>
                            <div class="player-photo">
                                <img src="<?php echo esc_url($player['zdjecie']); ?>" 
                                     alt="<?php echo esc_attr($player['imie_nazwisko']); ?>" 
                                     loading="lazy">
                                <div class="player-number"><?php echo $player['numer']; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="player-info">
                            <h4><?php echo esc_html($player['imie_nazwisko']); ?></h4>
                            <div class="player-position"><?php echo esc_html($player['pozycja']); ?></div>
                            
                            <div class="player-details">
                                <div><span>Wiek:</span> <?php echo $player['wiek']; ?> lat</div>
                                <div><span>Data urodzenia:</span> <?php echo $player['data_urodzenia']; ?></div>
                                <?php if ($player['wzrost'] !== 'N/A'): ?>
                                    <div><span>Wzrost:</span> <?php echo $player['wzrost']; ?> cm</div>
                                <?php endif; ?>
                                <div><span>Kraj:</span> <?php echo $player['kraj']; ?></div>
                                <?php if ($player['noga'] !== 'N/A'): ?>
                                    <div><span>Noga:</span> <?php echo $player['noga']; ?></div>
                                <?php endif; ?>
                                <?php if (!empty($player['wartosc']) && $player['wartosc'] !== 'N/A'): ?>
                                    <div><span>Wartość:</span> <?php echo $player['wartosc']; ?> €</div>
                                <?php endif; ?>
                                <div><span>Kontrakt do:</span> <?php echo $player['kontrakt_do']; ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="kadra-stats">
                <h4>📊 Statystyki kadry</h4>
                <div class="stats-grid">
                    <div><strong><?php echo count($players); ?></strong><br>Zawodników</div>
                    <div><strong><?php echo number_format(array_sum(array_column($players, 'wiek')) / count($players), 1); ?></strong><br>Średni wiek</div>
                </div>
            </div>
        </div>
        
        <style>
        .wisla-kadra-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .kadra-header {
            background: linear-gradient(135deg, #0299d6 0%, #1e7bb8 100%);
            color: white;
            padding: 20px;
            margin: -20px -20px 25px -20px;
            border-radius: 12px 12px 0 0;
            text-align: center;
        }
        
        .kadra-header h3 {
            margin: 0;
            font-size: 2em;
            color: white;
        }
        
        .season-info {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .players-grid {
            display: grid;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .players-grid.columns-1 { grid-template-columns: 1fr; }
        .players-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
        .players-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
        .players-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
        
        .player-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .player-card:hover {
            border-color: #0299d6;
            transform: translateY(-2px);
        }
        
        .player-photo {
            position: relative;
            margin-bottom: 15px;
        }
        
        .player-photo img {
            width: 100%;
            max-width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .player-number {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #0299d6;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: bold;
        }
        
        .player-info h4 {
            margin: 0 0 5px 0;
            color: #0299d6;
            font-size: 1.2em;
        }
        
        .player-position {
            background: #0299d6;
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .player-details {
            font-size: 0.9em;
            text-align: left;
        }
        
        .player-details div {
            margin: 3px 0;
        }
        
        .player-details span {
            font-weight: 600;
            color: #666;
        }
        
        .kadra-stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .wisla-error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f5c6cb;
            margin: 20px 0;
        }
        
        @media (max-width: 768px) {
            .players-grid {
                grid-template-columns: 1fr !important;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Renderowanie kadry Wisły II
     */
    private function render_wisla_ii_kadra($players, $atts) {
        // Sortowanie
        $players = $this->sort_wisla_ii_players($players, $atts['sortowanie']);
        
        ob_start();
        ?>
        <div class="wisla-ii-kadra-container">
            <div class="kadra-header">
                <h3>Kadra Wisły II Płock</h3>
                <div class="season-info">Sezon 2025/26 - III Liga</div>
            </div>
            
            <div class="players-grid columns-<?php echo $atts['kolumny']; ?>">
                <?php foreach ($players as $player): ?>
                    <div class="player-card">
                        <?php if (!empty($player['zdjecie'])): ?>
                            <div class="player-photo">
                                <img src="<?php echo esc_url($player['zdjecie']); ?>" 
                                     alt="<?php echo esc_attr($player['imie_nazwisko']); ?>" 
                                     loading="lazy">
                                <div class="player-number"><?php echo $player['numer']; ?></div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="player-info">
                            <h4><?php echo esc_html($player['imie_nazwisko']); ?></h4>
                            <div class="player-position"><?php echo esc_html($player['pozycja']); ?></div>
                            
                            <div class="player-details">
                                <div><span>Wiek:</span> <?php echo $player['wiek']; ?> lat</div>
                                <?php if ($player['wzrost'] !== 'N/A'): ?>
                                    <div><span>Wzrost:</span> <?php echo $player['wzrost']; ?> cm</div>
                                <?php endif; ?>
                                <div><span>Kraj:</span> <?php echo $player['kraj']; ?></div>
                                <?php if ($player['noga'] !== 'N/A'): ?>
                                    <div><span>Noga:</span> <?php echo $player['noga']; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="kadra-stats">
                <h4>📊 Statystyki kadry</h4>
                <div class="stats-grid">
                    <div><strong><?php echo count($players); ?></strong><br>Zawodników</div>
                    <div><strong><?php echo number_format(array_sum(array_column($players, 'wiek')) / count($players), 1); ?></strong><br>Średni wiek</div>
                </div>
            </div>
        </div>
        
        <style>
        .wisla-ii-kadra-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        .kadra-header {
            background: linear-gradient(135deg, #1e3d59 0%, #2d5a87 100%);
            color: white;
            padding: 20px;
            margin: -20px -20px 25px -20px;
            border-radius: 12px 12px 0 0;
            text-align: center;
        }
        
        .kadra-header h3 {
            margin: 0;
            font-size: 2em;
        }
        
        .season-info {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        .players-grid {
            display: grid;
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .players-grid.columns-1 { grid-template-columns: 1fr; }
        .players-grid.columns-2 { grid-template-columns: repeat(2, 1fr); }
        .players-grid.columns-3 { grid-template-columns: repeat(3, 1fr); }
        .players-grid.columns-4 { grid-template-columns: repeat(4, 1fr); }
        
        .player-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        
        .player-card:hover {
            border-color: #1e3d59;
            transform: translateY(-2px);
        }
        
        .player-photo {
            position: relative;
            margin-bottom: 15px;
        }
        
        .player-photo img {
            width: 100%;
            max-width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
        }
        
        .player-number {
            position: absolute;
            top: 5px;
            right: 5px;
            background: #1e3d59;
            color: white;
            padding: 5px 10px;
            border-radius: 15px;
            font-weight: bold;
        }
        
        .player-info h4 {
            margin: 0 0 5px 0;
            color: #1e3d59;
            font-size: 1.2em;
        }
        
        .player-position {
            background: #1e3d59;
            color: white;
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.8em;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .player-details {
            font-size: 0.9em;
            text-align: left;
        }
        
        .player-details div {
            margin: 3px 0;
        }
        
        .player-details span {
            font-weight: 600;
            color: #666;
        }
        
        .kadra-stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        @media (max-width: 768px) {
            .players-grid {
                grid-template-columns: 1fr !important;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Sortowanie zawodników Wisły Płock (Ekstraklasa)
     */
    private function sort_wisla_players($players, $sort_by) {
        switch ($sort_by) {
            case 'nazwisko':
                usort($players, function($a, $b) {
                    return strcmp($a['imie_nazwisko'], $b['imie_nazwisko']);
                });
                break;
            case 'pozycja':
                usort($players, function($a, $b) {
                    $pozycje = ['Bramkarz' => 1, 'Obrońca' => 2, 'Pomocnik' => 3, 'Napastnik' => 4];
                    $a_pos = $pozycje[$a['pozycja']] ?? 5;
                    $b_pos = $pozycje[$b['pozycja']] ?? 5;
                    return $a_pos - $b_pos;
                });
                break;
            case 'wiek':
                usort($players, function($a, $b) {
                    return (int)$a['wiek'] - (int)$b['wiek'];
                });
                break;
            case 'numer':
            default:
                usort($players, function($a, $b) {
                    return (int)$a['numer'] - (int)$b['numer'];
                });
                break;
        }
        
        return $players;
    }
    
    /**
     * Sortowanie zawodników Wisły II
     */
    private function sort_wisla_ii_players($players, $sort_by) {
        switch ($sort_by) {
            case 'nazwisko':
                usort($players, function($a, $b) {
                    return strcmp($a['imie_nazwisko'], $b['imie_nazwisko']);
                });
                break;
            case 'pozycja':
                usort($players, function($a, $b) {
                    $pozycje = ['Bramkarz' => 1, 'Obrońca' => 2, 'Pomocnik' => 3, 'Napastnik' => 4];
                    $a_pos = $pozycje[$a['pozycja']] ?? 5;
                    $b_pos = $pozycje[$b['pozycja']] ?? 5;
                    return $a_pos - $b_pos;
                });
                break;
            case 'wiek':
                usort($players, function($a, $b) {
                    return (int)$a['wiek'] - (int)$b['wiek'];
                });
                break;
            case 'numer':
            default:
                usort($players, function($a, $b) {
                    return (int)$a['numer'] - (int)$b['numer'];
                });
                break;
        }
        
        return $players;
    }
    
         /**
      * Strona główna Wisły II Płock
      */
     public function wisla_ii_main_page() {
         ?>
         <div class="wrap">
             <h1>Wisła II Płock - III Liga</h1>
             
             <div class="card">
                 <h2>⚙️ Konfiguracja źródła danych</h2>
                 <form method="post" action="">
                     <?php wp_nonce_field('wisla_ii_config', 'wisla_ii_config_nonce'); ?>
                     <table class="form-table">
                         <tr>
                             <th scope="row">URL strony 90minut.pl</th>
                             <td>
                                 <input type="url" name="wisla_ii_url" value="<?php echo esc_attr(get_option('wisla_ii_90minut_url', 'http://www.90minut.pl/liga/1/liga14154.html')); ?>" class="regular-text" required>
                                 <p class="description">Adres strony z tabelą i terminarzem III ligi na 90minut.pl</p>
                             </td>
                         </tr>
                     </table>
                     <p class="submit">
                         <input type="submit" name="save_wisla_ii_config" class="button-primary" value="Zapisz konfigurację">
                     </p>
                 </form>
                 
                 <?php
                 if (isset($_POST['save_wisla_ii_config']) && wp_verify_nonce($_POST['wisla_ii_config_nonce'], 'wisla_ii_config')) {
                     $url = sanitize_url($_POST['wisla_ii_url']);
                     update_option('wisla_ii_90minut_url', $url);
                     
                     // Wyczyść cache po zmianie URL
                     delete_transient('wisla_ii_table_data');
                     delete_transient('wisla_ii_fixtures_data');
                     
                     echo '<div class="notice notice-success"><p>✅ Konfiguracja zapisana! Cache został wyczyszczony.</p></div>';
                 }
                 ?>
             </div>
             
             <div class="card">
                 <h2>🏆 Zarządzanie danymi III ligi</h2>
                 <p>Aktualizuj dane tabeli i terminarza III ligi z 90minut.pl</p>
                 <p><strong>Aktualny URL:</strong> <code><?php echo esc_html(get_option('wisla_ii_90minut_url', 'http://www.90minut.pl/liga/1/liga14154.html')); ?></code></p>
                 
                 <button type="button" class="button button-primary" id="update-wisla-ii-data">
                     Aktualizuj dane Wisły II
                 </button>
                 <div id="wisla-ii-result" style="margin-top: 10px;"></div>
             </div>
             
             <div class="card">
                 <h2>📋 Dostępne shortcodes</h2>
                 <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                     <h4>Tabela III Liga:</h4>
                     <code>[tabela_3_liga]</code>
                     
                     <h4>Terminarz III Liga (cała liga):</h4>
                     <code>[terminarz_3_liga]</code><br>
                     <code>[terminarz_3_liga kolejka="1"]</code> - konkretna kolejka
                     
                     <h4>Terminarz Wisły II Płock:</h4>
                     <code>[terminarz_wisla_ii]</code><br>
                     <code>[terminarz_wisla_ii limit="20"]</code> - ograniczona liczba meczów
                     
                     <h4>Kadra Wisły II Płock:</h4>
                     <code>[wisla_ii_kadra]</code><br>
                     <code>[wisla_ii_kadra pozycja="Napastnik"]</code> - filtr po pozycji<br>
                     <code>[wisla_ii_kadra kolumny="2"]</code> - liczba kolumn (1-4)
                 </div>
             </div>
             
             <div class="card">
                 <h2>ℹ️ Informacje o module</h2>
                 <ul>
                     <li><strong>Źródło danych:</strong> 90minut.pl</li>
                     <li><strong>Aktualny URL:</strong> <small><?php echo esc_html(get_option('wisla_ii_90minut_url', 'http://www.90minut.pl/liga/1/liga14154.html')); ?></small></li>
                     <li><strong>Cache:</strong> 30 minut (1800 sekund)</li>
                     <li><strong>Liga:</strong> Betclic III Liga 2025/26, Grupa I</li>
                     <li><strong>Zespół:</strong> Wisła II Płock</li>
                     <li><strong>Kadra:</strong> Upload pliku CSV przez panel "Kadra Wisły II"</li>
                 </ul>
                 
                 <h4>🔧 Przykłady URL-i dla różnych sezonów:</h4>
                 <ul style="font-size: 0.9em; color: #666;">
                     <li><code>http://www.90minut.pl/liga/1/liga14154.html</code> - Sezon 2025/26</li>
                     <li><code>http://www.90minut.pl/liga/1/liga13XXX.html</code> - Przyszłe sezony</li>
                 </ul>
             </div>
         </div>
         
         <script>
         jQuery(document).ready(function($) {
             $('#update-wisla-ii-data').click(function() {
                 var button = $(this);
                 var result = $('#wisla-ii-result');
                 
                 button.prop('disabled', true).text('Aktualizowanie...');
                 result.html('');
                 
                 $.post(ajaxurl, {
                     action: 'update_wisla_ii_data',
                     nonce: '<?php echo wp_create_nonce('sofascore_nonce'); ?>'
                 }, function(response) {
                     if (response.success) {
                         result.html('<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>');
                     } else {
                         result.html('<div class="notice notice-error"><p>❌ Błąd: ' + response.data.message + '</p></div>');
                     }
                 }).always(function() {
                     button.prop('disabled', false).text('Aktualizuj dane Wisły II');
                 });
             });
         });
         </script>
         <?php
     }
     
     /**
      * Strona zarządzania kadrą Wisły II
      */
     public function wisla_ii_kadra_page() {
         $csv_file = get_template_directory() . '/wisla-ii-kadra.csv';
         $csv_exists = file_exists($csv_file);
         
         ?>
         <div class="wrap">
             <h1>Kadra Wisły II Płock - Upload CSV</h1>
             
             <div class="card">
                 <h2>📂 Status pliku kadry</h2>
                 <?php if ($csv_exists): ?>
                     <div class="notice notice-success">
                         <p>✅ <strong>wisla-ii-kadra.csv</strong> - plik istnieje</p>
                         <p>Rozmiar: <?php echo size_format(filesize($csv_file)); ?></p>
                         <p>Ostatnia modyfikacja: <?php echo date('Y-m-d H:i:s', filemtime($csv_file)); ?></p>
                     </div>
                 <?php else: ?>
                     <div class="notice notice-warning">
                         <p>⚠️ <strong>wisla-ii-kadra.csv</strong> - plik nie istnieje</p>
                         <p>Prześlij plik CSV z kadrą Wisły II Płock</p>
                     </div>
                 <?php endif; ?>
             </div>
             
             <div class="card">
                 <h2>📤 Upload pliku CSV</h2>
                 <form id="wisla-ii-csv-form" enctype="multipart/form-data">
                     <table class="form-table">
                         <tr>
                             <th scope="row">Plik CSV</th>
                             <td>
                                 <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                                 <p class="description">Wybierz plik CSV z kadrą Wisły II Płock</p>
                             </td>
                         </tr>
                     </table>
                     
                     <p class="submit">
                         <button type="submit" class="button button-primary">Prześlij plik CSV</button>
                     </p>
                 </form>
                 
                 <div id="upload-result" style="margin-top: 10px;"></div>
             </div>
             
             <div class="card">
                 <h2>📋 Format pliku CSV</h2>
                 <p>Plik CSV powinien zawierać następujące kolumny (w tej kolejności):</p>
                 <ol>
                     <li><strong>Imię i nazwisko</strong></li>
                     <li><strong>Numer</strong></li>
                     <li><strong>Pozycja</strong> (Bramkarz, Obrońca, Pomocnik, Napastnik)</li>
                     <li><strong>Wiek</strong></li>
                     <li><strong>Wzrost</strong> (w cm, lub "N/A")</li>
                     <li><strong>Kraj</strong></li>
                     <li><strong>Noga</strong> (lewa, prawa, obie, lub "N/A")</li>
                     <li><strong>Zdjęcie</strong> (URL do zdjęcia, opcjonalne)</li>
                 </ol>
                 
                 <h4>Przykład:</h4>
                 <code>
                 "Jan Kowalski",1,"Bramkarz",25,185,"Polska","prawa","https://example.com/photo.jpg"<br>
                 "Adam Nowak",10,"Napastnik",22,178,"Polska","lewa",""
                 </code>
             </div>
             
             <div class="card">
                 <h2>📖 Shortcode kadry</h2>
                 <p>Po przesłaniu pliku CSV, użyj shortcode:</p>
                 <code>[wisla_ii_kadra]</code>
                 
                 <h4>Opcje shortcode:</h4>
                 <ul>
                     <li><code>[wisla_ii_kadra pozycja="Napastnik"]</code> - filtr po pozycji</li>
                     <li><code>[wisla_ii_kadra kolumny="2"]</code> - liczba kolumn (1-4)</li>
                     <li><code>[wisla_ii_kadra sortowanie="nazwisko"]</code> - sortowanie (pozycja, numer, nazwisko, wiek)</li>
                 </ul>
             </div>
         </div>
         
         <script>
         jQuery(document).ready(function($) {
             $('#wisla-ii-csv-form').submit(function(e) {
                 e.preventDefault();
                 
                 var formData = new FormData();
                 var fileInput = $('#csv_file')[0];
                 var result = $('#upload-result');
                 
                 if (!fileInput.files[0]) {
                     result.html('<div class="notice notice-error"><p>❌ Wybierz plik CSV</p></div>');
                     return;
                 }
                 
                 formData.append('csv_file', fileInput.files[0]);
                 formData.append('action', 'upload_wisla_ii_csv');
                 formData.append('nonce', '<?php echo wp_create_nonce('sofascore_nonce'); ?>');
                 
                 var submitBtn = $(this).find('button[type="submit"]');
                 submitBtn.prop('disabled', true).text('Przesyłanie...');
                 result.html('');
                 
                 $.ajax({
                     url: ajaxurl,
                     type: 'POST',
                     data: formData,
                     processData: false,
                     contentType: false,
                     success: function(response) {
                         if (response.success) {
                             result.html('<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>');
                             setTimeout(function() {
                                 location.reload();
                             }, 2000);
                         } else {
                             result.html('<div class="notice notice-error"><p>❌ Błąd: ' + response.data.message + '</p></div>');
                         }
                     },
                     error: function() {
                         result.html('<div class="notice notice-error"><p>❌ Błąd podczas przesyłania pliku</p></div>');
                     },
                     complete: function() {
                         submitBtn.prop('disabled', false).text('Prześlij plik CSV');
                     }
                 });
             });
         });
         </script>
         <?php
     }
     
     /**
      * AJAX: Aktualizuj dane Wisły II
      */
     public function ajax_update_wisla_ii_data() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        // Wyczyść cache
        delete_transient('wisla_ii_table_data');
        delete_transient('wisla_ii_fixtures_data');
        
        wp_send_json_success(array(
            'message' => 'Dane Wisły II zostały zaktualizowane!'
        ));
    }
    
    /**
     * AJAX: Upload CSV kadry Wisły II
     */
    public function ajax_upload_wisla_ii_csv() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        if (!isset($_FILES['csv_file'])) {
            wp_send_json_error(array('message' => 'Nie przesłano pliku'));
        }
        
        $file = $_FILES['csv_file'];
        $upload_dir = get_template_directory();
        $target_file = $upload_dir . '/wisla-ii-kadra.csv';
        
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            wp_send_json_success(array(
                'message' => 'Plik CSV został przesłany pomyślnie!'
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Błąd podczas przesyłania pliku'
            ));
        }
    }
    
    /**
     * Strona administratora dla kadry Wisły Płock (wrapper dla prywatnej metody)
     */
    public function wisla_kadra_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień dostępu.');
        }
        
        $this->wisla_kadra_admin_page_private();
    }
    

    
    /**
     * Główna funkcja panelu kadry Wisły Płock - NOWA WERSJA API + BAZA DANYCH
     */
    private function wisla_kadra_admin_page_private() {
        echo '<div class="wrap">';
        echo '<h1>🏆 Kadra Wisła Płock - Export/Import v3.0 + API Integration</h1>';
        
        $this->wisla_show_api_dashboard();
        
        echo '</div>';
    }
    
    /**
     * Nowy dashboard API + baza danych
     */
    private function wisla_show_api_dashboard() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        // Sprawdź status tabeli
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $table_name
        )) === $table_name;
        
        // Pobierz statystyki
        $total_players = 0;
        $last_update = 'Nigdy';
        if ($table_exists) {
            $total_players = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE status = %s",
                'active'
            ));
            
            $last_update_row = $wpdb->get_row($wpdb->prepare(
                "SELECT updated_at FROM {$table_name} WHERE status = %s ORDER BY updated_at DESC LIMIT 1",
                'active'
            ));
            
            if ($last_update_row && $last_update_row->updated_at) {
                $last_update = date('d.m.Y H:i', strtotime($last_update_row->updated_at));
            }
        }
        
        echo '<div class="notice notice-info"><p>';
        echo '🚀 <strong>Nowa wersja 3.0:</strong> System API + Baza danych + WordPress Media Library!';
        echo '</p></div>';
        
        // Status systemu
        echo '<h2>📊 Status systemu:</h2>';
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
        
        // Status bazy danych
        echo '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">';
        echo '<h3>🗄️ Baza danych</h3>';
        if ($table_exists) {
            echo '✅ <strong>Tabela utworzona</strong><br>';
            echo 'Zawodników: <strong>' . $total_players . '</strong><br>';
            echo 'Ostatnia aktualizacja: <strong>' . $last_update . '</strong>';
        } else {
            echo '❌ <strong>Tabela nie istnieje</strong><br>';
            echo '<small>Kliknij "Pobierz z API" aby utworzyć</small>';
        }
        echo '</div>';
        
        // Status API
        echo '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">';
        echo '<h3>🌐 SofaScore API</h3>';
        echo '✅ <strong>Endpoint dostępny</strong><br>';
        echo 'Team ID: <strong>3122</strong> (Wisła Płock)<br>';
        echo 'URL: <code>/api/v1/team/3122/players</code>';
        echo '</div>';
        
        echo '</div>';
        
        // KROK 1A: Pobierz z API
        echo '<div style="border: 2px solid #0073aa; padding: 20px; margin: 20px 0; border-radius: 8px;">';
        echo '<h2>🔄 KROK 1A: Pobierz z SofaScore API</h2>';
        echo '<p>Pobierz aktualną kadrę Wisły Płock z SofaScore API.</p>';
        echo '<form style="margin-bottom: 15px;">';
        echo '<label for="team_id">Team ID:</label> ';
        echo '<input type="text" id="team_id" value="3122" style="width: 100px; margin: 0 10px;"> ';
        echo '<button type="button" class="button button-primary" id="fetch-api-squad">📥 Pobierz wszystkich z API</button>';
        echo '</form>';
        echo '<div id="api-fetch-result" style="margin-top: 10px;"></div>';
        echo '</div>';
        
        // KROK 1B: Lista zawodników
        if ($table_exists && $total_players > 0) {
            echo '<div style="border: 2px solid #00a32a; padding: 20px; margin: 20px 0; border-radius: 8px;">';
            echo '<h2>👥 KROK 1B: Zarządzanie zawodnikami</h2>';
            echo '<div style="margin-bottom: 15px;">';
            echo '<button type="button" class="button button-secondary" id="reload-players-list">🔄 Odśwież listę</button> ';
            echo '<button type="button" class="button button-secondary" id="update-selected-players" disabled>📡 Aktualizuj wybranych z API</button> ';
            echo '<button type="button" class="button button-link-delete" id="delete-selected-players" disabled>🗑️ Usuń wybranych</button>';
            echo '</div>';
            echo '<div id="players-list-container">';
            $this->render_players_management_table();
            echo '</div>';
            echo '</div>';
        }
        
        // KROK 2: Shortcode info
        echo '<div style="border: 2px solid #8f5a00; padding: 20px; margin: 20px 0; border-radius: 8px;">';
        echo '<h2>📋 KROK 2: Shortcode gotowy!</h2>';
        echo '<p>Użyj shortcode na stronie:</p>';
        echo '<code>[wisla_kadra]</code>';
        echo '<h4>Opcje shortcode:</h4>';
        echo '<ul>';
        echo '<li><code>[wisla_kadra pozycja="Napastnik"]</code> - filtr po pozycji</li>';
        echo '<li><code>[wisla_kadra kolumny="2"]</code> - liczba kolumn (1-4)</li>';
        echo '<li><code>[wisla_kadra styl="tabela"]</code> - styl wyświetlania (karty/tabela)</li>';
        echo '<li><code>[wisla_kadra sortowanie="nazwisko"]</code> - sortowanie (numer, nazwisko, pozycja, wiek)</li>';
        echo '<li><code>[wisla_kadra debug="1"]</code> - informacje debug</li>';
        echo '</ul>';
        echo '</div>';
        
        // JavaScript
        echo '<script>
        jQuery(document).ready(function($) {
            var selectedPlayers = [];
            
            // Pobierz z API
            $("#fetch-api-squad").click(function() {
                var button = $(this);
                var teamId = $("#team_id").val();
                var result = $("#api-fetch-result");
                
                button.prop("disabled", true).text("Pobieranie...");
                result.html("");
                
                $.post(ajaxurl, {
                    action: "fetch_wisla_squad_api",
                    team_id: teamId,
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        result.html("<div class=\"notice notice-success\"><p>✅ " + response.data.message + "</p></div>");
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        result.html("<div class=\"notice notice-error\"><p>❌ " + response.data.message + "</p></div>");
                    }
                }).always(function() {
                    button.prop("disabled", false).text("📥 Pobierz wszystkich z API");
                });
            });
            
            // Checkboxy
            $(document).on("change", ".player-checkbox", function() {
                selectedPlayers = [];
                $(".player-checkbox:checked").each(function() {
                    selectedPlayers.push($(this).val());
                });
                
                $("#update-selected-players, #delete-selected-players").prop("disabled", selectedPlayers.length === 0);
                $("#update-selected-players").text("📡 Aktualizuj wybranych (" + selectedPlayers.length + ")");
                $("#delete-selected-players").text("🗑️ Usuń wybranych (" + selectedPlayers.length + ")");
            });
            
            // Aktualizuj wybranych
            $("#update-selected-players").click(function() {
                if (selectedPlayers.length === 0) return;
                
                var button = $(this);
                button.prop("disabled", true).text("Aktualizowanie...");
                
                $.post(ajaxurl, {
                    action: "update_selected_players",
                    player_ids: selectedPlayers,
                    team_id: $("#team_id").val(),
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        alert("✅ " + response.data.message);
                        location.reload();
                    } else {
                        alert("❌ " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("📡 Aktualizuj wybranych");
                });
            });
            
            // Usuń wybranych
            $("#delete-selected-players").click(function() {
                if (selectedPlayers.length === 0) return;
                
                if (!confirm("Czy na pewno chcesz usunąć " + selectedPlayers.length + " zawodników?")) {
                    return;
                }
                
                var button = $(this);
                button.prop("disabled", true).text("Usuwanie...");
                
                $.post(ajaxurl, {
                    action: "delete_selected_players",
                    player_ids: selectedPlayers,
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        alert("✅ " + response.data.message);
                        location.reload();
                    } else {
                        alert("❌ " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("🗑️ Usuń wybranych");
                });
            });
            
            // Odśwież listę
            $("#reload-players-list").click(function() {
                location.reload();
            });
        });
        </script>';
    }
    
    /**
     * STARY Dashboard konwertera kadry Wisły Płock (do usunięcia)
     */
    private function wisla_show_converter_dashboard_old() {
        $json_file = get_template_directory() . '/WislaPlayers.txt';
        $csv_file = get_template_directory() . '/wisla-kadra.csv';
        
        echo '<div class="notice notice-info"><p>';
        echo '🚀 <strong>Wersja 3.0:</strong> Pełny Export/Import bez FTP!';
        echo '</p></div>';
        
        // Status plików
        echo '<h2>📁 Status plików:</h2>';
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
        
        // JSON Status
        echo '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">';
        echo '<h3>📄 Plik JSON</h3>';
        if (file_exists($json_file)) {
            echo '✅ <strong>WislaPlayers.txt</strong><br>';
            echo 'Rozmiar: ' . size_format(filesize($json_file)) . '<br>';
            echo 'Ostatnia modyfikacja: ' . date('d.m.Y H:i', filemtime($json_file));
        } else {
            echo '❌ <strong>WislaPlayers.txt</strong> - nie znaleziony<br>';
            echo '<small>Wgraj plik przez FTP do katalogu motywu</small>';
        }
        echo '</div>';
        
        // CSV Status
        echo '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">';
        echo '<h3>📊 Plik CSV</h3>';
        if (file_exists($csv_file)) {
            echo '✅ <strong>wisla-kadra.csv</strong><br>';
            echo 'Rozmiar: ' . size_format(filesize($csv_file)) . '<br>';
            echo 'Ostatnia modyfikacja: ' . date('d.m.Y H:i', filemtime($csv_file)) . '<br>';
            echo '<a href="?page=wisla-kadra-admin&action=download_csv" class="button button-secondary">📥 Pobierz CSV</a>';
        } else {
            echo '❌ <strong>wisla-kadra.csv</strong> - nie istnieje<br>';
            echo '<small>Użyj konwertera aby utworzyć</small>';
        }
        echo '</div>';
        
        echo '</div>';
        
        // Sekcja 1: Konwersja JSON → CSV
        echo '<div style="border: 2px solid #0073aa; padding: 20px; margin: 20px 0; border-radius: 8px;">';
        echo '<h2>🔄 KROK 1: Konwersja JSON → CSV</h2>';
        if (file_exists($json_file)) {
            echo '<p>Przekonwertuj dane z API na edytowalny plik CSV.</p>';
            echo '<form method="post">';
            wp_nonce_field('wisla_convert_v3', 'wisla_nonce_v3');
            submit_button('🚀 Konwertuj JSON → CSV', 'primary', 'convert_json_v3', false);
            echo '</form>';
        } else {
            echo '<p style="color: #d63638;">❌ Najpierw wgraj plik WislaPlayers.txt przez FTP.</p>';
        }
        echo '</div>';
        
        // Sekcja 2: Export CSV
        if (file_exists($csv_file)) {
            echo '<div style="border: 2px solid #00a32a; padding: 20px; margin: 20px 0; border-radius: 8px;">';
            echo '<h2>📥 KROK 2: Pobierz CSV do edycji</h2>';
            echo '<p>Pobierz plik CSV, edytuj w Excel i wgraj z powrotem.</p>';
            echo '<div style="margin-bottom: 15px;">';
            echo '<a href="?page=wisla-kadra-admin&action=download_csv" class="button button-primary">📥 Pobierz wisla-kadra.csv</a> ';
            echo '<button onclick="copyCSVContent()" class="button button-secondary">📋 Kopiuj zawartość CSV</button>';
            echo '</div>';
            
            // Dodaj niewidoczny textarea z zawartością CSV do kopiowania
            echo '<textarea id="csv-content" style="position: absolute; left: -9999px;">';
            echo esc_textarea(file_get_contents($csv_file));
            echo '</textarea>';
            
            echo '<div style="margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px;">';
            echo '<strong>💡 Instrukcja edycji:</strong><br>';
            echo '• <strong>Metoda 1:</strong> Kliknij "Pobierz CSV" (może nie działać z niektórymi pluginami)<br>';
            echo '• <strong>Metoda 2:</strong> Kliknij "Kopiuj zawartość" → wklej do nowego pliku → zapisz jako .csv<br>';
            echo '• Otwórz plik w Excel lub LibreOffice Calc<br>';
            echo '• Uzupełnij kolumnę "Zdjęcie (URL)" ścieżkami do zdjęć<br>';
            echo '• Możesz dodawać/usuwać zawodników<br>';
            echo '• Zapisz plik z tą samą nazwą';
            echo '</div>';
            echo '</div>';
            
            // JavaScript do kopiowania
            echo '<script>
            function copyCSVContent() {
                var textarea = document.getElementById("csv-content");
                textarea.select();
                textarea.setSelectionRange(0, 99999);
                document.execCommand("copy");
                alert("✅ Zawartość CSV została skopiowana do schowka!\\n\\nNastępnie:\\n1. Otwórz Notatnik\\n2. Wklej (Ctrl+V)\\n3. Zapisz jako wisla-kadra.csv");
            }
            </script>';
        }
        
        // Sekcja 3: Import CSV
        echo '<div style="border: 2px solid #8f5a00; padding: 20px; margin: 20px 0; border-radius: 8px;">';
        echo '<h2>📤 KROK 3: Wgraj edytowany CSV</h2>';
        echo '<p>Wgraj z powrotem edytowany plik CSV.</p>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('wisla_upload_csv', 'wisla_upload_nonce');
        echo '<input type="file" name="csv_file" accept=".csv" required style="margin-right: 10px;">';
        submit_button('📤 Wgraj CSV', 'secondary', 'upload_csv', false);
        echo '</form>';
        echo '</div>';
        
        // Podgląd danych (jeśli CSV istnieje)
        if (file_exists($csv_file)) {
            $this->wisla_show_csv_preview($csv_file);
            
            // Debug: sprawdź czy dane są prawidłowo parsowane
            $test_players = $this->load_wisla_kadra_csv($csv_file);
            echo '<div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 5px;">';
            echo '<h4>🔍 Debug parsowania CSV:</h4>';
            echo '<p><strong>Znalezionych zawodników:</strong> ' . count($test_players) . '</p>';
            if (count($test_players) > 0) {
                echo '<p><strong>Pierwszy zawodnik (test):</strong> ' . esc_html($test_players[0]['imie_nazwisko']) . ' (nr ' . $test_players[0]['numer'] . ', ' . $test_players[0]['pozycja'] . ')</p>';
            }
            echo '</div>';
        }
    }
    
    /**
     * Konwersja JSON → CSV
     */
    private function wisla_convert_json_to_csv_v3() {
        $json_file = get_template_directory() . '/WislaPlayers.txt';
        $csv_file = get_template_directory() . '/wisla-kadra.csv';
        
        // Backup poprzedniej wersji
        if (file_exists($csv_file)) {
            $backup_file = get_template_directory() . '/wisla-kadra-backup-' . date('Y-m-d-H-i-s') . '.csv';
            copy($csv_file, $backup_file);
            echo '<div class="notice notice-info"><p>💾 Utworzono kopię zapasową: ' . basename($backup_file) . '</p></div>';
        }
        
        if (!file_exists($json_file)) {
            echo '<div class="notice notice-error"><p>❌ Plik WislaPlayers.txt nie znaleziony!</p></div>';
            return;
        }
        
        $json_data = file_get_contents($json_file);
        $data = json_decode($json_data, true);
        
        if (!$data || !isset($data['players'])) {
            echo '<div class="notice notice-error"><p>❌ Błąd: Nieprawidłowy format JSON!</p></div>';
            return;
        }
        
        // Nagłówki CSV
        $csv_headers = [
            'Imię i nazwisko', 'Numer koszulki', 'Pozycja', 'Wiek', 'Data urodzenia',
            'Wzrost (cm)', 'Kraj', 'Preferowana noga', 'Wartość rynkowa (€)', 
            'Kontrakt do', 'Zdjęcie (URL)'
        ];
        
        $csv_data = [];
        $csv_data[] = $csv_headers;
        $count = 0;
        
        // Mapowanie krajów
        $country_codes = [
            'Poland' => 'POL', 'Spain' => 'ESP', 'Iraq' => 'IRQ', 'Equatorial Guinea' => 'GNQ',
            'Belgium' => 'BEL', 'Belarus' => 'BLR', 'Austria' => 'AUT', 'Bosnia & Herzegovina' => 'BIH',
            'Montenegro' => 'MNE', 'Faroe Islands' => 'FRO', 'Sweden' => 'SWE', 'Germany' => 'DEU'
        ];
        
        foreach ($data['players'] as $player_data) {
            $player = $player_data['player'];
            
            if (isset($player['team']['name']) && $player['team']['name'] === 'Wisła Płock') {
                
                // Wiek i data
                $age = 'N/A';
                $birth_date = 'N/A';
                if (isset($player['dateOfBirthTimestamp']) && $player['dateOfBirthTimestamp'] > 0) {
                    $age = date('Y') - date('Y', $player['dateOfBirthTimestamp']);
                    $birth_date = date('d.m.Y', $player['dateOfBirthTimestamp']);
                }
                
                // Kontrakt
                $contract_end = 'N/A';
                if (isset($player['contractUntilTimestamp']) && $player['contractUntilTimestamp'] > 0) {
                    $contract_end = date('d.m.Y', $player['contractUntilTimestamp']);
                }
                
                // Noga
                $foot_map = ['Right' => 'Prawa', 'Left' => 'Lewa', 'Both' => 'Obie'];
                $preferred_foot = isset($player['preferredFoot']) ? 
                                 ($foot_map[$player['preferredFoot']] ?? $player['preferredFoot']) : 'N/A';
                
                // Pozycja
                $position_map = ['G' => 'Bramkarz', 'D' => 'Obrońca', 'M' => 'Pomocnik', 'F' => 'Napastnik'];
                $position = isset($player['position']) ? 
                           ($position_map[$player['position']] ?? $player['position']) : 'N/A';
                
                // Kraj
                $country_name = $player['country']['name'] ?? 'N/A';
                $country_code = $country_codes[$country_name] ?? $country_name;
                
                // Wartość
                $market_value = (isset($player['proposedMarketValue']) && $player['proposedMarketValue'] > 0) ? 
                               number_format($player['proposedMarketValue'], 0, '', '') : 'N/A';
                
                // Wzrost
                $height = (isset($player['height']) && $player['height'] > 0) ? $player['height'] : 'N/A';
                
                $csv_data[] = [
                    $player['name'] ?? 'N/A',
                    $player['jerseyNumber'] ?? 'N/A',
                    $position, $age, $birth_date, $height, $country_code,
                    $preferred_foot, $market_value, $contract_end,
                    '' // Puste pole na zdjęcie
                ];
                $count++;
            }
        }
        
        // Zapisz CSV
        $handle = fopen($csv_file, 'w');
        if (!$handle) {
            echo '<div class="notice notice-error"><p>❌ Nie można utworzyć pliku CSV!</p></div>';
            return;
        }
        
        // Zapisz bez BOM - tylko czyste UTF-8
        foreach ($csv_data as $row) {
            fputcsv($handle, $row, ';');
        }
        fclose($handle);
        
        echo '<div class="notice notice-success"><p>';
        echo '✅ <strong>Konwersja zakończona!</strong><br>';
        echo '📊 Znaleziono zawodników: <strong>' . $count . '</strong><br>';
        echo '📁 Plik: <strong>wisla-kadra.csv</strong> został utworzony<br>';
        echo '⏭️ <strong>Następny krok:</strong> Pobierz CSV do edycji';
        echo '</p></div>';
        
        $this->wisla_show_csv_preview($csv_file, 3);
    }
    
    /**
     * Obsługa uploadu CSV
     */
    private function wisla_handle_csv_upload() {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo '<div class="notice notice-error"><p>❌ Błąd podczas uploadu pliku!</p></div>';
            return;
        }
        
        $uploaded_file = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        $csv_file = get_template_directory() . '/wisla-kadra.csv';
        
        // Sprawdź czy to plik CSV
        if (pathinfo($file_name, PATHINFO_EXTENSION) !== 'csv') {
            echo '<div class="notice notice-error"><p>❌ Można wgrywać tylko pliki CSV!</p></div>';
            return;
        }
        
        // Backup starej wersji
        if (file_exists($csv_file)) {
            $backup_file = get_template_directory() . '/wisla-kadra-backup-' . date('Y-m-d-H-i-s') . '.csv';
            copy($csv_file, $backup_file);
        }
        
        // Przenieś wgrany plik
        if (move_uploaded_file($uploaded_file, $csv_file)) {
            echo '<div class="notice notice-success"><p>';
            echo '✅ <strong>Plik CSV został wgrany pomyślnie!</strong><br>';
            echo '📁 Nazwa: <strong>' . esc_html($file_name) . '</strong><br>';
            echo '📊 Rozmiar: <strong>' . size_format(filesize($csv_file)) . '</strong><br>';
            echo '🎯 <strong>Gotowe!</strong> Możesz teraz używać shortcode [wisla_kadra]';
            echo '</p></div>';
            
            $this->wisla_show_csv_preview($csv_file, 5);
        } else {
            echo '<div class="notice notice-error"><p>❌ Nie udało się zapisać pliku na serwerze!</p></div>';
        }
    }
    
    /**
     * Download CSV
     */
    private function wisla_download_csv() {
        $csv_file = get_template_directory() . '/wisla-kadra.csv';
        
        if (!file_exists($csv_file)) {
            wp_die('Plik CSV nie istnieje!');
        }
        
        // Sprawdź czy nagłówki nie zostały już wysłane
        if (headers_sent()) {
            wp_die('❌ Błąd: Nie można pobrać pliku - nagłówki zostały już wysłane przez inne pluginy.<br><br>
                    <strong>Rozwiązanie:</strong><br>
                    1. Skopiuj zawartość pliku CSV powyżej<br>
                    2. Wklej do nowego pliku tekstowego<br>
                    3. Zapisz jako "wisla-kadra.csv"<br><br>
                    <a href="?page=wisla-kadra-admin" class="button">← Powrót do konwertera</a>');
        }
        
        // Wyczyść wszystkie poprzednie bufory
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Rozpocznij nowy bufor
        ob_start();
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wisla-kadra.csv"');
        header('Content-Length: ' . filesize($csv_file));
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        
        readfile($csv_file);
        
        ob_end_flush();
        exit;
    }
    
    /**
     * Podgląd CSV
     */
    private function wisla_show_csv_preview($csv_file, $max_rows = 5) {
        if (!file_exists($csv_file)) return;
        
        // Usuń BOM jeśli istnieje
        $content = file_get_contents($csv_file);
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
            file_put_contents($csv_file, $content);
        }
        
        echo '<h3>👀 Podgląd pliku CSV:</h3>';
        echo '<div style="overflow-x: auto; border: 1px solid #ddd; border-radius: 5px;">';
        echo '<table class="wp-list-table widefat fixed striped">';
        
        $handle = fopen($csv_file, 'r');
        
        // Sprawdź separator
        $first_line = fgets($handle);
        rewind($handle);
        $separator = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
        
        $row_count = 0;
        
        while (($data = fgetcsv($handle, 1000, $separator)) !== FALSE && $row_count < $max_rows + 1) {
            // Czyść dane
            $cleaned_data = array_map(function($field) {
                return trim($field, " \t\n\r\0\x0B\"");
            }, $data);
            
            echo '<tr>';
            foreach ($cleaned_data as $cell) {
                if ($row_count === 0) {
                    echo '<th style="white-space: nowrap; font-size: 12px; max-width: 120px; overflow: hidden;">' . esc_html($cell) . '</th>';
                } else {
                    echo '<td style="font-size: 12px; max-width: 120px; overflow: hidden;" title="' . esc_attr($cell) . '">' . esc_html(substr($cell, 0, 30)) . (strlen($cell) > 30 ? '...' : '') . '</td>';
                }
            }
            echo '</tr>';
            $row_count++;
        }
        
        fclose($handle);
        echo '</table>';
        echo '</div>';
        
        // Licznik wszystkich wierszy i info o separatorze
        $total_lines = count(file($csv_file)) - 1; // -1 dla nagłówka
        echo '<p><em>Separator: <strong>' . $separator . '</strong> | ';
        if ($total_lines > $max_rows) {
            echo 'Pokazano ' . $max_rows . ' z ' . $total_lines . ' zawodników...</em></p>';
        } else {
            echo 'Wszystkich zawodników: ' . $total_lines . '</em></p>';
        }
    }
    
    /**
     * Funkcje pomocnicze dla kadry Wisły Płock
     */
    
    /**
     * Flagi krajów (emoji)
     */
    private function wisla_get_country_flag_only($country_code) {
        $flags = [
            'POL' => '🇵🇱',  // Polska
            'ESP' => '🇪🇸',  // Hiszpania
            'IRQ' => '🇮🇶',  // Irak
            'GNQ' => '🇬🇶',  // Gwinea Równikowa
            'BEL' => '🇧🇪',  // Belgia
            'BLR' => '🇧🇾',  // Białoruś
            'AUT' => '🇦🇹',  // Austria
            'BIH' => '🇧🇦',  // Bośnia i Hercegowina
            'MNE' => '🇲🇪',  // Czarnogóra
            'FRO' => '🇫🇴',  // Wyspy Owcze
            'SWE' => '🇸🇪',  // Szwecja
            'DEU' => '🇩🇪',  // Niemcy
            'GEO' => '🇬🇪',  // Gruzja
            'GRC' => '🇬🇷',  // Grecja
            'FRA' => '🇫🇷',  // Francja
            'ITA' => '🇮🇹',  // Włochy
            'PRT' => '🇵🇹',  // Portugalia
            'NLD' => '🇳🇱',  // Holandia
            'CZE' => '🇨🇿',  // Czechy
            'SVK' => '🇸🇰',  // Słowacja
            'HUN' => '🇭🇺',  // Węgry
            'ROU' => '🇷🇴',  // Rumunia
            'BGR' => '🇧🇬',  // Bułgaria
            'HRV' => '🇭🇷',  // Chorwacja
            'SRB' => '🇷🇸',  // Serbia
            'SVN' => '🇸🇮',  // Słowenia
            'LTU' => '🇱🇹',  // Litwa
            'LVA' => '🇱🇻',  // Łotwa
            'EST' => '🇪🇪',  // Estonia
            'UKR' => '🇺🇦',  // Ukraina
            'RUS' => '🇷🇺',  // Rosja
            'NOR' => '🇳🇴',  // Norwegia
            'DNK' => '🇩🇰',  // Dania
            'FIN' => '🇫🇮',  // Finlandia
            'ISL' => '🇮🇸',  // Islandia
            'GBR' => '🇬🇧',  // Wielka Brytania
            'IRL' => '🇮🇪',  // Irlandia
            'CHE' => '🇨🇭',  // Szwajcaria
            'TUR' => '🇹🇷',  // Turcja
            'ALB' => '🇦🇱',  // Albania
            'MKD' => '🇲🇰',  // Macedonia Północna
            'KOS' => '🇽🇰',  // Kosowo
            'MLT' => '🇲🇹',  // Malta
            'CYP' => '🇨🇾',  // Cypr
            'LUX' => '🇱🇺',  // Luksemburg
            'AND' => '🇦🇩',  // Andora
            'SMR' => '🇸🇲',  // San Marino
            'VAT' => '🇻🇦',  // Watykan
            'MCO' => '🇲🇨',  // Monako
            'LIE' => '🇱🇮'   // Liechtenstein
        ];
        
        return $flags[$country_code] ?? '🌍';
    }
    
    /**
     * Statystyki drużyny
     */
    private function wisla_get_team_stats($players) {
        $ages = array_filter(array_map('intval', array_column($players, 'wiek')));
        $heights = array_filter(array_map('intval', array_column($players, 'wzrost')));
        $countries = array_unique(array_column($players, 'kraj'));
        
        return [
            'sredni_wiek' => !empty($ages) ? array_sum($ages) / count($ages) : 0,
            'sredni_wzrost' => !empty($heights) ? round(array_sum($heights) / count($heights)) : 0,
            'kraje' => count($countries)
        ];
    }
    
    /**
     * Generuj Schema.org dla całej drużyny
     */
    private function wisla_generate_team_schema($players) {
        // Podstawowe informacje o klubie
        $team_schema = [
            "@context" => "https://schema.org",
            "@type" => "SportsTeam",
            "name" => "Wisła Płock",
            "sport" => "Piłka nożna",
            "description" => "Oficjalna kadra klubu piłkarskiego Wisła Płock - Ekstraklasa",
            "url" => get_permalink(),
            "logo" => "https://nafciarski.pl/wp-content/uploads/logo-wisla-plock.png",
            "foundingDate" => "1947",
            "location" => [
                "@type" => "Place",
                "name" => "Płock",
                "address" => [
                    "@type" => "PostalAddress",
                    "addressLocality" => "Płock",
                    "addressCountry" => "PL"
                ]
            ],
            "member" => []
        ];
        
        // Dodaj każdego zawodnika
        foreach ($players as $player) {
            $player_schema = $this->wisla_generate_player_schema($player);
            $team_schema["member"][] = $player_schema;
        }
        
        // Generuj JSON-LD
        $json_ld = '<script type="application/ld+json">' . "\n";
        $json_ld .= json_encode($team_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json_ld .= "\n" . '</script>' . "\n";
        
        return $json_ld;
    }
    
    /**
     * Generuj Schema.org dla pojedynczego zawodnika
     */
    private function wisla_generate_player_schema($player) {
        // Mapowanie pozycji na angielski (dla Google)
        $position_mapping = [
            'Bramkarz' => 'Goalkeeper',
            'Obrońca' => 'Defender', 
            'Pomocnik' => 'Midfielder',
            'Napastnik' => 'Forward'
        ];
        
        // Mapowanie krajów na kody ISO
        $country_mapping = [
            'POL' => 'Poland',
            'ESP' => 'Spain',
            'IRQ' => 'Iraq',
            'GNQ' => 'Equatorial Guinea',
            'BEL' => 'Belgium',
            'BLR' => 'Belarus',
            'AUT' => 'Austria',
            'BIH' => 'Bosnia and Herzegovina',
            'MNE' => 'Montenegro',
            'FRO' => 'Faroe Islands',
            'SWE' => 'Sweden',
            'DEU' => 'Germany'
        ];
        
        $schema = [
            "@type" => "Person",
            "name" => $player['imie_nazwisko'],
            "jobTitle" => $position_mapping[$player['pozycja']] ?? $player['pozycja'],
            "sport" => "Piłka nożna",
            "memberOf" => [
                "@type" => "SportsTeam",
                "name" => "Wisła Płock"
            ]
        ];
        
        // Dodaj opcjonalne dane jeśli dostępne
        if ($player['wiek'] !== 'N/A' && is_numeric($player['wiek'])) {
            $schema["age"] = intval($player['wiek']);
        }
        
        if ($player['data_urodzenia'] !== 'N/A') {
            // Konwertuj datę polską na format ISO (dd.mm.yyyy -> yyyy-mm-dd)
            $date_parts = explode('.', $player['data_urodzenia']);
            if (count($date_parts) === 3) {
                $schema["birthDate"] = $date_parts[2] . '-' . 
                                      str_pad($date_parts[1], 2, '0', STR_PAD_LEFT) . '-' . 
                                      str_pad($date_parts[0], 2, '0', STR_PAD_LEFT);
            }
        }
        
        if ($player['wzrost'] !== 'N/A' && is_numeric($player['wzrost'])) {
            $schema["height"] = $player['wzrost'] . " cm";
        }
        
        if ($player['kraj'] !== 'N/A') {
            $schema["nationality"] = $country_mapping[$player['kraj']] ?? $player['kraj'];
        }
        
        if (!empty($player['zdjecie'])) {
            $schema["image"] = $player['zdjecie'];
        }
        
        // Dodaj numer koszulki jako dodatkową właściwość
        if ($player['numer'] !== 'N/A') {
            $schema["identifier"] = "Numer " . $player['numer'];
        }
        
        return $schema;
    }
    
    /**
     * CSS dla kadry Wisły Płock
     */
    private function wisla_kadra_css() {
        return '<style>
    .wisla-kadra-container {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .wisla-error {
        background: #f8d7da;
        color: #721c24;
        padding: 15px;
        border-radius: 8px;
        margin: 20px 0;
        text-align: center;
    }
    
    .wisla-stats {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: white;
        padding: 20px;
        border-radius: 12px;
        margin: 20px 0;
        text-align: center;
    }
    
    .wisla-stats h3 {
        margin: 0 0 15px 0;
        font-size: 1.6em;
    }
    
    .wisla-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 15px;
    }
    
    .stat-item {
        background: rgba(255,255,255,0.1);
        padding: 15px;
        border-radius: 8px;
        backdrop-filter: blur(10px);
    }
    
    .stat-item strong {
        font-size: 2.2em;
        display: block;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .stat-item {
        font-size: 1.1em;
    }
    
    .wisla-cards-grid {
        display: grid;
        gap: 20px;
        margin: 20px 0;
    }
    
    .wisla-cards-grid.columns-1 { grid-template-columns: 1fr; }
    .wisla-cards-grid.columns-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
    .wisla-cards-grid.columns-3 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
    .wisla-cards-grid.columns-4 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
    
    .wisla-player-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        overflow: hidden;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: 1px solid #e1e5e9;
    }
    
    .wisla-player-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15);
    }
    
    .player-photo {
        position: relative;
        height: 280px;
        overflow: hidden;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .player-photo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        object-position: center;
        transition: transform 0.3s ease;
        background: rgba(255,255,255,0.1);
    }
    
    .wisla-player-card:hover .player-photo img {
        transform: scale(1.05);
    }
    
    .player-position-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.8);
        color: white;
        min-width: 45px;
        height: 35px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 18px;
        font-weight: bold;
        font-size: 0.85em;
        padding: 0 8px;
        letter-spacing: 0.5px;
    }
    
    .player-info {
        padding: 25px;
    }
    
    .player-name {
        margin: 0 0 20px 0;
        font-size: 1.6em;
        font-weight: 600;
        color: #2c3e50;
        line-height: 1.2;
    }
    
    .country-flag {
        font-size: 1.4em;
        float: right;
    }
    
    .player-details {
        font-size: 1.1em;
        color: #666;
        line-height: 1.4;
    }
    
    .detail-row {
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .detail-row span {
        font-weight: 500;
        color: #444;
        font-size: 1.05em;
    }
    
    .wisla-table-container {
        overflow-x: auto;
        margin: 20px 0;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    }
    
    .wisla-players-table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        font-size: 1.1em;
    }
    
    .wisla-players-table th {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: white;
        padding: 18px 12px;
        text-align: left;
        font-weight: 600;
        font-size: 1.05em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .wisla-players-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e1e5e9;
        transition: background-color 0.2s ease;
        vertical-align: middle;
    }
    
    .wisla-players-table tr:hover td {
        background-color: #f8f9fa;
    }
    
    .player-number-cell {
        font-weight: bold;
        color: #3498db;
        text-align: center;
        width: 50px;
    }
    
    .player-photo-cell {
        width: 60px;
        text-align: center;
    }
    
    .table-photo {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #e1e5e9;
    }
    
    .player-name-cell {
        font-weight: 600;
        color: #2c3e50;
    }
    
    @media (max-width: 768px) {
        .wisla-cards-grid {
            grid-template-columns: 1fr !important;
        }
        
        .wisla-stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
        
        .wisla-players-table {
            font-size: 0.8em;
        }
        
        .wisla-players-table th,
        .wisla-players-table td {
            padding: 8px 5px;
        }
    }
    </style>';
    }
    
    /**
     * Pobierz czysty CSS content bez tagów style
     */
    private function get_kadra_css_content() {
        $css = $this->wisla_kadra_css();
        // Usuń tagi <style> i </style>
        $css = str_replace('<style>', '', $css);
        $css = str_replace('</style>', '', $css);
        return trim($css);
    }
    
    /**
     * Pobierz poprawione style CSS z wyższą specyfikacją
     */
    private function get_improved_kadra_css() {
        return '
        /* SofaScore Plugin - Kadra Styles with Higher Specificity */
        body .wisla-kadra-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
            max-width: 1200px !important;
            margin: 0 auto !important;
        }
        
        body .wisla-player-card .player-photo img {
            width: 100% !important;
            height: 100% !important;
            object-fit: contain !important;
            object-position: center !important;
            transition: transform 0.3s ease !important;
            background: rgba(255,255,255,0.1) !important;
        }
        
        body .wisla-players-table .table-photo {
            width: 50px !important;
            height: 50px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 2px solid #e1e5e9 !important;
        }
        
        body .wisla-cards-grid {
            display: grid !important;
            gap: 20px !important;
            margin: 20px 0 !important;
        }
        
        body .wisla-cards-grid.columns-1 { grid-template-columns: 1fr !important; }
        body .wisla-cards-grid.columns-2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)) !important; }
        body .wisla-cards-grid.columns-3 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)) !important; }
        body .wisla-cards-grid.columns-4 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)) !important; }
        
        body .wisla-player-card {
            background: #fff !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1) !important;
            overflow: hidden !important;
            transition: transform 0.3s ease, box-shadow 0.3s ease !important;
            border: 1px solid #e1e5e9 !important;
        }
        
        body .player-photo {
            position: relative !important;
            height: 280px !important;
            overflow: hidden !important;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
        }
        
        @media (max-width: 768px) {
            body .wisla-cards-grid {
                grid-template-columns: 1fr !important;
            }
        }
        ';
    }
    
    /**
     * Rozszerzona funkcja renderowania kadry (z functions.php)
     */
    private function render_wisla_kadra_enhanced($players, $atts) {
        // Sortowanie
        $players = $this->sort_wisla_players($players, $atts['sortowanie']);
        
        ob_start();
        ?>
        <div class="wisla-kadra-container">
            <div class="kadra-header">
                <h3>Kadra Wisły Płock</h3>
            </div>
            
            <?php if ($atts['styl'] === 'tabela'): ?>
                <?php echo $this->wisla_generate_table($players); ?>
            <?php else: ?>
                <?php echo $this->wisla_generate_cards($players, $atts['kolumny']); ?>
            <?php endif; ?>
            
            <?php 
            // Statystyki na końcu
            $stats = $this->wisla_get_team_stats($players);
            ?>
            <div class="wisla-stats">
                <h3>📊 Statystyki kadry</h3>
                <div class="wisla-stats-grid">
                    <div class="stat-item"><strong><?php echo count($players); ?></strong><br>Zawodników</div>
                    <div class="stat-item"><strong><?php echo number_format($stats['sredni_wiek'], 1); ?></strong><br>Średni wiek</div>
                    <div class="stat-item"><strong><?php echo $stats['sredni_wzrost']; ?></strong><br>Średni wzrost</div>
                    <div class="stat-item"><strong><?php echo $stats['kraje']; ?></strong><br>Narodowości</div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generuj karty zawodników
     */
    private function wisla_generate_cards($players, $columns) {
        $output = '<div class="wisla-cards-grid columns-' . $columns . '">';
        
        foreach ($players as $player) {
            $output .= '<div class="wisla-player-card">';
            
            // Zdjęcie
            if (!empty($player['zdjecie'])) {
                $output .= '<div class="player-photo">';
                $output .= '<img src="' . esc_url($player['zdjecie']) . '" alt="' . esc_attr($player['imie_nazwisko']) . '" loading="lazy">';
                $output .= '<div class="player-position-badge">' . $this->wisla_get_position_symbol($player['pozycja']) . '</div>';
                $output .= '</div>';
            }
            
            // Dane zawodnika
            $output .= '<div class="player-info">';
            $output .= '<h4 class="player-name">' . esc_html($player['imie_nazwisko']) . '</h4>';
            
            $output .= '<div class="player-details">';
            $output .= '<div class="detail-row"><span>Wiek:</span> ' . $player['wiek'] . ' lat</div>';
            if ($player['data_urodzenia'] !== 'N/A') {
                $output .= '<div class="detail-row"><span>Data urodzenia:</span> ' . $player['data_urodzenia'] . '</div>';
            }
            if ($player['wzrost'] !== 'N/A') {
                $output .= '<div class="detail-row"><span>Wzrost:</span> ' . $player['wzrost'] . ' cm</div>';
            }
            $output .= '<div class="detail-row"><span>Kraj:</span> <span class="country-flag">' . $this->wisla_get_country_flag_only($player['kraj']) . ' ' . esc_html($player['kraj']) . '</span></div>';
            if ($player['noga'] !== 'N/A') {
                $output .= '<div class="detail-row"><span>Noga:</span> ' . $player['noga'] . '</div>';
            }
            if ($player['kontrakt_do'] !== 'N/A') {
                $output .= '<div class="detail-row"><span>Kontrakt do:</span> ' . $player['kontrakt_do'] . '</div>';
            }
            $output .= '</div>';
            
            $output .= '</div>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        return $output;
    }
    
    /**
     * Generuj tabelę
     */
    private function wisla_generate_table($players) {
        $output = '<div class="wisla-table-container">';
        $output .= '<table class="wisla-players-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>Nr</th><th>Zdjęcie</th><th>Zawodnik</th><th>Pozycja</th><th>Wiek</th><th>Wzrost</th><th>Kraj</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';
        
        foreach ($players as $player) {
            $output .= '<tr>';
            $output .= '<td class="player-number-cell">' . $player['numer'] . '</td>';
            $output .= '<td class="player-photo-cell">';
            if (!empty($player['zdjecie'])) {
                $output .= '<img src="' . esc_url($player['zdjecie']) . '" alt="' . esc_attr($player['imie_nazwisko']) . '" class="table-photo">';
            }
            $output .= '</td>';
            $output .= '<td class="player-name-cell">' . esc_html($player['imie_nazwisko']) . '</td>';
            $output .= '<td>' . esc_html($player['pozycja']) . '</td>';
            $output .= '<td>' . $player['wiek'] . '</td>';
            $output .= '<td>' . ($player['wzrost'] !== 'N/A' ? $player['wzrost'] . ' cm' : '-') . '</td>';
            $output .= '<td><span class="country-flag">' . $this->wisla_get_country_flag_only($player['kraj']) . ' ' . esc_html($player['kraj']) . '</span></td>';
            $output .= '</tr>';
        }
        
        $output .= '</tbody>';
        $output .= '</table>';
        $output .= '</div>';
        
        return $output;
    }
    
    /**
     * Funkcja do wyświetlania symboli pozycji
     */
    private function wisla_get_position_symbol($position) {
        $symbols = [
            'Bramkarz' => 'B',
            'Obrońca' => 'O',
            'Pomocnik' => 'P',
            'Napastnik' => 'N'
        ];
        
        return $symbols[$position] ?? '?';
    }

    /**
     * Pobierz skład drużyny
     */
    public function get_team_squad($team_id = '3122') {
        $endpoint = "/team/{$team_id}/players";
        return $this->make_api_request($endpoint);
    }

    /**
     * Pobierz szczegóły drużyny
     */
    public function get_team_details($team_id = '3122') {
        $endpoint = "/team/{$team_id}";
        return $this->make_api_request($endpoint);
    }
    
    // ===============================================
    // NOWY SYSTEM KADRY - API + BAZA DANYCH
    // ===============================================
    
    /**
     * Konwertuj dane API na format bazy danych
     */
    private function convert_api_squad_to_database($api_data, $team_id = '3122') {
        if (!isset($api_data['players']) || !is_array($api_data['players'])) {
            return array('success' => false, 'message' => 'Nieprawidłowy format danych API');
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        $inserted_count = 0;
        $updated_count = 0;
        $errors = array();
        
        // Mapowanie pozycji
        $position_map = array(
            'G' => 'Bramkarz',
            'D' => 'Obrońca', 
            'M' => 'Pomocnik',
            'F' => 'Napastnik'
        );
        
        // Mapowanie krajów - już nie potrzebne, używamy bezpośrednio alpha3 z API
        
        foreach ($api_data['players'] as $player_data) {
            $player = $player_data['player'] ?? $player_data;
            
            // Sprawdź czy to zawodnik Wisły Płock
            if (!isset($player['team']['name']) || stripos($player['team']['name'], 'Wisła') === false) {
                continue;
            }
            
            // Przygotuj dane
            $api_id = (string)($player['id'] ?? '');
            $name = $player['name'] ?? 'Nieznany';
            $position = $position_map[$player['position'] ?? ''] ?? ($player['position'] ?? 'Nieznana');
            $jersey_number = intval($player['jerseyNumber'] ?? 0);
            
            // Wiek i data urodzenia
            $age = null;
            $birth_date = null;
            if (isset($player['dateOfBirthTimestamp']) && $player['dateOfBirthTimestamp'] > 0) {
                $age = date('Y') - date('Y', $player['dateOfBirthTimestamp']);
                $birth_date = date('Y-m-d', $player['dateOfBirthTimestamp']);
            }
            
            // Kraj - użyj bezpośrednio alpha3 z API
            $country_code = $player['country']['alpha3'] ?? ($player['country']['name'] ?? '');
            
            // Noga
            $foot_map = array('Right' => 'Prawa', 'Left' => 'Lewa', 'Both' => 'Obie');
            $preferred_foot = $foot_map[$player['preferredFoot'] ?? ''] ?? null;
            
            // Kontrakt
            $contract_end = null;
            if (isset($player['contractUntilTimestamp']) && $player['contractUntilTimestamp'] > 0) {
                $contract_end = date('Y-m-d', $player['contractUntilTimestamp']);
            }
            
            // Sprawdź czy zawodnik już istnieje
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, zdjecie_id FROM {$table_name} WHERE api_id = %s",
                $api_id
            ));
            
            $data = array(
                'api_id' => $api_id,
                'team_id' => $team_id,
                'imie_nazwisko' => $name,
                'pozycja' => $position,
                'numer' => $jersey_number > 0 ? $jersey_number : null,
                'wiek' => $age,
                'data_urodzenia' => $birth_date,
                'kraj' => $country_code,
                'noga' => $preferred_foot,
                'kontrakt_do' => $contract_end,
                'status' => 'active'
            );
            
            if ($existing) {
                // Aktualizuj istniejącego (zachowaj zdjęcie)
                $data['zdjecie_id'] = $existing->zdjecie_id;
                $result = $wpdb->update($table_name, $data, array('id' => $existing->id));
                if ($result !== false) {
                    $updated_count++;
                } else {
                    $errors[] = "Błąd aktualizacji zawodnika: {$name}";
                }
            } else {
                // Wstaw nowego
                $result = $wpdb->insert($table_name, $data);
                if ($result) {
                    $inserted_count++;
                } else {
                    $errors[] = "Błąd dodawania zawodnika: {$name}";
                }
            }
        }
        
        return array(
            'success' => true,
            'inserted' => $inserted_count,
            'updated' => $updated_count,
            'errors' => $errors,
            'message' => "Dodano: {$inserted_count}, Zaktualizowano: {$updated_count}"
        );
    }
    
    /**
     * AJAX: Pobierz skład z API
     */
    public function ajax_fetch_wisla_squad_api() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $team_id = sanitize_text_field($_POST['team_id'] ?? '3122');
        
        // Pobierz dane z API
        $api_result = $this->get_team_squad($team_id);
        
        if (!$api_result['success']) {
            wp_send_json_error(array(
                'message' => 'Błąd pobierania danych z API: ' . $api_result['error']
            ));
        }
        
        // Konwertuj i zapisz do bazy
        $db_result = $this->convert_api_squad_to_database($api_result['data'], $team_id);
        
        if ($db_result['success']) {
            wp_send_json_success($db_result);
        } else {
            wp_send_json_error($db_result);
        }
    }
    
    /**
     * AJAX: Aktualizuj wybranych zawodników
     */
    public function ajax_update_selected_players() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_ids = $_POST['player_ids'] ?? array();
        $team_id = sanitize_text_field($_POST['team_id'] ?? '3122');
        
        if (empty($player_ids) || !is_array($player_ids)) {
            wp_send_json_error(array('message' => 'Nie wybrano zawodników do aktualizacji'));
        }
        
        // Pobierz dane z API
        $api_result = $this->get_team_squad($team_id);
        
        if (!$api_result['success']) {
            wp_send_json_error(array(
                'message' => 'Błąd pobierania danych z API: ' . $api_result['error']
            ));
        }
        
        // Filtruj tylko wybranych zawodników z API
        $filtered_data = array('players' => array());
        foreach ($api_result['data']['players'] as $player_data) {
            $player = $player_data['player'] ?? $player_data;
            if (in_array((string)($player['id'] ?? ''), $player_ids)) {
                $filtered_data['players'][] = $player_data;
            }
        }
        
        // Aktualizuj w bazie
        $db_result = $this->convert_api_squad_to_database($filtered_data, $team_id);
        
        if ($db_result['success']) {
            wp_send_json_success($db_result);
        } else {
            wp_send_json_error($db_result);
        }
    }
    
    /**
     * AJAX: Usuń wybranych zawodników
     */
    public function ajax_delete_selected_players() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_ids = $_POST['player_ids'] ?? array();
        
        if (empty($player_ids) || !is_array($player_ids)) {
            wp_send_json_error(array('message' => 'Nie wybrano zawodników do usunięcia'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        $deleted_count = 0;
        
        foreach ($player_ids as $id) {
            $result = $wpdb->update(
                $table_name,
                array('status' => 'deleted'),
                array('id' => intval($id)),
                array('%s'),
                array('%d')
            );
            
            if ($result !== false) {
                $deleted_count++;
            }
        }
        
        wp_send_json_success(array(
            'message' => "Usunięto {$deleted_count} zawodników",
            'deleted' => $deleted_count
        ));
    }
    
    /**
     * AJAX: Upload zdjęcia zawodnika
     */
    public function ajax_upload_player_photo() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_id = intval($_POST['player_id'] ?? 0);
        
        if (!$player_id) {
            wp_send_json_error(array('message' => 'Nieprawidłowy ID zawodnika'));
        }
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'Błąd uploadu pliku'));
        }
        
        // Upload przez WordPress Media Library
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('photo', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => 'Błąd uploadu: ' . $attachment_id->get_error_message()));
        }
        
        // Aktualizuj bazę danych
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $result = $wpdb->update(
            $table_name,
            array('zdjecie_id' => $attachment_id),
            array('id' => $player_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            $photo_url = wp_get_attachment_image_url($attachment_id, 'medium');
            wp_send_json_success(array(
                'message' => 'Zdjęcie zostało przesłane',
                'photo_url' => $photo_url,
                'attachment_id' => $attachment_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Błąd zapisywania w bazie danych'));
        }
    }
    
    /**
     * AJAX: Przypisz zdjęcie z Media Library do zawodnika
     */
    public function ajax_attach_player_photo() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_id = intval($_POST['player_id'] ?? 0);
        $attachment_id = intval($_POST['attachment_id'] ?? 0);
        
        if (!$player_id || !$attachment_id) {
            wp_send_json_error(array('message' => 'Nieprawidłowe parametry'));
        }
        
        // Sprawdź czy attachment istnieje i czy to obrazek
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            wp_send_json_error(array('message' => 'Nieprawidłowe zdjęcie'));
        }
        
        $mime_type = get_post_mime_type($attachment_id);
        if (substr($mime_type, 0, 6) !== 'image/') {
            wp_send_json_error(array('message' => 'Plik musi być obrazkiem'));
        }
        
        // Aktualizuj bazę danych
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $result = $wpdb->update(
            $table_name,
            array('zdjecie_id' => $attachment_id),
            array('id' => $player_id),
            array('%d'),
            array('%d')
        );
        
        if ($result !== false) {
            $photo_url = wp_get_attachment_image_url($attachment_id, 'medium');
            wp_send_json_success(array(
                'message' => 'Zdjęcie zostało przypisane',
                'photo_url' => $photo_url,
                'attachment_id' => $attachment_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Błąd zapisywania w bazie danych'));
        }
    }
    
    /**
     * AJAX: Edytuj dane zawodnika
     */
    public function ajax_edit_player_data() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_id = intval($_POST['player_id'] ?? 0);
        
        if (!$player_id) {
            wp_send_json_error(array('message' => 'Nieprawidłowy ID zawodnika'));
        }
        
        // Pobierz i waliduj dane
        $data = array();
        
        if (isset($_POST['imie_nazwisko'])) {
            $data['imie_nazwisko'] = sanitize_text_field($_POST['imie_nazwisko']);
        }
        
        if (isset($_POST['numer'])) {
            $numer = intval($_POST['numer']);
            $data['numer'] = ($numer > 0 && $numer <= 99) ? $numer : null;
        }
        
        if (isset($_POST['pozycja'])) {
            $allowed_positions = array('Bramkarz', 'Obrońca', 'Pomocnik', 'Napastnik');
            $pozycja = sanitize_text_field($_POST['pozycja']);
            if (in_array($pozycja, $allowed_positions)) {
                $data['pozycja'] = $pozycja;
            }
        }
        
        if (isset($_POST['wiek'])) {
            $wiek = intval($_POST['wiek']);
            $data['wiek'] = ($wiek > 15 && $wiek < 50) ? $wiek : null;
        }
        
        if (isset($_POST['data_urodzenia'])) {
            $date = sanitize_text_field($_POST['data_urodzenia']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $data['data_urodzenia'] = $date;
            }
        }
        
        if (isset($_POST['kraj'])) {
            $data['kraj'] = sanitize_text_field($_POST['kraj']);
        }
        
        if (isset($_POST['noga'])) {
            $allowed_feet = array('Prawa', 'Lewa', 'Obie');
            $noga = sanitize_text_field($_POST['noga']);
            if (in_array($noga, $allowed_feet)) {
                $data['noga'] = $noga;
            }
        }
        
        if (isset($_POST['kontrakt_do'])) {
            $date = sanitize_text_field($_POST['kontrakt_do']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || empty($date)) {
                $data['kontrakt_do'] = empty($date) ? null : $date;
            }
        }
        
        if (empty($data)) {
            wp_send_json_error(array('message' => 'Brak danych do aktualizacji'));
        }
        
        // Aktualizuj bazę danych
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $result = $wpdb->update(
            $table_name,
            $data,
            array('id' => $player_id),
            array_fill(0, count($data), '%s'),
            array('%d')
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Dane zawodnika zostały zaktualizowane',
                'updated_fields' => array_keys($data)
            ));
        } else {
            wp_send_json_error(array('message' => 'Błąd zapisywania w bazie danych'));
        }
    }
    
    /**
     * AJAX: Pobierz dane zawodnika
     */
    public function ajax_get_player_data() {
        check_ajax_referer('sofascore_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $player_id = intval($_POST['player_id'] ?? 0);
        
        if (!$player_id) {
            wp_send_json_error(array('message' => 'Nieprawidłowy ID zawodnika'));
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $player = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d AND status = %s",
            $player_id, 'active'
        ), ARRAY_A);
        
        if (!$player) {
            wp_send_json_error(array('message' => 'Zawodnik nie został znaleziony'));
        }
        
        // Przygotuj dane do formularza
        $form_data = array(
            'id' => $player['id'],
            'imie_nazwisko' => $player['imie_nazwisko'],
            'numer' => $player['numer'],
            'pozycja' => $player['pozycja'],
            'wiek' => $player['wiek'],
            'data_urodzenia' => $player['data_urodzenia'],
            'kraj' => $player['kraj'],
            'noga' => $player['noga'],
            'kontrakt_do' => $player['kontrakt_do']
        );
        
        wp_send_json_success(array(
            'player' => $form_data
        ));
    }
    
    /**
     * Wczytaj kadrę z bazy danych (zamiast CSV)
     */
    private function load_wisla_kadra_database() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY numer ASC",
            'active'
        ), ARRAY_A);
        
        if (!$players) {
            return array();
        }
        
        // Przekonwertuj format bazy na format oczekiwany przez istniejące funkcje renderowania
        $formatted_players = array();
        foreach ($players as $player) {
            // URL zdjęcia z WordPress Media Library lub domyślne
            $zdjecie_url = '';
            if ($player['zdjecie_id']) {
                $zdjecie_url = wp_get_attachment_image_url($player['zdjecie_id'], 'medium');
            }
            
            // Jeśli brak zdjęcia, użyj domyślnego (dodamy później)
            if (empty($zdjecie_url)) {
                $zdjecie_url = $this->get_default_player_photo();
            }
            
            $formatted_players[] = array(
                'imie_nazwisko' => $player['imie_nazwisko'],
                'numer' => $player['numer'] ?: 'N/A',
                'pozycja' => $player['pozycja'] ?: 'N/A',
                'wiek' => $player['wiek'] ?: 'N/A',
                'data_urodzenia' => $player['data_urodzenia'] ? date('d.m.Y', strtotime($player['data_urodzenia'])) : 'N/A',
                'wzrost' => 'N/A', // API nie ma wzrostu
                'kraj' => $player['kraj'] ?: 'N/A',
                'noga' => $player['noga'] ?: 'N/A',
                'wartosc' => 'N/A', // Nie przechowujemy wartości
                'kontrakt_do' => $player['kontrakt_do'] ? date('d.m.Y', strtotime($player['kontrakt_do'])) : 'N/A',
                'zdjecie' => $zdjecie_url
            );
        }
        
        return $formatted_players;
    }
    
    /**
     * Pobierz URL domyślnego zdjęcia zawodnika
     */
    private function get_default_player_photo() {
        // Sprawdź czy plik PNG istnieje
        $png_path = SOFASCORE_PLUGIN_PATH . 'assets/default-player.png';
        $jpg_path = SOFASCORE_PLUGIN_PATH . 'assets/default-player.jpg';
        
        if (file_exists($png_path)) {
            return SOFASCORE_PLUGIN_URL . 'assets/default-player.png';
        } elseif (file_exists($jpg_path)) {
            // Fallback do JPG jeśli PNG nie istnieje
            return SOFASCORE_PLUGIN_URL . 'assets/default-player.jpg';
        }
        
        // Fallback - wygeneruj prostą ikonę jako data URI jeśli nic nie ma
        return 'data:image/svg+xml;base64,' . base64_encode('
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
            <rect width="100" height="100" fill="#667eea"/>
            <circle cx="50" cy="30" r="15" fill="white"/>
            <rect x="35" y="50" width="30" height="25" rx="3" fill="white"/>
            <text x="50" y="85" text-anchor="middle" fill="white" font-size="8">Wisła</text>
        </svg>');
    }
    
    /**
     * Renderuj tabelę zarządzania zawodnikami w panelu admin
     */
    private function render_players_management_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sofascore_players';
        
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE status = %s ORDER BY numer ASC, imie_nazwisko ASC",
            'active'
        ), ARRAY_A);
        
        if (empty($players)) {
            echo '<div class="notice notice-warning"><p>Brak zawodników w bazie danych. Użyj przycisku "Pobierz z API" powyżej.</p></div>';
            return;
        }
        
        echo '<div style="overflow-x: auto;">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width: 40px;"><input type="checkbox" id="select-all-players"></th>';
        echo '<th>Zdjęcie</th>';
        echo '<th>Zawodnik</th>';
        echo '<th>Nr</th>';
        echo '<th>Pozycja</th>';
        echo '<th>Wiek</th>';
        echo '<th>Kraj</th>';
        echo '<th>Noga</th>';
        echo '<th>Kontrakt</th>';
        echo '<th>Ostatnia aktualizacja</th>';
        echo '<th>Akcje</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($players as $player) {
            $photo_url = '';
            if ($player['zdjecie_id']) {
                $photo_url = wp_get_attachment_image_url($player['zdjecie_id'], 'thumbnail');
            }
            
            if (empty($photo_url)) {
                $photo_url = $this->get_default_player_photo();
            }
            
            $last_update = $player['updated_at'] ? date('d.m.Y H:i', strtotime($player['updated_at'])) : 'Nieznana';
            $contract_end = $player['kontrakt_do'] ? date('d.m.Y', strtotime($player['kontrakt_do'])) : 'Brak';
            
            echo '<tr>';
            // Checkbox
            echo '<td><input type="checkbox" class="player-checkbox" value="' . $player['id'] . '" data-api-id="' . esc_attr($player['api_id']) . '"></td>';
            
            // Zdjęcie
            echo '<td>';
            echo '<div style="position: relative; display: inline-block;">';
            echo '<img src="' . esc_url($photo_url) . '" alt="' . esc_attr($player['imie_nazwisko']) . '" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid #ddd;">';
            echo '<div style="margin-top: 5px;">';
            echo '<button type="button" class="button button-small upload-photo-btn" data-player-id="' . $player['id'] . '">📷</button>';
            echo '</div>';
            echo '</div>';
            echo '</td>';
            
            // Dane zawodnika
            echo '<td><strong>' . esc_html($player['imie_nazwisko']) . '</strong><br><small>ID: ' . $player['api_id'] . '</small></td>';
            echo '<td><strong>' . ($player['numer'] ?: '-') . '</strong></td>';
            echo '<td>' . esc_html($player['pozycja'] ?: '-') . '</td>';
            echo '<td>' . ($player['wiek'] ?: '-') . ' lat</td>';
            echo '<td>' . $this->wisla_get_country_flag_only($player['kraj']) . ' ' . esc_html($player['kraj'] ?: '-') . '</td>';
            echo '<td>' . esc_html($player['noga'] ?: '-') . '</td>';
            echo '<td>' . $contract_end . '</td>';
            echo '<td><small>' . $last_update . '</small></td>';
            
            // Akcje
            echo '<td>';
            echo '<button type="button" class="button button-small edit-player-btn" data-player-id="' . $player['id'] . '" title="Edytuj dane zawodnika">✏️</button> ';
            echo '<button type="button" class="button button-small update-single-player" data-api-id="' . esc_attr($player['api_id']) . '" title="Aktualizuj z API">🔄</button> ';
            echo '<button type="button" class="button button-small button-link-delete delete-single-player" data-id="' . $player['id'] . '" title="Usuń zawodnika">🗑️</button>';
            echo '</td>';
            
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        
        // WordPress Media Library używany zamiast file upload
        
        // Modal edycji zawodnika
        echo '<div id="edit-player-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4);">';
        echo '<div style="background-color: #fefefe; margin: 15% auto; padding: 20px; border: 1px solid #888; width: 500px; border-radius: 5px;">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
        echo '<h3 style="margin: 0;">Edytuj dane zawodnika</h3>';
        echo '<span id="close-edit-modal" style="color: #aaa; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>';
        echo '</div>';
        
        echo '<form id="edit-player-form">';
        echo '<input type="hidden" id="edit-player-id" name="player_id" value="">';
        
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<td><label>Imię i nazwisko:</label></td>';
        echo '<td><input type="text" id="edit-imie-nazwisko" name="imie_nazwisko" class="regular-text" required></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Numer:</label></td>';
        echo '<td><input type="number" id="edit-numer" name="numer" min="1" max="99" class="small-text"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Pozycja:</label></td>';
        echo '<td>';
        echo '<select id="edit-pozycja" name="pozycja">';
        echo '<option value="Bramkarz">Bramkarz</option>';
        echo '<option value="Obrońca">Obrońca</option>';
        echo '<option value="Pomocnik">Pomocnik</option>';
        echo '<option value="Napastnik">Napastnik</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Wiek:</label></td>';
        echo '<td><input type="number" id="edit-wiek" name="wiek" min="16" max="50" class="small-text"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Data urodzenia:</label></td>';
        echo '<td><input type="date" id="edit-data-urodzenia" name="data_urodzenia" class="regular-text"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Kraj:</label></td>';
        echo '<td><input type="text" id="edit-kraj" name="kraj" class="regular-text"></td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Preferowana noga:</label></td>';
        echo '<td>';
        echo '<select id="edit-noga" name="noga">';
        echo '<option value="">Nie wybrano</option>';
        echo '<option value="Prawa">Prawa</option>';
        echo '<option value="Lewa">Lewa</option>';
        echo '<option value="Obie">Obie</option>';
        echo '</select>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<td><label>Kontrakt do:</label></td>';
        echo '<td><input type="date" id="edit-kontrakt-do" name="kontrakt_do" class="regular-text"></td>';
        echo '</tr>';
        echo '</table>';
        
        echo '<div style="margin-top: 20px; text-align: right;">';
        echo '<button type="button" id="cancel-edit" class="button" style="margin-right: 10px;">Anuluj</button>';
        echo '<button type="submit" class="button button-primary">Zapisz zmiany</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
        
        // JavaScript dla zarządzania tabelą
        echo '<script>
        jQuery(document).ready(function($) {
            // Select all checkbox
            $("#select-all-players").change(function() {
                $(".player-checkbox").prop("checked", this.checked);
                $(".player-checkbox").first().trigger("change");
            });
            
            // Upload zdjęcia przez WordPress Media Library
            $(".upload-photo-btn").click(function() {
                var playerId = $(this).data("player-id");
                var button = $(this);
                
                // WordPress Media Library
                var mediaUploader = wp.media({
                    title: "Wybierz zdjęcie zawodnika",
                    button: {
                        text: "Wybierz to zdjęcie"
                    },
                    multiple: false,
                    library: {
                        type: "image"
                    }
                });
                
                mediaUploader.on("select", function() {
                    var attachment = mediaUploader.state().get("selection").first().toJSON();
                    
                    button.prop("disabled", true).text("⏳");
                    
                    // Wyślij attachment_id do serwera
                    $.post(ajaxurl, {
                        action: "attach_player_photo",
                        player_id: playerId,
                        attachment_id: attachment.id,
                        nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                    }, function(response) {
                        if (response.success) {
                            alert("✅ " + response.data.message);
                            location.reload();
                        } else {
                            alert("❌ " + response.data.message);
                        }
                    }).always(function() {
                        button.prop("disabled", false).text("📷");
                    });
                });
                
                mediaUploader.open();
            });
            
            // Aktualizuj pojedynczego zawodnika
            $(".update-single-player").click(function() {
                var apiId = $(this).data("api-id");
                var button = $(this);
                
                button.prop("disabled", true).text("⏳");
                
                $.post(ajaxurl, {
                    action: "update_selected_players",
                    player_ids: [apiId],
                    team_id: "3122",
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        alert("✅ Zawodnik został zaktualizowany");
                        location.reload();
                    } else {
                        alert("❌ " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("🔄");
                });
            });
            
            // Usuń pojedynczego zawodnika
            $(".delete-single-player").click(function() {
                var playerId = $(this).data("id");
                var playerName = $(this).closest("tr").find("td:nth-child(3) strong").text();
                
                if (!confirm("Czy na pewno chcesz usunąć zawodnika: " + playerName + "?")) {
                    return;
                }
                
                var button = $(this);
                button.prop("disabled", true).text("⏳");
                
                $.post(ajaxurl, {
                    action: "delete_selected_players",
                    player_ids: [playerId],
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        alert("✅ Zawodnik został usunięty");
                        location.reload();
                    } else {
                        alert("❌ " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("🗑️");
                });
            });
            
            // Edytuj zawodnika
            $(".edit-player-btn").click(function() {
                var playerId = $(this).data("player-id");
                var button = $(this);
                
                button.prop("disabled", true).text("⏳");
                
                // Pobierz pełne dane zawodnika z bazy
                $.post(ajaxurl, {
                    action: "get_player_data",
                    player_id: playerId,
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                }, function(response) {
                    if (response.success) {
                        var player = response.data.player;
                        
                        // Wypełnij formularz dokładnymi danymi z bazy
                        $("#edit-player-id").val(player.id);
                        $("#edit-imie-nazwisko").val(player.imie_nazwisko || "");
                        $("#edit-numer").val(player.numer || "");
                        $("#edit-pozycja").val(player.pozycja || "");
                        $("#edit-wiek").val(player.wiek || "");
                        $("#edit-data-urodzenia").val(player.data_urodzenia || "");
                        $("#edit-kraj").val(player.kraj || "");
                        $("#edit-noga").val(player.noga || "");
                        $("#edit-kontrakt-do").val(player.kontrakt_do || "");
                        
                        // Pokaż modal
                        $("#edit-player-modal").show();
                    } else {
                        alert("❌ Błąd pobierania danych: " + response.data.message);
                    }
                }).always(function() {
                    button.prop("disabled", false).text("✏️");
                });
            });
            
            // Zamknij modal
            $("#close-edit-modal, #cancel-edit").click(function() {
                $("#edit-player-modal").hide();
            });
            
            // Zamknij modal klikając poza nim
            $("#edit-player-modal").click(function(e) {
                if (e.target === this) {
                    $(this).hide();
                }
            });
            
            // Zapisz zmiany zawodnika
            $("#edit-player-form").submit(function(e) {
                e.preventDefault();
                
                var formData = $(this).serializeArray();
                var postData = {
                    action: "edit_player_data",
                    nonce: "' . wp_create_nonce('sofascore_nonce') . '"
                };
                
                $.each(formData, function(i, field) {
                    postData[field.name] = field.value;
                });
                
                var submitBtn = $(this).find("button[type=submit]");
                submitBtn.prop("disabled", true).text("Zapisywanie...");
                
                $.post(ajaxurl, postData, function(response) {
                    if (response.success) {
                        alert("✅ " + response.data.message);
                        $("#edit-player-modal").hide();
                        location.reload();
                    } else {
                        alert("❌ " + response.data.message);
                    }
                }).always(function() {
                    submitBtn.prop("disabled", false).text("Zapisz zmiany");
                });
            });
        });
        </script>';
    }
    
    /**
     * Dodaj custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_5_minutes'] = array(
            'interval' => 300,
            'display'  => __('Co 5 minut')
        );
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display'  => __('Co 1 minutę')
        );
        return $schedules;
    }
    
    /**
     * Zbuduj plan dnia -- skanuj saved_rounds i znajdź mecze z dzisiejszą datą.
     * @return array Plan dnia
     */
    private function build_daily_plan() {
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        $today = date('Y-m-d');
        $matches = array();

        foreach ($saved_rounds as $round_number => $round_data) {
            $events = $round_data['data']['events'] ?? array();
            foreach ($events as $event) {
                $ts = $event['startTimestamp'] ?? 0;
                if (!$ts) continue;

                $event_date = date('Y-m-d', $ts);
                if ($event_date !== $today) continue;

                $status_type = strtolower($event['status']['type'] ?? 'notstarted');
                if ($status_type === 'finished') continue;

                $matches[] = array(
                    'round'           => intval($round_number),
                    'event_id'        => $event['id'] ?? null,
                    'start_time'      => $ts,
                    'home_team'       => $event['homeTeam']['name'] ?? '?',
                    'away_team'       => $event['awayTeam']['name'] ?? '?',
                    'state'           => 'waiting',
                    'checking_since'  => null,
                    'api_status'      => $event['status']['description'] ?? null,
                    'api_status_code' => $event['status']['code'] ?? null,
                    'home_score'      => null,
                    'away_score'      => null,
                    'home_score_ht'   => null,
                    'away_score_ht'   => null,
                    'minute'          => null,
                    'last_updated'    => null,
                );
            }
        }

        usort($matches, function ($a, $b) {
            return $a['start_time'] - $b['start_time'];
        });

        $plan = array(
            'date'            => $today,
            'status'          => empty($matches) ? 'no_matches' : 'active',
            'created_at'      => time(),
            'api_calls_today' => 0,
            'matches'         => $matches,
        );

        update_option('sofascore_daily_plan', $plan, false);

        $match_count = count($matches);
        if ($match_count > 0) {
            $rounds = array_unique(array_column($matches, 'round'));
            error_log(sprintf(
                'SofaScore Smart-Scheduler: Plan na %s -- %d meczów, rundy: %s, godziny: %s',
                $today,
                $match_count,
                implode(',', $rounds),
                implode(', ', array_map(function ($m) { return date('H:i', $m['start_time']); }, $matches))
            ));
        } else {
            error_log(sprintf('SofaScore Smart-Scheduler: Plan na %s -- brak meczów', $today));
        }

        return $plan;
    }

    /**
     * Smart auto-refresh: odpytuje API tylko podczas trwania meczów,
     * śledząc każdy mecz indywidualnie (per-match state machine).
     */
    public function auto_refresh_data() {
        if (!get_option('sofascore_auto_refresh_enabled', 0)) {
            return;
        }

        $plan = get_option('sofascore_daily_plan', array());
        $today = date('Y-m-d');

        // Jeśli nie ma planu na dziś, zbuduj go
        if (empty($plan) || ($plan['date'] ?? '') !== $today) {
            $plan = $this->build_daily_plan();
        }

        // Nic do roboty
        if (in_array($plan['status'] ?? '', ['no_matches', 'complete'])) {
            return;
        }

        $now = time();
        $rounds_to_query = array();
        $any_active = false;

        // Przejdź przez mecze i zaktualizuj stany
        foreach ($plan['matches'] as &$match) {
            $state = $match['state'];

            // Stan końcowy -- nic nie robimy
            if (in_array($state, ['finished', 'abandoned'])) {
                continue;
            }

            // waiting -> checking: nadeszła godzina startu
            if ($state === 'waiting' && $now >= $match['start_time']) {
                $match['state'] = 'checking';
                $match['checking_since'] = $now;
            }

            // checking/force_check: sprawdź 15-min timeout
            if (in_array($match['state'], ['checking', 'force_check'])) {
                if ($match['checking_since'] && ($now - $match['checking_since'] > 15 * 60)) {
                    $match['state'] = 'abandoned';
                    error_log(sprintf(
                        'SofaScore Smart-Scheduler: ABANDONED mecz %s (%s vs %s) -- nie rozpoczął się w ciągu 15 min',
                        $match['event_id'], $match['home_team'], $match['away_team']
                    ));
                    continue;
                }
            }

            // Mecze wymagające odpytania API
            if (in_array($match['state'], ['checking', 'live', 'force_check'])) {
                $rounds_to_query[$match['round']] = true;
                $any_active = true;
            }

            // waiting -- jeszcze nie pora
            if ($match['state'] === 'waiting') {
                $any_active = true;
            }
        }
        unset($match);

        // Jeśli żadna runda nie wymaga odpytania, zapisz stan i wyjdź
        if (empty($rounds_to_query)) {
            if (!$any_active) {
                $plan['status'] = 'complete';
                error_log('SofaScore Smart-Scheduler: Wszystkie mecze zakończone/abandoned -- plan complete');
            }
            update_option('sofascore_daily_plan', $plan, false);
            return;
        }

        // Odpytaj API -- jedna runda = jedno wywołanie
        $current_season = '76477';
        $overrides = get_option('sofascore_match_overrides', array());
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        $api_responses = array();

        foreach (array_keys($rounds_to_query) as $round_number) {
            $result = $this->get_round_fixtures($current_season, $round_number);
            $plan['api_calls_today']++;

            if ($result['success']) {
                $api_responses[$round_number] = $result['data']['events'] ?? array();

                // Zaktualizuj saved_rounds (z overrides)
                $merged_events = array();
                foreach ($api_responses[$round_number] as $event) {
                    $mid = $event['id'] ?? null;
                    if ($mid && isset($overrides[$mid])) {
                        $ov = $overrides[$mid];
                        $event['homeTeam']['name'] = $ov['home_team'];
                        $event['awayTeam']['name'] = $ov['away_team'];
                        $event['startTimestamp'] = $ov['timestamp'];
                        $event['status']['description'] = $ov['status'];
                        if ($ov['home_score'] !== null) $event['homeScore']['current'] = $ov['home_score'];
                        if ($ov['away_score'] !== null) $event['awayScore']['current'] = $ov['away_score'];
                        $event['_manual_override'] = true;
                    }
                    $merged_events[] = $event;
                }

                $saved_rounds[$round_number] = array(
                    'data'          => array_merge($result['data'], ['events' => $merged_events]),
                    'updated'       => current_time('Y-m-d H:i:s'),
                    'matches_count' => count($merged_events),
                );
            }

            usleep(100000); // 0.1s między rundami
        }

        update_option('sofascore_saved_rounds', $saved_rounds);

        // Upsert meczów do tabeli sofascore_matches
        foreach ($api_responses as $rnd => $events) {
            foreach ($events as $ev) {
                $this->upsert_match_to_table($ev, intval($rnd));
            }
        }

        // Zaktualizuj stany meczów na podstawie odpowiedzi API
        $fp_synced_this_cycle = 0;
        $fp_max_sync = intval(get_option('sofascore_fp_max_sync_per_cycle', 2));
        $fp_sync_enabled = get_option('sofascore_fp_sync_enabled', 0);
        $all_done = true;
        foreach ($plan['matches'] as &$match) {
            if (in_array($match['state'], ['finished', 'abandoned'])) {
                continue;
            }
            if ($match['state'] === 'waiting') {
                $all_done = false;
                continue;
            }

            // Znajdź event w odpowiedzi API
            $round_events = $api_responses[$match['round']] ?? array();
            $api_event = null;
            foreach ($round_events as $ev) {
                if (($ev['id'] ?? null) == $match['event_id']) {
                    $api_event = $ev;
                    break;
                }
            }

            if (!$api_event) {
                $all_done = false;
                continue;
            }

            $api_type = strtolower($api_event['status']['type'] ?? '');
            $match['api_status'] = $api_event['status']['description'] ?? null;
            $match['api_status_code'] = $api_event['status']['code'] ?? null;
            $match['home_score'] = $api_event['homeScore']['current'] ?? null;
            $match['away_score'] = $api_event['awayScore']['current'] ?? null;
            $match['home_score_ht'] = $api_event['homeScore']['period1'] ?? null;
            $match['away_score_ht'] = $api_event['awayScore']['period1'] ?? null;
            $match['last_updated'] = $now;

            // Oblicz minutę meczu
            if ($api_type === 'inprogress') {
                $st = $api_event['statusTime'] ?? $api_event['time'] ?? array();
                $initial = $st['initial'] ?? 0;
                $ts_period = $st['timestamp'] ?? ($api_event['currentPeriodStartTimestamp'] ?? $now);
                $match['minute'] = intval(($initial + ($now - $ts_period)) / 60);

                if ($api_event['status']['code'] == 31) {
                    $match['minute'] = 45;
                }
            }

            // Przejścia stanów
            if ($api_type === 'finished') {
                $was_not_finished = ($match['state'] !== 'finished');
                $match['state'] = 'finished';
                $match['minute'] = 90;
                error_log(sprintf(
                    'SofaScore Smart-Scheduler: FINISHED %s vs %s  %s:%s',
                    $match['home_team'], $match['away_team'],
                    $match['home_score'] ?? '-', $match['away_score'] ?? '-'
                ));
                if ($was_not_finished && $match['event_id']) {
                    $this->fetch_and_store_incidents($match['event_id']);
                    $plan['api_calls_today']++;

                    // Auto-sync do Football Pool (jeśli włączony i w limicie)
                    if ($fp_sync_enabled && $fp_synced_this_cycle < $fp_max_sync) {
                        $sync_result = $this->sync_match_to_fp($match['event_id']);
                        if ($sync_result['success']) {
                            $fp_synced_this_cycle++;
                            error_log('SofaScore FP-Sync: ' . $sync_result['message']);
                        }
                    } elseif ($fp_sync_enabled && $fp_synced_this_cycle >= $fp_max_sync) {
                        error_log('SofaScore FP-Sync: Limit synców (' . $fp_max_sync . ') osiągnięty w tym cyklu, event_id=' . $match['event_id'] . ' czeka na kolejny cykl');
                    }
                }
            } elseif ($api_type === 'inprogress') {
                if ($match['event_id']) {
                    $this->fetch_and_store_incidents($match['event_id']);
                    $plan['api_calls_today']++;
                }
                if (in_array($match['state'], ['checking', 'force_check'])) {
                    error_log(sprintf(
                        'SofaScore Smart-Scheduler: LIVE %s vs %s (minuta %d)',
                        $match['home_team'], $match['away_team'], $match['minute'] ?? 0
                    ));
                }
                $match['state'] = 'live';
                $all_done = false;
            } else {
                $all_done = false;
            }
        }
        unset($match);

        // Doczyszczenie: sync meczów zmapowanych ale nieszsynchronizowanych z poprzednich cykli
        if ($fp_sync_enabled && $fp_synced_this_cycle < $fp_max_sync) {
            global $wpdb;
            $sm_table = $wpdb->prefix . 'sofascore_matches';
            $remaining = $fp_max_sync - $fp_synced_this_cycle;
            $pending = $wpdb->get_results($wpdb->prepare(
                "SELECT event_id FROM {$sm_table} WHERE status = 'Ended' AND fp_match_id IS NOT NULL AND fp_synced = 0 LIMIT %d",
                $remaining
            ));
            foreach ($pending as $p) {
                $sr = $this->sync_match_to_fp($p->event_id);
                if ($sr['success']) {
                    $fp_synced_this_cycle++;
                    error_log('SofaScore FP-Sync (backfill): ' . $sr['message']);
                }
            }
        }

        if ($all_done && !$any_active) {
            $plan['status'] = 'complete';
            error_log(sprintf(
                'SofaScore Smart-Scheduler: Plan COMPLETE -- %d wywołań API dzisiaj',
                $plan['api_calls_today']
            ));
        }

        update_option('sofascore_daily_plan', $plan, false);
        update_option('sofascore_last_auto_refresh', $now);
    }

    /**
     * Co-godzinowe sprawdzanie mediów dla dzisiejszych zakończonych meczów.
     * Highlights YouTube pojawiają się z opóźnieniem, dlatego sprawdzamy wielokrotnie.
     */
    public function hourly_media_check() {
        $plan = get_option('sofascore_daily_plan', array());
        $today = date('Y-m-d');

        if (empty($plan) || ($plan['date'] ?? '') !== $today) {
            return;
        }

        $checked = 0;
        foreach ($plan['matches'] as $match) {
            if ($match['state'] !== 'finished' || empty($match['event_id'])) {
                continue;
            }
            $this->fetch_and_store_media($match['event_id']);
            $checked++;
            usleep(300000);
        }

        if ($checked > 0) {
            error_log("SofaScore Media Check: Sprawdzono media dla {$checked} zakończonych meczów");
        }
    }
    
    /**
     * AJAX: Przeskanuj harmonogram na dziś (buduje plan od nowa)
     */
    public function ajax_rescan_daily_plan() {
        check_ajax_referer('sofascore_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        delete_option('sofascore_daily_plan');
        $plan = $this->build_daily_plan();

        $match_count = count($plan['matches']);
        $rounds = array_unique(array_column($plan['matches'], 'round'));
        $times = array_map(function ($m) { return date('H:i', $m['start_time']); }, $plan['matches']);

        wp_send_json_success(array(
            'message' => $match_count > 0
                ? sprintf('Znaleziono %d meczów. Rundy: %s. Godziny: %s', $match_count, implode(', ', $rounds), implode(', ', $times))
                : 'Brak meczów na dziś',
            'plan' => $plan,
        ));
    }

    /**
     * AJAX: Resetuj mecz (force_check) -- dla przełożonych meczów
     */
    public function ajax_reset_match() {
        check_ajax_referer('sofascore_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : '';
        if (empty($event_id)) {
            wp_send_json_error(array('message' => 'Brak event_id'));
        }

        $plan = get_option('sofascore_daily_plan', array());
        if (empty($plan['matches'])) {
            wp_send_json_error(array('message' => 'Brak planu dnia'));
        }

        $found = false;
        foreach ($plan['matches'] as &$match) {
            if ($match['event_id'] == $event_id) {
                $match['state'] = 'force_check';
                $match['checking_since'] = time();
                $match['minute'] = null;
                $found = true;
                break;
            }
        }
        unset($match);

        if (!$found) {
            wp_send_json_error(array('message' => 'Nie znaleziono meczu o ID: ' . $event_id));
        }

        // Cofnij plan do stanu active (jeśli był complete)
        $plan['status'] = 'active';
        update_option('sofascore_daily_plan', $plan, false);

        wp_send_json_success(array(
            'message' => sprintf('Mecz %s ustawiony na force_check -- odpytywanie rozpocznie się od następnego cyklu', $event_id),
            'plan' => $plan,
        ));
    }

    /**
     * AJAX: Pobierz aktualny plan dnia (do wyświetlenia w panelu)
     */
    public function ajax_get_daily_plan() {
        check_ajax_referer('sofascore_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        $plan = get_option('sofascore_daily_plan', array());
        wp_send_json_success(array('plan' => $plan));
    }

    /**
     * Backfill: jednorazowe uzupełnienie incidents i media dla rozegranych meczów sezonu.
     * Obsługuje przetwarzanie w partiach (batch) ze zwracaniem postępu.
     */
    public function ajax_backfill_incidents_media() {
        check_ajax_referer('sofascore_settings', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        global $wpdb;
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10;

        $finished_events = array();
        foreach ($saved_rounds as $round_data) {
            $events = $round_data['data']['events'] ?? array();
            foreach ($events as $event) {
                $status_type = strtolower($event['status']['type'] ?? '');
                if ($status_type === 'finished' && !empty($event['id'])) {
                    $finished_events[] = intval($event['id']);
                }
            }
        }
        $finished_events = array_unique($finished_events);
        sort($finished_events);

        $total = count($finished_events);
        $batch = array_slice($finished_events, $offset, $batch_size);
        $processed = 0;
        $inc_table = $wpdb->prefix . 'sofascore_incidents';
        $med_table = $wpdb->prefix . 'sofascore_media';

        foreach ($batch as $event_id) {
            $has_incidents = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$inc_table} WHERE event_id = %d", $event_id
            ));
            if (!$has_incidents) {
                $this->fetch_and_store_incidents($event_id);
                usleep(300000);
            }

            $has_media = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$med_table} WHERE event_id = %d", $event_id
            ));
            if (!$has_media) {
                $this->fetch_and_store_media($event_id);
                usleep(300000);
            }

            $processed++;
        }

        $new_offset = $offset + $processed;
        $done = ($new_offset >= $total);

        wp_send_json_success(array(
            'processed' => $processed,
            'offset'    => $new_offset,
            'total'     => $total,
            'done'      => $done,
            'message'   => $done
                ? "Backfill zakończony: {$total} meczów przetworzonych."
                : "Przetworzono {$new_offset}/{$total} meczów..."
        ));
    }

    /**
     * Zastosuj timezone offset do timestampu
     */
    public function apply_timezone_offset($timestamp) {
        $offset = get_option('sofascore_timezone_offset', 0);
        return $timestamp + ($offset * 3600); // offset w sekundach
    }
    
    /**
     * AJAX handler dla zapisywania ustawień
     */
    public function ajax_save_settings() {
        check_ajax_referer('sofascore_settings', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }
        
        // Zapisz offset strefy czasowej
        $timezone_offset = isset($_POST['timezone_offset']) ? intval($_POST['timezone_offset']) : 0;
        update_option('sofascore_timezone_offset', $timezone_offset);
        
        // Zapisz ustawienia automatycznego odświeżania
        $auto_refresh_enabled = isset($_POST['auto_refresh_enabled']) ? 1 : 0;
        update_option('sofascore_auto_refresh_enabled', $auto_refresh_enabled);

        // Zapisz ustawienia wyświetlania incidents/media
        update_option('sofascore_show_incidents', isset($_POST['show_incidents']) ? 1 : 0);
        update_option('sofascore_show_media', isset($_POST['show_media']) ? 1 : 0);

        // Zapisz ustawienia integracji z Football Pool
        update_option('sofascore_fp_sync_enabled', isset($_POST['fp_sync_enabled']) ? 1 : 0);
        update_option('sofascore_fp_dry_run', isset($_POST['fp_dry_run']) ? 1 : 0);
        update_option('sofascore_fp_ranking_filter', sanitize_text_field($_POST['fp_ranking_filter'] ?? ''));
        update_option('sofascore_fp_max_sync_per_cycle', max(1, min(10, intval($_POST['fp_max_sync'] ?? 2))));
        update_option('sofascore_fp_conflict_mode', in_array($_POST['fp_conflict_mode'] ?? '', array('skip', 'overwrite')) ? $_POST['fp_conflict_mode'] : 'skip');
        
        // Zapisz harmonogram dla każdego dnia tygodnia
        $days = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $schedule = array();
        
        foreach ($days as $day) {
            $schedule[$day] = array(
                'from' => isset($_POST[$day . '_from']) ? sanitize_text_field($_POST[$day . '_from']) : '12:00',
                'to' => isset($_POST[$day . '_to']) ? sanitize_text_field($_POST[$day . '_to']) : '22:00',
                'frequency' => isset($_POST[$day . '_frequency']) ? intval($_POST[$day . '_frequency']) : 60
            );
        }
        
        update_option('sofascore_refresh_schedule', $schedule);
        
        // Prze planuj cron jeśli jest włączony
        if ($auto_refresh_enabled) {
            $this->reschedule_cron();
        } else {
            // Wyłącz cron jeśli auto-refresh wyłączony
            wp_clear_scheduled_hook('sofascore_auto_refresh');
        }
        
        wp_send_json_success(array('message' => 'Ustawienia zapisane pomyślnie'));
    }
    
    /**
     * Przeplanuj cron job na podstawie nowych ustawień
     */
    private function reschedule_cron() {
        wp_clear_scheduled_hook('sofascore_auto_refresh');
        
        if (!wp_next_scheduled('sofascore_auto_refresh')) {
            wp_schedule_event(time(), 'every_minute', 'sofascore_auto_refresh');
        }
    }
    
    /**
     * Strona edycji meczów - zarządzanie + integracja z Football Pool
     */
    public function edit_matches_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_matches';
        $overrides = get_option('sofascore_match_overrides', array());

        $selected_round = isset($_GET['filter_round']) ? intval($_GET['filter_round']) : 0;
        $filter_fp = isset($_GET['filter_fp']) ? sanitize_text_field($_GET['filter_fp']) : '';

        $where = "WHERE 1=1";
        if ($selected_round > 0) {
            $where .= $wpdb->prepare(" AND round = %d", $selected_round);
        }
        if ($filter_fp === 'mapped') {
            $where .= " AND fp_match_id IS NOT NULL";
        } elseif ($filter_fp === 'unmapped') {
            $where .= " AND fp_match_id IS NULL";
        } elseif ($filter_fp === 'synced') {
            $where .= " AND fp_synced = 1";
        } elseif ($filter_fp === 'pending') {
            $where .= " AND fp_match_id IS NOT NULL AND fp_synced = 0 AND status = 'Ended'";
        }

        $all_matches = $wpdb->get_results("SELECT * FROM {$table} {$where} ORDER BY round ASC, start_timestamp ASC");
        $rounds = $wpdb->get_col("SELECT DISTINCT round FROM {$table} ORDER BY round ASC");

        $fp_sync_enabled = get_option('sofascore_fp_sync_enabled', 0);
        $fp_dry_run = get_option('sofascore_fp_dry_run', 1);

        ?>
        <div class="wrap">
            <h1>⚽ Zarządzanie meczami Ekstraklasy</h1>
            <p>Mecze z SofaScore API z możliwością mapowania do Football Pool i synchronizacji wyników.</p>

            <?php if ($fp_dry_run): ?>
            <div class="notice notice-info inline"><p><strong>Tryb dry-run aktywny</strong> — synchronizacja loguje, ale nie zapisuje do Football Pool.</p></div>
            <?php endif; ?>
            <?php if (!$fp_sync_enabled): ?>
            <div class="notice notice-warning inline"><p><strong>Automatyczna synchronizacja wyłączona</strong> (kill switch). Ręczna synchronizacja nadal dostępna.</p></div>
            <?php endif; ?>

            <form method="GET" style="margin:20px 0; padding:15px; background:white; border:1px solid #ccc; display:flex; gap:20px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sofascore-edit-matches">
                <label>
                    <strong>Kolejka:</strong>
                    <select name="filter_round" style="margin-left:5px;">
                        <option value="0">Wszystkie</option>
                        <?php foreach ($rounds as $r): ?>
                            <option value="<?php echo $r; ?>" <?php selected($selected_round, (int)$r); ?>>Kolejka <?php echo $r; ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <strong>Status FP:</strong>
                    <select name="filter_fp" style="margin-left:5px;">
                        <option value="">Wszystkie</option>
                        <option value="mapped" <?php selected($filter_fp, 'mapped'); ?>>Zmapowane</option>
                        <option value="unmapped" <?php selected($filter_fp, 'unmapped'); ?>>Niezmapowane</option>
                        <option value="synced" <?php selected($filter_fp, 'synced'); ?>>Zsynchronizowane</option>
                        <option value="pending" <?php selected($filter_fp, 'pending'); ?>>Do synchronizacji</option>
                    </select>
                </label>
                <button type="submit" class="button">Filtruj</button>
                <a href="?page=sofascore-edit-matches" class="button">Reset</a>
            </form>

            <?php if (empty($all_matches)): ?>
                <div class="notice notice-info"><p>Brak meczów do wyświetlenia. <?php if (empty($rounds)): ?>Tabela jest pusta — dezaktywuj i ponownie aktywuj plugin, aby zmigrować dane z saved_rounds.<?php endif; ?></p></div>
            <?php else: ?>
                <p><strong>Znaleziono meczów:</strong> <?php echo count($all_matches); ?></p>

                <table class="wp-list-table widefat fixed striped" style="table-layout:auto;">
                    <thead>
                        <tr>
                            <th style="width:55px;">Kol.</th>
                            <th style="width:130px;">Data</th>
                            <th>Gospodarze</th>
                            <th style="width:70px;text-align:center;">Wynik</th>
                            <th>Goście</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:120px;">Typer FP</th>
                            <th style="width:180px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_matches as $m):
                        $eid = $m->event_id;
                        $has_override = isset($overrides[$eid]);
                        $hs = $m->home_score !== null ? $m->home_score : '-';
                        $as = $m->away_score !== null ? $m->away_score : '-';
                        $dt = $m->start_timestamp ? date('Y-m-d H:i', $m->start_timestamp) : '-';

                        if ($m->fp_match_id && $m->fp_synced) {
                            $fp_badge = '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#d4edda;color:#155724;font-size:12px;" title="Zsynchronizowany ' . esc_attr($m->fp_synced_at) . '">✅ FP#' . intval($m->fp_match_id) . '</span>';
                        } elseif ($m->fp_match_id && !$m->fp_synced) {
                            $fp_badge = '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#fff3cd;color:#856404;font-size:12px;">⏳ FP#' . intval($m->fp_match_id) . '</span>';
                        } else {
                            $fp_badge = '<span style="display:inline-block;padding:2px 8px;border-radius:4px;background:#e9ecef;color:#6c757d;font-size:12px;">—</span>';
                        }

                        $row_style = $has_override ? ' style="background:#fffbcc;border-left:4px solid #f0c420;"' : '';
                    ?>
                        <tr<?php echo $row_style; ?>>
                            <td><strong><?php echo intval($m->round); ?></strong></td>
                            <td><?php echo esc_html($dt); ?></td>
                            <td><?php echo esc_html($m->home_team); ?></td>
                            <td style="text-align:center;"><strong><?php echo esc_html($hs . ':' . $as); ?></strong></td>
                            <td><?php echo esc_html($m->away_team); ?></td>
                            <td><?php echo esc_html($m->status ?? '-'); ?></td>
                            <td><?php echo $fp_badge; ?></td>
                            <td>
                                <button class="button button-small edit-match-btn"
                                        data-match-id="<?php echo esc_attr($eid); ?>"
                                        data-round="<?php echo esc_attr($m->round); ?>">Edytuj</button>
                                <?php if ($m->fp_match_id): ?>
                                    <button class="button button-small fp-sync-btn" title="Wymuś synchronizację do Football Pool"
                                            data-event-id="<?php echo esc_attr($eid); ?>">Sync FP</button>
                                    <button class="button button-small fp-unmap-btn" title="Usuń mapowanie"
                                            data-event-id="<?php echo esc_attr($eid); ?>" style="color:#d63638;">✕</button>
                                <?php else: ?>
                                    <button class="button button-small fp-map-btn"
                                            data-event-id="<?php echo esc_attr($eid); ?>"
                                            data-home="<?php echo esc_attr($m->home_team); ?>"
                                            data-away="<?php echo esc_attr($m->away_team); ?>">Mapuj Typera</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Modal edycji meczu (istniejący) -->
        <div id="edit-match-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6);">
            <div style="background:white; margin:50px auto; padding:30px; width:600px; max-width:90%; border-radius:8px; position:relative;">
                <span class="modal-close" style="position:absolute; top:15px; right:20px; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
                <h2>Edytuj mecz</h2>
                <form id="edit-match-form">
                    <?php wp_nonce_field('sofascore_edit_match', 'nonce'); ?>
                    <input type="hidden" id="edit_match_id" name="match_id">
                    <input type="hidden" id="edit_round" name="round">
                    <table class="form-table">
                        <tr><th>Gospodarze</th><td><input type="text" id="edit_home_team" name="home_team" class="regular-text" readonly style="background:#f0f0f0;"></td></tr>
                        <tr><th>Goście</th><td><input type="text" id="edit_away_team" name="away_team" class="regular-text" readonly style="background:#f0f0f0;"></td></tr>
                        <tr><th>Wynik gospodarzy</th><td><input type="number" id="edit_home_score" name="home_score" min="0" max="20" style="width:80px;"></td></tr>
                        <tr><th>Wynik gości</th><td><input type="number" id="edit_away_score" name="away_score" min="0" max="20" style="width:80px;"></td></tr>
                        <tr><th>Do przerwy (G)</th><td><input type="number" id="edit_home_score_ht" name="home_score_ht" min="0" max="20" style="width:80px;" placeholder="opcj."></td></tr>
                        <tr><th>Do przerwy (Go)</th><td><input type="number" id="edit_away_score_ht" name="away_score_ht" min="0" max="20" style="width:80px;" placeholder="opcj."></td></tr>
                        <tr><th>Data i godzina</th><td><input type="datetime-local" id="edit_timestamp" name="timestamp" class="regular-text"></td></tr>
                        <tr><th>Status</th><td>
                            <select id="edit_status" name="status" class="regular-text">
                                <option value="Ended">Ended (Zakończony)</option>
                                <option value="Postponed">Postponed (Przełożony)</option>
                                <option value="Scheduled">Scheduled (Zaplanowany)</option>
                                <option value="Not started">Not started</option>
                            </select>
                        </td></tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">💾 Zapisz zmiany</button>
                        <button type="button" class="button modal-close">Anuluj</button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Modal "Mapuj Typera" -->
        <div id="fp-map-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.6);">
            <div style="background:white; margin:50px auto; padding:30px; width:650px; max-width:90%; border-radius:8px; position:relative; max-height:80vh; overflow-y:auto;">
                <span class="modal-close" style="position:absolute; top:15px; right:20px; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
                <h2>🔗 Mapuj do Football Pool</h2>
                <p id="fp-map-match-label" style="font-size:14px; color:#555;"></p>
                <input type="hidden" id="fp_map_event_id">

                <div style="margin:15px 0;">
                    <label><strong>1. Wybierz ranking:</strong></label>
                    <select id="fp-ranking-select" style="width:100%; max-width:400px; margin-top:5px;">
                        <option value="">— Ładowanie rankingów... —</option>
                    </select>
                </div>

                <div id="fp-matches-container" style="margin:15px 0; display:none;">
                    <label><strong>2. Wybierz mecz z rankingu:</strong></label>
                    <div id="fp-matches-list" style="margin-top:10px; max-height:300px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;"></div>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var editNonce = '<?php echo wp_create_nonce("sofascore_edit_match"); ?>';
            var fpNonce = '<?php echo wp_create_nonce("sofascore_fp_action"); ?>';

            // --- Edycja meczu (istniejąca logika) ---
            $('.edit-match-btn').on('click', function() {
                var matchId = $(this).data('match-id');
                var round = $(this).data('round');
                $.post(ajaxurl, { action:'get_match_override_data', match_id:matchId, round:round, nonce:editNonce }, function(r) {
                    if (r.success) {
                        var d = r.data;
                        $('#edit_match_id').val(matchId);
                        $('#edit_round').val(round);
                        $('#edit_home_team').val(d.home_team);
                        $('#edit_away_team').val(d.away_team);
                        $('#edit_home_score').val(d.home_score || '');
                        $('#edit_away_score').val(d.away_score || '');
                        $('#edit_home_score_ht').val(d.home_score_ht || '');
                        $('#edit_away_score_ht').val(d.away_score_ht || '');
                        $('#edit_timestamp').val(d.timestamp_formatted);
                        $('#edit_status').val(d.status);
                        $('#edit-match-modal').show();
                    } else { alert('Błąd: ' + r.data.message); }
                });
            });

            $('#edit-match-form').on('submit', function(e) {
                e.preventDefault();
                var fd = $(this).serializeArray(), pd = { action:'save_match_override' };
                $.each(fd, function(i,f){ pd[f.name] = f.value; });
                var btn = $(this).find('button[type=submit]');
                btn.prop('disabled',true).text('Zapisywanie...');
                $.post(ajaxurl, pd, function(r) {
                    if (r.success) { alert('✅ ' + r.data.message); location.reload(); }
                    else { alert('❌ ' + r.data.message); }
                }).always(function(){ btn.prop('disabled',false).text('💾 Zapisz zmiany'); });
            });

            // --- Mapuj Typera ---
            $('.fp-map-btn').on('click', function() {
                var eventId = $(this).data('event-id');
                var home = $(this).data('home');
                var away = $(this).data('away');
                $('#fp_map_event_id').val(eventId);
                $('#fp-map-match-label').text(home + ' vs ' + away);
                $('#fp-matches-container').hide();
                $('#fp-ranking-select').html('<option value="">Ładowanie...</option>');
                $('#fp-map-modal').show();

                $.post(ajaxurl, { action:'sofascore_get_fp_rankings', nonce:fpNonce }, function(r) {
                    if (r.success && r.data.rankings) {
                        var opts = '<option value="">— Wybierz ranking —</option>';
                        $.each(r.data.rankings, function(i, rk) {
                            opts += '<option value="' + rk.id + '">' + rk.name + ' (' + rk.match_count + ' meczów)</option>';
                        });
                        $('#fp-ranking-select').html(opts);
                    } else {
                        $('#fp-ranking-select').html('<option value="">Brak rankingów</option>');
                    }
                });
            });

            $('#fp-ranking-select').on('change', function() {
                var rankingId = $(this).val();
                if (!rankingId) { $('#fp-matches-container').hide(); return; }

                $('#fp-matches-list').html('<em>Ładowanie meczów...</em>');
                $('#fp-matches-container').show();

                $.post(ajaxurl, { action:'sofascore_get_fp_ranking_matches', ranking_id:rankingId, nonce:fpNonce }, function(r) {
                    if (r.success && r.data.matches) {
                        var html = '';
                        $.each(r.data.matches, function(i, m) {
                            var scoreLabel = (m.home_score !== null && m.home_score !== '') ? m.home_score + ':' + m.away_score : 'brak wyniku';
                            html += '<div class="fp-match-row" data-fp-id="' + m.id + '" style="padding:10px 14px; border-bottom:1px solid #eee; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">';
                            html += '<span><strong>' + m.home + '</strong> vs <strong>' + m.away + '</strong></span>';
                            html += '<span style="color:#888; font-size:12px;">' + m.play_date + ' | ' + scoreLabel + ' | ID:' + m.id + '</span>';
                            html += '</div>';
                        });
                        if (!html) html = '<p style="padding:10px;">Brak meczów w tym rankingu.</p>';
                        $('#fp-matches-list').html(html);
                    } else {
                        $('#fp-matches-list').html('<p style="padding:10px; color:red;">Błąd pobierania meczów.</p>');
                    }
                });
            });

            $(document).on('click', '.fp-match-row', function() {
                var fpMatchId = $(this).data('fp-id');
                var eventId = $('#fp_map_event_id').val();
                var label = $(this).find('strong').first().text() + ' vs ' + $(this).find('strong').last().text();

                if (!confirm('Zmapować mecz SofaScore do FP#' + fpMatchId + ' (' + label + ')?')) return;

                $.post(ajaxurl, { action:'sofascore_save_fp_mapping', event_id:eventId, fp_match_id:fpMatchId, nonce:fpNonce }, function(r) {
                    if (r.success) { alert('✅ ' + r.data.message); location.reload(); }
                    else { alert('❌ ' + r.data.message); }
                });
            });

            // --- Sync FP ---
            $('.fp-sync-btn').on('click', function() {
                var eventId = $(this).data('event-id');
                if (!confirm('Wymusić synchronizację wyniku do Football Pool?')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, { action:'sofascore_manual_fp_sync', event_id:eventId, nonce:fpNonce }, function(r) {
                    alert(r.success ? ('✅ ' + r.data.message) : ('❌ ' + r.data.message));
                    if (r.success) location.reload();
                }).always(function(){ btn.prop('disabled',false).text('Sync FP'); });
            });

            // --- Usuń mapowanie ---
            $('.fp-unmap-btn').on('click', function() {
                if (!confirm('Usunąć mapowanie do Football Pool? Wynik w FP nie zostanie usunięty.')) return;
                var eventId = $(this).data('event-id');
                $.post(ajaxurl, { action:'sofascore_remove_fp_mapping', event_id:eventId, nonce:fpNonce }, function(r) {
                    if (r.success) { alert('✅ ' + r.data.message); location.reload(); }
                    else { alert('❌ ' + r.data.message); }
                });
            });

            // --- Zamykanie modali ---
            $(document).on('click', '.modal-close', function() {
                $(this).closest('[id$="-modal"]').hide();
            });

            // --- Hover na wierszach FP ---
            $(document).on('mouseenter', '.fp-match-row', function(){ $(this).css('background','#f0f6fc'); });
            $(document).on('mouseleave', '.fp-match-row', function(){ $(this).css('background',''); });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Pobierz dane meczu do edycji
     */
    public function ajax_get_match_override_data() {
        check_ajax_referer('sofascore_edit_match', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }
        
        $match_id = sanitize_text_field($_POST['match_id']);
        $round = intval($_POST['round']);
        
        // Pobierz dane z zapisanych rund
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        $overrides = get_option('sofascore_match_overrides', array());
        
        if (!isset($saved_rounds[$round])) {
            wp_send_json_error(array('message' => 'Kolejka nie znaleziona'));
        }
        
        $events = $saved_rounds[$round]['data']['events'] ?? array();
        $match_event = null;
        
        foreach ($events as $event) {
            if (($event['id'] ?? '') == $match_id) {
                $match_event = $event;
                break;
            }
        }
        
        if (!$match_event) {
            wp_send_json_error(array('message' => 'Mecz nie znaleziony'));
        }
        
        // Użyj override jeśli istnieje
        if (isset($overrides[$match_id])) {
            $override = $overrides[$match_id];
            $data = array(
                'home_team' => $override['home_team'],
                'away_team' => $override['away_team'],
                'home_score' => $override['home_score'],
                'away_score' => $override['away_score'],
                'home_score_ht' => $override['home_score_ht'] ?? '',
                'away_score_ht' => $override['away_score_ht'] ?? '',
                'timestamp' => $override['timestamp'],
                'timestamp_formatted' => date('Y-m-d\TH:i', $override['timestamp']),
                'status' => $override['status']
            );
        } else {
            // Dane z API
            $data = array(
                'home_team' => $match_event['homeTeam']['name'] ?? '',
                'away_team' => $match_event['awayTeam']['name'] ?? '',
                'home_score' => $match_event['homeScore']['current'] ?? '',
                'away_score' => $match_event['awayScore']['current'] ?? '',
                'home_score_ht' => $match_event['homeScore']['period1'] ?? '',
                'away_score_ht' => $match_event['awayScore']['period1'] ?? '',
                'timestamp' => $match_event['startTimestamp'] ?? time(),
                'timestamp_formatted' => date('Y-m-d\TH:i', $match_event['startTimestamp'] ?? time()),
                'status' => $match_event['status']['description'] ?? 'Scheduled'
            );
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * AJAX: Zapisz ręczną edycję meczu
     */
    public function ajax_save_match_override() {
        check_ajax_referer('sofascore_edit_match', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }
        
        $match_id = sanitize_text_field($_POST['match_id']);
        $round = intval($_POST['round']);
        $home_team = sanitize_text_field($_POST['home_team']);
        $away_team = sanitize_text_field($_POST['away_team']);
        $home_score = isset($_POST['home_score']) && $_POST['home_score'] !== '' ? intval($_POST['home_score']) : null;
        $away_score = isset($_POST['away_score']) && $_POST['away_score'] !== '' ? intval($_POST['away_score']) : null;
        $home_score_ht = isset($_POST['home_score_ht']) && $_POST['home_score_ht'] !== '' ? intval($_POST['home_score_ht']) : null;
        $away_score_ht = isset($_POST['away_score_ht']) && $_POST['away_score_ht'] !== '' ? intval($_POST['away_score_ht']) : null;
        $timestamp_str = sanitize_text_field($_POST['timestamp']);
        $status = sanitize_text_field($_POST['status']);
        
        // Konwertuj timestamp
        $timestamp = strtotime($timestamp_str);
        if (!$timestamp) {
            $timestamp = time();
        }
        
        // Pobierz obecne overrides
        $overrides = get_option('sofascore_match_overrides', array());
        
        // Zapisz override
        $overrides[$match_id] = array(
            'round' => $round,
            'home_team' => $home_team,
            'away_team' => $away_team,
            'home_score' => $home_score,
            'away_score' => $away_score,
            'home_score_ht' => $home_score_ht,
            'away_score_ht' => $away_score_ht,
            'timestamp' => $timestamp,
            'status' => $status,
            'edited_at' => current_time('Y-m-d H:i:s'),
            'edited_by' => get_current_user_id()
        );
        
        update_option('sofascore_match_overrides', $overrides);
        
        wp_send_json_success(array('message' => 'Mecz został zaktualizowany'));
    }
    
    /**
     * AJAX: Usuń ręczną edycję - przywróć dane z API
     */
    public function ajax_remove_match_override() {
        check_ajax_referer('sofascore_edit_match', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }
        
        $match_id = sanitize_text_field($_POST['match_id']);
        
        // Pobierz obecne overrides
        $overrides = get_option('sofascore_match_overrides', array());
        
        if (isset($overrides[$match_id])) {
            unset($overrides[$match_id]);
            update_option('sofascore_match_overrides', $overrides);
            
            wp_send_json_success(array('message' => 'Dane z API zostały przywrócone'));
        } else {
            wp_send_json_error(array('message' => 'Brak ręcznej edycji dla tego meczu'));
        }
    }
    
    // ============================================================
    //  AJAX: Integracja z Football Pool
    // ============================================================

    /**
     * Pobierz rankingi Football Pool filtrowane po wzorcu z ustawień.
     */
    public function ajax_get_fp_rankings() {
        check_ajax_referer('sofascore_fp_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        global $wpdb;
        $filter = get_option('sofascore_fp_ranking_filter', '');
        $pool_prefix = $wpdb->prefix . 'pool_';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$pool_prefix}rankings'");
        if (!$table_exists) {
            wp_send_json_error(array('message' => 'Tabela pool_rankings nie istnieje. Czy Football Pool jest aktywny?'));
        }

        $where = '';
        if (!empty($filter)) {
            $like = '%' . $wpdb->esc_like($filter) . '%';
            $where = $wpdb->prepare("WHERE name LIKE %s", $like);
        }

        $rankings = $wpdb->get_results(
            "SELECT r.id, r.name,
                    (SELECT COUNT(*) FROM {$pool_prefix}rankings_matches rm WHERE rm.ranking_id = r.id) AS match_count
             FROM {$pool_prefix}rankings r {$where}
             ORDER BY r.name"
        );

        wp_send_json_success(array('rankings' => $rankings));
    }

    /**
     * Pobierz mecze z wybranego rankingu Football Pool.
     */
    public function ajax_get_fp_ranking_matches() {
        check_ajax_referer('sofascore_fp_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        global $wpdb;
        $ranking_id = intval($_POST['ranking_id'] ?? 0);
        if ($ranking_id <= 0) {
            wp_send_json_error(array('message' => 'Brak ranking_id'));
        }

        $pool_prefix = $wpdb->prefix . 'pool_';

        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id, m.play_date, m.home_score, m.away_score,
                    th.name AS home, ta.name AS away
             FROM {$pool_prefix}matches m
             JOIN {$pool_prefix}rankings_matches rm ON rm.match_id = m.id AND rm.ranking_id = %d
             JOIN {$pool_prefix}teams th ON th.id = m.home_team_id
             JOIN {$pool_prefix}teams ta ON ta.id = m.away_team_id
             ORDER BY m.play_date ASC",
            $ranking_id
        ));

        wp_send_json_success(array('matches' => $matches));
    }

    /**
     * Zapisz mapowanie meczu SofaScore → Football Pool.
     */
    public function ajax_save_fp_mapping() {
        check_ajax_referer('sofascore_fp_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_matches';
        $event_id = intval($_POST['event_id'] ?? 0);
        $fp_match_id = intval($_POST['fp_match_id'] ?? 0);

        if (!$event_id || !$fp_match_id) {
            wp_send_json_error(array('message' => 'Brak event_id lub fp_match_id'));
        }

        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT event_id FROM {$table} WHERE fp_match_id = %d AND event_id != %d",
            $fp_match_id, $event_id
        ));
        if ($already) {
            wp_send_json_error(array('message' => 'Ten mecz FP jest już zmapowany do innego meczu SofaScore (event_id: ' . $already . ')'));
        }

        $wpdb->update(
            $table,
            array('fp_match_id' => $fp_match_id, 'fp_synced' => 0, 'fp_synced_at' => null),
            array('event_id' => $event_id),
            array('%d', '%d', '%s'),
            array('%d')
        );

        $this->log_fp_sync($event_id, $fp_match_id, 'mapped', 'success', array(), 'Mapowanie utworzone ręcznie');

        wp_send_json_success(array('message' => 'Mecz zmapowany do FP#' . $fp_match_id));
    }

    /**
     * Usuń mapowanie meczu SofaScore → Football Pool.
     */
    public function ajax_remove_fp_mapping() {
        check_ajax_referer('sofascore_fp_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sofascore_matches';
        $event_id = intval($_POST['event_id'] ?? 0);

        $match = $wpdb->get_row($wpdb->prepare("SELECT fp_match_id FROM {$table} WHERE event_id = %d", $event_id));
        if (!$match || !$match->fp_match_id) {
            wp_send_json_error(array('message' => 'Mecz nie jest zmapowany'));
        }

        $old_fp_id = $match->fp_match_id;
        $wpdb->update(
            $table,
            array('fp_match_id' => null, 'fp_synced' => 0, 'fp_synced_at' => null),
            array('event_id' => $event_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        $this->log_fp_sync($event_id, $old_fp_id, 'unmapped', 'success', array(), 'Mapowanie usunięte ręcznie');

        wp_send_json_success(array('message' => 'Mapowanie do FP#' . $old_fp_id . ' usunięte'));
    }

    /**
     * Ręczna synchronizacja wyniku meczu do Football Pool.
     */
    public function ajax_manual_fp_sync() {
        check_ajax_referer('sofascore_fp_action', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Brak uprawnień'));
        }

        $event_id = intval($_POST['event_id'] ?? 0);
        if (!$event_id) {
            wp_send_json_error(array('message' => 'Brak event_id'));
        }

        $result = $this->sync_match_to_fp($event_id, true);
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

    /**
     * Centralna logika synchronizacji meczu z Football Pool.
     * Używana zarówno przez auto-sync jak i ręczny przycisk.
     */
    public function sync_match_to_fp($event_id, $force = false) {
        global $wpdb;

        $table = $wpdb->prefix . 'sofascore_matches';
        $pool_prefix = $wpdb->prefix . 'pool_';

        $match = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE event_id = %d", $event_id));
        if (!$match) {
            return array('success' => false, 'message' => 'Mecz nie znaleziony w sofascore_matches');
        }
        if (!$match->fp_match_id) {
            return array('success' => false, 'message' => 'Mecz nie jest zmapowany do Football Pool');
        }
        if ($match->home_score === null || $match->away_score === null) {
            return array('success' => false, 'message' => 'Brak wyniku w SofaScore');
        }
        if ($match->fp_synced && !$force) {
            return array('success' => false, 'message' => 'Mecz już zsynchronizowany (użyj force)');
        }

        $fp_active = is_plugin_active('football-pool/football-pool.php') || class_exists('Football_Pool');
        if (!$fp_active) {
            $this->log_fp_sync($event_id, $match->fp_match_id, $force ? 'manual' : 'sync', 'error', array(), 'Football Pool nie jest aktywny');
            return array('success' => false, 'message' => 'Football Pool nie jest aktywny');
        }

        $pool_match = $wpdb->get_row($wpdb->prepare(
            "SELECT home_score, away_score FROM {$pool_prefix}matches WHERE id = %d",
            $match->fp_match_id
        ));
        if (!$pool_match) {
            $this->log_fp_sync($event_id, $match->fp_match_id, $force ? 'manual' : 'sync', 'error', array(), 'Mecz FP#' . $match->fp_match_id . ' nie istnieje');
            return array('success' => false, 'message' => 'Mecz FP#' . $match->fp_match_id . ' nie istnieje w bazie Football Pool');
        }

        $scores = array(
            'home_src' => intval($match->home_score),
            'away_src' => intval($match->away_score),
            'home_dst' => $pool_match->home_score,
            'away_dst' => $pool_match->away_score,
        );

        // Conflict detection
        $conflict_mode = get_option('sofascore_fp_conflict_mode', 'skip');
        if ($pool_match->home_score !== null && $pool_match->away_score !== null) {
            if (intval($pool_match->home_score) !== intval($match->home_score) || intval($pool_match->away_score) !== intval($match->away_score)) {
                $this->log_fp_sync($event_id, $match->fp_match_id, $force ? 'manual' : 'sync', 'conflict', $scores,
                    'FP ma ' . $pool_match->home_score . ':' . $pool_match->away_score . ', SofaScore ma ' . $match->home_score . ':' . $match->away_score);
                if ($conflict_mode === 'skip' && !$force) {
                    return array('success' => false, 'message' => 'Konflikt wyniku — FP ma ' . $pool_match->home_score . ':' . $pool_match->away_score . '. Użyj ręcznego sync, aby wymusić.');
                }
            }
        }

        // Dry-run check
        $dry_run = get_option('sofascore_fp_dry_run', 1);
        if ($dry_run && !$force) {
            $this->log_fp_sync($event_id, $match->fp_match_id, 'dry_run', 'success', $scores,
                'DRY-RUN: Zapisałby ' . $match->home_score . ':' . $match->away_score . ' do FP#' . $match->fp_match_id);
            return array('success' => true, 'message' => 'Dry-run: wynik ' . $match->home_score . ':' . $match->away_score . ' zalogowany (bez zapisu do FP)');
        }

        // SYNC: zapis do Football Pool
        $updated = $wpdb->update(
            $pool_prefix . 'matches',
            array('home_score' => intval($match->home_score), 'away_score' => intval($match->away_score)),
            array('id' => $match->fp_match_id),
            array('%d', '%d'),
            array('%d')
        );

        if ($updated === false) {
            $this->log_fp_sync($event_id, $match->fp_match_id, $force ? 'manual' : 'sync', 'error', $scores, 'Błąd UPDATE: ' . $wpdb->last_error);
            return array('success' => false, 'message' => 'Błąd zapisu do Football Pool: ' . $wpdb->last_error);
        }

        // Przeliczenie rankingu
        $recalc_msg = '';
        if (class_exists('Football_Pool_Admin_Score_Calculation')) {
            Football_Pool_Admin_Score_Calculation::process();
            $recalc_msg = ' + ranking przeliczony';
        } else {
            $recalc_msg = ' (ranking wymaga ręcznego przeliczenia)';
        }

        // Oznacz jako zsynchronizowany
        $wpdb->update(
            $table,
            array('fp_synced' => 1, 'fp_synced_at' => current_time('mysql')),
            array('event_id' => $event_id),
            array('%d', '%s'),
            array('%d')
        );

        $this->log_fp_sync($event_id, $match->fp_match_id, $force ? 'manual' : 'sync', 'success', $scores,
            'Zapisano ' . $match->home_score . ':' . $match->away_score . ' do FP#' . $match->fp_match_id . $recalc_msg);

        return array('success' => true, 'message' => 'Wynik ' . $match->home_score . ':' . $match->away_score . ' zapisany do FP#' . $match->fp_match_id . $recalc_msg);
    }

    /**
     * Strona ustawień
     */
    public function settings_page() {
        $timezone_offset = get_option('sofascore_timezone_offset', 0);
        $auto_refresh_enabled = get_option('sofascore_auto_refresh_enabled', 0);
        $show_incidents = get_option('sofascore_show_incidents', 0);
        $show_media = get_option('sofascore_show_media', 0);
        $schedule = get_option('sofascore_refresh_schedule', array());
        
        // Domyślne wartości dla harmonogramu
        $days_labels = array(
            'monday' => 'Poniedziałek',
            'tuesday' => 'Wtorek',
            'wednesday' => 'Środa',
            'thursday' => 'Czwartek',
            'friday' => 'Piątek',
            'saturday' => 'Sobota',
            'sunday' => 'Niedziela'
        );
        
        $default_schedule = array(
            'from' => '12:00',
            'to' => '22:00',
            'frequency' => 60
        );
        
        ?>
        <div class="wrap">
            <h1>⚙️ Ustawienia Soccer ScrAPI</h1>
            
            <form id="sofascore-settings-form">
                <?php wp_nonce_field('sofascore_settings', 'nonce'); ?>
                
                <table class="form-table">
                    <!-- Strefa czasowa -->
                    <tr>
                        <th scope="row">
                            <label for="timezone_offset">Strefa czasowa (offset w godzinach)</label>
                        </th>
                        <td>
                            <select name="timezone_offset" id="timezone_offset">
                                <option value="-1" <?php selected($timezone_offset, -1); ?>>UTC -1 (Czas zimowy)</option>
                                <option value="0" <?php selected($timezone_offset, 0); ?>>UTC +0</option>
                                <option value="1" <?php selected($timezone_offset, 1); ?>>UTC +1 (Czas letni)</option>
                                <option value="2" <?php selected($timezone_offset, 2); ?>>UTC +2</option>
                            </select>
                            <p class="description">Ustaw offset dla prawidłowego wyświetlania godzin meczów (czas zimowy/letni).</p>
                        </td>
                    </tr>
                    
                    <!-- Automatyczne odświeżanie -->
                    <tr>
                        <th scope="row">
                            <label for="auto_refresh_enabled">Automatyczne odświeżanie danych</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_refresh_enabled" id="auto_refresh_enabled" value="1" <?php checked($auto_refresh_enabled, 1); ?>>
                                Włącz automatyczne odświeżanie
                            </label>
                            <p class="description">Po włączeniu, dane będą automatycznie odświeżane według harmonogramu poniżej.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Wyświetlanie szczegółów meczów</th>
                        <td>
                            <label style="display:block; margin-bottom:6px;">
                                <input type="checkbox" name="show_incidents" id="show_incidents" value="1" <?php checked($show_incidents, 1); ?>>
                                Pokazuj szczegóły meczów (strzelcy, kartki, zmiany)
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="show_media" id="show_media" value="1" <?php checked($show_media, 1); ?>>
                                Pokazuj media (skróty meczów z YouTube)
                            </label>
                            <p class="description">Dane są pobierane i zapisywane niezależnie od tych ustawień. Przełączniki kontrolują tylko wyświetlanie w shortcodach.</p>
                        </td>
                    </tr>
                </table>

                <h2>🔗 Integracja z Football Pool</h2>
                <p><em>Ustawienia automatycznej synchronizacji wyników meczów z pluginem Football Pool (typer).</em></p>
                <?php
                    $fp_sync_enabled  = get_option('sofascore_fp_sync_enabled', 0);
                    $fp_dry_run       = get_option('sofascore_fp_dry_run', 1);
                    $fp_ranking_filter = get_option('sofascore_fp_ranking_filter', '');
                    $fp_max_sync      = get_option('sofascore_fp_max_sync_per_cycle', 2);
                    $fp_conflict_mode = get_option('sofascore_fp_conflict_mode', 'skip');
                    $fp_active        = is_plugin_active('football-pool/football-pool.php') || class_exists('Football_Pool');
                ?>

                <?php if (!$fp_active): ?>
                <div class="notice notice-warning inline" style="margin:10px 0;">
                    <p><strong>Football Pool nie jest aktywny.</strong> Integracja wymaga aktywnego pluginu Football Pool.</p>
                </div>
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="fp_sync_enabled">Automatyczna synchronizacja</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="fp_sync_enabled" id="fp_sync_enabled" value="1" <?php checked($fp_sync_enabled, 1); ?>>
                                Włącz automatyczne uzupełnianie wyników w Football Pool
                            </label>
                            <p class="description">Gdy włączone, wyniki zakończonych meczów (zmapowanych) będą automatycznie wpisywane do Football Pool. <strong>Wyłącz w razie problemów (kill switch).</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_dry_run">Tryb dry-run (testowy)</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" name="fp_dry_run" id="fp_dry_run" value="1" <?php checked($fp_dry_run, 1); ?>>
                                Tylko loguj, nie zapisuj do Football Pool
                            </label>
                            <p class="description">Gdy włączone, mechanizm loguje co <em>zrobiłby</em> w audit logu (<code>sofascore_fp_sync_log</code>), ale nie modyfikuje danych Football Pool. Idealne do testowania.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_ranking_filter">Filtr rankingów Football Pool</label>
                        </th>
                        <td>
                            <input type="text" name="fp_ranking_filter" id="fp_ranking_filter" value="<?php echo esc_attr($fp_ranking_filter); ?>" class="regular-text" placeholder="np. ESA">
                            <p class="description">Wpisz fragment nazwy rankingów, które mają się pojawiać przy mapowaniu meczów (np. <code>ESA</code> pokaże tylko rankingi z "ESA" w nazwie).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_max_sync">Limit synców na cykl</label>
                        </th>
                        <td>
                            <input type="number" name="fp_max_sync" id="fp_max_sync" value="<?php echo esc_attr($fp_max_sync); ?>" min="1" max="10" style="width:80px;">
                            <p class="description">Maksymalna liczba meczów synchronizowanych w jednym cyklu auto-refresh. Zabezpieczenie przed masową aktualizacją w wyniku błędu.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fp_conflict_mode">Wykrywanie konfliktów</label>
                        </th>
                        <td>
                            <select name="fp_conflict_mode" id="fp_conflict_mode">
                                <option value="skip" <?php selected($fp_conflict_mode, 'skip'); ?>>Pomiń (nie nadpisuj istniejącego wyniku w FP)</option>
                                <option value="overwrite" <?php selected($fp_conflict_mode, 'overwrite'); ?>>Nadpisz (zastąp wynik w FP nowym z SofaScore)</option>
                            </select>
                            <p class="description">Co zrobić, gdy Football Pool już ma wpisany wynik inny niż ten z SofaScore. Zalecane: <strong>Pomiń</strong>.</p>
                        </td>
                    </tr>
                </table>

                <h2>📅 Harmonogram automatycznego odświeżania</h2>
                <p><em>Ustaw zakres godzin i częstotliwość odświeżania dla każdego dnia tygodnia.</em></p>
                
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Dzień tygodnia</th>
                            <th>Od godziny</th>
                            <th>Do godziny</th>
                            <th>Częstotliwość (min)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($days_labels as $day_key => $day_label): 
                            $day_schedule = isset($schedule[$day_key]) ? $schedule[$day_key] : $default_schedule;
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($day_label); ?></strong></td>
                            <td>
                                <input type="time" name="<?php echo esc_attr($day_key); ?>_from" 
                                       value="<?php echo esc_attr($day_schedule['from']); ?>" style="width: 120px;">
                            </td>
                            <td>
                                <input type="time" name="<?php echo esc_attr($day_key); ?>_to" 
                                       value="<?php echo esc_attr($day_schedule['to']); ?>" style="width: 120px;">
                            </td>
                            <td>
                                <input type="number" name="<?php echo esc_attr($day_key); ?>_frequency" 
                                       value="<?php echo esc_attr($day_schedule['frequency']); ?>" 
                                       min="5" max="360" step="5" style="width: 80px;"> minut
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">💾 Zapisz ustawienia</button>
                </p>
            </form>
            
            <!-- Smart Scheduler: Plan dnia -->
            <div style="margin-top:30px; padding:20px; background:#f0f6fc; border:1px solid #0073aa; border-radius:8px;">
                <h2>📋 Smart Scheduler — Plan dnia</h2>
                <p class="description">Algorytm automatycznie skanuje harmonogram meczów raz dziennie i odpytuje API tylko podczas trwania meczów (co 1 minutę).</p>
                
                <div style="margin:15px 0; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" id="btn-rescan-plan" class="button button-primary">🔄 Przeskanuj harmonogram na dziś</button>
                    <button type="button" id="btn-refresh-plan-view" class="button">📊 Odśwież widok</button>
                    <button type="button" id="btn-backfill" class="button" style="background:#e7f5e7; border-color:#46b450;">📥 Uzupełnij incidents i media dla rozegranych meczów</button>
                </div>
                <div id="backfill-progress" style="display:none; margin:10px 0; padding:12px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px;">
                    <strong>Backfill:</strong> <span id="backfill-msg">Rozpoczynanie...</span>
                    <div style="margin-top:8px; background:#eee; border-radius:4px; height:20px;">
                        <div id="backfill-bar" style="height:100%; background:#46b450; border-radius:4px; width:0%; transition:width 0.3s;"></div>
                    </div>
                </div>
                
                <div id="daily-plan-status" style="margin:15px 0; padding:12px; background:#fff; border:1px solid #ddd; border-radius:4px;">
                    <em>Ładowanie planu...</em>
                </div>
                
                <div id="daily-plan-matches" style="margin-top:15px;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var settingsNonce = '<?php echo wp_create_nonce('sofascore_settings'); ?>';
            
            $('#sofascore-settings-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = $(this).serializeArray();
                var postData = {
                    action: 'save_sofascore_settings'
                };
                
                $.each(formData, function(i, field) {
                    postData[field.name] = field.value;
                });
                
                var submitBtn = $(this).find('button[type=submit]');
                submitBtn.prop('disabled', true).text('Zapisywanie...');
                
                $.post(ajaxurl, postData, function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                    } else {
                        alert('❌ Błąd: ' + response.data.message);
                    }
                }).always(function() {
                    submitBtn.prop('disabled', false).text('💾 Zapisz ustawienia');
                });
            });
            
            // Smart Scheduler UI
            function renderPlan(plan) {
                if (!plan || !plan.date) {
                    $('#daily-plan-status').html('<em>Brak planu — kliknij "Przeskanuj harmonogram"</em>');
                    $('#daily-plan-matches').html('');
                    return;
                }
                
                var stateLabels = {
                    'waiting': '⏳ Oczekuje',
                    'checking': '🔍 Sprawdzanie startu',
                    'live': '🔴 LIVE',
                    'finished': '✅ Zakończony',
                    'abandoned': '⚠️ Porzucony',
                    'force_check': '🔄 Wymuszony reset'
                };
                var stateColors = {
                    'waiting': '#666',
                    'checking': '#e67e22',
                    'live': '#e74c3c',
                    'finished': '#27ae60',
                    'abandoned': '#95a5a6',
                    'force_check': '#3498db'
                };
                
                var statusHtml = '<strong>Data:</strong> ' + plan.date
                    + ' | <strong>Status:</strong> ' + plan.status
                    + ' | <strong>Wywołań API:</strong> ' + (plan.api_calls_today || 0)
                    + ' | <strong>Meczów:</strong> ' + (plan.matches ? plan.matches.length : 0);
                $('#daily-plan-status').html(statusHtml);
                
                if (!plan.matches || plan.matches.length === 0) {
                    $('#daily-plan-matches').html('<p><em>Brak meczów na dziś</em></p>');
                    return;
                }
                
                var html = '<table class="widefat striped"><thead><tr>'
                    + '<th>Godzina</th><th>Mecz</th><th>Kolejka</th><th>Wynik</th>'
                    + '<th>Minuta</th><th>Status API</th><th>Stan</th><th>Akcja</th>'
                    + '</tr></thead><tbody>';
                
                plan.matches.forEach(function(m) {
                    var time = new Date(m.start_time * 1000);
                    var timeStr = ('0' + time.getHours()).slice(-2) + ':' + ('0' + time.getMinutes()).slice(-2);
                    var score = (m.home_score !== null && m.away_score !== null)
                        ? m.home_score + ':' + m.away_score : '—';
                    var ht = (m.home_score_ht !== null && m.away_score_ht !== null)
                        ? ' (' + m.home_score_ht + ':' + m.away_score_ht + ')' : '';
                    var minute = m.minute !== null ? m.minute + "'" : '—';
                    var stateLabel = stateLabels[m.state] || m.state;
                    var stateColor = stateColors[m.state] || '#333';
                    var resetBtn = (m.state === 'abandoned' || m.state === 'finished')
                        ? '<button class="button button-small btn-reset-match" data-event-id="' + m.event_id + '">Resetuj</button>'
                        : '';
                    
                    html += '<tr>'
                        + '<td>' + timeStr + '</td>'
                        + '<td><strong>' + m.home_team + '</strong> vs <strong>' + m.away_team + '</strong></td>'
                        + '<td style="text-align:center;">' + m.round + '</td>'
                        + '<td style="text-align:center; font-weight:bold;">' + score + ht + '</td>'
                        + '<td style="text-align:center;">' + minute + '</td>'
                        + '<td>' + (m.api_status || '—') + '</td>'
                        + '<td style="color:' + stateColor + '; font-weight:bold;">' + stateLabel + '</td>'
                        + '<td>' + resetBtn + '</td>'
                        + '</tr>';
                });
                
                html += '</tbody></table>';
                $('#daily-plan-matches').html(html);
            }
            
            function loadPlan() {
                $.post(ajaxurl, { action: 'sofascore_get_daily_plan', nonce: settingsNonce }, function(response) {
                    if (response.success) {
                        renderPlan(response.data.plan);
                    }
                });
            }
            
            $('#btn-rescan-plan').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).text('Skanuję...');
                $.post(ajaxurl, { action: 'sofascore_rescan_daily_plan', nonce: settingsNonce }, function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        renderPlan(response.data.plan);
                    } else {
                        alert('❌ ' + (response.data ? response.data.message : 'Błąd'));
                    }
                }).always(function() {
                    btn.prop('disabled', false).text('🔄 Przeskanuj harmonogram na dziś');
                });
            });
            
            $('#btn-refresh-plan-view').on('click', function() {
                loadPlan();
            });
            
            $(document).on('click', '.btn-reset-match', function() {
                var eventId = $(this).data('event-id');
                if (!confirm('Czy na pewno chcesz zresetować ten mecz? Algorytm zacznie odpytywać API od następnego cyklu.')) return;
                var btn = $(this);
                btn.prop('disabled', true).text('...');
                $.post(ajaxurl, { action: 'sofascore_reset_match', nonce: settingsNonce, event_id: eventId }, function(response) {
                    if (response.success) {
                        alert('✅ ' + response.data.message);
                        renderPlan(response.data.plan);
                    } else {
                        alert('❌ ' + (response.data ? response.data.message : 'Błąd'));
                    }
                }).always(function() {
                    btn.prop('disabled', false).text('Resetuj');
                });
            });
            
            // Backfill incidents i media
            $('#btn-backfill').on('click', function() {
                if (!confirm('Czy chcesz uzupełnić incidents i media dla wszystkich rozegranych meczów? Może to potrwać kilka minut.')) return;
                var btn = $(this);
                btn.prop('disabled', true);
                $('#backfill-progress').show();

                function runBatch(offset) {
                    $.post(ajaxurl, {
                        action: 'sofascore_backfill_incidents_media',
                        nonce: settingsNonce,
                        offset: offset
                    }, function(response) {
                        if (response.success) {
                            var d = response.data;
                            var pct = d.total > 0 ? Math.round((d.offset / d.total) * 100) : 100;
                            $('#backfill-msg').text(d.message);
                            $('#backfill-bar').css('width', pct + '%');
                            if (!d.done) {
                                runBatch(d.offset);
                            } else {
                                btn.prop('disabled', false);
                                $('#backfill-bar').css('background', '#0073aa');
                            }
                        } else {
                            $('#backfill-msg').text('Błąd: ' + (response.data ? response.data.message : 'nieznany'));
                            btn.prop('disabled', false);
                        }
                    }).fail(function() {
                        $('#backfill-msg').text('Błąd komunikacji z serwerem.');
                        btn.prop('disabled', false);
                    });
                }

                runBatch(0);
            });

            // Załaduj plan przy wejściu na stronę
            loadPlan();
        });
        </script>
        <?php
    }
}

// Inicjalizacja pluginu
new SofaScoreEkstraklasa(); 