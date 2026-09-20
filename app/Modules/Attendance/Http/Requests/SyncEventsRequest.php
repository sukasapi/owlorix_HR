<?php

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\EventType;
use App\Modules\Attendance\Enums\IdleTag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A batch from the PC outbox (docs/03-architecture.md 4.1). Only the batch shape fails the request; each event is
 * checked on its own so one malformed event is rejected without blocking the rest (3.8). `user_id` and `device_id`
 * in an event are ignored: the person and the PC come from the token.
 */
class SyncEventsRequest extends FormRequest
{
    public const MAX_EVENTS = 200;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_EVENTS],
        ];
    }

    /** @return array<string, mixed> */
    public static function eventRules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'type' => ['required', 'string', Rule::in(EventType::fromDevice())],
            'occurred_at_device' => ['required', 'date'],
            'boot_id' => ['required', 'string', 'max:40'],
            'uptime_ms' => ['required', 'integer', 'min:0'],
            'server_offset_ms' => ['required', 'integer', 'between:-2147483648,2147483647'],
            'offline' => ['required', 'boolean'],
            'payload' => ['nullable', 'array'],
            'payload.reason' => ['nullable', 'string', 'max:2000'],
            'payload.work_report' => ['nullable', 'string', 'max:5000'],
            'payload.tag' => ['nullable', Rule::enum(IdleTag::class)],
            'payload.note' => ['nullable', 'string', 'max:255'],
            'payload.shift_id' => ['nullable', 'integer'],
            'payload.last_heartbeat_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: list<array{id: string|null, index: int, code: string, errors: array<string, list<string>>}>}
     *                                                                                                                                                    valid events keyed by batch position, and the rejected ones
     */
    public function partitionEvents(): array
    {
        $valid = [];
        $rejected = [];

        foreach ($this->input('events') as $index => $event) {
            $validator = Validator::make(is_array($event) ? $event : [], self::eventRules());

            if ($validator->fails()) {
                $rejected[] = [
                    'id' => is_array($event) && is_string($event['id'] ?? null) ? strtolower($event['id']) : null,
                    'index' => $index,
                    'code' => 'invalid',
                    'errors' => $validator->errors()->toArray(),
                ];

                continue;
            }

            $valid[$index] = $event;
        }

        return [$valid, $rejected];
    }
}
