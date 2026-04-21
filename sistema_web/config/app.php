<?php

return [
    'order_statuses' => [
        'draft','awaiting_payment','payment_processing','paid','queued','generating_structure','generating_content','refining','formatting_docx','awaiting_human_review','ready','delivered','revision_requested','revised','cancelled'
    ],
    'payment_statuses' => [
        'pending','processing','pending_confirmation','paid','failed','cancelled','expired'
    ],
    'roles' => ['user','admin','human_reviewer','superadmin'],
];
