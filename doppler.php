<?php
/*
Plugin Name: Doppler Traffic Detector
Description: Detects requests from known AI bots (OpenAI, Perplexity, Google, Bing) and logs them to Doppler API asynchronously.
Version: 2.0
Author: You
*/

register_activation_hook(__FILE__, 'doppler_activate');
function doppler_activate() {
    doppler_download_ip_lists();
    if (!wp_next_scheduled('doppler_daily_cron')) {
        wp_schedule_event(time(), 'daily', 'doppler_daily_cron');
    }
}

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('doppler_daily_cron');
});

add_action('doppler_daily_cron', 'doppler_download_ip_lists');

add_action('init', function() {
    $api_key = get_option('doppler_api_key');
    if (!$api_key || empty(trim($api_key))) return;

    $filters = doppler_load_filters();
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $ip = doppler_get_ip($headers);
    $ua = $headers['user-agent'] ?? '';
    $url = $_SERVER['REQUEST_URI'] ?? '';
    $matched = false;
    $utmMatched = false;
    $highlightedText = null;

    // --- extract highlighted text fragment
    if (strpos($url, '#:~:text=') !== false) {
        if (preg_match('/#:~:text=([^&]+)/', $url, $matches)) {
            $highlightedText = urldecode($matches[1]);
        }
    }

    // --- extract utm_source
    $utmSource = null;
    if (strpos($url, '?') !== false || strpos($url, '&') !== false) {
        $queryString = parse_url($url, PHP_URL_QUERY);
        parse_str($queryString, $queryParams);
        if (isset($queryParams['utm_source'])) {
            $utmSource = $queryParams['utm_source'];
        }
    }

    if ($utmSource) {
        foreach ($filters as $filter) {
            foreach ($filter['utm'] as $utm) {
                if (strpos($utmSource, $utm) !== false) {
                    $matched = true;
                    $utmMatched = true;
                    doppler_log_payload([
                        "source" => $filter['name'],
                        "intent" => "browse",
                        "type" => "click",
                        "userAgent" => null,
                        "destinationURL" => doppler_current_url(),
                        "highlightedText" => $highlightedText,
                        "headers" => null
                    ]);
                    break 2;
                }
            }
        }
    }

    if (!$utmMatched) {
        foreach ($filters as $filter) {
            if (doppler_check_ip($ip, $filter['ips']) && doppler_check_ua($ua, $filter['userAgents'])) {
                $matched = true;
                doppler_log_payload([
                    "source" => $filter['name'],
                    "intent" => "crawl",
                    "type" => "crawl",
                    "userAgent" => $ua,
                    "destinationURL" => doppler_current_url(),
                    "highlightedText" => null,
                    "headers" => $headers
                ]);
                break;
            }
        }
    }

    error_log("[Doppler] IP={$ip} UA=\"{$ua}\" matched=" . ($matched ? "true" : "false"));
});

add_action('admin_menu', function() {
    add_options_page('Doppler Settings', 'Doppler', 'manage_options', 'doppler', 'doppler_settings_page');
});

add_action('admin_init', function() {
    register_setting('doppler_options', 'doppler_api_key');
});

function doppler_settings_page() {
?>
<div class="wrap">
<h1>Doppler Settings</h1>
<form method="post" action="options.php">
    <?php settings_fields('doppler_options'); ?>
    <?php do_settings_sections('doppler_options'); ?>
    <table class="form-table">
        <tr valign="top">
            <th scope="row">Doppler API Key</th>
            <td><input type="text" name="doppler_api_key" value="<?php echo esc_attr(get_option('doppler_api_key')); ?>" size="50"/></td>
        </tr>
    </table>
    <?php submit_button(); ?>
</form>
</div>
<?php
}

function doppler_load_filters() {
    $dir = plugin_dir_path(__FILE__) . 'filters/';
    $files = glob($dir . '*.json');
    $filters = [];
    foreach ($files as $file) {
        $filters[] = json_decode(file_get_contents($file), true);
    }
    return $filters;
}

function doppler_get_ip($headers) {
    if (!empty($headers['x-forwarded-for'])) {
        return trim(explode(',', $headers['x-forwarded-for'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function doppler_check_ip($ip, $ranges) {
    foreach ($ranges as $cidr) {
        if (strpos($cidr, '/') !== false && doppler_ip_in_cidr($ip, $cidr)) return true;
        if ($ip === $cidr || strpos($ip, $cidr) === 0) return true;
    }
    return false;
}

function doppler_check_ua($ua, $knownUAs) {
    foreach ($knownUAs as $needle) {
        if (stripos($ua, $needle) !== false) return true;
    }
    return false;
}

function doppler_ip_in_cidr($ip, $cidr) {
    list($subnet, $maskLength) = explode('/', $cidr);
    $ipBin = inet_pton($ip);
    $subnetBin = inet_pton($subnet);

    if ($ipBin === false || $subnetBin === false) return false;

    $maskBytes = floor($maskLength / 8);
    $maskBits = $maskLength % 8;

    if ($maskBytes > 0 && substr_compare($ipBin, $subnetBin, 0, $maskBytes) !== 0) return false;

    if ($maskBits > 0) {
        $ipByte = ord($ipBin[$maskBytes]);
        $subnetByte = ord($subnetBin[$maskBytes]);
        $mask = ~((1 << (8 - $maskBits)) - 1) & 0xFF;
        if (($ipByte & $mask) !== ($subnetByte & $mask)) return false;
    }

    return true;
}

function doppler_current_url() {
    return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
}

function doppler_log_payload($payload) {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    $args = [
        'headers' => [
            'Authorization' => 'Bearer ' . get_option('doppler_api_key'),
            'Content-Type'  => 'application/json'
        ],
        'body' => json_encode($payload),
        'timeout' => 1,
        'blocking' => false
    ];
    wp_remote_post('https://askdoppler.com/api/traffic', $args);
}

function doppler_download_ip_lists() {
    $dir = plugin_dir_path(__FILE__) . 'filters/';
    if (!file_exists($dir)) mkdir($dir);

    $filters = [
        'openai' => [
            'urls' => ['https://openai.com/searchbot.json','https://openai.com/chatgpt-user.json','https://openai.com/gptbot.json'],
            'agents' => ['OAI-SearchBot/1.0','ChatGPT-User/1.0','+https://openai.com/bot','+https://openai.com/searchbot','GPTBot/1.1','+https://openai.com/gptbot'],
            'utm' => ['chatgpt.com','openai.com']
        ],
        'google' => [
            'urls' => ['https://developers.google.com/static/search/apis/ipranges/googlebot.json','https://developers.google.com/static/search/apis/ipranges/special-crawlers.json','https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers.json','https://developers.google.com/static/search/apis/ipranges/user-triggered-fetchers-google.json'],
            'agents' => ['Google-CloudVertexBot','Googlebot','Google-Extended'],
            'utm' => ['google.com']
        ],
        'bing' => [
            'urls' => ['https://www.bing.com/toolbox/bingbot.json'],
            'agents' => ['bingbot/2.0','+http://www.bing.com/bingbot'],
            'utm' => ['bing.com']
        ],
        'perplexity' => [
            'urls' => ['https://www.perplexity.com/perplexitybot.json','https://www.perplexity.com/perplexity-user.json'],
            'agents' => ['PerplexityBot/1.0','+https://perplexity.ai/perplexitybot','Perplexity-User/1.0','+https://perplexity.ai/perplexity-user'],
            'utm' => ['perplexity.ai','perplexity.com']
        ]
    ];

    foreach ($filters as $name => $info) {
        $ips = [];
        foreach ($info['urls'] as $url) {
            $json = @file_get_contents($url);
            if ($json) {
                $data = json_decode($json,true);
                if (!empty($data['prefixes'])) {
                    foreach ($data['prefixes'] as $p) {
                        $ips[] = $p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? '';
                    }
                }
            }
        }
        $out = ["name"=>$name,"ips"=>$ips,"userAgents"=>$info['agents'],"utm"=>$info['utm']];
        file_put_contents($dir.$name.'.json', json_encode($out));
    }
}
