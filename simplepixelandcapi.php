<?php
/*
Plugin Name: Simple FB Pixel and CAPI
Description: A simple plugin to add the Facebook Pixel code and Meta CAPI to your WordPress site.
Version: 3.7
Author: George M
*/

defined('ABSPATH') || exit;

// Set a debug mode constant: toggle to true/false
define('SIMPLE_PIXEL_DEBUG', true);

// Require our CAPI functions (payload building & sending)
require_once plugin_dir_path(__FILE__) . 'includes/capi-functions.php';

// On page‐load, make one event_id and share it via a global
add_action('template_redirect','simple_fb_generate_pageview_event_id', 1);
function simple_fb_generate_pageview_event_id() {
    if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST')&&REST_REQUEST) ) {
        return;
    }
    global $simple_fb_pageview_event_id;
    $simple_fb_pageview_event_id = 'PageView_' . uniqid();
}

// In your wp_head injection, pull that same ID and use it in fbq()
add_action('wp_head','simple_fb_pixel_inject_code');
function simple_fb_pixel_inject_code() {
    global $simple_fb_pageview_event_id;
    $pixel_id = simple_fb_get_config('pixel_id');
    
    if (SIMPLE_PIXEL_DEBUG) {
        error_log('Attempting to inject FB Pixel. Pixel ID: ' . ($pixel_id ?: 'Not Found'));
    }

    if ( !$pixel_id || empty($simple_fb_pageview_event_id) ) {
        if (SIMPLE_PIXEL_DEBUG) {
            error_log('FB Pixel not injected: no pixel ID or event ID found.');
        }
        return;
    }
    ?>
    <!-- Facebook Meta Pixel Code w/ shared eventID -->
    <script>
      !function(f,b,e,v,n,t,s){/* usual init */}(window,document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init','<?php echo esc_js($pixel_id); ?>');
      fbq(
        'track',
        'PageView',
        {},  // no custom_data
        { eventID: '<?php echo esc_js($simple_fb_pageview_event_id); ?>' }
      );
    </script>
    <noscript>
      <img height="1" width="1" style="display:none"
           src="https://www.facebook.com/tr?
            id=<?php echo esc_attr($pixel_id); ?>&ev=PageView&noscript=1"/>
    </noscript>
    <!-- End Facebook Meta Pixel Code -->
    <?php
    if (SIMPLE_PIXEL_DEBUG) {
        error_log('FB Pixel injected with event ID: ' . ($simple_fb_pageview_event_id ?: 'Not Found'));
    }
}

/**
 * Fire an _fbc cookie from fbclid URL param if not already set.
 */
add_action( 'init', 'simple_fb_pixel_ensure_fbc_cookie' );
function simple_fb_pixel_ensure_fbc_cookie() {
    // Only run for front-end requests
    if ( is_admin() || defined('DOING_AJAX') && DOING_AJAX ) {
        return;
    }

    // If we already have an _fbc cookie, nothing to do
    if ( ! empty( $_COOKIE['_fbc'] ) ) {
        return;
    }

    // If fbclid is in the URL, build and set _fbc
    if ( ! empty( $_GET['fbclid'] ) ) {
        $click_id = sanitize_text_field( wp_unslash( $_GET['fbclid'] ) );
        $ts       = floor( microtime( true ) * 1000 );        // epoch ms
        $fbc      = "fb.1.{$ts}.{$click_id}";

        // 90-day cookie on your site’s root domain, readable by JS
        setcookie(
            '_fbc',
            $fbc,
            time() + ( 90 * DAY_IN_SECONDS ),
            '/',
            COOKIE_DOMAIN,
            is_ssl(),   // Secure only on HTTPS
            false       // HttpOnly off so JS can read if needed
        );

        // Make available immediately in this request
        $_COOKIE['_fbc'] = $fbc;
    }
}

// Hook page view CAPI on template_redirect
add_action('template_redirect', 'simple_fb_pixel_send_pageview_event');
function simple_fb_pixel_send_pageview_event() {
    global $simple_fb_pageview_event_id;

    // Bail on admin, Ajax, REST, cron, feeds, previews…
    if (
        is_admin() ||
        wp_doing_ajax() ||
        wp_doing_cron() ||
        ( defined('REST_REQUEST') && REST_REQUEST ) ||
        is_feed() ||
        is_preview()
    ) {
        return;
    }

    // Only singular posts/pages or home/front page
    if ( ! ( is_singular() || is_front_page() || is_home() ) ) {
        return;
    }

    // If for some reason the event_id wasn't generated, bail
    if ( empty( $simple_fb_pageview_event_id ) ) {
        if ( SIMPLE_PIXEL_DEBUG ) {
            error_log( 'No PageView event_id found; skipping CAPI send.' );
        }
        return;
    }

    if ( SIMPLE_PIXEL_DEBUG ) {
        error_log( 'Preparing to send PageView event via CAPI. event_id: ' . $simple_fb_pageview_event_id );
    }

    // Decide on the canonical URL
    if ( is_singular() ) {
        $event_url = get_permalink();
    } elseif ( is_front_page() || is_home() ) {
        $event_url = home_url();
    } else {
        $event_url = home_url( add_query_arg( null, null ) );
    }

    // Build & send the payload, passing our shared event_id
    $payload = simple_fb_build_capi_payload( 'PageView', SIMPLE_PIXEL_DEBUG, [
        'event_id'         => $simple_fb_pageview_event_id,
        'event_source_url' => esc_url( $event_url ),
    ] );

    if ( SIMPLE_PIXEL_DEBUG ) {
        error_log( 'PageView payload: ' . print_r( $payload, true ) );
    }

    $response = simple_fb_send_capi_event( $payload, SIMPLE_PIXEL_DEBUG );

    if ( SIMPLE_PIXEL_DEBUG ) {
        error_log( 'PageView event response: ' . print_r( $response, true ) );
    }
}

// Register/Enqueue the JS
add_action('wp_enqueue_scripts', 'simple_fb_pixel_enqueue_scripts');
function simple_fb_pixel_enqueue_scripts() {
    if ( SIMPLE_PIXEL_DEBUG ) {
        error_log('[SimplePixel] Enqueueing HubSpot base & listener scripts…');
    }

    // ALWAYS enqueue the tracking lib:
    wp_enqueue_script(
        'simple-pixel-hubspot-tracking',
        plugin_dir_url(__FILE__) . 'js/hubspotTracking.js',
        [],
        '1.0',
        true
    );
    wp_localize_script(
        'simple-pixel-hubspot-tracking',
        'simplePixelData',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'debug'   => SIMPLE_PIXEL_DEBUG,
        ]
    );

    // CONDITIONALLY enqueue the event-listener:
    $use_listener = simple_fb_get_config( 'use_event_listener' );
    if ( SIMPLE_PIXEL_DEBUG ) {
        error_log('[SimplePixel] use_event_listener? ' . ( $use_listener ? 'yes' : 'no' ));
    }
    if ( $use_listener ) {
        if ( SIMPLE_PIXEL_DEBUG ) {
            error_log('[SimplePixel] Enqueueing HubSpot Form Listener script.');
        }
        wp_enqueue_script(
            'simple-pixel-hubspot-event-listener',
            plugin_dir_url(__FILE__) . 'js/hubspotEventListener.js',
            ['simple-pixel-hubspot-tracking'], // ensure it loads after tracking
            '1.0',
            false
        );
    }
}

// AJAX actions for sending a Lead event
add_action('wp_ajax_send_lead_capi_event', 'simple_fb_pixel_lead_capi_event');
add_action('wp_ajax_nopriv_send_lead_capi_event', 'simple_fb_pixel_lead_capi_event');
function simple_fb_pixel_lead_capi_event() {
    if (SIMPLE_PIXEL_DEBUG) {
        error_log('Received AJAX request to send Lead event.');
    }

    // Grab the JS-generated event_id (or fall back)
    $event_id = isset($_POST['event_id'])
        ? sanitize_text_field( wp_unslash( $_POST['event_id'] ) )
        : uniqid('fb_', true);

    // Determine the current page URL
    $event_url = home_url( add_query_arg( null, null ) );

    // Pass it into build_capi_payload’s additionalData
        $payload = simple_fb_build_capi_payload('Lead', SIMPLE_PIXEL_DEBUG, [
            'event_id'         => $event_id,
            'event_source_url' => esc_url($event_url),
        ]);
    

    if (SIMPLE_PIXEL_DEBUG) {
        error_log('Lead payload: ' . print_r($payload, true));
    }

    $response = simple_fb_send_capi_event($payload, SIMPLE_PIXEL_DEBUG);

    if (SIMPLE_PIXEL_DEBUG) {
        if (is_wp_error($response)) {
            error_log('Lead event error: ' . $response->get_error_message());
        } else {
            error_log('Lead event sent successfully.');
        }
    }

    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    } else {
        wp_send_json_success('Lead event sent via CAPI!');
    }
}