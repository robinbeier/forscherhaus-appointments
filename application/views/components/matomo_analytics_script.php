<?php
/**
 * @var string $matomo_analytics_site_id
 */
?>

<?php
$matomo_analytics_url = defined('Config::MATOMO_ANALYTICS_URL') ? (string) Config::MATOMO_ANALYTICS_URL : '';
$matomo_analytics_url = trim($matomo_analytics_url);
$matomo_analytics_url_is_valid =
    $matomo_analytics_url !== '' &&
    filter_var($matomo_analytics_url, FILTER_VALIDATE_URL) !== false &&
    strtolower((string) parse_url($matomo_analytics_url, PHP_URL_SCHEME)) === 'https' &&
    (string) parse_url($matomo_analytics_url, PHP_URL_HOST) !== '' &&
    parse_url($matomo_analytics_url, PHP_URL_USER) === null &&
    parse_url($matomo_analytics_url, PHP_URL_PASS) === null &&
    parse_url($matomo_analytics_url, PHP_URL_QUERY) === null &&
    parse_url($matomo_analytics_url, PHP_URL_FRAGMENT) === null;
if ($matomo_analytics_url_is_valid && substr($matomo_analytics_url, -1) !== '/') {
    $matomo_analytics_url .= '/';
}
$matomo_analytics_url_js = $matomo_analytics_url_is_valid
    ? json_encode(
        $matomo_analytics_url,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
    )
    : '';
$matomo_analytics_site_id_js = json_encode(
    (string) $matomo_analytics_site_id,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
);
?>

<?php if ($matomo_analytics_url_is_valid): ?>

    <script>
        var _paq = window._paq = window._paq || [];
        /* tracker methods like "setCustomDimension" should be called before "trackPageView" */
        _paq.push(["setDocumentTitle", document.domain + "/" + document.title]);
        _paq.push(['trackPageView']);
        _paq.push(['enableLinkTracking']);
        (function () {
            var u = <?= $matomo_analytics_url_js ?>;
            _paq.push(['setTrackerUrl', u + 'matomo.php']);
            _paq.push(['setSiteId', <?= $matomo_analytics_site_id_js ?>]);
            var d = document, g = d.createElement('script'), s = d.getElementsByTagName('script')[0];
            g.async = true;
            g.src = u + 'matomo.js';
            s.parentNode.insertBefore(g, s);
        })();
    </script>

    <noscript>
        <p><img src="<?= e($matomo_analytics_url) ?>matomo.php?idsite=<?= e(
    $matomo_analytics_site_id,
) ?>&amp;rec=1" style="border:0;" alt=""/></p>
    </noscript>

<?php endif; ?>
