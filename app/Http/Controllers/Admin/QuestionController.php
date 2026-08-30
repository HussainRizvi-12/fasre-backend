<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreQuestionRequest;
use App\Http\Requests\Admin\UpdateQuestionRequest;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Question::query()->orderBy('sort_order');

        if ($request->has('form_type')) {
            $query->where('form_type', $request->form_type);
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Questions retrieved successfully.',
        ]);
    }

    public function store(StoreQuestionRequest $request): JsonResponse
    {
        $hasActiveWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();

        if ($hasActiveWindow && $request->input('form_type') === FormType::StudentReview->value) {
            return response()->json([
                'message' => 'Cannot add new review questions while a review window is actively open.',
            ], 422);
        }

        $question = Question::create($request->validated());

        return response()->json([
            'data' => $question,
            'message' => 'Question created successfully.',
        ], 201);
    }

    public function update(UpdateQuestionRequest $request, Question $question): JsonResponse
    {
        $hasActiveWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();

        if ($hasActiveWindow && $question->form_type === FormType::StudentReview) {
            return response()->json([
                'message' => 'Cannot modify review questions while a review window is actively open.',
            ], 422);
        }

        $question->update($request->validated());

        return response()->json([
            'data' => $question->fresh(),
            'message' => 'Question updated successfully.',
        ]);
    }

    public function destroy(Question $question): JsonResponse
    {
        $hasActiveWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();

        if ($hasActiveWindow && $question->form_type === FormType::StudentReview) {
            return response()->json([
                'message' => 'Cannot delete review questions while a review window is actively open.',
            ], 422);
        }

        $hasResponses = ReviewResponse::whereNotNull("answers_json->{$question->id}")->exists();
        if ($hasResponses) {
            return response()->json([
                'message' => 'Cannot delete question with existing recorded responses. Deactivate the question instead.',
            ], 422);
        }

        $question->delete();

        return response()->json([
            'message' => 'Question deleted successfully.',
        ]);
    }
}
