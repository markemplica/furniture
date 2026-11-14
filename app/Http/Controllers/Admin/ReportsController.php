<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FurnitureProduct;
use App\Models\Recommendation;
use App\Models\Reservation;
use App\Models\User;
use App\Http\Requests\Admin\ReportFiltersRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Dompdf\Dompdf;
use Dompdf\Options;

class ReportsController extends Controller
{


    public function index(ReportFiltersRequest $request)
    {
        $filters = $request->validated();
        $report = $filters['report'] ?? 'reservations';
        $query = $this->reportQuery($report, $filters);
        $rows = $query->paginate(30)->withQueryString();
        $recordCount = $rows->total();
        $branchStats = $report === 'reservations' ? $this->reservationBranchStats($filters) : collect();

        return view('admin.reports.index', compact('report', 'filters', 'rows', 'recordCount', 'branchStats'));
    }

    public function export(ReportFiltersRequest $request)
    {
        $filters = $request->validated();
        $report = $filters['report'] ?? 'reservations';
        $filename = 'furnicraft-'.$report.'-report-'.now()->format('Ymd-His').'.csv';
        [$headers, $fields] = $this->columns($report);
        $query = $this->reportQuery($report, $filters);

        if (($filters['format'] ?? 'csv') === 'pdf') {
            $recordCount = (clone $query)->count();
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $pdf = new Dompdf($options);
            $pdf->loadHtml(view('admin.reports.pdf', [
                'report' => $report, 'filters' => $filters, 'headers' => $headers,
                'recordCount' => $recordCount,
                'rows' => $this->pdfRows($query, $fields),
            ])->render());
            $pdf->setPaper('a3', 'landscape');
            $pdf->render();

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.str_replace('.csv', '.pdf', $filename).'"',
            ]);
        }

        return response()->streamDownload(function () use ($query, $headers, $fields): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            $query->chunkById(500, function ($records) use ($stream, $fields): void {
                foreach ($records as $record) {
                    $values = $this->rowValues($record, $fields);
                    fputcsv($stream, array_map($this->spreadsheetSafe(...), $values));
                }
            });
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function reportQuery(string $report, array $filters): Builder
    {
        $query = match ($report) {
            'recommendations' => Recommendation::query()
                ->with('user:id,name,email')
                ->withCount('items')
                ->addSelect(['reservation_count' => Reservation::selectRaw('COUNT(*)')->whereColumn('recommendation_id', 'recommendations.id')]),
            'customers' => User::query()->where('role', 'customer')->withCount(['recommendations', 'reservations']),
            'furniture' => FurnitureProduct::query()->withCount(['views', 'favorites', 'reservations']),
            default => Reservation::query()->with(['user:id,name,email', 'product:id,name,category']),
        };

        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.trim($filters['q']).'%';
            $columns = match ($report) {
                'customers' => ['name', 'email', 'phone'],
                'furniture' => ['name', 'category', 'wood_type', 'varnish_finish', 'style'],
                'recommendations' => ['id', 'furniture_type', 'algorithm_version'],
                default => ['reference', 'status', 'notes'],
            };
            $query->where(function (Builder $search) use ($columns, $report, $term) {
                foreach ($columns as $column) {
                    $search->orWhere($column, 'like', $term);
                }
                if (in_array($report, ['reservations', 'recommendations'], true)) {
                    $search->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term));
                }
                if ($report === 'reservations') {
                    $search->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $term)->orWhere('category', 'like', $term));
                }
            });
        }

        $dateColumn = match ($report) {
            'customers' => 'users.created_at',
            'furniture' => 'furniture_products.created_at',
            'recommendations' => 'recommendations.created_at',
            default => 'reservations.created_at',
        };

        if (! empty($filters['date_from'])) {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }
        if ($report === 'reservations' && ! empty($filters['branch'])) {
            $query->where('branch', $filters['branch']);
        }

        return $query->latest($dateColumn)->orderByDesc($query->getModel()->qualifyColumn('id'));
    }

    private function reservationBranchStats(array $filters)
    {
        return $this->reportQuery('reservations', $filters)->reorder()
            ->setEagerLoads([])
            ->select('branch')
            ->selectRaw('COUNT(*) as reservation_count')
            ->selectRaw("SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_count")
            ->groupBy('branch')->orderBy('branch')->get();
    }

    private function pdfRows(Builder $query, array $fields): LazyCollection
    {
        return $query->lazy(200)->map(fn ($record) => $this->rowValues($record, $fields));
    }

    private function columns(string $report): array
    {
        return match ($report) {
            'recommendations' => [['Recommendation ID', 'Created At', 'Customer', 'Email', 'Furniture Type', 'Algorithm', 'Content Weight', 'Collaborative Weight', 'Items', 'Linked Reservations'], ['id', 'created_at', 'user_name', 'user_email', 'furniture_type', 'algorithm_version', 'content_weight', 'collaborative_weight', 'items_count', 'reservation_count']],
            'customers' => [['Customer ID', 'Name', 'Email', 'Phone', 'Active', 'Joined At', 'Recommendations', 'Reservations'], ['id', 'name', 'email', 'phone', 'is_active', 'created_at', 'recommendations_count', 'reservations_count']],
            'furniture' => [['Product ID', 'Name', 'Category', 'Style', 'Wood', 'Finish', 'Price PHP', 'Width cm', 'Depth cm', 'Height cm', 'Status', 'Views', 'Favorites', 'Reservations'], ['id', 'name', 'category', 'style', 'wood_type', 'varnish_finish', 'price', 'width_cm', 'depth_cm', 'height_cm', 'status', 'views_count', 'favorites_count', 'reservations_count']],
            default => [['Reference', 'Created At', 'Customer', 'Email', 'Furniture', 'Category', 'Status', 'Recommendation ID', 'Notes'], ['reference', 'created_at', 'user_name', 'user_email', 'product_name', 'product_category', 'status', 'recommendation_id', 'notes']],
        };
    }

    private function rowValues($record, array $fields): array
    {
        return array_map(fn (string $field) => match ($field) {
            'user_name' => $record->user?->name,
            'user_email' => $record->user?->email,
            'product_name' => $record->product?->name,
            'product_category' => $record->product?->category,
            'created_at' => $record->created_at?->toDateTimeString(),
            'is_active' => $record->is_active ? 'Yes' : 'No',
            default => data_get($record, $field),
        }, $fields);
    }

    private function spreadsheetSafe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+@\-\t\r]/u', $text) ? "'".$text : $text;
    }
}
