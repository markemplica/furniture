<?php

namespace App\Services;

class AiAggregateBudgetExceeded extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('ai_application_budget_exhausted');
    }
}
