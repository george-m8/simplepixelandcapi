# Simple FB Pixel and CAPI

A lightweight plugin that injects Facebook Pixel on the frontend and sends server-side events to Meta’s Conversions API (CAPI). Currently supports PageView and Lead events.

## Description

Simple FB Pixel and CAPI automatically places the Facebook Pixel code in the header of your WordPress site. It also sends server-side page_view events to Meta’s Conversions API on each page load and includes a simple mechanism to send Lead events (both on the client side through the pixel and server side through CAPI).

### With this plugin, you can:
- Track PageViews in both the Pixel and CAPI.
- Trigger Lead events in Pixel and CAPI (e.g., when a user completes a form).

## Features
- Quick Setup: Just drop in your Meta Pixel ID and Access Token in the config.json file.
- Modular Code: Clear separation between building the payload, sending it to CAPI, and hooking into WordPress events.
- Easy Debugging: Turn on/off debug logs via a single constant.
- Ajax Endpoint for Lead Events: Fire a Lead event from any JavaScript code (e.g., after a successful form submission).

## Installation & Setup
1. Upload & Activate
- Place the entire plugin folder (e.g., simple-fb-pixel-capi) in your WordPress wp-content/plugins directory.
- Activate it from Plugins in the WordPress Admin.
2. Add Your Config
- In the plugin directory, open config.json (or create it if it doesn’t exist).
- Provide your Pixel ID and Access Token:

```json
{
  "pixel_id": "YOUR_PIXEL_ID",
  "access_token": "YOUR_ACCESS_TOKEN"
}
```

3. Set Debug Mode (Optional)
- Open the main plugin file (e.g., simple-fb-pixel-capi.php) and look for:

```php
define('SIMPLE_PIXEL_DEBUG', true);
```

- Switch it to false if you don’t want verbose logging.

## Usage

### PageView Events
1. Automatic on Page Load
- Once the plugin is active, every time a user visits a page, the Pixel code will fire a standard PageView event client-side.
- Simultaneously, your server will send a page_view event to Meta’s CAPI in the background.
2. No Extra Setup
- There is nothing else you need to do for the PageView event to work.

### Lead Events
1. Client-Side Trigger
- The plugin provides a JavaScript function called sendHubspotLeadEvent(). If a page has your plugin’s JS enqueued, you can trigger a lead event simply by calling:

```js
sendHubspotLeadEvent();
```

- This will do two things:
1. Fire a Lead event on the Pixel client side: fbq('track', 'Lead');
2. Make an AJAX call to admin-ajax.php?action=send_lead_capi_event to send a server-side Lead event.

2. Example Usage in a Form
- If you’re using a form (e.g., HubSpot), you can call sendHubspotLeadEvent() inside the form’s on-submit success callback, so that your Lead event fires right after the user submits.
3. Result
- If everything is set up correctly, your plugin will send the Lead event to Facebook from both the client and the server.

### `sendHubspotLeadEvent()` Usage

The `sentHubspotLeadEvent()` function can be used to send a lead event after any trigger. Below are a couple of examples of its intended use:

#### Hubspot Forms

##### Example of standart Hubspot form embed:
Your typical Hubspot form should be embedded as follows.
```html
<script charset="utf-8" type="text/javascript" src="//js.hsforms.net/forms/embed/v2.js"></script>
<script>
  hbspt.forms.create({
    region: "na1",
    portalId: "portalId",
    formId: "formId"
  });
</script>
```
##### Example of standart Hubspot form embed with lead event sent on submission:
Tracking can be added using the `onFormSubmit` callback, as per the example below.
```html
<script charset="utf-8" type="text/javascript" src="//js.hsforms.net/forms/embed/v2.js"></script>
<script>
  hbspt.forms.create({
    region: "na1",
    portalId: "portalId",
    formId: "formId",
    onFormSubmitted: function() {
       sendHubspotLeadEvent();
    }
  });
</script>
```

### Hubspot Meeting Booking Forms

#### Example of standard Hubspot Meeting booking form:
Typically, meeting booking form embeds will look like the example below. They do not offer a callback on submission.
```html
<!-- Start of Meetings Embed Script -->
<div class="meetings-iframe-container" data-src="https://meetings.hubspot.com/form-src?embed=true"></div>
<script type="text/javascript" src="https://static.hsappstatic.net/MeetingsEmbed/ex/MeetingsEmbedCode.js"></script>

<!-- End of Meetings Embed Script -->
```


#### Example of Hubspot Meeting booking form with lead event sent on submission:
As there is no callback, here we use a mutation observer to look for an element with class `'.success-header'` appearing on the page. This should cause the lead event to be sent upon the form success message. Other applications may difer. 
```html
<!-- Start of Meetings Embed Script -->
<div class="meetings-iframe-container" data-src="https://meetings.hubspot.com/form-src?embed=true"></div>
<script type="text/javascript" src="https://static.hsappstatic.net/MeetingsEmbed/ex/MeetingsEmbedCode.js"></script>
<!-- End of Meetings Embed Script -->
<script>
    window.addEventListener("message", function(event) {
        if (event.origin === "https://meetings.hubspot.com" ){
            console.log("Event origin is https://meetings.hubspot.com");
      
            const data = event.data;
      
            // Only respond to messages indicating a successful booking
            if (data && data.meetingBookSucceeded === true) {
            console.log("Meeting was successfully booked:", data);

            // Call your local function, e.g.:
            window.sendHubspotLeadEvent();
  
            // If you like, you can also parse data.meetingsPayload for more details.
            }
        }
        else {
            console.log("Event origin is not https://meetings.hubspot.com");
            console.log("Event origin:", event.origin);
        }
    });
</script>
 ```


### Using the event listener
If you are using the Hubspot plugin to embed forms on your website or do not wish to use callbacks, then you can use the built in event listener. Just edit config.json to include:

```json
"use_event_listener": true
```

This will automatically add javascript to your pages to trigger the lead event on form submission or calendar bookings.

## Debugging
1. Check Dev Tools
- In your browser’s Dev Tools → Network tab, look for:
- The Pixel request (https://www.facebook.com/tr?...) for the client-side.
- An AJAX request to admin-ajax.php?action=send_lead_capi_event when firing the Lead event.
2. Enable WordPress Debug Logs
- In your wp-config.php, enable:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

- Look in wp-content/debug.log for plugin debug messages. If SIMPLE_PIXEL_DEBUG is true, you should see logs about payload building and sending.

3. Check the Response
- If SIMPLE_PIXEL_DEBUG is true, the plugin will log both the payload sent to Meta and the response.
- If the response from Meta indicates “Success”, you’re good to go. If there’s an error, you’ll see it in the logs (e.g. invalid access token).

## Technical Details
- Plugin Files:
- simple-fb-pixel-capi.php (Main plugin file with hooks & actions)
- includes/capi-functions.php (Helper functions for building payloads and sending to CAPI)
- js/hubspotTracking.js (JavaScript for triggering the lead event)
- config.json (Holds your Pixel ID and Access Token)
- Server-Side:
- Uses wp_remote_post() to send the JSON payload to https://graph.facebook.com/v12.0/{pixel_id}/events?access_token={access_token}.
- Client-Side:
- Injects the Facebook Pixel script via wp_head.
- Fires PageView automatically.
- Provides the sendHubspotLeadEvent function for firing the Lead event.