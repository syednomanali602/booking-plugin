<?php
if (!defined('ABSPATH')) exit;

class CCB_Plugin {
    private static $instance = null;
    private $table;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'ccb_bookings';

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'dependency_notice']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_ccb_google_connect', [$this, 'google_connect']);
        add_action('admin_post_ccb_google_disconnect', [$this, 'google_disconnect']);
        add_action('admin_post_ccb_google_callback', [$this, 'google_callback']);
        add_action('admin_post_ccb_cancel_booking', [$this, 'cancel_booking']);

        add_shortcode('bookings', [$this, 'shortcode']);
        add_shortcode('call_booking', [$this, 'shortcode']); // legacy alias, keeps existing pages working
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);

        add_action('rest_api_init', function () {
            register_rest_route('ccb/v1', '/availability', [
                'methods' => 'GET',
                'callback' => [$this, 'availability_endpoint'],
                'permission_callback' => '__return_true',
                'args' => [
                    'date' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']
                ]
            ]);
            register_rest_route('ccb/v1', '/book', [
                'methods' => 'POST',
                'callback' => [$this, 'book_endpoint'],
                'permission_callback' => '__return_true'
            ]);
        });
    }

    public static function activate() {
        global $wpdb;
        $table = $wpdb->prefix . 'ccb_bookings';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_name VARCHAR(190) NOT NULL,
            customer_email VARCHAR(190) NOT NULL,
            message TEXT NULL,
            start_utc DATETIME NOT NULL,
            end_utc DATETIME NOT NULL,
            timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Karachi',
            google_event_id VARCHAR(255) NULL,
            google_meet_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_start_utc (start_utc),
            KEY status (status)
        ) $charset;";
        dbDelta($sql);

        if (!get_option('ccb_settings')) {
            add_option('ccb_settings', self::default_settings());
        }
    }

    public static function deactivate() {
        // Keep booking data and OAuth settings on deactivation.
    }

    public function dependency_notice() {
        if (!current_user_can('manage_options')) return;
        if (!file_exists(CCB_PATH . 'vendor/autoload.php')) {
            echo '<div class="notice notice-warning"><p><strong>Booking:</strong> Google API library missing. Run <code>composer install --no-dev</code> inside the plugin folder, or upload the generated <code>vendor</code> directory.</p></div>';
        }
    }

    public function register_assets() {
        wp_register_style('ccb-booking', CCB_URL . 'assets/booking.css', [], CCB_VERSION);
        wp_register_script('ccb-booking', CCB_URL . 'assets/booking.js', [], CCB_VERSION, true);
    }

    public function shortcode() {
        $s = $this->settings();
        $duration = (int)$s['duration'];

        wp_enqueue_style('ccb-booking');
        wp_enqueue_script('ccb-booking');
        wp_localize_script('ccb-booking', 'CCB', [
            'availabilityUrl' => esc_url_raw(rest_url('ccb/v1/availability')),
            'bookingUrl' => esc_url_raw(rest_url('ccb/v1/book')),
            'nonce' => wp_create_nonce('wp_rest'),
            'timezone' => $s['timezone'] ?: 'Asia/Karachi',
            'duration' => $duration
        ]);

        $logo_url = !empty($s['brand_logo_id']) ? wp_get_attachment_image_url((int)$s['brand_logo_id'], 'full') : '';
        $brand_label = trim($s['brand_text'] . $s['brand_accent']);
        $heading = str_replace('{duration}', (string)$duration, $s['heading']);

        ob_start(); ?>
        <div class="ccb-widget" style="<?php echo esc_attr($this->css_vars()); ?>">
            <div class="ccb-intro">
                <?php if ($logo_url): ?>
                    <div class="ccb-brand ccb-brand-logo"><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($brand_label); ?>"></div>
                <?php elseif ($brand_label !== ''): ?>
                    <div class="ccb-brand"><?php echo esc_html($s['brand_text']); ?><span><?php echo esc_html($s['brand_accent']); ?></span></div>
                <?php endif; ?>
                <?php if ($s['eyebrow'] !== ''): ?><div class="ccb-eyebrow"><?php echo esc_html($s['eyebrow']); ?></div><?php endif; ?>
                <?php if ($heading !== ''): ?><h2><?php echo esc_html($heading); ?></h2><?php endif; ?>
                <?php if ($s['description'] !== ''): ?><p><?php echo nl2br(esc_html($s['description'])); ?></p><?php endif; ?>
                <div class="ccb-meta">◷ &nbsp;<?php echo (int)$duration; ?> Minutes</div>
                <div class="ccb-meta">▣ &nbsp;Google Meet</div>
            </div>
            <div class="ccb-calendar-panel">
                <h3>Select a Date</h3>
                <div class="ccb-month">
                    <button type="button" class="ccb-prev" aria-label="Previous month">‹</button>
                    <strong class="ccb-month-label"></strong>
                    <button type="button" class="ccb-next" aria-label="Next month">›</button>
                </div>
                <div class="ccb-calendar-grid">
                    <span>Sun</span><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span>
                </div>
                <p class="ccb-timezone"></p>
            </div>
            <div class="ccb-slots-panel">
                <h3>Available Times</h3>
                <p class="ccb-selected-date">Select a date first</p>
                <div class="ccb-slots"><p class="ccb-muted">Please select a date.</p></div>
                <button type="button" class="ccb-continue" disabled>Continue →</button>
            </div>
            <div class="ccb-form-wrap" hidden>
                <form class="ccb-form">
                    <button type="button" class="ccb-close" aria-label="Close">×</button>
                    <h3>Your Details</h3>
                    <p class="ccb-booking-summary"></p>
                    <label>Your name<input name="name" type="text" autocomplete="name" required maxlength="190"></label>
                    <label>Email address<input name="email" type="email" autocomplete="email" required maxlength="190"></label>
                    <label>What would you like to discuss?<textarea name="message" rows="3" maxlength="3000"></textarea></label>
                    <div class="ccb-form-error" role="alert"></div>
                    <button class="ccb-submit" type="submit">Confirm Booking</button>
                </form>
            </div>
            <div class="ccb-result" hidden role="status"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Builds the inline CSS custom properties from the Style tab.
     * Empty "inherit" values fall back to the accent / accent-text colors.
     */
    private function css_vars() {
        $st = $this->style();
        $pick = function ($key, $fallback) use ($st) {
            return $st[$key] !== '' ? $st[$key] : $fallback;
        };
        $accent = $st['primary'];
        $accent_text = $st['primary_text'];

        $vars = [
            '--ccb-primary' => $accent,
            '--ccb-primary-text' => $accent_text,
            '--ccb-bg' => $st['widget_bg'],
            '--ccb-border' => $st['border'],
            '--ccb-text' => $st['heading'],
            '--ccb-muted' => $st['muted'],
            '--ccb-meta' => $st['meta_text'],
            '--ccb-brand-text' => $st['brand_text'],
            '--ccb-brand-accent' => $pick('brand_accent', $accent),
            '--ccb-slots-bg' => $st['slots_bg'],
            '--ccb-nav-bg' => $st['nav_bg'],
            '--ccb-nav-icon' => $pick('nav_icon', $accent),
            '--ccb-weekday' => $st['weekday'],
            '--ccb-date-text' => $st['date_text'],
            '--ccb-date-disabled' => $st['date_disabled'],
            '--ccb-date-active-bg' => $pick('date_active_bg', $accent),
            '--ccb-date-active-text' => $pick('date_active_text', $accent_text),
            '--ccb-slot-bg' => $st['slot_bg'],
            '--ccb-slot-text' => $pick('slot_text', $accent),
            '--ccb-slot-border' => $pick('slot_border', $accent),
            '--ccb-slot-hover-bg' => $pick('slot_hover_bg', $accent),
            '--ccb-slot-hover-text' => $pick('slot_hover_text', $accent_text),
            '--ccb-btn-bg' => $pick('button_bg', $accent),
            '--ccb-btn-text' => $pick('button_text', $accent_text),
            '--ccb-form-bg' => $st['form_bg'],
            '--ccb-form-text' => $st['form_text'],
            '--ccb-input-bg' => $st['input_bg'],
            '--ccb-input-border' => $st['input_border'],
            '--ccb-overlay' => $this->hex_to_rgba($st['overlay'], 0.58),
            '--ccb-error' => $st['error'],
            '--ccb-success-bg' => $st['success_bg'],
            '--ccb-success-icon' => $st['success_icon'],
        ];

        $out = '';
        foreach ($vars as $name => $value) {
            $out .= $name . ':' . $value . ';';
        }
        return $out;
    }

    private function hex_to_rgba($hex, $alpha) {
        $hex = ltrim((string)$hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return 'rgba(17,24,39,' . $alpha . ')';
        return sprintf('rgba(%d,%d,%d,%s)', hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $alpha);
    }

    public function admin_menu() {
        add_menu_page(
            'Bookings',
            'Bookings',
            'manage_options',
            'ccb-bookings',
            [$this, 'render_bookings_page'],
            'dashicons-calendar-alt',
            26
        );
        add_submenu_page('ccb-bookings', 'Google Calendar Settings', 'Settings', 'manage_options', 'ccb-settings', [$this, 'render_settings_page']);
    }

    public function register_settings() {
        register_setting('ccb_settings_group', 'ccb_settings', ['sanitize_callback' => [$this, 'sanitize_settings']]);
        register_setting('ccb_style_group', 'ccb_style', ['sanitize_callback' => [$this, 'sanitize_style']]);
    }

    public function admin_assets() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'ccb-settings') return;
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_style('ccb-admin', CCB_URL . 'assets/admin.css', [], CCB_VERSION);
        wp_enqueue_script('ccb-admin', CCB_URL . 'assets/admin.js', ['jquery', 'wp-color-picker'], CCB_VERSION, true);
    }

    public static function allowed_durations() {
        return [30, 45, 60];
    }

    public static function default_settings() {
        return [
            'client_id' => '',
            'client_secret' => '',
            'timezone' => 'Asia/Karachi',
            'start_hour' => 9,
            'end_hour' => 17,
            'days_ahead' => 30,
            'minimum_notice' => 120,
            'buffer_minutes' => 0,
            'duration' => 30,
            'brand_logo_id' => 0,
            'brand_text' => 'Your',
            'brand_accent' => 'Brand.',
            'eyebrow' => 'INTRODUCTORY MEETING',
            'heading' => 'Book a {duration}-Minute Call',
            'description' => "Let's discuss your project, ideas and how we can help you achieve your goals."
        ];
    }

    /**
     * Empty string = "use the accent / accent-text color".
     */
    public static function default_style() {
        return [
            'primary' => '#635bff',
            'primary_text' => '#ffffff',
            'brand_text' => '#202938',
            'brand_accent' => '',
            'widget_bg' => '#ffffff',
            'border' => '#eceef3',
            'slots_bg' => '#fafaff',
            'overlay' => '#111827',
            'heading' => '#202938',
            'muted' => '#7b8492',
            'meta_text' => '#535d6b',
            'nav_bg' => '#f0efff',
            'nav_icon' => '',
            'weekday' => '#929aa7',
            'date_text' => '#333d4b',
            'date_disabled' => '#d2d6dd',
            'date_active_bg' => '',
            'date_active_text' => '',
            'slot_bg' => '#ffffff',
            'slot_text' => '',
            'slot_border' => '',
            'slot_hover_bg' => '',
            'slot_hover_text' => '',
            'button_bg' => '',
            'button_text' => '',
            'form_bg' => '#ffffff',
            'form_text' => '#202938',
            'input_bg' => '#ffffff',
            'input_border' => '#d9dee7',
            'error' => '#b42318',
            'success_bg' => '#e6f8ed',
            'success_icon' => '#16834a'
        ];
    }

    public function sanitize_settings($input) {
        $d = self::default_settings();
        $input = is_array($input) ? $input : [];

        $timezone = sanitize_text_field($input['timezone'] ?? $d['timezone']);
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) $timezone = $d['timezone'];

        $duration = absint($input['duration'] ?? $d['duration']);
        if (!in_array($duration, self::allowed_durations(), true)) $duration = $d['duration'];

        $logo_id = absint($input['brand_logo_id'] ?? 0);
        if ($logo_id && !wp_attachment_is_image($logo_id)) $logo_id = 0;

        return [
            'client_id' => sanitize_text_field($input['client_id'] ?? ''),
            'client_secret' => sanitize_text_field($input['client_secret'] ?? ''),
            'timezone' => $timezone,
            'start_hour' => max(0, min(23, absint($input['start_hour'] ?? $d['start_hour']))),
            'end_hour' => max(1, min(24, absint($input['end_hour'] ?? $d['end_hour']))),
            'days_ahead' => max(1, min(180, absint($input['days_ahead'] ?? $d['days_ahead']))),
            'minimum_notice' => max(0, absint($input['minimum_notice'] ?? $d['minimum_notice'])),
            'buffer_minutes' => max(0, absint($input['buffer_minutes'] ?? $d['buffer_minutes'])),
            'duration' => $duration,
            'brand_logo_id' => $logo_id,
            'brand_text' => sanitize_text_field($input['brand_text'] ?? $d['brand_text']),
            'brand_accent' => sanitize_text_field($input['brand_accent'] ?? $d['brand_accent']),
            'eyebrow' => sanitize_text_field($input['eyebrow'] ?? $d['eyebrow']),
            'heading' => sanitize_text_field($input['heading'] ?? $d['heading']),
            'description' => sanitize_textarea_field($input['description'] ?? $d['description'])
        ];
    }

    public function sanitize_style($input) {
        $input = is_array($input) ? $input : [];
        $out = [];
        foreach (self::default_style() as $key => $default) {
            $value = isset($input[$key]) ? sanitize_hex_color(trim((string)$input[$key])) : '';
            $out[$key] = $value ? $value : $default;
        }
        return $out;
    }

    private function settings() {
        return wp_parse_args(get_option('ccb_settings', []), self::default_settings());
    }

    private function style() {
        return wp_parse_args(get_option('ccb_style', []), self::default_style());
    }

    private function google_client() {
        $autoload = CCB_PATH . 'vendor/autoload.php';
        if (!file_exists($autoload)) {
            return new WP_Error('ccb_missing_library', 'Google API library is not installed.');
        }
        require_once $autoload;

        $s = $this->settings();
        if (empty($s['client_id']) || empty($s['client_secret'])) {
            return new WP_Error('ccb_missing_credentials', 'Add your Google OAuth Client ID and Client Secret in plugin settings.');
        }

        $client = new Google\Client();
        $client->setApplicationName('WordPress Bookings');
        $client->setClientId($s['client_id']);
        $client->setClientSecret($s['client_secret']);
        $client->setRedirectUri(admin_url('admin-post.php?action=ccb_google_callback'));
        $client->setScopes([
            Google\Service\Calendar::CALENDAR_EVENTS,
            Google\Service\Calendar::CALENDAR_FREEBUSY
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client;
    }

    private function timezone_options($selected) {
        $groups = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $parts = explode('/', $id, 2);
            $region = count($parts) > 1 ? $parts[0] : 'Other';
            $groups[$region][] = $id;
        }
        $now = new DateTime('now', new DateTimeZone('UTC'));
        foreach ($groups as $region => $ids) {
            echo '<optgroup label="' . esc_attr($region) . '">';
            foreach ($ids as $id) {
                $offset = (new DateTimeZone($id))->getOffset($now);
                $sign = $offset < 0 ? '-' : '+';
                $abs = abs($offset);
                $label = str_replace('_', ' ', $id) . sprintf(' (UTC%s%02d:%02d)', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60));
                echo '<option value="' . esc_attr($id) . '"' . selected($selected, $id, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</optgroup>';
        }
    }

    private function style_fields() {
        $inherit = 'Leave empty to use the Accent color.';
        $inherit_text = 'Leave empty to use the Accent text color.';
        return [
            'Accent & Brand' => [
                'primary' => ['Accent color', 'Main color used for buttons, active dates and time slots.'],
                'primary_text' => ['Accent text color', 'Text color shown on top of the accent color.'],
                'brand_text' => ['Brand text color', 'Color of the first part of the brand text.'],
                'brand_accent' => ['Brand accent text color', $inherit]
            ],
            'Backgrounds & Borders' => [
                'widget_bg' => ['Widget background', ''],
                'border' => ['Border / divider color', ''],
                'slots_bg' => ['Available times panel background', ''],
                'overlay' => ['Popup overlay color', 'Shown behind the details popup (semi-transparent).']
            ],
            'Text' => [
                'heading' => ['Heading & main text', ''],
                'muted' => ['Muted / description text', ''],
                'meta_text' => ['Duration & Meet info text', '']
            ],
            'Calendar' => [
                'nav_bg' => ['Month arrows background', ''],
                'nav_icon' => ['Month arrows icon', $inherit],
                'weekday' => ['Weekday names (Sun, Mon…)', ''],
                'date_text' => ['Available date text', ''],
                'date_disabled' => ['Unavailable date text', ''],
                'date_active_bg' => ['Selected / hovered date background', $inherit],
                'date_active_text' => ['Selected / hovered date text', $inherit_text]
            ],
            'Time Slots' => [
                'slot_bg' => ['Time slot background', ''],
                'slot_text' => ['Time slot text', $inherit],
                'slot_border' => ['Time slot border', $inherit],
                'slot_hover_bg' => ['Selected / hovered slot background', $inherit],
                'slot_hover_text' => ['Selected / hovered slot text', $inherit_text]
            ],
            'Buttons' => [
                'button_bg' => ['Button background (Continue / Confirm)', $inherit],
                'button_text' => ['Button text', $inherit_text]
            ],
            'Details Popup' => [
                'form_bg' => ['Popup background', ''],
                'form_text' => ['Popup text', ''],
                'input_bg' => ['Input background', ''],
                'input_border' => ['Input border', '']
            ],
            'Status' => [
                'error' => ['Error text', ''],
                'success_bg' => ['Success icon background', ''],
                'success_icon' => ['Success icon color', '']
            ]
        ];
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $tab = (isset($_GET['tab']) && $_GET['tab'] === 'style') ? 'style' : 'general';
        $base = admin_url('admin.php?page=ccb-settings');
        ?>
        <div class="wrap ccb-admin">
            <h1>Bookings Settings</h1>
            <?php settings_errors(); ?>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url($base); ?>" class="nav-tab <?php echo $tab === 'general' ? 'nav-tab-active' : ''; ?>">General</a>
                <a href="<?php echo esc_url(add_query_arg('tab', 'style', $base)); ?>" class="nav-tab <?php echo $tab === 'style' ? 'nav-tab-active' : ''; ?>">Style</a>
            </h2>
            <?php $tab === 'style' ? $this->render_style_tab() : $this->render_general_tab(); ?>
        </div>
        <?php
    }

    private function render_general_tab() {
        $s = $this->settings();
        $token = get_option('ccb_google_token', []);
        $connected = !empty($token['refresh_token']) || !empty($token['access_token']);
        $logo_id = (int)$s['brand_logo_id'];
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('ccb_settings_group'); ?>

            <h2 class="title">Booking Page Content</h2>
            <table class="form-table">
                <tr>
                    <th>Brand logo</th>
                    <td>
                        <input type="hidden" id="ccb-logo-id" name="ccb_settings[brand_logo_id]" value="<?php echo esc_attr($logo_id); ?>">
                        <div class="ccb-logo-preview"><?php if ($logo_url) echo '<img src="' . esc_url($logo_url) . '" alt="">'; ?></div>
                        <button type="button" class="button ccb-logo-select">Select Logo</button>
                        <button type="button" class="button ccb-logo-remove"<?php echo $logo_url ? '' : ' style="display:none"'; ?>>Remove</button>
                        <p class="description">If a logo is selected, it is shown instead of the brand text below.</p>
                    </td>
                </tr>
                <tr><th>Brand text</th><td><input class="regular-text" name="ccb_settings[brand_text]" value="<?php echo esc_attr($s['brand_text']); ?>"><p class="description">Example: Your</p></td></tr>
                <tr><th>Brand accent text</th><td><input class="regular-text" name="ccb_settings[brand_accent]" value="<?php echo esc_attr($s['brand_accent']); ?>"><p class="description">Shown right after the brand text, in the accent color. Example: Brand.</p></td></tr>
                <tr><th>Eyebrow text</th><td><input class="regular-text" name="ccb_settings[eyebrow]" value="<?php echo esc_attr($s['eyebrow']); ?>"><p class="description">Small label above the heading. Leave empty to hide.</p></td></tr>
                <tr><th>Heading</th><td><input class="regular-text" name="ccb_settings[heading]" value="<?php echo esc_attr($s['heading']); ?>"><p class="description">Use <code>{duration}</code> to insert the selected slot time (e.g. “Book a {duration}-Minute Call”).</p></td></tr>
                <tr><th>Description</th><td><textarea class="large-text" rows="4" name="ccb_settings[description]"><?php echo esc_textarea($s['description']); ?></textarea></td></tr>
            </table>

            <h2 class="title">Schedule</h2>
            <table class="form-table">
                <tr>
                    <th>Timezone</th>
                    <td>
                        <select name="ccb_settings[timezone]" class="ccb-timezone-select"><?php $this->timezone_options($s['timezone']); ?></select>
                    </td>
                </tr>
                <tr>
                    <th>Slot duration</th>
                    <td>
                        <select name="ccb_settings[duration]">
                            <?php foreach (self::allowed_durations() as $minutes): ?>
                                <option value="<?php echo (int)$minutes; ?>"<?php selected((int)$s['duration'], $minutes); ?>><?php echo (int)$minutes; ?> Minutes</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr><th>Daily start hour</th><td><input type="number" min="0" max="23" name="ccb_settings[start_hour]" value="<?php echo esc_attr($s['start_hour']); ?>"></td></tr>
                <tr><th>Daily end hour</th><td><input type="number" min="1" max="24" name="ccb_settings[end_hour]" value="<?php echo esc_attr($s['end_hour']); ?>"></td></tr>
                <tr><th>Booking window (days)</th><td><input type="number" min="1" max="180" name="ccb_settings[days_ahead]" value="<?php echo esc_attr($s['days_ahead']); ?>"></td></tr>
                <tr><th>Minimum notice (minutes)</th><td><input type="number" min="0" name="ccb_settings[minimum_notice]" value="<?php echo esc_attr($s['minimum_notice']); ?>"></td></tr>
                <tr><th>Buffer between calls (minutes)</th><td><input type="number" min="0" name="ccb_settings[buffer_minutes]" value="<?php echo esc_attr($s['buffer_minutes']); ?>"></td></tr>
            </table>

            <h2 class="title">Google Calendar API</h2>
            <table class="form-table">
                <tr><th>Google OAuth Client ID</th><td><input class="regular-text" name="ccb_settings[client_id]" value="<?php echo esc_attr($s['client_id']); ?>"></td></tr>
                <tr><th>Google OAuth Client Secret</th><td><input type="password" class="regular-text" name="ccb_settings[client_secret]" value="<?php echo esc_attr($s['client_secret']); ?>" autocomplete="new-password"></td></tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
        <hr>
        <h2>Google Calendar Connection</h2>
        <p>Status: <strong><?php echo $connected ? 'Connected (token saved)' : 'Not connected'; ?></strong></p>
        <?php if ($connected): ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ccb_google_disconnect">
                <?php wp_nonce_field('ccb_google_disconnect'); ?>
                <?php submit_button('Disconnect Google Calendar', 'secondary'); ?>
            </form>
        <?php else: ?>
            <p>Save your Client ID and Client Secret first, then connect your Google account.</p>
            <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ccb_google_connect'), 'ccb_google_connect')); ?>">Connect Google Calendar</a>
        <?php endif; ?>
        <p class="description">Authorized redirect URI to enter in Google Cloud Console:</p>
        <code><?php echo esc_html(admin_url('admin-post.php?action=ccb_google_callback')); ?></code>
        <?php
    }

    private function render_style_tab() {
        $style = $this->style();
        $defaults = self::default_style();
        ?>
        <p>Customize the booking widget colors. Fonts and layout are not changeable here.</p>
        <form method="post" action="options.php">
            <?php settings_fields('ccb_style_group'); ?>
            <?php foreach ($this->style_fields() as $group => $fields): ?>
                <h2 class="title"><?php echo esc_html($group); ?></h2>
                <table class="form-table">
                    <?php foreach ($fields as $key => $meta): ?>
                        <tr>
                            <th><label for="ccb-style-<?php echo esc_attr($key); ?>"><?php echo esc_html($meta[0]); ?></label></th>
                            <td>
                                <input type="text" class="ccb-color" id="ccb-style-<?php echo esc_attr($key); ?>"
                                       name="ccb_style[<?php echo esc_attr($key); ?>]"
                                       value="<?php echo esc_attr($style[$key]); ?>"
                                       <?php if ($defaults[$key] !== ''): ?>data-default-color="<?php echo esc_attr($defaults[$key]); ?>"<?php endif; ?>>
                                <?php if ($meta[1] !== ''): ?><p class="description"><?php echo esc_html($meta[1]); ?></p><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>
            <p class="submit">
                <?php submit_button('Save Style', 'primary', 'submit', false); ?>
                <button type="button" class="button" id="ccb-reset-colors">Reset all colors to default</button>
            </p>
        </form>
        <?php
    }

    public function google_connect() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('ccb_google_connect');
        $client = $this->google_client();
        if (is_wp_error($client)) wp_die(esc_html($client->get_error_message()));
        $client->setState(wp_create_nonce('ccb_google_oauth_state'));
        wp_safe_redirect($client->createAuthUrl());
        exit;
    }

    public function google_callback() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (empty($_GET['code']) || empty($_GET['state']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['state'])), 'ccb_google_oauth_state')) {
            wp_die('Google authorization failed. Please try connecting again.');
        }
        $client = $this->google_client();
        if (is_wp_error($client)) wp_die(esc_html($client->get_error_message()));
        try {
            $token = $client->fetchAccessTokenWithAuthCode(sanitize_text_field(wp_unslash($_GET['code'])));
            if (isset($token['error'])) throw new Exception($token['error_description'] ?? $token['error']);
            $existing = get_option('ccb_google_token', []);
            if (empty($token['refresh_token']) && !empty($existing['refresh_token'])) {
                $token['refresh_token'] = $existing['refresh_token'];
            }
            update_option('ccb_google_token', $token, false);
            wp_safe_redirect(admin_url('admin.php?page=ccb-settings&ccb_connected=1'));
            exit;
        } catch (Exception $e) {
            wp_die('Google connection error: ' . esc_html($e->getMessage()));
        }
    }

    public function google_disconnect() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('ccb_google_disconnect');
        delete_option('ccb_google_token');
        wp_safe_redirect(admin_url('admin.php?page=ccb-settings'));
        exit;
    }

    private function calendar_service() {
        $client = $this->google_client();
        if (is_wp_error($client)) return $client;
        $token = get_option('ccb_google_token', []);
        if (empty($token)) return new WP_Error('ccb_not_connected', 'Google Calendar is not connected.');
        $client->setAccessToken($token);
        if ($client->isAccessTokenExpired()) {
            if (empty($token['refresh_token'])) return new WP_Error('ccb_no_refresh_token', 'Google refresh token missing. Disconnect and reconnect Google Calendar.');
            $new = $client->fetchAccessTokenWithRefreshToken($token['refresh_token']);
            if (isset($new['error'])) return new WP_Error('ccb_refresh_failed', $new['error_description'] ?? $new['error']);
            $new['refresh_token'] = $token['refresh_token'];
            update_option('ccb_google_token', $new, false);
            $client->setAccessToken($new);
        }
        return new Google\Service\Calendar($client);
    }

    private function date_timezone() {
        $s = $this->settings();
        try { return new DateTimeZone($s['timezone']); }
        catch (Exception $e) { return new DateTimeZone('Asia/Karachi'); }
    }

    private function get_busy_periods($start, $end) {
        $service = $this->calendar_service();
        if (is_wp_error($service)) return $service;
        try {
            $request = new Google\Service\Calendar\FreeBusyRequest([
                'timeMin' => $start->format(DateTime::RFC3339),
                'timeMax' => $end->format(DateTime::RFC3339),
                'timeZone' => $this->date_timezone()->getName(),
                'items' => [['id' => 'primary']]
            ]);
            $response = $service->freebusy->query($request);
            $calendars = $response->getCalendars();
            $primary = $calendars['primary'] ?? null;
            $busy = [];
            if ($primary) {
                foreach ($primary->getBusy() as $period) {
                    $busy[] = [
                        'start' => new DateTime($period->getStart()),
                        'end' => new DateTime($period->getEnd())
                    ];
                }
            }
            return $busy;
        } catch (Exception $e) {
            return new WP_Error('ccb_freebusy_error', $e->getMessage());
        }
    }

    public function availability_endpoint($request) {
        $date_string = sanitize_text_field($request->get_param('date'));
        $date = DateTime::createFromFormat('!Y-m-d', $date_string, $this->date_timezone());
        if (!$date || $date->format('Y-m-d') !== $date_string) {
            return new WP_Error('ccb_invalid_date', 'Invalid date.', ['status' => 400]);
        }

        $s = $this->settings();
        $today = new DateTime('today', $this->date_timezone());
        $last = (clone $today)->modify('+' . absint($s['days_ahead']) . ' days');
        if ($date < $today || $date > $last || (int)$date->format('N') > 5) {
            return rest_ensure_response(['slots' => []]);
        }

        $day_start = (clone $date)->setTime((int)$s['start_hour'], 0);
        $day_end = (clone $date)->setTime((int)$s['end_hour'], 0);
        $busy = $this->get_busy_periods($day_start, $day_end);
        if (is_wp_error($busy)) return new WP_Error('ccb_calendar_error', 'Unable to check calendar availability. Please try again later.', ['status' => 503]);

        $slots = [];
        $now = new DateTime('now', $this->date_timezone());
        $minimum = (clone $now)->modify('+' . absint($s['minimum_notice']) . ' minutes');
        $buffer = absint($s['buffer_minutes']);

        $duration = (int)$s['duration'];

        for ($cursor = clone $day_start; $cursor < $day_end; $cursor->modify('+' . $duration . ' minutes')) {
            $slot_start = clone $cursor;
            $slot_end = (clone $slot_start)->modify('+' . $duration . ' minutes');
            if ($slot_end > $day_end || $slot_start < $minimum) continue;

            $conflict = false;
            foreach ($busy as $period) {
                $busy_start = (clone $period['start'])->modify('-' . $buffer . ' minutes');
                $busy_end = (clone $period['end'])->modify('+' . $buffer . ' minutes');
                if ($slot_start < $busy_end && $slot_end > $busy_start) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict && !$this->local_booking_conflict($slot_start, $slot_end, $buffer)) {
                $slots[] = [
                    'value' => $slot_start->format('H:i'),
                    'label' => $slot_start->format('h:i A')
                ];
            }
        }
        return rest_ensure_response(['slots' => $slots]);
    }

    private function local_booking_conflict($start, $end, $buffer = 0) {
        global $wpdb;
        $start_utc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_utc = (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $buffer_seconds = absint($buffer) * 60;
        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE status IN ('pending','confirmed')
             AND start_utc < DATE_ADD(%s, INTERVAL %d SECOND)
             AND end_utc > DATE_SUB(%s, INTERVAL %d SECOND)",
            $end_utc, $buffer_seconds, $start_utc, $buffer_seconds
        );
        return (int)$wpdb->get_var($query) > 0;
    }

    public function book_endpoint($request) {
        $data = $request->get_json_params();
        $name = sanitize_text_field($data['name'] ?? '');
        $email = sanitize_email($data['email'] ?? '');
        $message = sanitize_textarea_field($data['message'] ?? '');
        $date_string = sanitize_text_field($data['date'] ?? '');
        $time_string = sanitize_text_field($data['time'] ?? '');

        if (!$name || !is_email($email) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_string) || !preg_match('/^\d{2}:\d{2}$/', $time_string)) {
            return new WP_Error('ccb_invalid_input', 'Please provide a valid name, email, date and time.', ['status' => 400]);
        }

        $timezone = $this->date_timezone();
        $start = DateTime::createFromFormat('!Y-m-d H:i', $date_string . ' ' . $time_string, $timezone);
        if (!$start || $start->format('Y-m-d H:i') !== $date_string . ' ' . $time_string) {
            return new WP_Error('ccb_invalid_datetime', 'Invalid booking date or time.', ['status' => 400]);
        }
        $s = $this->settings();
        $duration = (int)$s['duration'];
        $end = (clone $start)->modify('+' . $duration . ' minutes');

        $today = new DateTime('today', $timezone);
        $last = (clone $today)->modify('+' . absint($s['days_ahead']) . ' days');
        $day_start = (clone $start)->setTime((int)$s['start_hour'], 0);
        $day_end = (clone $start)->setTime((int)$s['end_hour'], 0);
        $minimum = (new DateTime('now', $timezone))->modify('+' . absint($s['minimum_notice']) . ' minutes');

        if ($start < $minimum || $start < $today || $start > $last || (int)$start->format('N') > 5 || $start < $day_start || $end > $day_end || ((int)(($start->getTimestamp() - $day_start->getTimestamp()) / 60) % $duration !== 0)) {
            return new WP_Error('ccb_slot_invalid', 'This time is outside the available booking schedule.', ['status' => 400]);
        }

        $service = $this->calendar_service();
        if (is_wp_error($service)) return new WP_Error('ccb_calendar_not_ready', $service->get_error_message(), ['status' => 503]);

        $buffer = absint($s['buffer_minutes']);
        if ($this->local_booking_conflict($start, $end, $buffer)) {
            return new WP_Error('ccb_slot_taken', 'This slot has just been booked. Please choose another time.', ['status' => 409]);
        }

        $busy = $this->get_busy_periods($start, $end);
        if (is_wp_error($busy)) return new WP_Error('ccb_calendar_error', 'Unable to verify calendar availability.', ['status' => 503]);
        foreach ($busy as $period) {
            $busy_start = (clone $period['start'])->modify('-' . $buffer . ' minutes');
            $busy_end = (clone $period['end'])->modify('+' . $buffer . ' minutes');
            if ($start < $busy_end && $end > $busy_start) {
                return new WP_Error('ccb_slot_taken', 'This slot is no longer available.', ['status' => 409]);
            }
        }

        global $wpdb;
        $start_utc = (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $end_utc = (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $inserted = $wpdb->insert($this->table, [
            'customer_name' => $name,
            'customer_email' => $email,
            'message' => $message,
            'start_utc' => $start_utc,
            'end_utc' => $end_utc,
            'timezone' => $timezone->getName(),
            'status' => 'pending',
            'created_at' => current_time('mysql', true)
        ], ['%s','%s','%s','%s','%s','%s','%s','%s']);

        if (!$inserted) {
            return new WP_Error('ccb_slot_taken', 'This slot is already being booked. Please choose another time.', ['status' => 409]);
        }

        $booking_id = (int)$wpdb->insert_id;
        try {
            $event = new Google\Service\Calendar\Event([
                'summary' => $duration . '-Minute Call with ' . $name,
                'description' => "Customer: {$name}\nEmail: {$email}\n\nMessage:\n{$message}",
                'start' => [
                    'dateTime' => $start->format(DateTime::RFC3339),
                    'timeZone' => $timezone->getName()
                ],
                'end' => [
                    'dateTime' => $end->format(DateTime::RFC3339),
                    'timeZone' => $timezone->getName()
                ],
                'attendees' => [['email' => $email, 'displayName' => $name]],
                'conferenceData' => [
                    'createRequest' => [
                        'requestId' => wp_generate_uuid4(),
                        'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                    ]
                ]
            ]);

            $created = $service->events->insert('primary', $event, [
                'conferenceDataVersion' => 1,
                'sendUpdates' => 'all'
            ]);

            $meet_url = '';
            $conference = $created->getConferenceData();
            if ($conference) {
                foreach ((array)$conference->getEntryPoints() as $entry) {
                    if ($entry->getEntryPointType() === 'video') {
                        $meet_url = $entry->getUri();
                        break;
                    }
                }
            }

            $status = $meet_url ? 'confirmed' : 'pending';
            $wpdb->update($this->table, [
                'google_event_id' => $created->getId(),
                'google_meet_url' => $meet_url,
                'status' => $status
            ], ['id' => $booking_id], ['%s','%s','%s'], ['%d']);

            wp_mail(
                get_option('admin_email'),
                'New ' . $duration . '-minute booking',
                "New booking from {$name} ({$email}).\nDate: {$start->format('Y-m-d H:i')} ({$timezone->getName()})\nMeet: " . ($meet_url ?: 'Conference link is being generated.')
            );

            return rest_ensure_response([
                'success' => true,
                'bookingId' => $booking_id,
                'status' => $status,
                'meetUrl' => $meet_url,
                'message' => $meet_url ? 'Your call has been booked.' : 'Your calendar event was created. The meeting link may take a moment to become available.'
            ]);
        } catch (Exception $e) {
            $wpdb->delete($this->table, ['id' => $booking_id], ['%d']);
            return new WP_Error('ccb_event_failed', 'Unable to create the calendar event. Please try another slot or contact us.', ['status' => 502]);
        }
    }

    public function render_bookings_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY start_utc DESC LIMIT 300");
        ?>
        <div class="wrap">
            <h1>Bookings</h1>
            <p>Showing the latest 300 bookings.</p>
            <table class="widefat striped">
                <thead><tr><th>ID</th><th>Customer</th><th>Date / Time</th><th>Meet</th><th>Status</th><th>Action</th></tr></thead>
                <tbody>
                <?php if (!$rows): ?><tr><td colspan="6">No bookings found.</td></tr><?php endif; ?>
                <?php foreach ($rows as $row):
                    $local = new DateTime($row->start_utc, new DateTimeZone('UTC'));
                    $local->setTimezone($this->date_timezone()); ?>
                    <tr>
                        <td><?php echo (int)$row->id; ?></td>
                        <td><strong><?php echo esc_html($row->customer_name); ?></strong><br><?php echo esc_html($row->customer_email); ?></td>
                        <td><?php echo esc_html($local->format('D, d M Y h:i A')); ?></td>
                        <td><?php if ($row->google_meet_url): ?><a href="<?php echo esc_url($row->google_meet_url); ?>" target="_blank" rel="noopener">Join Meet</a><?php else: ?>—<?php endif; ?></td>
                        <td><?php echo esc_html(ucfirst($row->status)); ?></td>
                        <td>
                            <?php if ($row->status !== 'cancelled'): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Cancel this booking and its calendar event?');">
                                <input type="hidden" name="action" value="ccb_cancel_booking">
                                <input type="hidden" name="booking_id" value="<?php echo (int)$row->id; ?>">
                                <?php wp_nonce_field('ccb_cancel_' . (int)$row->id); ?>
                                <button class="button button-small">Cancel</button>
                            </form>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function cancel_booking() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = absint($_POST['booking_id'] ?? 0);
        check_admin_referer('ccb_cancel_' . $id);
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id));
        if ($booking && $booking->google_event_id) {
            $service = $this->calendar_service();
            if (!is_wp_error($service)) {
                try { $service->events->delete('primary', $booking->google_event_id, ['sendUpdates' => 'all']); }
                catch (Exception $e) { /* Keep local cancellation state; admin can reconcile in Calendar. */ }
            }
        }
        if ($booking) {
            $wpdb->update($this->table, ['status' => 'cancelled'], ['id' => $id], ['%s'], ['%d']);
        }
        wp_safe_redirect(admin_url('admin.php?page=ccb-bookings'));
        exit;
    }
}
