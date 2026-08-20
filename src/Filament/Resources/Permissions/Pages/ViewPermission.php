<?php

declare(strict_types=1);

namespace ElPandaPe\FilamentWarden\Filament\Resources\Permissions\Pages;

use ElPandaPe\FilamentWarden\Conditions\Columns;
use ElPandaPe\FilamentWarden\Filament\Resources\Permissions\PermissionResource;
use ElPandaPe\FilamentWarden\Grants\Holders;
use ElPandaPe\FilamentWarden\Grants\Probe;
use ElPandaPe\FilamentWarden\Grants\Reach;
use ElPandaPe\FilamentWarden\Support\Config;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewPermission extends ViewRecord
{
    protected static string $resource = PermissionResource::class;

    public static function accountLabel(mixed $value): ?string
    {
        $account = self::account($value);

        return $account instanceof Model ? Holders::label($account) : null;
    }

    /**
     * @return array<int|string, string>
     */
    public static function accounts(string $search): array
    {
        $model = Columns::authorityModel();

        // The header action is not offered without one, so this is the crafted
        // request rather than the screen.
        if ($model === null) {
            return [];
        }

        $columns = array_values(array_intersect(['name', 'email', 'title'], Columns::of($model)));

        $records = $model::query()
            ->where(static function (mixed $query) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            })
            ->limit(20)
            ->get();

        $options = [];

        foreach ($records as $record) {
            $key = $record->getKey();

            if (is_int($key) || is_string($key)) {
                $options[$key] = Holders::label($record);
            }
        }

        return $options;
    }

    /**
     * The test bench: `explain()` asked the way the application asks it, with a
     * real account and — when the permission has a model — a real row.
     *
     * It lives in a modal rather than on the page because that is where Filament
     * gives a searchable select for free, and an installation's account table can
     * be very large. The answer comes back as a notification that stays put.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! Config::enabled('permissions.probe') || Columns::authorityModel() === null) {
            return [];
        }

        return [
            Action::make('probe')
                ->label(__('filament-warden::ui.resources.permissions.probe.label'))
                ->icon(Heroicon::OutlinedBeaker)
                ->modalSubmitActionLabel(__('filament-warden::ui.resources.permissions.probe.submit'))
                ->schema([
                    Select::make('account')
                        ->label(__('filament-warden::ui.resources.permissions.probe.account'))
                        ->required()
                        ->searchable()
                        ->getSearchResultsUsing($this->accounts(...))
                        ->getOptionLabelUsing(static fn (mixed $value): ?string => self::accountLabel($value)),

                    TextInput::make('record')
                        ->label(__('filament-warden::ui.resources.permissions.probe.record'))
                        ->helperText(__('filament-warden::ui.resources.permissions.probe.record_help'))
                        ->visible(fn (): bool => $this->getRecord()->getAttribute('entity_type') !== null),
                ])
                ->action(function (array $data): void {
                    $this->answer($data);
                }),
        ];
    }

    private static function account(mixed $key): ?Model
    {
        $model = Columns::authorityModel();

        return $model === null || ! is_int($key) && ! is_string($key)
            ? null
            : $model::query()->whereKey($key)->first();
    }

    /**
     * @param  array<mixed>  $data
     */
    private function answer(array $data): void
    {
        $account = self::account($data['account'] ?? null);
        $record = $data['record'] ?? null;

        // The select validates against its own options, so an account that does
        // not resolve is one whose row went away between opening the modal and
        // submitting it. There is nothing to answer, and nothing to say.
        if ($account instanceof Model) {
            $this->tell(
                Probe::run(
                    $account,
                    $this->getRecord(),
                    is_string($record) && $record !== '' ? $record : null,
                ),
                Reach::of($this->getRecord(), $account),
            );
        }
    }

    /**
     * The verdict, and — where it can be counted — how far it reaches.
     *
     * The reach is worked out here and nowhere else: one `whereCan()` is six
     * queries with no cache, so it happens when somebody asks and never on a
     * render.
     */
    private function tell(Probe $probe, Reach $reach): void
    {
        Notification::make()
            ->title(__('filament-warden::ui.stances.'.$probe->verdict->value))
            ->body(mb_trim($probe->summary.' '.($probe->note ?? '').' '.$reach->sentence()))
            ->status(match ($probe->verdict->value) {
                'granted' => 'success',
                'forbidden' => 'danger',
                default => 'warning',
            })
            ->persistent()
            ->send();
    }
}
