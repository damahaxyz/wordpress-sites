<?php
/**
 * Persistent asynchronous publishing tasks.
 */

declare(strict_types=1);

namespace Tikool\MemoryPage;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

final class Tasks
{
    public const POST_TYPE = 'tikool_memory_task';
    private const HOOK = 'tikool_memory_process_task';

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
        add_action('init', [self::class, 'register_post_type']);
        add_action(self::HOOK, [$this, 'process'], 10, 1);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('add_meta_boxes_' . self::POST_TYPE, [$this, 'add_details_metabox']);
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        add_action('admin_post_tikool_retry_memory_task', [$this, 'retry']);
    }

    public static function register_post_type(): void
    {
        $capabilities = array_fill_keys([
            'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
            'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts',
            'create_posts',
        ], 'manage_woocommerce');
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'          => __('Memory Page Tasks', 'tikool-memory-page'),
                'singular_name' => __('Memory Page Task', 'tikool-memory-page'),
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'woocommerce',
            'show_in_rest'    => false,
            'supports'        => ['title'],
            'map_meta_cap'    => false,
            'capabilities'    => $capabilities,
            'capability_type' => 'post',
        ]);
    }

    /** @return int|WP_Error */
    public function create(int $page_id, string $token)
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token) || ! is_dir(Storage::bundle_directory($token))) {
            return new WP_Error('tikool_memory_task_bundle_invalid', __('The publishing-task bundle is invalid.', 'tikool-memory-page'));
        }
        $page = get_post($page_id);
        if (! $page || $page->post_type !== Memory_Pages::POST_TYPE) {
            return new WP_Error('tikool_memory_task_page_invalid', __('The Memory Page record is invalid.', 'tikool-memory-page'));
        }

        $task_id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => sprintf(__('Publish Memory Page %s', 'tikool-memory-page'), get_the_title($page_id)),
            'post_author' => get_current_user_id(),
        ], true);
        if (is_wp_error($task_id)) {
            return $task_id;
        }

        $bucket_git_url = (string) get_post_meta($page_id, '_tikool_memory_bucket_git_url', true);
        update_post_meta($task_id, '_tikool_memory_task_status', 'queued');
        update_post_meta($task_id, '_tikool_memory_task_page_id', $page_id);
        update_post_meta($task_id, '_tikool_memory_task_bundle', $token);
        update_post_meta($task_id, '_tikool_memory_task_bucket_git_url', $bucket_git_url);
        update_post_meta($task_id, '_tikool_memory_task_queued_at', gmdate('c'));
        update_post_meta($page_id, '_tikool_memory_latest_task_id', $task_id);
        update_post_meta($page_id, '_tikool_memory_publish_status', 'queued');
        delete_post_meta($page_id, '_tikool_memory_last_error');

        $this->enqueue((int) $task_id);

        return (int) $task_id;
    }

    public function has_active_task(int $page_id): bool
    {
        $task_id = (int) get_post_meta($page_id, '_tikool_memory_latest_task_id', true);
        $status = $task_id > 0 ? (string) get_post_meta($task_id, '_tikool_memory_task_status', true) : '';
        return in_array($status, ['queued', 'running'], true);
    }

    public function process(int $task_id): void
    {
        $task = get_post($task_id);
        if (! $task || $task->post_type !== self::POST_TYPE) {
            return;
        }
        $status = (string) get_post_meta($task_id, '_tikool_memory_task_status', true);
        if ($status !== 'queued') {
            return;
        }
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $page_id = (int) get_post_meta($task_id, '_tikool_memory_task_page_id', true);
        $token = (string) get_post_meta($task_id, '_tikool_memory_task_bundle', true);
        $slug = (string) get_post_meta($page_id, '_tikool_memory_directory', true);
        $bucket_git_url = (string) get_post_meta($task_id, '_tikool_memory_task_bucket_git_url', true);
        $bucket = Settings::bucket_from_git_url($bucket_git_url);

        update_post_meta($task_id, '_tikool_memory_task_status', 'running');
        update_post_meta($task_id, '_tikool_memory_task_started_at', gmdate('c'));
        update_post_meta($page_id, '_tikool_memory_publish_status', 'running');

        $result = (new Git_Publisher())->publish_task($task_id, $page_id, $slug, $bucket_git_url, $bucket, $token);
        if ($result instanceof WP_Error) {
            $message = $result->get_error_message();
            update_post_meta($task_id, '_tikool_memory_task_status', 'failed');
            update_post_meta($task_id, '_tikool_memory_task_error', $message);
            update_post_meta($task_id, '_tikool_memory_task_finished_at', gmdate('c'));
            update_post_meta($page_id, '_tikool_memory_publish_status', 'failed');
            update_post_meta($page_id, '_tikool_memory_last_error', $message);
            return;
        }

        update_post_meta($task_id, '_tikool_memory_task_status', 'completed');
        update_post_meta($task_id, '_tikool_memory_task_bucket_commit', $result['bucket_commit']);
        update_post_meta($task_id, '_tikool_memory_task_html_commit', $result['html_commit']);
        update_post_meta($task_id, '_tikool_memory_task_finished_at', gmdate('c'));
        update_post_meta($page_id, '_tikool_memory_publish_status', 'published');
        update_post_meta($page_id, '_tikool_memory_bucket_initialized', 'yes');
        update_post_meta($page_id, '_tikool_memory_last_commit', $result['html_commit']);
        update_post_meta($page_id, '_tikool_memory_last_bucket_commit', $result['bucket_commit']);
        update_post_meta($page_id, '_tikool_memory_remote_path', $result['remote_path']);
        update_post_meta($page_id, '_tikool_memory_bucket_remote_path', $result['bucket_path']);
        update_post_meta($page_id, '_tikool_memory_media', $result['media']);
        update_post_meta($page_id, '_tikool_memory_manifest', $result['manifest']);
        update_post_meta($page_id, '_tikool_memory_published_at', gmdate('c'));
        update_post_meta($page_id, '_tikool_memory_public_url', Memory_Pages::public_url($page_id));
        delete_post_meta($page_id, '_tikool_memory_last_error');
        wp_update_post(['ID' => $page_id, 'post_status' => 'publish']);
    }

    /** @param array<string, string> $columns */
    public function columns(array $columns): array
    {
        return [
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Task', 'tikool-memory-page'),
            'tikool_task_status' => __('Status', 'tikool-memory-page'),
            'tikool_task_page' => __('Memory Page', 'tikool-memory-page'),
            'tikool_task_bucket' => __('Bucket repository', 'tikool-memory-page'),
            'date' => $columns['date'] ?? __('Date', 'tikool-memory-page'),
        ];
    }

    public function render_column(string $column, int $task_id): void
    {
        if ($column === 'tikool_task_status') {
            echo esc_html((string) get_post_meta($task_id, '_tikool_memory_task_status', true));
        } elseif ($column === 'tikool_task_page') {
            $page_id = (int) get_post_meta($task_id, '_tikool_memory_task_page_id', true);
            $url = $page_id > 0 ? get_edit_post_link($page_id) : '';
            echo $url ? '<a href="' . esc_url($url) . '">#' . esc_html((string) $page_id) . '</a>' : '&mdash;';
        } elseif ($column === 'tikool_task_bucket') {
            echo '<code>' . esc_html((string) get_post_meta($task_id, '_tikool_memory_task_bucket_git_url', true)) . '</code>';
        }
    }

    public function add_details_metabox(): void
    {
        add_meta_box(
            'tikool-memory-task-details',
            __('Publishing task details', 'tikool-memory-page'),
            [$this, 'render_details_metabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_details_metabox(\WP_Post $post): void
    {
        $page_id = (int) get_post_meta($post->ID, '_tikool_memory_task_page_id', true);
        $fields = [
            __('Status', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_status', true),
            __('Memory Page', 'tikool-memory-page') => '#' . $page_id,
            __('Bucket repository', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_bucket_git_url', true),
            __('Queued at', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_queued_at', true),
            __('Started at', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_started_at', true),
            __('Finished at', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_finished_at', true),
            __('Bucket commit', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_bucket_commit', true),
            __('HTML commit', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_html_commit', true),
            __('Error', 'tikool-memory-page') => (string) get_post_meta($post->ID, '_tikool_memory_task_error', true),
        ];
        echo '<table class="widefat striped"><tbody>';
        foreach ($fields as $label => $value) {
            echo '<tr><th style="width:180px">' . esc_html($label) . '</th><td><code style="overflow-wrap:anywhere">' . esc_html($value !== '' ? $value : '—') . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }

    /** @param array<string, string> $actions */
    public function row_actions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== self::POST_TYPE || ! current_user_can('manage_woocommerce')) {
            return $actions;
        }
        if (get_post_meta($post->ID, '_tikool_memory_task_status', true) === 'failed') {
            $url = wp_nonce_url(
                add_query_arg(['action' => 'tikool_retry_memory_task', 'task_id' => $post->ID], admin_url('admin-post.php')),
                'tikool_retry_memory_task_' . $post->ID
            );
            $actions['tikool_retry_memory_task'] = '<a href="' . esc_url($url) . '">' . esc_html__('Retry', 'tikool-memory-page') . '</a>';
        }
        return $actions;
    }

    public function retry(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You cannot retry this task.', 'tikool-memory-page'), '', ['response' => 403]);
        }
        $task_id = isset($_GET['task_id']) ? absint($_GET['task_id']) : 0;
        check_admin_referer('tikool_retry_memory_task_' . $task_id);
        $token = (string) get_post_meta($task_id, '_tikool_memory_task_bundle', true);
        if (get_post_meta($task_id, '_tikool_memory_task_status', true) !== 'failed' || ! is_dir(Storage::bundle_directory($token))) {
            wp_die(esc_html__('This failed task no longer has a retryable bundle.', 'tikool-memory-page'), '', ['response' => 409]);
        }
        $page_id = (int) get_post_meta($task_id, '_tikool_memory_task_page_id', true);
        $latest_task_id = (int) get_post_meta($page_id, '_tikool_memory_latest_task_id', true);
        if ($latest_task_id !== $task_id) {
            wp_die(esc_html__('A newer publishing task exists for this Memory Page; the older task cannot be retried.', 'tikool-memory-page'), '', ['response' => 409]);
        }
        update_post_meta($task_id, '_tikool_memory_task_status', 'queued');
        delete_post_meta($task_id, '_tikool_memory_task_error');
        delete_post_meta($task_id, '_tikool_memory_task_started_at');
        delete_post_meta($task_id, '_tikool_memory_task_finished_at');
        update_post_meta($page_id, '_tikool_memory_latest_task_id', $task_id);
        update_post_meta($page_id, '_tikool_memory_publish_status', 'queued');
        $this->enqueue($task_id);
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::POST_TYPE));
        exit;
    }

    private function enqueue(int $task_id): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK, [$task_id], 'tikool-memory-page', true);
        } else {
            wp_schedule_single_event(time() + 5, self::HOOK, [$task_id]);
        }
    }
}
