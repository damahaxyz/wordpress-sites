<?php
/**
 * WP Media Folder REST integration.
 *
 * @package AromamatrixPlugin
 */

declare(strict_types=1);

namespace Aromamatrix\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

final class MediaFolderApi
{
    private const REST_NAMESPACE = 'aromamatrix/v1';

    private const REST_ROUTE = '/media-folders/assign';

    private const FOLDER_LOOKUP_ROUTE = '/media-folders';

    private const FOLDER_ATTACHMENTS_ROUTE = '/media-folders/(?P<id>\\d+)/attachments';

    /**
     * Register the REST route.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Register media-folder endpoints.
     */
    public function registerRoutes(): void
    {
        $canUpload = static function (): bool {
            return current_user_can('upload_files');
        };

        register_rest_route(
            self::REST_NAMESPACE,
            self::FOLDER_LOOKUP_ROUTE,
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'findFolders'],
                'permission_callback' => $canUpload,
                'args'                => [
                    'name' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::FOLDER_LOOKUP_ROUTE,
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'createFolder'],
                'permission_callback' => $canUpload,
                'args'                => [
                    'name' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'parent' => [
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::FOLDER_ATTACHMENTS_ROUTE,
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'listFolderAttachments'],
                'permission_callback' => $canUpload,
                'args'                => [
                    'id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'assignAttachments'],
                'permission_callback' => $canUpload,
                'args'                => [
                    'folder_id'      => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                    'attachment_ids' => [
                        'required' => true,
                    ],
                ],
            ]
        );
    }

    /**
     * Find existing WP Media Folder folders by an exact, case-insensitive name.
     *
     * This route intentionally never creates a folder or performs fuzzy matching.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function findFolders(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $taxonomy = $this->mediaFolderTaxonomy();
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }

        $name = trim((string) $request->get_param('name'));
        if ($name === '') {
            return new \WP_Error(
                'aromamatrix_wpmf_invalid_folder_name',
                __('Provide a folder name.', 'aromamatrix-plugin'),
                ['status' => 400]
            );
        }

        $terms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'name'       => $name,
        ]);
        if (is_wp_error($terms)) {
            return $terms;
        }

        $folders = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term && strcasecmp($term->name, $name) === 0) {
                $folders[] = [
                    'id'     => $term->term_id,
                    'name'   => $term->name,
                    'parent' => $term->parent,
                ];
            }
        }

        return new \WP_REST_Response(['folders' => $folders], 200);
    }

    /**
     * Create one WP Media Folder folder, or return an exact existing folder.
     *
     * Only an exact case-insensitive match is reused. This makes retries
     * idempotent and avoids silently selecting a similarly named folder.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function createFolder(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $taxonomy = $this->mediaFolderTaxonomy();
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }

        $name = trim((string) $request->get_param('name'));
        if ($name === '') {
            return new \WP_Error(
                'aromamatrix_wpmf_invalid_folder_name',
                __('Provide a folder name.', 'aromamatrix-plugin'),
                ['status' => 400]
            );
        }

        $parentId = absint($request->get_param('parent'));
        if ($parentId > 0 && ! (get_term($parentId, $taxonomy) instanceof \WP_Term)) {
            return new \WP_Error(
                'aromamatrix_wpmf_parent_not_found',
                __('The requested parent media folder does not exist.', 'aromamatrix-plugin'),
                ['status' => 404]
            );
        }

        $folders = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'name'       => $name,
        ]);
        if (is_wp_error($folders)) {
            return $folders;
        }

        foreach ($folders as $folder) {
            if (
                $folder instanceof \WP_Term
                && strcasecmp($folder->name, $name) === 0
                && (int) $folder->parent === $parentId
            ) {
                return new \WP_REST_Response(
                    [
                        'folder' => [
                            'id'     => $folder->term_id,
                            'name'   => $folder->name,
                            'parent' => $folder->parent,
                        ],
                        'created' => false,
                    ],
                    200
                );
            }
        }

        $created = wp_insert_term($name, $taxonomy, ['parent' => $parentId]);
        if (is_wp_error($created)) {
            return $created;
        }

        $folderId = absint($created['term_id'] ?? 0);
        $folder = get_term($folderId, $taxonomy);
        if (! $folder instanceof \WP_Term || $folder->name !== $name || (int) $folder->parent !== $parentId) {
            return new \WP_Error(
                'aromamatrix_wpmf_folder_create_failed',
                __('The media folder could not be verified after creation.', 'aromamatrix-plugin'),
                ['status' => 500]
            );
        }

        return new \WP_REST_Response(
            [
                'folder' => [
                    'id'     => $folder->term_id,
                    'name'   => $folder->name,
                    'parent' => $folder->parent,
                ],
                'created' => true,
            ],
            201
        );
    }

    /**
     * List every attachment currently assigned to one WP Media Folder folder.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function listFolderAttachments(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $taxonomy = $this->mediaFolderTaxonomy();
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }

        $folderId = absint($request->get_param('id'));
        $folder = get_term($folderId, $taxonomy);
        if (! $folder instanceof \WP_Term) {
            return new \WP_Error(
                'aromamatrix_wpmf_folder_not_found',
                __('The requested media folder does not exist.', 'aromamatrix-plugin'),
                ['status' => 404]
            );
        }

        $attachmentIds = get_objects_in_term($folderId, $taxonomy);
        if (is_wp_error($attachmentIds)) {
            return $attachmentIds;
        }

        $attachmentIds = array_values(array_map('absint', $attachmentIds));
        sort($attachmentIds, SORT_NUMERIC);

        return new \WP_REST_Response(
            [
                'folder' => [
                    'id'   => $folder->term_id,
                    'name' => $folder->name,
                ],
                'attachment_ids' => $attachmentIds,
                'count'          => count($attachmentIds),
            ],
            200
        );
    }

    /**
     * Move attachments into one existing WP Media Folder folder.
     *
     * Uses the same WordPress actions as the plugin's media-library move
     * command, so optional physical-folder processing remains intact.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function assignAttachments(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $taxonomy = $this->mediaFolderTaxonomy();
        if (is_wp_error($taxonomy)) {
            return $taxonomy;
        }

        $folderId = absint($request->get_param('folder_id'));
        $folder = get_term($folderId, $taxonomy);

        if (! $folder instanceof \WP_Term) {
            return new \WP_Error(
                'aromamatrix_wpmf_folder_not_found',
                __('The requested media folder does not exist.', 'aromamatrix-plugin'),
                ['status' => 404]
            );
        }

        $attachmentIds = $this->normalizeAttachmentIds($request->get_param('attachment_ids'));

        if ($attachmentIds === []) {
            return new \WP_Error(
                'aromamatrix_wpmf_invalid_attachments',
                __('Provide at least one attachment ID.', 'aromamatrix-plugin'),
                ['status' => 400]
            );
        }

        if (count($attachmentIds) > 100) {
            return new \WP_Error(
                'aromamatrix_wpmf_too_many_attachments',
                __('A request can move at most 100 attachments.', 'aromamatrix-plugin'),
                ['status' => 400]
            );
        }

        foreach ($attachmentIds as $attachmentId) {
            if (get_post_type($attachmentId) !== 'attachment') {
                return new \WP_Error(
                    'aromamatrix_wpmf_invalid_attachment',
                    sprintf(
                        /* translators: %d: attachment ID. */
                        __('Attachment %d does not exist.', 'aromamatrix-plugin'),
                        $attachmentId
                    ),
                    ['status' => 404]
                );
            }

            if (! current_user_can('edit_post', $attachmentId)) {
                return new \WP_Error(
                    'aromamatrix_wpmf_cannot_edit_attachment',
                    __('You cannot edit one or more attachments.', 'aromamatrix-plugin'),
                    ['status' => 403]
                );
            }
        }

        foreach ($attachmentIds as $attachmentId) {
            $result = wp_set_object_terms($attachmentId, $folderId, $taxonomy, false);

            if (is_wp_error($result)) {
                return $result;
            }

            /**
             * Let WP Media Folder apply any enabled physical-folder actions.
             * This mirrors its native move-file action.
             *
             * @param int   $attachmentId Attachment ID.
             * @param int   $folderId     Target WP Media Folder term ID.
             * @param array $context      Move context.
             */
            do_action('wpmf_attachment_set_folder', $attachmentId, $folderId, ['trigger' => 'move_file']);
        }

        return new \WP_REST_Response(
            [
                'folder'      => [
                    'id'   => $folder->term_id,
                    'name' => $folder->name,
                ],
                'attachments' => $attachmentIds,
                'moved'       => count($attachmentIds),
            ],
            200
        );
    }

    /**
     * Return the registered WP Media Folder taxonomy or a consistent API error.
     *
     * @return string|\WP_Error
     */
    private function mediaFolderTaxonomy(): string|\WP_Error
    {
        if (! defined('WPMF_TAXO') || ! taxonomy_exists(WPMF_TAXO)) {
            return new \WP_Error(
                'aromamatrix_wpmf_unavailable',
                __('WP Media Folder is not available.', 'aromamatrix-plugin'),
                ['status' => 503]
            );
        }

        return WPMF_TAXO;
    }

    /**
     * @param mixed $value REST parameter value.
     * @return list<int>
     */
    private function normalizeAttachmentIds(mixed $value): array
    {
        if (! is_array($value)) {
            $value = [$value];
        }

        $attachmentIds = array_map('absint', $value);
        $attachmentIds = array_filter($attachmentIds);

        return array_values(array_unique($attachmentIds));
    }
}
