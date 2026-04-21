<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PricingService;

final class OrderController extends BaseController
{
    public function create(): void
    {
        $this->view('user/orders/create');
    }

    public function store(): void
    {
        // controllers magros: apenas orquestração e validação superficial
        $pricing = (new PricingService())->calculate([
            'work_type_slug' => $_POST['work_type_slug'] ?? 'trabalho-pesquisa',
            'target_pages' => (int) ($_POST['target_pages'] ?? 10),
            'academic_level_multiplier' => (float) ($_POST['academic_level_multiplier'] ?? 1),
            'complexity_multiplier' => (float) ($_POST['complexity_multiplier'] ?? 1),
            'urgency_multiplier' => (float) ($_POST['urgency_multiplier'] ?? 1),
            'requires_human_review' => !empty($_POST['requires_human_review']),
            'user_id' => (int) ($_POST['user_id'] ?? 1),
            'work_type_id' => (int) ($_POST['work_type_id'] ?? 1),
            'extras' => $_POST['extras'] ?? [],
            'coupon_percent' => (float) ($_POST['coupon_percent'] ?? 0),
        ]);

        $this->json(['pricing_breakdown' => $pricing]);
    }
}
