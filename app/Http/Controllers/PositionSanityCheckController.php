<?php

namespace App\Http\Controllers;

use App\Models\Position;

use Illuminate\Http\Request;

class PositionSanityCheckController extends Controller
{
    /**
     * Return a Rangers with position issues
     *
     * @return  \Illuminate\Http\JsonResponse
     */
    public function sanityChecker()
    {
        $this->authorize('sanityChecker', [ Position::class ]);
        return response()->json(Position::sanityChecker());
    }

    /**
     * Repair position issues
     *
     * @return  \Illuminate\Http\JsonResponse
     */

    public function repair()
    {
        $this->authorize('repair', [ Position::class ]);

        $params = request()->validate([
            'repair'       => 'required|string',
            'people_ids'   => 'required|array',
            'people_ids.*' => 'required|integer|exists:person,id',
        ]);

        return response()->json(Position::repair($params['repair'], $params['people_ids']));
    }
}
