<?php
/**
 * Plugin Name: eID Easy
 * Plugin URI: https://eideasy.com/
 * Description: Allow your visitors to login to Wordpress ID-card, Mobile-ID, Smart-ID mobile app and other methods.
 * Version: 4.9.1
 * Author: EID Easy OÜ
 * Author URI: https://eideasy.com/
 * License: GPLv2 or later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

require_once(plugin_dir_path(__FILE__) . 'functions/eideasyLog.php');
require_once(plugin_dir_path(__FILE__) . 'eideasyOptions.php');
require_once(plugin_dir_path(__FILE__) . 'eideasyTemplate.php');

// Register all the templates here
function eideasyTemplateFiles() {
    return [
        'checkbox-template' => plugin_dir_path(__FILE__) . 'templates/checkbox-template.php',
        'login-button-template' => plugin_dir_path(__FILE__) . 'templates/login-button-template.php',
    ];
}

if (!class_exists("IdCardLogin")) {
    require_once(plugin_dir_path(__FILE__) . 'admin.php');

    class IdCardLogin
    {
        public static function save_custom_user_profile_fields($user_id)
        {
            if (!current_user_can('administrator')) {
                return;
            }

            if (!array_key_exists('smartid_user_idcode', $_POST)) {
                return; // New idcode not included in post, not changing the idcode field.
            }

            $idcode = sanitize_text_field($_POST['smartid_user_idcode']);
            if (!$idcode || strlen($idcode) === 0) {
                return; // Not allowing to completely remove idcode.
            }

            global $wpdb;
            $prefix = is_multisite() ? $wpdb->get_blog_prefix(BLOG_ID_CURRENT_SITE) : $wpdb->prefix;

            $table_name = $prefix . "idcard_users";

            $existingUser = $wpdb->get_row(
                $wpdb->prepare("select * from $table_name WHERE identitycode=%s", $idcode)
            );

            if ($existingUser != null && $existingUser->userid == $user_id) {
                return; // same user updated, no need to do anything
            }

            if ($existingUser != null) {
                if ($idcode != "-") {
                    $wpdb->delete($table_name, ['identitycode' => $idcode]);
                }
                $wpdb->update($table_name, ['identitycode' => $idcode], ['userid' => $user_id]);
            } else {
                $wpdb->delete($table_name, ['userid' => $user_id]);
                $wpdb->insert($table_name, array(
                        'firstname'    => "",
                        'lastname'     => "",
                        'identitycode' => $idcode,
                        'userid'       => $user_id,
                        'created_at'   => current_time('mysql')
                    )
                );
            }
        }

        public static function custom_user_profile_fields($user)
        {
            if (!current_user_can('administrator')) {
                return;
            }
            ?>

            <table class="form-table">
                <tbody>
                <tr class="user-email-wrap">
                    <th><label for="smartid_user_idcode">Country + ID code (EE_47102281234)</label></th>
                    <td>
                        <input name="smartid_user_idcode"
                               value="<?php echo esc_attr(IdCardLogin::getIdcodeByUserId($user->ID)); ?>"
                               class='regular-text'/>
                        <br>
                        <small>To remove ID code value write here dash without quotes "-". Empty field will be
                            ignored</small>
                    </td>
                </tr>
                </tbody>
            </table>

            <?php
        }

        public static function getIdcodeByUserId($userId)
        {
            global $wpdb;
            $prefix = is_multisite() ? $wpdb->get_blog_prefix(BLOG_ID_CURRENT_SITE) : $wpdb->prefix;

            $table_name = $prefix . "idcard_users";
            $user       = $wpdb->get_row(
                $wpdb->prepare("select * from $table_name WHERE userid=%s", $userId)
            );

            if ($user == null) {
                return "";
            } else {
                return $user->identitycode;
            }

        }

        public static function getSupportedMethods()
        {
            $smartid_supportedMethods = [
                "smartid_lt-mobile-id_enabled",
                "smartid_lt-id-card_enabled",
                "smartid_be-id-card_enabled",
                "smartid_pt-id-card_enabled",
                "lveid_enabled",
                "eideasy-eparaksts-mobile_enabled",
                "smartid_idcard_enabled",
                "smartid_mobileid_enabled",
                "smartid_smartid_enabled",
                "eideasy-itsme-login-standard_enabled",
            ];

            return $smartid_supportedMethods;
        }

        static function deleteUserCleanUp($user_id)
        {
            global $wpdb;
            $prefix = is_multisite() ? $wpdb->get_blog_prefix(BLOG_ID_CURRENT_SITE) : $wpdb->prefix;
            $wpdb->delete($prefix . "idcard_users", array('userid' => $user_id));
        }

        static function getStoredUserData()
        {
            global $wpdb;
            $current_user = wp_get_current_user();
            $prefix       = is_multisite() ? $wpdb->get_blog_prefix(BLOG_ID_CURRENT_SITE) : $wpdb->prefix;
            $user         = $wpdb->get_row(
                $wpdb->prepare("select * from $prefix" . "idcard_users WHERE userid=%s", $current_user->ID)
            );

            return $user;
        }

        static function isLogin()
        {
            return array_key_exists('code', $_GET) && strlen($_GET['code']) > 20;
        }

        static function wpInitProcess()
        {
            $pluginVersion = get_plugin_data(__FILE__);
            $version       = isset($pluginVersion['Version']) ? $pluginVersion['Version'] : date("ymd-Gis", filemtime(plugin_dir_path(__FILE__)));
            wp_register_script('smartid_functions_js', plugins_url('smartid_functions.js', __FILE__), [], $version);

            if (IdCardLogin::isLogin()) {
                $loginUrl = apply_filters('smartid_login', get_option('smartid_redirect_uri'));
                $loginUrl = apply_filters('eideasy_login', $loginUrl);
                if (IdcardAuthenticate::isAlreadyLogged() && !get_option('eideasy_only_identify')) {
                    wp_redirect($loginUrl);
                    exit;
                }
                eideasyLog("WP plugin login with code=" . sanitize_text_field($_GET['code']));
                require_once(plugin_dir_path(__FILE__) . 'securelogin.php');
                $userId = IdcardAuthenticate::login(sanitize_text_field($_GET['code']));
                if ($userId) {
                    wp_redirect($loginUrl);
                    exit;
                }
            }
        }

        static function admin_notice()
        {
            if (get_option("smartid_client_id") == null && array_key_exists("page",
                    $_GET) && $_GET['page'] !== "smart-id-settings") {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p>Your eID Easy is almost ready! Please open
                        <a href="<?php echo esc_url(get_admin_url(null, 'admin.php?page=eid-easy-settings')) ?>"> eID
                            Easy Settings </a> to activate.
                    </p>
                </div>
                <?php
            }
        }

        static function get_settings_url($links)
        {
            $links[] = '<a href="' . esc_url(get_admin_url(null, 'admin.php?page=eid-easy-settings')) . '">eID Easy Settings</a>';

            return $links;
        }

        static function echo_id_login()
        {
            echo '<div style="margin:auto" align="center">'
                . IdCardLogin::getLoginButtonCode()
                . "</div>";
        }

        static function return_id_login()
        {
            return IdCardLogin::getLoginButtonCode();
        }

        static function display_contract_to_sign($atts)
        {
            if (get_option("smartid_client_id") == null) {
                return "<b>eID Easy service not activated, cannot sign the contract";
            }
            if (!array_key_exists("id", $atts)) {
                return "<b>Contract ID missing, cannot show signing page</b>";
            }
            $code = '<iframe src="https://id.eideasy.com/sign_contract?client_id='
                . get_option("smartid_client_id") . "&contract_id=" . $atts["id"] . '"'
                . 'style="height: 100vh; width: 100vw" frameborder="0"></iframe>';

            return $code;
        }

        /**
         * @return false if login button needs to be shown. Happens when auth_key is missing
         * or auth key is present but WP user is not logged in.
         */
        static function isUserIdLogged()
        {
            if (!is_user_logged_in()) {
                return false;
            } else {
                return IdCardLogin::getStoredUserData() != null;
            }
        }

        static function getLoginButtonCode()
        {
            if (IdCardLogin::isUserIdLogged()) {
                return null;
            }

            if (get_option("smartid_client_id") == null) {
                return "<b>ID login not activated yet. Login will be available as soon as admin has activated it.</b>";
            }

            $allDisabled = true;
            foreach (IdCardLogin::getSupportedMethods() as $method) {
                if (get_option($method) != false) {
                    $allDisabled = false;
                    break;
                }
            }
            if ($allDisabled) {
                return "<b>No Secure login methods enabled yet in Wordpress admin, please contact administrator to enable these from eID Easy config</b>";
            }
            $redirectUri = urlencode(get_option("smartid_redirect_uri"));
            $clientId    = get_option("smartid_client_id");
            $urlParams   = '?client_id=' . $clientId
                . '&redirect_uri=' . $redirectUri
                . '&response_type=code';
            $baseUri     = 'https://id.eideasy.com';
            $loginUri    = $baseUri . "/oauth/authorize" . $urlParams;

            wp_enqueue_script("smartid_functions_js");

            $loginCode = '<style>
                #smartid-login-block .login-button {
                    display:inline;
                    margin-left: 5px;
                    margin-right: 5px;
                }
                #smartid-login-block .login-button img {                    
                    margin: 3px;
                    height: 46px;
                }
                #smartid-login-block .login-square-w img {
                    width: 46px;
                }
                #smartid-login-block .login-middle-w img {
                    width: 130px;
                }
                #smartid-login-block .login-wide-w img {
                    width: 200px;
                }                
            </style><div id="smartid-login-block">';

            foreach (eideasyOptions()['methods'] as $method) {
                if (get_option($method['optionName'])) {
                    $loginCode .= eideasyTemplate(eideasyTemplateFiles()['login-button-template'], [
                        'id' => $method['buttonId'],
                        'filterName' => $method['filterName'],
                        'imageSrc' => IdCardLogin::getPluginBaseUrl() . $method['image'],
                    ]);;
                }
            }

            $loginCode .= '</div><script>' .
                '    if(document.getElementById("smartid-id-login")) document.getElementById("smartid-id-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&start=ee-id-card");' .
                '    });' .
                '    if(document.getElementById("smartid-mid-login")) document.getElementById("smartid-mid-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&method=ee-mobile-id");' .
                '    });' .
                '    if(document.getElementById("smartid-lveid-login")) document.getElementById("smartid-lveid-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&start=lv-id-card");' .
                '    });' .
                '    if(document.getElementById("smartid-lt-id-card-login")) document.getElementById("smartid-lt-id-card-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&start=lt-id-card");' .
                '    });' .
                '    if(document.getElementById("eideasy-eparaksts-mobile-login")) document.getElementById("eideasy-eparaksts-mobile-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $baseUri . "/oauth/start/lv-eparaksts-mobile-login$urlParams" . '");' .
                '    });' .
                '    if(document.getElementById("smartid-be-id-card-login")) document.getElementById("smartid-be-id-card-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&start=be-id-card");' .
                '    });' .
                '    if(document.getElementById("smartid-pt-id-card-login")) document.getElementById("smartid-pt-id-card-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&start=pt-id-card");' .
                '    });' .
                '    if(document.getElementById("smartid-lt-mobile-id-login")) document.getElementById("smartid-lt-mobile-id-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&method=lt-mobile-id");' .
                '    });' .
                '    if(document.getElementById("smartid-smartid-login")) document.getElementById("smartid-smartid-login").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&method=smart-id");' .
                '    });' .
                '    if(document.getElementById("eideasy-itsme-login-standard")) document.getElementById("eideasy-itsme-login-standard").addEventListener("click", function () {' .
                '        startEidEasyLogin("' . $loginUri . '&method=itsme-login-standard");' .
                '    });' .
                '</script>';

            return $loginCode;
        }

        static function getPluginBaseUrl()
        {
            $pUrl     = plugins_url();
            $baseName = plugin_basename(__FILE__);
            // Remove script name and keep only path. DIRECTORY_SEPARATOR is having trouble in IIS
            $pluginFolder = substr($baseName, 0, -12);

            return $pUrl . '/' . $pluginFolder;
        }

        static function apiCall($apiPath, $params, $postParams = null)
        {
            $accessToken = null;
            $headers = [];

            $paramString = "?client_id=" . get_option("smartid_client_id");
            if ($params != null) {
                foreach ($params as $key => $value) {
                    if ($key === "access_token") {
                        $accessToken = $value;
                    } else {
                        $paramString .= "&$key=$value";
                    }
                }
            }

            $bodyParams = [];
            if ($postParams != null) {
                foreach ($postParams as $key => $value) {
                    $bodyParams[$key] = $value;
                }
            }

            if (isset($accessToken)) {
                $headers['authorization'] = 'Bearer ' . $accessToken;
            }

            $url = "https://id.eideasy.com/" . $apiPath . $paramString;

            if (!empty($bodyParams)) {
                $response = wp_remote_post($url, [
                    'headers' => $headers,
                    'body' => $bodyParams,
                ]);
            } else {
                $response = wp_remote_get($url, [
                    'headers' => $headers,
                ]);
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        }

        static function idcard_install()
        {
            $alreadyUsed = false;
            foreach (IdCardLogin::getSupportedMethods() as $value) {
                if (get_option($value)) {
                    $alreadyUsed = true;
                    break;
                }
            }

            // Activate all methods only on first install.
            if (!$alreadyUsed) {
                foreach (IdCardLogin::getSupportedMethods() as $value) {
                    add_option($value, true);
                }
            }

            global $wpdb;

            $prefix = is_multisite() ? $wpdb->get_blog_prefix(BLOG_ID_CURRENT_SITE) : $wpdb->prefix;

            $table_name = $prefix . "idcard_users";

            $sqlCreate = "CREATE TABLE $table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,                
                firstname tinytext NOT NULL,
                lastname tinytext NOT NULL,
                identitycode VARCHAR(21) NOT NULL,
                userid bigint(20) unsigned NOT NULL,
                created_at datetime NOT NULL,
		        access_token VARCHAR(32),
                UNIQUE KEY id (id),
                UNIQUE KEY identitycode (identitycode)
                  );";

            require_once(ABSPATH . '/wp-admin/includes/upgrade.php');
            dbDelta($sqlCreate);

            return "Thank you for installing eID Easy. Open eID Easy settings to activate the service";
        }

        static function enqueueJquery()
        {
            wp_enqueue_script('jquery');
        }
    }

    add_action('delete_user', 'IdCardLogin::deleteUserCleanUp');

    add_action('login_footer', 'IdCardLogin::echo_id_login');
    add_action('login_enqueue_scripts', 'IdCardLogin::enqueueJquery');

    add_action('init', 'IdCardLogin::wpInitProcess');

    register_activation_hook(__FILE__, 'IdCardLogin::idcard_install');

    add_action('plugins_loaded', 'IdCardLogin::idcard_install');
    add_action('admin_notices', 'IdCardLogin::admin_notice');

    add_action('admin_menu', 'IdcardAdmin::id_settings_page');

    add_action('show_user_profile', 'IdCardLogin::custom_user_profile_fields');
    add_action('edit_user_profile', 'IdCardLogin::custom_user_profile_fields');
    add_action('profile_update', 'IdCardLogin::save_custom_user_profile_fields');

    add_shortcode('smart_id', 'IdCardLogin::return_id_login');
    add_shortcode('eid_easy', 'IdCardLogin::return_id_login');
    add_shortcode('contract', 'IdCardLogin::display_contract_to_sign');

    add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'IdCardLogin::get_settings_url');
}
