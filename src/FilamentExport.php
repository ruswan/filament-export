<?php

namespace AlperenErsoy\FilamentExport;

use AlperenErsoy\FilamentExport\Actions\FilamentExportBulkAction;
use AlperenErsoy\FilamentExport\Actions\FilamentExportHeaderAction;
use AlperenErsoy\FilamentExport\Components\TableView;
use AlperenErsoy\FilamentExport\Concerns\CanFilterColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveAdditionalColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveExtraColumns;
use AlperenErsoy\FilamentExport\Concerns\CanHaveExtraViewData;
use AlperenErsoy\FilamentExport\Concerns\CanShowHiddenColumns;
use AlperenErsoy\FilamentExport\Concerns\CanUseSnappy;
use AlperenErsoy\FilamentExport\Concerns\HasCsvDelimiter;
use AlperenErsoy\FilamentExport\Concerns\HasData;
use AlperenErsoy\FilamentExport\Concerns\HasFileName;
use AlperenErsoy\FilamentExport\Concerns\HasFormat;
use AlperenErsoy\FilamentExport\Concerns\HasPageOrientation;
use AlperenErsoy\FilamentExport\Concerns\HasPaginator;
use AlperenErsoy\FilamentExport\Concerns\HasTable;
use Carbon\Carbon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilamentExport
{
    use CanFilterColumns;
    use CanHaveAdditionalColumns;
    use CanHaveExtraColumns;
    use CanHaveExtraViewData;
    use CanShowHiddenColumns;
    use CanUseSnappy;
    use HasCsvDelimiter;
    use HasData;
    use HasFileName;
    use HasFormat;
    use HasPageOrientation;
    use HasPaginator;
    use HasTable;

    public const FORMATS = [
        'xlsx' => 'XLSX',
        'csv' => 'CSV',
        'pdf' => 'PDF',
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
        $tableColumns = $this->shouldShowHiddenColumns() ? $this->getTable()->getLivewire()->getCachedTableColumns() : $this->getTable()->getColumns();

        $columns = collect($tableColumns);

        if ($this->getWithColumns()->isNotEmpty()) {
            $columns = $columns->merge($this->getWithColumns());
        }

        if ($this->getFilteredColumns()->isNotEmpty()) {
            $columns = $columns->filter(fn ($column) => $this->getFilteredColumns()->contains($column->getName()));
        }

        if ($this->getAdditionalColumns()->isNotEmpty()) {
            $columns = $columns->merge($this->getAdditionalColumns());
        }

        return $columns;
    }

    public function getPdfView(): string
    {
        return 'filament-export::pdf';
    }

    public function getViewData(): array
    {
        return array_merge(
            [
                'fileName' => $this->getFileName(),
                'columns' => $this->getAllColumns(),
                'rows' => $this->getRows(),
            ],
            $this->getExtraViewData()
        );
    }

    public function download(): StreamedResponse
    {
        if ($this->getFormat() === 'pdf') {
            $pdf = $this->getPdf();

            return response()->streamDownload(fn () => print($pdf->output()), "{$this->getFileName()}.{$this->getFormat()}");
        }

        return response()->streamDownload(function () {
            $headers = $this->getAllColumns()->map(fn ($column) => $column->getLabel())->toArray();

            $stream = SimpleExcelWriter::streamDownload("{$this->getFileName()}.{$this->getFormat()}", $this->getFormat(), delimiter: $this->getCsvDelimiter())
                ->noHeaderRow()
                ->addRows($this->getRows()->prepend($headers));

            $stream->close();
        }, "{$this->getFileName()}.{$this->getFormat()}");
    }

    public function getPdf(): \Barryvdh\DomPDF\PDF | \Barryvdh\Snappy\PdfWrapper
    {
        if ($this->shouldUseSnappy()) {
            return \Barryvdh\Snappy\Facades\SnappyPdf::loadView($this->getPdfView(), $this->getViewData())
                ->setPaper('A4', $this->getPageOrientation());
        }

        return \Barryvdh\DomPDF\Facade\Pdf::loadView($this->getPdfView(), $this->getViewData())
            ->setPaper('A4', $this->getPageOrientation());
    }

    public static function setUpFilamentExportAction(FilamentExportHeaderAction | FilamentExportBulkAction $action): void
    {
        $action->timeFormat(config('filament-export.time_format'));

        $action->defaultFormat(config('filament-export.default_format'));

        $action->defaultPageOrientation(config('filament-export.default_page_orientation'));

        $action->disableAdditionalColumns(config('filament-export.disable_additional_columns'));

        $action->disableFilterColumns(config('filament-export.disable_filter_columns'));

        $action->disableFileName(config('filament-export.disable_file_name'));

        $action->disableFileNamePrefix(config('filament-export.disable_file_name_prefix'));

        $action->disablePreview(config('filament-export.disable_preview'));

        $action->snappy(config('filament-export.use_snappy', false));

        $action->icon(config('filament-export.action_icon'));

        $action->fileName(Carbon::now()->translatedFormat($action->getTimeFormat()));

        $action->fileNameFieldLabel(__('filament-export::export_action.file_name_field_label'));

        $action->filterColumnsFieldLabel(__('filament-export::export_action.filter_columns_field_label'));

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

    public static function getFormComponents(FilamentExportHeaderAction | FilamentExportBulkAction $action): array
    {
        $action->fileNamePrefix($action->getFileNamePrefix() ?: $action->getTable()->getHeading());

        $columns = $action->shouldShowHiddenColumns() ? $action->getLivewire()->getCachedTableColumns() : $action->getTable()->getColumns();

        $columns = collect($columns);

        $extraColumns = collect($action->getWithColumns());

        if($extraColumns->isNotEmpty()) {
            $columns = $columns->merge($extraColumns);
        }

        $columns = $columns
            ->mapWithKeys(fn ($column) => [$column->getName() => $column->getLabel()])
            ->toArray();

        $updateTableView = function ($component, $livewire) use ($action) {
            $data = $action instanceof FilamentExportBulkAction ? $livewire->mountedTableBulkActionData : $livewire->mountedTableActionData;

            $export = FilamentExport::make()
                ->filteredColumns($data['filter_columns'] ?? [])
                ->additionalColumns($data['additional_columns'] ?? [])
                ->data(collect())
                ->table($action->getTable())
                ->extraViewData($action->getExtraViewData())
                ->withColumns($action->getWithColumns())
                ->paginator($action->getPaginator())
                ->csvDelimiter($action->getCsvDelimiter());

            $component
                ->export($export)
                ->refresh($action->shouldRefreshTableView());

            if ($data['table_view'] == 'print-'.$action->getUniqueActionId()) {
                $export->data($action->getRecords());
                $action->getLivewire()->printHTML = view('filament-export::print', $export->getViewData())->render();
            } elseif ($data['table_view'] == 'afterprint-'.$action->getUniqueActionId()) {
                $action->getLivewire()->printHTML = null;
            }
        };

        $initialExport = FilamentExport::make()
            ->table($action->getTable())
            ->data(collect())
            ->extraViewData($action->getExtraViewData())
            ->withColumns($action->getWithColumns())
            ->paginator($action->getPaginator())
            ->csvDelimiter($action->getCsvDelimiter());

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
                ->default($action->getDefaultFormat()),
            \Filament\Forms\Components\Select::make('page_orientation')
                ->label($action->getPageOrientationFieldLabel())
                ->options(FilamentExport::getPageOrientations())
                ->default($action->getDefaultPageOrientation())
                ->visible(fn ($get) => $get('format') === 'pdf'),
            \Filament\Forms\Components\CheckboxList::make('filter_columns')
                ->label($action->getFilterColumnsFieldLabel())
                ->options($columns)
                ->columns(4)
                ->default(array_keys($columns))
                ->hidden($action->isFilterColumnsDisabled()),
            \Filament\Forms\Components\KeyValue::make('additional_columns')
                ->label($action->getAdditionalColumnsFieldLabel())
                ->keyLabel($action->getAdditionalColumnsTitleFieldLabel())
                ->valueLabel($action->getAdditionalColumnsDefaultValueFieldLabel())
                ->addButtonLabel($action->getAdditionalColumnsAddButtonLabel())
                ->hidden($action->isAdditionalColumnsDisabled()),
            TableView::make('table_view')
                ->export($initialExport)
                ->uniqueActionId($action->getUniqueActionId())
                ->afterStateUpdated($updateTableView)
                ->reactive()
                ->refresh($action->shouldRefreshTableView()),
        ];
    }

    public static function callDownload(FilamentExportHeaderAction | FilamentExportBulkAction $action, Collection $records, array $data)
    {
        return FilamentExport::make()
            ->fileName($data['file_name'] ?? $action->getFileName())
            ->data($records)
            ->table($action->getTable())
            ->filteredColumns(! $action->isFilterColumnsDisabled() ? $data['filter_columns'] : [])
            ->additionalColumns(! $action->isAdditionalColumnsDisabled() ? $data['additional_columns'] : [])
            ->format($data['format'] ?? $action->getDefaultFormat())
            ->pageOrientation($data['page_orientation'] ?? $action->getDefaultPageOrientation())
            ->snappy($action->shouldUseSnappy())
            ->extraViewData($action->getExtraViewData())
            ->withColumns($action->getWithColumns())
            ->withHiddenColumns($action->shouldShowHiddenColumns())
            ->csvDelimiter($action->getCsvDelimiter())
            ->download();
    }

    public function getRows(): Collection
    {
        $records = $this->getData();

        $data = self::getDataWithStates($records);

        return collect($data);
    }

    public function getDataWithStates(Collection|LengthAwarePaginator $records): array
    {
        $items = [];

        $columns = $this->getAllColumns();

        foreach ($records as $index => $record) {
            $item = [];
            foreach ($columns as $column) {
                $state = self::getColumnState($column, $record, $index);

                $item[$column->getName()] = (string) $state;
            }
            array_push($items, $item);
        }

        return $items;
    }

    public static function getColumnState(Column $column, Model $record, int $index): ?string
    {
        $column->rowLoop((object) [
            'index' => $index,
            'iteration' => $index + 1,
        ]);

        $column = $column->record($record);

        $state = in_array(\Filament\Tables\Columns\Concerns\CanFormatState::class, class_uses($column)) ? $column->getFormattedState() : $column->getState();

        if (is_array($state)) {
            $state = implode(', ', $state);
        } elseif ($column instanceof ImageColumn) {
            $state = $column->getImagePath();
        } elseif ($column instanceof ViewColumn) {
            $state = trim(preg_replace('/\s+/', ' ', strip_tags($column->render()->render())));
        }

        return $state;
    }
}
