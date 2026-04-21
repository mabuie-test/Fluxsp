<?php

declare(strict_types=1);

namespace App\Controllers;

final class DashboardController extends BaseController
{
    public function index(): void
    {
        $this->view('user/dashboard/index');
    }
}
