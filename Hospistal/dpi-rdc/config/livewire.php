<?php

return [
    'asset_url' => null,
    'app_url' => null,
    'middleware_group' => ['web'],
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => null,
        'directory' => null,
        'middleware' => null,
        'preview_mixin' => null,
    ],
    'render_on_redirect' => false,
    'legacy_model_binding' => false,
    'inject_assets' => true,
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],
    'pagination_theme' => 'tailwind',
];