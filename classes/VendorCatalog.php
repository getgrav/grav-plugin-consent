<?php

declare(strict_types=1);

namespace Grav\Plugin\Consent;

/**
 * Known third-party hosts, so the auto-blocker can describe what it found.
 *
 * Detection alone is not much use — a banner that says "an unknown script from
 * googletagmanager.com" helps nobody. Matching a host to a real name, provider,
 * category and cookie list is what turns a blocked request into an entry the
 * visitor can make sense of.
 *
 * Hosts match on suffix, so `www.google-analytics.com` matches the
 * `google-analytics.com` entry. Order matters only where one vendor's domain
 * is a suffix of another's, which is why the more specific entries come first.
 */
final class VendorCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            // ── strictly necessary ──────────────────────────────────────────
            // Catalogued so auto-blocking never breaks a checkout or a captcha.
            'stripe' => [
                'hosts' => ['js.stripe.com', 'stripe.com', 'stripe.network'],
                'name' => 'Stripe',
                'provider' => 'Stripe, Inc.',
                'category' => 'necessary',
                'privacy_url' => 'https://stripe.com/privacy',
                'cookies' => [
                    ['name' => '__stripe_mid', 'duration' => '1 year'],
                    ['name' => '__stripe_sid', 'duration' => '30 minutes'],
                ],
            ],
            'turnstile' => [
                'hosts' => ['challenges.cloudflare.com'],
                'name' => 'Cloudflare Turnstile',
                'provider' => 'Cloudflare, Inc.',
                'category' => 'necessary',
                'privacy_url' => 'https://www.cloudflare.com/privacypolicy/',
                'cookies' => [['name' => 'cf_clearance', 'duration' => '1 year']],
            ],
            'paypal' => [
                'hosts' => ['paypal.com', 'paypalobjects.com'],
                'name' => 'PayPal',
                'provider' => 'PayPal, Inc.',
                'category' => 'necessary',
                'privacy_url' => 'https://www.paypal.com/privacy',
                'cookies' => [['name' => 'ts', 'duration' => '3 years']],
            ],
            'recaptcha' => [
                'hosts' => ['recaptcha.net', 'www.recaptcha.net'],
                'name' => 'Google reCAPTCHA',
                'provider' => 'Google LLC',
                'category' => 'necessary',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [['name' => '_GRECAPTCHA', 'duration' => '6 months']],
            ],

            // ── analytics ───────────────────────────────────────────────────
            'google-tag-manager' => [
                'hosts' => ['googletagmanager.com'],
                'name' => 'Google Tag Manager',
                'provider' => 'Google LLC',
                'category' => 'analytics',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [
                    ['name' => '_ga', 'duration' => '2 years'],
                    ['name' => '_ga_*', 'duration' => '2 years'],
                    ['name' => '_gid', 'duration' => '24 hours'],
                ],
            ],
            'google-analytics' => [
                'hosts' => ['google-analytics.com', 'analytics.google.com'],
                'name' => 'Google Analytics',
                'provider' => 'Google LLC',
                'category' => 'analytics',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [
                    ['name' => '_ga', 'duration' => '2 years'],
                    ['name' => '_ga_*', 'duration' => '2 years'],
                    ['name' => '_gid', 'duration' => '24 hours'],
                    ['name' => '_gat', 'duration' => '1 minute'],
                ],
            ],
            'matomo' => [
                'hosts' => ['matomo.cloud', 'matomo.org'],
                'name' => 'Matomo',
                'provider' => 'InnoCraft Ltd',
                'category' => 'analytics',
                'privacy_url' => 'https://matomo.org/privacy-policy/',
                'cookies' => [
                    ['name' => '_pk_id*', 'duration' => '13 months'],
                    ['name' => '_pk_ses*', 'duration' => '30 minutes'],
                ],
            ],
            'hotjar' => [
                'hosts' => ['hotjar.com', 'hotjar.io'],
                'name' => 'Hotjar',
                'provider' => 'Hotjar Ltd',
                'category' => 'analytics',
                'privacy_url' => 'https://www.hotjar.com/privacy/',
                'cookies' => [
                    ['name' => '_hj*', 'duration' => '1 year'],
                ],
            ],
            'clarity' => [
                'hosts' => ['clarity.ms'],
                'name' => 'Microsoft Clarity',
                'provider' => 'Microsoft Corporation',
                'category' => 'analytics',
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'cookies' => [['name' => '_clck', 'duration' => '1 year'], ['name' => '_clsk', 'duration' => '1 day']],
            ],
            'plausible' => [
                'hosts' => ['plausible.io'],
                'name' => 'Plausible Analytics',
                'provider' => 'Plausible Insights OÜ',
                'category' => 'analytics',
                'privacy_url' => 'https://plausible.io/privacy',
                'cookies' => [],
            ],
            'fathom' => [
                'hosts' => ['usefathom.com', 'cdn.usefathom.com'],
                'name' => 'Fathom Analytics',
                'provider' => 'Conva Ventures Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://usefathom.com/privacy',
                'cookies' => [],
            ],
            'segment' => [
                'hosts' => ['segment.com', 'segment.io'],
                'name' => 'Segment',
                'provider' => 'Twilio Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://segment.com/legal/privacy/',
                'cookies' => [['name' => 'ajs_*', 'duration' => '1 year']],
            ],
            'mixpanel' => [
                'hosts' => ['mixpanel.com', 'mxpnl.com'],
                'name' => 'Mixpanel',
                'provider' => 'Mixpanel, Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://mixpanel.com/legal/privacy-policy/',
                'cookies' => [['name' => 'mp_*', 'duration' => '1 year']],
            ],
            'amplitude' => [
                'hosts' => ['amplitude.com'],
                'name' => 'Amplitude',
                'provider' => 'Amplitude, Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://amplitude.com/privacy',
                'cookies' => [['name' => 'amp_*', 'duration' => '1 year']],
            ],
            'sentry' => [
                'hosts' => ['sentry.io', 'sentry-cdn.com'],
                'name' => 'Sentry',
                'provider' => 'Functional Software, Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://sentry.io/privacy/',
                'cookies' => [],
            ],
            'cloudflare-insights' => [
                'hosts' => ['static.cloudflareinsights.com'],
                'name' => 'Cloudflare Web Analytics',
                'provider' => 'Cloudflare, Inc.',
                'category' => 'analytics',
                'privacy_url' => 'https://www.cloudflare.com/privacypolicy/',
                'cookies' => [],
            ],

            // ── marketing ───────────────────────────────────────────────────
            'google-ads' => [
                'hosts' => ['googleadservices.com', 'googlesyndication.com', 'doubleclick.net', 'g.doubleclick.net'],
                'name' => 'Google Ads',
                'provider' => 'Google LLC',
                'category' => 'marketing',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [
                    ['name' => 'IDE', 'duration' => '13 months'],
                    ['name' => '_gcl_*', 'duration' => '90 days'],
                ],
            ],
            'meta-pixel' => [
                'hosts' => ['connect.facebook.net'],
                'name' => 'Meta Pixel',
                'provider' => 'Meta Platforms, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.facebook.com/privacy/policy/',
                'cookies' => [
                    ['name' => '_fbp', 'duration' => '90 days'],
                    ['name' => 'fr', 'duration' => '90 days'],
                ],
            ],
            'linkedin' => [
                'hosts' => ['linkedin.com', 'licdn.com', 'ads.linkedin.com'],
                'name' => 'LinkedIn Insight',
                'provider' => 'LinkedIn Corporation',
                'category' => 'marketing',
                'privacy_url' => 'https://www.linkedin.com/legal/privacy-policy',
                'cookies' => [['name' => 'li_sugr', 'duration' => '90 days'], ['name' => 'bcookie', 'duration' => '1 year']],
            ],
            'x-twitter' => [
                'hosts' => ['ads-twitter.com', 'static.ads-twitter.com', 'platform.twitter.com', 'platform.x.com'],
                'name' => 'X (Twitter)',
                'provider' => 'X Corp.',
                'category' => 'marketing',
                'privacy_url' => 'https://x.com/en/privacy',
                'cookies' => [['name' => 'personalization_id', 'duration' => '2 years']],
            ],
            'tiktok' => [
                'hosts' => ['tiktok.com', 'analytics.tiktok.com'],
                'name' => 'TikTok Pixel',
                'provider' => 'TikTok Ltd.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.tiktok.com/legal/privacy-policy',
                'cookies' => [['name' => '_ttp', 'duration' => '13 months']],
            ],
            'pinterest' => [
                'hosts' => ['pinterest.com', 'ct.pinterest.com'],
                'name' => 'Pinterest Tag',
                'provider' => 'Pinterest, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://policy.pinterest.com/privacy-policy',
                'cookies' => [['name' => '_pinterest_ct_ua', 'duration' => '1 year']],
            ],
            'snapchat' => [
                'hosts' => ['sc-static.net', 'snapchat.com'],
                'name' => 'Snap Pixel',
                'provider' => 'Snap Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://snap.com/privacy/privacy-policy',
                'cookies' => [['name' => '_scid', 'duration' => '13 months']],
            ],
            'reddit' => [
                'hosts' => ['redditstatic.com', 'reddit.com'],
                'name' => 'Reddit Pixel',
                'provider' => 'Reddit, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.reddit.com/policies/privacy-policy',
                'cookies' => [['name' => '_rdt_uuid', 'duration' => '90 days']],
            ],
            'bing-uet' => [
                'hosts' => ['bat.bing.com', 'clarity.microsoft.com'],
                'name' => 'Microsoft Advertising UET',
                'provider' => 'Microsoft Corporation',
                'category' => 'marketing',
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'cookies' => [['name' => '_uetsid', 'duration' => '1 day'], ['name' => '_uetvid', 'duration' => '13 months']],
            ],
            'hubspot' => [
                'hosts' => ['hs-scripts.com', 'hubspot.com', 'hsforms.net', 'hs-analytics.net'],
                'name' => 'HubSpot',
                'provider' => 'HubSpot, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://legal.hubspot.com/privacy-policy',
                'cookies' => [['name' => 'hubspotutk', 'duration' => '6 months'], ['name' => '__hs*', 'duration' => '30 minutes']],
            ],
            'mailchimp' => [
                'hosts' => ['chimpstatic.com', 'list-manage.com', 'mailchimp.com'],
                'name' => 'Mailchimp',
                'provider' => 'Intuit Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.intuit.com/privacy/statement/',
                'cookies' => [['name' => '_mcid', 'duration' => '1 year']],
            ],
            'yandex' => [
                'hosts' => ['mc.yandex.ru', 'yandex.ru'],
                'name' => 'Yandex Metrica',
                'provider' => 'Yandex LLC',
                'category' => 'marketing',
                'privacy_url' => 'https://yandex.com/legal/confidential/',
                'cookies' => [['name' => '_ym_*', 'duration' => '1 year']],
            ],
            'baidu' => [
                'hosts' => ['hm.baidu.com'],
                'name' => 'Baidu Analytics',
                'provider' => 'Baidu, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://privacy.baidu.com/',
                'cookies' => [['name' => 'Hm_*', 'duration' => '1 year']],
            ],
            'adobe' => [
                'hosts' => ['omtrdc.net', 'demdex.net', 'adobedtm.com'],
                'name' => 'Adobe Experience Cloud',
                'provider' => 'Adobe Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.adobe.com/privacy/policy.html',
                'cookies' => [['name' => 'demdex', 'duration' => '180 days']],
            ],

            // ── embeds and widgets ──────────────────────────────────────────
            'youtube' => [
                'hosts' => ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'youtu.be', 'ytimg.com'],
                'name' => 'YouTube',
                'provider' => 'Google LLC',
                'category' => 'marketing',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [
                    ['name' => 'VISITOR_INFO1_LIVE', 'duration' => '6 months'],
                    ['name' => 'YSC', 'duration' => 'session'],
                ],
            ],
            'vimeo' => [
                'hosts' => ['vimeo.com', 'player.vimeo.com', 'vimeocdn.com'],
                'name' => 'Vimeo',
                'provider' => 'Vimeo.com, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://vimeo.com/privacy',
                'cookies' => [['name' => 'vuid', 'duration' => '2 years']],
            ],
            'google-maps' => [
                'hosts' => ['maps.googleapis.com', 'maps.google.com', 'maps.gstatic.com'],
                'name' => 'Google Maps',
                'provider' => 'Google LLC',
                'category' => 'functional',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [['name' => 'NID', 'duration' => '6 months']],
            ],
            'facebook-embed' => [
                'hosts' => ['facebook.com', 'www.facebook.com', 'fbcdn.net'],
                'name' => 'Facebook embeds',
                'provider' => 'Meta Platforms, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.facebook.com/privacy/policy/',
                'cookies' => [['name' => 'fr', 'duration' => '90 days']],
            ],
            'instagram' => [
                'hosts' => ['instagram.com', 'cdninstagram.com'],
                'name' => 'Instagram embeds',
                'provider' => 'Meta Platforms, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://privacycenter.instagram.com/policy',
                'cookies' => [['name' => 'ig_did', 'duration' => '2 years']],
            ],
            'twitch' => [
                'hosts' => ['twitch.tv', 'player.twitch.tv', 'jtvnw.net'],
                'name' => 'Twitch',
                'provider' => 'Twitch Interactive, Inc.',
                'category' => 'marketing',
                'privacy_url' => 'https://www.twitch.tv/p/legal/privacy-notice/',
                'cookies' => [['name' => 'unique_id', 'duration' => '1 year']],
            ],
            'soundcloud' => [
                'hosts' => ['soundcloud.com', 'w.soundcloud.com', 'sndcdn.com'],
                'name' => 'SoundCloud',
                'provider' => 'SoundCloud Global Limited',
                'category' => 'marketing',
                'privacy_url' => 'https://soundcloud.com/pages/privacy',
                'cookies' => [['name' => 'sc_anonymous_id', 'duration' => '10 years']],
            ],
            'spotify' => [
                'hosts' => ['spotify.com', 'open.spotify.com', 'scdn.co'],
                'name' => 'Spotify',
                'provider' => 'Spotify AB',
                'category' => 'marketing',
                'privacy_url' => 'https://www.spotify.com/legal/privacy-policy/',
                'cookies' => [['name' => 'sp_t', 'duration' => '1 year']],
            ],
            'disqus' => [
                'hosts' => ['disqus.com', 'disquscdn.com'],
                'name' => 'Disqus',
                'provider' => 'Zeta Global',
                'category' => 'functional',
                'privacy_url' => 'https://disqus.com/privacy-policy/',
                'cookies' => [['name' => 'disqus_unique', 'duration' => '1 year']],
            ],
            'intercom' => [
                'hosts' => ['intercom.io', 'intercomcdn.com', 'widget.intercom.io'],
                'name' => 'Intercom',
                'provider' => 'Intercom, Inc.',
                'category' => 'functional',
                'privacy_url' => 'https://www.intercom.com/legal/privacy',
                'cookies' => [['name' => 'intercom-*', 'duration' => '9 months']],
            ],
            'crisp' => [
                'hosts' => ['crisp.chat'],
                'name' => 'Crisp Chat',
                'provider' => 'Crisp IM SAS',
                'category' => 'functional',
                'privacy_url' => 'https://crisp.chat/en/privacy/',
                'cookies' => [['name' => 'crisp-client*', 'duration' => '6 months']],
            ],
            'google-fonts' => [
                'hosts' => ['fonts.googleapis.com', 'fonts.gstatic.com'],
                'name' => 'Google Fonts',
                'provider' => 'Google LLC',
                'category' => 'functional',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [],
                'description' => 'PLUGIN_CONSENT.VENDOR.GOOGLE_FONTS_DESC',
            ],
            'gstatic-recaptcha' => [
                'hosts' => ['www.google.com/recaptcha', 'www.gstatic.com/recaptcha'],
                'name' => 'Google reCAPTCHA',
                'provider' => 'Google LLC',
                'category' => 'necessary',
                'privacy_url' => 'https://policies.google.com/privacy',
                'cookies' => [['name' => '_GRECAPTCHA', 'duration' => '6 months']],
            ],
        ];
    }

    /**
     * Find the vendor owning a URL, if any.
     *
     * @return array{0: string, 1: array<string, mixed>}|null [id, definition]
     */
    public static function match(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        // Protocol-relative and absolute only. A same-origin path cannot be a
        // third party, and blocking the site's own scripts would be a bug.
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $path = (string)($parts['path'] ?? '');

        foreach (self::all() as $id => $vendor) {
            foreach ($vendor['hosts'] as $pattern) {
                $pattern = strtolower($pattern);

                // A pattern carrying a path (`www.google.com/recaptcha`) has to
                // match the path too, so matching reCAPTCHA does not swallow
                // every google.com URL on the page.
                if (str_contains($pattern, '/')) {
                    [$patternHost, $patternPath] = explode('/', $pattern, 2);
                    if (self::hostMatches($host, $patternHost) && str_starts_with(ltrim($path, '/'), $patternPath)) {
                        return [$id, $vendor];
                    }
                    continue;
                }

                if (self::hostMatches($host, $pattern)) {
                    return [$id, $vendor];
                }
            }
        }

        return null;
    }

    private static function hostMatches(string $host, string $pattern): bool
    {
        return $host === $pattern || str_ends_with($host, '.' . $pattern);
    }
}
