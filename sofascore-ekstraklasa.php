<?php
/**
 * Plugin Name: Soccer ScrAPI
 * Plugin URI: https://nafciarski.pl
 * Description: Plugin do pobierania i wyświetlania danych Ekstraklasy (SofaScore API) oraz III ligi - Wisła II Płock (90minut.pl)
 * Version: 1.6.0
 * Author: Majkel
 * License: GPL v2 or later
 * Text Domain: sofascore-ekstraklasa
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Stałe pluginu
define('SOFASCORE_PLUGIN_VERSION', '1.6.0');
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
        
        // Zbierz wszystkie mecze Wisły Płock
        $wisla_matches = array();
        
        foreach ($saved_rounds as $round_num => $round_data) {
            if (isset($round_data['data']['events'])) {
                foreach ($round_data['data']['events'] as $match) {
                    // Sprawdź czy Wisła Płock gra w tym meczu
                    $is_wisla_match = (
                        stripos($match['homeTeam']['name'], 'Wisła Płock') !== false ||
                        stripos($match['awayTeam']['name'], 'Wisła Płock') !== false
                    );
                    
                    if ($is_wisla_match) {
                        $match['round_number'] = $round_num;
                        $wisla_matches[] = $match;
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
        
        // Filtruj mecze - usuń postponed
        $filtered_events = array_filter($data['events'], function($match) {
            $status = strtolower($match['status']['description'] ?? '');
            return $status !== 'postponed';
        });
        
        $events = array_slice($filtered_events, 0, intval($atts['limit']));
        
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
                    
                    // Dla zakończonych meczów pobierz szczegóły z wynikami
                    $event_details = null;
                    if ($is_finished && isset($match['id'])) {
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
                    <div class="<?php echo $match_class; ?>">
                        <div class="match-teams">
                            <span class="home-team"><?php echo esc_html($match['homeTeam']['name']); ?></span>
                            <span class="vs">
                                <?php if ($is_finished && $event_details && isset($event_details['event']['homeScore'], $event_details['event']['awayScore'])): ?>
                                    <span class="match-separator">-</span>
                                <?php else: ?>
                                    vs
                                <?php endif; ?>
                            </span>
                            <span class="away-team"><?php echo esc_html($match['awayTeam']['name']); ?></span>
                        </div>
                        <div class="match-info">
                            <?php if ($is_finished && $event_details && isset($event_details['event']['homeScore'], $event_details['event']['awayScore'])): ?>
                                <span class="match-result">
                                    <strong><?php echo $event_details['event']['homeScore']['current']; ?>:<?php echo $event_details['event']['awayScore']['current']; ?></strong> 
                                    <span class="halftime-result">(<?php echo $event_details['event']['homeScore']['period1']; ?>:<?php echo $event_details['event']['awayScore']['period1']; ?>)</span>
                                </span>
                            <?php else: ?>
                                <span class="match-date"><?php echo date('d.m.Y H:i', $this->apply_timezone_offset($match['startTimestamp'])); ?></span>
                                <?php if (!empty($status)): ?>
                                    <span class="match-status"><?php echo esc_html($status); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
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
        </style>
        <?php
        
        return ob_get_clean();
    }
    
    /**
     * Renderuj tabelę terminarza Wisły Płock
     */
    private function render_wisla_fixtures_table($matches, $atts) {
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
                    
                    // Dla zakończonych meczów pobierz szczegóły z wynikami
                    $event_details = null;
                    if ($is_finished && isset($match['id'])) {
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
                    ?>
                     <div class="match-item wisla-match">
                         <div class="match-round">
                             <span class="round-number">Kolejka <?php echo esc_html($match['round_number']); ?></span>
                         </div>
                        <div class="match-teams">
                            <span class="home-team <?php echo $wisla_home ? 'wisla-team' : ''; ?>">
                                <?php echo esc_html($match['homeTeam']['name']); ?>
                            </span>
                            <span class="vs">
                                <?php if ($is_finished && $event_details && isset($event_details['event']['homeScore'], $event_details['event']['awayScore'])): ?>
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
                            <?php if ($is_finished && $event_details && isset($event_details['event']['homeScore'], $event_details['event']['awayScore'])): ?>
                                <span class="match-result">
                                    <strong><?php echo $event_details['event']['homeScore']['current']; ?>:<?php echo $event_details['event']['awayScore']['current']; ?></strong> 
                                    <span class="halftime-result">(<?php echo $event_details['event']['homeScore']['period1']; ?>:<?php echo $event_details['event']['awayScore']['period1']; ?>)</span>
                                </span>
                            <?php else: ?>
                                <span class="match-date"><?php echo date('d.m.Y H:i', $this->apply_timezone_offset($match['startTimestamp'])); ?></span>
                                <?php if (!empty($status)): ?>
                                    <span class="match-status"><?php echo esc_html($status); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
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
        </style>
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
        
        // Zaplanuj auto-refresh jeśli jest włączony
        if (get_option('sofascore_auto_refresh_enabled', 0)) {
            if (!wp_next_scheduled('sofascore_auto_refresh')) {
                wp_schedule_event(time(), 'every_5_minutes', 'sofascore_auto_refresh');
            }
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
        // Zaplanuj pierwsze zadanie
        wp_schedule_event(time(), 'hourly', 'sofascore_update_data');
        
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
    }

    /**
     * Deaktywacja pluginu
     */
    public function deactivate() {
        // Usuń zaplanowane zadania
        wp_clear_scheduled_hook('sofascore_update_data');
        
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
            'interval' => 300, // 5 minut w sekundach
            'display'  => __('Co 5 minut')
        );
        return $schedules;
    }
    
    /**
     * Automatyczne odświeżanie danych (wykonywane co 5 minut)
     */
    public function auto_refresh_data() {
        // Sprawdź czy auto-refresh jest włączony
        if (!get_option('sofascore_auto_refresh_enabled', 0)) {
            return;
        }
        
        // Pobierz harmonogram
        $schedule = get_option('sofascore_refresh_schedule', array());
        if (empty($schedule)) {
            return;
        }
        
        // Pobierz aktualny dzień tygodnia i czas
        $current_day = strtolower(date('l')); // monday, tuesday, etc.
        $current_time = date('H:i');
        
        // Sprawdź czy dzisiaj jest w harmonogramie
        if (!isset($schedule[$current_day])) {
            return;
        }
        
        $day_schedule = $schedule[$current_day];
        $from_time = $day_schedule['from'];
        $to_time = $day_schedule['to'];
        $frequency = intval($day_schedule['frequency']);
        
        // Sprawdź czy jesteśmy w zakresie godzin
        if ($current_time < $from_time || $current_time > $to_time) {
            return;
        }
        
        // Sprawdź czy minęła wymagana częstotliwość od ostatniego odświeżenia
        $last_refresh = get_option('sofascore_last_auto_refresh', 0);
        $time_since_last = time() - $last_refresh;
        $frequency_seconds = $frequency * 60;
        
        if ($time_since_last < $frequency_seconds) {
            return; // Za wcześnie na kolejne odświeżanie
        }
        
        // Wykonaj odświeżanie - pobierz wszystkie zapisane kolejki i je zaktualizuj
        $saved_rounds = get_option('sofascore_saved_rounds', array());
        
        if (!empty($saved_rounds)) {
            foreach ($saved_rounds as $round_key => $round_data) {
                $round_id = $round_data['id'];
                $season_id = $round_data['season_id'];
                $tournament_id = $round_data['tournament_id'];
                
                // Zaktualizuj dane dla tej kolejki
                $this->fetch_and_save_round_data($round_id, $season_id, $tournament_id, $round_key);
            }
            
            // Zapisz czas ostatniego odświeżania
            update_option('sofascore_last_auto_refresh', time());
            
            // Loguj odświeżanie
            error_log(sprintf(
                'SofaScore Auto-Refresh: Zaktualizowano %d kolejek o %s',
                count($saved_rounds),
                date('Y-m-d H:i:s')
            ));
        }
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
        // Wyczyść stary harmonogram
        wp_clear_scheduled_hook('sofascore_auto_refresh');
        
        // Zaplanuj nowy - co 5 minut (będziemy sprawdzać czy jest w harmonogramie)
        if (!wp_next_scheduled('sofascore_auto_refresh')) {
            wp_schedule_event(time(), 'every_5_minutes', 'sofascore_auto_refresh');
        }
    }
    
    /**
     * Strona ustawień
     */
    public function settings_page() {
        $timezone_offset = get_option('sofascore_timezone_offset', 0);
        $auto_refresh_enabled = get_option('sofascore_auto_refresh_enabled', 0);
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
        </div>
        
        <script>
        jQuery(document).ready(function($) {
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
        });
        </script>
        <?php
    }
}

// Inicjalizacja pluginu
new SofaScoreEkstraklasa(); 