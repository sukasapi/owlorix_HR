<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\IdleReview;
use App\Modules\Shared\Audit\Auditor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** The person answers a lead's question about one of their PC diam periods, from Perlu kamu on Hari ini. */
class IdleAnswerController extends Controller
{
    public function __invoke(Request $request, IdleReview $review, Auditor $auditor): RedirectResponse
    {
        abort_unless($review->user_id === $request->user()->id, 403);

        $data = $request->validate(['answer' => ['required', 'string', 'min:5', 'max:1000']]);

        if ($review->status !== IdleReview::ASKED) {
            throw ValidationException::withMessages(['answer' => 'answer_closed']);
        }

        $review->fill(['status' => IdleReview::ANSWERED, 'answer' => trim($data['answer']), 'answered_at' => CarbonImmutable::now()])->save();
        $auditor->record('idle_review.answered', $review, ['status' => IdleReview::ASKED], ['status' => IdleReview::ANSWERED]);

        return back();
    }
}
