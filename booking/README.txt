BOOKING — INSTALLATION

1. Upload the booking folder to:
   wp-content/plugins/

2. In that folder run:
   composer install --no-dev

   If Composer is unavailable on hosting, run the command locally and upload
   the complete vendor folder along with this plugin.

3. Activate "Booking" from WordPress > Plugins.

4. Open WordPress > Bookings > Settings.

5. Add Google OAuth Client ID and Client Secret.

6. In Google Cloud Console, configure this exact Authorized redirect URI:
   https://YOUR-DOMAIN/wp-admin/admin-post.php?action=ccb_google_callback

7. Save settings, then click "Connect Google Calendar".

8. Add [bookings] to any WordPress page.

CUSTOMIZATION (Bookings > Settings)
- General tab: brand logo + brand text, eyebrow, heading ({duration} placeholder
  is supported), description, timezone dropdown, slot duration (30 / 45 / 60
  minutes), working hours, booking window, notice, buffer, Google credentials.
- Style tab: color-only styling for every part of the widget. Colors left empty
  inherit the Accent color.

DEFAULT BOOKING RULES
- Monday to Friday
- 09:00 to 17:00
- 30-minute slots (changeable to 45 or 60)
- Asia/Karachi timezone
- 30-day booking window
- 120-minute minimum notice
- 0-minute buffer

IMPORTANT NOTES
- This plugin requires HTTPS and a working WordPress/PHP environment.
- Google Calendar API must be enabled in the Google Cloud project.
- OAuth consent screen and test-user/publishing settings must be configured.
- The connected Google account must permit Calendar event creation.
- Google Meet conference creation can be asynchronous; the initial response
  may not always contain the Meet URL immediately.
- Test the full flow with a non-production calendar before going live.
- Configure SPF/DKIM and reliable WordPress mail delivery for site notifications.
- The booking REST endpoint is public by design. Add CAPTCHA/rate limiting
  before exposing it to high-traffic or untrusted traffic.
- This starter plugin does not include rescheduling, recurring availability,
  holidays, CAPTCHA, payment collection, or a background Meet-link retry job.
- Review privacy/data-retention obligations before storing customer details.
