<?php

namespace Filament\Tables\Actions;

use Closure;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Select;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Arr;

class AttachAction extends Action
{
    use Concerns\InteractsWithRelationship;

    protected ?Closure $modifyRecordSelectUsing = null;

    protected bool | Closure $allowsDuplicates = false;

    protected bool | Closure $isAttachAnotherDisabled = false;

    protected bool | Closure $isRecordSelectPreloaded = false;

    public static function make(string $name = 'attach'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-support::actions/attach.single.label'));

        $this->modalHeading(fn (AttachAction $action): string => __('filament-support::actions/attach.single.modal.heading', ['label' => $action->getModelLabel()]));

        $this->modalButton(__('filament-support::actions/attach.single.modal.actions.attach.label'));

        $this->modalWidth('lg');

        $this->extraModalActions(function (AttachAction $action): array {
            return $action->isAttachAnotherDisabled() ? [] : [
                $this->makeExtraModalAction('attachAnother', ['another' => true])
                    ->label(__('filament-support::actions/attach.single.modal.actions.attach_and_attach_another.label')),
            ];
        });

        $this->successNotificationMessage(__('filament-support::actions/attach.single.messages.attached'));

        $this->color('secondary');

        $this->button();

        $this->form(static fn (AttachAction $action): array => [$action->getRecordSelect()]);

        $this->action(static function (AttachAction $action, array $arguments, array $data, ComponentContainer $form): void {
            /** @var BelongsToMany $relationship */
            $relationship = $action->getRelationship();

            $recordToAttach = $relationship->getRelated()->query()->find($data['recordId']);

            $relationship->attach(
                $recordToAttach,
                Arr::only($data, $relationship->getPivotColumns()),
            );

            if ($arguments['another'] ?? false) {
                $form->fill();

                $action->sendSuccessNotification();
                $action->callAfter();
                $action->hold();

                return;
            }

            $action->success();
        });
    }

    public function recordSelect(?Closure $callback): static
    {
        $this->modifyRecordSelectUsing = $callback;

        return $this;
    }

    public function allowDuplicates(bool | Closure $condition = true): static
    {
        $this->allowsDuplicates = $condition;

        return $this;
    }

    public function disableAttachAnother(bool | Closure $condition = true): static
    {
        $this->isAttachAnotherDisabled = $condition;

        return $this;
    }

    public function preloadRecordSelect(bool | Closure $condition = true): static
    {
        $this->isRecordSelectPreloaded = $condition;

        return $this;
    }

    public function isAttachAnotherDisabled(): bool
    {
        return $this->evaluate($this->isAttachAnotherDisabled);
    }

    public function isRecordSelectPreloaded(): bool
    {
        return $this->evaluate($this->isRecordSelectPreloaded);
    }

    public function allowsDuplicates(): bool
    {
        return $this->evaluate($this->allowsDuplicates);
    }

    public function getRecordSelect(): Select
    {
        $getOptions = function (?string $search = null, ?array $searchColumns = []): array {
            /** @var BelongsToMany $relationship */
            $relationship = $this->getRelationship();

            $titleColumnName = $this->getRecordTitleAttribute();

            $relationshipQuery = $relationship->getRelated()->query()->orderBy($titleColumnName);

            if (filled($search)) {
                $search = strtolower($search);

                /** @var Connection $databaseConnection */
                $databaseConnection = $relationshipQuery->getConnection();

                $searchOperator = match ($databaseConnection->getDriverName()) {
                    'pgsql' => 'ilike',
                    default => 'like',
                };

                $searchColumns ??= [$titleColumnName];
                $isFirst = true;

                $relationshipQuery->where(function (Builder $query) use ($isFirst, $searchColumns, $searchOperator, $search): Builder {
                    foreach ($searchColumns as $searchColumnName) {
                        $whereClause = $isFirst ? 'where' : 'orWhere';

                        $query->{$whereClause}(
                            $searchColumnName,
                            $searchOperator,
                            "%{$search}%",
                        );

                        $isFirst = false;
                    }

                    return $query;
                });
            }

            $relatedKeyName = $relationship->getRelatedKeyName();

            return $relationshipQuery
                ->when(
                    ! $this->allowsDuplicates(),
                    fn (Builder $query): Builder => $query->whereDoesntHave(
                        $this->getInverseRelationshipName(),
                        function (Builder $query): Builder {
                            return $query->where($this->getRelationship()->getParent()->getQualifiedKeyName(), $this->getRelationship()->getParent()->getKey());
                        },
                    ),
                )
                ->get()
                ->mapWithKeys(fn (Model $record): array => [$record->{$relatedKeyName} => $this->getRecordTitle($record)])
                ->toArray();
        };

        $select = Select::make('recordId')
            ->label(__('filament-support::actions/attach.single.modal.fields.record_id.label'))
            ->searchable()
            ->getSearchResultsUsing(static fn (Select $component, string $searchQuery): array  => $getOptions(search: $searchQuery, searchColumns: $component->getSearchColumns()))
            ->getOptionLabelUsing(fn ($value): ?string => $this->getRecordTitle($this->getRelationship()->getRelated()->query()->find($value)))
            ->options(fn (): array => $this->isRecordSelectPreloaded() ? $getOptions() : [])
            ->disableLabel();

        if ($this->modifyRecordSelectUsing) {
            $select = $this->evaluate($this->modifyRecordSelectUsing, [
                'select' => $select,
            ]);
        }

        return $select;
    }
}
