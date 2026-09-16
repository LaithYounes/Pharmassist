<?php

namespace App\Http\Controllers;

use App\Repositories\ReportRepository;
use App\Repositories\Interfaces\ReportRepositoryInterface;
use Illuminate\Support\Facades\Gate;
class ReportController extends Controller
{
    protected $reportRepo;

   public function __construct(ReportRepositoryInterface $reportRepo)
{
    $this->reportRepo = $reportRepo;
}
    public function netSales()
    {
        Gate::authorize('manage-pharmacy');
        return response()->json([
            ...$this->reportRepo->summary()
        ]);
    }

    public function dailyNetSales($date = null)
    {
        Gate::authorize('manage-pharmacy');
        return response()->json([
            'date' => $date ?? now()->toDateString(),
            ...$this->reportRepo->summary($date ?? now()->toDateString(), $date ?? now()->toDateString())
        ]);
    }

    public function monthlyNetSales($year, $month)
    {
        Gate::authorize('manage-pharmacy');
        return response()->json([
            'year' => $year,
            'month' => $month,
            ...$this->reportRepo->summary(sprintf('%04d-%02d-01', $year, $month),
                date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $month))))
        ]);
    }
}
