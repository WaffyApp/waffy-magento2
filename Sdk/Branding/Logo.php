<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Branding;

/**
 * The Waffy wordmark, so every platform integration renders the same logo from
 * the same source.
 *
 * Why the markup lives in a PHP class rather than as a file each plugin ships in
 * its own asset directory: the vendoring scripts (`dev/sync-sdk.sh` in
 * waffy-magento2 and waffy-woocommerce) mirror `*.php` from `src/` verbatim, so
 * a class travels to both platforms with no build step, no Magento static-content
 * deploy, and no URL for a template to resolve. `assets/waffy-logo.svg` in this
 * repo is the same artwork as a standalone file for design hand-off;
 * {@see \Waffy\Ecommerce\Tests\Unit\Branding\LogoTest} keeps the two identical.
 *
 * Inline the SVG with {@see self::svg()} where markup is allowed, or use
 * {@see self::img()} / {@see self::dataUri()} where an image source is expected
 * (a WooCommerce gateway icon, a JS-rendered label).
 */
class Logo
{
    /** Intrinsic size of the artwork — the viewBox it was drawn at. */
    public const WIDTH = 60;
    public const HEIGHT = 36;

    /** Brand blue used by the wordmark; exposed so callers can match it. */
    public const COLOR = '#0D44AD';

    private const MARKUP = <<<'SVG'
        <svg width="60" height="36" viewBox="0 0 60 36" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M18.9247 28.0419V27.5699C18.9217 25.0149 18.9247 22.4598 18.91 19.9062C18.9059 19.5126 18.8616 19.1204 18.7778 18.7359C18.4531 17.2258 17.6724 16.5319 16.1351 16.408C15.5926 16.3642 15.0433 16.4043 14.4978 16.3869C14.186 16.3771 14.0878 16.4926 14.0908 16.8082C14.1063 18.3206 14.0976 19.8333 14.0976 21.3461V28.066H13.6665C11.8997 28.0683 10.1329 28.066 8.36568 28.0739C6.97525 28.0796 5.63013 27.835 4.36693 27.2615C2.00551 26.1901 0.790637 24.2481 0.29457 21.7757C-0.141094 19.603 -0.0425589 17.4308 0.260594 15.2558C0.288531 15.0546 0.350444 14.8538 0.344781 14.6537C0.33421 14.2554 0.533543 14.1176 0.900875 14.0496C1.97003 13.8499 3.03238 13.6151 4.12381 13.3886C3.99582 14.0681 3.84406 14.7077 3.761 15.3544C3.51033 17.3039 3.41783 19.2553 3.94184 21.1792C4.36844 22.7478 5.35529 23.797 6.89484 24.3625C7.91907 24.74 8.95386 24.865 10.0872 24.7547V24.2764C10.0872 20.8572 10.0921 17.4375 10.0793 14.0183C10.0793 13.6706 10.1804 13.5539 10.5255 13.4849C12.3897 13.1122 14.2687 12.931 16.1672 12.9835C17.2768 13.0141 18.3705 13.1964 19.403 13.6121C21.4178 14.4234 22.4855 15.9818 22.7796 18.0899C22.8828 18.8695 22.9404 19.6545 22.9521 20.4408C22.98 21.7115 22.9597 22.983 22.9597 24.2538C22.9597 24.7804 22.9597 24.7906 23.501 24.791C25.4585 24.791 27.416 24.7857 29.3738 24.7823C29.8514 24.7823 30.3286 24.7823 30.7582 24.7823C30.5547 23.9668 30.2795 23.1827 30.1734 22.3763C29.8442 19.8767 30.2463 17.5289 31.9187 15.5431C33.2106 14.0077 34.9287 13.2402 36.8994 13.0564C39.0766 12.8536 41.2353 13.1036 43.3887 13.4199C43.6907 13.4645 43.6397 13.6464 43.6397 13.8254C43.6397 15.4397 43.6352 17.054 43.6344 18.6687C43.6344 21.2743 43.6344 23.88 43.6344 26.4857V28.0426L18.9247 28.0419ZM39.6448 20.5952H39.6553C39.6553 19.324 39.647 18.0525 39.6621 16.7822C39.6655 16.5088 39.5832 16.3861 39.3144 16.3639C38.8236 16.3239 38.3329 16.2438 37.8379 16.2291C36.4483 16.1872 35.3946 16.7859 34.7671 18.0216C33.969 19.5932 34.0268 21.2143 34.7056 22.8067C35.2806 24.156 36.3795 24.7581 37.8141 24.7925C38.297 24.8042 38.7814 24.7744 39.2635 24.7966C39.5655 24.811 39.655 24.7117 39.6512 24.4097C39.6376 23.1378 39.6448 21.8667 39.6448 20.5952Z" fill="#0D44AD"/>
        <path d="M59.6338 13.4453V27.5123C59.6338 31.7965 57.2071 34.7876 53.0098 35.6933C51.0844 36.1085 49.1499 36.0508 47.2117 35.8141C46.8229 35.7665 46.4336 35.7223 46.0131 35.6733C45.9424 34.4856 45.8729 33.3141 45.8047 32.1589C46.7662 32.2899 47.6783 32.456 48.5984 32.53C49.9533 32.6387 51.3166 32.6972 52.6379 32.2691C54.4251 31.6919 55.6774 29.9251 55.5694 28.0635C55.4184 28.0635 55.2583 28.0635 55.1005 28.0635C53.7414 28.0586 52.3857 28.1103 51.0459 27.7796C48.1061 27.0536 46.4956 25.2611 46.1343 22.2572C45.8964 20.2778 46.1282 18.3686 47.1294 16.6207C48.37 14.4556 50.3225 13.3272 52.7613 13.0572C55.0529 12.8039 57.3245 13.1041 59.6338 13.4453ZM55.5943 20.5945C55.5943 19.3109 55.5867 18.0274 55.6007 16.7438C55.6033 16.4863 55.5135 16.3663 55.2734 16.3436C54.7071 16.2889 54.1352 16.1775 53.5696 16.1971C52.3272 16.2405 51.3471 16.7944 50.751 17.9107C49.9601 19.3921 49.9922 20.9351 50.5222 22.4829C50.9821 23.8258 51.964 24.5797 53.3537 24.7571C53.9668 24.8356 54.5972 24.7669 55.2183 24.7949C55.5294 24.8092 55.6037 24.6982 55.6003 24.4045C55.5867 23.1357 55.5947 21.8649 55.5947 20.5945H55.5943Z" fill="#0D44AD"/>
        <path d="M25.0305 2.54037C25.7218 2.35916 26.4062 2.17945 27.0918 2.00239C27.1386 1.99031 27.1915 2.00239 27.2881 2.00239C27.2881 2.41767 27.2851 2.83295 27.2881 3.24823C27.2927 3.71032 27.4539 3.89719 27.8408 3.90135C28.2183 3.9055 28.4147 3.70994 28.4305 3.26673C28.4471 2.80954 28.4241 2.35085 28.4456 1.89442C28.4505 1.79174 28.5498 1.63431 28.6393 1.60486C29.2875 1.39194 29.9448 1.20506 30.6462 0.995911C30.6462 1.62185 30.6462 2.21343 30.6462 2.80501C30.6462 3.29164 30.8097 3.49702 31.1963 3.50985C31.6115 3.52382 31.8581 3.28334 31.8664 2.80954C31.8792 2.09942 31.8698 1.38854 31.8698 0.654254L34.0802 0C34.0802 0.458315 34.0862 0.882653 34.0802 1.30699C34.0677 2.03033 34.127 2.76613 34.0149 3.47436C33.6592 5.72328 31.5432 6.504 29.7647 5.05318C29.3698 5.59945 28.8964 6.09288 28.1908 6.12421C27.6857 6.14687 27.1382 6.09627 26.6709 5.91846C25.4567 5.45259 24.9456 4.34645 25.0305 2.54037Z" fill="#0D44AD"/>
        <path d="M40.6979 10.6207C39.4121 10.6283 38.3807 9.62179 38.375 8.35557C38.3697 7.10974 39.4245 6.047 40.6723 6.03719C41.9272 6.02699 42.9374 7.03234 42.9525 8.30234C42.9676 9.59574 41.9774 10.6135 40.6979 10.6207Z" fill="#0D44AD"/>
        <path d="M12.8707 32.6879C12.8753 33.9783 11.9077 35.0029 10.6898 34.9976C9.47187 34.9923 8.48049 33.9541 8.49031 32.6947C8.5005 31.4145 9.44243 30.4499 10.6852 30.4473C11.9424 30.4424 12.8666 31.3907 12.8707 32.6879Z" fill="#0D44AD"/>
        <path d="M7.68552 32.6946C7.68552 33.974 6.74397 34.9847 5.5374 34.9975C4.29157 35.0096 3.30434 33.9846 3.31453 32.6855C3.32472 31.3955 4.25457 30.4464 5.50908 30.4453C6.7636 30.4442 7.68439 31.3952 7.68552 32.6946Z" fill="#0D44AD"/>
        </svg>
        SVG;

    /**
     * The artwork exactly as authored, at its intrinsic 60×36 size.
     */
    public static function rawSvg(): string
    {
        return self::MARKUP;
    }

    /**
     * Inline `<svg>` scaled to $height pixels, width following the aspect ratio.
     *
     * Labelled for assistive tech; pass $cssClass to hook it up to a stylesheet.
     */
    public static function svg(int $height = self::HEIGHT, string $cssClass = ''): string
    {
        $attributes = sprintf(
            'width="%d" height="%d" viewBox="0 0 %d %d" fill="none" '
            . 'role="img" aria-label="Waffy" xmlns="http://www.w3.org/2000/svg"',
            self::scaledWidth($height),
            self::normalizeHeight($height),
            self::WIDTH,
            self::HEIGHT,
        );

        if ($cssClass !== '') {
            $attributes .= sprintf(' class="%s"', self::escape($cssClass));
        }

        // Swap the authored opening tag for one carrying our attributes. The
        // first ">" closes it — no attribute value in the artwork contains one.
        $openTagEnd = (int) strpos(self::MARKUP, '>');

        return '<svg ' . $attributes . '>' . substr(self::MARKUP, $openTagEnd + 1);
    }

    /**
     * The artwork as a base64 `data:` URI, for `<img src>`, CSS `url()`, or any
     * consumer that needs a source string rather than markup.
     */
    public static function dataUri(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::MARKUP);
    }

    /**
     * A complete `<img>` tag at $height pixels, self-contained (data URI), for
     * the places a platform expects an image rather than inline SVG.
     */
    public static function img(int $height = self::HEIGHT, string $cssClass = '', string $alt = 'Waffy'): string
    {
        return sprintf(
            '<img src="%s" alt="%s" width="%d" height="%d"%s />',
            self::dataUri(),
            self::escape($alt),
            self::scaledWidth($height),
            self::normalizeHeight($height),
            $cssClass === '' ? '' : sprintf(' class="%s"', self::escape($cssClass)),
        );
    }

    private static function normalizeHeight(int $height): int
    {
        return max(1, $height);
    }

    private static function scaledWidth(int $height): int
    {
        return (int) round(self::normalizeHeight($height) * self::WIDTH / self::HEIGHT);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
