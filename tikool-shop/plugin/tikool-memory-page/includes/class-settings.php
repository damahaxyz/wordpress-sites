<?php
/**
 * Git publishing settings.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

if (! defined('ABSPATH')) {
    exit;
}

final class Settings
{
    private const OPTION = 'tikool_memory_page_settings';

    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('option_page_capability_tikool_memory_page', static fn (): string => 'manage_woocommerce');
    }

    public function admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Memory Pages', 'tikool-memory-page'),
            __('Memory Page Settings', 'tikool-memory-page'),
            'manage_woocommerce',
            'tikool-memory-pages',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('tikool_memory_page', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize'],
            'default'           => [],
        ]);

        add_settings_section(
            'tikool_memory_page_git',
            __('Git publishing', 'tikool-memory-page'),
            static function (): void {
                $available = file_exists(Git_Publisher::socket_path());
                echo '<p>' . esc_html__('Pages are committed and pushed through the host-side publisher, keeping SSH credentials outside WordPress.', 'tikool-memory-page') . '</p>';
                echo '<p><strong>' . esc_html__('Publisher:', 'tikool-memory-page') . '</strong> ';
                echo $available
                    ? '<span style="color:#18733a">' . esc_html__('socket available', 'tikool-memory-page') . '</span>'
                    : '<span style="color:#a13d32">' . esc_html__('socket unavailable', 'tikool-memory-page') . '</span>';
                echo '</p>';
                $bucket_git_url = self::get('bucket_git_url');
                echo '<p><strong>' . esc_html__('Current bucket repository:', 'tikool-memory-page') . '</strong> <code>' . esc_html($bucket_git_url) . '</code></p>';
            },
            'tikool-memory-pages'
        );

        $fields = [
            'branch'      => __('Branch', 'tikool-memory-page'),
            'base_path'   => __('Public HTML directory', 'tikool-memory-page'),
            'bucket_git_url' => __('Current bucket Git URL', 'tikool-memory-page'),
            'public_url'  => __('Public base URL', 'tikool-memory-page'),
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                'tikool_memory_page_' . $key,
                $label,
                [$this, 'render_field'],
                'tikool-memory-pages',
                'tikool_memory_page_git',
                ['key' => $key]
            );
        }
    }

    /**
     * @param mixed $input Raw option input.
     * @return array<string, string>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $branch = isset($input['branch']) ? sanitize_text_field((string) $input['branch']) : 'main';
        $base_path = isset($input['base_path']) ? trim(sanitize_text_field((string) $input['base_path']), '/') : 'public';
        $bucket_git_url = isset($input['bucket_git_url']) ? trim(sanitize_text_field((string) $input['bucket_git_url'])) : self::default_bucket_git_url();
        $public_url = isset($input['public_url']) ? untrailingslashit(esc_url_raw((string) $input['public_url'])) : '';
        $base_path = preg_replace('#[^A-Za-z0-9_./-]#', '', $base_path) ?: 'public';
        if (! preg_match('#^[A-Za-z0-9_.-]+$#', $base_path) || self::is_valid_bucket(strtolower($base_path))) {
            $base_path = 'public';
        }
        if (! self::is_valid_bucket_git_url($bucket_git_url)) {
            add_settings_error(self::OPTION, 'tikool_memory_bucket_git_url', __('Use a GitHub SSH URL ending in tikool-memory-pages-bucketN.git.', 'tikool-memory-page'));
            $bucket_git_url = self::get('bucket_git_url');
        }

        return [
            'branch'     => $branch !== '' ? $branch : 'main',
            'base_path'  => $base_path,
            'bucket_git_url' => $bucket_git_url,
            'public_url' => $public_url,
        ];
    }

    /**
     * @param array{key:string} $args Field arguments.
     */
    public function render_field(array $args): void
    {
        $key = $args['key'];
        $value = self::get($key);
        $type = $key === 'public_url' ? 'url' : 'text';
        $placeholder = match ($key) {
            'branch' => 'main',
            'base_path' => 'public',
            'bucket_git_url' => self::default_bucket_git_url(),
            'public_url' => 'https://memories.tikool.com',
            default => '',
        };

        printf(
            '<input class="regular-text" type="%1$s" name="%2$s[%3$s]" value="%4$s" placeholder="%5$s" autocomplete="%6$s">',
            esc_attr($type),
            esc_attr(self::OPTION),
            esc_attr($key),
            esc_attr($value),
            esc_attr($placeholder),
            'off'
        );

        if ($key === 'base_path') {
            echo '<p class="description">' . esc_html__('Final NFC files are written as public-directory/random-id.html in the persistent HTML repository.', 'tikool-memory-page') . '</p>';
        } elseif ($key === 'bucket_git_url') {
            echo '<p class="description">' . esc_html__('New Memory Pages permanently record this repository. Example: git@github.com:damahaxyz/tikool-memory-pages-bucket1.git.', 'tikool-memory-page') . '</p>';
        } elseif ($key === 'public_url') {
            echo '<p class="description">' . esc_html__('NFC URLs use this base plus random-id.html.', 'tikool-memory-page') . '</p>';
        }
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Memory Pages', 'tikool-memory-page'); ?></h1>
            <p><?php esc_html_e('Configure the repository used by Cloudflare and the stable public URL written to each NFC tag.', 'tikool-memory-page'); ?></p>
            <form action="options.php" method="post">
                <?php
                settings_fields('tikool_memory_page');
                do_settings_sections('tikool-memory-pages');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public static function get(string $key): string
    {
        $constant = match ($key) {
            'branch' => 'TIKOOL_MEMORY_GITHUB_BRANCH',
            'base_path' => 'TIKOOL_MEMORY_GITHUB_BASE_PATH',
            'bucket_git_url' => 'TIKOOL_MEMORY_BUCKET_GIT_URL',
            'public_url' => 'TIKOOL_MEMORY_PUBLIC_URL',
            default => '',
        };

        if ($constant !== '' && defined($constant)) {
            $configured = trim((string) constant($constant));
            if ($key === 'bucket_git_url') {
                return self::is_valid_bucket_git_url($configured) ? $configured : self::default_bucket_git_url();
            }
            if ($key === 'base_path') {
                $configured = trim($configured, '/');
                return preg_match('#^[A-Za-z0-9_.-]+$#', $configured) && ! self::is_valid_bucket(strtolower($configured)) ? $configured : 'public';
            }
            return $configured;
        }

        $settings = get_option(self::OPTION, []);
        $value = is_array($settings) ? (string) ($settings[$key] ?? '') : '';
        if ($key === 'bucket_git_url' && $value === '' && is_array($settings)) {
            $legacy_bucket = strtolower((string) ($settings['bucket'] ?? ''));
            if (self::is_valid_bucket($legacy_bucket)) {
                $value = 'git@github.com:damahaxyz/tikool-memory-pages-' . $legacy_bucket . '.git';
            }
        }

        if ($key === 'base_path') {
            $value = trim($value, '/');
            if ($value === '' || ! preg_match('#^[A-Za-z0-9_.-]+$#', $value) || self::is_valid_bucket(strtolower($value))) {
                return 'public';
            }
        }

        if ($key === 'bucket_git_url' && ! self::is_valid_bucket_git_url($value)) {
            return self::default_bucket_git_url();
        }

        return match ($key) {
            'branch' => $value !== '' ? $value : 'main',
            'base_path' => $value !== '' ? trim($value, '/') : 'public',
            'bucket_git_url' => $value !== '' ? $value : self::default_bucket_git_url(),
            default => $value,
        };
    }

    public static function is_valid_bucket(string $bucket): bool
    {
        return (bool) preg_match('/^bucket[1-9][0-9]*$/', $bucket);
    }

    public static function default_bucket_git_url(): string
    {
        return 'git@github.com:damahaxyz/tikool-memory-pages-bucket1.git';
    }

    public static function is_valid_bucket_git_url(string $url): bool
    {
        return (bool) preg_match('#^git@github\.com:[A-Za-z0-9_.-]+/tikool-memory-pages-bucket[1-9][0-9]*\.git$#', $url);
    }

    public static function bucket_from_git_url(string $url): string
    {
        if (! self::is_valid_bucket_git_url($url) || ! preg_match('#-(bucket[1-9][0-9]*)\.git$#', $url, $matches)) {
            return '';
        }

        return $matches[1];
    }

    public static function bucket_public_url(string $git_url): string
    {
        $bucket = self::bucket_from_git_url($git_url);
        return $bucket !== '' ? 'https://' . $bucket . '.tikool.com' : '';
    }

}
