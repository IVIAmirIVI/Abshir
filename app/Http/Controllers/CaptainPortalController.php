<?php

namespace App\Http\Controllers;

use App\Services\CaptainPortalQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CaptainPortalController extends Controller
{
    public function index(Request $request, CaptainPortalQuery $portal): Response
    {
        return response()->view('captain-portal.index', $portal->listing($request->user(), $request->query()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order, CaptainPortalQuery $portal): Response
    {
        return response()->view('captain-portal.show', $portal->detail($request->user(), $order))
            ->header('Cache-Control', 'private, no-store');
    }
}
