<?php
/**
 * includes/analytics.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Single-source analytics partial.  Include once, just before </head>.
 *
 * Requires config.php to have been loaded first so GA_TAG_ID is defined.
 *
 * Behaviour
 * ─────────
 * - Both Google Analytics (gtag) and Microsoft Clarity are deferred to the
 *   window 'load' event so they never block first paint.
 * - Both respect the visitor's cookie-consent choice stored in localStorage
 *   under the key 'ir_cookie_consent'.  The value 'declined' suppresses all
 *   optional tracking.  Any other value (including absent / null) allows it,
 *   so users who have not yet seen the banner on this tab are tracked — the
 *   parent page shows the banner and the choice propagates via shared
 *   localStorage (same origin, including new tabs opened from the site).
 * - The GA tag ID is injected server-side from GA_TAG_ID (config.php reads
 *   this from the GOOGLE_TAG_ID environment variable).  If the constant is
 *   empty the GA block is skipped entirely, preventing broken gtag calls.
 * - The Clarity project ID is hardcoded here because it is a public,
 *   client-side identifier with no security implication.
 *
 * GDPR / EU Cookie Law compliance notes
 * ──────────────────────────────────────
 * - Both trackers are classified as optional / analytics cookies and are
 *   disclosed by name in the cookie banner (index.php) and in Terms §08.
 * - Loading is gated on consent; no tracking fires on 'declined'.
 * - Microsoft Clarity performs session recording and heatmap collection;
 *   this is disclosed in the banner message and in Terms §08.
 */

if (!defined('GA_TAG_ID')) {
    // Fallback: if this partial is somehow included before config.php.
    // Define as empty string so the JS block can skip gracefully.
    define('GA_TAG_ID', '');
}
$_ir_ga_id = GA_TAG_ID;
?>
<script>
(function() {
  /* ── Analytics loader — deferred, consent-aware ─────────────────────── */
  window.addEventListener('load', function() {
    var consent = '';
    try { consent = localStorage.getItem('ir_cookie_consent') || ''; } catch(e) {}
    if (consent === 'declined') return;

    /* Google Analytics / Google Ads ──────────────────────────────────── */
    var gaId = '<?php echo htmlspecialchars($_ir_ga_id, ENT_QUOTES, 'UTF-8'); ?>';
    if (gaId) {
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://www.googletagmanager.com/gtag/js?id=' + gaId;
      document.head.appendChild(s);
      window.dataLayer = window.dataLayer || [];
      function gtag() { dataLayer.push(arguments); }
      window.gtag = gtag;
      gtag('js', new Date());
      gtag('config', gaId);
    }

    /* Microsoft Clarity ──────────────────────────────────────────────── */
    (function(c, l, a, r, i, t, y) {
      c[a] = c[a] || function() { (c[a].q = c[a].q || []).push(arguments); };
      t = l.createElement(r); t.async = 1;
      t.src = 'https://www.clarity.ms/tag/' + i;
      y = l.getElementsByTagName(r)[0];
      y.parentNode.insertBefore(t, y);
    })(window, document, 'clarity', 'script', 'wirkgcoxjj');
  });
})();
</script>
