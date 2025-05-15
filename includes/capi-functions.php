<?php
defined('ABSPATH') || exit;

/**
 * Return configuration from config.json or fallback if missing
 *
 * @param string $key The config key to retrieve, e.g. 'pixel_id' or 'access_token'
 * @return mixed|false
 */
function simple_fb_get_config($key) {
    $config_file = plugin_dir_path(__FILE__) . '../config.json';
    if (!file_exists($config_file)) {
        return false;
    }

    $config = json_decode(file_get_contents($config_file), true);
    return $config[$key] ?? false;
}

/**
 * Build a Facebook CAPI payload
 *
 * @param string $eventName e.g. 'page_view', 'Lead', etc.
 * @param bool   $debug     Whether we want debug logs
 * @param array  $additionalData Additional data like event_source_url or extra user_data
 * @return array The structured payload ready for sending
 */
/**
 * Recursively sanitize CAPI data arrays.
 *
 * @param mixed $data
 * @return mixed
 */
function simple_fb_sanitize_capi_data( $data ) {
    if ( is_array( $data ) ) {
        $out = [];
        foreach ( $data as $key => $value ) {
            // sanitize key too
            $key = sanitize_text_field( (string) $key );
            $out[ $key ] = simple_fb_sanitize_capi_data( $value );
        }
        return $out;
    }

    if ( is_string( $data ) ) {
        return sanitize_text_field( $data );
    }

    // allow ints/floats through
    if ( is_int( $data ) || is_float( $data ) ) {
        return $data;
    }

    // everything else: cast to string then sanitize
    return sanitize_text_field( (string) $data );
}

/**
 * Build a safe FB CAPI payload.
 *
 * @param string $eventName      e.g. 'Purchase'
 * @param bool   $debug          whether to wp_debug-log the payload
 * @param array  $additionalData any extra keys (custom_data, event_source_url, etc)
 * @return array
 */

function simple_fb_build_capi_payload( $eventName, $debug = false, $additionalData = [] ) {
    // Clean up the event name
    $event_name = preg_replace( '/[^A-Za-z0-9_]/', '_', sanitize_text_field( $eventName ) );

    // Pull & sanitize cookies
    $fbp = isset( $_COOKIE['_fbp'] )
        ? sanitize_text_field( wp_unslash( $_COOKIE['_fbp'] ) )
        : null;
    $fbc = isset( $_COOKIE['_fbc'] )
        ? sanitize_text_field( wp_unslash( $_COOKIE['_fbc'] ) )
        : null;

    // Sanitize server values
    $ua = ! empty( $_SERVER['HTTP_USER_AGENT'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
        : null;

    // Grab the true client IP (v4 or v6) that Cloudflare passes along
    $client_ip = null;

    // Try Cloudflare’s header first
    if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
        $cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
        if ( filter_var( $cf_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
            $client_ip = $cf_ip;
        }
    }
    
    // Fallback to REMOTE_ADDR
    if ( is_null( $client_ip ) && ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $remote = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        if ( filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
            $client_ip = $remote;
        }
    }

    // Build user_data, only non-empty
    $user_data = array_filter( [
        'fbp'                => $fbp,
        'fbc'                => $fbc,
        'client_user_agent'  => $ua,
        'client_ip_address'  => $client_ip,
    ], function( $v ) { return ! is_null( $v ) && $v !== ''; } );

    // Sanitize any incoming additionalData
    $additional = simple_fb_sanitize_capi_data( $additionalData );

    // Use incoming event_id if available, otherwise generate a unique one
    if ( isset( $additional['event_id'] ) ) {
        $event_id = sanitize_text_field( $additional['event_id'] );
        unset( $additional['event_id'] );
    } else {
        $event_id = $event_name . '_' . uniqid();
    }

    // Assemble core event
    $event = [
        'event_name'    => $event_name,
        'event_time'    => time(),
        'action_source' => 'website',
        'event_id'      => $event_id,
        'user_data'     => $user_data,
    ];

    // Merge in any extra fields (custom_data, event_source_url, etc)
    if ( ! empty( $additional ) && is_array( $additional ) ) {
        $event = array_merge( $event, $additional );
    }

    $payload = [ 'data' => [ $event ] ];

    if ( $debug && defined('WP_DEBUG') && WP_DEBUG ) {
        error_log( 'FB CAPI payload: ' . wp_json_encode( $payload ) );
    }

    return $payload;
}

/**
 * Send the Facebook CAPI event to Meta
 *
 * @param array $payload  The payload array from build_capi_payload
 * @param bool  $debug    Whether we want debug logs
 * @return array|WP_Error The response
 */
function simple_fb_send_capi_event($payload, $debug = false) {
    // Grab pixel & token
    $pixel_id    = simple_fb_get_config('pixel_id');
    $accessToken = simple_fb_get_config('access_token');

    if (!$pixel_id || !$accessToken) {
        // If missing config, return an error early
        $error = new WP_Error('capi_config_missing', 'Missing Pixel ID or Access Token.');
        if ($debug) {
            error_log('CAPI error: ' . $error->get_error_message());
        }
        return $error;
    }

    // Endpoint
    $url = "https://graph.facebook.com/v17.0/{$pixel_id}/events?access_token={$accessToken}";

    // Make the request
    $response = wp_remote_post($url, [
        'body'    => json_encode($payload),
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        if ($debug) {
            error_log('CAPI request error: ' . $response->get_error_message());
        }
        return $response;
    }

    if ($debug) {
        error_log('CAPI event sent successfully. Response: ' . print_r($response, true));
    }

    return $response;
}