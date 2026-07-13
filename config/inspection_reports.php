<?php

return [
    'pdf' => [
        'max_images' => (int) env('INSPECTION_PDF_MAX_IMAGES', 20),
        'max_image_bytes' => (int) env('INSPECTION_PDF_MAX_IMAGE_BYTES', 2 * 1024 * 1024),
        'max_total_image_bytes' => (int) env('INSPECTION_PDF_MAX_TOTAL_IMAGE_BYTES', 12 * 1024 * 1024),
        'max_image_pixels' => (int) env('INSPECTION_PDF_MAX_IMAGE_PIXELS', 16_000_000),
    ],
];
