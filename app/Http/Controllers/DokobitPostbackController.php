<?php

namespace App\Http\Controllers;

use App\Services\DocumentDokobitSigner;
use App\Services\EmployeeContractSigner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class DokobitPostbackController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $payload = $request->json()->all();

        if ($payload === []) {
            $payload = $request->all();
        }

        try {
            if (! DocumentDokobitSigner::handlePostback($payload)) {
                EmployeeContractSigner::handlePostback($payload);
            }
        } catch (Throwable $exception) {
            Log::error('Dokobit postback failed', [
                'message' => $exception->getMessage(),
                'payload' => $payload,
            ]);
        }

        return response('OK', 200);
    }
}
