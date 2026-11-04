<?php
/**
 * Idempotent Loox-to-WooCommerce review migration.
 *
 * @package TrovesiaShopPlugin
 */

declare(strict_types=1);

namespace Trovesia\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class Review_Migrator
{
    private const SOURCE_URL = 'https://viorine.com/products/viorine-advanced-batana-hair-regrowth-therapy';

    private const SOURCE_PRODUCT_ID = '8449957462208';

    private const SOURCE_HANDLE = 'viorine-advanced-batana-hair-regrowth-therapy';

    private const LOOX_CLIENT_ID = 'qXCmzLaHKk';

    private const PAGE_SIZE = 20;

    public static function register(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('trovesia migrate-reviews', [self::class, 'run']);
        }
    }

    /**
     * Import the public Loox reviews for the migrated Batana product.
     *
     * The command intentionally imports only public storefront fields. It
     * never requests or fabricates customer email addresses, order data, or
     * other private customer information.
     *
     * ## EXAMPLES
     *
     *     wp trovesia migrate-reviews
     *     wp trovesia migrate-reviews --skip-media
     *
     * @param array<int, string>   $args Positional arguments.
     * @param array<string, mixed> $assoc_args Named arguments.
     */
    public static function run(array $args, array $assoc_args): void
    {
        unset($args);

        if (! class_exists('WooCommerce')) {
            \WP_CLI::error('WooCommerce must be active before importing reviews.');
        }

        if (! class_exists('DOMDocument') || ! class_exists('DOMXPath')) {
            \WP_CLI::error('The PHP DOM extension is required to parse the public Loox review feed.');
        }

        $product_id = self::find_product_id();
        if ($product_id === 0) {
            \WP_CLI::error('The migrated Batana product could not be found. Run migrate-catalog first.');
        }

        $source_html = self::fetch(self::SOURCE_URL);
        $hash = self::extract_hash($source_html);
        $source_dates = self::extract_source_dates($source_html);
        $first_html = self::fetch(self::feed_url($hash, 1));
        $total = self::extract_total($first_html);

        if ($total < 1) {
            \WP_CLI::error('Loox returned no public product reviews.');
        }

        $existing = self::existing_source_ids($product_id);
        $skip_media = array_key_exists('skip-media', $assoc_args);
        $inserted = 0;
        $skipped = 0;
        $media_synced = 0;
        $position = 0;
        $page_count = (int) ceil($total / self::PAGE_SIZE);

        wp_update_post([
            'ID'             => $product_id,
            'comment_status' => 'open',
        ]);

        for ($page = 1; $page <= $page_count; $page++) {
            $html = $page === 1 ? $first_html : self::fetch(self::feed_url($hash, $page, $total));
            $reviews = self::parse_reviews($html);

            if ($reviews === []) {
                \WP_CLI::warning(sprintf('No reviews returned on page %d; stopping early.', $page));
                break;
            }

            foreach ($reviews as $review) {
                $position++;
                $source_id = (string) $review['source_id'];

                if (isset($existing[$source_id])) {
                    if (! $skip_media && self::sync_review_media((int) $existing[$source_id], $product_id, (string) $review['media_url'])) {
                        $media_synced++;
                    }
                    $skipped++;
                    continue;
                }

                $source_date = $source_dates[$source_id] ?? '';
                $date_known = $source_date !== '';
                $timestamp = $date_known ? strtotime($source_date) : (time() - $position);
                if ($timestamp === false) {
                    $timestamp = time() - $position;
                    $date_known = false;
                }

                $comment_id = wp_insert_comment([
                    'comment_approved'   => 1,
                    'comment_author'     => (string) $review['author'],
                    'comment_author_IP'  => '',
                    'comment_author_url' => '',
                    'comment_content'    => (string) $review['content'],
                    'comment_date'       => get_date_from_gmt(gmdate('Y-m-d H:i:s', $timestamp)),
                    'comment_date_gmt'   => gmdate('Y-m-d H:i:s', $timestamp),
                    'comment_post_ID'    => $product_id,
                    'comment_type'       => 'review',
                    'comment_agent'      => 'Trovesia Loox migration',
                ]);

                if (! $comment_id) {
                    \WP_CLI::warning(sprintf('Review %s could not be inserted.', $source_id));
                    continue;
                }

                add_comment_meta($comment_id, 'rating', (int) $review['rating'], true);
                add_comment_meta($comment_id, '_trovesia_loox_review_id', $source_id, true);
                add_comment_meta($comment_id, '_trovesia_loox_verified', $review['verified'] ? '1' : '0', true);
                add_comment_meta($comment_id, '_trovesia_loox_media_url', (string) $review['media_url'], true);
                add_comment_meta($comment_id, '_trovesia_source_date_known', $date_known ? '1' : '0', true);
                add_comment_meta($comment_id, '_trovesia_source_url', self::SOURCE_URL, true);
                add_comment_meta($comment_id, '_trovesia_source_position', $position, true);

                if (! $skip_media && self::sync_review_media($comment_id, $product_id, (string) $review['media_url'])) {
                    $media_synced++;
                }

                $existing[$source_id] = $comment_id;
                $inserted++;
            }

            \WP_CLI::log(sprintf('Processed Loox page %d/%d.', $page, $page_count));
        }

        wp_update_comment_count_now($product_id);
        update_option('page_comments', 1);
        update_option('comments_per_page', 20);
        if (class_exists('WC_Comments')) {
            \WC_Comments::clear_transients($product_id);
        }
        wc_delete_product_transients($product_id);

        \WP_CLI::success(
            sprintf(
                'Review migration complete: %d inserted, %d already present, %d review photos available locally, %d public source reviews reported.',
                $inserted,
                $skipped,
                $media_synced,
                $total
            )
        );
    }

    private static function find_product_id(): int
    {
        $matches = get_posts([
            'fields'         => 'ids',
            'meta_key'       => '_trovesia_source_handle',
            'meta_value'     => self::SOURCE_HANDLE,
            'post_type'      => 'product',
            'posts_per_page' => 1,
        ]);

        return $matches === [] ? 0 : (int) $matches[0];
    }

    private static function extract_hash(string $html): string
    {
        if (! preg_match("/loox_global_hash\\s*=\\s*['\"]([0-9]+)['\"]/", $html, $matches)) {
            \WP_CLI::error('The Loox public widget hash could not be found on the source product page.');
        }

        return (string) $matches[1];
    }

    /**
     * @return array<string, string>
     */
    private static function extract_source_dates(string $html): array
    {
        $xpath = self::xpath($html);
        $dates = [];
        $articles = $xpath->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' loox-review ')][@data-review-id]");

        if (! $articles) {
            return $dates;
        }

        foreach ($articles as $article) {
            if (! $article instanceof \DOMElement) {
                continue;
            }

            $time = $xpath->query(".//time[@datetime]", $article)?->item(0);
            if ($time instanceof \DOMElement) {
                $dates[$article->getAttribute('data-review-id')] = $time->getAttribute('datetime');
            }
        }

        return $dates;
    }

    private static function extract_total(string $html): int
    {
        $xpath = self::xpath($html);
        $node = $xpath->query("//button[@data-tab='product']//span[contains(concat(' ', normalize-space(@class), ' '), ' tabs__badge ')]")?->item(0);

        return $node ? (int) preg_replace('/[^0-9]/', '', $node->textContent) : 0;
    }

    /**
     * @return array<int, array{source_id: string, author: string, content: string, rating: int, verified: bool, media_url: string}>
     */
    private static function parse_reviews(string $html): array
    {
        $xpath = self::xpath($html);
        $nodes = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' grid-item-wrap ')][@data-id]");
        $reviews = [];

        if (! $nodes) {
            return $reviews;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $source_id = trim($node->getAttribute('data-id'));
            $title = $xpath->query(".//*[@data-testid and contains(@data-testid, '-title')]", $node)?->item(0);
            $content = $xpath->query(".//*[@data-testid and contains(@data-testid, '-text')]", $node)?->item(0);
            $stars = $xpath->query(".//*[@aria-label and contains(@aria-label, '/ 5 star review')]", $node)?->item(0);

            if ($source_id === '' || ! $title || ! $content || ! $stars) {
                continue;
            }

            $author = trim((string) ($title->firstChild?->nodeValue ?? $title->textContent));
            $review_text = trim($content->textContent);
            preg_match('/([1-5])\\s*\\/\\s*5/', (string) $stars->attributes?->getNamedItem('aria-label')?->nodeValue, $rating_match);
            $rating = isset($rating_match[1]) ? (int) $rating_match[1] : 0;

            if ($author === '' || $review_text === '' || $rating < 1) {
                continue;
            }

            $verified = (bool) $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' verified-badge-and-text ')]", $node)?->length;
            $media = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' item-img ')]//img[@src]", $node)?->item(0);
            $media_url = $media instanceof \DOMElement ? trim($media->getAttribute('src')) : '';
            if (str_starts_with($media_url, '//')) {
                $media_url = 'https:' . $media_url;
            }

            $reviews[] = [
                'source_id' => $source_id,
                'author'     => $author,
                'content'    => $review_text,
                'rating'     => $rating,
                'verified'   => $verified,
                'media_url'  => esc_url_raw($media_url),
            ];
        }

        return $reviews;
    }

    /**
     * @return array<string, int>
     */
    private static function existing_source_ids(int $product_id): array
    {
        $ids = [];
        $comments = get_comments([
            'post_id' => $product_id,
            'status'  => 'all',
            'type'    => 'review',
        ]);

        foreach ($comments as $comment) {
            $source_id = (string) get_comment_meta($comment->comment_ID, '_trovesia_loox_review_id', true);
            if ($source_id !== '') {
                $ids[$source_id] = (int) $comment->comment_ID;
            }
        }

        return $ids;
    }

    private static function sync_review_media(int $comment_id, int $product_id, string $source_url): bool
    {
        if ($source_url === '') {
            return false;
        }

        $attachment_id = (int) get_comment_meta($comment_id, '_trovesia_loox_media_attachment_id', true);
        if ($attachment_id > 0 && get_post_type($attachment_id) === 'attachment') {
            return true;
        }

        $matches = get_posts([
            'fields'         => 'ids',
            'meta_key'       => '_trovesia_loox_media_url',
            'meta_value'     => $source_url,
            'post_type'      => 'attachment',
            'posts_per_page' => 1,
        ]);

        if ($matches !== []) {
            update_comment_meta($comment_id, '_trovesia_loox_media_attachment_id', (int) $matches[0]);
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $temporary_file = download_url($source_url, 120);
        if (is_wp_error($temporary_file)) {
            \WP_CLI::warning(sprintf('Review photo skipped: %s', $temporary_file->get_error_message()));
            return false;
        }

        $path = (string) wp_parse_url($source_url, PHP_URL_PATH);
        $filename = sanitize_file_name((string) wp_basename($path));
        if ($filename === '') {
            $filename = sprintf('loox-review-%d.jpg', $comment_id);
        }

        $attachment_id = media_handle_sideload(
            [
                'name'     => $filename,
                'tmp_name' => $temporary_file,
            ],
            $product_id,
            __('Imported customer review photo', 'trovesia-plugin')
        );

        if (is_wp_error($attachment_id)) {
            @unlink($temporary_file); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            \WP_CLI::warning(sprintf('Review photo skipped: %s', $attachment_id->get_error_message()));
            return false;
        }

        update_post_meta((int) $attachment_id, '_trovesia_loox_media_url', $source_url);
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', __('Customer review photo', 'trovesia-plugin'));
        update_comment_meta($comment_id, '_trovesia_loox_media_attachment_id', (int) $attachment_id);

        return true;
    }

    private static function feed_url(string $hash, int $page, int $total = 0): string
    {
        $args = [
            'h'          => $hash,
            'language'   => 'en',
            'page'       => $page,
            'productIds' => self::SOURCE_PRODUCT_ID,
            'variant'    => 'visible',
        ];

        if ($total > 0) {
            $args['total'] = $total;
        }

        return add_query_arg(
            $args,
            sprintf('https://loox.io/widget/%s/reviews', self::LOOX_CLIENT_ID)
        );
    }

    private static function fetch(string $url): string
    {
        $response = wp_remote_get($url, [
            'redirection' => 5,
            'timeout'     => 60,
            'user-agent'  => 'Trovesia review migration/2.1',
        ]);

        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status !== 200 || $body === '') {
            \WP_CLI::error(sprintf('Review source returned HTTP %d for %s.', $status, $url));
        }

        return $body;
    }

    private static function xpath(string $html): \DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}
