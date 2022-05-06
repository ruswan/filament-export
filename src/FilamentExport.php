<?php

namespace AlperenErsoy\FilamentExport;

use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use AlperenErsoy\FilamentExport\Actions\FilamentExportHeaderAction;
use AlperenErsoy\FilamentExport\Components\TableView;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Facades\Excel;
use AlperenErsoy\FilamentExport\Concerns\CanFilterColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveAdditionalColumns;
use AlperenErsoy\FilamentExport\Concerns\HasData;
use AlperenErsoy\FilamentExport\Concerns\HasFileName;
use AlperenErsoy\FilamentExport\Concerns\HasFormat;
use AlperenErsoy\FilamentExport\Concerns\HasPageOrientation;
use AlperenErsoy\FilamentExport\Concerns\HasTable;
use Barryvdh\Debugbar\Facades\Debugbar;
use Barryvdh\DomPDF\PDF;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilamentExport implements FromCollection, WithHeadings, WithTitle, WithCustomCsvSettings, ShouldAutoSize
{
    use CanFilterColumns;
    use CanHaveAdditionalColumns;
    use HasData;
    use HasFileName;
    use HasFormat;
    use HasPageOrientation;
    use HasTable;

    public const FORMATS = [
        'xlsx' => \Maatwebsite\Excel\Excel::XLSX,
        'csv' => \Maatwebsite\Excel\Excel::CSV,
        'pdf' => 'Pdf'
    ];

    public static function make(): static
    {
        $static = app(static::class);
        $static->setUp();

        return $static;
    }

    protected function setUp(): void
    {
        $this->fileName(Date::now()->toString());
        $this->format(config('filament-export.default_format'));
    }

    public function getAllColumns(): Collection
    {
        $columns = collect($this->getTable()->getColumns());

        if ($this->getFilteredColumns()->isNotEmpty())
            $columns = $columns->filter(fn ($column) => $this->getFilteredColumns()->contains($column->getName()));

        if ($this->getAdditionalColumns()->isNotEmpty())
            $columns = $columns->merge($this->getAdditionalColumns());

        return $columns;
    }

    public function getPdfView(): string
    {
        return 'filament-export::pdf';
    }

    public function getPdfViewData(): array
    {
        return ['fileName' => $this->getFileName(), 'columns' => $this->getAllColumns(), 'rows' => $this->collection()];
    }

    public function download(): BinaryFileResponse | StreamedResponse
    {
        if ($this->getFormat() === 'pdf') {
            $pdf = $this->getPdf();
            return response()->streamDownload(fn () => print($pdf->output()), "{$this->getFileName()}.{$this->getFormat()}");
        }

        return Excel::download($this, "{$this->getFileName()}.{$this->getFormat()}", static::FORMATS[$this->getFormat()]);
    }

    public function getPdf(): PDF
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView($this->getPdfView(), $this->getPdfViewData())->setPaper('A4', $this->getPageOrientation());
    }

    public static function setUpFilamentExportAction(FilamentExportHeaderAction | FilamentExportBulkAction $action): void
    {
        $action->timeFormat(config('filament-export.time_format'));

        $action->defaultFormat(config('filament-export.default_format'));

        $action->defaultPageOrientation(config('filament-export.default_page_orientation'));

        $action->disableAdditionalColumns(config('filament-export.disable_additional_columns'));

        $action->disableColumnFilters(config('filament-export.disable_column_filters'));

        $action->disableFileName(config('filament-export.disable_file_name'));

        $action->disablePreview(config('filament-export.disable_preview'));

        $action->icon(config('filament-export.action_icon'));

        $action->fileName(Carbon::now()->translatedFormat($action->getTimeFormat()));

        $action->fileNameFieldLabel(__('filament-export::export_action.file_name_field_label'));

        $action->columnFiltersFieldLabel(__('filament-export::export_action.column_filters_field_label'));

        $action->formatFieldLabel(__('filament-export::export_action.format_field_label'));

        $action->pageOrientationFieldLabel(__('filament-export::export_action.page_orientation_field_label'));

        $action->additionalColumnsFieldLabel(__('filament-export::export_action.additional_columns_field.label'));

        $action->additionalColumnsTitleFieldLabel(__('filament-export::export_action.additional_columns_field.title_field_label'));

        $action->additionalColumnsDefaultValueFieldLabel(__('filament-export::export_action.additional_columns_field.default_value_field_label'));

        $action->additionalColumnsAddButtonLabel(__('filament-export::export_action.additional_columns_field.add_button_label'));

        $action->modalButton(__('filament-export::export_action.export_action_label'));

        $action->modalHeading(__('filament-export::export_action.modal_heading'));

        $action->modalActions($action->getExportModalActions());
    }

    public static function getFormComponents(FilamentExportHeaderAction | FilamentExportBulkAction $action, Collection $records): array
    {
        $action->fileNamePrefix($action->getFileNamePrefix() ?: $action->getTable()->getHeading());

        $columns = collect($action->getTable()->getColumns())
            ->map(fn ($column) => ['name' => $column->getName(), 'label' => $column->getLabel()])
            ->pluck('label', 'name');

        $updateTableView = function ($component, $livewire) use ($records, $action) {
            $data =  $action instanceof FilamentExportBulkAction ? $livewire->mountedTableBulkActionData : $livewire->mountedTableActionData;

            $export = FilamentExport::make()
                ->filteredColumns($data["column_filters"] ?? [])
                ->additionalColumns($data["additional_columns"] ?? [])
                ->data($records)
                ->table($action->getTable());

            $table_view = $component->getContainer()->getComponent(fn ($component) => $component->getName() === 'table_view');

            $table_view->export($export);
        };

        return [
            \Filament\Forms\Components\TextInput::make('file_name')
                ->label($action->getFileNameFieldLabel())
                ->default($action->getFileName())
                ->hidden($action->isFileNameDisabled())
                ->rule('regex:/[a-zA-Z0-9\s_\\.\-\(\):]/')
                ->required(),
            \Filament\Forms\Components\Select::make('format')
                ->label($action->getFormatFieldLabel())
                ->options(FilamentExport::FORMATS)
                ->default($action->getDefaultFormat())
                ->reactive(),
            \Filament\Forms\Components\Select::make('page_orientation')
                ->label($action->getPageOrientationFieldLabel())
                ->options(FilamentExport::getPageOrientations())
                ->default($action->getDefaultPageOrientation())
                ->visible(fn ($get) => $get('format') === 'pdf')
                ->reactive(),
            \Filament\Forms\Components\MultiSelect::make('column_filters')
                ->label($action->getColumnFiltersFieldLabel())
                ->options($columns)
                ->afterStateUpdated($updateTableView)
                ->reactive()
                ->hidden($action->isColumnFiltersDisabled()),
            \Filament\Forms\Components\KeyValue::make('additional_columns')
                ->label($action->getAdditionalColumnsFieldLabel())
                ->keyLabel($action->getAdditionalColumnsTitleFieldLabel())
                ->valueLabel($action->getAdditionalColumnsDefaultValueFieldLabel())
                ->addButtonLabel($action->getAdditionalColumnsAddButtonLabel())
                ->afterStateUpdated($updateTableView)
                ->reactive()
                ->hidden($action->isAdditionalColumnsDisabled()),
            TableView::make('table_view')
                ->export(
                    FilamentExport::make()
                        ->data($records)
                        ->table($action->getTable())
                )
                ->reactive()
        ];
    }

    public static function callDownload(FilamentExportHeaderAction | FilamentExportBulkAction $action, Collection $records, array $data)
    {
        return FilamentExport::make()
            ->fileName($data["file_name"] ?? $action->getFileName())
            ->data($records)
            ->table($action->getTable())
            ->filteredColumns(!$action->isColumnFiltersDisabled() ? $data["column_filters"] : [])
            ->additionalColumns(!$action->isAdditionalColumnsDisabled() ? $data["additional_columns"] : [])
            ->format($data["format"] ?? $action->getDefaultFormat())
            ->pageOrientation($data["page_orientation"] ?? $action->getDefaultPageOrientation())
            ->download();
    }

    public function collection(): Collection
    {
        $records = $this->getData();

        $columns = $this->getAllColumns();

        $items = [];

        foreach ($records as $record) {
            $item = [];
            foreach ($columns as $column) {
                $column = $column->record($record);
                $state = in_array(\Filament\Tables\Columns\Concerns\CanFormatState::class, class_uses($column)) ? $column->getFormattedState() : $column->getState();
                if (is_array($state))
                    $state = implode(", ", $state);
                else if ($column instanceof ImageColumn) {
                    $state = $column->getImagePath();
                } else if ($column instanceof ViewColumn) {
                    $state = trim(preg_replace('/\s+/', ' ', strip_tags($column->render()->render())));
                }
                $item[$column->getName()] = $state;
            }
            array_push($items, $item);
        }

        return collect($items);
    }

    public function headings(): array
    {
        $allColumns = $this->getAllColumns();

        $headers = $allColumns->map(fn ($column) => $column->getLabel());

        if ($this->getFilteredColumns()->isNotEmpty())
            $headers = $allColumns
                ->filter(fn ($column) => $this->getFilteredColumns()->contains($column->getName()) || $this->getAdditionalColumns()->contains($column))
                ->map(fn ($column) => $column->getLabel());

        return $headers->toArray();
    }

    public function title(): string
    {
        return $this->getFileName();
    }

    public function getCsvSettings(): array
    {
        return ['use_bom' => true];
    }
}