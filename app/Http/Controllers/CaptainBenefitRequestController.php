<?php

namespace App\Http\Controllers;

use App\Models\Captain;
use App\Models\CaptainBenefitRequest;
use App\Models\Currency;
use App\Services\CaptainBenefitNotificationService;
use App\Services\CaptainBenefitRequestService;
use App\Services\CaptainBenefitReviewService;
use App\Services\CaptainFinancialEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CaptainBenefitRequestController extends Controller
{
    public function index(Request $request, Captain $captain, CaptainBenefitRequestService $service): View
    {
        $service->assertView($request->user(), $captain);
        $available = Schema::hasTable('captain_benefit_requests');
        $branches = $captain->activeOperationalBranches()->filter(fn ($branch) => $request->user()->canAccessBranch($branch));
        $proposals = $available ? CaptainBenefitRequest::query()->where('captain_id', $captain->id)
            ->whereIn('branch_id', $request->user()->availableBranches()->select('branches.id'))
            ->whereDoesntHave('nextVersion')->with(['branch', 'currency', 'createdBy'])
            ->latest('recorded_at')->paginate(20) : null;

        return view('captain-requests.index', [
            'captain' => $captain->load('user'), 'available' => $available,
            'branches' => $branches, 'currencies' => Currency::where('is_active', true)->orderBy('code')->get(),
            'proposals' => $proposals, 'proposal' => null,
        ]);
    }

    public function store(Request $request, Captain $captain, CaptainBenefitRequestService $service): RedirectResponse
    {
        $proposal = $service->save($request->user(), $captain, $this->input($request), $request->file('evidence'));

        return redirect()->route('captains.requests.show', [$captain, $proposal])->with('status', __('captain_requests.saved'));
    }

    public function show(Request $request, Captain $captain, CaptainBenefitRequest $proposal, CaptainBenefitRequestService $service): View
    {
        $service->assertRecord($request->user(), $captain, $proposal);

        return view('captain-requests.show', [
            'captain' => $captain->load('user'), 'proposal' => $proposal->load(['branch', 'currency', 'order', 'createdBy', 'recordedBy', 'nextVersion']),
            'history' => CaptainBenefitRequest::where('proposal_key', $proposal->proposal_key)->with('recordedBy')->orderByDesc('version')->get(),
            'reviewAvailable' => Schema::hasTable('captain_benefit_reviews'),
            'summary' => app(CaptainBenefitReviewService::class)->summary($proposal),
            'reviews' => Schema::hasTable('captain_benefit_reviews') ? $proposal->reviews()->with('actor')->orderBy('id')->get() : collect(),
        ]);
    }

    public function revise(Request $request, Captain $captain, CaptainBenefitRequest $proposal, CaptainBenefitRequestService $service): RedirectResponse
    {
        $revision = $service->save($request->user(), $captain, $this->input($request), $request->file('evidence'), $proposal);

        return redirect()->route('captains.requests.show', [$captain, $revision])->with('status', __('captain_requests.saved'));
    }

    public function evidence(Request $request, Captain $captain, CaptainBenefitRequest $proposal, CaptainBenefitRequestService $service, CaptainFinancialEvidenceService $evidence): Response
    {
        $service->assertRecord($request->user(), $captain, $proposal);
        Gate::authorize('captain_financial_evidence.download');
        abort_unless($proposal->evidence_path, 404);
        try {
            $bytes = $evidence->bytes($proposal->evidence_path, $proposal->evidence_sha256);
            abort_unless(strlen($bytes) === $proposal->evidence_size
                && (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $proposal->evidence_mime, 409);
        } catch (\Throwable) {
            abort(409, __('domain.evidence_integrity'));
        }

        return response($bytes, 200, [
            'Content-Type' => $proposal->evidence_mime,
            'Content-Disposition' => 'attachment; filename="captain-request-'.$proposal->id.'"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }

    public function review(Request $request, Captain $captain, CaptainBenefitRequest $proposal, CaptainBenefitReviewService $service): RedirectResponse
    {
        $review = $service->act($request->user(), $captain, $proposal, $request->only(['request_key', 'action', 'reason']));

        $response = redirect()->route('captains.requests.show', [$captain, $proposal])->with('status', __('captain_requests.review_recorded'));
        if ($review->action === 'SUBMIT' && $service->summary($proposal)['state'] === 'PENDING'
            && app(CaptainBenefitNotificationService::class)->reviewers($proposal)->isEmpty()) {
            $response->with('warning', __('internal.no_reviewer'));
        }

        return $response;
    }

    private function input(Request $request): array
    {
        $request->validate(['evidence' => ['nullable', 'file', 'max:5120']]);

        return $request->except(['_token', '_method', 'evidence']);
    }
}
