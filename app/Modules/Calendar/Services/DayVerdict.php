<?php

namespace App\Modules\Calendar\Services;

final readonly class DayVerdict
{
    public const SOURCE_OPENED = 'opened';

    public const SOURCE_CALENDAR = 'calendar';

    public const SOURCE_WORK_WEEK = 'work_week';

    public function __construct(
        public string $date,
        public bool $isWorkday,
        public string $source,
        /** CalendarDayType value when the source is the calendar */
        public ?string $calendarType = null,
        /** Holiday name, or the note of an opened workday */
        public ?string $label = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'is_workday' => $this->isWorkday,
            'source' => $this->source,
            'calendar_type' => $this->calendarType,
            'label' => $this->label,
        ];
    }
}
