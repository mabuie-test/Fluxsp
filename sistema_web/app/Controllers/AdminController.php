<?php

declare(strict_types=1);

namespace App\Controllers;

final class AdminController extends BaseController
{
    public function index(): void
    {
        $this->view('admin/index');
    }
}
