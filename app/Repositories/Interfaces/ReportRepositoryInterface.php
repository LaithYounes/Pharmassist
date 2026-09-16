<?php

namespace App\Repositories\Interfaces;

interface ReportRepositoryInterface 
{
    public function summary(?string $from = null, ?string $to = null): array;
    public function getTotalSales();
    public function getTotalReturns();
    public function getNetSales();
    public function getDailyNetSales($date = null);
    public function getMonthlyNetSales($year, $month);
}
